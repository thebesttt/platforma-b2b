<?php
/**
 * Lista potencjalnych klientów. Firmy trafiają na nią z danych publicznych (wykonawcy z wyników przetargów)
 * albo z listy wklejonej przez użytkownika (NIP-y, adresy stron). Panel dopisuje dane z rejestrów, liczy dopasowanie
 * i podaje powód kontaktu. Samo prowadzenie listy i pisanie oferty nie wymaga zgody firmy. Zgody wymaga wysyłka
 * e-mailem, dlatego karta firmy pokazuje dostępne drogi kontaktu razem z ryzykiem.
 */

use App\Core\Schema;

return static function (Schema $s): void {
    $s->table('prospects', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'company_id' => 'bigint',            // karta firmy z rejestrów, gdy znamy NIP
        'contact_id' => 'bigint',            // kontakt założony przy pierwszej wiadomości
        'nip' => 'str',
        'name' => 'text NOT NULL',
        'search' => 'text',
        'website' => 'text',
        'host' => 'str',                     // domena strony: rozpoznanie powtórek, gdy nie ma NIP-u
        'city' => 'text',
        'voivodeship' => 'str',
        'source' => 'str NOT NULL',          // results (wyniki przetargów) | list (lista użytkownika)
        'facts_json' => 'json',              // wygrane przetargi, wynik sprawdzenia strony, zaproszenie do ofert
        'score' => 'int NOT NULL DEFAULT 0', // dopasowanie 0-100
        'score_json' => 'json',              // rozpisane punkty
        'status' => "str NOT NULL DEFAULT 'new'", // new | picked | contacted | skipped | objected
        'contacted_how' => 'str',            // letter | email
        'contacted_at' => 'ts',
        'enriched_at' => 'ts',               // kiedy pobraliśmy dane z rejestrów
        'site_checked_at' => 'ts',
        'notes' => 'text',
        'created_by' => 'bigint',
        'created_at' => 'ts NOT NULL',
        'updated_at' => 'ts NOT NULL',
    ], ['index' => [['org_id', 'status'], ['org_id', 'nip'], ['org_id', 'host']]]);
};
