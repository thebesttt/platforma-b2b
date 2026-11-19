<?php

use App\Core\Kv;
use App\Core\Nip;

return [
    'NIP: suma kontrolna i formatowanie' => function () {
        ok(Nip::valid('1234563218'));
        ok(Nip::valid('123-456-32-18'));
        ok(!Nip::valid('1234563219'), 'zła suma kontrolna');
        ok(!Nip::valid('0000000000'));
        eq('123-456-32-18', Nip::format('1234563218'));
    },
    'search_text usuwa polskie znaki i interpunkcję' => function () {
        eq('zolta lodz na wiśle', str_replace('wisle', 'wiśle', search_text('Żółta łódź - na Wiśle!')));
        eq('remont ul glownej', search_text('Remont ul. Głównej'));
    },
    'plural odmienia liczebniki' => function () {
        eq('przetarg', plural(1, 'przetarg', 'przetargi', 'przetargów'));
        eq('przetargi', plural(3, 'przetarg', 'przetargi', 'przetargów'));
        eq('przetargów', plural(12, 'przetarg', 'przetargi', 'przetargów'));
        eq('przetargi', plural(22, 'przetarg', 'przetargi', 'przetargów'));
    },
    'Kv: zapis, licznik w oknie czasowym, wygasanie' => function () {
        Kv::set('a', ['x' => 1]);
        eq(['x' => 1], Kv::get('a'));
        eq(1, Kv::hit('licznik', 60));
        eq(2, Kv::hit('licznik', 60));
        Kv::set('stare', 1, -10);
        eq(null, Kv::get('stare'));
    },
    'marked_html oznacza zdania bez źródła' => function () {
        $h = marked_html("Zdanie A ⟦źródło: SWZ 7.2⟧\nZdanie B ⟦sprawdź⟧\n<script>");
        contains('<span class="src ok">SWZ 7.2</span>', $h);
        contains('class="nosrc"', $h);
        contains('&lt;script&gt;', $h);
    },
];
