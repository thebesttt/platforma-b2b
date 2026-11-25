<?php
/** Treść planu postępowań z BZP do testów (struktura jak w prawdziwym ogłoszeniu, rok bieżący). */
if (!function_exists('plan_fixture_html')) {
    function plan_fixture_html(?int $year = null, int $version = 2, array $drop = []): string
    {
        $y = (string) ($year ?? (int) gmdate('Y'));
        $spaced = static fn (string $s): string => implode('&nbsp;&nbsp;&nbsp;&nbsp;', mb_str_split($s));
        $info = 'Zamówienie jest odpowiednie dla MŚP: TAK<br />Zamówienie strategiczne (zielone, społeczne, innowacyjne): NIE<br />Zamówienie zastrzeżone: NIE<br />Charakter zamówienia: zamówienia klasyczne';
        $row = static function (string $pos, string $subject, string $mode, string $value, string $term, string $info, string $update = '', string $cls = ' ') use ($drop): string {
            if (in_array($pos, $drop, true)) {
                return '';
            }
            $cells = [$pos, $subject, $mode, $value, $term, $info];
            $out = "                <tr>\n";
            foreach ($cells as $c) {
                $out .= '                    <td colspan="1" rowspan="1" class="' . $cls . '">' . $c . "</td>\n";
            }
            return $out . '                    <td colspan="1" rowspan="1">' . $update . "</td>\n                </tr>\n";
        };
        $header = static fn (string $n, string $label): string => '                <tr><td colspan="7" class="row-header">' . $spaced($n . '.' . $label) . "</td></tr>\n";
        $thead = '<thead><tr class="header"><td>Pozycja Planu</td><td>Przedmiot zamówienia</td><td>Przewidywany tryb albo procedura udzielenia zamówienia<sup class="sup4">4</sup></td>'
            . '<td>Orientacyjna wartość zamówienia<sup class="sup4">5</sup></td><td>Przewidywany termin wszczęcia postępowania<sup class="sup4">6</sup></td>'
            . '<td>Informacje dodatkowe<sup class="sup4">7</sup></td><td>Informacja na temat aktualizacji<sup class="sup4">8</sup></td></tr>'
            . '<tr class="header"><td>1</td><td>2</td><td>3</td><td>4</td><td>5</td><td>6</td><td>7</td></tr></thead>';
        $basic = 'Zamówienie udzielane jest w trybie podstawowym na podstawie: art. 275 pkt 1 ustawy';
        $digits = implode('', array_map(static fn ($d) => "<td>$d</td>", str_split($y)));
        return '<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8"><style>.bold{font-weight:bold}</style></head><body class="body">'
            . "\n    <div class=\"attachment-title bold mt20\"><table class=\"table-attachment-Title\"><tr><td style=\"border-left:none; border-top: none;width:80%\">Plan postępowań o udzielenie zamówień na rok</td>$digits</tr></table></div>"
            . "\n    <div class=\"attachment-title bold mt20\"><table class=\"table-attachment-Title\"><tr><td style=\"border-left:none; border-bottom: none;width:80%\">Wersja nr</td><td></td><td></td><td></td><td>$version</td></tr></table></div>"
            . "\n    <div class=\"attachment-title bold mt20\">Zamieszczony w Biuletynie Zamówień Publicznych w dniu 20.01.$y nr $y/BZP 00037981/0$version/P</div>"
            . "\n    <div class=\"bold mt20\">Zamawiający:</div><table class=\"table_0 table_1 mt20\"><tbody><tr><td colspan=\"2\">Nazwa: Gmina Jarcz&#243;w </td>"
            . "<td>Krajowy numer identyfikacyjny:<sup>2</sup> 9211980566</td></tr><tr><td colspan=\"3\">Adres: 3 Maja 24</td></tr></tbody></table>"
            . "\n    <div class=\"new-page\"></div>\n    <div class=\"bold mt20\">\n        1. Zamówienia o wartości mniejszej niż progi unijne\n    </div>\n    <table class=\"table-positions mt20\">$thead<tbody>\n"
            . $header('1', 'ROBOTY  BUDOWLANE')
            . $row('1.1.1', 'Przebudowa drogi gminnej nr 111 933 L w miejscowości Wola Gródecka', $basic, '2 100 000,00 PLN', 'II Kwartał', $info)
            . $row('1.1.2', 'Termomodernizacja budynku Szkoły Podstawowej w Jarczowie', $basic, '850 000,00 PLN', 'IV kwartał', $info, 'zmiana pozycji', ' bold')
            . $header('2', 'DOSTAWY')
            . $row('1.2.1', 'Dostawa sprzętu komputerowego wraz z oprogramowaniem biurowym', $basic, '180 000,00 PLN', 'grudzień', $info . '<br />Wstępne konsultacje rynkowe w październiku')
            . $header('3', 'USŁUGI')
            . $row('1.3.1', 'Odbiór i zagospodarowanie odpadów komunalnych z nieruchomości zamieszkałych', $basic, '1 450 000,00 PLN', 'I i II kwartał', $info)
            . $row('1.3.2', 'Utrzymanie strony internetowej i Biuletynu Informacji Publicznej', $basic, '60 000,00 PLN', 'IV Kwartał', $info, 'rezygnacja z pozycji')
            . "</tbody></table>\n    <div class=\"new-page\"></div>\n    <div class=\"bold\">\n        2. Zamówienia o wartości równej lub przekraczającej progi unijne\n    </div>\n    <table class=\"table-positions mt20\">$thead<tbody>\n"
            . $header('1', 'ROBOTY  BUDOWLANE')
            . $row('2.1.1', 'Budowa hali sportowej przy Szkole Podstawowej', 'Zamówienie udzielane jest w trybie przetargu nieograniczonego na podstawie: art. 132 ustawy', '22 500 000,00 PLN', 'III / IV kwartał', 'Zamówienie jest odpowiednie dla MŚP: NIE')
            . "</tbody></table>\n</body></html>";
    }
}
