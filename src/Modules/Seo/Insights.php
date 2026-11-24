<?php

declare(strict_types=1);

namespace App\Modules\Seo;

/**
 * Wnioski z danych Search Console: co poprawić najpierw i gdzie widać wpływ odpowiedzi AI.
 *
 * Wszystkie progi są jawne i stałe. Punkt odniesienia dla klikalności liczymy z danych tej samej witryny,
 * a dopiero gdy jest ich za mało, bierzemy wartości umowne (oznaczone w wyniku jako „assumed”).
 */
final class Insights
{
    /** Zapytanie albo strona musi mieć tyle wyświetleń w okresie 28 dni, żeby trafić na listy. Przy innym okresie próg skalujemy. */
    public const MIN_IMPRESSIONS = 30;

    /** Zakres pozycji „tuż za czołówką”: stąd najłatwiej wejść do pierwszej trójki. */
    public const NEAR_FROM = 3.5;

    public const NEAR_TO = 15.5;

    /** Umowna klikalność dla przedziałów pozycji, używana tylko przy zbyt małej liczbie własnych danych. */
    public const ASSUMED_CTR = ['1' => 0.25, '2' => 0.13, '3' => 0.09, '4-5' => 0.05];

    private const QUESTION_WORDS = ['jak', 'co', 'czy', 'ile', 'dlaczego', 'kiedy', 'gdzie', 'kto', 'ktory', 'ktora', 'ktore', 'jaki', 'jaka', 'jakie', 'czym', 'czemu', 'skad', 'po co',
        'how', 'what', 'why', 'when', 'where', 'which', 'who', 'can', 'does', 'is', 'are', 'should'];

    /**
     * Komplet dla ekranu „Google”.
     * @return array<string, mixed>|null null, gdy nie ma żadnych danych z Search Console
     */
    public static function google(array $site): ?array
    {
        $id = (int) $site['id'];
        [$cur, $prev] = Stats::pair($id, 'gsc', 'total');
        if (!$cur) {
            [$cur, $prev] = Stats::pair($id, 'gsc', 'query');
        }
        if (!$cur) {
            return null;
        }
        $terms = Sites::brandTerms($site);
        $queries = self::tag(Stats::rows($id, 'gsc', 'query', $cur), $terms);
        $prevQueries = self::tag(Stats::rows($id, 'gsc', 'query', $prev), $terms);
        $pages = Stats::rows($id, 'gsc', 'page', $cur);
        $prevPages = Stats::rows($id, 'gsc', 'page', $prev);
        $total = Stats::rows($id, 'gsc', 'total', $cur)[0] ?? null;
        $prevTotal = Stats::rows($id, 'gsc', 'total', $prev)[0] ?? null;
        $totals = $total ?: GscFile::total($queries);
        $min = self::minImpressions($cur['days']);
        $scale = $prev ? $cur['days'] / $prev['days'] : 1.0;
        $ref = self::reference($queries, $min);
        return [
            'cur' => $cur, 'prev' => $prev,
            'totals' => self::withCtr($totals), 'prevTotals' => $prevTotal ? self::withCtr($prevTotal) : null,
            // Suma z samych widocznych wierszy jest niepełna: Google ukrywa rzadkie zapytania.
            'totalsPartial' => !$total || ($total['k'] ?? '') === 'rows',
            'scale' => $scale,
            'min' => $min,
            'reference' => $ref,
            'counts' => ['queries' => count($queries), 'pages' => count($pages)],
            'brand' => self::brandSplit($queries, $totals),
            'near' => self::near($queries, $min, $ref['top']),
            'weak' => self::weak($queries, $min, $ref),
            'movers' => $prev ? self::movers($queries, $prevQueries, $scale) : null,
            'pageMovers' => $prev ? self::movers($pages, $prevPages, $scale) : null,
            'cannibal' => self::cannibal(Stats::rows($id, 'gsc', 'query_page', $cur, 'impressions DESC, clicks DESC', 20000), $min),
            'hasQueryPage' => (bool) Stats::periods($id, 'gsc', 'query_page'),
            'topQueries' => array_slice(self::sortBy($queries, 'clicks'), 0, 10),
            'topPages' => array_slice(self::sortBy($pages, 'clicks'), 0, 10),
            'devices' => Stats::rows($id, 'gsc', 'device', $cur, 'clicks DESC, impressions DESC', 5),
        ];
    }

    /**
     * Sygnały o odpowiedziach AI widoczne w danych Search Console.
     * @return array{cur: ?array, prev: ?array, questions: array, questionShare: ?float, squeeze: ?array, min: int}
     */
    public static function aiFromGsc(array $site): array
    {
        $id = (int) $site['id'];
        [$cur, $prev] = Stats::pair($id, 'gsc', 'query');
        if (!$cur) {
            return ['cur' => null, 'prev' => null, 'questions' => [], 'questionShare' => null, 'squeeze' => null, 'min' => self::MIN_IMPRESSIONS];
        }
        $terms = Sites::brandTerms($site);
        $queries = self::tag(Stats::rows($id, 'gsc', 'query', $cur), $terms);
        $min = self::minImpressions($cur['days']);
        $all = array_sum(array_column($queries, 'impressions'));
        $questions = array_values(array_filter($queries, static fn (array $q): bool => !$q['brand'] && self::isQuestion($q['k'])));
        return [
            'cur' => $cur, 'prev' => $prev, 'min' => $min,
            'questions' => array_slice(self::sortBy($questions, 'impressions'), 0, 15),
            'questionShare' => $all > 0 ? array_sum(array_column($questions, 'impressions')) / $all : null,
            'squeeze' => $prev ? self::squeeze($queries, self::tag(Stats::rows($id, 'gsc', 'query', $prev), $terms), $cur['days'] / $prev['days']) : null,
        ];
    }

    /** Próg wyświetleń przeskalowany do długości okresu (30 na 28 dni, nie mniej niż 10). */
    public static function minImpressions(int $days): int
    {
        return max(10, (int) round(self::MIN_IMPRESSIONS * $days / 28));
    }

    /** Pytanie albo długie zapytanie (6 słów i więcej): tak ludzie rozmawiają z wyszukiwarką i asystentem AI. */
    public static function isQuestion(string $query): bool
    {
        $q = Sites::fold($query);
        $words = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) >= 6) {
            return true;
        }
        if (count($words) < 2) {
            return false;
        }
        return in_array($words[0], self::QUESTION_WORDS, true) || in_array($words[0] . ' ' . $words[1], self::QUESTION_WORDS, true) || str_ends_with($q, '?');
    }

    /**
     * Klikalność odniesienia z własnych zapytań niemarkowych. „top” to pozycje 1-3 razem.
     * @return array{top: float, topOwn: bool, buckets: array<string, array{ctr: float, own: bool}>}
     */
    public static function reference(array $queries, int $min): array
    {
        $sum = ['top' => [0, 0, 0], '1' => [0, 0, 0], '2' => [0, 0, 0], '3' => [0, 0, 0], '4-5' => [0, 0, 0]];
        foreach ($queries as $q) {
            if ($q['brand'] || $q['position'] === null || $q['impressions'] < $min) {
                continue;
            }
            $b = self::bucket($q['position']);
            if ($b === null) {
                continue;
            }
            foreach ($b === '4-5' ? [$b] : [$b, 'top'] as $key) {
                $sum[$key][0] += $q['clicks'];
                $sum[$key][1] += $q['impressions'];
                $sum[$key][2]++;
            }
        }
        // Własna średnia liczy się od 5 zapytań i 300 wyświetleń w przedziale. Mniej to przypadek, nie średnia.
        $own = static fn (array $s): bool => $s[2] >= 5 && $s[1] >= 300 && $s[0] > 0;
        $buckets = [];
        foreach (self::ASSUMED_CTR as $b => $assumed) {
            $buckets[$b] = $own($sum[$b]) ? ['ctr' => $sum[$b][0] / $sum[$b][1], 'own' => true] : ['ctr' => $assumed, 'own' => false];
        }
        $topOwn = $own($sum['top']);
        return ['top' => $topOwn ? $sum['top'][0] / $sum['top'][1] : 0.10, 'topOwn' => $topOwn, 'buckets' => $buckets];
    }

    /** Zapytania na pozycjach 4-15 z dużą liczbą wyświetleń. „gain” to szacunek dodatkowych kliknięć przy klikalności z pozycji 1-3. */
    public static function near(array $queries, int $min, float $topCtr): array
    {
        $out = [];
        foreach ($queries as $q) {
            if ($q['brand'] || $q['position'] === null || $q['position'] < self::NEAR_FROM || $q['position'] > self::NEAR_TO || $q['impressions'] < $min) {
                continue;
            }
            $gain = (int) round($q['impressions'] * $topCtr) - $q['clicks'];
            if ($gain > 0) {
                $out[] = $q + ['gain' => $gain];
            }
        }
        usort($out, static fn (array $a, array $b): int => [$b['gain'], $b['impressions']] <=> [$a['gain'], $a['impressions']]);
        return array_slice($out, 0, 15);
    }

    /** Zapytania z pierwszej piątki, których klikalność jest niższa niż połowa odniesienia dla tej pozycji: tytuł i opis nie zachęcają. */
    public static function weak(array $queries, int $min, array $ref): array
    {
        $out = [];
        foreach ($queries as $q) {
            $b = $q['position'] === null ? null : self::bucket($q['position']);
            if ($q['brand'] || $b === null || $q['impressions'] < $min) {
                continue;
            }
            $expected = $ref['buckets'][$b]['ctr'];
            $ctr = $q['clicks'] / $q['impressions'];
            if ($ctr < 0.5 * $expected) {
                $out[] = $q + ['ctr' => $ctr, 'expected' => $expected, 'own' => $ref['buckets'][$b]['own'], 'missed' => (int) round($q['impressions'] * $expected) - $q['clicks']];
            }
        }
        usort($out, static fn (array $a, array $b): int => $b['missed'] <=> $a['missed']);
        return array_slice($out, 0, 12);
    }

    /**
     * Największe spadki i wzrosty kliknięć między okresami. Poprzedni okres skalujemy do długości bieżącego.
     * @return array{down: array, up: array}
     */
    public static function movers(array $cur, array $prev, float $scale = 1.0): array
    {
        $byKey = [];
        foreach ($prev as $p) {
            $byKey[$p['k']] = $p;
        }
        $rows = [];
        $seen = [];
        foreach ($cur as $c) {
            $seen[$c['k']] = true;
            $p = $byKey[$c['k']] ?? null;
            $was = $p ? (int) round($p['clicks'] * $scale) : 0;
            $rows[] = ['k' => $c['k'], 'clicks' => $c['clicks'], 'was' => $was, 'delta' => $c['clicks'] - $was,
                'position' => $c['position'], 'wasPosition' => $p['position'] ?? null, 'impressions' => $c['impressions'],
                'wasImpressions' => $p ? (int) round($p['impressions'] * $scale) : 0, 'gone' => false];
        }
        foreach ($prev as $p) {
            if (!isset($seen[$p['k']])) {
                $was = (int) round($p['clicks'] * $scale);
                $rows[] = ['k' => $p['k'], 'clicks' => 0, 'was' => $was, 'delta' => -$was, 'position' => null, 'wasPosition' => $p['position'],
                    'impressions' => 0, 'wasImpressions' => (int) round($p['impressions'] * $scale), 'gone' => true];
            }
        }
        // Liczy się zmiana o co najmniej 3 kliknięcia i o co najmniej 20%.
        $big = static fn (array $r): bool => abs($r['delta']) >= 3 && abs($r['delta']) >= 0.2 * max($r['was'], $r['clicks']);
        $down = array_values(array_filter($rows, static fn (array $r): bool => $r['delta'] < 0 && $big($r)));
        $up = array_values(array_filter($rows, static fn (array $r): bool => $r['delta'] > 0 && $big($r)));
        usort($down, static fn (array $a, array $b): int => $a['delta'] <=> $b['delta']);
        usort($up, static fn (array $a, array $b): int => $b['delta'] <=> $a['delta']);
        return ['down' => array_slice($down, 0, 10), 'up' => array_slice($up, 0, 10)];
    }

    /**
     * Kanibalizacja: jedno zapytanie, dwie albo więcej stron, z których każda ma co najmniej 20% wyświetleń tego zapytania.
     * Wejście: wiersze zapytanie + strona (k, k2).
     */
    public static function cannibal(array $pairs, int $min): array
    {
        $by = [];
        foreach ($pairs as $r) {
            $by[$r['k']][] = $r;
        }
        $out = [];
        foreach ($by as $query => $rows) {
            $all = array_sum(array_column($rows, 'impressions'));
            if (count($rows) < 2 || $all < $min) {
                continue;
            }
            $strong = array_values(array_filter($rows, static fn (array $r): bool => $r['impressions'] >= 0.2 * $all));
            if (count($strong) < 2) {
                continue;
            }
            usort($strong, static fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);
            $out[] = ['k' => (string) $query, 'impressions' => $all, 'clicks' => array_sum(array_column($rows, 'clicks')), 'pages' => array_slice($strong, 0, 4)];
        }
        usort($out, static fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);
        return array_slice($out, 0, 10);
    }

    /**
     * Zapytania, przy których wyświetleń nie ubyło, pozycja się nie zmieniła, a klikalność spadła o 40% i więcej.
     * Tak wygląda zapytanie, nad którego wynikami pojawiło się coś, co zabiera kliknięcia: odpowiedź AI, reklamy albo mapa.
     */
    public static function squeeze(array $cur, array $prev, float $scale = 1.0): array
    {
        $byKey = [];
        foreach ($prev as $p) {
            $byKey[$p['k']] = $p;
        }
        $out = [];
        foreach ($cur as $c) {
            $p = $byKey[$c['k']] ?? null;
            if (!$p || $c['brand'] || $p['clicks'] * $scale < 5 || $p['impressions'] <= 0 || $c['impressions'] <= 0 || $c['position'] === null || $p['position'] === null) {
                continue;
            }
            $ctr = $c['clicks'] / $c['impressions'];
            $was = $p['clicks'] / $p['impressions'];
            if ($c['impressions'] >= 0.9 * $p['impressions'] * $scale && abs($c['position'] - $p['position']) <= 1.5 && $ctr <= 0.6 * $was) {
                $out[] = ['k' => $c['k'], 'impressions' => $c['impressions'], 'position' => $c['position'], 'wasPosition' => $p['position'],
                    'ctr' => $ctr, 'wasCtr' => $was, 'clicks' => $c['clicks'], 'wasClicks' => (int) round($p['clicks'] * $scale),
                    'lost' => (int) round($p['clicks'] * $scale) - $c['clicks'], 'question' => self::isQuestion($c['k'])];
            }
        }
        usort($out, static fn (array $a, array $b): int => $b['lost'] <=> $a['lost']);
        return array_slice($out, 0, 12);
    }

    /**
     * Podział na zapytania z nazwą marki, pozostałe i ukryte przez Google (różnica między sumą okresu a widocznymi wierszami).
     * @return array<string, array{clicks: int, impressions: int}>
     */
    public static function brandSplit(array $queries, array $totals): array
    {
        $out = ['brand' => ['clicks' => 0, 'impressions' => 0], 'other' => ['clicks' => 0, 'impressions' => 0]];
        foreach ($queries as $q) {
            $key = $q['brand'] ? 'brand' : 'other';
            $out[$key]['clicks'] += $q['clicks'];
            $out[$key]['impressions'] += $q['impressions'];
        }
        $out['hidden'] = [
            'clicks' => max(0, (int) $totals['clicks'] - $out['brand']['clicks'] - $out['other']['clicks']),
            'impressions' => max(0, (int) $totals['impressions'] - $out['brand']['impressions'] - $out['other']['impressions']),
        ];
        return $out;
    }

    /** Dane dzienne zsumowane w tygodnie (od poniedziałku). Niepełny pierwszy i ostatni tydzień pomijamy. */
    public static function weekly(array $daily, string $field = 'clicks'): array
    {
        $weeks = [];
        foreach ($daily as $d) {
            $ts = strtotime($d['day'] . ' UTC');
            $monday = gmdate('Y-m-d', $ts - ((int) gmdate('N', $ts) - 1) * 86400);
            $weeks[$monday] ??= ['sum' => 0, 'n' => 0];
            $weeks[$monday]['sum'] += (float) ($d[$field] ?? 0);
            $weeks[$monday]['n']++;
        }
        $out = [];
        foreach ($weeks as $monday => $w) {
            if ($w['n'] === 7) {
                $out[$monday] = $w['sum'];
            }
        }
        return $out;
    }

    private static function bucket(float $position): ?string
    {
        return match (true) {
            $position < 1.5 => '1',
            $position < 2.5 => '2',
            $position < 3.5 => '3',
            $position <= 5.5 => '4-5',
            default => null,
        };
    }

    private static function tag(array $rows, array $terms): array
    {
        return array_map(static fn (array $r): array => $r + ['brand' => Sites::isBrand($r['k'], $terms)], $rows);
    }

    private static function sortBy(array $rows, string $field): array
    {
        usort($rows, static fn (array $a, array $b): int => [$b[$field], $b['impressions']] <=> [$a[$field], $a['impressions']]);
        return $rows;
    }

    private static function withCtr(array $t): array
    {
        return $t + ['ctr' => $t['impressions'] > 0 ? $t['clicks'] / $t['impressions'] : null];
    }
}
