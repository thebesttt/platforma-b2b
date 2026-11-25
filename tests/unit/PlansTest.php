<?php

use App\Core\Db;
use App\Core\Http;
use App\Core\Kv;
use App\Http\CronController;
use App\Modules\Tenders\BzpClient;
use App\Modules\Tenders\PlanImporter;
use App\Modules\Tenders\PlanMatcher;
use App\Modules\Tenders\PlanParser;
use App\Modules\Tenders\Plans;
use App\Modules\Tenders\TedClient;

require_once __DIR__ . '/../fixtures/http/plan_html.php';

/** Plan z treści przykładowej zapisany w bazie, tak jak robi to import. */
$savePlan = static function (int $version = 2, array $drop = []): int {
    $y = (int) gmdate('Y');
    $parsed = PlanParser::parse(plan_fixture_html($y, $version, $drop));
    return Plans::save([
        'source' => 'bzp', 'plan_key' => "$y/BZP 00037981", 'notice_number' => "$y/BZP 00037981/0$version/P", 'version' => $version, 'year' => $parsed['year'],
        'buyer_name' => 'Gmina Jarczów', 'buyer_nip' => '9211980566', 'buyer_city' => 'Jarczów', 'voivodeship' => 'lubelskie',
        'url' => 'https://ezamowienia.gov.pl/mo-client-board/bzp/tender-details/plan', 'published_at' => "$y-01-20 06:12:42",
    ], $parsed['items'])['id'];
};

$profile = static function (int $org, array $over = []): int {
    return Db::get()->insert('tender_profiles', $over + [
        'org_id' => $org, 'name' => 'Usługi komunalne', 'cpv' => '', 'keywords' => "odpady komunalne\nkomputer", 'exclude_keywords' => '',
        'voivodeships' => '', 'order_types' => '', 'sources' => 'bzp ted', 'active' => 1, 'created_at' => now_utc(),
    ]);
};

return [
    'Plany: parser czyta rok, wersję, sekcje, kategorie, wartości i terminy' => function () {
        $y = (int) gmdate('Y');
        $p = PlanParser::parse(plan_fixture_html());
        eq($y, $p['year']);
        eq(2, $p['version']);
        eq('9211980566', $p['buyer_nip']);
        eq(['1.1.1', '1.1.2', '1.2.1', '1.3.1', '1.3.2', '2.1.1'], array_column($p['items'], 'position'));
        eq(['works', 'works', 'supplies', 'services', 'services', 'works'], array_column($p['items'], 'category'));
        eq(['below', 'below', 'below', 'below', 'below', 'above'], array_column($p['items'], 'section'));
        $road = $p['items'][0];
        eq('Przebudowa drogi gminnej nr 111 933 L w miejscowości Wola Gródecka', $road['subject']);
        eq([2100000.0, 'PLN', 2, null, 1], [$road['value_estimated'], $road['currency'], $road['quarter'], $road['month'], $road['sme']]);
        eq('Tryb podstawowym na podstawie: art. 275 pkt 1 ustawy', $road['mode']);
        eq([4, 12], [$p['items'][2]['quarter'], $p['items'][2]['month']], 'termin miesięczny');
        contains("Wstępne konsultacje rynkowe w październiku", $p['items'][2]['info']);
        eq('zmiana pozycji', $p['items'][1]['update_note']);
        eq(['active', 'active', 'active', 'active', 'removed', 'active'], array_column($p['items'], 'status'), 'rezygnacja z pozycji');
        eq([22500000.0, 3, 0], [$p['items'][5]['value_estimated'], $p['items'][5]['quarter'], $p['items'][5]['sme']]);
        eq(['year' => null, 'version' => null, 'buyer_nip' => null, 'items' => []], PlanParser::parse(''));
    },
    'Plany: termin z planu na kwartał i miesiąc' => function () {
        foreach ([
            'II Kwartał' => [2, null], '3 kwartał 2026' => [3, null], 'I i II kwartał' => [1, null], 'II / III kwartał' => [2, null],
            'IV kw.' => [4, null], 'marzec' => [1, 3], 'kwiecień 2026' => [2, 4], 'w październiku' => [4, 10], '05.2026' => [2, 5],
            'II półrocze' => [3, null], 'I półrocze 2026' => [1, null], 'cały rok' => [null, null], '' => [null, null],
        ] as $term => $want) {
            eq($want, PlanParser::when($term), $term);
        }
        eq([1234567.89, 'PLN'], PlanParser::value('1 234 567,89 PLN'));
        eq([50000.0, 'EUR'], PlanParser::value('50 000,00 EUR'));
        eq([null, null], PlanParser::value('-'));
    },
    'Plany: import BZP bierze najnowszą wersję, ponawia nieudane i zapisuje zapowiedzi z TED' => function () {
        BzpClient::$pauseUs = 0;
        Http::$log = [];
        $y = gmdate('Y');
        $res = PlanImporter::run("$y-01-01 00:00:00");
        eq([1, 1], [$res['plans'], $res['ted']]);
        eq(7, $res['items'], '6 pozycji z BZP i 1 z TED');
        contains('Nie udało się pobrać treści 1 planu', implode(' ', $res['errors']));
        $fetched = array_values(array_filter(array_column(Http::$log, 'url'), static fn ($u) => str_contains($u, 'GetNoticeQuery')));
        eq(2, count($fetched), 'treść najnowszej wersji planu i próba planu z błędem, bez starszej wersji');
        ok(!str_contains(implode(' ', $fetched), '%2F01%2FP%22') && str_contains(implode(' ', $fetched), '00037981%2F02%2FP'));
        $plan = Db::get()->one("SELECT * FROM procurement_plans WHERE source = 'bzp'");
        eq(["$y/BZP 00037981", "$y/BZP 00037981/02/P", 2, (int) $y, 'lubelskie', '9211980566', 5],
            [$plan['plan_key'], $plan['notice_number'], (int) $plan['version'], (int) $plan['year'], $plan['voivodeship'], $plan['buyer_nip'], (int) $plan['items_count']]);
        $retry = Kv::get('import:plans:retry');
        eq(["$y/BZP 00049196/01/P"], array_column($retry, 'notice_number'), 'plan z błędem czeka na ponowienie');
        $ted = Db::get()->one("SELECT p.*, i.subject, i.cpv, i.value_estimated, i.month, i.category FROM procurement_plans p JOIN plan_items i ON i.plan_id = p.id WHERE p.source = 'ted'");
        eq(['701234-' . $y, 'mazowieckie', '5252248481', (int) $y + 1, 'services', '72212000 48000000', 2],
            [$ted['plan_key'], $ted['voivodeship'], $ted['buyer_nip'], (int) $ted['year'], $ted['category'], $ted['cpv'], (int) $ted['month']]);
        eq('System obsługi mieszkańców i elektroniczny obieg dokumentów', $ted['subject']);
        eq(4800000.0, (float) $ted['value_estimated']);

        // Drugie uruchomienie: ten sam plan nie jest pobierany drugi raz, a nieudany próbujemy ponownie.
        Http::$log = [];
        $again = PlanImporter::run("$y-01-01 00:00:00");
        eq(0, $again['plans']);
        $fetched = array_values(array_filter(array_column(Http::$log, 'url'), static fn ($u) => str_contains($u, 'GetNoticeQuery')));
        eq(1, count($fetched), 'tylko ponowienie nieudanego planu');
        eq(2, (int) (Kv::get('import:plans:retry')[0]['tries'] ?? 0));
        BzpClient::$pauseUs = 150000;
    },
    'Plany: limit pobrań przerywa import, a następny zaczyna od pierwszego niepobranego planu' => function () {
        BzpClient::$pauseUs = 0;
        $y = gmdate('Y');
        $res = PlanImporter::run(null, 0);
        eq(true, $res['waiting']);
        eq(0, $res['plans']);
        eq("$y-01-20 06:12:41", Kv::get('import:plans:bzp:until'), 'kursor sekundę przed pierwszym niepobranym planem');
        BzpClient::$pauseUs = 150000;
    },
    'Plany: nowa wersja planu aktualizuje pozycje w miejscu i zachowuje zapisane dopasowania' => function () use ($savePlan, $profile) {
        [$org] = make_org();
        $planId = $savePlan(2);
        $db = Db::get();
        $waste = (int) $db->val("SELECT id FROM plan_items WHERE plan_id = ? AND position = '1.3.1'", [$planId]);
        $road = (int) $db->val("SELECT id FROM plan_items WHERE plan_id = ? AND position = '1.1.1'", [$planId]);
        $pid = $profile($org);
        $db->insert('plan_matches', ['org_id' => $org, 'profile_id' => $pid, 'item_id' => $waste, 'score' => 60, 'status' => 'saved', 'created_at' => now_utc()]);
        eq(false, Plans::save(['source' => 'bzp', 'plan_key' => gmdate('Y') . '/BZP 00037981', 'notice_number' => 'x', 'version' => 1], [])['changed'], 'starsza wersja niczego nie zmienia');
        $savePlan(3, ['1.3.1']);
        eq('removed', $db->val('SELECT status FROM plan_items WHERE id = ?', [$waste]), 'pozycji nie ma w nowej wersji');
        eq('active', $db->val('SELECT status FROM plan_items WHERE id = ?', [$road]));
        eq(6, (int) $db->val('SELECT COUNT(*) FROM plan_items WHERE plan_id = ?', [$planId]), 'pozycje zaktualizowane w miejscu');
        eq('saved', $db->val('SELECT status FROM plan_matches WHERE item_id = ?', [$waste]), 'zapisane dopasowanie zostaje');
        eq([3, 4], [(int) $db->val('SELECT version FROM procurement_plans WHERE id = ?', [$planId]), (int) $db->val('SELECT items_count FROM procurement_plans WHERE id = ?', [$planId])]);
    },
    'Plany: dopasowanie po słowach, województwie, rodzaju i terminie' => function () {
        $y = (int) gmdate('Y');
        $now = strtotime("$y-11-10 12:00:00 UTC");
        $item = ['status' => 'active', 'year' => $y, 'source' => 'bzp', 'voivodeship' => 'lubelskie', 'category' => 'services', 'cpv' => null,
            'search' => Plans::searchText('Odbiór i zagospodarowanie odpadów komunalnych', "Zamówienie jest odpowiednie dla MŚP: TAK\nCharakter zamówienia: zamówienia klasyczne"),
            'value_estimated' => 1450000, 'quarter' => 4];
        $p = ['keywords' => "odpady\nodpadów komunalnych", 'voivodeships' => 'lubelskie mazowieckie', 'order_types' => '', 'exclude_keywords' => '', 'cpv' => '', 'sources' => 'bzp ted', 'min_value' => null, 'max_value' => null];
        $s = PlanMatcher::score($item, $p, $now);
        eq(60, $s['score'], 'województwo 10 + słowa 40 + ten kwartał 10');
        eq(['Województwo: Lubelskie', 'Słowa kluczowe: odpady, odpadów komunalnych', 'Wszczęcie planowane w tym kwartale'], $s['reasons']);
        eq(null, PlanMatcher::score(['voivodeship' => 'slaskie'] + $item, $p, $now), 'inne województwo');
        eq(null, PlanMatcher::score($item, ['order_types' => 'works'] + $p, $now), 'inny rodzaj zamówienia');
        eq(null, PlanMatcher::score($item, ['exclude_keywords' => 'komunaln'] + $p, $now), 'słowo wykluczające');
        eq(null, PlanMatcher::score($item, ['keywords' => 'charakter zamowienia'] + $p, $now), 'stałe zdania planu nie są dopasowaniem');
        eq(null, PlanMatcher::score(['status' => 'removed'] + $item, $p, $now), 'wycofana pozycja');
        eq(null, PlanMatcher::score(['year' => $y - 1] + $item, $p, $now), 'plan z ubiegłego roku');
        eq(null, PlanMatcher::score(['source' => 'ted'] + $item, ['sources' => 'bzp'] + $p, $now));
        $past = PlanMatcher::score(['quarter' => 2] + $item, ['keywords' => 'odpady'] + $p, $now);
        eq(40, $past['score'], 'minięty termin nie obniża oceny, ale dostaje ostrzeżenie');
        contains('Termin z planu minął', implode(' ', $past['reasons']));
        eq(null, PlanMatcher::score($item, ['min_value' => 2000000, 'keywords' => 'odpady', 'voivodeships' => ''] + $p, $now), 'wartość poza zakresem spycha pod próg');
        $ted = PlanMatcher::score(['source' => 'ted', 'cpv' => '72212000 48000000', 'search' => 'system obslugi', 'year' => $y + 1, 'quarter' => 1, 'category' => 'services', 'voivodeship' => 'mazowieckie'] + $item,
            ['cpv' => '72200000', 'keywords' => ''] + $p, $now);
        eq(65, $ted['score'], 'CPV 40 + województwo 10 + przyszły rok 15');
    },
    'Plany: ekran pokazuje dopasowania, zapisuje i ukrywa tylko w swojej firmie' => function () use ($savePlan, $profile) {
        [$org, $uid] = make_org();
        $savePlan(2);
        $pid = $profile($org);
        eq(2, PlanMatcher::run($org), 'odpady komunalne i komputery, bez wycofanej pozycji');
        eq(0, PlanMatcher::run($org), 'drugie uruchomienie nie dubluje');
        login_as($uid);
        $page = http('GET', '/przetargi/plany', [], ['kiedy' => 'caly-rok'])->body;
        contains('Odbiór i zagospodarowanie odpadów komunalnych', $page);
        contains('Dostawa sprzętu komputerowego', $page);
        ok(!str_contains($page, 'Utrzymanie strony internetowej'), 'wycofana pozycja nie wraca');
        ok(!str_contains($page, 'Budowa hali sportowej'), 'pozycja spoza profilu');
        contains('plan w BZP, poz. 1.3.1', $page);
        contains('Dlaczego: Słowa kluczowe: odpady komunalne', $page);
        contains('href="/kontrahenci/9211980566"', $page);
        $item = (int) Db::get()->val("SELECT id FROM plan_items WHERE position = '1.3.1'");
        eq(303, http('POST', '/przetargi/plany/' . $item . '/status', ['status' => 'saved'])->status);
        eq('saved', Db::get()->val('SELECT status FROM plan_matches WHERE item_id = ?', [$item]));
        contains('zapisana', http('GET', '/przetargi/plany', [], ['kiedy' => 'caly-rok', 'status' => 'saved'])->body);
        http('POST', '/przetargi/plany/' . $item . '/status', ['status' => 'dismissed']);
        ok(!str_contains(http('GET', '/przetargi/plany', [], ['kiedy' => 'caly-rok'])->body, 'Odbiór i zagospodarowanie'), 'ukryta znika z listy');
        // Wszystkie plany: wyszukiwarka bez profilu.
        $all = http('GET', '/przetargi/plany', [], ['widok' => 'wszystkie', 'kiedy' => 'caly-rok', 'q' => 'hala sportowa'])->body;
        contains('Budowa hali sportowej', $all);
        contains('od progów UE', $all);
        ok(!str_contains(http('GET', '/przetargi/plany', [], ['widok' => 'wszystkie', 'kiedy' => 'caly-rok', 'woj' => 'slaskie'])->body, 'Budowa hali'), 'filtr województwa');
        // Inna firma nie zmieni statusu cudzego dopasowania.
        [$otherOrg, $other] = make_org('inna@example.com', 'Inna Firma sp. z o.o.');
        $profile($otherOrg, ['keywords' => 'hala sportowa']);
        eq(1, PlanMatcher::run($otherOrg));
        login_as($other);
        http('POST', '/przetargi/plany/' . $item . '/status', ['status' => 'new']);
        eq('dismissed', Db::get()->val('SELECT status FROM plan_matches WHERE item_id = ?', [$item]));
        $theirs = http('GET', '/przetargi/plany', [], ['kiedy' => 'caly-rok'])->body;
        contains('Budowa hali sportowej', $theirs);
        ok(!str_contains($theirs, 'Dostawa sprzętu komputerowego'), 'cudze dopasowania niewidoczne');
    },
    'Plany: zapis profilu od razu dopasowuje plany, a liczba nowych jest na liście przetargów' => function () use ($savePlan) {
        [$org, $uid] = make_org();
        $savePlan(2);
        login_as($uid);
        $r = http('POST', '/profile', ['name' => 'Budowlanka', 'keywords' => "termomodernizacja\nhala sportowa", 'active' => '1', 'zrodla' => ['bzp', 'ted']]);
        eq(303, $r->status);
        contains('W planach zamówień: 2 pasujące pozycje', implode(' ', array_column($_SESSION['_flash'] ?? [], 'message')));
        $y = (int) gmdate('Y');
        $quarter = intdiv((int) gmdate('n') - 1, 3) + 1;
        // Nowe w nadchodzących: termomodernizacja (IV kw.) i hala (III kw.), zależnie od dzisiejszego kwartału.
        $expect = ($quarter <= 4 ? 1 : 0) + ($quarter <= 3 ? 1 : 0);
        eq($expect, Plans::newCount($org));
        if ($expect) {
            contains('href="/przetargi/plany">Plany zamówień <span class="chip info">' . $expect . '</span>', http('GET', '/przetargi')->body);
        }
        eq($y, (int) Db::get()->val('SELECT year FROM procurement_plans'));
    },
    'Plany: zadanie dzienne obejmuje plany zamówień' => function () {
        BzpClient::$pauseUs = 0;
        $out = CronController::daily();
        eq(1, $out['plans']['plans'] ?? null);
        eq(1, $out['plans']['ted'] ?? null);
        ok(is_array(Kv::get('import:plans:last')));
        BzpClient::$pauseUs = 150000;
    },
];
