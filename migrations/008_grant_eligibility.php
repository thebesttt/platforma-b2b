<?php
/** Dotacje: wynik sprawdzenia warunków naboru dla firmy (lista warunków z cytatami z regulaminu i z danych firmy). */

use App\Core\Schema;

return static function (Schema $s): void {
    $s->table('offer_eligibility', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'offer_id' => 'ref:offers NOT NULL',
        'result_json' => 'json NOT NULL',     // warunki, liczba odrzuconych pozycji, nazwy dokumentów
        'model' => 'str',
        'created_by' => 'bigint',
        'created_at' => 'ts NOT NULL',
    ], ['index' => [['org_id', 'offer_id']]]);
};
