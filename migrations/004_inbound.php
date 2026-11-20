<?php
/** Poczta wychodząca platformy oraz zapytania ze strony: widżet z kwalifikatorem, czat AI, zapytania i liczniki do lejka. */

use App\Core\Schema;

return static function (Schema $s): void {
    // Każda wiadomość wysłana przez platformę (powiadomienia, reset hasła). Także te, których nie udało się wysłać.
    $s->table('outbox', [
        'id' => 'id',
        'org_id' => 'bigint',
        'recipient' => 'str NOT NULL',
        'subject' => 'text NOT NULL',
        'body' => 'text NOT NULL',
        'kind' => 'str',
        'status' => 'str NOT NULL',            // sent | logged | failed | skipped
        'error' => 'text',
        'created_at' => 'ts NOT NULL',
    ], ['index' => [['org_id', 'created_at']]]);

    $s->table('inbound_widgets', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'public_id' => 'str NOT NULL',         // losowy identyfikator w adresie kodu widżetu
        'name' => 'text NOT NULL',
        'origins' => 'text',                   // dozwolone strony, po jednej w wierszu
        'rules_json' => 'json NOT NULL',       // pytania, punkty, progi
        'rules_version' => 'int NOT NULL DEFAULT 1',
        'texts_json' => 'json',
        'limits_json' => 'json',
        'ab_test' => 'bool NOT NULL DEFAULT 0',
        'chat' => 'bool NOT NULL DEFAULT 1',
        'active' => 'bool NOT NULL DEFAULT 1',
        'created_at' => 'ts NOT NULL',
        'updated_at' => 'ts',
    ], ['unique' => [['public_id']], 'index' => [['org_id']]]);

    $s->table('inbound_conversations', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'widget_id' => 'ref:inbound_widgets NOT NULL',
        'token' => 'str NOT NULL',             // losowy, zna go tylko przeglądarka odwiedzającego
        'variant' => 'str NOT NULL',           // a = zwykły formularz, b = kwalifikator
        'page_url' => 'text',
        'answers_json' => 'json',
        'messages' => 'int NOT NULL DEFAULT 0',
        'status' => "str NOT NULL DEFAULT 'open'", // open | completed | lead
        'preview' => 'bool NOT NULL DEFAULT 0',    // rozmowa z podglądu w panelu: nie liczy się do wyników
        'ip_hash' => 'str',
        'started_at' => 'ts NOT NULL',
        'updated_at' => 'ts',
    ], ['unique' => [['token']], 'index' => [['widget_id', 'started_at']]]);

    $s->table('inbound_messages', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'conversation_id' => 'ref:inbound_conversations NOT NULL',
        'role' => 'str NOT NULL',              // user | assistant
        'text' => 'text NOT NULL',
        'created_at' => 'ts NOT NULL',
    ], ['index' => [['conversation_id']]]);

    $s->table('inbound_leads', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'widget_id' => 'bigint',
        'conversation_id' => 'bigint',
        'contact_id' => 'bigint',
        'variant' => 'str',
        'score' => 'int NOT NULL',
        'level' => 'str NOT NULL',             // hot | talk | low
        'points_json' => 'json',
        'rules_version' => 'int',
        'name' => 'text',
        'email' => 'str NOT NULL',
        'phone' => 'str',
        'nip' => 'str',
        'company_id' => 'bigint',
        'site_url' => 'text',
        'site_audit_id' => 'bigint',
        'message' => 'text',
        'marketing_consent' => 'bool NOT NULL DEFAULT 0',
        'consent_text' => 'text',
        'summary' => 'text',
        'status' => "str NOT NULL DEFAULT 'new'", // new | contact | offer | won | lost
        'page_url' => 'text',
        'enriched_at' => 'ts',
        'created_at' => 'ts NOT NULL',
    ], ['index' => [['org_id', 'created_at']]]);

    $s->table('inbound_stats', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'widget_id' => 'ref:inbound_widgets NOT NULL',
        'day' => 'date NOT NULL',
        'variant' => 'str NOT NULL',
        'views' => 'int NOT NULL DEFAULT 0',
        'started' => 'int NOT NULL DEFAULT 0',
        'completed' => 'int NOT NULL DEFAULT 0',
        'leads' => 'int NOT NULL DEFAULT 0',
        'hot' => 'int NOT NULL DEFAULT 0',
    ], ['unique' => [['widget_id', 'day', 'variant']]]);

    // Baza wiedzy czatu: strony firmy pobrane i zapisane jako tekst.
    $s->table('inbound_knowledge', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'url' => 'text NOT NULL',
        'title' => 'text',
        'body' => 'text',
        'chars' => 'int NOT NULL DEFAULT 0',
        'fetched_at' => 'ts NOT NULL',
    ], ['index' => [['org_id']]]);

    // Zgoda na oferty e-mailem zaznaczona w formularzu na stronie: kiedy i w jakim brzmieniu.
    $s->addColumn('contacts', 'marketing_consent_at', 'ts');
    $s->addColumn('contacts', 'marketing_consent_text', 'text');
};
