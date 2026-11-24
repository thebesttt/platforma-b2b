<?php
/**
 * Platforma dla wielu firm: stan firmy (blokada), pakiety z limitem AI, zaproszenia firm z pakietem,
 * zaszyfrowane sekrety firm (własne połączenia), unieważnianie sesji i logowanie dwuetapowe.
 */

use App\Core\Schema;

return static function (Schema $s): void {
    $s->addColumn('organizations', 'status', "str NOT NULL DEFAULT 'active'");   // active | blocked
    $s->addColumn('users', 'session_epoch', 'int NOT NULL DEFAULT 0');           // zmiana numeru wylogowuje wszystkie sesje osoby
    $s->addColumn('users', 'totp_secret', 'text');                               // zaszyfrowany klucz aplikacji z kodami
    $s->addColumn('users', 'totp_on', 'bool NOT NULL DEFAULT 0');
    $s->addColumn('users', 'recovery_json', 'text');                             // skróty jednorazowych kodów zapasowych

    // Zużycie AI: jeden wiersz na firmę, miesiąc i rodzaj (ops = funkcje panelu, chat = odpowiedzi czatu na stronie).
    $s->table('ai_usage', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'month' => 'str NOT NULL',
        'kind' => 'str NOT NULL',
        'calls' => 'int NOT NULL DEFAULT 0',
        'input_tokens' => 'bigint NOT NULL DEFAULT 0',
        'output_tokens' => 'bigint NOT NULL DEFAULT 0',
        'updated_at' => 'ts',
    ], ['unique' => [['org_id', 'month', 'kind']]]);

    // Zaproszenie firmy: jednorazowy link przypisany do adresu e-mail, z pakietem. W bazie zostaje tylko skrót tokenu.
    $s->table('invites', [
        'id' => 'id',
        'token_hash' => 'str NOT NULL',
        'email' => 'str NOT NULL',
        'company' => 'text',
        'plan' => 'str NOT NULL',
        'created_by' => 'bigint',
        'created_at' => 'ts NOT NULL',
        'expires_at' => 'ts NOT NULL',
        'used_at' => 'ts',
        'org_id' => 'bigint',
    ], ['unique' => [['token_hash']]]);

    // Sekrety firmy (token Cloudflare, zgoda Google). Wartość jest zaszyfrowana kluczem z APP_SECRET.
    $s->table('org_secrets', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'name' => 'str NOT NULL',
        'value_enc' => 'text NOT NULL',
        'meta_json' => 'text',
        'created_by' => 'bigint',
        'created_at' => 'ts NOT NULL',
    ], ['unique' => [['org_id', 'name']]]);
};
