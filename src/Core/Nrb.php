<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Polski numer rachunku bankowego (NRB, 26 cyfr). Sprawdzamy sumę kontrolną tak jak IBAN (PL i modulo 97),
 * bo wykaz podatników VAT odrzuca błędny numer, a takie zapytanie zjada dzienny limit API.
 */
final class Nrb
{
    public static function clean(string $account): string
    {
        $a = preg_replace('/^\s*PL/i', '', $account) ?? '';
        return preg_replace('/\D/', '', $a) ?? '';
    }

    public static function valid(string $account): bool
    {
        $a = self::clean($account);
        if (!preg_match('/^\d{26}$/', $a)) {
            return false;
        }
        // IBAN: cyfry kontrolne na koniec, PL jako 2521, reszta z dzielenia przez 97 musi wynosić 1.
        $digits = substr($a, 2) . '2521' . substr($a, 0, 2);
        $r = 0;
        foreach (str_split($digits) as $d) {
            $r = ($r * 10 + (int) $d) % 97;
        }
        return $r === 1;
    }

    /** „12 3456 7890 …”: tak jak numer drukuje bank, łatwo porównać z fakturą. */
    public static function format(string $account): string
    {
        $a = self::clean($account);
        return strlen($a) === 26 ? substr($a, 0, 2) . ' ' . implode(' ', str_split(substr($a, 2), 4)) : $account;
    }
}
