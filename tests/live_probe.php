<?php
declare(strict_types=1);
putenv('APP_ENV=test');
putenv('DATABASE_URL=sqlite::memory:');
putenv('HTTP_FIXTURES=');
require __DIR__ . '/../src/bootstrap.php';

use App\Core\Http;
use App\Modules\Tenders\BzpClient;
use App\Modules\Tenders\BzpNoticeParser;

$from = gmdate('Y-m-d\TH:i:s\Z', time() - 4 * 86400);
$seen = [];
foreach ([1, 2, 3, 4, 5, 6] as $page) {
    $rows = Http::getJson(BzpClient::BASE . '/Search?' . http_build_query([
        'NoticeType' => 'TenderResultNotice', 'publicationDateFrom' => $from,
        'SortingColumnName' => 'PublicationDate', 'SortingDirection' => 'ASC', 'PageNumber' => $page, 'PageSize' => 10,
    ]));
    echo "== strona $page: " . (is_array($rows) ? count($rows) : 'null') . "\n";
    foreach ((array) $rows as $r) {
        $res = (string) ($r['procedureResult'] ?? '');
        $n = is_array($r['contractors'] ?? null) ? count($r['contractors']) : -1;
        echo "ROW " . $r['objectId'] . ' | ' . $r['noticeNumber'] . ' | ' . $res . ' | wykonawcy=' . $n . ' | html=' . (isset($r['htmlBody']) ? strlen((string) $r['htmlBody']) : 'null') . ' | ' . ($r['tenderId'] ?? '') . "\n";
        $key = $res . '|' . min($n, 2);
        if (!isset($seen[$key]) && count($seen) < 6) {
            $seen[$key] = true;
            $copy = $r; unset($copy['htmlBody']);
            echo json_encode($copy, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            $html = (new BzpClient())->noticeHtml((string) $r['objectId']);
            echo "--- pola ogłoszenia (" . strlen((string) $html) . " B)\n";
            foreach (BzpNoticeParser::fields((string) $html) as $f) {
                echo $f['code'] . ' | ' . $f['label'] . ' | ' . truncate(str_replace("\n", ' ⏎ ', $f['value']), 300) . "\n";
            }
            if (count($seen) === 1) {
                echo "--- surowy HTML (początek i środek)\n" . substr((string) $html, 0, 3000) . "\n.....\n" . substr((string) $html, (int) (strlen((string) $html) * 0.6), 6000) . "\n";
            }
            usleep(300000);
        }
    }
    usleep(300000);
}
// Ogłoszenie o zamówieniu z tego samego postępowania: czy tenderId się zgadza
echo "== ContractNotice (1 wiersz)\n";
$rows = Http::getJson(BzpClient::BASE . '/Search?' . http_build_query(['NoticeType' => 'ContractNotice', 'publicationDateFrom' => $from, 'PageNumber' => 1, 'PageSize' => 1]));
$c = $rows[0] ?? []; unset($c['htmlBody']);
echo json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
