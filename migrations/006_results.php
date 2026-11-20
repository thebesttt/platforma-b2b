<?php
/** Wyniki przetargów z BZP: kto wygrał, za ile i ile było ofert. Jeden wiersz na część ogłoszenia o wyniku. */

use App\Core\Schema;

return static function (Schema $s): void {
    // Identyfikator postępowania łączy ogłoszenie o zamówieniu z ogłoszeniem o wyniku.
    $s->addColumn('tenders', 'ocid', 'str');
    $s->index('tenders', ['ocid']);

    $s->table('tender_results', [
        'id' => 'id',
        'source' => 'str NOT NULL',             // bzp
        'external_id' => 'str NOT NULL',        // identyfikator ogłoszenia o wyniku
        'part' => 'int NOT NULL DEFAULT 1',     // numer części zamówienia
        'notice_number' => 'str',
        'ocid' => 'str',                        // identyfikator postępowania
        'base_notice' => 'str',                 // numer ogłoszenia o zamówieniu (z treści)
        'title' => 'text NOT NULL',
        'order_type' => 'str',
        'buyer_name' => 'text',
        'buyer_nip' => 'str',
        'buyer_city' => 'text',
        'voivodeship' => 'str',
        'cpv_main' => 'str',
        'cpv_all' => 'text',
        'result' => 'str NOT NULL',             // contract | cancelled | unresolved
        'winner_name' => 'text',
        'winner_nip' => 'str',
        'winner_city' => 'text',
        'winner_voivodeship' => 'str',
        'offers' => 'int',                      // liczba ofert
        'price_lowest' => 'money',
        'price_highest' => 'money',
        'price_winner' => 'money',              // cena oferty wybranego wykonawcy
        'contract_value' => 'money',            // wartość umowy
        'est_value' => 'money',                 // wartość szacunkowa części (bez VAT)
        'currency' => 'str',
        'contract_date' => 'date',
        'details_json' => 'json',               // konsorcjum, wielkość firmy, okres realizacji, podstawa unieważnienia
        'details_fetched_at' => 'ts',           // kiedy odczytaliśmy treść ogłoszenia (ceny i liczba ofert są tylko tam)
        'search' => 'text',
        'published_at' => 'ts',
        'url' => 'text',
        'created_at' => 'ts NOT NULL',
        'updated_at' => 'ts NOT NULL',
    ], ['unique' => [['source', 'external_id', 'part']], 'index' => [['ocid'], ['winner_nip'], ['buyer_nip'], ['published_at']]]);
};
