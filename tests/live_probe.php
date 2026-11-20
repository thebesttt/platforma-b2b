<?php
declare(strict_types=1);
putenv('APP_ENV=test');
putenv('DATABASE_URL=sqlite::memory:');
putenv('HTTP_FIXTURES=');
require __DIR__ . '/../src/bootstrap.php';

use App\Core\Http;
use App\Modules\Tenders\BzpClient;
use App\Modules\Tenders\BzpNoticeParser;

$c = new BzpClient();
$dump = static function (string $id, string $why) use ($c): void {
    $html = (string) $c->noticeHtml($id);
    echo "\n##### HTML $id ($why) " . strlen($html) . " B\n";
    echo "<<<B64\n" . chunk_split(base64_encode(gzencode($html, 9)), 160, "\n") . "B64>>>\n";
};
$dump('08df43d4-41e2-3c9f-c1c5-d50001942f7e', 'dwie czesci');
$dump('08df43d7-b031-2d40-c1c5-d50001943049', 'puste wyniki czesci');
$dump('08df43d5-1bbf-0759-c1c5-d50001942fb4', 'nierozstrzygnieta czesc');
$dump('08df43d2-7d1d-f757-c1c5-d50001942f2a', 'jedna czesc');

// Szukamy konsorcjum (7.1 = Tak) i wyniku bez wcześniejszego ogłoszenia (2.13 = Nie)
$from = gmdate('Y-m-d\TH:i:s\Z', time() - 4 * 86400);
$found = ['konsorcjum' => 0, 'bez_ogloszenia' => 0];
for ($page = 1; $page <= 12 && ($found['konsorcjum'] < 2 || $found['bez_ogloszenia'] < 1); $page++) {
    $rows = Http::getJson(BzpClient::BASE . '/Search?' . http_build_query([
        'NoticeType' => 'TenderResultNotice', 'publicationDateFrom' => $from,
        'SortingColumnName' => 'PublicationDate', 'SortingDirection' => 'ASC', 'PageNumber' => $page, 'PageSize' => 10,
    ]));
    foreach ((array) $rows as $r) {
        $html = (string) $c->noticeHtml((string) $r['objectId']);
        $f = BzpNoticeParser::fields($html);
        $by = [];
        foreach ($f as $x) { $by[$x['code']][] = $x['value']; }
        $kons = false;
        foreach ($by['7.1'] ?? [] as $v) { if (str_starts_with($v, 'Tak')) { $kons = true; } }
        $bez = str_starts_with((string) ($by['2.13'][0] ?? ''), 'Nie');
        if (($kons && $found['konsorcjum'] < 2) || ($bez && $found['bez_ogloszenia'] < 1)) {
            $kons ? $found['konsorcjum']++ : $found['bez_ogloszenia']++;
            $copy = $r; unset($copy['htmlBody']);
            echo "\n##### WIERSZ " . ($kons ? 'konsorcjum' : 'bez ogloszenia') . "\n" . json_encode($copy, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            echo "<<<B64\n" . chunk_split(base64_encode(gzencode($html, 9)), 160, "\n") . "B64>>>\n";
        }
        usleep(200000);
    }
}
echo "\nznalezione: " . json_encode($found) . "\n";
