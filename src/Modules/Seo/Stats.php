<?php

declare(strict_types=1);

namespace App\Modules\Seo;

use App\Core\Db;

/**
 * Magazyn liczb o widoczności. Każdy wiersz to: źródło, wymiar, okres, klucz i liczby.
 *
 * Źródła: gsc (Search Console: skuteczność), gsc_ai (Search Console: funkcje generatywnej AI), bing_ai (Bing: AI Performance),
 * ga4_ai (sesje z asystentów AI), cf_bot (boty AI w Cloudflare).
 * Dane dzienne mają okres jednego dnia (wymiar „date” i „bot”). Pozostałe wymiary opisują cały okres raportu.
 */
final class Stats
{
    /** Ile okresów raportu trzymamy na źródło i wymiar. Starsze kasujemy. */
    public const KEEP_PERIODS = 6;

    public const KEEP_DAYS = 500;

    private const COLS = ['org_id', 'site_id', 'source', 'dim', 'period_start', 'period_end', 'k', 'k2', 'clicks', 'impressions', 'position', 'value'];

    /**
     * Zastępuje wiersze jednego okresu. Wiersz: ['k' => ..., 'k2' => ..., 'clicks' => ..., 'impressions' => ..., 'position' => ..., 'value' => ...].
     */
    public static function replace(array $site, string $source, string $dim, string $start, string $end, array $rows): int
    {
        $db = Db::get();
        return (int) $db->transaction(static function (Db $db) use ($site, $source, $dim, $start, $end, $rows): int {
            $db->run(
                'DELETE FROM seo_stats WHERE site_id = ? AND source = ? AND dim = ? AND period_start = ? AND period_end = ?',
                [(int) $site['id'], $source, $dim, $start, $end]
            );
            return self::insert($db, $site, $source, $dim, array_map(static fn (array $r): array => $r + ['start' => $start, 'end' => $end], $rows));
        });
    }

    /** Dane dzienne: każdy wiersz ma klucz „day” (RRRR-MM-DD). Zastępujemy tylko dni, które przyszły. */
    public static function putDaily(array $site, string $source, string $dim, array $rows): int
    {
        if (!$rows) {
            return 0;
        }
        $days = array_values(array_unique(array_column($rows, 'day')));
        $db = Db::get();
        return (int) $db->transaction(static function (Db $db) use ($site, $source, $dim, $rows, $days): int {
            foreach (array_chunk($days, 400) as $chunk) {
                $db->run(
                    'DELETE FROM seo_stats WHERE site_id = ? AND source = ? AND dim = ? AND period_start IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                    [(int) $site['id'], $source, $dim, ...$chunk]
                );
            }
            $n = self::insert($db, $site, $source, $dim, array_map(static fn (array $r): array => $r + ['start' => $r['day'], 'end' => $r['day']], $rows));
            $db->run(
                'DELETE FROM seo_stats WHERE site_id = ? AND source = ? AND dim = ? AND period_start < ?',
                [(int) $site['id'], $source, $dim, gmdate('Y-m-d', time() - self::KEEP_DAYS * 86400)]
            );
            return $n;
        });
    }

    /** Kasuje wszystkie okresy źródła w podanych wymiarach (API przysyła komplet na nowo). */
    public static function clear(array $site, string $source, array $dims): void
    {
        if ($dims) {
            Db::get()->run(
                'DELETE FROM seo_stats WHERE site_id = ? AND source = ? AND dim IN (' . implode(', ', array_fill(0, count($dims), '?')) . ')',
                [(int) $site['id'], $source, ...$dims]
            );
        }
    }

    /** Zostawia najnowsze okresy raportu, starsze kasuje. Dane dzienne mają własny limit w putDaily(). */
    public static function prune(array $site, string $source, array $daily = ['date', 'bot']): void
    {
        $db = Db::get();
        $periods = $db->all(
            'SELECT DISTINCT period_start, period_end FROM seo_stats WHERE site_id = ? AND source = ? AND dim NOT IN (' . implode(', ', array_fill(0, count($daily), '?')) . ')
             ORDER BY period_end DESC, period_start DESC',
            [(int) $site['id'], $source, ...$daily]
        );
        foreach (array_slice($periods, self::KEEP_PERIODS) as $p) {
            $db->run(
                'DELETE FROM seo_stats WHERE site_id = ? AND source = ? AND period_start = ? AND period_end = ? AND dim NOT IN (' . implode(', ', array_fill(0, count($daily), '?')) . ')',
                [(int) $site['id'], $source, $p['period_start'], $p['period_end'], ...$daily]
            );
        }
    }

    /**
     * Okresy raportu dla wymiaru, od najnowszego.
     * @return array<int, array{start: string, end: string, days: int}>
     */
    public static function periods(int $siteId, string $source, string $dim): array
    {
        $out = [];
        foreach (Db::get()->all(
            'SELECT DISTINCT period_start, period_end FROM seo_stats WHERE site_id = ? AND source = ? AND dim = ? ORDER BY period_end DESC, period_start DESC',
            [$siteId, $source, $dim]
        ) as $p) {
            $out[] = ['start' => (string) $p['period_start'], 'end' => (string) $p['period_end'], 'days' => self::days((string) $p['period_start'], (string) $p['period_end'])];
        }
        return $out;
    }

    /**
     * Bieżący okres (najnowszy) i poprzedni: najnowszy z tych, które kończą się przed początkiem bieżącego.
     * @return array{0: ?array, 1: ?array}
     */
    public static function pair(int $siteId, string $source, string $dim): array
    {
        $all = self::periods($siteId, $source, $dim);
        $cur = $all[0] ?? null;
        $prev = null;
        if ($cur) {
            foreach ($all as $p) {
                if ($p['end'] < $cur['start']) {
                    $prev = $p;
                    break;
                }
            }
        }
        return [$cur, $prev];
    }

    /** @return array<int, array<string, mixed>> wiersze okresu z liczbami jako int i float */
    public static function rows(int $siteId, string $source, string $dim, ?array $period, string $order = 'impressions DESC, clicks DESC', int $limit = 5000): array
    {
        if (!$period) {
            return [];
        }
        $order = in_array($order, ['impressions DESC, clicks DESC', 'clicks DESC, impressions DESC', 'value DESC', 'k'], true) ? $order : 'impressions DESC, clicks DESC';
        return array_map(self::cast(...), Db::get()->all(
            "SELECT k, k2, clicks, impressions, position, value FROM seo_stats
             WHERE site_id = ? AND source = ? AND dim = ? AND period_start = ? AND period_end = ? ORDER BY $order, id LIMIT " . max(1, min(20000, $limit)),
            [$siteId, $source, $dim, $period['start'], $period['end']]
        ));
    }

    /** @return array<int, array<string, mixed>> dane dzienne od najstarszego dnia; każdy wiersz ma klucz „day” */
    public static function daily(int $siteId, string $source, string $dim, int $days = 190): array
    {
        $rows = Db::get()->all(
            'SELECT period_start AS day, k, clicks, impressions, position, value FROM seo_stats
             WHERE site_id = ? AND source = ? AND dim = ? AND period_start >= ? ORDER BY period_start, id',
            [$siteId, $source, $dim, gmdate('Y-m-d', time() - $days * 86400)]
        );
        return array_map(static fn (array $r): array => self::cast($r) + ['day' => (string) $r['day']], $rows);
    }

    public static function has(int $siteId, string $source): bool
    {
        return (bool) Db::get()->val('SELECT 1 FROM seo_stats WHERE site_id = ? AND source = ? LIMIT 1', [$siteId, $source]);
    }

    public static function days(string $start, string $end): int
    {
        return max(1, (int) round((strtotime($end . ' UTC') - strtotime($start . ' UTC')) / 86400) + 1);
    }

    private static function insert(Db $db, array $site, string $source, string $dim, array $rows): int
    {
        $one = '(' . implode(', ', array_fill(0, count(self::COLS), '?')) . ')';
        $perQuery = intdiv($db->driver() === 'sqlite' ? 900 : 12000, count(self::COLS));
        $n = 0;
        foreach (array_chunk($rows, $perQuery) as $chunk) {
            $params = [];
            foreach ($chunk as $r) {
                array_push(
                    $params,
                    (int) $site['org_id'],
                    (int) $site['id'],
                    $source,
                    $dim,
                    $r['start'],
                    $r['end'],
                    mb_substr((string) ($r['k'] ?? ''), 0, 500),
                    mb_substr((string) ($r['k2'] ?? ''), 0, 500),
                    max(0, (int) ($r['clicks'] ?? 0)),
                    max(0, (int) ($r['impressions'] ?? 0)),
                    isset($r['position']) ? round((float) $r['position'], 2) : null,
                    isset($r['value']) ? round((float) $r['value'], 2) : null
                );
                $n++;
            }
            $db->run('INSERT INTO seo_stats (' . implode(', ', self::COLS) . ') VALUES ' . implode(', ', array_fill(0, count($chunk), $one)), $params);
        }
        return $n;
    }

    private static function cast(array $r): array
    {
        return [
            'k' => (string) ($r['k'] ?? ''),
            'k2' => (string) ($r['k2'] ?? ''),
            'clicks' => (int) $r['clicks'],
            'impressions' => (int) $r['impressions'],
            'position' => $r['position'] === null ? null : (float) $r['position'],
            'value' => $r['value'] === null ? null : (float) $r['value'],
        ];
    }
}
