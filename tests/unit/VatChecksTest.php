<?php

use App\Core\Db;
use App\Core\Http;
use App\Core\Kv;
use App\Core\Nrb;
use App\Modules\Companies\Companies;
use App\Modules\Companies\Registries;
use App\Modules\Companies\Risk;
use App\Modules\Companies\VatChecks;

/** Rachunki z poprawną sumą kontrolną: TAK w wykazie (fixture), NIE w wykazie i taki, przy którym API zwraca limit (429). */
const WL_ACC_YES = '61109010140000071219812874';
const WL_ACC_NO = '30102055610000390200001234';
const WL_ACC_LIMIT = '89114020040000350212345678';

return [
    'Biała lista: numer rachunku z sumą kontrolną jak w banku' => function () {
        ok(Nrb::valid(WL_ACC_YES));
        ok(Nrb::valid('PL 61 1090 1014 0000 0712 1981 2874'), 'z PL i odstępami');
        ok(Nrb::valid('44103015080000000550465159'), 'prawdziwy rachunek przyjęty przez API MF');
        ok(!Nrb::valid('12103015080000000550465159'), 'ten sam z błędnymi cyframi kontrolnymi: API MF odrzuca go kodem WL-111');
        ok(!Nrb::valid('6110901014000007121981287'), '25 cyfr');
        eq('61 1090 1014 0000 0712 1981 2874', Nrb::format(WL_ACC_YES));
    },
    'Biała lista: sprawdzenie rachunku zapisuje odpowiedź i klucz zapytania MF' => function () {
        [$org, $uid] = make_org();
        $c = Companies::lookup('1234563218')['company'];
        Http::$log = [];
        $yes = VatChecks::checkAccount($org, $uid, $c, 'PL61 1090 1014 0000 0712 1981 2874', Registries::today(), 18450.0);
        eq(true, $yes['assigned']);
        eq('https://wl-api.mf.gov.pl/api/check/nip/1234563218/bank-account/' . WL_ACC_YES . '?date=' . Registries::today(), Http::$log[0]['url']);
        eq(['account', WL_ACC_YES, 'TAK', 'KPYYH-99ch9dm', '2026-11-27 20:43:58'], [$yes['row']['kind'], $yes['row']['account'], $yes['row']['result'], $yes['row']['request_id'], $yes['row']['request_at']], 'czas MF (polski) zapisany w UTC');
        contains('jest na białej liście', VatChecks::verdict($yes['row']));
        $no = VatChecks::checkAccount($org, $uid, $c, WL_ACC_NO, '2026-11-20', 18450.0);
        eq(false, $no['assigned']);
        contains('złóż zawiadomienie ZAW-NR w ciągu 7 dni', VatChecks::verdict($no['row']));
        $small = VatChecks::checkAccount($org, $uid, $c, WL_ACC_NO, '2026-11-20', 900.0);
        contains('sankcje z białej listy nie działają', VatChecks::verdict($small['row']), 'kwota do 15 000 zł');
        eq(3, count(VatChecks::forCompany($org, (int) $c['id'])));
    },
    'Biała lista: błędy wykrywane przed zapytaniem nie zużywają limitu API' => function () {
        [$org, $uid] = make_org();
        $c = Companies::lookup('1234563218')['company'];
        $before = Registries::whiteListLeft();
        Http::$log = [];
        contains('26 cyfr i sumę kontrolną', VatChecks::checkAccount($org, $uid, $c, '12103015080000000550465159', Registries::today())['error']);
        $tomorrow = (new DateTimeImmutable('tomorrow', new DateTimeZone('Europe/Warsaw')))->format('Y-m-d');
        contains('tylko za dni do dziś', VatChecks::checkAccount($org, $uid, $c, WL_ACC_YES, $tomorrow)['error']);
        contains('od 01.01.2020', VatChecks::checkAccount($org, $uid, $c, WL_ACC_YES, '2019-09-01')['error']);
        contains('RRRR-MM-DD', VatChecks::checkStatus($org, $uid, $c, '27.11.2026')['error']);
        eq([], Http::$log, 'żadnego zapytania do MF');
        eq($before, Registries::whiteListLeft());
        // Błąd z API (dzień sprzed zakresu rejestru) i blokada po limicie.
        contains('sprzed zakresu rejestru', VatChecks::checkAccount($org, $uid, $c, WL_ACC_YES, '2020-01-02')['error']);
        contains('limit zapytań na dziś', VatChecks::checkAccount($org, $uid, $c, WL_ACC_LIMIT, Registries::today())['error']);
        eq(0, Registries::whiteListLeft(), 'po odpowiedzi 429 panel nie pyta MF do północy');
        contains('prawie wyczerpany', VatChecks::checkAccount($org, $uid, $c, WL_ACC_YES, Registries::today())['error']);
        eq(0, (int) Db::get()->val('SELECT COUNT(*) FROM vat_checks'), 'błędy nie zapisują potwierdzeń');
    },
    'Biała lista: dzienny przydział firmy i rezerwa na nocne odświeżanie' => function () {
        [$org, $uid] = make_org();
        $c = Companies::lookup('1234563218')['company'];
        Kv::set('wl:org:' . $org . ':' . Registries::today(), (string) VatChecks::DAILY_PER_ORG);
        contains('wykorzystała dziś 20 sprawdzeń', VatChecks::checkAccount($org, $uid, $c, WL_ACC_YES, Registries::today())['error']);
        eq(0, VatChecks::left($org));
        [$org2, $uid2] = make_org('inna@example.com', 'Inna Firma sp. z o.o.');
        eq(true, VatChecks::checkAccount($org2, $uid2, $c, WL_ACC_YES, Registries::today())['ok'], 'inna firma ma własny przydział');
        Kv::set('wl:calls:' . Registries::today(), (string) (Registries::WL_DAILY - VatChecks::RESERVE));
        eq(0, VatChecks::left($org2), 'ostatnie zapytania zostają dla nocnego odświeżania');
        contains('limit Ministerstwa Finansów dla całego panelu', VatChecks::checkAccount($org2, $uid2, $c, WL_ACC_YES, Registries::today())['error']);
    },
    'Biała lista: status VAT na dzień faktury' => function () {
        [$org, $uid] = make_org();
        $c = Companies::lookup('1234563218')['company'];
        $r = VatChecks::checkStatus($org, $uid, $c, '2025-03-10');
        eq('Zwolniony', $r['status']);
        eq('Hist1-99ch9fa', $r['row']['request_id']);
        eq('Status VAT w dniu 10.03.2025: Zwolniony.', VatChecks::verdict($r['row']));
        eq('2024-03-01', json_in($r['row']['details_json'])['registrationLegalDate']);
    },
    'Biała lista: karta firmy z sekcją, sprawdzenie z formularza, potwierdzenia w CSV i izolacja firm' => function () {
        [$org, $uid] = make_org();
        login_as($uid);
        $page = http('GET', '/kontrahenci/1234563218')->body;
        contains('Biała lista VAT: rachunek przed przelewem', $page);
        contains('Status VAT dziś: <b>czynny</b>, zarejestrowany od 01.03.2024', $page);
        contains('klucz zapytania MF: <code>test-1</code>', $page, 'klucz z ostatniego pobrania karty');
        contains('Dziś możesz wykonać jeszcze 20 sprawdzeń', $page);
        $r = http('POST', '/kontrahenci/1234563218/biala-lista', ['kind' => 'account', 'account' => '61 1090 1014 0000 0712 1981 2874', 'date' => Registries::today(), 'amount' => '18 450,00']);
        eq('/kontrahenci/1234563218#biala-lista', $r->headers['Location']);
        contains('jest na białej liście', implode(' ', array_column($_SESSION['_flash'] ?? [], 'message')));
        $_SESSION['_flash'] = [];
        http('POST', '/kontrahenci/1234563218/biala-lista', ['kind' => 'account', 'account' => WL_ACC_NO, 'amount' => 'dużo']);
        contains('Kwotę podaj liczbą', implode(' ', array_column($_SESSION['_flash'] ?? [], 'message')));
        eq(1, (int) Db::get()->val('SELECT COUNT(*) FROM vat_checks WHERE org_id = ?', [$org]), 'zła kwota: bez zapytania i bez wpisu');
        eq(18450.0, (float) Db::get()->val('SELECT amount FROM vat_checks WHERE org_id = ?', [$org]));
        http('POST', '/kontrahenci/1234563218/biala-lista', ['kind' => 'status', 'date' => '2025-03-10']);
        $page = http('GET', '/kontrahenci/1234563218')->body;
        contains('<code>KPYYH-99ch9dm</code>', $page);
        contains('rachunek <span class="nowrap">61 1090 1014 0000 0712 1981 2874</span>', $page);
        contains('zwolniony', $page);
        contains('Dziś możesz wykonać jeszcze 18 sprawdzeń', $page);
        $csv = http('GET', '/kontrahenci/1234563218/biala-lista.csv');
        contains('text/csv', $csv->headers['Content-Type'] ?? '');
        contains('"rachunek";"61 1090 1014 0000 0712 1981 2874";"18450,00"', $csv->body);
        contains('"KPYYH-99ch9dm";"27.11.2026 21:43:58";"Jan Test"', $csv->body, 'czas MF z powrotem po polsku');
        eq(2, (int) Db::get()->val("SELECT COUNT(*) FROM audit_log WHERE action = 'company.vat_check' AND org_id = ?", [$org]), 'sprawdzenia w dzienniku zdarzeń');
        // Inna firma widzi kartę kontrahenta (dane publiczne), ale nie cudze sprawdzenia i potwierdzenia.
        [, $other] = make_org('inna@example.com', 'Inna Firma sp. z o.o.');
        login_as($other);
        $page = http('GET', '/kontrahenci/1234563218')->body;
        ok(!str_contains($page, 'KPYYH-99ch9dm') && !str_contains($page, 'Pobierz potwierdzenia'), 'cudze sprawdzenia niewidoczne');
        $csv = http('GET', '/kontrahenci/1234563218/biala-lista.csv')->body;
        ok(!str_contains($csv, 'KPYYH'), 'cudze potwierdzenia nie trafiają do CSV');
        eq(404, http('GET', '/kontrahenci/5260250274/biala-lista.csv')->status, 'firma, której nikt nie sprawdzał');
    },
    'Biała lista: niedawne przywrócenie do rejestru VAT podnosi ryzyko' => function () {
        $now = strtotime('2026-11-27 12:00:00 UTC');
        $r = Risk::assess(['statusVat' => 'Czynny', 'accountCount' => 2, 'restorationDate' => '2026-06-01', 'removalDate' => '2025-11-03'], [], [], $now);
        $sig = array_values(array_filter($r['signals'], static fn ($s) => $s['title'] === 'Niedawno przywrócony jako podatnik VAT'))[0] ?? null;
        ok($sig !== null);
        contains('Przywrócenie do rejestru 01.06.2026, wcześniej wykreślenie 03.11.2025', $sig['detail']);
        $old = Risk::assess(['statusVat' => 'Czynny', 'accountCount' => 2, 'restorationDate' => '2024-01-10'], [], [], $now);
        ok(!in_array('Niedawno przywrócony jako podatnik VAT', array_column($old['signals'], 'title'), true), 'przywrócenie sprzed roku bez sygnału');
    },
];
