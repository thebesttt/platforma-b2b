<?php

declare(strict_types=1);

namespace App\Modules\Content;

/** Lista podstron z mapy witryny (sitemap.xml), żeby dodać do audytu wiele stron naraz. */
final class Sitemap
{
    public const MAX_URLS = 150;

    private const MAX_FILES = 4;

    /**
     * @return array{ok: bool, urls?: string[], source?: string, error?: string}
     */
    public static function urls(string $site): array
    {
        $site = trim($site);
        if (!preg_match('#^https?://#i', $site)) {
            $site = 'https://' . $site;
        }
        $host = Fetcher::host($site);
        if ($host === '' || !preg_match('/^[a-z0-9.\-]+\.[a-z]{2,}$/', $host)) {
            return ['ok' => false, 'error' => 'Podaj adres witryny, np. twojafirma.pl.'];
        }
        $p = parse_url($site);
        $origin = ($p['scheme'] ?? 'https') . '://' . $p['host'];
        // Adres mapy witryny bierzemy z robots.txt, a gdy go tam nie ma, sprawdzamy /sitemap.xml.
        $queue = [];
        $robots = Fetcher::follow($origin . '/robots.txt', null, 3)['final'];
        if ((int) $robots['status'] === 200 && preg_match_all('/^\s*sitemap\s*:\s*(\S+)/im', (string) $robots['body'], $m)) {
            foreach ($m[1] as $u) {
                if (Fetcher::sameSite($host, Fetcher::host($u))) {
                    $queue[] = $u;
                }
            }
        }
        $queue = $queue ?: [$origin . '/sitemap.xml'];
        $source = $queue[0];
        $urls = [];
        $files = 0;
        while ($queue && $files < self::MAX_FILES && count($urls) < self::MAX_URLS) {
            $file = array_shift($queue);
            $files++;
            $r = Fetcher::follow($file, null, 3)['final'];
            if ((int) $r['status'] !== 200 || trim((string) $r['body']) === '') {
                continue;
            }
            $body = (string) $r['body'];
            if (str_starts_with($body, "\x1f\x8b") && function_exists('gzdecode')) {
                $body = (string) @gzdecode($body);
            }
            if (!preg_match_all('#<loc>\s*(?:<!\[CDATA\[)?\s*([^<\]\s]+)#i', $body, $m)) {
                continue;
            }
            $isIndex = stripos($body, '<sitemapindex') !== false;
            foreach ($m[1] as $loc) {
                $loc = html_entity_decode(trim($loc), ENT_QUOTES | ENT_XML1, 'UTF-8');
                if (!preg_match('#^https?://#i', $loc) || !Fetcher::sameSite($host, Fetcher::host($loc)) || strlen($loc) > 500) {
                    continue;
                }
                if ($isIndex) {
                    $queue[] = $loc;
                } elseif (!preg_match('/\.(jpe?g|png|gif|webp|svg|pdf|xml|zip|mp4)(\?|$)/i', $loc)) {
                    $urls[$loc] = true;
                }
                if (count($urls) >= self::MAX_URLS) {
                    break;
                }
            }
        }
        if (!$urls) {
            return ['ok' => false, 'error' => 'Nie znaleźliśmy mapy witryny pod adresem ' . $source . '. Dodaj strony pojedynczo.'];
        }
        return ['ok' => true, 'urls' => array_keys($urls), 'source' => $source];
    }

    /** Rodzaj strony odgadnięty z adresu. Użytkownik może go zmienić przed dodaniem. */
    public static function guessType(string $url): string
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: '/'));
        if ($path === '/' || $path === '') {
            return 'home';
        }
        if (preg_match('#/(blog|poradnik|poradniki|artykul|artykuly|aktualnosci|news|wiedza|baza-wiedzy)(/|$)#', $path)) {
            return 'article';
        }
        if (preg_match('#/(produkt|produkty|product|products|p|sklep)/[^/]+#', $path)) {
            return 'product';
        }
        return 'other';
    }
}
