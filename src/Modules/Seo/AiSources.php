<?php

declare(strict_types=1);

namespace App\Modules\Seo;

/**
 * Widoczność w odpowiedziach AI z danych, które da się zdobyć bez płatnych narzędzi:
 *  - Search Console, raport o generatywnej AI: wyświetlenia linków do witryny w Przeglądach od AI i Trybie AI (same wyświetlenia),
 *  - Bing Webmaster Tools, raport „AI Performance”: cytowania w Copilocie i podsumowaniach Binga oraz frazy, którymi AI szukało treści,
 *  - Google Analytics: wejścia ze stron asystentów AI.
 * Dwa pierwsze raporty nie mają publicznego API, więc dane wkleja się albo wgrywa z eksportu.
 */
final class AiSources
{
    /** Domeny, z których przychodzą wejścia od asystentów AI. Klucz to końcówka hosta. */
    public const ASSISTANTS = [
        'chatgpt.com' => 'ChatGPT', 'chat.openai.com' => 'ChatGPT', 'openai.com' => 'ChatGPT',
        'perplexity.ai' => 'Perplexity',
        'copilot.microsoft.com' => 'Copilot', 'copilot.com' => 'Copilot',
        'gemini.google.com' => 'Gemini', 'bard.google.com' => 'Gemini',
        'claude.ai' => 'Claude',
        'chat.deepseek.com' => 'DeepSeek', 'deepseek.com' => 'DeepSeek',
        'meta.ai' => 'Meta AI',
        'grok.com' => 'Grok', 'x.ai' => 'Grok',
        'chat.mistral.ai' => 'Le Chat',
        'you.com' => 'You.com',
        'phind.com' => 'Phind',
    ];

    /** Wyrażenie dla filtra źródła sesji w GA4 (dopasowanie częściowe, bez wielkości liter). */
    public const GA4_REGEX = 'chatgpt|openai|perplexity|copilot|gemini\.google|bard\.google|claude\.ai|deepseek|meta\.ai|grok|x\.ai|mistral|you\.com|phind';

    /** Rodzaje danych wklejanych ręcznie: źródło, wymiar, nazwa liczby, opis. */
    public const KINDS = [
        'gsc_ai_page' => ['gsc_ai', 'page', 'impressions', 'Google: strony w odpowiedziach AI (adres i wyświetlenia)'],
        'bing_page' => ['bing_ai', 'page', 'value', 'Bing i Copilot: cytowane strony (adres i cytowania)'],
        'bing_query' => ['bing_ai', 'query', 'value', 'Bing i Copilot: frazy, którymi AI szukało treści (fraza i cytowania)'],
        'ga4_source' => ['ga4_ai', 'source', 'value', 'Google Analytics: źródła sesji (źródło i sesje)'],
    ];

    public const MAX_ROWS = 1000;

    /** Nazwa asystenta dla hosta albo wartości utm_source („chatgpt.com”, „perplexity”). Null, gdy to nie asystent AI. */
    public static function assistant(string $source): ?string
    {
        $s = mb_strtolower(trim($source));
        $s = (string) preg_replace('#^[a-z]+://#', '', $s);
        $s = (string) preg_replace('#[/?\s].*$#', '', $s);
        $s = (string) preg_replace('#^www\.#', '', $s);
        if ($s === '') {
            return null;
        }
        foreach (self::ASSISTANTS as $host => $name) {
            if ($s === $host || str_ends_with($s, '.' . $host)) {
                return $name;
            }
        }
        // Wartości utm_source bez domeny, np. „chatgpt” albo „perplexity”.
        return ['chatgpt' => 'ChatGPT', 'perplexity' => 'Perplexity', 'copilot' => 'Copilot', 'gemini' => 'Gemini', 'claude' => 'Claude', 'deepseek' => 'DeepSeek', 'grok' => 'Grok'][$s] ?? null;
    }

    /**
     * Tabela wklejona z raportu albo z pliku CSV: nazwa w pierwszej kolumnie, liczba w pierwszej kolumnie liczbowej za nią.
     * Wiersze bez liczby (nagłówki) pomijamy.
     * @return array<int, array{0: string, 1: int}>
     */
    public static function parseTable(string $text): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line, " \t\u{feff}");
            if ($line === '' || count($out) >= self::MAX_ROWS) {
                continue;
            }
            // Kolejność: tabulatory (tabela skopiowana ze strony), szerokie odstępy, liczba na końcu wiersza, a na końcu CSV.
            if (str_contains($line, "\t")) {
                $cells = explode("\t", $line);
            } elseif (preg_match('/\s{2,}/u', $line)) {
                $cells = preg_split('/\s{2,}/u', $line) ?: [$line];
            } elseif (($loose = self::loose($line)) !== null) {
                $cells = $loose;
            } elseif (str_contains($line, ';')) {
                $cells = str_getcsv($line, ';', '"', '');
            } else {
                $cells = str_getcsv($line, ',', '"', '');
            }
            $label = trim((string) ($cells[0] ?? ''));
            $num = null;
            foreach (array_slice($cells, 1) as $c) {
                $c = trim((string) $c);
                if ($c !== '' && preg_match('/^\d[\d\s\x{a0}.,]*$/u', $c)) {
                    $num = GscFile::int($c);
                    break;
                }
            }
            if ($label === '' || $num === null) {
                continue;
            }
            $out[] = [mb_substr($label, 0, 500), $num];
        }
        return $out;
    }

    /**
     * Zapisuje wklejone dane.
     * @return array{ok: bool, error?: string, saved?: int, skipped?: int}
     */
    public static function import(array $site, string $kind, string $text, string $from, string $to, ?int $total = null): array
    {
        if (!isset(self::KINDS[$kind])) {
            return ['ok' => false, 'error' => 'Wybierz, jakie dane wklejasz.'];
        }
        if (!GscFile::isDate($from) || !GscFile::isDate($to) || $from > $to || Stats::days($from, $to) > 500 || $to > gmdate('Y-m-d', time() + 86400)) {
            return ['ok' => false, 'error' => 'Podaj okres raportu: data „od” przed datą „do”, najwyżej 16 miesięcy i nie z przyszłości.'];
        }
        [$source, $dim, $field] = self::KINDS[$kind];
        $rows = [];
        $skipped = 0;
        foreach (self::parseTable($text) as [$label, $num]) {
            if ($kind === 'ga4_source') {
                // Z listy wszystkich źródeł zostają tylko asystenci AI. Kilka domen jednego asystenta sumujemy.
                $name = self::assistant($label);
                if ($name === null) {
                    $skipped++;
                    continue;
                }
                $rows[$name] = ['k' => $name, $field => ($rows[$name][$field] ?? 0) + $num];
                continue;
            }
            $rows[$label] = ['k' => $label, $field => $num];
        }
        if (!$rows) {
            return ['ok' => false, 'error' => $kind === 'ga4_source' && $skipped
                ? 'Wśród wklejonych źródeł nie ma asystentów AI (np. chatgpt.com, perplexity.ai). Nic nie zapisaliśmy.'
                : 'Nie znaleźliśmy wierszy z nazwą i liczbą. Skopiuj tabelę z raportu razem z kolumną liczb.'];
        }
        $saved = Stats::replace($site, $source, $dim, $from, $to, array_values($rows));
        $sum = array_sum(array_column($rows, $field));
        if ($total !== null && $total > 0) {
            // Suma podana z raportu: dla GA4 to wszystkie sesje witryny, dla pozostałych suma z nagłówka raportu.
            Stats::replace($site, $source, 'total', $from, $to, [['k' => 'report', 'value' => $total, 'impressions' => $source === 'gsc_ai' ? $total : 0]]);
        } elseif ($source !== 'ga4_ai' && !Stats::rows((int) $site['id'], $source, 'total', ['start' => $from, 'end' => $to])) {
            Stats::replace($site, $source, 'total', $from, $to, [['k' => 'rows', 'value' => $sum, 'impressions' => $source === 'gsc_ai' ? $sum : 0]]);
        }
        Stats::prune($site, $source);
        return ['ok' => true, 'saved' => $saved, 'skipped' => $skipped];
    }

    /**
     * Dane dla ekranu „Widoczność w AI” (bez botów z Cloudflare i bez sygnałów z zapytań, te liczą osobne klasy).
     * @return array{google: array, copilot: array, visits: array}
     */
    public static function overview(array $site): array
    {
        $id = (int) $site['id'];
        $path = static fn (string $url): string => self::path($url, (string) $site['host']);

        // Google: strony pokazywane w odpowiedziach AI, obok kliknięć tych samych stron z wyszukiwarki.
        [$gCur, $gPrev] = self::pairAny($id, 'gsc_ai', ['total', 'page']);
        $aiPages = Stats::rows($id, 'gsc_ai', 'page', $gCur, 'impressions DESC, clicks DESC', 1000);
        [$sCur] = Stats::pair($id, 'gsc', 'page');
        $organic = [];
        foreach (Stats::rows($id, 'gsc', 'page', $sCur, 'clicks DESC, impressions DESC', 3000) as $p) {
            $organic[self::key($p['k'])] = $p;
        }
        $cited = [];
        foreach ($aiPages as &$p) {
            $key = self::key($p['k']);
            $cited[$key] = true;
            $p['path'] = $path($p['k']);
            $p['organic'] = $organic[$key] ?? null;
        }
        unset($p);
        $missing = [];
        if ($aiPages) {
            foreach ($organic as $key => $p) {
                if (!isset($cited[$key]) && $p['clicks'] > 0 && count($missing) < 8) {
                    $missing[] = $p + ['path' => $path($p['k'])];
                }
            }
        }
        $gTotal = Stats::rows($id, 'gsc_ai', 'total', $gCur)[0] ?? null;
        $gPrevTotal = Stats::rows($id, 'gsc_ai', 'total', $gPrev)[0] ?? null;

        // Bing i Copilot.
        [$bCur, $bPrev] = self::pairAny($id, 'bing_ai', ['total', 'page', 'query']);
        $bTotal = Stats::rows($id, 'bing_ai', 'total', $bCur)[0] ?? null;
        $bPrevTotal = Stats::rows($id, 'bing_ai', 'total', $bPrev)[0] ?? null;
        $bPages = array_map(static fn (array $p): array => $p + ['path' => $path($p['k'])], Stats::rows($id, 'bing_ai', 'page', $bCur, 'value DESC', 15));

        // Wejścia z asystentów AI.
        [$vCur, $vPrev] = self::pairAny($id, 'ga4_ai', ['source', 'total']);
        $prevBy = [];
        foreach (Stats::rows($id, 'ga4_ai', 'source', $vPrev, 'value DESC', 50) as $r) {
            $prevBy[$r['k']] = (int) $r['value'];
        }
        $visits = array_map(static fn (array $r): array => ['name' => $r['k'], 'sessions' => (int) $r['value'], 'was' => $prevBy[$r['k']] ?? null], Stats::rows($id, 'ga4_ai', 'source', $vCur, 'value DESC', 50));
        $all = Stats::rows($id, 'ga4_ai', 'total', $vCur)[0] ?? null;

        return [
            'google' => [
                'cur' => $gCur, 'prev' => $gPrev,
                'total' => $gTotal ? (int) $gTotal['impressions'] : null, 'totalPartial' => $gTotal && $gTotal['k'] === 'rows',
                'prevTotal' => $gPrevTotal ? (int) $gPrevTotal['impressions'] : null,
                'pages' => array_slice($aiPages, 0, 15), 'pagesCount' => count($aiPages),
                'missing' => $missing, 'organicPeriod' => $sCur,
                'daily' => Stats::daily($id, 'gsc_ai', 'date'),
            ],
            'copilot' => [
                'cur' => $bCur, 'prev' => $bPrev,
                'total' => $bTotal ? (int) $bTotal['value'] : null, 'totalPartial' => $bTotal && $bTotal['k'] === 'rows',
                'prevTotal' => $bPrevTotal ? (int) $bPrevTotal['value'] : null,
                'pages' => $bPages,
                'queries' => Stats::rows($id, 'bing_ai', 'query', $bCur, 'value DESC', 15),
            ],
            'visits' => [
                'cur' => $vCur, 'prev' => $vPrev,
                'rows' => $visits, 'sum' => array_sum(array_column($visits, 'sessions')),
                'prevSum' => $vPrev ? array_sum($prevBy) : null,
                'all' => $all ? (int) $all['value'] : null,
                'pages' => array_map(static fn (array $p): array => ['path' => $p['k'], 'name' => $p['k2'], 'sessions' => (int) $p['value']], Stats::rows($id, 'ga4_ai', 'page', $vCur, 'value DESC', 10)),
            ],
        ];
    }

    /** Ścieżka adresu, gdy adres należy do witryny. Obcy adres zostaje w całości. */
    public static function path(string $url, string $host): string
    {
        $p = parse_url($url);
        $h = (string) preg_replace('/^www\./', '', mb_strtolower((string) ($p['host'] ?? '')));
        if ($h === '' || $h !== $host) {
            return $url;
        }
        return ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    }

    /**
     * Wiersz „nazwa liczba” rozdzielony pojedynczymi spacjami. Liczba może mieć odstęp jako separator tysięcy („1 234”).
     * @return array{0: string, 1: string}|null
     */
    private static function loose(string $line): ?array
    {
        $tokens = preg_split('/\s+/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $i = count($tokens);
        while ($i > 1 && preg_match('/^\d[\d.,]*$/', $tokens[$i - 1])) {
            $i--;
        }
        if ($i === count($tokens) || preg_match('/[,;]$/', $tokens[$i - 1])) {
            return null;
        }
        $num = $tokens[$i];
        if (preg_match('/^\d{1,3}$/', $num)) {
            for ($j = $i + 1; $j < count($tokens) && preg_match('/^\d{3}$/', $tokens[$j]); $j++) {
                $num .= $tokens[$j];
            }
        }
        return [implode(' ', array_slice($tokens, 0, $i)), $num];
    }

    /** Okres bieżący i poprzedni z pierwszego wymiaru, który ma dane. */
    private static function pairAny(int $siteId, string $source, array $dims): array
    {
        foreach ($dims as $dim) {
            [$cur, $prev] = Stats::pair($siteId, $source, $dim);
            if ($cur) {
                return [$cur, $prev];
            }
        }
        return [null, null];
    }

    /** Klucz do łączenia adresów z dwóch raportów: bez protokołu, „www.” i końcowego ukośnika. */
    private static function key(string $url): string
    {
        $u = mb_strtolower(trim($url));
        $u = (string) preg_replace('#^https?://(www\.)?#', '', $u);
        return rtrim($u, '/');
    }
}
