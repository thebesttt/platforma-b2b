<?php
/**
 * Strona zgody pyta, co firmę interesuje: tematy do zaznaczenia (ustawiane w panelu) i krótka wiadomość.
 * Odpowiedź zapisujemy przy dowodzie zgody, żeby oferta od razu trafiała w temat.
 */

use App\Core\Schema;

return static function (Schema $s): void {
    $s->addColumn('consents', 'topics', 'text'); // zaznaczone tematy, po jednym w wierszu
    $s->addColumn('consents', 'note', 'text');   // wiadomość wpisana przez firmę
};
