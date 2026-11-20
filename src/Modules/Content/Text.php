<?php

declare(strict_types=1);

namespace App\Modules\Content;

/** Proste operacje na tekście: słowa, słowa znaczące, porównanie odmienionych form. Bez słowników i bez AI. */
final class Text
{
    private const STOP = ['a', 'aby', 'albo', 'ale', 'bez', 'by', 'być', 'co', 'czy', 'czym', 'dla', 'do', 'gdy', 'gdzie', 'i', 'ich', 'ile', 'jak', 'jaka',
        'jaki', 'jakie', 'jakim', 'jako', 'jest', 'kiedy', 'która', 'które', 'który', 'lub', 'ma', 'na', 'nad', 'nie', 'o', 'od', 'oraz', 'po', 'pod', 'przed',
        'przez', 'przy', 'są', 'się', 'ta', 'tak', 'te', 'ten', 'to', 'tu', 'tym', 'u', 'w', 'we', 'z', 'za', 'ze', 'że', 'the', 'and', 'of', 'for', 'in', 'to', 'how', 'what'];

    public static function normalize(string $s): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}\x{200B}]+/u', ' ', $s));
    }

    /** @return string[] słowa małymi literami (litery i cyfry, z łącznikiem w środku) */
    public static function words(string $s): array
    {
        preg_match_all('/[\p{L}\p{N}]+(?:[\-\'’][\p{L}\p{N}]+)*/u', mb_strtolower($s), $m);
        return $m[0];
    }

    public static function count(string $s): int
    {
        return count(self::words($s));
    }

    /** @return string[] słowa bez spójników, przyimków i zaimków pytających */
    public static function content(string $s): array
    {
        return array_values(array_filter(self::words($s), static fn (string $w): bool => mb_strlen($w) > 1 && !in_array($w, self::STOP, true)));
    }

    /** Czy to to samo słowo w innej odmianie: wspólny początek obejmuje prawie całe krótsze słowo. */
    public static function same(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        $la = mb_strlen($a);
        $lb = mb_strlen($b);
        $min = min($la, $lb);
        if ($min < 3 || abs($la - $lb) > 4) {
            return false;
        }
        $common = 0;
        while ($common < $min && mb_substr($a, $common, 1) === mb_substr($b, $common, 1)) {
            $common++;
        }
        return $common >= max(3, $min - 2) && $common >= (int) ceil($min * 0.6);
    }

    /** Ile słów z listy $needles występuje (w dowolnej odmianie) w tekście. */
    public static function hits(array $needles, string $haystack): int
    {
        $words = array_unique(self::words($haystack));
        $n = 0;
        foreach ($needles as $needle) {
            foreach ($words as $w) {
                if (self::same($needle, $w)) {
                    $n++;
                    break;
                }
            }
        }
        return $n;
    }

    /** @return string[] zdania */
    public static function sentences(string $s): array
    {
        $parts = preg_split('/(?<=[.!?…])\s+(?=[\p{Lu}\p{N}„"(])|\n+/u', $s) ?: [];
        return array_values(array_filter(array_map([self::class, 'normalize'], $parts), static fn (string $x): bool => $x !== ''));
    }

    /** Liczba z polskim przecinkiem, bez zbędnych zer: 2.50 → „2,5”. */
    public static function num(float $v, int $decimals = 1): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format($v, $decimals, '.', ''), '0'), '.'));
    }
}
