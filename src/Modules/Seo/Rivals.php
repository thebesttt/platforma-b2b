<?php

declare(strict_types=1);

namespace App\Modules\Seo;

use App\Core\Db;
use App\Core\Kv;
use App\Modules\Content\Fetcher;

/**
 * Konkurencja z publicznych danych: mapy witryn (sitemap.xml).
 *
 * Search Console nie pokazuje cudzych witryn, a pozycje konkurentów w wynikach wymagają płatnych usług.
 * Mapa witryny jest jawna i przeznaczona dla robotów, więc z niej wiemy, ile stron ma konkurent, co ostatnio dodał
 * i jakie tematy opisuje, a my nie. Tematy porównujemy po słowach z adresów stron i z własnych zapytań.
 */
final class Rivals
{
    public const MAX_URLS = 3000;

    public const NEW_DAYS = 30;

    private const MAX_FILES = 8;

    /** Odświeżenie jednej domeny nie częściej niż co tyle sekund. */
    private const COOLDOWN = 600;

    /** Słowa z adresów, które nie mówią nic o temacie strony. */
    private const STOP = ['oraz', 'jest', 'jaki', 'jakie', 'jaka', 'przez', 'html', 'index', 'strona', 'strony', 'page', 'blog', 'post', 'news', 'kategoria', 'category',
        'poradnik', 'poradniki', 'artykul', 'artykuly', 'aktualnosci', 'oferta', 'uslugi', 'usluga', 'firma', 'firmy', 'ktory', 'ktora', 'ktore', 'twoj', 'twoja', 'twoje',
        'nasz', 'nasza', 'nasze', 'with', 'your', 'from', 'what', 'that', 'this', 'best', 'jak', 'czy', 'dlaczego', 'kiedy', 'wszystko', 'musisz', 'wiedziec', 'warto',
        'czym', 'tego', 'tych', 'beda', 'moze', 'mozna', 'nalezy', 'home', 'about', 'kontakt', 'contact'];

    /** Adresy techniczne: nie są treścią, więc nie liczymy ich do tematów. */
    private const SKIP_PATH = '#/(tag|tagi|author|autor|page|strona|koszyk|cart|checkout|zamowienie|konto|account|login|logowanie|regulamin|polityka-prywatnosci|privacy|cookies|kontakt|contact|feed|wp-json|search|szukaj)(/|$)#';

    /**
     * Strony z mapy witryny z datą ostatniej zmiany (jeśli mapa ją podaje).
     * @return array{ok: bool, pages?: array<string, ?string>, truncated?: bool, error?: string}
     */
    public static function fetch(string $host): array
    {
        $origin = 'https://' . $host;
        $queue = [];
        $robots = Fetcher::follow($origin . '/robots.txt', null, 3)['final'];
        if ((int) $robots['status'] === 200 && preg_match_all('/^\s*sitemap\s*:\s*(\S+)/im', (string) $robots['body'], $m)) {
            foreach ($m[1] as $u) {
                if (Fetcher::sameSite($host, Fetcher::host($u))) {
                    $queue[] = $u;
                }
            }
        }
        $queue = $queue ?: [$origin . '/sitemap.xml', $origin . '/sitemap_index.xml'];
        $pages = [];
        $files = 0;
        $seen = [];
        while ($queue && $files < self::MAX_FILES && count($pages) < self::MAX_URLS) {
            $file = array_shift($queue);
            if (isset($seen[$file])) {
                continue;
            }
            $seen[$file] = true;
            $files++;
            $r = Fetcher::follow($file, null, 3)['final'];
            $body = (string) $r['body'];
            if ((int) $r['status'] !== 200 || trim($body) === '') {
                continue;
            }
            if (str_starts_with($body, "\x1f\x8b")) {
                $body = (string) @gzdecode($body);
            }
            $isIndex = stripos($body, '<sitemapindex') !== false;
            if (!preg_match_all('#<(url|sitemap)\b[^>]*>(.*?)</\1>#is', $body, $blocks, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($blocks as $b) {
                if (!preg_match('#<loc>\s*(?:<!\[CDATA\[)?\s*([^<\]\s]+)#i', $b[2], $lm)) {
                    continue;
                }
                $loc = html_entity_decode(trim($lm[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
                if (!preg_match('#^https?://#i', $loc) || strlen($loc) > 500 || !Fetcher::sameSite($host, Fetcher::host($loc))) {
                    continue;
                }
                if ($isIndex || strtolower($b[1]) === 'sitemap') {
                    // Mapy tagów, autorów i obrazków pomijamy: szkoda na nie limitu plików.
                    if (!preg_match('/(tag|author|autor|attachment|image|video|media)[^\/]*\.xml/i', $loc)) {
                        $queue[] = $loc;
                    }
                    continue;
                }
                if (preg_match('/\.(jpe?g|png|gif|webp|svg|pdf|xml|zip|mp4)(\?|$)/i', $loc)) {
                    continue;
                }
                $lastmod = preg_match('#<lastmod>\s*(\d{4}-\d{2}-\d{2})#i', $b[2], $dm) && GscFile::isDate($dm[1]) && $dm[1] <= gmdate('Y-m-d', time() + 86400) ? $dm[1] : null;
                $pages[$loc] = $lastmod;
                if (count($pages) >= self::MAX_URLS) {
                    break;
                }
            }
        }
        if (!$pages) {
            return ['ok' => false, 'error' => 'Nie znaleźliśmy mapy witryny ' . $host . ' (sprawdziliśmy robots.txt i /sitemap.xml).'];
        }
        return ['ok' => true, 'pages' => $pages, 'truncated' => count($pages) >= self::MAX_URLS || (bool) $queue];
    }

    /**
     * Pobiera mapę jednej domeny i zapisuje różnice. Pierwsze pobranie to punkt odniesienia: „nowe” są dopiero strony, które przybędą później.
     * @return array{ok: bool, error?: string, total?: int, new?: int, skipped?: bool}
     */
    public static function refresh(array $site, string $host, bool $force = false): array
    {
        $siteId = (int) $site['id'];
        if (!$force && Kv::get('seo-rival:' . $siteId . ':' . $host)) {
            return ['ok' => true, 'skipped' => true];
        }
        Kv::set('seo-rival:' . $siteId . ':' . $host, 1, self::COOLDOWN);
        $res = self::fetch($host);
        if (!$res['ok']) {
            Sites::markSync($site, 'map:' . $host, false, (string) $res['error']);
            return $res;
        }
        $db = Db::get();
        $known = [];
        foreach ($db->all('SELECT url, lastmod FROM seo_rival_pages WHERE site_id = ? AND host = ?', [$siteId, $host]) as $r) {
            $known[(string) $r['url']] = $r['lastmod'];
        }
        $first = !$known;
        $now = now_utc();
        $new = 0;
        $db->transaction(static function (Db $db) use ($res, $known, $first, $now, $site, $siteId, $host, &$new): void {
            foreach ($res['pages'] as $url => $lastmod) {
                if (!array_key_exists($url, $known)) {
                    $db->insert('seo_rival_pages', ['org_id' => (int) $site['org_id'], 'site_id' => $siteId, 'host' => $host, 'url' => $url,
                        'lastmod' => $lastmod, 'baseline' => $first ? 1 : 0, 'first_seen' => $now]);
                    $new += $first ? 0 : 1;
                } elseif ($lastmod !== null && $known[$url] !== $lastmod) {
                    $db->update('seo_rival_pages', ['lastmod' => $lastmod], 'site_id = ? AND url = ?', [$siteId, $url]);
                }
            }
            // Strony, które zniknęły z mapy, kasujemy tylko wtedy, gdy mapę przeczytaliśmy w całości.
            if (empty($res['truncated'])) {
                foreach (array_diff(array_keys($known), array_keys($res['pages'])) as $gone) {
                    $db->run('DELETE FROM seo_rival_pages WHERE site_id = ? AND url = ?', [$siteId, $gone]);
                }
            }
        });
        Sites::markSync($site, 'map:' . $host, true, count($res['pages']) . ' stron');
        return ['ok' => true, 'total' => count($res['pages']), 'new' => $new];
    }

    /**
     * Odświeża mapę własnej witryny i wszystkich konkurentów.
     * @return array<string, array> wynik dla każdej domeny
     */
    public static function refreshAll(array $site, bool $force = false): array
    {
        $out = [];
        foreach (array_merge([(string) $site['host']], Sites::rivals($site)) as $host) {
            $out[$host] = self::refresh($site, $host, $force);
        }
        return $out;
    }

    /**
     * Podsumowanie dla ekranu „Konkurencja”.
     * @return array{own: ?array, rivals: array<int, array>, recent: array, gaps: ?array}
     */
    public static function summary(array $site): array
    {
        $db = Db::get();
        $siteId = (int) $site['id'];
        $since = gmdate('Y-m-d', time() - self::NEW_DAYS * 86400);
        $sinceTs = gmdate('Y-m-d H:i:s', time() - self::NEW_DAYS * 86400);
        $fresh = Sites::find((int) $site['org_id'], $siteId) ?? $site;
        $card = static function (string $host) use ($db, $siteId, $since, $sinceTs, $fresh): array {
            $c = $db->one(
                'SELECT COUNT(*) AS total,
                        SUM(CASE WHEN baseline = 0 AND first_seen >= ? THEN 1 ELSE 0 END) AS added,
                        SUM(CASE WHEN lastmod >= ? THEN 1 ELSE 0 END) AS changed,
                        SUM(CASE WHEN lastmod IS NOT NULL THEN 1 ELSE 0 END) AS dated,
                        MIN(first_seen) AS since
                 FROM seo_rival_pages WHERE site_id = ? AND host = ?',
                [$sinceTs, $since, $siteId, $host]
            ) ?? [];
            return ['host' => $host, 'total' => (int) ($c['total'] ?? 0), 'added' => (int) ($c['added'] ?? 0), 'changed' => (int) ($c['changed'] ?? 0),
                'dated' => (int) ($c['dated'] ?? 0), 'since' => $c['since'] ?? null, 'sync' => Sites::lastSync($fresh, 'map:' . $host)];
        };
        $rivals = array_map($card, Sites::rivals($site));
        $own = $card((string) $site['host']);
        $recent = [];
        if ($rivals) {
            $hosts = array_column($rivals, 'host');
            $marks = implode(', ', array_fill(0, count($hosts), '?'));
            foreach ($db->all(
                "SELECT host, url, lastmod, baseline, first_seen FROM seo_rival_pages
                 WHERE site_id = ? AND host IN ($marks) AND ((baseline = 0 AND first_seen >= ?) OR lastmod >= ?)
                 ORDER BY CASE WHEN baseline = 0 AND first_seen >= ? THEN 0 ELSE 1 END, COALESCE(lastmod, substr(first_seen, 1, 10)) DESC, id DESC LIMIT 40",
                [$siteId, ...$hosts, $sinceTs, $since, $sinceTs]
            ) as $r) {
                $isNew = (int) $r['baseline'] === 0 && (string) $r['first_seen'] >= $sinceTs;
                $recent[] = ['host' => (string) $r['host'], 'url' => (string) $r['url'], 'path' => AiSources::path((string) $r['url'], (string) $r['host']),
                    'new' => $isNew, 'date' => $r['lastmod'] ?: substr((string) $r['first_seen'], 0, 10)];
            }
        }
        return ['own' => $own, 'rivals' => $rivals, 'recent' => $recent, 'gaps' => self::gaps($site)];
    }

    /**
     * Tematy konkurentów, których nie ma w naszych adresach ani zapytaniach.
     * @return array{topics: array, pages: array, ours: int}|null null, gdy nie mamy z czym porównać
     */
    public static function gaps(array $site): ?array
    {
        $db = Db::get();
        $siteId = (int) $site['id'];
        $rivals = Sites::rivals($site);
        if (!$rivals) {
            return null;
        }
        // Nasze słowa: adresy z własnej mapy witryny, strony i zapytania z Search Console.
        $ours = [];
        foreach ($db->all('SELECT url FROM seo_rival_pages WHERE site_id = ? AND host = ?', [$siteId, (string) $site['host']]) as $r) {
            foreach (self::words((string) parse_url((string) $r['url'], PHP_URL_PATH)) as $prefix => $_) {
                $ours[$prefix] = true;
            }
        }
        [$pagePeriod] = Stats::pair($siteId, 'gsc', 'page');
        foreach (Stats::rows($siteId, 'gsc', 'page', $pagePeriod, 'impressions DESC, clicks DESC', 3000) as $p) {
            foreach (self::words((string) parse_url($p['k'], PHP_URL_PATH)) as $prefix => $_) {
                $ours[$prefix] = true;
            }
        }
        [$queryPeriod] = Stats::pair($siteId, 'gsc', 'query');
        foreach (Stats::rows($siteId, 'gsc', 'query', $queryPeriod, 'impressions DESC, clicks DESC', 5000) as $q) {
            foreach (self::words($q['k']) as $prefix => $_) {
                $ours[$prefix] = true;
            }
        }
        if (!$ours) {
            return null;
        }
        $marks = implode(', ', array_fill(0, count($rivals), '?'));
        $topics = [];
        $pages = [];
        foreach ($db->all("SELECT host, url, lastmod FROM seo_rival_pages WHERE site_id = ? AND host IN ($marks) ORDER BY id LIMIT 12000", [$siteId, ...$rivals]) as $r) {
            $path = (string) parse_url((string) $r['url'], PHP_URL_PATH);
            if ($path === '' || $path === '/' || preg_match(self::SKIP_PATH, strtolower($path))) {
                continue;
            }
            $words = self::words($path);
            if (count($words) < 2) {
                continue;
            }
            $missing = array_diff_key($words, $ours);
            // Luka: ponad połowy słów z adresu nie ma u nas nigdzie.
            if (count($missing) * 2 <= count($words)) {
                continue;
            }
            $pages[] = ['host' => (string) $r['host'], 'url' => (string) $r['url'], 'path' => $path, 'lastmod' => $r['lastmod'], 'words' => array_values($missing)];
            foreach ($missing as $prefix => $word) {
                $topics[$prefix] ??= ['word' => $word, 'pages' => 0, 'hosts' => [], 'examples' => []];
                $topics[$prefix]['pages']++;
                $topics[$prefix]['hosts'][(string) $r['host']] = true;
                if (count($topics[$prefix]['examples']) < 3) {
                    $topics[$prefix]['examples'][] = ['host' => (string) $r['host'], 'url' => (string) $r['url'], 'path' => $path];
                }
            }
        }
        // Temat liczy się, gdy wraca na co najmniej dwóch stronach. Wyżej te, które ma więcej niż jeden konkurent.
        $topics = array_filter($topics, static fn (array $t): bool => $t['pages'] >= 2);
        foreach ($topics as &$t) {
            $t['hosts'] = array_keys($t['hosts']);
        }
        unset($t);
        usort($topics, static fn (array $a, array $b): int => [count($b['hosts']), $b['pages']] <=> [count($a['hosts']), $a['pages']]);
        return ['topics' => array_slice($topics, 0, 12), 'pages' => count($pages), 'ours' => count($ours)];
    }

    /**
     * Słowa tematu z adresu albo zapytania: klucz to pierwsze 5 liter (zgrubne sprowadzenie odmiany), wartość to całe słowo.
     * @return array<string, string>
     */
    public static function words(string $text): array
    {
        $t = Sites::fold(rawurldecode($text));
        $t = (string) preg_replace('/\.(html?|php|aspx?)$/', '', $t);
        $out = [];
        foreach (preg_split('/[^a-z0-9]+/', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
            if (strlen($w) < 4 || ctype_digit($w) || in_array($w, self::STOP, true)) {
                continue;
            }
            $out[substr($w, 0, 5)] ??= $w;
        }
        return $out;
    }
}
