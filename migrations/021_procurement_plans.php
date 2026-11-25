<?php
/**
 * Plany zamówień: plany postępowań z BZP (art. 23 Pzp) i wstępne ogłoszenia informacyjne z TED.
 * Plan i jego pozycje są wspólne dla wszystkich firm (to dane publiczne), a dopasowania do profili należą do firmy.
 * Nowa wersja planu aktualizuje pozycje po numerze pozycji, więc zapisane i ukryte dopasowania zostają.
 */
return static function (App\Core\Schema $s): void {
    $s->table('procurement_plans', [
        'id' => 'id',
        'source' => 'str NOT NULL',          // bzp | ted
        'plan_key' => 'str NOT NULL',        // numer planu w BZP bez wersji albo numer publikacji TED
        'notice_number' => 'str NOT NULL',   // numer najnowszej pobranej wersji
        'version' => 'int NOT NULL DEFAULT 1',
        'year' => 'int',
        'buyer_name' => 'text',
        'buyer_nip' => 'str',
        'buyer_city' => 'text',
        'voivodeship' => 'str',
        'url' => 'text',
        'published_at' => 'ts',
        'items_count' => 'int NOT NULL DEFAULT 0',
        'created_at' => 'ts NOT NULL',
        'updated_at' => 'ts NOT NULL',
    ], ['unique' => [['source', 'plan_key']], 'index' => [['year', 'voivodeship']]]);
    $s->table('plan_items', [
        'id' => 'id',
        'plan_id' => 'ref:procurement_plans NOT NULL',
        'position' => 'str NOT NULL',
        'section' => 'str',                  // below | above (progi unijne)
        'category' => 'str',                 // works | supplies | services
        'subject' => 'text NOT NULL',
        'mode' => 'text',
        'value_estimated' => 'money',
        'currency' => 'str',
        'term' => 'text',
        'quarter' => 'int',
        'month' => 'int',
        'info' => 'text',
        'sme' => 'bool',
        'cpv' => 'text',
        'search' => 'text NOT NULL',         // przedmiot i informacje bez polskich znaków, do dopasowania słów kluczowych
        'status' => "str NOT NULL DEFAULT 'active'", // active | removed (zamawiający zrezygnował z pozycji)
        'update_note' => 'text',
        'created_at' => 'ts NOT NULL',
        'updated_at' => 'ts NOT NULL',
    ], ['unique' => [['plan_id', 'position']]]);
    $s->table('plan_matches', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'profile_id' => 'ref:tender_profiles NOT NULL',
        'item_id' => 'ref:plan_items NOT NULL',
        'score' => 'int NOT NULL',
        'reasons_json' => 'json',
        'status' => "str NOT NULL DEFAULT 'new'", // new | saved | dismissed
        'created_at' => 'ts NOT NULL',
    ], ['unique' => [['profile_id', 'item_id']], 'index' => [['org_id', 'status']]]);
};
