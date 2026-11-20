<?php

use App\Core\Db;
use App\Core\Kv;
use App\Http\CronController;
use App\Modules\Notify\Digest;
use App\Modules\Tenders\BzpClient;
use App\Modules\Tenders\BzpNoticeParser;
use App\Modules\Tenders\Results;
use App\Modules\Tenders\Tenders;

$fx = __DIR__ . '/../fixtures/http/';

/** Import wyników z zapisanych odpowiedzi BZP. Daty publikacji przesuwamy na wczoraj, żeby filtry okresu nie zależały od dnia testu. */
$import = static function (): array {
    BzpClient::$pauseUs = 0;
    $res = Results::import('2026-11-15 00:00:00');
    Db::get()->run('UPDATE tender_results SET published_at = ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
    return $res;
};

/** Wiersz wyniku do testów ekranów. */
$row = static fn (array $over = []): array => $over + [
    'source' => 'bzp', 'external_id' => 'test-' . bin2hex(random_bytes(4)), 'part' => 1, 'notice_number' => '2026/BZP 00000001/01', 'ocid' => null,
    'title' => 'Remont drogi gminnej (test)', 'order_type' => 'works', 'buyer_name' => 'Gmina Testowa', 'buyer_nip' => '7181968222', 'buyer_city' => 'Testowo',
    'voivodeship' => 'mazowieckie', 'cpv_main' => '45233000', 'cpv_all' => '45233000 45100000', 'result' => 'contract',
    'winner_name' => 'Drogi Testowe sp. z o.o.', 'winner_nip' => '1234563218', 'winner_city' => 'Warszawa', 'winner_voivodeship' => 'mazowieckie',
    'published_at' => gmdate('Y-m-d H:i:s', time() - 3600), 'url' => 'https://ezamowienia.gov.pl/mo-client-board/bzp/notice-details/id/test',
];

return [
    'Parser wyniku: jedna część, ceny ofert, wykonawca i umowa' => function () use ($fx) {
        $r = BzpNoticeParser::results((string) file_get_contents($fx . 'bzp_result_jedna.html'));
        eq('ocds-148610-4365df29-c24f-4eb4-bbf9-d7b0728f48b1', $r['ocid']);
        eq('2026/BZP 00540296', $r['notice']);
        eq('2026/BZP 00408681', $r['base_notice'], 'numer ogłoszenia o zamówieniu');
        eq(1, count($r['parts']));
        $p = $r['parts'][0];
        eq([1, 'contract', 2, 453023.83, 590892.0, 453023.83, 368312.06, 'PLN', '2026-10-09', false],
            [$p['part'], $p['result'], $p['offers'], $p['price_lowest'], $p['price_highest'], $p['price_winner'], $p['contract_value'], $p['currency'], $p['contract_date'], $p['consortium']]);
        eq('5911015227', $p['winners'][0]['nip']);
        eq('60 dni', $p['period']);
    },
    'Parser wyniku: części mają własnych wykonawców, a numer części pochodzi z nagłówka' => function () use ($fx) {
        $two = BzpNoticeParser::results((string) file_get_contents($fx . 'bzp_result_dwie.html'));
        eq([1, 2], array_column($two['parts'], 'part'));
        eq(['Nadwiślańska Agencja Turystyczna Sp. z o.o.', 'Sun & More Sp. z o.o.'], [$two['parts'][0]['winners'][0]['name'], $two['parts'][1]['winners'][0]['name']]);
        eq([7, 5], array_column($two['parts'], 'offers'));
        eq([39000.0, 30000.0], array_column($two['parts'], 'est_value'), 'wartość szacunkowa każdej części');
        eq('zachodniopomorskie', $two['parts'][1]['winners'][0]['voivodeship']);
        // Ogłoszenie tylko o części 4 z czterech: numer 4, nie 1.
        $four = BzpNoticeParser::results((string) file_get_contents($fx . 'bzp_result_czesc4.html'));
        eq([4], array_column($four['parts'], 'part'));
        eq('cancelled', $four['parts'][0]['result']);
        eq('art. 255 pkt 6 ustawy', $four['parts'][0]['cancel_basis']);
        eq([], $four['parts'][0]['winners']);
        // Część nierozstrzygnięta obok dwóch zawartych umów.
        $three = BzpNoticeParser::results((string) file_get_contents($fx . 'bzp_result_trzy.html'));
        eq(['contract', 'contract', 'unresolved'], array_column($three['parts'], 'result'));
        eq(367088.58, $three['parts'][0]['price_winner'], 'wygrała oferta droższa od najtańszej');
        eq(326214.45, $three['parts'][0]['price_lowest']);
    },
    'Parser wyniku: konsorcjum i zamówienie bez wcześniejszego ogłoszenia' => function () use ($fx) {
        $k = BzpNoticeParser::results((string) file_get_contents($fx . 'bzp_result_konsorcjum.html'));
        eq(true, $k['parts'][0]['consortium']);
        eq('Przedsiębiorstwo Wielobranżowe “ZIEJA” Ryszard Zieja', $k['parts'][0]['winners'][0]['name'], 'lider bez listy pozostałych wykonawców');
        contains('Sylwia Chaberek', $k['parts'][0]['partners']);
        eq('2026/BZP 00482200', $k['base_notice'], 'numer z wersją „/01” skrócony do numeru ogłoszenia');
        $w = BzpNoticeParser::results((string) file_get_contents($fx . 'bzp_result_wolna_reka.html'));
        eq(null, $w['base_notice']);
        eq(235430.63, $w['parts'][0]['contract_value']);
        // Treść, która nie jest ogłoszeniem o wyniku, nie daje części.
        eq([], BzpNoticeParser::results((string) file_get_contents($fx . 'bzp_notice_543096.html'))['parts']);
        eq([], BzpNoticeParser::results('<p>Błąd serwera</p>')['parts']);
    },
    'Kwoty z ogłoszeń: przecinek, kropka, spacje i brak groszy' => function () {
        eq([453023.83, 'PLN'], BzpNoticeParser::money('453023,83 PLN'));
        eq([21960.0, 'PLN'], BzpNoticeParser::money('21960 PLN'));
        eq([763787.2, 'PLN'], BzpNoticeParser::money('763787,2 PLN'));
        eq([1392360.0, 'PLN'], BzpNoticeParser::money("1 392 360 PLN"));
        eq([1392360.5, 'PLN'], BzpNoticeParser::money("1\u{00A0}392\u{00A0}360,50 zł"));
        eq([1392360.0, 'EUR'], BzpNoticeParser::money('1.392.360 EUR'));
        eq([847206.81, 'PLN'], BzpNoticeParser::money('847206.81 PLN'));
        eq([], BzpNoticeParser::money('nie dotyczy'));
    },
    'Wiersz wyszukiwarki BZP: pozycja wyniku to numer części, puste pozycje pomijamy' => function () {
        $base = ['objectId' => 'x1', 'noticeNumber' => '2026/BZP 1/01', 'orderObject' => 'Dostawa', 'tenderId' => 'ocds-1', 'publicationDate' => '2026-11-16T10:00:00Z', 'cpvCode' => '30000000-9 (Sprzęt)'];
        $rows = BzpClient::normalizeResult($base + ['procedureResult' => ';;;uniewaznienie', 'contractors' => array_fill(0, 4, ['contractorName' => null])]);
        eq([[4, 'cancelled', null]], array_map(static fn ($r) => [$r['part'], $r['result'], $r['winner_name']], $rows));
        $rows = BzpClient::normalizeResult($base + ['procedureResult' => 'zawarcieUmowy;uniewaznienie;nieRozstrzygnieto', 'contractors' => [
            ['contractorName' => ' Firma A ', 'contractorNationalId' => '123-456-32-18', 'contractorCity' => 'Łódź', 'contractorProvince' => 'PL10'],
            ['contractorName' => null], ['contractorName' => null],
        ]]);
        eq(['contract', 'cancelled', 'unresolved'], array_column($rows, 'result'));
        eq(['Firma A', '1234563218', 'Łódź', 'lodzkie'], [$rows[0]['winner_name'], $rows[0]['winner_nip'], $rows[0]['winner_city'], $rows[0]['winner_voivodeship']]);
        eq('ocds-1', $rows[0]['ocid']);
        // Numer, który nie jest NIP-em (np. REGON), nie trafia do pola NIP.
        $rows = BzpClient::normalizeResult($base + ['procedureResult' => 'zawarcieUmowy', 'contractors' => [['contractorName' => 'Firma B', 'contractorNationalId' => '190597909']]]);
        eq(null, $rows[0]['winner_nip']);
        eq([], BzpClient::normalizeResult($base + ['procedureResult' => null, 'contractors' => null]));
    },
    'Import wyników: wiersze z wyszukiwarki, ceny z treści, bez duplikatów przy powtórce' => function () use ($import) {
        $res = $import();
        eq([10, 6, []], [$res['rows'], $res['details'], $res['errors']]);
        $db = Db::get();
        eq(10, (int) $db->val('SELECT COUNT(*) FROM tender_results'));
        eq(8, (int) $db->val("SELECT COUNT(*) FROM tender_results WHERE result = 'contract'"));
        $k = $db->one("SELECT * FROM tender_results WHERE external_id LIKE '08df43d2-7d1d%'");
        eq([2, 453023.83, 368312.06, '2026/BZP 00408681', 'pomorskie'], [(int) $k['offers'], (float) $k['price_winner'], (float) $k['contract_value'], $k['base_notice'], $k['voivodeship']]);
        eq(1, (int) $db->val("SELECT COUNT(*) FROM tender_results WHERE external_id LIKE '08df43d7-b031%'"), 'ogłoszenie o samej części 4 to jeden wiersz');
        eq(4, (int) $db->val("SELECT part FROM tender_results WHERE external_id LIKE '08df43d7-b031%'"));
        // Powtórny import: te same wiersze, ceny zostają, treści nie pobieramy drugi raz.
        $again = Results::import('2026-11-15 00:00:00');
        eq([10, 0], [$again['rows'], $again['details']]);
        eq(10, (int) $db->val('SELECT COUNT(*) FROM tender_results'));
        eq(2, (int) $db->val("SELECT offers FROM tender_results WHERE external_id LIKE '08df43d2-7d1d%'"), 'liczba ofert przetrwała ponowny import');
        // Import z podaną datą nie przesuwa znacznika codziennego importu, import bez daty go ustawia.
        eq(null, Kv::get('import:bzp:results:until'));
        Results::import();
        ok((string) Kv::get('import:bzp:results:until') > gmdate('Y-m-d H:i:s', time() - 3600), 'znacznik następnego importu');
    },
    'Treść, której nie umiemy odczytać, nie wraca do kolejki i nie kasuje danych z wyszukiwarki' => function () use ($row) {
        Results::saveMany([$row(['external_id' => 'nieznany-uklad'])]);
        Results::applyDetails('nieznany-uklad', BzpNoticeParser::results('<h1>Inny dokument</h1>'));
        $r = Db::get()->one("SELECT * FROM tender_results WHERE external_id = 'nieznany-uklad'");
        ok($r['details_fetched_at'] !== null, 'oznaczone jako sprawdzone');
        eq('Drogi Testowe sp. z o.o.', $r['winner_name']);
        eq(0, Results::fetchDetails(10, 5), 'nic do pobrania');
    },
    'Ekran wyników: zestawienie zwycięzców, filtr profilu, wyszukiwanie po wykonawcy' => function () use ($import) {
        [$org, $uid] = make_org();
        eq(303, http('GET', '/przetargi/wyniki')->status, 'bez logowania przekierowanie');
        login_as($uid);
        contains('Nie ma jeszcze wyników', http('GET', '/przetargi/wyniki')->body);
        $import();
        $all = http('GET', '/przetargi/wyniki', [], ['profil' => '0'])->body;
        contains('Najczęściej wygrywają', $all);
        contains('10 rozstrzygnięć', $all);
        contains('ZIEJA', $all);
        contains('/kontrahenci/7210000613', $all, 'wykonawca prowadzi do karty firmy');
        contains('lider konsorcjum', $all);
        contains('Unieważniono', $all);
        contains('Nie rozstrzygnięto', $all);
        contains('368' . "\u{00A0}" . '312,06', $all, 'wartość umowy');
        // Profil „roboty drogowe w Łódzkiem i na Pomorzu”: zostają dwa ogłoszenia z kodem 45233.
        $pid = Db::get()->insert('tender_profiles', ['org_id' => $org, 'name' => 'Drogi', 'cpv' => '45233000', 'voivodeships' => 'lodzkie pomorskie', 'order_types' => 'works', 'active' => 1, 'created_at' => now_utc()]);
        $mine = http('GET', '/przetargi/wyniki')->body;
        contains('2 rozstrzygnięcia', $mine, 'domyślnie pierwszy profil firmy');
        contains('DB Construct', $mine);
        contains('Chwarszczyński', $mine);
        ok(!str_contains($mine, 'ZIEJA'), 'oczyszczalnia spoza profilu');
        contains('Profil: Drogi', $mine);
        // Sam region bez branży.
        Db::get()->update('tender_profiles', ['cpv' => '', 'voivodeships' => 'slaskie'], 'id = ?', [$pid]);
        contains('Brak rozstrzygnięć przy tych filtrach', http('GET', '/przetargi/wyniki')->body);
        // Szukanie po wykonawcy i po kodzie CPV.
        $q = http('GET', '/przetargi/wyniki', [], ['profil' => '0', 'q' => 'sun more'])->body;
        contains('1 rozstrzygnięcie', $q);
        contains('Sun &amp; More', $q);
        contains('3 rozstrzygnięcia', http('GET', '/przetargi/wyniki', [], ['profil' => '0', 'cpv' => '30'])->body, 'sprzęt komputerowy: trzy części jednego ogłoszenia');
        contains('Brak rozstrzygnięć', http('GET', '/przetargi/wyniki', [], ['profil' => '0', 'woj' => 'opolskie'])->body);
    },
    'Karta przetargu pokazuje wynik postępowania, a przed rozstrzygnięciem historię zamawiającego' => function () use ($row) {
        [$org, $uid] = make_org();
        login_as($uid);
        import_fixtures();
        $db = Db::get();
        $t = $db->one("SELECT * FROM tenders WHERE source = 'bzp' AND ocid IS NOT NULL ORDER BY id LIMIT 1");
        ok($t !== null && str_starts_with((string) $t['ocid'], 'ocds-'), 'import zapisuje identyfikator postępowania');
        // Zamawiający ma w bazie wcześniejsze rozstrzygnięcie: karta pokazuje, kto u niego wygrał.
        Results::saveMany([$row(['buyer_nip' => $t['buyer_nip'], 'title' => 'Wcześniejszy remont (test)', 'winner_name' => 'Poprzedni Zwycięzca S.A.'])]);
        $before = http('GET', '/przetargi/' . $t['id'])->body;
        contains('Kto wygrywał u tego zamawiającego', $before);
        contains('Poprzedni Zwycięzca S.A.', $before);
        // Pojawia się wynik tego postępowania: dwie części, jedna unieważniona.
        Results::saveMany([
            $row(['external_id' => 'wynik-tego', 'ocid' => $t['ocid'], 'part' => 1, 'buyer_nip' => $t['buyer_nip'], 'winner_name' => 'Nowy Wykonawca sp. z o.o.']),
            $row(['external_id' => 'wynik-tego', 'ocid' => $t['ocid'], 'part' => 2, 'buyer_nip' => $t['buyer_nip'], 'result' => 'cancelled', 'winner_name' => null, 'winner_nip' => null]),
        ]);
        $db->run("UPDATE tender_results SET offers = 4, price_lowest = 900000, price_highest = 1500000, price_winner = 1000000, contract_value = 1000000, details_fetched_at = ? WHERE external_id = 'wynik-tego' AND part = 1", [now_utc()]);
        $db->run("UPDATE tender_results SET details_fetched_at = ? WHERE external_id = 'wynik-tego' AND part = 2", [now_utc()]);
        $after = http('GET', '/przetargi/' . $t['id'])->body;
        contains('Wynik postępowania', $after);
        contains('Nowy Wykonawca sp. z o.o.', $after);
        contains('Część 2', $after);
        contains('900 tys. zł - 1,5 mln zł', $after, 'rozrzut cen ofert');
        ok(!str_contains($after, 'Kto wygrywał u tego zamawiającego'), 'historia ustępuje wynikowi');
        // Lista wyników prowadzi do karty przetargu, bo ogłoszenie o zamówieniu jest w bazie.
        contains('href="/przetargi/' . $t['id'] . '"', http('GET', '/przetargi/wyniki', [], ['profil' => '0', 'q' => 'nowy wykonawca'])->body);
    },
    'Zapisany przetarg po rozstrzygnięciu: jeden alert dla firmy, która go zapisała, i wpis w porannym przeglądzie' => function () use ($row) {
        [$org, $uid] = make_org();
        [$other] = make_org('inna@example.com', 'Inna Firma sp. z o.o.');
        import_fixtures();
        $db = Db::get();
        $t = $db->one("SELECT * FROM tenders WHERE source = 'bzp' AND ocid IS NOT NULL ORDER BY id LIMIT 1");
        $mk = static fn (int $o, string $status) => $db->insert('tender_matches', [
            'org_id' => $o, 'profile_id' => $db->insert('tender_profiles', ['org_id' => $o, 'name' => 'P', 'cpv' => '45', 'active' => 1, 'created_at' => now_utc()]),
            'tender_id' => (int) $t['id'], 'score' => 80, 'status' => $status, 'created_at' => now_utc(),
        ]);
        $mk($org, 'saved');
        $mk($other, 'new');
        eq(0, Results::alertSaved(), 'bez wyniku nie ma alertu');
        Results::saveMany([$row(['external_id' => 'wynik-zapisanego', 'ocid' => $t['ocid'], 'winner_name' => 'Zwycięzca <b>Drogowy</b>'])]);
        $db->run("UPDATE tender_results SET offers = 3, contract_value = 2500000 WHERE external_id = 'wynik-zapisanego'");
        eq(1, Results::alertSaved());
        eq(0, Results::alertSaved(), 'drugi raz bez powtórki');
        $a = $db->one('SELECT * FROM alerts WHERE org_id = ?', [$org]);
        contains('Rozstrzygnięto przetarg', $a['title']);
        contains('Wygrał: Zwycięzca <b>Drogowy</b>, 2' . "\u{00A0}" . '500' . "\u{00A0}" . '000,00' . "\u{00A0}" . 'zł, 3 oferty.', $a['detail']);
        eq(0, (int) $db->val('SELECT COUNT(*) FROM alerts WHERE org_id = ?', [$other]), 'firma, która nie zapisała przetargu, nie dostaje alertu');
        login_as($uid);
        $dash = http('GET', '/pulpit')->body;
        contains('href="/przetargi/' . $t['id'] . '#wynik"', $dash);
        contains('Zwycięzca &lt;b&gt;Drogowy&lt;/b&gt;', $dash, 'nazwa wykonawcy jako tekst');
        $mail = Digest::compose((array) $db->one('SELECT * FROM organizations WHERE id = ?', [$org]), gmdate('Y-m-d H:i:s', time() - 3600), 'https://panel.example');
        contains('ROZSTRZYGNIĘTE PRZETARGI Z TWOJEJ LISTY (1)', $mail['body']);
        contains('https://panel.example/przetargi/' . $t['id'], $mail['body']);
        ok(!str_contains($mail['body'], 'ZMIANY U OBSERWOWANYCH KONTRAHENTÓW'), 'alert o przetargu nie jest alertem o kontrahencie');
    },
    'Karta firmy: wygrane przetargi wykonawcy i rozstrzygnięcia zamawiającego' => function () use ($row) {
        [$org, $uid] = make_org();
        login_as($uid);
        Results::saveMany([
            $row(['external_id' => 'w1', 'title' => 'Budowa chodnika <script>alert(1)</script>']),
            $row(['external_id' => 'w2', 'title' => 'Remont mostu (test)']),
            $row(['external_id' => 'z1', 'buyer_nip' => '1234563218', 'winner_nip' => '7181968222', 'winner_name' => 'Wykonawca u Testowej', 'title' => 'Zakup usług (test)']),
        ]);
        Db::get()->run("UPDATE tender_results SET contract_value = 600000 WHERE external_id IN ('w1', 'w2')");
        $wins = Results::wins('1234563218');
        eq([2, 1200000.0], [$wins['count'], $wins['total']]);
        $body = http('GET', '/kontrahenci/1234563218')->body;
        contains('Wygrane przetargi', $body);
        contains('2 umowy na 1,2 mln zł', $body);
        contains('Budowa chodnika &lt;script&gt;alert(1)&lt;/script&gt;', $body);
        ok(!str_contains($body, '<script>alert(1)</script>'), 'tytuł z ogłoszenia jako tekst');
        contains('Kto wygrywa u tej firmy', $body);
        contains('Wykonawca u Testowej', $body);
        // Ekran wyników też pokazuje tytuł jako tekst.
        $list = http('GET', '/przetargi/wyniki', [], ['profil' => '0'])->body;
        ok(!str_contains($list, '<script>alert(1)</script>') && str_contains($list, '&lt;script&gt;'), 'ekran wyników');
    },
    'Kod CPV działu z zerem (30, 80, 90) działa w profilu, w wyszukiwarce i w wynikach' => function () use ($import) {
        eq(['30', '45233', '80', '', ''], array_map([App\Modules\Tenders\Matcher::class, 'cpvPrefix'], ['30000000-9', '45233000', '80', '9', 'x']));
        $tender = ['source' => 'bzp', 'title' => 'Dostawa komputerów', 'voivodeship' => 'mazowieckie', 'order_type' => 'supplies', 'cpv_main' => '30213000', 'cpv_all' => '30213000 48000000', 'value_estimated' => null, 'deadline_at' => null, 'details_json' => null];
        $profile = ['cpv' => '30000000', 'keywords' => '', 'exclude_keywords' => '', 'voivodeships' => '', 'order_types' => '', 'min_value' => null, 'max_value' => null, 'sources' => 'bzp ted'];
        $m = App\Modules\Tenders\Matcher::score($tender, $profile);
        ok($m !== null && $m['score'] >= 50, 'profil z kodem działu 30 dopasowuje sprzęt komputerowy');
        eq(null, App\Modules\Tenders\Matcher::score(['cpv_main' => '45233000', 'cpv_all' => '45233000', 'title' => 'Remont drogi'] + $tender, $profile), 'inny dział nie pasuje');
        [$org, $uid] = make_org();
        login_as($uid);
        $import();
        Tenders::save(['source' => 'bzp', 'external_id' => 'komputery', 'title' => 'Dostawa komputerów (test)', 'cpv_main' => '30213000', 'cpv_all' => '30213000', 'published_at' => now_utc(), 'deadline_at' => gmdate('Y-m-d H:i:s', time() + 86400)]);
        Tenders::save(['source' => 'bzp', 'external_id' => 'drogi', 'title' => 'Remont drogi 30 km (test)', 'cpv_main' => '45233000', 'cpv_all' => '45233000', 'published_at' => now_utc(), 'deadline_at' => gmdate('Y-m-d H:i:s', time() + 86400)]);
        $found = http('GET', '/przetargi/szukaj', [], ['cpv' => '30'])->body;
        contains('Dostawa komputerów (test)', $found);
        ok(!str_contains($found, 'Remont drogi 30 km'), 'kod 30 to dział, a nie dowolne wystąpienie cyfr');
        contains('3 rozstrzygnięcia', http('GET', '/przetargi/wyniki', [], ['profil' => '0', 'cpv' => '30000000'])->body);
    },
    'Zadanie dzienne pobiera wyniki, a starsze przetargi dostają identyfikator postępowania' => function () {
        BzpClient::$pauseUs = 0;
        $db = Db::get();
        $id = Tenders::save(['source' => 'bzp', 'external_id' => 'stary', 'title' => 'Stary przetarg', 'published_at' => now_utc(), 'raw_json' => json_out(['tenderId' => 'ocds-stary-1'])]);
        $out = CronController::daily();
        eq(10, $out['results']['rows'] ?? null, 'wyniki w zadaniu dziennym');
        eq([], $out['results']['errors']);
        eq('ocds-stary-1', $db->val('SELECT ocid FROM tenders WHERE id = ?', [$id]));
        eq(0, Results::backfillOcid(), 'uzupełnianie nie wraca do tych samych wierszy');
        BzpClient::$pauseUs = 150000;
    },
];
