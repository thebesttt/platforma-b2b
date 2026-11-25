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
use App\Modules\Outreach\EuProjects;
use App\Modules\Tenders\BzpClient;
use App\Modules\Tenders\BzpNoticeParser;
use App\Modules\Tenders\PlanParser;
use App\Modules\Tenders\TedClient;

(new Migrator(Db::get()))->migrate();
$failures = 0;
$check = static function (string $name, callable $fn) use (&$failures): void {
    try {
        $info = $fn();
        echo "  ✓ $name" . ($info ? " - $info" : '') . "\n";
    } catch (Throwable $e) {
        $failures++;
        echo "  ✗ $name - " . $e->getMessage() . "\n";
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
$check('BZP: ogłoszenia o wyniku (kto wygrał i za ile)', function () use ($need) {
    // Dwie strony wyszukiwarki z ostatnich dni roboczych i treść 25 ogłoszeń: wynik, wykonawca, ceny, liczba ofert.
    $rows = [];
    foreach ([1, 2, 3] as $page) {
        $url = BzpClient::BASE . '/Search?' . http_build_query(['NoticeType' => BzpClient::RESULT_TYPE, 'publicationDateFrom' => gmdate('Y-m-d\T00:00:00\Z', time() - 5 * 86400), 'SortingColumnName' => 'PublicationDate', 'SortingDirection' => 'ASC', 'PageNumber' => $page, 'PageSize' => 10]);
        $rows = array_merge($rows, Http::getJson($url) ?? []);
        usleep(200000);
    }
    $need(count($rows) >= 10, 'za mało ogłoszeń o wyniku z ostatnich 5 dni: ' . count($rows));
    foreach (['objectId', 'procedureResult', 'contractors', 'tenderId', 'orderObject', 'organizationName'] as $k) {
        $need(array_key_exists($k, $rows[0]), "brak pola $k");
    }
    $stats = ['ogłoszeń' => 0, 'części' => 0, 'umów' => 0, 'z_wykonawcą' => 0, 'z_ceną' => 0, 'z_ofertami' => 0, 'zgodnych_części' => 0, 'zgodnych_wykonawców' => 0];
    $client = new BzpClient();
    foreach (array_slice($rows, 0, 25) as $row) {
        $fromSearch = BzpClient::normalizeResult($row);
        $html = $client->noticeHtml((string) $row['objectId']);
        $need($html !== null, 'brak treści ogłoszenia ' . $row['objectId']);
        $parsed = BzpNoticeParser::results($html);
        $need((bool) $parsed['parts'], 'parser nie rozpoznał ogłoszenia ' . $row['objectId'] . ' (' . ($row['noticeNumber'] ?? '') . ')');
        $need($parsed['ocid'] === ($row['tenderId'] ?? null), 'inny identyfikator postępowania w treści niż w wyszukiwarce: ' . $row['objectId']);
        $stats['ogłoszeń']++;
        $byPart = [];
        foreach ($fromSearch as $r) {
            $byPart[$r['part']] = $r;
        }
        $stats['zgodnych_części'] += (int) (array_column($parsed['parts'], 'part') === array_keys($byPart));
        foreach ($parsed['parts'] as $p) {
            $stats['części']++;
            if ($p['result'] !== 'contract') {
                continue;
            }
            $stats['umów']++;
            $stats['z_wykonawcą'] += (int) !empty($p['winners'][0]['name']);
            $stats['z_ceną'] += (int) (($p['contract_value'] ?? $p['price_winner'] ?? null) !== null);
            $stats['z_ofertami'] += (int) (($p['offers'] ?? null) !== null);
            $stats['zgodnych_wykonawców'] += (int) (($byPart[$p['part']]['winner_name'] ?? null) === ($p['winners'][0]['name'] ?? ''));
        }
        usleep(200000);
    }
    $need($stats['umów'] > 0, 'żadne z ogłoszeń nie kończy się umową');
    $need($stats['z_wykonawcą'] >= 0.95 * $stats['umów'], 'wykonawca odczytany w ' . $stats['z_wykonawcą'] . ' z ' . $stats['umów'] . ' umów');
    $need($stats['z_ceną'] >= 0.9 * $stats['umów'], 'cena odczytana w ' . $stats['z_ceną'] . ' z ' . $stats['umów'] . ' umów');
    $need($stats['zgodnych_części'] >= 0.9 * $stats['ogłoszeń'], 'numery części z treści zgadzają się z wyszukiwarką w ' . $stats['zgodnych_części'] . ' z ' . $stats['ogłoszeń'] . ' ogłoszeń');
    return json_encode($stats, JSON_UNESCAPED_UNICODE);
});
$check('TED: wyszukiwarka API v3', function () use ($need) {
    $rows = (new TedClient())->since(gmdate('Y-m-d', time() - 4 * 86400), 1);
    $need(count($rows) > 0, 'brak ogłoszeń z Polski');
    $need($rows[0]['title'] !== '(bez tytułu)', 'brak tytułu');
    return count($rows) . ' ogłoszeń';
});
$check('BZP: plany postępowań (lista, treść i parser)', function () use ($need) {
    $bzp = new BzpClient();
    BzpClient::$pauseUs = 100000;
    // Styczeń ma najwięcej planów, więc sprawdzamy początek roku: najpierw bieżącego, potem poprzedniego.
    $plans = $bzp->plansSince(gmdate('Y') . '-01-15 00:00:00', 2) ?: $bzp->plansSince(((int) gmdate('Y') - 1) . '-01-15 00:00:00', 2);
    $need(count($plans) > 0, 'brak planów w wyszukiwarce (NoticeType=TenderPlanNotice)');
    $need((bool) preg_match('#^\d{4}/BZP \d+$#', $plans[0]['plan_key']), 'nieoczekiwany numer planu: ' . $plans[0]['plan_key']);
    $stats = ['planów' => 0, 'z_rokiem' => 0, 'z_pozycjami' => 0, 'pozycji' => 0, 'z_kwartałem' => 0, 'z_wartością' => 0, 'z_kategorią' => 0];
    foreach (array_slice($plans, 0, 6) as $p) {
        $html = $bzp->planHtml($p['notice_number']);
        $need($html !== null, 'brak treści planu ' . $p['notice_number'] . ' (GetNoticeQuery)');
        $parsed = PlanParser::parse($html);
        $stats['planów']++;
        $stats['z_rokiem'] += $parsed['year'] ? 1 : 0;
        $stats['z_pozycjami'] += $parsed['items'] ? 1 : 0;
        foreach ($parsed['items'] as $it) {
            $stats['pozycji']++;
            $stats['z_kwartałem'] += $it['quarter'] ? 1 : 0;
            $stats['z_wartością'] += $it['value_estimated'] !== null ? 1 : 0;
            $stats['z_kategorią'] += $it['category'] ? 1 : 0;
        }
        usleep(150000);
    }
    $need($stats['z_rokiem'] === $stats['planów'], 'rok planu odczytany w ' . $stats['z_rokiem'] . ' z ' . $stats['planów']);
    $need($stats['z_pozycjami'] >= $stats['planów'] - 1, 'pozycje odczytane w ' . $stats['z_pozycjami'] . ' z ' . $stats['planów'] . ' planów');
    $need($stats['z_wartością'] >= 0.9 * $stats['pozycji'], 'wartość odczytana w ' . $stats['z_wartością'] . ' z ' . $stats['pozycji'] . ' pozycji');
    $need($stats['z_kategorią'] >= 0.9 * $stats['pozycji'], 'kategoria odczytana w ' . $stats['z_kategorią'] . ' z ' . $stats['pozycji'] . ' pozycji');
    $need($stats['z_kwartałem'] >= 0.8 * $stats['pozycji'], 'termin odczytany w ' . $stats['z_kwartałem'] . ' z ' . $stats['pozycji'] . ' pozycji');
    return json_encode($stats, JSON_UNESCAPED_UNICODE);
});
$check('TED: wstępne ogłoszenia informacyjne (zapowiedzi)', function () use ($need) {
    $r = Http::postJson(TedClient::URL, [
        'query' => 'buyer-country=POL AND notice-type IN (' . TedClient::PLAN_TYPES . ') AND publication-date>=' . gmdate('Ymd', time() - 120 * 86400),
        'fields' => TedClient::PLAN_FIELDS, 'page' => 1, 'limit' => 20, 'scope' => 'ALL', 'paginationMode' => 'PAGE_NUMBER',
    ]);
    $need($r['status'] === 200, 'kod ' . $r['status'] . ': ' . substr((string) $r['body'], 0, 300));
    $j = json_decode((string) $r['body'], true);
    $notices = $j['notices'] ?? [];
    $need(count($notices) > 0, 'brak zapowiedzi z Polski z ostatnich 120 dni');
    $types = array_count_values(array_map(static fn ($n) => (string) ($n['notice-type'] ?? '?'), $notices));
    $p = TedClient::normalizePlan($notices[0]);
    $need($p['plan']['buyer_name'] !== null && $p['items'][0]['subject'] !== '(bez tytułu)', 'brak zamawiającego albo tytułu');
    $withDate = count(array_filter($notices, static fn ($n) => !empty($n['future-notice'])));
    $withRegion = count(array_filter(array_map(static fn ($n) => TedClient::normalizePlan($n)['plan']['voivodeship'], $notices)));
    return count($notices) . ' zapowiedzi, typy: ' . json_encode($types) . ", z datą ogłoszenia: $withDate, z województwem: $withRegion";
});
$check('Fundusze UE: lista projektów 2021-2027 (adres pliku, nagłówek, spółki)', function () use ($need) {
    $url = EuProjects::sourceUrl();
    $need($url !== null, 'nie znaleziono adresu pliku CSV na stronie ' . EuProjects::PAGE_URL);
    // Tylko początek pliku (ok. 400 kB), żeby nie pobierać 150 MB przy każdym sprawdzeniu.
    $r = Http::get($url, ['Range' => 'bytes=0-400000'], 60);
    $need(in_array($r['status'], [200, 206], true), 'kod ' . $r['status'] . ' przy pobieraniu ' . $url);
    $body = substr($r['body'], 0, 400000);
    $body = substr($body, 0, (int) strrpos($body, "\n")); // ostatni wiersz bywa ucięty
    $tmp = tempnam(sys_get_temp_dir(), 'fe');
    file_put_contents($tmp, $body);
    $res = EuProjects::importFile($tmp);
    unlink($tmp);
    $need(!$res['errors'], implode('; ', $res['errors']));
    $need($res['read'] > 50, 'za mało wierszy: ' . $res['read']);
    $withRegion = (int) Db::get()->val("SELECT COUNT(*) FROM eu_projects WHERE voivodeships <> ''");
    $withValue = (int) Db::get()->val('SELECT COUNT(*) FROM eu_projects WHERE value_total IS NOT NULL');
    $withEnd = (int) Db::get()->val('SELECT COUNT(*) FROM eu_projects WHERE end_date IS NOT NULL');
    $need($res['kept'] === 0 || $withValue >= 0.9 * $res['kept'], 'wartość odczytana w ' . $withValue . ' z ' . $res['kept']);
    $need($res['kept'] === 0 || $withEnd >= 0.9 * $res['kept'], 'data zakończenia odczytana w ' . $withEnd . ' z ' . $res['kept']);
    return basename($url) . ': przeczytano ' . $res['read'] . ' wierszy, spółek z trwającymi projektami ' . $res['kept'] . ", z województwem $withRegion";
});
$krsNo = null;
$check('Biała lista VAT', function () use (&$bzpRows, &$krsNo, $need) {
    $tried = 0;
    // Najpierw spółki (komunalne itp.) - mają numer KRS potrzebny w kolejnym kroku.
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
    // Zła suma kontrolna - API odpowiada błędem 400 z komunikatem.
    $bad = Registries::whiteList('1234563219');
    $need(!$bad['ok'] && str_contains((string) ($bad['error'] ?? ''), 'NIP'), 'nieprawidłowy NIP: oczekiwano komunikatu API, jest: ' . json_encode($bad, JSON_UNESCAPED_UNICODE));
    return "sprawdzono $tried NIP";
});
$check('KRS: odpis aktualny', function () use (&$krsNo, $need) {
    if ($krsNo === null) {
        return 'pominięto - wśród ostatnich zamawiających nie było spółki z numerem KRS';
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
