<?php

declare(strict_types=1);

namespace App\Modules\Content;

use App\Core\Config;
use App\Core\Http;

/**
 * Dwie zewnętrzne usługi pomiarowe audytu treści:
 * - PageSpeed Insights (Google): Core Web Vitals z danych użytkowników Chrome, wskaźnik E1,
 * - Cloudflare Browser Run: strona po uruchomieniu skryptów przy ekranie 360 px, wskaźniki A6 i E2.
 * Gdy usługa nie jest podłączona albo nie odpowiada, wskaźnik dostaje stan „brak pomiaru” i nie odbiera punktów.
 */
final class Services
{
    public const PSI = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    /** Skrypt wstrzykiwany w renderowaną stronę: zapisuje szerokość treści i ekranu w atrybucie, który potem czytamy z HTML. */
    private const MEASURE_JS = "document.documentElement.setAttribute('data-pd-width', document.documentElement.scrollWidth + '/' + document.documentElement.clientWidth);";

    /** @return array{lcp?: float, inp?: float, cls?: float, source?: string, error?: string} LCP i INP w milisekundach */
    public static function pagespeed(string $url): array
    {
        $api = self::PSI . '?strategy=mobile&category=performance&locale=pl&url=' . rawurlencode($url);
        $key = (string) Config::get('PSI_KEY');
        if ($key !== '') {
            $api .= '&key=' . rawurlencode($key);
        }
        $r = Http::get($api, ['Accept' => 'application/json'], 60);
        $j = json_decode((string) $r['body'], true);
        if ($r['status'] !== 200 || !is_array($j)) {
            $msg = is_array($j) ? (string) ($j['error']['message'] ?? '') : '';
            return ['error' => 'PageSpeed Insights nie odpowiedział (kod ' . $r['status'] . ')' . ($msg !== '' ? ': ' . truncate($msg, 160) : '.')
                . ($key === '' ? ' Bez klucza PSI_KEY Google mocno ogranicza liczbę zapytań.' : '')];
        }
        $out = [];
        $field = $j['loadingExperience']['metrics'] ?? [];
        $map = ['lcp' => 'LARGEST_CONTENTFUL_PAINT_MS', 'inp' => 'INTERACTION_TO_NEXT_PAINT', 'cls' => 'CUMULATIVE_LAYOUT_SHIFT_SCORE'];
        foreach ($map as $k => $name) {
            if (isset($field[$name]['percentile'])) {
                // CLS przychodzi pomnożony przez 100.
                $out[$k] = $k === 'cls' ? (float) $field[$name]['percentile'] / 100 : (float) $field[$name]['percentile'];
            }
        }
        if ($out) {
            return $out + ['source' => !empty($j['loadingExperience']['origin_fallback']) ? 'origin' : 'field'];
        }
        $audits = $j['lighthouseResult']['audits'] ?? [];
        if (isset($audits['largest-contentful-paint']['numericValue'])) {
            $out['lcp'] = (float) $audits['largest-contentful-paint']['numericValue'];
        }
        if (isset($audits['cumulative-layout-shift']['numericValue'])) {
            $out['cls'] = (float) $audits['cumulative-layout-shift']['numericValue'];
        }
        return $out ? $out + ['source' => 'lab'] : ['error' => 'PageSpeed Insights nie zwrócił danych o szybkości tej strony.'];
    }

    public static function renderEnabled(): bool
    {
        return (string) Config::get('CF_ACCOUNT_ID') !== '' && (string) Config::get('CF_BROWSER_TOKEN') !== '';
    }

    /**
     * Strona po uruchomieniu skryptów w przeglądarce Cloudflare, przy ekranie telefonu 360 px.
     * @return array{html?: string, scroll?: array{0: int, 1: int}, error?: string}
     */
    public static function render(string $url): array
    {
        if (!self::renderEnabled()) {
            return ['error' => 'Usługa renderowania stron nie jest podłączona (zmienne CF_ACCOUNT_ID i CF_BROWSER_TOKEN).'];
        }
        $r = Http::postJson('https://api.cloudflare.com/client/v4/accounts/' . rawurlencode((string) Config::get('CF_ACCOUNT_ID')) . '/browser-run/snapshot', [
            'url' => $url,
            'formats' => ['content'],
            'viewport' => ['width' => 360, 'height' => 800],
            'gotoOptions' => ['waitUntil' => 'networkidle2', 'timeout' => 25000],
            'addScriptTag' => [['content' => self::MEASURE_JS]],
        ], ['Authorization' => 'Bearer ' . Config::get('CF_BROWSER_TOKEN')], 45);
        $j = json_decode((string) $r['body'], true);
        $html = is_array($j) && !empty($j['success']) ? ($j['result']['content'] ?? (is_string($j['result'] ?? null) ? $j['result'] : null)) : null;
        if (!is_string($html) || $html === '') {
            $msg = is_array($j) ? (string) ($j['errors'][0]['message'] ?? '') : '';
            return ['error' => 'Usługa renderowania stron nie odpowiedziała (kod ' . $r['status'] . ')' . ($msg !== '' ? ': ' . truncate($msg, 160) : '.')];
        }
        $out = ['html' => $html];
        if (preg_match('/data-pd-width="(\d+)\/(\d+)"/', $html, $m)) {
            $out['scroll'] = [(int) $m[1], (int) $m[2]];
        }
        return $out;
    }
}
