<?php

declare(strict_types=1);

namespace App\Core;

final class Nip
{
    public static function clean(string $nip): string
    {
        $nip = preg_replace('/^PL/i', '', trim($nip)) ?? '';
        return preg_replace('/\D/', '', $nip) ?? '';
    }

    public static function valid(string $nip): bool
    {
        $nip = self::clean($nip);
        if (!preg_match('/^\d{10}$/', $nip) || $nip === '0000000000') {
            return false;
        }
        $w = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $nip[$i] * $w[$i];
        }
        return $sum % 11 === (int) $nip[9];
    }

    public static function format(string $nip): string
    {
        $n = self::clean($nip);
        return strlen($n) === 10 ? substr($n, 0, 3) . '-' . substr($n, 3, 3) . '-' . substr($n, 6, 2) . '-' . substr($n, 8, 2) : $nip;
    }
}
