<?php

use App\Core\Qr;

$bits = static fn (array $m): string => implode('', array_map(static fn (array $row): string => implode('', array_map(static fn (bool $d): string => $d ? '1' : '0', $row)), $m));

return [
    'Kod QR: rozmiar rośnie z długością tekstu, a kody sprawdzone czytnikiem się nie zmieniają' => function () use ($bits) {
        // Trzy kody odczytane niezależnym czytnikiem (OpenCV). Odcisk pilnuje, żeby późniejsza zmiana nie zepsuła kodowania.
        foreach ([
            ['A', 21, '444d3cd9b5a3fc15d7b2816edba96777efb85194'],
            ['https://platforma-b2b.onrender.com/z/tb6y4s29mb', 33, '886f8291b3b1993915e7928480b3d2ebefce4d45'],
            [str_repeat('perfectdata.pl/', 14) . 'abc', 57, 'a61aee54d3dc1e90ac56ce79c25540620c4a7264'],
        ] as [$text, $size, $hash]) {
            $m = Qr::matrix($text);
            eq($size, count($m), 'rozmiar dla ' . strlen($text) . ' bajtów');
            eq($size, count($m[0]));
            eq($hash, sha1($bits($m)), 'odcisk kodu dla ' . strlen($text) . ' bajtów');
        }
        // Granice pojemności kolejnych wersji na poziomie korekcji M.
        foreach ([14 => 21, 15 => 25, 26 => 25, 27 => 29, 62 => 33, 63 => 37, 122 => 45, 123 => 49, 180 => 53, 181 => 57, 213 => 57] as $len => $size) {
            eq($size, count(Qr::matrix(str_repeat('x', $len))), 'długość ' . $len);
        }
        eq(213, Qr::MAX_BYTES);
        eq(null, Qr::matrix(str_repeat('x', 214)), 'za długi tekst nie ma kodu');
        eq(null, Qr::matrix(''));
        eq(null, Qr::png(''));

        // Znaczniki w trzech narożnikach i jasny odstęp wokół nich. Polskie znaki liczą się w bajtach.
        $m = Qr::matrix('Zażółć gęślą jaźń');
        $n = count($m);
        foreach ([[0, 0], [0, $n - 7], [$n - 7, 0]] as [$y, $x]) {
            eq('1111111', $bits([array_slice($m[$y], $x, 7)]), 'górna krawędź znacznika');
            eq('1000001', $bits([array_slice($m[$y + 1], $x, 7)]));
            eq('1011101', $bits([array_slice($m[$y + 3], $x, 7)]), 'środek znacznika');
        }
        eq('0', $bits([[$m[7][0]]]), 'odstęp pod znacznikiem');
        ok($m[$n - 8][8], 'ciemny moduł stały');
        eq(Qr::matrix('Zażółć gęślą jaźń'), $m, 'ten sam tekst daje ten sam kod');

        // PNG: poprawny nagłówek, wymiary z marginesem czterech modułów, jeden bit na piksel.
        $png = Qr::png('https://platforma-b2b.onrender.com/z/tb6y4s29mb', 10);
        eq("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
        $ihdr = unpack('Nw/Nh/Cdepth/Ccolor', substr($png, 16, 10));
        eq(['w' => 410, 'h' => 410, 'depth' => 1, 'color' => 0], $ihdr);
        eq('IEND', substr($png, -8, 4));
        $raw = gzuncompress(substr($png, 41, unpack('N', substr($png, 33, 4))[1]));
        eq(410 * (1 + 52), strlen($raw), 'każdy wiersz ma bajt filtra i 52 bajty pikseli');
        eq("\x00" . str_repeat("\xFF", 52), substr($raw, 0, 53), 'pierwszy wiersz to biały margines');
        ok(strlen($png) < 3000, 'obraz jest mały');
    },
];
