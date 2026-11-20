<?php
/** Praca kilku osób nad ofertą: osoba odpowiedzialna za sekcję, ochrona przed nadpisaniem cudzej zmiany, lista dokumentów do złożenia. */

use App\Core\Schema;

return static function (Schema $s): void {
    $s->addColumn('offer_sections', 'assignee_id', 'bigint');             // osoba odpowiedzialna za sekcję
    $s->addColumn('offer_sections', 'updated_by', 'bigint');              // kto ostatnio zapisał treść
    $s->addColumn('offer_sections', 'version', 'int NOT NULL DEFAULT 1'); // rośnie przy każdym zapisie treści

    // Dokumenty do złożenia z ofertą. Pozycje startowe pochodzą z ogłoszenia i analizy dokumentów, resztę dopisuje zespół.
    $s->table('offer_checklist', [
        'id' => 'id',
        'org_id' => 'ref:organizations NOT NULL',
        'offer_id' => 'ref:offers NOT NULL',
        'label' => 'text NOT NULL',
        'source' => 'text',                    // skąd wynika wymóg, np. „Ogłoszenie, pkt 5.4”
        'done' => 'bool NOT NULL DEFAULT 0',
        'done_by' => 'bigint',
        'done_at' => 'ts',
        'position' => 'int NOT NULL DEFAULT 0',
        'created_at' => 'ts NOT NULL',
    ], ['index' => [['org_id', 'offer_id']]]);
};
