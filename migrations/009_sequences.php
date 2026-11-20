<?php
/** Klienci: przypomnienia po wysłanej wiadomości (sekwencja trzech kroków), oznaczenie odpowiedzi i link wypisania. */

use App\Core\Schema;

return static function (Schema $s): void {
    $s->addColumn('contacts', 'unsub_token', 'str');   // losowy token linku wypisania w stopce wiadomości
    $s->addColumn('contacts', 'replied_at', 'ts');     // odbiorca odpisał: przypomnienia się zatrzymują
    $s->index('contacts', ['unsub_token']);
    $s->addColumn('messages', 'sequence_id', 'bigint');
    $s->addColumn('messages', 'step', 'int');          // 0 pierwsza wiadomość, 1 przypomnienie, 2 ostatnia wiadomość

    $s->table('sequences', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'contact_id' => 'ref:contacts NOT NULL',
        'kind' => 'str NOT NULL',                      // rodzaj pierwszej wiadomości (ten sam poziom ryzyka w kolejnych krokach)
        'status' => "str NOT NULL DEFAULT 'active'",   // active | done | stopped
        'stop_reason' => 'str',                        // replied | unsubscribed | manual | finished
        'step' => 'int NOT NULL DEFAULT 0',            // ostatni wysłany krok
        'next_at' => 'ts',                             // kiedy przypomnieć o kolejnym kroku
        'started_at' => 'ts NOT NULL',
        'updated_at' => 'ts NOT NULL',
        'created_by' => 'bigint',
    ], ['index' => [['org_id', 'status', 'next_at'], ['contact_id']]]);
};
