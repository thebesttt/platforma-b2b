<?php

declare(strict_types=1);

namespace App\Modules\Seo;

use App\Core\Config;
use App\Core\Http;
use App\Core\Kv;

/**
 * Dane z Google przez konto usługi (service account): Search Console API i Google Analytics Data API.
 *
 * Konto usługi to „robot” z własnym adresem e-mail. Właściciel witryny dodaje ten adres jako użytkownika w Search Console
 * (i jako przeglądającego w GA4), a panel czyta dane bez logowania Google i bez weryfikacji aplikacji przez Google.
 * Klucz (plik JSON z Google Cloud) trafia do zmiennej GOOGLE_SERVICE_ACCOUNT. Panel prosi tylko o uprawnienia do odczytu.
 *
 * Wywołania są napisane według dokumentacji Google i sprawdzone na odpowiedziach w jej formacie, bez prawdziwego klucza.
 */
final class Google
{
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const SCOPES = 'https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/analytics.readonly';

    private const GSC = 'https://www.googleapis.com/webmasters/v3';

    private const GA4 = 'https://analyticsdata.googleapis.com/v1beta';

    private const GA4_ADMIN = 'https://analyticsadmin.googleapis.com/v1beta';

    /** Search Console podaje pełne dane z opóźnieniem 2-3 dni. */
    private const GSC_DELAY_DAYS = 3;

    public const WINDOW = 28;

    public static function enabled(): bool
    {
        return self::account() !== null;
    }

    /** Adres e-mail konta usługi: ten adres właściciel dodaje w Search Console i GA4. */
    public static function email(): string
    {
        return (string) (self::account()['client_email'] ?? '');
    }

    /** @return array{client_email: string, private_key: string}|null */
    public static function account(): ?array
    {
        $raw = trim((string) Config::get('GOOGLE_SERVICE_ACCOUNT'));
        if ($raw === '') {
            return null;
        }
        // Zmienna przyjmuje treść pliku JSON albo ten sam plik zakodowany w base64 (wygodniejsze w panelach hostingu).
        if (!str_starts_with($raw, '{')) {
            $raw = (string) base64_decode($raw, true);
        }
        $j = json_decode($raw, true);
        if (!is_array($j) || empty($j['client_email']) || empty($j['private_key']) || !str_contains((string) $j['private_key'], 'PRIVATE KEY')) {
            return null;
        }
        return ['client_email' => (string) $j['client_email'], 'private_key' => (string) $j['private_key']];
    }

    /** @return array{ok: bool, token?: string, error?: string} */
    public static function token(): array
    {
        $acc = self::account();
        if (!$acc) {
            return ['ok' => false, 'error' => 'Brak klucza konta usługi Google (zmienna GOOGLE_SERVICE_ACCOUNT) albo klucz ma zły format.'];
        }
        $cacheKey = 'google:token:' . sha1($acc['client_email']);
        $cached = Kv::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return ['ok' => true, 'token' => $cached];
        }
        $jwt = self::jwt($acc, time());
        if ($jwt === null) {
            return ['ok' => false, 'error' => 'Nie udało się podpisać zapytania kluczem konta usługi. Wklej klucz z pliku JSON jeszcze raz.'];
        }
        $r = Http::request('POST', self::TOKEN_URL, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt,
        ]), ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json']);
        $j = json_decode((string) $r['body'], true);
        if ($r['status'] !== 200 || !is_array($j) || empty($j['access_token'])) {
            $why = is_array($j) ? (string) ($j['error_description'] ?? $j['error'] ?? '') : '';
            return ['ok' => false, 'error' => 'Google odrzucił klucz konta usługi' . ($why !== '' ? ' (' . mb_substr($why, 0, 160) . ')' : '') . '. Sprawdź, czy klucz nie został usunięty w Google Cloud.'];
        }
        Kv::set($cacheKey, (string) $j['access_token'], max(60, (int) ($j['expires_in'] ?? 3600) - 120));
        return ['ok' => true, 'token' => (string) $j['access_token']];
    }

    /** Podpisany token JWT do wymiany na token dostępu (RS256). */
    public static function jwt(array $acc, int $now): ?string
    {
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $head = $b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $b64((string) json_encode([
            'iss' => $acc['client_email'], 'scope' => self::SCOPES, 'aud' => self::TOKEN_URL, 'iat' => $now, 'exp' => $now + 3600,
        ], JSON_UNESCAPED_SLASHES));
        $sig = '';
        try {
            $ok = @openssl_sign($head . '.' . $claims, $sig, $acc['private_key'], OPENSSL_ALGO_SHA256);
        } catch (\Throwable) {
            $ok = false;
        }
        return $ok ? $head . '.' . $claims . '.' . $b64($sig) : null;
    }

    /**
     * Usługi Search Console, do których konto usługi ma dostęp.
     * @return array{ok: bool, sites?: array<int, array{url: string, level: string}>, error?: string}
     */
    public static function properties(): array
    {
        $t = self::token();
        if (!$t['ok']) {
            return $t;
        }
        $r = Http::get(self::GSC . '/sites', self::auth($t['token']));
        if ($r['status'] !== 200) {
            return ['ok' => false, 'error' => self::explain($r, 'Search Console')];
        }
        $out = [];
        foreach ((array) (json_decode((string) $r['body'], true)['siteEntry'] ?? []) as $s) {
            // „siteUnverifiedUser” to dostęp do usługi, której nikt nie zweryfikował: danych z niej nie będzie.
            if (is_array($s) && !empty($s['siteUrl']) && ($s['permissionLevel'] ?? '') !== 'siteUnverifiedUser') {
                $out[] = ['url' => (string) $s['siteUrl'], 'level' => (string) ($s['permissionLevel'] ?? '')];
            }
        }
        return ['ok' => true, 'sites' => $out];
    }

    /** Usługa pasująca do domeny: najpierw usługa domeny (sc-domain:), potem adresy z prefiksem. */
    public static function propertyFor(string $host, array $sites): ?string
    {
        $urls = array_column($sites, 'url');
        foreach (['sc-domain:' . $host, 'https://' . $host . '/', 'https://www.' . $host . '/', 'http://' . $host . '/', 'http://www.' . $host . '/'] as $candidate) {
            if (in_array($candidate, $urls, true)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Pobiera z Search Console ostatnie 28 dni i 28 dni wcześniej: sumy, zapytania, strony, pary zapytanie + strona,
     * urządzenia oraz dane dzienne z pół roku. Zapisuje dopiero komplet.
     * @return array{ok: bool, error?: string, start?: string, end?: string, queries?: int, pages?: int}
     */
    public static function syncGsc(array $site): array
    {
        $t = self::token();
        if (!$t['ok']) {
            return $t;
        }
        $property = trim((string) ($site['gsc_property'] ?? ''));
        if ($property === '') {
            $props = self::properties();
            if (!$props['ok']) {
                return $props;
            }
            $property = (string) self::propertyFor((string) $site['host'], $props['sites']);
            if ($property === '') {
                return ['ok' => false, 'error' => 'Konto usługi nie widzi witryny ' . $site['host'] . ' w Search Console. Dodaj adres ' . self::email()
                    . ' jako użytkownika tej usługi (Ustawienia → Użytkownicy i uprawnienia), a potem spróbuj ponownie.'];
            }
            \App\Core\Db::get()->update('seo_sites', ['gsc_property' => $property], 'id = ?', [(int) $site['id']]);
        }
        $end = gmdate('Y-m-d', time() - self::GSC_DELAY_DAYS * 86400);
        $day = static fn (int $back): string => gmdate('Y-m-d', strtotime($end . ' UTC') - $back * 86400);
        $cur = [$day(self::WINDOW - 1), $end];
        $prev = [$day(2 * self::WINDOW - 1), $day(self::WINDOW)];
        $q = static function (array $range, array $dims, int $limit) use ($property, $t): array {
            return self::gscQuery($property, $t['token'], ['startDate' => $range[0], 'endDate' => $range[1], 'dimensions' => $dims, 'rowLimit' => $limit, 'type' => 'web']);
        };
        $plan = [
            'total' => [$cur, [], 1], 'total_prev' => [$prev, [], 1],
            'query' => [$cur, ['query'], 3000], 'query_prev' => [$prev, ['query'], 3000],
            'page' => [$cur, ['page'], 1000], 'page_prev' => [$prev, ['page'], 1000],
            'query_page' => [$cur, ['query', 'page'], 5000],
            'device' => [$cur, ['device'], 10],
            'date' => [[$day(179), $end], ['date'], 200],
        ];
        $got = [];
        foreach ($plan as $name => [$range, $dims, $limit]) {
            $res = $q($range, $dims, $limit);
            if (!$res['ok']) {
                return $res;
            }
            $got[$name] = $res['rows'];
        }
        $row = static fn (array $r): array => [
            'k' => (string) ($r['keys'][0] ?? ''), 'k2' => (string) ($r['keys'][1] ?? ''),
            'clicks' => (int) round((float) ($r['clicks'] ?? 0)), 'impressions' => (int) round((float) ($r['impressions'] ?? 0)),
            'position' => isset($r['position']) ? (float) $r['position'] : null,
        ];
        Stats::clear($site, 'gsc', ['total', 'query', 'page', 'query_page', 'device', 'country']);
        foreach (['total' => $cur, 'query' => $cur, 'page' => $cur, 'query_page' => $cur, 'device' => $cur, 'total_prev' => $prev, 'query_prev' => $prev, 'page_prev' => $prev] as $name => $range) {
            $rows = array_map($row, $got[$name]);
            if (str_starts_with($name, 'total')) {
                $rows = [['k' => 'api', 'k2' => ''] + ($rows[0] ?? ['clicks' => 0, 'impressions' => 0, 'position' => null])];
            }
            Stats::replace($site, 'gsc', str_replace('_prev', '', $name), $range[0], $range[1], $rows);
        }
        Stats::putDaily($site, 'gsc', 'date', array_map(static fn (array $r): array => $row($r) + ['day' => (string) ($r['keys'][0] ?? '')], array_filter(
            $got['date'],
            static fn (array $r): bool => GscFile::isDate((string) ($r['keys'][0] ?? ''))
        )));
        return ['ok' => true, 'start' => $cur[0], 'end' => $cur[1], 'queries' => count($got['query']), 'pages' => count($got['page'])];
    }

    /**
     * Sesje z asystentów AI z Google Analytics: ostatnie 28 dni i 28 dni wcześniej, źródła i strony wejścia.
     * @return array{ok: bool, error?: string, start?: string, end?: string, sessions?: int}
     */
    public static function syncGa4(array $site): array
    {
        $t = self::token();
        if (!$t['ok']) {
            return $t;
        }
        $property = trim((string) ($site['ga4_property'] ?? ''));
        if (!preg_match('/^\d{5,15}$/', $property)) {
            // Użytkownik niczego nie przepisuje: usługę GA4 znajdujemy po adresie strumienia danych.
            $found = self::ga4PropertyFor((string) $site['host'], $t['token']);
            if (!$found['ok']) {
                return $found;
            }
            $property = $found['property'];
            \App\Core\Db::get()->update('seo_sites', ['ga4_property' => $property], 'id = ?', [(int) $site['id']]);
        }
        $end = gmdate('Y-m-d', time() - 86400);
        $day = static fn (int $back): string => gmdate('Y-m-d', strtotime($end . ' UTC') - $back * 86400);
        $cur = [$day(self::WINDOW - 1), $end];
        $prev = [$day(2 * self::WINDOW - 1), $day(self::WINDOW)];
        $filter = ['filter' => ['fieldName' => 'sessionSource', 'stringFilter' => ['matchType' => 'PARTIAL_REGEXP', 'value' => AiSources::GA4_REGEX, 'caseSensitive' => false]]];
        $run = static function (array $range, array $dims, bool $onlyAi) use ($property, $t, $filter): array {
            $body = ['dateRanges' => [['startDate' => $range[0], 'endDate' => $range[1]]], 'metrics' => [['name' => 'sessions']], 'limit' => '500'];
            if ($dims) {
                $body['dimensions'] = array_map(static fn (string $d): array => ['name' => $d], $dims);
            }
            if ($onlyAi) {
                $body['dimensionFilter'] = $filter;
            }
            $r = Http::postJson(self::GA4 . '/properties/' . $property . ':runReport', $body, self::auth($t['token']));
            if ($r['status'] !== 200) {
                return ['ok' => false, 'error' => self::explain($r, 'Google Analytics')];
            }
            $out = [];
            foreach ((array) (json_decode((string) $r['body'], true)['rows'] ?? []) as $row) {
                $out[] = [array_map(static fn ($d): string => (string) ($d['value'] ?? ''), (array) ($row['dimensionValues'] ?? [])), (int) ($row['metricValues'][0]['value'] ?? 0)];
            }
            return ['ok' => true, 'rows' => $out];
        };
        $got = [];
        foreach (['src' => [$cur, ['sessionSource'], true], 'src_prev' => [$prev, ['sessionSource'], true], 'all' => [$cur, [], false], 'all_prev' => [$prev, [], false],
            'pages' => [$cur, ['landingPage', 'sessionSource'], true]] as $name => [$range, $dims, $onlyAi]) {
            $res = $run($range, $dims, $onlyAi);
            if (!$res['ok']) {
                return $res;
            }
            $got[$name] = $res['rows'];
        }
        // Wyrażenie w filtrze jest szerokie, więc każde źródło sprawdzamy jeszcze na liście asystentów. Domeny jednego asystenta sumujemy.
        $bySource = static function (array $rows): array {
            $out = [];
            foreach ($rows as [$dims, $n]) {
                $name = AiSources::assistant($dims[0] ?? '');
                if ($name !== null && $n > 0) {
                    $out[$name] = ['k' => $name, 'value' => ($out[$name]['value'] ?? 0) + $n];
                }
            }
            return array_values($out);
        };
        $pages = [];
        foreach ($got['pages'] as [$dims, $n]) {
            $name = AiSources::assistant($dims[1] ?? '');
            $path = mb_substr((string) ($dims[0] ?? ''), 0, 400);
            if ($name !== null && $n > 0 && $path !== '' && $path !== '(not set)') {
                $key = $path . '|' . $name;
                $pages[$key] = ['k' => $path, 'k2' => $name, 'value' => ($pages[$key]['value'] ?? 0) + $n];
            }
        }
        Stats::clear($site, 'ga4_ai', ['source', 'page', 'total']);
        $curRows = $bySource($got['src']);
        Stats::replace($site, 'ga4_ai', 'source', $cur[0], $cur[1], $curRows);
        Stats::replace($site, 'ga4_ai', 'source', $prev[0], $prev[1], $bySource($got['src_prev']));
        Stats::replace($site, 'ga4_ai', 'total', $cur[0], $cur[1], [['k' => 'api', 'value' => (int) ($got['all'][0][1] ?? 0)]]);
        Stats::replace($site, 'ga4_ai', 'total', $prev[0], $prev[1], [['k' => 'api', 'value' => (int) ($got['all_prev'][0][1] ?? 0)]]);
        Stats::replace($site, 'ga4_ai', 'page', $cur[0], $cur[1], array_values($pages));
        return ['ok' => true, 'start' => $cur[0], 'end' => $cur[1], 'sessions' => (int) array_sum(array_column($curRows, 'value'))];
    }

    /**
     * Usługa GA4, której strumień danych ma adres tej witryny. Szukamy wśród usług, do których konto usługi ma dostęp.
     * @return array{ok: bool, property?: string, error?: string}
     */
    public static function ga4PropertyFor(string $host, string $token): array
    {
        $r = Http::get(self::GA4_ADMIN . '/accountSummaries?pageSize=200', self::auth($token));
        if ($r['status'] !== 200) {
            return ['ok' => false, 'error' => self::explain($r, 'Google Analytics')];
        }
        $props = [];
        foreach ((array) (json_decode((string) $r['body'], true)['accountSummaries'] ?? []) as $acc) {
            foreach ((array) ($acc['propertySummaries'] ?? []) as $p) {
                if (preg_match('#^properties/(\d{5,15})$#', (string) ($p['property'] ?? ''), $m)) {
                    $props[$m[1]] = (string) ($p['displayName'] ?? '');
                }
            }
        }
        if (!$props) {
            return ['ok' => false, 'error' => 'Konto usługi nie widzi żadnej usługi Google Analytics. Dodaj adres ' . self::email() . ' w GA4 (Administracja → Zarządzanie dostępem do usługi) z rolą „Wyświetlający”.'];
        }
        // Jedna usługa to oczywisty wybór. Przy kilku sprawdzamy adresy strumieni danych (najwyżej 25 usług).
        if (count($props) === 1) {
            return ['ok' => true, 'property' => (string) array_key_first($props)];
        }
        foreach (array_slice(array_keys($props), 0, 25) as $id) {
            $j = Http::getJson(self::GA4_ADMIN . '/properties/' . $id . '/dataStreams', self::auth($token));
            foreach ((array) ($j['dataStreams'] ?? []) as $s) {
                if (Sites::host((string) ($s['webStreamData']['defaultUri'] ?? '')) === $host) {
                    return ['ok' => true, 'property' => (string) $id];
                }
            }
        }
        return ['ok' => false, 'error' => 'Konto usługi widzi ' . count($props) . ' usługi Google Analytics, ale żadna nie ma strumienia danych dla ' . $host . '. Dodaj adres ' . self::email() . ' we właściwej usłudze GA4 albo wpisz jej numer w ustawieniach zaawansowanych.'];
    }

    /** @return array{ok: bool, rows?: array, error?: string} */
    private static function gscQuery(string $property, string $token, array $body): array
    {
        if (!$body['dimensions']) {
            unset($body['dimensions']);
        }
        $r = Http::postJson(self::GSC . '/sites/' . rawurlencode($property) . '/searchAnalytics/query', $body, self::auth($token));
        if ($r['status'] !== 200) {
            return ['ok' => false, 'error' => self::explain($r, 'Search Console')];
        }
        $rows = json_decode((string) $r['body'], true)['rows'] ?? [];
        return ['ok' => true, 'rows' => is_array($rows) ? $rows : []];
    }

    private static function auth(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    /** Komunikat dla użytkownika z odpowiedzi błędu Google. */
    private static function explain(array $r, string $service): string
    {
        $j = json_decode((string) $r['body'], true);
        $msg = is_array($j) ? (string) ($j['error']['message'] ?? '') : '';
        $status = (int) $r['status'];
        if ($status === 0) {
            return 'Brak połączenia z Google (' . ($r['error'] ?? 'nieznany błąd') . '). Spróbuj ponownie za chwilę.';
        }
        if ($status === 403 && preg_match('/has not been used in project|is disabled|accessNotConfigured/i', $msg . (string) $r['body'])) {
            return 'W projekcie Google Cloud, z którego pochodzi klucz, nie jest włączony interfejs ' . ($service === 'Search Console' ? 'Google Search Console API' : 'Google Analytics Data API') . '. Włącz go w Google Cloud (Interfejsy API i usługi) i spróbuj ponownie.';
        }
        if ($status === 403 || $status === 404) {
            return 'Konto usługi nie ma dostępu do tych danych w ' . $service . '. Dodaj adres ' . self::email() . ' jako użytkownika z prawem odczytu i sprawdź identyfikator usługi w ustawieniach witryny.';
        }
        if ($status === 429) {
            return $service . ' chwilowo ogranicza liczbę zapytań. Spróbuj ponownie za kilka minut.';
        }
        return $service . ' odpowiedział kodem ' . $status . ($msg !== '' ? ': ' . mb_substr($msg, 0, 200) : '') . '.';
    }
}
