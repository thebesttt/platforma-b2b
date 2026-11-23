<?php
/**
 * Ogólna strona zgody firmy: jeden adres i kod QR na wizytówkę, ulotkę albo stoisko targowe.
 * Kto zeskanuje kod, sam wpisuje firmę i e-mail i zaznacza zgodę. Kod jest krótki jak kody z listów i działa pod tym samym adresem /z/kod.
 */

use App\Core\Schema;

return static function (Schema $s): void {
    $s->addColumn('organizations', 'consent_code', 'str');
    $s->index('organizations', ['consent_code']);
};
