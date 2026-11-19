<?php

use App\Modules\Audit\Audit;

return [
    'Audyt: język sklepu zamieniony na „stronę” z poprawną odmianą' => function () {
        eq('Strona zablokowała automatyczne sprawdzenie', Audit::localize('Sklep zablokował automatyczne sprawdzenie'));
        eq('Wymuszenie HTTPS na całej stronie.', Audit::localize('Wymuszenie HTTPS na całym sklepie.'));
        eq('przed osadzaniem strony na obcych stronach', Audit::localize('przed osadzaniem sklepu na obcych stronach'));
        eq('np. mojsklep.pl', Audit::localize('np. mojsklep.pl'), 'nazwy domen bez zmian');
        eq('Brak HTTPS - dane są widoczne', Audit::localize("Brak HTTPS \u{2013} dane są widoczne"), 'zwykły łącznik zamiast półpauzy');
    },
    'Audyt: adresy wewnętrzne są blokowane poza środowiskiem testowym' => function () {
        $res = Audit::run(1, 1, 'http://127.0.0.1/');
        eq(false, $res['ok']);
        contains('Podaj domenę', $res['error']);
    },
];
