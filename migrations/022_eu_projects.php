<?php
/**
 * Projekty z funduszy europejskich 2021-2027 realizowane przez firmy (lista z funduszeeuropejskie.gov.pl, aktualizowana co miesiąc).
 * Dane publiczne, wspólne dla wszystkich firm na platformie. Zapisujemy tylko projekty spółek (bez osób fizycznych i spółek cywilnych)
 * trwające albo zakończone w ostatnich miesiącach: firma z dotacją ma budżet i często kupuje usługi w czasie projektu.
 */
return static function (App\Core\Schema $s): void {
    $s->table('eu_projects', [
        'id' => 'id',
        'contract_no' => 'str NOT NULL',      // numer umowy o dofinansowanie
        'title' => 'text NOT NULL',
        'summary' => 'text',
        'beneficiary' => 'text NOT NULL',
        'beneficiary_key' => 'str NOT NULL',  // nazwa bez polskich znaków i formy prawnej, do łączenia projektów jednej firmy
        'fund' => 'str',
        'program' => 'text',
        'action' => 'text',                   // działanie programu
        'value_total' => 'money',
        'eu_amount' => 'money',
        'place' => 'text',
        'voivodeships' => 'text',             // slugi rozdzielone spacjami, puste = cały kraj
        'start_date' => 'date',
        'end_date' => 'date',
        'category' => 'text',
        'search' => 'text NOT NULL',
        'created_at' => 'ts NOT NULL',
        'updated_at' => 'ts NOT NULL',
    ], ['unique' => [['contract_no']], 'index' => [['end_date'], ['beneficiary_key']]]);
};
