<?php
/**
 * Kampanie e-mail dopasowane do każdego odbiorcy. Kampania ma szablon z polami ({powitanie}, {powod} itd.),
 * a każdy odbiorca dostaje własną wersję zbudowaną z faktów o jego firmie (opcjonalnie dopracowaną przez AI).
 * Panel wysyła sam tylko do odbiorców ze zgodą; pozostałe wersje użytkownik pobiera i wysyła ze swojej skrzynki
 * po zapoznaniu się z ryzykiem. Wypisani odbiorcy nie dostają nic.
 */
return static function (App\Core\Schema $s): void {
    $s->table('campaigns', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'name' => 'text NOT NULL',
        'subject' => 'text NOT NULL',
        'body' => 'text NOT NULL',
        'status' => "str NOT NULL DEFAULT 'draft'", // draft | sending | done
        'created_by' => 'bigint',
        'created_at' => 'ts NOT NULL',
        'updated_at' => 'ts NOT NULL',
        'sent_at' => 'ts',
    ], ['index' => [['org_id']]]);
    $s->table('campaign_recipients', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'campaign_id' => 'ref:campaigns NOT NULL',
        'contact_id' => 'ref:contacts NOT NULL',
        'subject' => 'text NOT NULL',
        'body' => 'text NOT NULL',
        'facts_json' => 'json',                 // fakty użyte w tej wersji (do podglądu „skąd to zdanie”)
        'source' => "str NOT NULL DEFAULT 'rules'", // rules | ai | manual
        'risk' => 'str NOT NULL',               // low | high | very_high
        'route' => 'str NOT NULL',              // panel (wysyła panel) | own (użytkownik wysyła ze swojej skrzynki)
        'ack_text' => 'text',
        'ack_at' => 'ts',
        'ack_by' => 'bigint',
        'status' => "str NOT NULL DEFAULT 'pending'", // pending | excluded | sent | failed | downloaded | blocked
        'error' => 'text',
        'sent_at' => 'ts',
        'created_at' => 'ts NOT NULL',
        'updated_at' => 'ts NOT NULL',
    ], ['unique' => [['campaign_id', 'contact_id']], 'index' => [['org_id', 'status'], ['contact_id']]]);
};
