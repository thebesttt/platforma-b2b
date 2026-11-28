<?php
/**
 * Monitory opisane jednym zdaniem, alarm pomiaru GA4 i raporty miesięczne dla klientów agencji.
 * Powiadomienia z tych funkcji mają osobną tabelę: alerty kontrahentów i przetargów zostają bez zmian.
 */

use App\Core\Schema;

return static function (Schema $s): void {
    $s->addColumn('seo_sites', 'health_json', 'text');   // stan alarmu pomiaru: od kiedy trwa i kiedy powiadomiliśmy

    $s->table('monitors', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'sentence' => 'text NOT NULL',          // zdanie użytkownika, bez zmian
        'kind' => 'str NOT NULL',               // rodzaj reguły z katalogu (Monitors::KINDS)
        'params_json' => 'text NOT NULL',       // progi i obiekt reguły: witryna, fraza, adres, liczba dni
        'site_id' => 'bigint',
        'active' => 'bool NOT NULL DEFAULT 1',
        'parsed_by' => "str NOT NULL DEFAULT 'panel'", // panel | ai | form: kto zamienił zdanie na regułę
        'state_json' => 'text',                 // wynik ostatniego sprawdzenia
        'checked_at' => 'ts',
        'fired_at' => 'ts',
        'created_by' => 'bigint',
        'created_at' => 'ts NOT NULL',
    ], ['index' => [['org_id', 'active']]]);

    $s->table('notices', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'kind' => 'str NOT NULL',               // tracking | monitor | report
        'site_id' => 'bigint',
        'monitor_id' => 'bigint',
        'level' => "str NOT NULL DEFAULT 'info'", // info | mid | high
        'title' => 'text NOT NULL',
        'detail' => 'text',
        'url' => 'text',
        'read_at' => 'ts',
        'created_at' => 'ts NOT NULL',
    ], ['index' => [['org_id', 'created_at']]]);

    $s->table('seo_reports', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'site_id' => 'ref:seo_sites NOT NULL',
        'month' => 'str NOT NULL',              // RRRR-MM
        'data_json' => 'text NOT NULL',         // liczby zapisane w chwili tworzenia: raport nie zmienia się, gdy dane się postarzeją
        'done_text' => 'text',                  // co agencja zrobiła w tym miesiącu (pisze człowiek)
        'next_text' => 'text',                  // plan na następny miesiąc
        'token' => 'str',                       // link dla klienta, pusty dopóki raport nie jest udostępniony
        'shared_at' => 'ts',
        'created_by' => 'bigint',
        'created_at' => 'ts NOT NULL',
        'updated_at' => 'ts NOT NULL',
    ], ['unique' => [['site_id', 'month'], ['token']]]);
};
