<?php
/**
 * Zgody na ofertę e-mailem zbierane drogą, która sama zgody nie wymaga. Firma dostaje list pocztą z adresem strony zgody,
 * sama wpisuje tam adres e-mail i zaznacza zgodę. Każda zgoda ma zapisany dowód: dokładną treść, czas i źródło.
 */

use App\Core\Schema;

return static function (Schema $s): void {
    $s->addColumn('prospects', 'consent_code', 'str'); // krótki kod strony zgody, drukowany w liście

    $s->table('consents', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'prospect_id' => 'bigint',
        'contact_id' => 'bigint',
        'email' => 'str NOT NULL',
        'phone' => 'str',
        'person' => 'text',
        'channels' => 'str NOT NULL',       // email | email,phone
        'wording' => 'text NOT NULL',       // dokładna treść zgody, którą zobaczyła firma
        'source' => 'str NOT NULL',         // page (strona zgody) | manual (zapisana przez użytkownika)
        'detail' => 'text',                 // przy zapisie ręcznym: jak i gdzie firma się zgodziła
        'ip_hash' => 'str',
        'user_agent' => 'text',
        'created_by' => 'bigint',
        'created_at' => 'ts NOT NULL',
        'withdrawn_at' => 'ts',
    ], ['index' => [['org_id', 'prospect_id'], ['org_id', 'email']]]);
    $s->index('prospects', ['consent_code']);
};
