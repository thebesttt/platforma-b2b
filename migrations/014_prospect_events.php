<?php
/**
 * Zdarzenia firmy z listy potencjalnych klientów: list wysłany, wejście na stronę zgody, prośba o raport, zgoda, oferta, odpowiedź.
 * Z nich powstaje lejek listów (czy listy się opłacają) i historia na karcie firmy. Wejścia liczymy bez plików cookie.
 */

use App\Core\Schema;

return static function (Schema $s): void {
    $s->table('prospect_events', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'prospect_id' => 'bigint NOT NULL',
        'kind' => 'str NOT NULL',     // letter | visit | report | report_view | consent | refused | offer | reply
        'detail' => 'text',
        'ip_hash' => 'str',           // tylko przy wejściach: skrót adresu, żeby odświeżenie strony nie liczyło się drugi raz
        'created_by' => 'bigint',
        'created_at' => 'ts NOT NULL',
    ], ['index' => [['org_id', 'kind', 'created_at'], ['prospect_id', 'created_at']]]);
};
