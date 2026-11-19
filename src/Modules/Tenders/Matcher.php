<?php

declare(strict_types=1);

namespace App\Modules\Tenders;

use App\Core\Db;

/**
 * Ocena dopasowania przetargu do profilu firmy (0-100) z uzasadnieniem po polsku.
 * Twarde filtry (województwa, rodzaj zamówienia, słowa wykluczające, minione terminy) odrzucają przetarg.
 */
final class Matcher
{
    public const THRESHOLD = 40;

    /** @return array{score: int, reasons: string[]}|null null = przetarg odrzucony */
    public static function score(array $tender, array $profile, ?int $now = null): ?array
    {
        $now ??= time();
        $reasons = [];
        $score = 0;
        $text = search_text(($tender['title'] ?? '') . ' ' . implode(' ', self::detailTexts($tender)));

        if (!empty($tender['deadline_at']) && strtotime($tender['deadline_at'] . ' UTC') < $now) {
            return null;
        }
        if (!empty($profile['sources']) && !str_contains((string) $profile['sources'], (string) $tender['source'])) {
            return null;
        }
        $regions = self::words($profile['voivodeships'] ?? '');
        if ($regions) {
            if (!$tender['voivodeship'] || !in_array($tender['voivodeship'], $regions, true)) {
                return null;
            }
            $score += 10;
            $reasons[] = 'Województwo: ' . Regions::name($tender['voivodeship']);
        }
        $types = self::words($profile['order_types'] ?? '');
        if ($types && $tender['order_type'] && !in_array($tender['order_type'], $types, true)) {
            return null;
        }
        foreach (self::lines($profile['exclude_keywords'] ?? '') as $kw) {
            if ($kw !== '' && str_contains(' ' . $text . ' ', ' ' . $kw)) {
                return null;
            }
        }

        // CPV: dopasowanie prefiksu, im dłuższy wspólny prefiks, tym więcej punktów.
        $cpvAll = self::words($tender['cpv_all'] ?? '');
        $cpvMain = (string) ($tender['cpv_main'] ?? '');
        $bestCpv = 0;
        $bestCode = '';
        foreach (self::words($profile['cpv'] ?? '') as $prefix) {
            $prefix = rtrim(preg_replace('/\D/', '', $prefix) ?? '', '0');
            if (strlen($prefix) < 2) {
                continue;
            }
            foreach ($cpvAll as $code) {
                if (str_starts_with($code, $prefix)) {
                    $pts = ($code === $cpvMain ? 50 : 38) + min(10, (strlen($prefix) - 2) * 2);
                    if ($pts > $bestCpv) {
                        $bestCpv = $pts;
                        $bestCode = $code;
                    }
                }
            }
        }
        if ($bestCpv > 0) {
            $score += min(60, $bestCpv);
            $reasons[] = 'Kod CPV ' . $bestCode . ($bestCode === $cpvMain ? ' (główny)' : '');
        }

        // Słowa kluczowe w tytule i opisie. Porównujemy bez polskich znaków, a w uzasadnieniu pokazujemy słowo w zapisie użytkownika.
        $hits = [];
        foreach (self::keywordMap($profile['keywords'] ?? '') as $kw => $shown) {
            if (str_contains(' ' . $text . ' ', ' ' . $kw)) {
                $hits[] = $shown;
            }
        }
        if ($hits) {
            $score += min(40, 20 + 10 * (count($hits) - 1));
            $reasons[] = 'Słowa kluczowe: ' . implode(', ', array_slice($hits, 0, 4));
        }
        if ($bestCpv === 0 && !$hits) {
            return null;
        }

        // Wartość szacunkowa (gdy znana).
        $v = $tender['value_estimated'] !== null && $tender['value_estimated'] !== '' ? (float) $tender['value_estimated'] : null;
        $min = $profile['min_value'] !== null && $profile['min_value'] !== '' ? (float) $profile['min_value'] : null;
        $max = $profile['max_value'] !== null && $profile['max_value'] !== '' ? (float) $profile['max_value'] : null;
        if ($v !== null && ($min !== null || $max !== null)) {
            if (($min !== null && $v < $min) || ($max !== null && $v > $max)) {
                $score -= 25;
                $reasons[] = 'Wartość poza Twoim zakresem';
            } else {
                $score += 10;
                $reasons[] = 'Wartość w Twoim zakresie';
            }
        }

        // Za mało czasu na przygotowanie oferty.
        if (!empty($tender['deadline_at'])) {
            $days = (strtotime($tender['deadline_at'] . ' UTC') - $now) / 86400;
            if ($days < 3) {
                $score -= 15;
                $reasons[] = 'Mniej niż 3 dni na ofertę';
            }
        }
        return ['score' => max(0, min(100, $score)), 'reasons' => $reasons];
    }

    /** Dopasowuje nowe przetargi do wszystkich aktywnych profili. Zwraca liczbę nowych dopasowań. */
    public static function run(?int $orgId = null, ?int $profileId = null, ?string $sinceUtc = null): int
    {
        $db = Db::get();
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
        $created = 0;
        $profiles = $db->all($sql, $params);
        if (!$profiles) {
            return 0;
        }
        // Ogłoszenia czytamy raz dla wszystkich profili (bez dużego pola raw_json), a potem filtrujemy per profil.
        $default = gmdate('Y-m-d H:i:s', time() - 14 * 86400);
        $sinceFor = static fn (array $p) => $sinceUtc ?? ($p['matched_until'] ?: $default);
        $minSince = min(array_map($sinceFor, $profiles));
        $all = $db->all(
            'SELECT id, source, title, order_type, voivodeship, cpv_main, cpv_all, value_estimated, deadline_at, details_json, updated_at
               FROM tenders WHERE updated_at >= ? AND (deadline_at IS NULL OR deadline_at > ?) ORDER BY id',
            [$minSince, now_utc()]
        );
        foreach ($profiles as $profile) {
            $since = $sinceFor($profile);
            $startedAt = now_utc();
            $tenders = $since === $minSince ? $all : array_filter($all, static fn ($t) => $t['updated_at'] >= $since);
            // Dotychczasowe dopasowania profilu czytamy raz, a zmiany zapisujemy paczkami - bez dwóch zapytań na każde ogłoszenie.
            $existing = [];
            foreach ($db->all('SELECT tender_id, score, reasons_json FROM tender_matches WHERE profile_id = ?', [$profile['id']]) as $m) {
                $existing[(int) $m['tender_id']] = $m;
            }
            $batch = [];
            foreach ($tenders as $t) {
                $r = self::score($t, $profile);
                if (!$r || $r['score'] < self::THRESHOLD) {
                    continue;
                }
                $old = $existing[(int) $t['id']] ?? null;
                if ($old && (int) $old['score'] === (int) $r['score'] && json_in($old['reasons_json']) == $r['reasons']) {
                    continue;
                }
                $batch[(int) $t['id']] = [
                    'org_id' => $profile['org_id'],
                    'profile_id' => $profile['id'],
                    'tender_id' => $t['id'],
                    'score' => $r['score'],
                    'reasons_json' => json_out($r['reasons']),
                    'status' => 'new',
                    'created_at' => now_utc(),
                ];
                $created += $old ? 0 : 1;
            }
            foreach (array_chunk(array_values($batch), 200) as $chunk) {
                // Przy istniejącym dopasowaniu zmieniamy tylko ocenę i uzasadnienie - status (zapisany, odrzucony) zostaje.
                $db->upsertMany('tender_matches', $chunk, ['profile_id', 'tender_id'], [], ['org_id', 'status', 'created_at']);
            }
            $db->update('tender_profiles', ['matched_until' => $startedAt], 'id = ?', [$profile['id']]);
        }
        return $created;
    }

    private static function detailTexts(array $tender): array
    {
        $d = json_in($tender['details_json'] ?? null);
        return array_map('strval', $d['descriptions'] ?? []);
    }

    /** @return string[] */
    private static function words(?string $s): array
    {
        return array_values(array_filter(preg_split('/[\s,;]+/u', trim((string) $s)) ?: [], static fn ($w) => $w !== ''));
    }

    /** @return array<string, string> słowo znormalizowane do porównań => to samo słowo w zapisie użytkownika (małymi literami) */
    private static function keywordMap(?string $s): array
    {
        $map = [];
        foreach (preg_split('/[\r\n,;]+/u', (string) $s) ?: [] as $part) {
            $key = search_text($part);
            if ($key !== '' && !isset($map[$key])) {
                $map[$key] = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $part) ?? ''));
            }
        }
        return $map;
    }

    /** Słowa kluczowe: po jednym w linii albo po przecinku, znormalizowane. */
    public static function lines(?string $s): array
    {
        $parts = preg_split('/[\r\n,;]+/u', (string) $s) ?: [];
        return array_values(array_unique(array_filter(array_map('search_text', $parts), static fn ($w) => $w !== '')));
    }
}
