<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Polskie dni ustawowo wolne od pracy (ustawa z 18 stycznia 1951 r. o dniach wolnych od pracy).
 * Wigilia jest dniem wolnym od 2025 r. Ruchome święta liczymy od Wielkanocy.
 *
 * Do czego: porównania dzień do dnia (alarm pomiaru, monitory, spadki) pomijają święta, bo ruch w Boże Narodzenie
 * czy w Wielkanoc spada sam z siebie i nie jest powodem do alarmu.
 */
final class Holidays
{
    /** @var array<int, array<string, string>> */
    private static array $cache = [];

    /** Wielkanoc według algorytmu Meeusa/Jonesa/Butchera (kalendarz gregoriański). */
    public static function easter(int $year): string
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /** @return array<string, string> data RRRR-MM-DD => nazwa święta, w kolejności dat */
    public static function forYear(int $year): array
    {
        if (isset(self::$cache[$year])) {
            return self::$cache[$year];
        }
        $easter = strtotime(self::easter($year) . ' UTC');
        $plus = static fn (int $days): string => gmdate('Y-m-d', $easter + $days * 86400);
        $fixed = static fn (string $md): string => $year . '-' . $md;
        $out = [
            $fixed('01-01') => 'Nowy Rok',
            $fixed('01-06') => 'Święto Trzech Króli',
            $plus(0) => 'Wielkanoc',
            $plus(1) => 'Poniedziałek Wielkanocny',
            $fixed('05-01') => 'Święto Pracy',
            $fixed('05-03') => 'Święto Konstytucji 3 Maja',
            $plus(49) => 'Zielone Świątki',
            $plus(60) => 'Boże Ciało',
            $fixed('08-15') => 'Wniebowzięcie NMP',
            $fixed('11-01') => 'Wszystkich Świętych',
            $fixed('11-11') => 'Święto Niepodległości',
            $fixed('12-25') => 'Boże Narodzenie',
            $fixed('12-26') => 'drugi dzień Bożego Narodzenia',
        ];
        if ($year >= 2025) {
            $out[$fixed('12-24')] = 'Wigilia';
        }
        ksort($out);
        return self::$cache[$year] = $out;
    }

    public static function name(string $day): ?string
    {
        if (!preg_match('/^(\d{4})-\d{2}-\d{2}$/', $day, $m)) {
            return null;
        }
        return self::forYear((int) $m[1])[$day] ?? null;
    }

    public static function is(string $day): bool
    {
        return self::name($day) !== null;
    }

    /** @return array<string, string> święta w przedziale dat (włącznie) */
    public static function between(string $from, string $to): array
    {
        $out = [];
        for ($y = (int) substr($from, 0, 4); $y <= (int) substr($to, 0, 4); $y++) {
            foreach (self::forYear($y) as $day => $name) {
                if ($day >= $from && $day <= $to) {
                    $out[$day] = $name;
                }
            }
        }
        return $out;
    }
}
