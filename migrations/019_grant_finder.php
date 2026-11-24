<?php
/**
 * Wyszukiwarka naborów: wyszukiwania zlecone przez firmę i nabory, które AI znalazła w internecie.
 * Znalezione pozycje to propozycje ze wskazaniem źródła. Dopiero „Obserwuj” albo „Pisz wniosek” robi z nich nabór firmy.
 */
return static function (App\Core\Schema $s): void {
    $s->table('grant_searches', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'query_json' => 'json',                 // na co firma szuka pieniędzy, region, wielkość
        'auto' => 'bool NOT NULL DEFAULT 0',    // 1 = wyszukiwanie cotygodniowe, bez udziału użytkownika
        'ok' => 'bool NOT NULL DEFAULT 0',
        'found' => 'int NOT NULL DEFAULT 0',    // ile nowych naborów doszło
        'searches' => 'int NOT NULL DEFAULT 0', // ile zapytań do wyszukiwarki wykonała AI
        'error' => 'text',
        'created_by' => 'int',
        'created_at' => 'ts NOT NULL',
    ], ['index' => [['org_id', 'created_at']]]);
    $s->table('grant_finds', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'search_id' => 'ref:grant_searches NOT NULL',
        'name' => 'text NOT NULL',
        'institution' => 'str',
        'url' => 'text NOT NULL',               // strona, na której wyszukiwarka znalazła nabór
        'url_hash' => 'str NOT NULL',
        'source_title' => 'text',
        'official' => 'bool NOT NULL DEFAULT 0', // adres w domenie urzędowej
        'opens_at' => 'date',
        'closes_at' => 'date',
        'amount_note' => 'text',
        'audience_note' => 'text',
        'fit' => 'text',                        // dlaczego pasuje do firmy albo co budzi wątpliwość
        'quote' => 'text',                      // fragment strony przytoczony przez wyszukiwarkę
        'status' => "str NOT NULL DEFAULT 'new'", // new | saved | dismissed
        'call_id' => 'int',                     // nabór firmy utworzony z tej propozycji
        'created_at' => 'ts NOT NULL',
    ], ['unique' => [['org_id', 'url_hash']], 'index' => [['org_id', 'status']]]);
};
