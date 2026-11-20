<?php
/**
 * Dane ze źródeł, których nie wolno albo nie da się pobierać programem. Panel pokazuje, gdzie je znaleźć, a użytkownik wpisuje wynik:
 *  - sprawdzenie firmy w Krajowym Rejestrze Zadłużonych i liczby z jej sprawozdania finansowego,
 *  - zapytania ofertowe znalezione w Bazie Konkurencyjności, na platformach zakupowych i stronach firm,
 *  - nabory dotacji z wyszukiwarek funduszy.
 * Wszystko jest widoczne tylko dla firmy, która to wpisała.
 */

use App\Core\Schema;

return static function (Schema $s): void {
    $s->table('company_checks', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'company_id' => 'ref:companies NOT NULL',
        'kind' => 'str NOT NULL',            // krz | finance
        'data_json' => 'json NOT NULL',
        'checked_at' => 'date NOT NULL',     // dzień sprawdzenia w źródle
        'created_by' => 'bigint',
        'created_at' => 'ts NOT NULL',
    ], ['index' => [['org_id', 'company_id', 'kind']]]);

    $s->table('rfqs', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'contact_id' => 'bigint',            // kontakt z podstawą „zapytanie ofertowe”, gdy znamy adres e-mail
        'company_name' => 'text NOT NULL',
        'nip' => 'str',
        'person' => 'text',
        'email' => 'str',
        'subject' => 'text NOT NULL',
        'url' => 'text',
        'source' => 'text',                  // gdzie znaleziono zapytanie
        'published_at' => 'date',
        'deadline_at' => 'date',
        'status' => "str NOT NULL DEFAULT 'new'", // new | replied | skipped
        'notes' => 'text',
        'created_by' => 'bigint',
        'created_at' => 'ts NOT NULL',
        'updated_at' => 'ts NOT NULL',
    ], ['index' => [['org_id', 'status', 'deadline_at'], ['contact_id']]]);

    $s->table('grant_calls', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'name' => 'text NOT NULL',
        'institution' => 'text',
        'url' => 'text',
        'opens_at' => 'date',
        'closes_at' => 'date',
        'amount_note' => 'text',             // np. „do 150 000 zł, dofinansowanie 50%”
        'audience_note' => 'text',           // dla kogo
        'notes' => 'text',
        'status' => "str NOT NULL DEFAULT 'watch'", // watch | apply | skip
        'offer_id' => 'bigint',              // wniosek rozpoczęty z tego naboru
        'created_by' => 'bigint',
        'created_at' => 'ts NOT NULL',
        'updated_at' => 'ts NOT NULL',
    ], ['index' => [['org_id', 'status', 'closes_at']]]);
};
