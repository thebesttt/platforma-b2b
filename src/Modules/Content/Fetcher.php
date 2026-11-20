<?php

declare(strict_types=1);

namespace App\Modules\Content;

use App\Core\Config;
use App\Core\Http;
use App\Modules\Audit\Audit;

/**
 * Pobieranie sprawdzanej strony. Korzysta z silnika audytu, który każde połączenie sprawdza pod kątem adresów
 * wewnętrznych (ochrona przed SSRF), także po przekierowaniu. W testach odpowiedzi pochodzą z plików (HTTP_FIXTURES).
 */
final class Fetcher
{
    /** Tak przedstawia się zwykła przeglądarka. Z tą odpowiedzią porównujemy odpowiedzi dla botów. */
    public const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

    /** @var array<int, array{url: string, ua: string}> dziennik zapytań (testy) */
    public static array $log = [];

    /** @return array{ok: bool, status: int, headers: array<string, string[]>, body: string, url: string, time: float, ssl_error: bool, error?: string} */
    public static function get(string $url, ?string $ua = null, int $timeout = 15, int $maxBody = 3_000_000): array
    {
        $ua ??= self::BROWSER;
        self::$log[] = ['url' => $url, 'ua' => $ua];
        $started = microtime(true);
        if ((string) Config::get('HTTP_FIXTURES') !== '') {
            // W testach nazwę bota przekazujemy w treści zapytania, żeby dało się przygotować inną odpowiedź dla bota.
            $r = Http::request('GET', $url, 'User-Agent: ' . $ua);
            $headers = [];
            foreach ($r['headers'] as $k => $v) {
                $headers[strtolower((string) $k)] = (array) $v;
            }
            return ['ok' => $r['status'] > 0, 'status' => (int) $r['status'], 'headers' => $headers, 'body' => (string) $r['body'], 'url' => $url,
                'time' => (float) ($r['time'] ?? 0.2), 'ssl_error' => !empty($r['ssl_error'])];
        }
        try {
            $scanner = Audit::scanner();
            $scanner->normalize_url($url);
            $r = $scanner->request($url, ['user_agent' => $ua, 'timeout' => $timeout, 'max_body' => $maxBody, 'certinfo' => str_starts_with($url, 'https://')]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'headers' => [], 'body' => '', 'url' => $url, 'time' => 0.0, 'ssl_error' => false, 'error' => Audit::localize($e->getMessage())];
        }
        return ['ok' => (bool) $r['ok'], 'status' => (int) $r['status'], 'headers' => (array) $r['headers'], 'body' => (string) $r['body'], 'url' => $url,
            'time' => round(microtime(true) - $started, 2), 'ssl_error' => !empty($r['ssl_error'])] + (!empty($r['error']) ? ['error' => Audit::localize((string) $r['error'])] : []);
    }

    /**
     * Pobiera adres, idąc za przekierowaniami. Każdy krok jest osobnym, sprawdzonym zapytaniem.
     * @return array{final: array, chain: array<int, array{url: string, status: int}>, loop: bool}
     */
    public static function follow(string $url, ?string $ua = null, int $max = 8): array
    {
        $chain = [];
        $seen = [];
        $r = ['ok' => false, 'status' => 0, 'headers' => [], 'body' => '', 'url' => $url, 'time' => 0.0, 'ssl_error' => false];
        for ($i = 0; $i <= $max; $i++) {
            if (isset($seen[$url])) {
                return ['final' => $r, 'chain' => $chain, 'loop' => true];
            }
            $seen[$url] = true;
            $r = self::get($url, $ua);
            $chain[] = ['url' => $url, 'status' => $r['status']];
            $loc = $r['headers']['location'][0] ?? null;
            if ($r['ok'] && $r['status'] >= 300 && $r['status'] < 400 && $loc) {
                $next = self::absolute($url, (string) $loc);
                if ($next === null) {
                    break;
                }
                $url = $next;
                continue;
            }
            return ['final' => $r, 'chain' => $chain, 'loop' => false];
        }
        return ['final' => $r, 'chain' => $chain, 'loop' => true];
    }

    public static function host(string $url): string
    {
        $h = parse_url($url, PHP_URL_HOST);
        return is_string($h) ? (string) preg_replace('/^www\./', '', strtolower($h)) : '';
    }

    /** Czy dwa hosty należą do tej samej witryny (ta sama domena główna, także z subdomeną). */
    public static function sameSite(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        return self::base($a) === self::base($b);
    }

    private static function base(string $host): string
    {
        $parts = explode('.', $host);
        $n = count($parts);
        if ($n <= 2) {
            return $host;
        }
        // Domeny typu firma.com.pl, firma.co.uk: trzy ostatnie człony.
        $second = $parts[$n - 2];
        $take = in_array($second, ['com', 'net', 'org', 'edu', 'gov', 'co', 'info', 'biz', 'waw', 'krakow', 'wroclaw', 'poznan'], true) && strlen($parts[$n - 1]) === 2 ? 3 : 2;
        return implode('.', array_slice($parts, -$take));
    }

    /** Adres bezwzględny z adresu strony i wartości atrybutu href. Null dla mailto:, tel:, javascript: i kotwic. */
    public static function absolute(string $base, string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || $href[0] === '#' || preg_match('/^(mailto|tel|javascript|data|sms|whatsapp|skype|viber):/i', $href)) {
            return null;
        }
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $b = parse_url($base);
        if (!$b || empty($b['host'])) {
            return null;
        }
        $origin = ($b['scheme'] ?? 'https') . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($href, '//')) {
            return ($b['scheme'] ?? 'https') . ':' . $href;
        }
        if ($href[0] === '/') {
            return $origin . $href;
        }
        if ($href[0] === '?') {
            return $origin . ($b['path'] ?? '/') . $href;
        }
        if (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $href)) {
            return null;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
        $path = $dir . $href;
        // Usuwamy „./” i „../”.
        $out = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '..') {
                array_pop($out);
            } elseif ($seg !== '.') {
                $out[] = $seg;
            }
        }
        return $origin . '/' . ltrim(implode('/', $out), '/');
    }

    /** Adres do porównań: bez kotwicy, bez końcowego ukośnika, host małymi literami i bez „www.”. */
    public static function canonicalForm(string $url): string
    {
        $p = parse_url($url);
        if (!$p || empty($p['host'])) {
            return $url;
        }
        $path = rtrim($p['path'] ?? '', '/');
        return strtolower($p['scheme'] ?? 'https') . '://' . preg_replace('/^www\./', '', strtolower($p['host'])) . $path . (isset($p['query']) ? '?' . $p['query'] : '');
    }
}
