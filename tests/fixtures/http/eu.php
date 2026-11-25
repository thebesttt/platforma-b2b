<?php
/**
 * Lista projektów z funduszy europejskich 2021-2027: strona z przyciskiem pobierania i plik CSV w formacie z funduszeeuropejskie.gov.pl
 * (UTF-8 z BOM, średnik, pola w cudzysłowach, opis z nową linią, pierwsza linia to tytuł). Daty są liczone od dziś,
 * żeby filtr „projekty w trakcie” nie zależał od dnia testu. Zmienna FIXTURE_EU=krotka daje plik bez projektu „Zielona Energia”.
 *
 * @var callable $json
 */
if (!function_exists('eu_fixture_csv')) {
    function eu_fixture_csv(bool $short = false): string
    {
        $d = static fn (int $days): string => gmdate('Y-m-d', time() + $days * 86400);
        $q = static fn (string $s): string => '"' . str_replace('"', '""', $s) . '"';
        $header = ['Nazwa projektu', 'Opis projektu', 'Numer umowy', 'Nazwa beneficjenta', 'Nazwa wykonawcy kontraktu', 'Numer statku', 'Fundusz', 'Cel szczegółowy',
            'Program', 'Priorytet', 'Działanie', 'Wartość projektu (zł)', 'Poziom dofinansowania %', 'Dofinansowanie UE (zł)', 'Kurs EURO', 'Miejsce realizacji',
            'Data rozpoczęcia', 'Data zakończenia', 'Kategoria wsparcia'];
        $row = static fn (string $title, string $desc, string $no, string $who, string $program, string $action, string $value, string $eu, string $place, string $start, string $end): array
            => [$title, $desc, $no, $who, '', '', 'EFRR', 'Cyfryzacja', $program, 'Priorytet 1', $action, $value, '70,00', $eu, '4,3', $place, $start, $end, 'Wsparcie MŚP'];
        $rows = [
            $row('Wdrożenie platformy B2B i automatyzacja sprzedaży', "Projekt obejmuje wdrożenie platformy B2B.\nW ramach projektu powstanie sklep internetowy dla odbiorców hurtowych.", 'FELD.01.01-IP.01-0001/24',
                'TECHNOLOGIE PRZYSZŁOŚCI SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', 'Fundusze Europejskie dla Łódzkiego 2021-2027', '1.3 Cyfryzacja MŚP', '1 250 000,00', '875 000,00',
                'WOJ.: ŁÓDZKIE, POW.: Łódź, GM.: Łódź', $d(-60), $d(400)),
            $row('Remont pomieszczeń firmy', 'Opis', 'FEMA.01.01-IP.01-0002/24', 'Jan Kowalski Usługi Remontowe', 'Fundusze Europejskie dla Mazowsza 2021-2027', '1.3 MŚP', '90 000,00', '60 000,00',
                'WOJ.: MAZOWIECKIE, POW.: Warszawa, GM.: Warszawa', $d(-30), $d(300)),
            $row('Nowa linia produkcyjna', 'Opis', 'FESL.01.01-IP.01-0003/24', 'ABC s.c. Anna Nowak, Piotr Nowak', 'Fundusze Europejskie dla Śląskiego 2021-2027', '1.3 MŚP', '300 000,00', '200 000,00',
                'WOJ.: ŚLĄSKIE, POW.: Katowice, GM.: Katowice', $d(-30), $d(300)),
            $row('Modernizacja pieca szklarskiego', 'Opis', 'FENG.01.01-IP.01-0004/22', 'Huta Szkła Przykładowa S.A.', 'Fundusze Europejskie dla Nowoczesnej Gospodarki', '1.1 B+R', '9 000 000,00', '4 000 000,00',
                'WOJ.: DOLNOŚLĄSKIE, POW.: Wrocław, GM.: Wrocław', $d(-1200), $d(-400)),
            $row('Budowa drogi gminnej', 'Opis', 'FELU.02.01-IP.01-0005/24', 'Gmina Testowo', 'Fundusze Europejskie dla Lubelskiego 2021-2027', '5.1 Drogi', '4 000 000,00', '3 400 000,00',
                'WOJ.: LUBELSKIE, POW.: lubelski, GM.: Testowo', $d(-30), $d(300)),
            $row('Instalacja fotowoltaiczna i magazyn energii', 'Farma fotowoltaiczna na dachach hal z magazynem energii.', 'FENX.02.01-IP.01-0006/25', 'Zielona Energia sp. z o.o.',
                'Fundusze Europejskie na Infrastrukturę, Klimat, Środowisko', '2.1 OZE', '6 400 000,00', '3 200 000,00',
                'WOJ.: MAZOWIECKIE, POW.: Radom, GM.: Radom | WOJ.: ŚLĄSKIE, POW.: Gliwice, GM.: Gliwice', $d(-30), $d(700)),
            $row('Centrum logistyczne z systemem zarządzania magazynem', 'System WMS i automatyczne sortowanie.', 'FENG.02.01-IP.01-0007/25', 'Logistyka Polska Spółka Akcyjna',
                'Fundusze Europejskie dla Nowoczesnej Gospodarki', '2.4 Logistyka', '12 000 000,00', '5 000 000,00', 'Cały Kraj', $d(-200), $d(500)),
            // Ten sam numer umowy drugi raz (plik bywa niespójny): zostaje jeden projekt.
            $row('Wdrożenie platformy B2B i automatyzacja sprzedaży', 'Opis powtórzony', 'FELD.01.01-IP.01-0001/24',
                'TECHNOLOGIE PRZYSZŁOŚCI SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', 'Fundusze Europejskie dla Łódzkiego 2021-2027', '1.3 Cyfryzacja MŚP', '1 250 000,00', '875 000,00',
                'WOJ.: ŁÓDZKIE, POW.: Łódź, GM.: Łódź', $d(-60), $d(400)),
        ];
        if ($short) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => $r[3] !== 'Zielona Energia sp. z o.o.'));
        }
        $out = "\xEF\xBB\xBF" . $q('Lista projektów realizowanych z Funduszy Europejskich w Polsce w latach 2021-2027 (stan na 01.11.2026)') . str_repeat(';', 18) . "\n";
        $out .= implode(';', array_map($q, $header)) . "\n";
        foreach ($rows as $r) {
            $out .= implode(';', array_map($q, $r)) . "\n";
        }
        return $out;
    }
}

$csvPath = '/wp-content/uploads/2026/11/Lista_projektow_FE_2021_2027_01112026.csv';
return [
    '#^GET https://funduszeeuropejskie\.gov\.pl/raporty-i-analizy/lista-projektow-realizowanych-z-funduszy-europejskich-w-polsce-w-latach-2021-2027/\n#' => ['status' => 200, 'body' =>
        '<html><body><h1>Lista projektów realizowanych z Funduszy Europejskich w Polsce w latach 2021-2027</h1>'
        . '<button class="btn" data-url="' . $csvPath . '">Pobierz plik CSV (145 MB)</button>'
        . '<button class="btn" data-url="/wp-content/uploads/2026/11/Lista_projektow_FE_2021_2027_01112026.xlsx">Pobierz plik XLSX</button></body></html>'],
    '#^GET https://funduszeeuropejskie\.gov\.pl' . preg_quote($csvPath, '#') . '\n#' => ['status' => 200, 'body' => eu_fixture_csv((string) App\Core\Config::get('FIXTURE_EU', '') === 'krotka')],
];
