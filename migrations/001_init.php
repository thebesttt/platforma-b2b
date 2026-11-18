<?php
/** Schemat startowy: konta firm, przetargi, kontrahenci, oferty, wiadomości, audyty. */

use App\Core\Schema;

return static function (Schema $s): void {
    // --- Konta ---------------------------------------------------------------
    $s->table('organizations', [
        'id' => 'id',
        'name' => 'text NOT NULL',
        'nip' => 'str',
        'address' => 'text',
        'phone' => 'str',
        'website' => 'text',
        'plan' => "str NOT NULL DEFAULT 'trial'",
        'settings_json' => 'json',
        'created_at' => 'ts NOT NULL',
    ]);
    $s->table('users', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'email' => 'str NOT NULL',
        'name' => 'text',
        'password_hash' => 'text NOT NULL',
        'role' => "str NOT NULL DEFAULT 'member'",
        'created_at' => 'ts NOT NULL',
        'last_login_at' => 'ts',
    ], ['unique' => [['email']], 'index' => [['org_id']]]);
    $s->table('sessions', [
        'id' => 'str PRIMARY KEY',
        'data' => 'text NOT NULL',
        'expires_at' => 'bigint NOT NULL',
    ], ['index' => [['expires_at']]]);
    $s->table('kv', [
        'k' => 'str PRIMARY KEY',
        'v' => 'text',
        'expires_at' => 'bigint',
    ]);
    $s->table('audit_log', [
        'id' => 'id',
        'org_id' => 'bigint',
        'user_id' => 'bigint',
        'action' => 'str NOT NULL',
        'entity' => 'str',
        'entity_id' => 'str',
        'data_json' => 'json',
        'ip' => 'str',
        'created_at' => 'ts NOT NULL',
    ], ['index' => [['org_id', 'created_at']]]);

    // --- Przetargi -----------------------------------------------------------
    $s->table('tenders', [
        'id' => 'id',
        'source' => 'str NOT NULL',           // bzp | ted
        'external_id' => 'str NOT NULL',      // objectId BZP / numer publikacji TED
        'notice_number' => 'str',
        'notice_type' => 'str',
        'title' => 'text NOT NULL',
        'order_type' => 'str',                // works | services | supplies
        'buyer_name' => 'text',
        'buyer_nip' => 'str',
        'buyer_city' => 'text',
        'voivodeship' => 'str',               // slug, np. mazowieckie
        'cpv_main' => 'str',
        'cpv_all' => 'text',                  // kody 8-cyfrowe rozdzielone spacjami
        'value_estimated' => 'money',
        'currency' => 'str',
        'published_at' => 'ts',
        'deadline_at' => 'ts',
        'url' => 'text',
        'documents_url' => 'text',
        'search' => 'text',                   // znormalizowany tytuł + zamawiający
        'raw_json' => 'json',
        'details_json' => 'json',             // sparsowana treść ogłoszenia
        'details_fetched_at' => 'ts',
        'created_at' => 'ts NOT NULL',
        'updated_at' => 'ts NOT NULL',
    ], ['unique' => [['source', 'external_id']], 'index' => [['published_at'], ['deadline_at'], ['buyer_nip']]]);
    $s->table('tender_profiles', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'name' => 'text NOT NULL',
        'cpv' => 'text',                      // prefiksy CPV rozdzielone spacjami
        'keywords' => 'text',                 // słowa kluczowe, po jednym w linii
        'exclude_keywords' => 'text',
        'voivodeships' => 'text',             // slugi rozdzielone spacjami, puste = cała Polska
        'order_types' => 'text',
        'min_value' => 'money',
        'max_value' => 'money',
        'sources' => "str NOT NULL DEFAULT 'bzp ted'",
        'active' => 'bool NOT NULL DEFAULT 1',
        'matched_until' => 'ts',
        'created_at' => 'ts NOT NULL',
    ], ['index' => [['org_id']]]);
    $s->table('tender_matches', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'profile_id' => 'ref:tender_profiles NOT NULL',
        'tender_id' => 'ref:tenders NOT NULL',
        'score' => 'int NOT NULL',
        'reasons_json' => 'json',
        'status' => "str NOT NULL DEFAULT 'new'", // new | saved | dismissed
        'created_at' => 'ts NOT NULL',
    ], ['unique' => [['profile_id', 'tender_id']], 'index' => [['org_id', 'status']]]);
    $s->table('tender_documents', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'tender_id' => 'ref:tenders NOT NULL',
        'filename' => 'text NOT NULL',
        'mime' => 'str',
        'size' => 'int',
        'content' => 'blob',
        'text' => 'text',
        'uploaded_by' => 'bigint',
        'created_at' => 'ts NOT NULL',
    ], ['index' => [['org_id', 'tender_id']]]);
    $s->table('tender_analyses', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'tender_id' => 'ref:tenders NOT NULL',
        'kind' => "str NOT NULL DEFAULT 'summary'", // summary | qa
        'question' => 'text',
        'result_json' => 'json',
        'model' => 'str',
        'created_by' => 'bigint',
        'created_at' => 'ts NOT NULL',
    ], ['index' => [['org_id', 'tender_id']]]);

    // --- Kontrahenci ---------------------------------------------------------
    $s->table('companies', [
        'id' => 'id',
        'nip' => 'str NOT NULL',
        'regon' => 'str',
        'krs' => 'str',
        'name' => 'text',
        'search' => 'text',
        'legal_form' => 'text',
        'vat_status' => 'str',
        'address' => 'text',
        'pkd' => 'text',
        'registered_at' => 'date',
        'whitelist_json' => 'json',
        'krs_json' => 'json',
        'signals_json' => 'json',
        'risk_score' => 'int',
        'snapshot_hash' => 'str',
        'refreshed_at' => 'ts',
        'created_at' => 'ts NOT NULL',
    ], ['unique' => [['nip']], 'index' => [['krs']]]);
    $s->table('uokik_decisions', [
        'id' => 'id',
        'signature' => 'str NOT NULL',
        'company_name' => 'text NOT NULL',
        'search' => 'text',
        'nip' => 'str',
        'decided_at' => 'date',
        'summary' => 'text',
        'url' => 'text',
        'created_at' => 'ts NOT NULL',
    ], ['unique' => [['signature']], 'index' => [['nip']]]);
    $s->table('watchlist', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'company_id' => 'ref:companies NOT NULL',
        'note' => 'text',
        'created_at' => 'ts NOT NULL',
    ], ['unique' => [['org_id', 'company_id']]]);
    $s->table('alerts', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'company_id' => 'bigint',
        'tender_id' => 'bigint',
        'level' => "str NOT NULL DEFAULT 'info'", // info | mid | high
        'title' => 'text NOT NULL',
        'detail' => 'text',
        'read_at' => 'ts',
        'created_at' => 'ts NOT NULL',
    ], ['index' => [['org_id', 'read_at']]]);
    $s->table('receivables', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'company_id' => 'ref:companies NOT NULL',
        'invoice_no' => 'str NOT NULL',
        'amount' => 'money NOT NULL',
        'currency' => "str NOT NULL DEFAULT 'PLN'",
        'issued_at' => 'date',
        'due_at' => 'date NOT NULL',
        'paid_at' => 'date',
        'demand_sent_at' => 'date',
        'demand_bureau' => 'text',
        'notes' => 'text',
        'created_at' => 'ts NOT NULL',
    ], ['index' => [['org_id', 'company_id']]]);

    // --- Oferty i biblioteka -------------------------------------------------
    $s->table('library_items', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'kind' => 'str NOT NULL',             // reference | person | certificate | insurance | text
        'title' => 'text NOT NULL',
        'body' => 'text',
        'meta_json' => 'json',
        'valid_until' => 'date',
        'created_at' => 'ts NOT NULL',
        'updated_at' => 'ts NOT NULL',
    ], ['index' => [['org_id', 'kind']]]);
    $s->table('offers', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'tender_id' => 'bigint',
        'title' => 'text NOT NULL',
        'status' => "str NOT NULL DEFAULT 'draft'", // draft | review | ready | submitted
        'created_by' => 'bigint',
        'created_at' => 'ts NOT NULL',
        'updated_at' => 'ts NOT NULL',
    ], ['index' => [['org_id']]]);
    $s->table('offer_sections', [
        'id' => 'id',
        'offer_id' => 'ref:offers NOT NULL',
        'key' => 'str NOT NULL',
        'title' => 'text NOT NULL',
        'body' => 'text',
        'sources_json' => 'json',
        'status' => "str NOT NULL DEFAULT 'draft'",
        'position' => 'int NOT NULL DEFAULT 0',
        'updated_at' => 'ts NOT NULL',
    ], ['unique' => [['offer_id', 'key']]]);

    // --- Klienci i wiadomości ------------------------------------------------
    $s->table('contacts', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'company_name' => 'text NOT NULL',
        'nip' => 'str',
        'person' => 'text',
        'email' => 'str NOT NULL',
        'phone' => 'str',
        'basis' => "str NOT NULL DEFAULT 'none'", // none | rfq | consent | client
        'basis_detail' => 'text',
        'basis_url' => 'text',
        'basis_at' => 'date',
        'unsubscribed_at' => 'ts',
        'notes' => 'text',
        'created_at' => 'ts NOT NULL',
    ], ['index' => [['org_id', 'email']]]);
    $s->table('messages', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'contact_id' => 'ref:contacts NOT NULL',
        'kind' => 'str NOT NULL',             // rfq_reply | consented | consent_request | cold_offer | demand
        'risk' => 'str NOT NULL',             // low | high | very_high
        'subject' => 'text NOT NULL',
        'body' => 'text NOT NULL',
        'ack_text' => 'text',
        'ack_by' => 'bigint',
        'ack_at' => 'ts',
        'status' => "str NOT NULL DEFAULT 'draft'", // draft | sent
        'sent_at' => 'ts',
        'created_by' => 'bigint',
        'created_at' => 'ts NOT NULL',
    ], ['index' => [['org_id', 'created_at']]]);

    // --- Audyt strony --------------------------------------------------------
    $s->table('site_audits', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'url' => 'text NOT NULL',
        'host' => 'str',
        'score' => 'int',
        'result_json' => 'json',
        'created_by' => 'bigint',
        'created_at' => 'ts NOT NULL',
    ], ['index' => [['org_id', 'created_at']]]);
};
