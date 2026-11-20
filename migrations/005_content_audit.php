<?php
/** Audyt treści pod wyszukiwarki i AI: strony z frazą, wykonania audytu, wyniki wskaźników i linki dla klienta. */

use App\Core\Schema;

return static function (Schema $s): void {
    // Cel audytu: jedna strona i jedna fraza. Lista pytań pobocznych jest zamrażana, żeby wyniki dało się porównywać.
    $s->table('content_targets', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'contact_id' => 'bigint',                  // klient z listy „Klienci” (opcjonalnie)
        'url' => 'text NOT NULL',
        'host' => 'str NOT NULL',
        'phrase' => 'text NOT NULL',
        'page_type' => "str NOT NULL DEFAULT 'article'", // article | product | home | other
        'questions_json' => 'json',
        'questions_locked_at' => 'ts',
        'created_by' => 'bigint',
        'created_at' => 'ts NOT NULL',
    ], ['unique' => [['org_id', 'url', 'phrase']], 'index' => [['org_id', 'host']]]);

    // Jedno wykonanie audytu. Kroki są zapisane, więc przerwany audyt wznawia się od ostatniego kroku.
    $s->table('content_audits', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'target_id' => 'ref:content_targets NOT NULL',
        'status' => "str NOT NULL DEFAULT 'queued'", // queued | running | done | failed
        'steps_json' => 'json',                    // krok => pending | done | failed
        'ruleset' => 'str NOT NULL',               // wersja wytycznych, według której policzono punkty
        'label' => 'text',                         // np. „przed pracami”
        'score' => 'int',
        'points' => 'int',
        'max_points' => 'int',
        'blocked' => 'bool NOT NULL DEFAULT 0',    // niespełniony warunek konieczny
        'title' => 'text',
        'words' => 'int',
        'text_hash' => 'str',                      // skrót treści głównej; pełnej treści nie przechowujemy
        'state_json' => 'json',                    // dane robocze kroków, po zakończeniu tylko to, czego potrzebuje porównanie
        'error' => 'text',
        'created_by' => 'bigint',
        'created_at' => 'ts NOT NULL',
        'finished_at' => 'ts',
    ], ['index' => [['org_id', 'target_id', 'id']]]);

    $s->table('content_audit_checks', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'audit_id' => 'ref:content_audits NOT NULL',
        'code' => 'str NOT NULL',                  // A1 ... E3
        'status' => 'str NOT NULL',                // good | mid | bad | none | na
        'points' => 'int NOT NULL DEFAULT 0',
        'max_points' => 'int NOT NULL DEFAULT 0',  // 0, gdy wskaźnika nie zmierzono albo nie dotyczy
        'value' => 'text',
        'evidence_json' => 'json',
    ], ['unique' => [['audit_id', 'code']]]);

    // Link dla klienta: raport tylko do odczytu, bez logowania. W bazie jest skrót tokenu, nie token.
    $s->table('content_audit_shares', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'target_id' => 'ref:content_targets NOT NULL',
        'token_hash' => 'str NOT NULL',
        'created_by' => 'bigint',
        'created_at' => 'ts NOT NULL',
        'expires_at' => 'ts',
        'revoked_at' => 'ts',
    ], ['unique' => [['token_hash']], 'index' => [['target_id']]]);
};
