<?php
/** Strona internetowa kontaktu – do personalizacji wiadomości na podstawie treści strony firmy. */

use App\Core\Schema;

return static function (Schema $s): void {
    $s->addColumn('contacts', 'website', 'text');
    $s->addColumn('contacts', 'company_id', 'bigint');
};
