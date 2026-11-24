<?php
/**
 * Widoczność witryny: dane z Google Search Console (plik albo API), sygnały z odpowiedzi AI (Google, Copilot),
 * wejścia z asystentów AI, boty AI z Cloudflare i publiczne mapy witryn konkurentów.
 * Jedna tabela statystyk dla wszystkich źródeł: źródło + wymiar + okres + klucz.
 */

use App\Core\Schema;

return static function (Schema $s): void {
    $s->table('seo_sites', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'host' => 'str NOT NULL',
        'brand_terms' => 'str',          // nazwy marki po przecinku: do podziału zapytań na markowe i pozostałe
        'gsc_property' => 'str',         // np. sc-domain:firma.pl albo https://firma.pl/
        'ga4_property' => 'str',         // numer usługi GA4
        'cf_zone' => 'str',              // identyfikator strefy w Cloudflare
        'rivals_json' => 'text',         // domeny konkurentów
        'synced_json' => 'text',         // kiedy i z jakim skutkiem pobrano dane z każdego źródła
        'created_at' => 'ts NOT NULL',
    ], ['unique' => [['org_id', 'host']]]);

    $s->table('seo_stats', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'site_id' => 'ref:seo_sites NOT NULL',
        'source' => 'str NOT NULL',      // gsc | gsc_ai | bing_ai | ga4_ai | cf_bot
        'dim' => 'str NOT NULL',         // total | date | query | page | query_page | device | country | source | bot | bot_page
        'period_start' => 'date NOT NULL',
        'period_end' => 'date NOT NULL',
        'k' => "text NOT NULL DEFAULT ''",
        'k2' => "text NOT NULL DEFAULT ''",
        'clicks' => 'int NOT NULL DEFAULT 0',
        'impressions' => 'int NOT NULL DEFAULT 0',
        'position' => 'num',
        'value' => 'num',                // liczba spoza GSC: cytowania, sesje, zapytania zablokowane
    ], ['index' => [['site_id', 'source', 'dim', 'period_end']]]);

    $s->table('seo_rival_pages', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'site_id' => 'ref:seo_sites NOT NULL',
        'host' => 'str NOT NULL',
        'url' => 'text NOT NULL',
        'lastmod' => 'date',
        'baseline' => 'bool NOT NULL DEFAULT 0',   // 1 = strona była w mapie przy pierwszym pobraniu, więc nie wiemy, kiedy powstała
        'first_seen' => 'ts NOT NULL',
    ], ['unique' => [['site_id', 'url']], 'index' => [['site_id', 'host', 'first_seen']]]);
};
