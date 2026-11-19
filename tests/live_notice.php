<?php
/**
 * Diagnostyka jednego ogłoszenia BZP: php tests/live_notice.php <identyfikator> [--html]
 * Wypisuje wszystkie punkty ogłoszenia (kod, etykieta, początek wartości) i wynik parsera.
 */

declare(strict_types=1);

putenv('APP_ENV=test');
putenv('DATABASE_URL=sqlite::memory:');
putenv('HTTP_FIXTURES=');
require __DIR__ . '/../src/bootstrap.php';

use App\Modules\Tenders\BzpClient;
use App\Modules\Tenders\BzpNoticeParser;

$id = $argv[1] ?? '';
$html = (new BzpClient())->noticeHtml($id);
if ($html === null) {
    fwrite(STDERR, "Nie udało się pobrać ogłoszenia $id\n");
    exit(1);
}
if (in_array('--html', $argv, true)) {
    echo $html, "\n";
    exit(0);
}
foreach (BzpNoticeParser::fields($html) as $f) {
    echo $f['code'] . ' | ' . $f['label'] . ' | ' . truncate(str_replace("\n", ' ⏎ ', $f['value']), 140) . "\n";
}
$d = BzpNoticeParser::parse($html);
unset($d['text']);
echo "\n" . json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
