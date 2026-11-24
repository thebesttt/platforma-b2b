<?php

return [
    'Menu główne: sześć grup w kolejności pracy, Dotacje jako osobna pozycja, drugi wiersz tylko dla grupy z kilkoma pozycjami' => function () {
        [$orgId, $uid] = make_org();
        login_as($uid);
        $nav = static function (string $path): array {
            $body = http('GET', $path)->body;
            preg_match('#<nav class="nav".*?</nav>#s', $body, $main);
            preg_match('#<nav class="subnav".*?</nav>#s', $body, $sub);
            preg_match_all('#<a class="nav-top( on)?" href="([^"]+)"[^>]*>([^<]+)#', $main[0] ?? '', $tops, PREG_SET_ORDER);
            return ['main' => $main[0] ?? '', 'sub' => $sub[0] ?? '', 'tops' => array_map(static fn (array $t): string => trim($t[3]) . ($t[1] ? '*' : ''), $tops)];
        };

        // Nabory: aktywna grupa „Przetargi i dotacje”, w drugim wierszu aktywne „Dotacje”, a nie „Oferty”.
        $n = $nav('/oferty/nabory');
        eq(['Pulpit', 'Sprzedaż', 'Marketing', 'Przetargi i dotacje*', 'Kontrahenci', 'Konto'], $n['tops']);
        contains('<a href="/oferty/nabory" class="on" aria-current="page">Dotacje</a>', $n['sub']);
        contains('<a href="/oferty">Oferty</a>', $n['sub']);
        eq(4, substr_count($n['sub'], '<a href='), 'Przetargi, Oferty, Dotacje, Biblioteka firmy');
        // Lista ofert: aktywne „Oferty”.
        contains('<a href="/oferty" class="on" aria-current="page">Oferty</a>', $nav('/oferty')['sub']);
        // Grupa z jedną pozycją nie ma drugiego wiersza. Zwykła firma nie widzi Administracji.
        $k = $nav('/kontrahenci');
        eq(['Pulpit', 'Sprzedaż', 'Marketing', 'Przetargi i dotacje', 'Kontrahenci*', 'Konto'], $k['tops']);
        eq('', $k['sub']);
        ok(!str_contains($k['main'], '/administracja'));
        eq('', $nav('/ustawienia')['sub'], 'Konto ma dla zwykłej firmy jedną pozycję');
        with_config(['ADMIN_EMAILS' => 'wlasciciel@example.com'], function () use ($nav) {
            contains('<a href="/administracja" class="on" aria-current="page">Administracja</a>', $nav('/administracja')['sub']);
        });
        // Wyłączone moduły znikają z menu razem z pustymi grupami.
        App\Core\Db::get()->update('organizations', ['settings_json' => json_out(['modules' => ['zapytania', 'audyt']])], 'id = ?', [$orgId]);
        App\Core\Auth::forget();
        $few = $nav('/pulpit');
        eq(['Pulpit*', 'Sprzedaż', 'Marketing', 'Konto'], $few['tops']);
        contains('href="/biblioteka"', $few['main'], 'biblioteka przechodzi do grupy Konto, gdy nie ma przetargów ani ofert');
    },
];
