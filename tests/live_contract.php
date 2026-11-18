<?php
/**
 * Test kontraktu z prawdziwymi API (BZP, TED, biała lista VAT, KRS, UOKiK): php tests/live_contract.php
 * Sprawdza, czy struktura odpowiedzi nie zmieniła się. Uruchamiany w GitHub Actions (kontener deweloperski nie ma dostępu do tych serwisów).
 */

declare(strict_types=1);

putenv('APP_ENV=test');
putenv('DATABASE_URL=sqlite::memory:');
putenv('HTTP_FIXTURES=');
require __DIR__ . '/../src/bootstrap.php';

use App\Core\Db;
use App\Core\Http;
use App\Core\Migrator;
use App\Core\Nip;
use App\Modules\Companies\Registries;
use App\Modules\Companies\Uokik;
use App\Modules\Tenders\BzpClient;
use App\Modules\Tenders\BzpNoticeParser;
use App\Modules\Tenders\TedClient;

(new Migrator(Db::get()))->migrate();
$failures = 0;
$check = static function (string $name, callable $fn) use (&$failures): void {
    try {
        $info = $fn();
        echo "  ✓ $name" . ($info ? " – $info" : '') . "\n";
    } catch (Throwable $e) {
        $failures++;
        echo "  ✗ $name – " . $e->getMessage() . "\n";
    }
};
$need = static function (bool $cond, string $msg): void {
    if (!$cond) {
        throw new RuntimeException($msg);
    }
};

$bzpRows = [];
$check('BZP: wyszukiwarka ogłoszeń', function () use (&$bzpRows, $need) {
    $url = BzpClient::BASE . '/Search?' . http_build_query(['NoticeType' => 'ContractNotice', 'publicationDateFrom' => gmdate('Y-m-d\T00:00:00\Z', time() - 3 * 86400), 'SortingColumnName' => 'PublicationDate', 'SortingDirection' => 'DESC', 'PageNumber' => 1, 'PageSize' => 10]);
    $bzpRows = Http::getJson($url) ?? [];
    $need(count($bzpRows) > 0, 'brak ogłoszeń z ostatnich 3 dni');
    foreach (['objectId', 'orderObject', 'submittingOffersDate', 'organizationName', 'cpvCode', 'organizationProvince'] as $k) {
        $need(array_key_exists($k, $bzpRows[0]), "brak pola $k");
    }
    $t = BzpClient::normalize($bzpRows[0]);
    $need($t['deadline_at'] !== null, 'nie odczytano terminu');
    return count($bzpRows) . ' rekordów';
});
$check('BZP: treść ogłoszenia i parser', function () use (&$bzpRows, $need) {
    $need((bool) $bzpRows, 'brak rekordów z poprzedniego kroku');
    $html = (new BzpClient())->noticeHtml((string) $bzpRows[0]['objectId']);
    $need($html !== null, 'brak treści');
    $d = BzpNoticeParser::parse($html);
    $need(count(BzpNoticeParser::fields($html)) > 30, 'za mało punktów ogłoszenia');
    $need($d['deadline'] !== null, 'brak terminu składania ofert (pkt 8.1)');
    return 'termin ' . $d['deadline'] . ', kryteriów: ' . count($d['criteria']);
});
$check('TED: wyszukiwarka API v3', function () use ($need) {
    $rows = (new TedClient())->since(gmdate('Y-m-d', time() - 4 * 86400), 1);
    $need(count($rows) > 0, 'brak ogłoszeń z Polski');
    $need($rows[0]['title'] !== '(bez tytułu)', 'brak tytułu');
    return count($rows) . ' ogłoszeń';
});
$krsNo = null;
$check('Biała lista VAT', function () use (&$bzpRows, &$krsNo, $need) {
    $tried = 0;
    // Najpierw spółki (komunalne itp.) – mają numer KRS potrzebny w kolejnym kroku.
    usort($bzpRows, static fn ($a, $b) => (int) (bool) preg_match('/sp[oó]łk|S\.A\./iu', (string) ($b['organizationName'] ?? '')) <=> (int) (bool) preg_match('/sp[oó]łk|S\.A\./iu', (string) ($a['organizationName'] ?? '')));
    foreach ($bzpRows as $row) {
        $nip = Nip::clean((string) ($row['organizationNationalId'] ?? ''));
        if (!Nip::valid($nip)) {
            continue;
        }
        $tried++;
        $r = Registries::whiteList($nip);
        $need($r['ok'], $r['error'] ?? 'błąd API');
        if (!empty($r['subject']['krs'])) {
            $krsNo = $r['subject']['krs'];
        }
        if ($tried >= 6) {
            break;
        }
    }
    $need($tried > 0, 'brak NIP do sprawdzenia');
    // Zła suma kontrolna – API odpowiada błędem 400 z komunikatem.
    $bad = Registries::whiteList('1234563219');
    $need(!$bad['ok'] && str_contains((string) ($bad['error'] ?? ''), 'NIP'), 'nieprawidłowy NIP: oczekiwano komunikatu API, jest: ' . json_encode($bad, JSON_UNESCAPED_UNICODE));
    return "sprawdzono $tried NIP";
});
$check('KRS: odpis aktualny', function () use (&$krsNo, $need) {
    if ($krsNo === null) {
        return 'pominięto – wśród ostatnich zamawiających nie było spółki z numerem KRS';
    }
    $r = Registries::krs((string) $krsNo);
    $need($r['ok'] && $r['odpis'], 'brak odpisu dla KRS ' . $krsNo);
    $s = Registries::krsSummary($r['odpis']);
    $need(!empty($s['name']) && !empty($s['krs']), 'brak nazwy lub numeru w odpisie');
    return $s['name'];
});
$check('UOKiK: rejestr decyzji (zatory płatnicze)', function () use ($need) {
    $res = Uokik::sync(2);
    if ($res['saved'] === 0 && str_contains(implode(' ', $res['errors']), 'timed out')) {
        return 'OSTRZEŻENIE: rejestr nie przyjmuje połączeń z tej sieci (aktualizacja ręczna w Ustawieniach)';
    }
    $need($res['saved'] > 0, 'nie odczytano decyzji: ' . implode('; ', $res['errors']));
    return $res['saved'] . ' decyzji';
});

echo $failures ? "\n$failures niezaliczonych\n" : "\nWszystkie API odpowiadają zgodnie z oczekiwaniami.\n";
exit($failures ? 1 : 0);
