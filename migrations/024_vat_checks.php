<?php
/**
 * Sprawdzenia w wykazie podatników VAT (biała lista) zlecone przez firmę: rachunek przed przelewem albo status VAT
 * na wybrany dzień. Każdy wpis ma klucz zapytania z Ministerstwa Finansów, który potwierdza, że sprawdzenie wykonano.
 * Wpisy należą do firmy, która sprawdzała (dowód jej należytej staranności), inne firmy ich nie widzą.
 */
return static function (App\Core\Schema $s): void {
    $s->table('vat_checks', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'company_id' => 'ref:companies NOT NULL',
        'nip' => 'str NOT NULL',
        'kind' => 'str NOT NULL',          // account | status
        'account' => 'str',                // 26 cyfr, tylko przy sprawdzeniu rachunku
        'amount' => 'money',               // kwota płatności, jeśli użytkownik ją podał
        'check_date' => 'date NOT NULL',   // dzień, którego dotyczy odpowiedź (np. dzień zlecenia przelewu)
        'result' => 'str NOT NULL',        // TAK | NIE przy rachunku, status VAT albo „brak” przy statusie
        'details_json' => 'json',
        'request_id' => 'str NOT NULL',    // klucz zapytania z MF
        'request_at' => 'ts NOT NULL',     // czas zapytania podany przez MF
        'created_by' => 'bigint',
        'created_at' => 'ts NOT NULL',
    ], ['index' => [['org_id', 'company_id']]]);
};
