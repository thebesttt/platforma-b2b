<?php

declare(strict_types=1);

namespace App\Modules\Seo;

use App\Core\Db;
use App\Core\Holidays;
use App\Modules\Notify\Notices;

/**
 * Alarm „pomiar przestał działać” na danych dziennych z Google Analytics 4.
 *
 * Częsty problem agencji: po zmianie na stronie formularz albo tag przestaje liczyć zdarzenia kluczowe i nikt nie zauważa
 * przez miesiąc. Panel porównuje każdy dzień z tym samym dniem tygodnia w 8 poprzednich tygodniach tej samej witryny,
 * więc progi wynikają z jej własnej historii. Święta pomijamy, bo ruch spada w nie sam z siebie.
 *
 * Dwa sygnały, oba muszą trwać dwa kolejne dni robocze lub weekendowe (bez świąt):
 *  - zdarzenia: ruch normalny (co najmniej połowa zwykłego), a zdarzeń kluczowych 0, choć zwykle są w tym dniu tygodnia,
 *  - ruch: sesji 10% zwykłego albo mniej przy zwykłym ruchu od 20 sesji dziennie (tag mógł zniknąć ze strony).
 * Dni sprawdzamy z dwudniowym opóźnieniem, bo dane GA4 z wczoraj bywają jeszcze niepełne.
 */
final class Tracking
{
    public const HISTORY_WEEKS = 8;
    public const LAG_DAYS = 2;
    public const DAYS = 2;
    public const NORMAL_TRAFFIC = 0.5;
    public const EVENT_DAYS_SHARE = 0.75;
    public const DEAD_TRAFFIC = 0.1;
    public const MIN_SESSIONS = 20;
    public const MIN_HISTORY_DAYS = 21;

    public static function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Warsaw')))->format('Y-m-d');
    }

    /**
     * @param array<int, array{day: string, clicks: int, value: ?float}> $daily sesje w „clicks”, zdarzenia kluczowe w „value”
     * @return array{status: string, days: string[], detail: string, sessions?: int, usual?: int, events_share?: float}
     *   status: ok | events | sessions | nodata
     */
    public static function evaluate(array $daily, ?string $today = null): array
    {
        $today ??= self::today();
        $map = [];
        foreach ($daily as $r) {
            $map[(string) $r['day']] = [(int) $r['clicks'], (float) ($r['value'] ?? 0)];
        }
        // Dwa ostatnie dni do sprawdzenia: od dnia sprzed opóźnienia w tył, z pominięciem świąt.
        $days = [];
        $ts = strtotime($today . ' UTC') - self::LAG_DAYS * 86400;
        for ($i = 0; $i < 10 && count($days) < self::DAYS; $i++, $ts -= 86400) {
            $day = gmdate('Y-m-d', $ts);
            if (!Holidays::is($day)) {
                $days[] = $day;
            }
        }
        $history = count(array_filter(array_keys($map), static fn (string $d): bool => $d < end($days)));
        if ($history < self::MIN_HISTORY_DAYS || count(array_filter($days, static fn (string $d): bool => isset($map[$d]))) < self::DAYS) {
            return ['status' => 'nodata', 'days' => $days, 'detail' => 'Za mało danych dziennych z Google Analytics, żeby ocenić pomiar (potrzeba 3 tygodni).'];
        }
        $verdicts = [];
        foreach ($days as $day) {
            $base = self::baseline($map, $day, $days);
            [$s, $e] = $map[$day];
            $verdicts[$day] = [
                'sessions' => $s, 'events' => $e, 'usual' => $base['sessions'], 'share' => $base['share'],
                'dead' => $base['sessions'] >= self::MIN_SESSIONS && $s <= self::DEAD_TRAFFIC * $base['sessions'],
                'silent' => $e <= 0 && $base['share'] >= self::EVENT_DAYS_SHARE && $s >= self::NORMAL_TRAFFIC * $base['sessions'] && $base['sessions'] > 0,
            ];
        }
        $label = static fn (array $ds): string => implode(' i ', array_map(static fn (string $d): string => date('d.m', strtotime($d)), array_reverse($ds)));
        $last = $verdicts[$days[0]];
        if (count(array_filter($verdicts, static fn (array $v): bool => $v['dead'])) === self::DAYS) {
            return [
                'status' => 'sessions', 'days' => $days, 'sessions' => $last['sessions'], 'usual' => (int) round($last['usual']),
                'detail' => 'Google Analytics prawie nie liczy ruchu: ' . $label($days) . ' po ' . implode(' i ', array_map(static fn (array $v): string => num_pl($v['sessions']), array_reverse($verdicts)))
                    . ' sesji, a zwykle w te dni tygodnia około ' . num_pl((int) round($last['usual'])) . '. Sprawdź, czy tag Google (gtag albo Menedżer tagów) jest nadal na stronie i czy baner zgody nie blokuje go wszystkim.',
            ];
        }
        if (count(array_filter($verdicts, static fn (array $v): bool => $v['silent'])) === self::DAYS) {
            return [
                'status' => 'events', 'days' => $days, 'sessions' => $last['sessions'], 'usual' => (int) round($last['usual']), 'events_share' => $last['share'],
                'detail' => 'Ruch jest normalny (' . $label($days) . ': ' . implode(' i ', array_map(static fn (array $v): string => num_pl($v['sessions']), array_reverse($verdicts)))
                    . ' sesji), ale zdarzeń kluczowych jest 0. W ostatnich ' . self::HISTORY_WEEKS . ' tygodniach były w ' . pct_pl($last['share'], 0)
                    . ' takich dni. Najczęstsze przyczyny: zmieniony formularz albo strona podziękowania, usunięte zdarzenie w Menedżerze tagów, zmiana nazwy zdarzenia oznaczonego jako kluczowe.',
            ];
        }
        return ['status' => 'ok', 'days' => $days, 'sessions' => $last['sessions'], 'usual' => (int) round($last['usual']), 'detail' => 'Pomiar wygląda normalnie: ruch i zdarzenia kluczowe w zwykłym zakresie dla tej witryny.'];
    }

    /**
     * Zwykły ruch i udział dni ze zdarzeniami: ten sam dzień tygodnia w 8 poprzednich tygodniach, bez świąt i bez dni
     * właśnie sprawdzanych. Gdy takich dni jest mniej niż 5 (nowa witryna), bierzemy wszystkie dni z 8 tygodni.
     * @param array<string, array{0: int, 1: float}> $map
     * @return array{sessions: float, share: float}
     */
    private static function baseline(array $map, string $day, array $exclude): array
    {
        $pick = static function (bool $sameWeekday) use ($map, $day, $exclude): array {
            $out = [];
            $ts = strtotime($day . ' UTC');
            for ($i = 1; $i <= self::HISTORY_WEEKS * 7; $i++) {
                $d = gmdate('Y-m-d', $ts - $i * 86400);
                if (($sameWeekday && $i % 7 !== 0) || in_array($d, $exclude, true) || Holidays::is($d) || !isset($map[$d])) {
                    continue;
                }
                $out[] = $map[$d];
            }
            return $out;
        };
        $rows = $pick(true);
        if (count($rows) < 5) {
            $rows = $pick(false);
        }
        if (!$rows) {
            return ['sessions' => 0.0, 'share' => 0.0];
        }
        $sessions = array_column($rows, 0);
        sort($sessions);
        $n = count($sessions);
        $median = $n % 2 ? (float) $sessions[intdiv($n, 2)] : ($sessions[$n / 2 - 1] + $sessions[$n / 2]) / 2;
        return ['sessions' => $median, 'share' => count(array_filter($rows, static fn (array $r): bool => $r[1] > 0)) / $n];
    }

    /**
     * Sprawdzenie po pobraniu danych: zapisuje stan przy witrynie i powiadamia tylko przy zmianie (początek alarmu i powrót do normy).
     * @return array{status: string, days: string[], detail: string}
     */
    public static function check(array $site, ?string $today = null): array
    {
        $res = self::evaluate(Stats::daily((int) $site['id'], 'ga4', 'date', 80), $today);
        $was = json_in($site['health_json'] ?? null);
        $prev = (string) ($was['status'] ?? '');
        $alarm = in_array($res['status'], ['events', 'sessions'], true);
        $state = ['status' => $res['status'], 'detail' => $res['detail'], 'days' => $res['days'], 'checked_at' => now_utc(),
            'since' => $alarm && $prev === $res['status'] ? ($was['since'] ?? end($res['days'])) : ($alarm ? end($res['days']) : null)];
        $orgId = (int) $site['org_id'];
        $url = '/audyt/widocznosc/' . (int) $site['id'] . '/dane#pomiar';
        if ($alarm && $prev !== $res['status']) {
            Notices::add($orgId, 'tracking', 'high', ($res['status'] === 'events' ? 'Zdarzenia kluczowe przestały się liczyć: ' : 'Google Analytics przestał liczyć ruch: ') . $site['host'], $res['detail'], $url, (int) $site['id']);
        } elseif ($res['status'] === 'ok' && in_array($prev, ['events', 'sessions'], true)) {
            Notices::add($orgId, 'tracking', 'info', 'Pomiar znów działa: ' . $site['host'], 'Ruch i zdarzenia kluczowe wróciły do zwykłego zakresu. Alarm trwał od ' . local_date((string) ($was['since'] ?? '')) . '.', $url, (int) $site['id']);
        }
        if ($res['status'] !== 'nodata' || $prev === '') {
            Db::get()->update('seo_sites', ['health_json' => json_out($state)], 'id = ?', [(int) $site['id']]);
        }
        return $res;
    }

    /** @return ?array zapisany stan alarmu witryny do pokazania na ekranie */
    public static function state(array $site): ?array
    {
        $s = json_in($site['health_json'] ?? null);
        return $s ?: null;
    }
}
