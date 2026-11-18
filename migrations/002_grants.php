<?php
/** Wnioski o dotacje obok ofert przetargowych: rodzaj dokumentu, nabór, dokumenty naboru. */

use App\Core\Schema;

return static function (Schema $s): void {
    $s->addColumn('offers', 'kind', "str NOT NULL DEFAULT 'tender'"); // tender | grant
    $s->addColumn('offers', 'call_name', 'text');                       // nazwa naboru / programu
    $s->addColumn('offers', 'call_url', 'text');
    $s->addColumn('offers', 'deadline_at', 'ts');
    $s->table('offer_documents', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'offer_id' => 'ref:offers NOT NULL',
        'filename' => 'text NOT NULL',
        'mime' => 'str',
        'size' => 'int',
        'text' => 'text',
        'uploaded_by' => 'bigint',
        'created_at' => 'ts NOT NULL',
    ], ['index' => [['org_id', 'offer_id']]]);
};
