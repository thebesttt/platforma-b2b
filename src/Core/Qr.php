<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Kod QR bez zewnętrznych bibliotek: tryb bajtowy, poziom korekcji M, wersje 1-10 (do 213 bajtów, czyli każdy adres strony).
 * Służy do druku adresu w liście papierowym: adresat skanuje kod telefonem i nie przepisuje adresu ręcznie.
 */
final class Qr
{
    /** Poziom M: [kody korekcji na blok, [liczba bloków, kody danych w bloku], ...] dla kolejnych wersji. */
    private const BLOCKS = [
        1 => [10, [1, 16]], 2 => [16, [1, 28]], 3 => [26, [1, 44]], 4 => [18, [2, 32]], 5 => [24, [2, 43]],
        6 => [16, [4, 27]], 7 => [18, [4, 31]], 8 => [22, [2, 38], [2, 39]], 9 => [22, [3, 36], [2, 37]], 10 => [26, [4, 43], [1, 44]],
    ];

    /** Środki wzorów wyrównujących dla kolejnych wersji. */
    private const ALIGN = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50]];

    /** Ile bajtów tekstu mieści największy obsługiwany kod. */
    public const MAX_BYTES = 213;

    /**
     * Macierz modułów [wiersz][kolumna], prawda oznacza ciemny moduł. Bez marginesu.
     * @return ?array<int, array<int, bool>> null, gdy tekst jest pusty albo za długi
     */
    public static function matrix(string $text): ?array
    {
        $len = strlen($text);
        $version = 0;
        $dataCw = 0;
        foreach (self::BLOCKS as $v => $spec) {
            $dataCw = 0;
            foreach (array_slice($spec, 1) as [$n, $k]) {
                $dataCw += $n * $k;
            }
            if ($len > 0 && $len <= intdiv($dataCw * 8 - 4 - ($v < 10 ? 8 : 16), 8)) {
                $version = $v;
                break;
            }
        }
        if ($version === 0) {
            return null;
        }

        // Dane: tryb bajtowy, długość, bajty, zakończenie i wypełnienie do pojemności wersji.
        $bits = '0100' . str_pad(decbin($len), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
        for ($i = 0; $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
        }
        $bits .= str_repeat('0', min(4, $dataCw * 8 - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
        $data = array_map('bindec', str_split($bits, 8));
        for ($pad = 0xEC; count($data) < $dataCw; $pad ^= 0xEC ^ 0x11) {
            $data[] = $pad;
        }

        // Podział na bloki, kody korekcji Reeda-Solomona i przeplot.
        $spec = self::BLOCKS[$version];
        $ecLen = $spec[0];
        $generator = self::rsGenerator($ecLen);
        $blocks = [];
        $ecs = [];
        $offset = 0;
        foreach (array_slice($spec, 1) as [$n, $k]) {
            for ($b = 0; $b < $n; $b++) {
                $block = array_slice($data, $offset, $k);
                $offset += $k;
                $blocks[] = $block;
                $ecs[] = self::rsRemainder($block, $generator);
            }
        }
        $stream = [];
        $longest = max(array_map('count', $blocks));
        for ($i = 0; $i < $longest; $i++) {
            foreach ($blocks as $block) {
                if ($i < count($block)) {
                    $stream[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $ecLen; $i++) {
            foreach ($ecs as $ec) {
                $stream[] = $ec[$i];
            }
        }

        // Wzory stałe: synchronizacja, trzy znaczniki narożne, wzory wyrównujące, miejsce na format i wersję.
        $size = $version * 4 + 17;
        $m = array_fill(0, $size, array_fill(0, $size, false));
        $fn = $m;
        $set = static function (int $x, int $y, bool $dark) use (&$m, &$fn, $size): void {
            if ($x >= 0 && $x < $size && $y >= 0 && $y < $size) {
                $m[$y][$x] = $dark;
                $fn[$y][$x] = true;
            }
        };
        for ($i = 0; $i < $size; $i++) {
            $set(6, $i, $i % 2 === 0);
            $set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $dist = max(abs($dx), abs($dy));
                    $set($cx + $dx, $cy + $dy, $dist !== 2 && $dist !== 4);
                }
            }
        }
        $pos = self::ALIGN[$version];
        $last = count($pos) - 1;
        foreach ($pos as $i => $cy) {
            foreach ($pos as $j => $cx) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                    continue; // tu stoją znaczniki narożne
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $set($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $format = static function (int $mask) use ($set, $size): void {
            $data = $mask; // poziom M ma bity 00
            $rem = $data;
            for ($i = 0; $i < 10; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
            }
            $bits = (($data << 10) | $rem) ^ 0x5412;
            $bit = static fn (int $i): bool => (($bits >> $i) & 1) === 1;
            for ($i = 0; $i <= 5; $i++) {
                $set(8, $i, $bit($i));
            }
            $set(8, 7, $bit(6));
            $set(8, 8, $bit(7));
            $set(7, 8, $bit(8));
            for ($i = 9; $i < 15; $i++) {
                $set(14 - $i, 8, $bit($i));
            }
            for ($i = 0; $i < 8; $i++) {
                $set($size - 1 - $i, 8, $bit($i));
            }
            for ($i = 8; $i < 15; $i++) {
                $set(8, $size - 15 + $i, $bit($i));
            }
            $set(8, $size - 8, true);
        };
        $format(0);
        if ($version >= 7) {
            $rem = $version;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            }
            $bits = ($version << 12) | $rem;
            for ($i = 0; $i < 18; $i++) {
                $dark = (($bits >> $i) & 1) === 1;
                $a = $size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $set($a, $b, $dark);
                $set($b, $a, $dark);
            }
        }

        // Dane wężykiem od prawego dolnego rogu, po dwie kolumny, z pominięciem kolumny synchronizacji.
        $total = count($stream) * 8;
        $i = 0;
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $y = (($right + 1) & 2) === 0 ? $size - 1 - $vert : $vert;
                    if (!$fn[$y][$x] && $i < $total) {
                        $m[$y][$x] = (($stream[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }

        // Maska: wybieramy tę z najmniejszą karą, żeby kod dobrze się skanował.
        $base = $m;
        $best = null;
        $bestMask = 0;
        $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $m = $base;
            for ($y = 0; $y < $size; $y++) {
                for ($x = 0; $x < $size; $x++) {
                    if (!$fn[$y][$x] && self::masked($mask, $x, $y)) {
                        $m[$y][$x] = !$m[$y][$x];
                    }
                }
            }
            $format($mask);
            $score = self::penalty($m, $size);
            if ($score < $bestScore) {
                [$best, $bestMask, $bestScore] = [$m, $mask, $score];
            }
        }
        return $best;
    }

    /** Obraz PNG kodu (czarno-biały, 1 bit na piksel) z białym marginesem. Null, gdy tekst się nie mieści. */
    public static function png(string $text, int $scale = 8, int $quiet = 4): ?string
    {
        $m = self::matrix($text);
        if ($m === null) {
            return null;
        }
        $scale = max(1, min(20, $scale));
        $size = count($m);
        $width = ($size + 2 * $quiet) * $scale;
        $pack = static function (string $bits): string {
            $bits .= str_repeat('1', (8 - strlen($bits) % 8) % 8);
            return implode('', array_map(static fn (string $b): string => chr((int) bindec($b)), str_split($bits, 8)));
        };
        $blank = "\x00" . $pack(str_repeat('1', $width));
        $raw = str_repeat($blank, $quiet * $scale);
        foreach ($m as $row) {
            $bits = str_repeat('1', $quiet * $scale);
            foreach ($row as $dark) {
                $bits .= str_repeat($dark ? '0' : '1', $scale);
            }
            $raw .= str_repeat("\x00" . $pack($bits . str_repeat('1', $quiet * $scale)), $scale);
        }
        $raw .= str_repeat($blank, $quiet * $scale);
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $width, $width, 1, 0, 0, 0, 0))
            . $chunk('IDAT', (string) gzcompress($raw, 9))
            . $chunk('IEND', '');
    }

    private static function masked(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
            5 => $x * $y % 2 + $x * $y % 3 === 0,
            6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
            default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
        };
    }

    /** Kara za układ trudny dla czytnika: długie pasy, bloki 2x2, wzory podobne do znacznika, nierówny udział ciemnych modułów. */
    private static function penalty(array $m, int $size): int
    {
        $score = 0;
        $dark = 0;
        $lines = [];
        for ($y = 0; $y < $size; $y++) {
            $row = '';
            $col = '';
            for ($x = 0; $x < $size; $x++) {
                $row .= $m[$y][$x] ? '1' : '0';
                $col .= $m[$x][$y] ? '1' : '0';
                if ($m[$y][$x]) {
                    $dark++;
                }
                if ($x + 1 < $size && $y + 1 < $size && $m[$y][$x] === $m[$y][$x + 1] && $m[$y][$x] === $m[$y + 1][$x] && $m[$y][$x] === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
            $lines[] = $row;
            $lines[] = $col;
        }
        foreach ($lines as $line) {
            preg_match_all('/0{5,}|1{5,}/', $line, $runs);
            foreach ($runs[0] as $run) {
                $score += strlen($run) - 2;
            }
            $score += 40 * (preg_match_all('/(?=10111010000)/', $line) + preg_match_all('/(?=00001011101)/', $line));
        }
        $all = $size * $size;
        return $score + 10 * intdiv(abs($dark * 20 - $all * 10), $all);
    }

    /** Wielomian generujący kodu Reeda-Solomona (bez wiodącej jedynki). @return int[] */
    private static function rsGenerator(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::mul($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::mul($root, 2);
        }
        return $result;
    }

    /** Reszta z dzielenia danych przez wielomian generujący: kody korekcji bloku. @return int[] */
    private static function rsRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coef) {
                $result[$i] ^= self::mul($coef, $factor);
            }
        }
        return $result;
    }

    /** Mnożenie w ciele GF(256) z wielomianem 0x11D. */
    private static function mul(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z;
    }
}
