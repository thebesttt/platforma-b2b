<?php
/**
 * Audyt strony firmy z listy potencjalnych klientów jako powód, żeby firma chciała zostawić adres e-mail.
 * List i strona zgody obiecują raport, a firma dostaje go pod linkiem z losowym tokenem.
 */

use App\Core\Schema;

return static function (Schema $s): void {
    $s->addColumn('prospects', 'audit_id', 'bigint');     // audyt techniczny strony tej firmy
    $s->addColumn('site_audits', 'share_token', 'str');   // token publicznego raportu, tylko dla audytów przypiętych do firmy z listy
    $s->index('site_audits', ['share_token']);
};
