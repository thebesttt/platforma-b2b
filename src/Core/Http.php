<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Klient HTTP dla zaufanych API (BZP, TED, KRS, biała lista, Anthropic).
 * W testach odpowiedzi pochodzą z katalogu HTTP_FIXTURES (plik routes.php: wzorzec adresu => plik).
 * Do skanowania stron podawanych przez użytkowników służy silnik audytu z ochroną SSRF, nie ta klasa.
 */
final class Http
{
    public const UA = 'PlatformaB2B/0.1 (+kontakt: administrator platformy)';

    /** @var array<int, array{method: string, url: string}> */
    public static array $log = [];

    /** @return array{status: int, headers: array<string, string>, body: string, error?: string} */
    public static function request(string $method, string $url, ?string $body = null, array $headers = [], int $timeout = 20): array
    {
        self::$log[] = ['method' => $method, 'url' => $url];
        $fixtures = (string) Config::get('HTTP_FIXTURES');
        if ($fixtures !== '') {
            return self::fromFixture($fixtures, $method, $url, (string) $body);
        }
        $ch = curl_init($url);
        $respHeaders = [];
        $h = ['User-Agent: ' . self::UA, 'Accept-Language: pl,en;q=0.8'];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                $p = strpos($line, ':');
                if ($p !== false) {
                    $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
        if ($resp === false) {
            return ['status' => 0, 'headers' => [], 'body' => '', 'error' => $err ?: 'Brak połączenia'];
        }
        return ['status' => $status, 'headers' => $respHeaders, 'body' => (string) $resp];
    }

    public static function get(string $url, array $headers = [], int $timeout = 20): array
    {
        return self::request('GET', $url, null, $headers, $timeout);
    }

    /** GET z dekodowaniem JSON; null przy błędzie. */
    public static function getJson(string $url, array $headers = [], int $timeout = 20): ?array
    {
        $r = self::get($url, $headers + ['Accept' => 'application/json'], $timeout);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            return null;
        }
        $j = json_decode($r['body'], true);
        return is_array($j) ? $j : null;
    }

    public static function postJson(string $url, array $data, array $headers = [], int $timeout = 30): array
    {
        return self::request('POST', $url, json_out($data), $headers + ['Content-Type' => 'application/json', 'Accept' => 'application/json'], $timeout);
    }

    private static function fromFixture(string $dir, string $method, string $url, string $body = ''): array
    {
        $routes = is_file($dir . '/routes.php') ? require $dir . '/routes.php' : [];
        foreach ($routes as $pattern => $file) {
            // Wzorzec dopasowujemy do „METODA adres” oraz treści zapytania w kolejnej linii (np. rozróżnienie zapytań do AI).
            if (preg_match($pattern, $method . ' ' . $url . "\n" . $body)) {
                if (is_array($file)) {
                    return $file + ['headers' => []];
                }
                $path = $dir . '/' . $file;
                $body = (string) file_get_contents($path);
                return ['status' => 200, 'headers' => [], 'body' => $body];
            }
        }
        return ['status' => 404, 'headers' => [], 'body' => '', 'error' => 'Brak fixture dla ' . $method . ' ' . $url];
    }
}
