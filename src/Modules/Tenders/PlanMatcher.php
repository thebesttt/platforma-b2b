<?php

declare(strict_types=1);

namespace App\Modules\Tenders;

use App\Core\Db;
use App\Core\Kv;

/**
 * Dopasowanie pozycji planów zamówień do profili przetargowych.
 *
 * Plany z BZP nie mają kodów CPV, więc liczą się słowa kluczowe z profilu (w przedmiocie i informacjach dodatkowych).
 * Zapowiedzi z TED mają CPV i dopasowują się także po kodach. Województwa, rodzaj zamówienia i słowa wykluczające działają
 * jak przy przetargach. Bliski termin wszczęcia podnosi ocenę, a minięty tylko dostaje ostrzeżenie (pozycja mogła już trafić do BZP).
 */
final class PlanMatcher
{
    public const THRESHOLD = 30;

    /** Ile pozycji sprawdzamy w jednej porcji. */
    private const CHUNK = 2000;

    /** @return array{score: int, reasons: string[]}|null */
    public static function score(array $item, array $profile, ?int $now = null): ?array
    {
        $now ??= time();
        if (($item['status'] ?? 'active') !== 'active') {
            return null;
        }
        $year = (int) gmdate('Y', $now);
        $planYear = isset($item['year']) && $item['year'] !== null ? (int) $item['year'] : null;
        if ($planYear !== null && $planYear < $year) {
            return null;
        }
        if (!empty($profile['sources']) && !str_contains((string) $profile['sources'], (string) ($item['source'] ?? 'bzp'))) {
            return null;
        }
        $score = 0;
        $reasons = [];
        $regions = self::words($profile['voivodeships'] ?? '');
        if ($regions) {
            if (empty($item['voivodeship']) || !in_array($item['voivodeship'], $regions, true)) {
                return null;
            }
            $score += 10;
            $reasons[] = 'Województwo: ' . Regions::name((string) $item['voivodeship']);
        }
        $types = self::words($profile['order_types'] ?? '');
        if ($types && !empty($item['category']) && !in_array($item['category'], $types, true)) {
            return null;
        }
        $text = ' ' . (string) ($item['search'] ?? '') . ' ';
        foreach (self::patterns($profile['exclude_keywords'] ?? '') as $re) {
            if (preg_match($re, $text)) {
                return null;
            }
        }
        $cpvHit = '';
        foreach (self::words($profile['cpv'] ?? '') as $prefix) {
            $prefix = Matcher::cpvPrefix($prefix);
            foreach (self::words((string) ($item['cpv'] ?? '')) as $code) {
                if ($prefix !== '' && str_starts_with($code, $prefix)) {
                    $cpvHit = $code;
                    break 2;
                }
            }
        }
        if ($cpvHit !== '') {
            $score += 40;
            $reasons[] = 'Kod CPV ' . $cpvHit;
        }
        $hits = [];
        foreach (self::patterns($profile['keywords'] ?? '') as $shown => $re) {
            if (preg_match($re, $text)) {
                $hits[] = (string) $shown;
            }
        }
        if ($hits) {
            $score += min(50, 30 + 10 * (count($hits) - 1));
            $reasons[] = 'Słowa kluczowe: ' . implode(', ', array_slice($hits, 0, 4));
        }
        if ($cpvHit === '' && !$hits) {
            return null;
        }
        $v = isset($item['value_estimated']) && $item['value_estimated'] !== '' && $item['value_estimated'] !== null ? (float) $item['value_estimated'] : null;
        $min = isset($profile['min_value']) && $profile['min_value'] !== '' && $profile['min_value'] !== null ? (float) $profile['min_value'] : null;
        $max = isset($profile['max_value']) && $profile['max_value'] !== '' && $profile['max_value'] !== null ? (float) $profile['max_value'] : null;
        if ($v !== null && ($min !== null || $max !== null)) {
            if (($min !== null && $v < $min) || ($max !== null && $v > $max)) {
                $score -= 25;
                $reasons[] = 'Wartość poza Twoim zakresem';
            } else {
                $score += 10;
                $reasons[] = 'Wartość w Twoim zakresie';
            }
        }
        $quarter = !empty($item['quarter']) ? (int) $item['quarter'] : null;
        $nowQuarter = intdiv((int) gmdate('n', $now) - 1, 3) + 1;
        if ($planYear !== null && $planYear > $year) {
            $score += 15;
            $reasons[] = 'Plan na ' . $planYear . ' rok: dużo czasu na przygotowanie';
        } elseif ($quarter !== null && $quarter < $nowQuarter) {
            // Bez kary punktowej: plany często się przesuwają. Lista i tak pokazuje najpierw nadchodzące pozycje.
            $reasons[] = 'Termin z planu minął: sprawdź, czy przetarg nie jest już ogłoszony';
        } elseif ($quarter !== null && $quarter === $nowQuarter) {
            $score += 10;
            $reasons[] = 'Wszczęcie planowane w tym kwartale';
        } elseif ($quarter !== null) {
            $score += 15;
            $reasons[] = 'Jest czas na przygotowanie';
        }
        $score = max(0, min(100, $score));
        return $score >= self::THRESHOLD ? ['score' => $score, 'reasons' => $reasons] : null;
    }

    /**
     * Dopasowuje nowe pozycje planów do aktywnych profili. Postęp każdego profilu (ostatnia sprawdzona pozycja) leży w Kv,
     * więc przerwana praca (limit czasu) wraca w tym samym miejscu. Zwraca liczbę nowych dopasowań.
     */
    public static function run(?int $orgId = null, ?int $profileId = null, float $budgetSeconds = 0.0): int
    {
        $db = Db::get();
        $started = microtime(true);
        $sql = 'SELECT * FROM tender_profiles WHERE active = 1';
        $params = [];
        if ($orgId) {
            $sql .= ' AND org_id = ?';
            $params[] = $orgId;
        }
        if ($profileId) {
            $sql .= ' AND id = ?';
            $params[] = $profileId;
        }
        $year = (int) gmdate('Y');
        $new = 0;
        foreach ($db->all($sql . ' ORDER BY id', $params) as $profile) {
            $pid = (int) $profile['id'];
            $last = (int) (Kv::get(self::cursorKey($pid)) ?? 0);
            $known = [];
            foreach ($db->all('SELECT item_id FROM plan_matches WHERE profile_id = ?', [$pid]) as $r) {
                $known[(int) $r['item_id']] = true;
            }
            while (true) {
                $items = $db->all(
                    "SELECT i.id, i.status, i.category, i.search, i.cpv, i.value_estimated, i.quarter, p.year, p.voivodeship, p.source
                     FROM plan_items i JOIN procurement_plans p ON p.id = i.plan_id
                     WHERE i.id > ? AND (p.year IS NULL OR p.year >= ?) ORDER BY i.id LIMIT " . self::CHUNK,
                    [$last, $year]
                );
                if (!$items) {
                    break;
                }
                $rows = [];
                foreach ($items as $it) {
                    $last = (int) $it['id'];
                    if (isset($known[$last])) {
                        continue;
                    }
                    $s = self::score($it, $profile);
                    if ($s !== null) {
                        $known[$last] = true;
                        $rows[] = [
                            'org_id' => (int) $profile['org_id'], 'profile_id' => $pid, 'item_id' => $last, 'score' => $s['score'],
                            'reasons_json' => json_out($s['reasons']), 'status' => 'new', 'created_at' => now_utc(),
                        ];
                    }
                }
                if ($rows) {
                    $db->upsertMany('plan_matches', $rows, ['profile_id', 'item_id'], [], array_keys($rows[0]));
                    $new += count($rows);
                }
                Kv::set(self::cursorKey($pid), (string) $last);
                if (count($items) < self::CHUNK || ($budgetSeconds > 0 && microtime(true) - $started > $budgetSeconds)) {
                    break;
                }
            }
            if ($budgetSeconds > 0 && microtime(true) - $started > $budgetSeconds) {
                break;
            }
        }
        return $new;
    }

    /** Po zmianie profilu: dopasowania do przejrzenia liczymy od nowa, zapisane i ukryte zostają. */
    public static function reset(int $profileId): void
    {
        Db::get()->run("DELETE FROM plan_matches WHERE profile_id = ? AND status = 'new'", [$profileId]);
        Kv::forget(self::cursorKey($profileId));
    }

    /** Czy profil ma jeszcze pozycje planów do sprawdzenia (np. po zapisaniu profilu, gdy przerwał limit czasu). */
    public static function pending(int $orgId): bool
    {
        $db = Db::get();
        $maxItem = (int) $db->val('SELECT COALESCE(MAX(id), 0) FROM plan_items');
        foreach ($db->all('SELECT id FROM tender_profiles WHERE org_id = ? AND active = 1', [$orgId]) as $p) {
            if ((int) (Kv::get(self::cursorKey((int) $p['id'])) ?? 0) < $maxItem) {
                return true;
            }
        }
        return false;
    }

    private static function cursorKey(int $profileId): string
    {
        return 'plans:match:' . $profileId;
    }

    /** @return string[] */
    private static function words(string $s): array
    {
        return array_values(array_filter(preg_split('/[\s,;]+/', trim($s)) ?: []));
    }

    /**
     * Słowa kluczowe jako wyrażenia z prostą odmianą (Plans::stem): plan pisze „odpadów komunalnych”, a profil „odpady komunalne”.
     * @return array<string, string> słowo w zapisie użytkownika => wyrażenie regularne
     */
    public static function patterns(string $s): array
    {
        static $cache = [];
        if (isset($cache[$s])) {
            return $cache[$s];
        }
        $out = [];
        foreach (preg_split('/[\r\n,;]+/u', $s) ?: [] as $raw) {
            $k = search_text($raw);
            if ($k === '') {
                continue;
            }
            $parts = [];
            foreach (explode(' ', $k) as $w) {
                [$stem, $short] = Plans::stem($w);
                $parts[] = preg_quote($stem, '/') . ($short ? '[a-z]{0,3}\b' : '[a-z0-9]*');
            }
            $out[mb_strtolower(utrim(preg_replace('/\s+/u', ' ', $raw) ?? $raw))] = '/ ' . implode(' ', $parts) . '/';
        }
        if (count($cache) > 200) {
            $cache = [];
        }
        return $cache[$s] = $out;
    }
}
