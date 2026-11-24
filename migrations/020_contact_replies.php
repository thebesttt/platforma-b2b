<?php
/**
 * Odpowiedzi klientów wklejone przez użytkownika. Wiadomości wychodzą z jego własnej skrzynki, więc panel nie widzi odpowiedzi:
 * użytkownik wkleja treść przy oznaczeniu „Firma odpisała”, a panel pokazuje ją na karcie i na liście kontaktów.
 */
return static function (App\Core\Schema $s): void {
    $s->table('contact_replies', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'contact_id' => 'ref:contacts NOT NULL',
        'body' => 'text NOT NULL',
        'received_at' => 'date',       // kiedy klient napisał, jeśli użytkownik podał datę
        'created_by' => 'bigint',
        'created_at' => 'ts NOT NULL',
    ], ['index' => [['org_id', 'contact_id']]]);
};
