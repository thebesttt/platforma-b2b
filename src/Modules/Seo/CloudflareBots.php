<?php

declare(strict_types=1);

namespace App\Modules\Seo;

use App\Core\Config;
use App\Core\Db;
use App\Core\Http;

/**
 * Boty AI na witrynie za Cloudflare: kto pobiera strony, ile razy i które adresy.
 *
 * To jedyne źródło, które pokazuje ChatGPT, Claude i Perplexity od strony serwera. Najciekawsze są boty kategorii „asystent”:
 * pobierają stronę w chwili, gdy ktoś zadał asystentowi pytanie, więc ich wizyty to ślad użycia treści w odpowiedzi.
 * Dane pochodzą z GraphQL Analytics API (zbiór httpRequestsAdaptiveGroups, dostępny w każdym planie). Liczby są próbkowane,
 * a bota rozpoznajemy po nagłówku User-Agent, który da się podrobić.
 *
 * Zapytania są napisane według dokumentacji Cloudflare (AI Crawl Control, GraphQL API) i sprawdzone na odpowiedziach w jej formacie.
 */
final class CloudflareBots
{
    private const ENDPOINT = 'https://api.cloudflare.com/client/v4/graphql';

    /** Fragment nagłówka User-Agent (małe litery) => [nazwa, operator, kategoria]. Kolejność ma znaczenie przy podobnych nazwach. */
    public const BOTS = [
        'chatgpt-user' => ['ChatGPT-User', 'OpenAI', 'assistant'],
        'oai-searchbot' => ['OAI-SearchBot', 'OpenAI', 'search'],
        'gptbot' => ['GPTBot', 'OpenAI', 'training'],
        'claude-user' => ['Claude-User', 'Anthropic', 'assistant'],
        'claude-searchbot' => ['Claude-SearchBot', 'Anthropic', 'search'],
        'claudebot' => ['ClaudeBot', 'Anthropic', 'training'],
        'perplexity-user' => ['Perplexity-User', 'Perplexity', 'assistant'],
        'perplexitybot' => ['PerplexityBot', 'Perplexity', 'search'],
        'mistralai-user' => ['MistralAI-User', 'Mistral', 'assistant'],
        'duckassistbot' => ['DuckAssistBot', 'DuckDuckGo', 'assistant'],
        'meta-externalfetcher' => ['Meta-ExternalFetcher', 'Meta', 'assistant'],
        'meta-externalagent' => ['Meta-ExternalAgent', 'Meta', 'training'],
        'google-cloudvertexbot' => ['Google-CloudVertexBot', 'Google', 'training'],
        'applebot' => ['Applebot', 'Apple', 'search'],
        'amazonbot' => ['Amazonbot', 'Amazon', 'training'],
        'bytespider' => ['Bytespider', 'ByteDance', 'training'],
        'ccbot' => ['CCBot', 'Common Crawl', 'training'],
    ];

    public const CATEGORIES = [
        'assistant' => ['Asystent na żywo', 'Pobiera stronę w chwili, gdy ktoś zadał asystentowi pytanie.'],
        'search' => ['Wyszukiwarka AI', 'Zbiera strony do odpowiedzi z linkami do źródeł.'],
        'training' => ['Trening modeli', 'Zbiera treści do uczenia modeli. Nie wpływa na odpowiedzi z linkami.'],
    ];

    public const DAYS = 28;

    private const CHUNK_DAYS = 7;

    private const LIMIT = 5000;

    public static function enabled(): bool
    {
        return self::tokenValue() !== '';
    }

    /**
     * Pobiera ostatnie 28 dni: wizyty botów dzień po dniu i adresy pobierane przez asystentów oraz wyszukiwarki AI.
     * @return array{ok: bool, error?: string, requests?: int, bots?: int}
     */
    public static function sync(array $site): array
    {
        $token = self::tokenValue();
        if ($token === '') {
            return ['ok' => false, 'error' => 'Brak tokenu Cloudflare z uprawnieniem do odczytu statystyk (zmienna CF_ANALYTICS_TOKEN).'];
        }
        $zone = (string) ($site['cf_zone'] ?? '');
        if ($zone === '') {
            $zone = (string) self::findZone((string) $site['host'], $token);
            if ($zone === '') {
                return ['ok' => false, 'error' => 'Nie znaleźliśmy domeny ' . $site['host'] . ' na koncie Cloudflare tego tokenu. Wklej identyfikator strefy (Zone ID) w ustawieniach witryny.'];
            }
            Db::get()->update('seo_sites', ['cf_zone' => $zone], 'id = ?', [(int) $site['id']]);
        }
        $today = strtotime(gmdate('Y-m-d') . ' UTC');
        $daily = [];
        for ($back = self::DAYS; $back > 0; $back -= self::CHUNK_DAYS) {
            $from = gmdate('Y-m-d\TH:i:s\Z', $today - $back * 86400);
            $to = gmdate('Y-m-d\TH:i:s\Z', $today - max(0, $back - self::CHUNK_DAYS) * 86400);
            $res = self::query($token, $zone, $from, $to, 'datetimeHour userAgent edgeResponseStatus', array_keys(self::BOTS));
            if (!$res['ok']) {
                return $res;
            }
            foreach ($res['rows'] as $r) {
                $bot = self::bot((string) ($r['dimensions']['userAgent'] ?? ''));
                $day = substr((string) ($r['dimensions']['datetimeHour'] ?? ''), 0, 10);
                if ($bot === null || !GscFile::isDate($day)) {
                    continue;
                }
                $n = (int) ($r['count'] ?? 0);
                $status = (int) ($r['dimensions']['edgeResponseStatus'] ?? 0);
                $daily[$day . '|' . $bot] ??= ['day' => $day, 'k' => $bot, 'impressions' => 0, 'clicks' => 0, 'value' => 0];
                $daily[$day . '|' . $bot]['impressions'] += $n;
                if ($status >= 200 && $status < 400) {
                    $daily[$day . '|' . $bot]['clicks'] += $n;       // strona podana
                } elseif (in_array($status, [401, 403, 429], true)) {
                    $daily[$day . '|' . $bot]['value'] += $n;        // bot zablokowany
                }
            }
        }
        // Adresy pobierane przez boty, które pokazują linki do źródeł (asystenci i wyszukiwarki AI). Tylko strony podane poprawnie.
        $linking = array_keys(array_filter(self::BOTS, static fn (array $b): bool => $b[2] !== 'training'));
        $from = gmdate('Y-m-d\TH:i:s\Z', $today - self::DAYS * 86400);
        $to = gmdate('Y-m-d\TH:i:s\Z', $today);
        $res = self::query($token, $zone, $from, $to, 'userAgent clientRequestPath', $linking, 'edgeResponseStatus_geq: 200 edgeResponseStatus_lt: 400');
        if (!$res['ok']) {
            return $res;
        }
        $paths = [];
        foreach ($res['rows'] as $r) {
            $bot = self::bot((string) ($r['dimensions']['userAgent'] ?? ''));
            $path = (string) ($r['dimensions']['clientRequestPath'] ?? '');
            if ($bot === null || $path === '' || preg_match('#^/(robots\.txt|favicon|cdn-cgi/|\.well-known/)|\.(css|js|png|jpe?g|gif|webp|svg|ico|woff2?|xml|json|txt)$#i', $path)) {
                continue;
            }
            $key = $bot . '|' . $path;
            $paths[$key] ??= ['k' => $bot, 'k2' => mb_substr($path, 0, 400), 'impressions' => 0];
            $paths[$key]['impressions'] += (int) ($r['count'] ?? 0);
        }
        usort($paths, static fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);
        Stats::putDaily($site, 'cf_bot', 'bot', array_values($daily));
        Stats::clear($site, 'cf_bot', ['bot_page']);
        Stats::replace($site, 'cf_bot', 'bot_page', substr($from, 0, 10), gmdate('Y-m-d', $today - 86400), array_slice($paths, 0, 300));
        return ['ok' => true, 'requests' => (int) array_sum(array_column($daily, 'impressions')), 'bots' => count(array_unique(array_column($daily, 'k')))];
    }

    /**
     * Podsumowanie ostatnich 28 dni dla ekranu „Widoczność w AI”.
     * @return array{has: bool, from?: string, to?: string, bots?: array, byCategory?: array, pages?: array, weekly?: array}
     */
    public static function summary(array $site): array
    {
        $id = (int) $site['id'];
        $rows = Stats::daily($id, 'cf_bot', 'bot', self::DAYS + 2);
        if (!$rows) {
            return ['has' => false];
        }
        $byName = [];
        foreach (self::BOTS as [$name, $operator, $category]) {
            $byName[$name] = [$operator, $category];
        }
        $bots = [];
        $byCategory = array_fill_keys(array_keys(self::CATEGORIES), 0);
        $assistantDays = [];
        foreach ($rows as $r) {
            [$operator, $category] = $byName[$r['k']] ?? ['', 'training'];
            $bots[$r['k']] ??= ['name' => $r['k'], 'operator' => $operator, 'category' => $category, 'requests' => 0, 'served' => 0, 'blocked' => 0];
            $bots[$r['k']]['requests'] += $r['impressions'];
            $bots[$r['k']]['served'] += $r['clicks'];
            $bots[$r['k']]['blocked'] += (int) $r['value'];
            $byCategory[$category] += $r['impressions'];
            if ($category === 'assistant') {
                $assistantDays[] = ['day' => $r['day'], 'n' => $r['impressions']];
            }
        }
        $order = array_flip(array_keys(self::CATEGORIES));
        usort($bots, static fn (array $a, array $b): int => [$order[$a['category']], $b['requests']] <=> [$order[$b['category']], $a['requests']]);
        [$period] = Stats::pair($id, 'cf_bot', 'bot_page');
        $pages = [];
        foreach (Stats::rows($id, 'cf_bot', 'bot_page', $period, 'impressions DESC, clicks DESC', 300) as $p) {
            $pages[$p['k2']] ??= ['path' => $p['k2'], 'requests' => 0, 'bots' => []];
            $pages[$p['k2']]['requests'] += $p['impressions'];
            $pages[$p['k2']]['bots'][$p['k']] = $p['impressions'];
        }
        usort($pages, static fn (array $a, array $b): int => $b['requests'] <=> $a['requests']);
        return [
            'has' => true, 'from' => $rows[0]['day'], 'to' => $rows[count($rows) - 1]['day'],
            'bots' => $bots, 'byCategory' => $byCategory, 'pages' => array_slice($pages, 0, 12),
            'weekly' => Insights::weekly($assistantDays, 'n'),
        ];
    }

    /** Nazwa bota rozpoznana w nagłówku User-Agent. */
    public static function bot(string $userAgent): ?string
    {
        $ua = mb_strtolower($userAgent);
        foreach (self::BOTS as $needle => [$name]) {
            if (str_contains($ua, $needle)) {
                return $name;
            }
        }
        return null;
    }

    /** Treść zapytania GraphQL. Wartości pochodzą z kodu i ze sprawdzonych pól, nie z formularzy. */
    public static function graphql(string $zone, string $from, string $to, string $dimensions, array $needles, string $extra = ''): string
    {
        $or = implode(' ', array_map(static fn (string $n): string => '{ userAgent_like: "%' . $n . '%" }', $needles));
        return '{ viewer { zones(filter: { zoneTag: "' . $zone . '" }) { httpRequestsAdaptiveGroups('
            . 'filter: { datetime_geq: "' . $from . '" datetime_lt: "' . $to . '" requestSource: "eyeball" ' . ($extra !== '' ? $extra . ' ' : '') . 'OR: [' . $or . '] } '
            . 'limit: ' . self::LIMIT . ' orderBy: [count_DESC]) { count dimensions { ' . $dimensions . ' } } } } }';
    }

    /** @return array{ok: bool, rows?: array, error?: string} */
    private static function query(string $token, string $zone, string $from, string $to, string $dimensions, array $needles, string $extra = ''): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $zone)) {
            return ['ok' => false, 'error' => 'Identyfikator strefy Cloudflare ma zły format.'];
        }
        $r = Http::postJson(self::ENDPOINT, ['query' => self::graphql($zone, $from, $to, $dimensions, $needles, $extra)], ['Authorization' => 'Bearer ' . $token]);
        $j = json_decode((string) $r['body'], true);
        if ($r['status'] === 0) {
            return ['ok' => false, 'error' => 'Brak połączenia z Cloudflare. Spróbuj ponownie za chwilę.'];
        }
        $err = is_array($j) ? (string) ($j['errors'][0]['message'] ?? '') : '';
        if ($r['status'] === 401 || $r['status'] === 403 || preg_match('/not authorized|authentication|permission|does not have access/i', $err)) {
            return ['ok' => false, 'error' => 'Token Cloudflare nie ma dostępu do statystyk tej domeny. Potrzebne uprawnienie: Zone → Analytics → Read dla tej strefy.'];
        }
        if ($r['status'] !== 200 || $err !== '' || !is_array($j)) {
            return ['ok' => false, 'error' => 'Cloudflare odpowiedział błędem' . ($err !== '' ? ': ' . mb_substr($err, 0, 200) : ' (kod ' . (int) $r['status'] . ')') . '.'];
        }
        $rows = $j['data']['viewer']['zones'][0]['httpRequestsAdaptiveGroups'] ?? null;
        if (!is_array($rows)) {
            return ['ok' => false, 'error' => 'Cloudflare nie zwrócił danych dla tej strefy. Sprawdź identyfikator strefy (Zone ID).'];
        }
        return ['ok' => true, 'rows' => $rows];
    }

    /** Identyfikator strefy po nazwie domeny. Działa, gdy token ma też uprawnienie Zone → Zone → Read. */
    private static function findZone(string $host, string $token): ?string
    {
        $j = Http::getJson('https://api.cloudflare.com/client/v4/zones?name=' . rawurlencode($host), ['Authorization' => 'Bearer ' . $token]);
        $id = (string) ($j['result'][0]['id'] ?? '');
        return preg_match('/^[a-f0-9]{32}$/', $id) ? $id : null;
    }

    private static function tokenValue(): string
    {
        // Jeden token Cloudflare może mieć oba uprawnienia (Browser Rendering i Analytics), więc osobna zmienna jest opcjonalna.
        return trim((string) Config::get('CF_ANALYTICS_TOKEN')) ?: trim((string) Config::get('CF_BROWSER_TOKEN'));
    }
}
