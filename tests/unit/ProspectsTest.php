<?php

use App\Core\Db;
use App\Http\CronController;
use App\Modules\Outreach\Prospects;
use App\Modules\Outreach\RiskPolicy;

/** Wiersz wyniku przetargu: wykonawca, który wygrał zamówienie. */
$win = static fn (array $over = []): array => $over + [
    'source' => 'bzp', 'external_id' => 'test-' . bin2hex(random_bytes(4)), 'part' => 1, 'notice_number' => '2026/BZP 00000001/01', 'ocid' => null,
    'title' => 'Remont drogi gminnej w Testowie', 'search' => search_text('Remont drogi gminnej w Testowie Gmina Testowa'), 'order_type' => 'works',
    'buyer_name' => 'Gmina Testowa', 'buyer_nip' => '7181968222', 'buyer_city' => 'Testowo', 'voivodeship' => 'mazowieckie', 'cpv_main' => '45233000', 'cpv_all' => '45233000 45100000',
    'result' => 'contract', 'winner_name' => 'Drogi Testowe sp. z o.o.', 'winner_nip' => '1234563218', 'winner_city' => 'Warszawa', 'winner_voivodeship' => 'mazowieckie',
    'contract_value' => 1200000, 'published_at' => gmdate('Y-m-d H:i:s', time() - 86400), 'url' => 'https://ezamowienia.gov.pl/test', 'created_at' => now_utc(), 'updated_at' => now_utc(),
];
/** Firma testowa z własnym NIP-em innym niż NIP wykonawcy z wyników. */
$org = static function (string $email = 'wlasciciel@example.com', string $name = 'Firma Testowa sp. z o.o.'): array {
    [$org, $uid] = make_org($email, $name);
    Db::get()->update('organizations', ['nip' => '5260000005'], 'id = ?', [$org]);
    login_as($uid);
    return [$org, $uid];
};
$rows = static fn (array $p): array => array_column(json_in($p['score_json']), 'points', 'label');

return [
    'Potencjalni klienci z wyników przetargów: kandydaci, dane z rejestrów, punkty i powód kontaktu' => function () use ($win, $org, $rows) {
        [$orgId, $uid] = $org();
        $db = Db::get();
        $db->insert('tender_results', $win());
        $db->insert('tender_results', $win(['title' => 'Przebudowa chodnika', 'search' => search_text('Przebudowa chodnika'), 'contract_value' => 300000, 'published_at' => gmdate('Y-m-d H:i:s', time() - 40 * 86400)]));
        $db->insert('tender_results', $win(['winner_name' => 'Powiat Przykładowy', 'winner_nip' => '7181968222', 'winner_city' => 'Łomża', 'winner_voivodeship' => 'podlaskie', 'title' => 'Remont mostu']
            + ['search' => search_text('Remont mostu')]));
        $db->insert('tender_results', $win(['winner_name' => 'Bez NIP-u sp. z o.o.', 'winner_nip' => null]));
        $db->insert('tender_results', $win(['winner_name' => 'Nasza firma', 'winner_nip' => '5260000005']));
        $db->insert('tender_results', $win(['winner_name' => 'Unieważnione', 'winner_nip' => '7790000008', 'result' => 'cancelled']));

        // Pusta lista prowadzi do źródeł. Wyszukiwanie pokazuje wykonawców z NIP-em, bez własnej firmy i bez unieważnionych.
        $page = http('GET', '/klienci/potencjalni')->body;
        contains('Lista jest pusta', $page);
        contains('Zgody wymaga wysyłka e-mailem', $page);
        contains('href="/klienci/potencjalni">Potencjalni klienci</a>', http('GET', '/klienci')->body);
        $found = http('GET', '/klienci/potencjalni', [], ['szukaj' => '1', 'q' => 'remont'])->body;
        contains('<strong>2 firmy</strong>', $found);
        contains('Drogi Testowe sp. z o.o.', $found);
        contains('Powiat Przykładowy', $found);
        ok(!str_contains($found, 'Bez NIP-u') && !str_contains($found, 'Nasza firma') && !str_contains($found, 'Unieważnione'), 'tylko wykonawcy z NIP-em i zawartą umową');
        eq(['1234563218', '7181968222'], array_column(Prospects::candidates($orgId, []), 'winner_nip'), 'najczęściej wygrywający na górze');
        eq(['7181968222'], array_column(Prospects::candidates($orgId, ['woj' => 'podlaskie']), 'winner_nip'));
        contains('nie ma nowych firm', http('GET', '/klienci/potencjalni', [], ['szukaj' => '1', 'q' => 'kanalizacja'])->body);

        // Dodanie firmy: fakty o wygranych od razu, dane z rejestrów w kolejce.
        eq(303, http('POST', '/klienci/potencjalni/z-przetargow', ['nips' => ['123-456-32-18', '0000000000', '9510002221']])->status);
        eq(1, (int) $db->val('SELECT COUNT(*) FROM prospects'), 'tylko firmy z wyników, z poprawnym NIP-em');
        $p = $db->one('SELECT * FROM prospects');
        eq(['results', 'new', 'Drogi Testowe sp. z o.o.', 'mazowieckie', null], [$p['source'], $p['status'], $p['name'], $p['voivodeship'], $p['enriched_at']]);
        eq([2, 1500000.0, 'Remont drogi gminnej w Testowie'], [Prospects::facts($p)['wins']['count'], (float) Prospects::facts($p)['wins']['total'], Prospects::facts($p)['wins']['rows'][0]['title']]);
        eq(['7181968222'], array_column(Prospects::candidates($orgId, []), 'winner_nip'), 'firma z listy nie wraca w wyszukiwaniu');
        eq(303, http('POST', '/klienci/potencjalni/z-przetargow', ['nips' => ['1234563218']])->status);
        eq(1, (int) $db->val('SELECT COUNT(*) FROM prospects'), 'bez powtórek');
        contains('data-work-queue="/klienci/potencjalni/krok"', http('GET', '/klienci/potencjalni')->body);

        // Krok kolejki dopisuje dane z wykazu VAT i KRS.
        $step = json_decode(http('POST', '/klienci/potencjalni/krok')->body, true);
        eq(['done' => 1, 'left' => 0], $step);
        $p = Prospects::find($orgId, (int) $p['id']);
        ok($p['company_id'] !== null && $p['enriched_at'] !== null, 'karta firmy podpięta');
        eq(['PRZYKŁADOWA SPÓŁKA BUDOWLANA SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', 'Warszawa'], [$p['name'], $p['city']]);
        ok(!str_contains(http('GET', '/klienci/potencjalni')->body, 'data-work-queue'), 'kolejka pusta');

        // Punkty z jawnej tabeli: bez słów branżowych branża ma zero, po ich podaniu punkty się przeliczają.
        eq([0, 15, 10, 20], [$rows($p)['Branża'], $rows($p)['Region'], $rows($p)['Staż firmy'], $rows($p)['Powód kontaktu']]);
        eq(303, http('POST', '/klienci/potencjalni/kryteria', ['keywords' => "transport\nbudow\nab", 'regions' => ['mazowieckie', 'nieznane'], 'min_years' => '5'])->status);
        eq(['keywords' => "transport\nbudow", 'regions' => ['mazowieckie'], 'min_years' => 5], Prospects::criteria($db->one('SELECT * FROM organizations WHERE id = ?', [$orgId])));
        $p = Prospects::find($orgId, (int) $p['id']);
        eq([40, 15, 10, 20], [$rows($p)['Branża'], $rows($p)['Region'], $rows($p)['Staż firmy'], $rows($p)['Powód kontaktu']]);
        eq((int) $p['score'], array_sum($rows($p)));
        http('POST', '/klienci/potencjalni/kryteria', ['keywords' => 'budow', 'regions' => ['pomorskie'], 'min_years' => '20']);
        $p = Prospects::find($orgId, (int) $p['id']);
        eq([40, 0, 0], [$rows($p)['Branża'], $rows($p)['Region'], $rows($p)['Staż firmy']], 'region spoza listy i za krótki staż');

        // Karta: powód kontaktu z faktów, rozpisane punkty i drogi kontaktu od najmniej ryzykownej.
        $card = http('GET', '/klienci/potencjalni/' . $p['id'])->body;
        contains('W wynikach przetargów ma 2 wygrane zamówienia na 1 500 000', str_replace("\u{00A0}", ' ', $card));
        contains('Ostatnie: „Remont drogi gminnej w Testowie” dla Gmina Testowa', $card);
        contains('Spółka z ograniczoną odpowiedzialnością, działa od 2016, główna działalność: roboty związane z budową dróg i autostrad.', $card);
        contains('słowo „budow” w nazwie, działalności albo wygranych zamówieniach', $card);
        contains('Mazowieckie: poza Twoją listą', $card);
        contains('Wyślij list pocztą', $card);
        contains('Wyślij ofertę e-mailem bez zgody', $card);
        ok(strpos($card, 'Wyślij list pocztą') < strpos($card, 'Wyślij ofertę e-mailem bez zgody'), 'najpierw droga o niskim ryzyku');
        contains('bardzo wysokie ryzyko', $card);
        contains('Art. 398 Prawa komunikacji elektronicznej', $card);
        contains('Panel nie zbiera adresów e-mail ze stron', $card);
        contains('href="/kontrahenci/1234563218"', $card);

        // Stany z listy: wybrane, pominięte, przywrócone.
        eq(303, http('POST', '/klienci/potencjalni/' . $p['id'] . '/stan', ['status' => 'picked', 'back' => 'list'])->status);
        contains('Wybrane (1)', http('GET', '/klienci/potencjalni', [], ['stan' => 'picked'])->body);
        http('POST', '/klienci/potencjalni/' . $p['id'] . '/stan', ['status' => 'cokolwiek']);
        eq('picked', $db->val('SELECT status FROM prospects'));
        http('POST', '/klienci/potencjalni/' . $p['id'] . '/stan', ['status' => 'skipped']);
        eq(['7181968222'], array_column(Prospects::candidates($orgId, []), 'winner_nip'), 'pominięta firma nie wraca w wyszukiwaniu');
        contains('Pominięte (1)', http('GET', '/klienci/potencjalni', [], ['stan' => 'skipped'])->body);

        // Druga firma: nie widzi listy, karty ani kolejki pierwszej firmy i niczego w niej nie zmieni.
        [$org2] = $org('obca@example.com', 'Obca sp. z o.o.');
        ok(!str_contains(http('GET', '/klienci/potencjalni', [], ['stan' => 'skipped'])->body, 'PRZYKŁADOWA SPÓŁKA'), 'cudza lista niewidoczna');
        foreach ([['GET', ''], ['POST', ''], ['POST', '/stan'], ['POST', '/strona'], ['POST', '/zaproszenie'], ['POST', '/kontakt'], ['GET', '/list'], ['POST', '/list']] as [$m, $suffix]) {
            eq(404, http($m, '/klienci/potencjalni/' . $p['id'] . $suffix, $m === 'POST' ? ['status' => 'picked', 'delete' => '1', 'email' => 'x@example.com', 'body' => 'x'] : [])->status, $m . ' ' . $suffix);
        }
        eq('skipped', $db->val('SELECT status FROM prospects WHERE id = ?', [(int) $p['id']]));
        eq(['1234563218', '7181968222'], array_column(Prospects::candidates($org2, []), 'winner_nip'), 'lista jednej firmy nie ukrywa wykonawców przed drugą');
        eq(Prospects::criteria(null)['min_years'], Prospects::criteria($db->one('SELECT * FROM organizations WHERE id = ?', [$org2]))['min_years'], 'kryteria są osobne dla każdej firmy');
    },
    'Lista wklejona przez użytkownika: NIP-y i strony, powtórki, błędne wiersze, limit, kolejka w zadaniu dziennym' => function () use ($org) {
        [$orgId, $uid] = $org();
        $db = Db::get();
        // Rozpoznawanie wiersza: NIP z kreskami i z przedrostkiem, adres strony, nazwa. E-mail i numer telefonu nie są firmą.
        eq(['nip' => '1234563218', 'website' => null, 'host' => null, 'name' => 'Przykładowa Budowlana'], Prospects::parseLine('123-456-32-18 Przykładowa Budowlana'));
        eq(['nip' => '7181968222', 'website' => 'https://powiat.example', 'host' => 'powiat.example', 'name' => 'powiat.example'], Prospects::parseLine('PL7181968222; powiat.example'));
        eq(['nip' => null, 'website' => 'https://www.firma-uslugowa.example/oferta', 'host' => 'firma-uslugowa.example', 'name' => 'firma-uslugowa.example'], Prospects::parseLine('https://www.firma-uslugowa.example/oferta'));
        eq('Hurtownia Żwir', Prospects::parseLine('Hurtownia Żwir, hurtownia-zwir.example')['name']);
        eq(null, Prospects::parseLine('jan@firma.example'));
        eq(null, Prospects::parseLine('tel. 600 100 200'));
        eq(null, Prospects::parseLine('1234567890 błędna suma kontrolna'));

        $r = http('POST', '/klienci/potencjalni/lista', ['lines' => "123-456-32-18 Przykładowa\n<b>Firma</b> zly-przyklad.example\nfirma-zaprasza.example\n\nkontakt@firma.example\ncoś bez danych\nFIRMA-ZAPRASZA.example/kontakt\n1234563218"]);
        eq(303, $r->status);
        eq(3, (int) $db->val('SELECT COUNT(*) FROM prospects'));
        $list = http('GET', '/klienci/potencjalni')->body;
        contains('Dodano 3 firmy. 2 firmy były już na liście. Nie rozpoznaliśmy NIP-u ani adresu strony w 2 wierszach: kontakt@firma.example; coś bez danych.', $list);
        contains('&lt;b&gt;Firma&lt;/b&gt;', $list);
        ok(!str_contains($list, '<b>Firma</b>'), 'nazwa z wklejonej listy jako tekst');
        // Firma bez NIP-u nie czeka na rejestry. Firma z NIP-em czeka.
        eq(1, Prospects::pendingCount($orgId));
        ok($db->val("SELECT enriched_at FROM prospects WHERE host = 'firma-zaprasza.example'") !== null, 'sama strona: nie ma czego pobierać z rejestrów');
        // Pusty formularz i limit jednego wklejenia.
        http('POST', '/klienci/potencjalni/lista', ['lines' => "  \n"]);
        contains('Wklej listę: w każdym wierszu NIP albo adres strony firmy.', http('GET', '/klienci/potencjalni')->body);
        $many = implode("\n", array_map(static fn (int $i) => 'firma-' . $i . '.example', range(1, 55)));
        http('POST', '/klienci/potencjalni/lista', ['lines' => $many]);
        eq(53, (int) $db->val('SELECT COUNT(*) FROM prospects'));
        contains('Jedno wklejenie to najwyżej 50 wierszy. Pozostałe 5 wklej osobno.', http('GET', '/klienci/potencjalni')->body);

        // Zadanie dzienne dopisuje dane z rejestrów bez udziału użytkownika. Firma, której nie ma w wykazie VAT, dostaje jasny komunikat.
        http('POST', '/klienci/potencjalni/lista', ['lines' => '7790000008 Firma spoza wykazu']);
        eq(2, Prospects::pendingCount($orgId));
        $out = CronController::daily();
        eq(0, $out['prospects']['left']);
        eq(0, Prospects::pendingCount($orgId));
        $known = Prospects::byNip($orgId, '1234563218');
        eq('PRZYKŁADOWA SPÓŁKA BUDOWLANA SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', $known['name']);
        $unknown = Prospects::byNip($orgId, '7790000008');
        eq([null, 'Firma spoza wykazu'], [$unknown['company_id'], $unknown['name']]);
        contains('Nie mamy danych z rejestrów: Tej firmy nie ma w wykazie podatników VAT', http('GET', '/klienci/potencjalni/' . $unknown['id'])->body);
        contains('Żeby przygotować list, potrzebny jest adres firmy z rejestrów', http('GET', '/klienci/potencjalni/' . $unknown['id'])->body);

        // Dopisanie NIP-u i strony na karcie. Strona z KRS trafia na kartę, gdy użytkownik jej nie podał.
        $site = Prospects::byHost($orgId, 'firma-zaprasza.example');
        eq(303, http('POST', '/klienci/potencjalni/' . $site['id'], ['nip' => '1234563218', 'website' => '', 'notes' => 'z targów'])->status);
        contains('Firma z tym NIP-em jest już na liście.', http('GET', '/klienci/potencjalni/' . $site['id'])->body);
        http('POST', '/klienci/potencjalni/' . $site['id'], ['nip' => '12345', 'website' => '']);
        contains('NIP ma błąd', http('GET', '/klienci/potencjalni/' . $site['id'])->body);
        http('POST', '/klienci/potencjalni/' . $site['id'], ['nip' => '', 'website' => 'to nie jest adres']);
        contains('Adres strony ma błąd', http('GET', '/klienci/potencjalni/' . $site['id'])->body);
        $db->update('companies', ['krs_json' => json_out(['website' => 'WWW.FIRMA-ZAPRASZA.EXAMPLE'] + json_in($db->val("SELECT krs_json FROM companies WHERE nip = '1234563218'")))], "nip = '1234563218'");
        $db->update('prospects', ['enriched_at' => null], 'id = ?', [(int) $known['id']]);
        Prospects::delete($orgId, (int) $site['id']);
        Prospects::enrichPending($orgId);
        $known = Prospects::find($orgId, (int) $known['id']);
        eq(['https://WWW.FIRMA-ZAPRASZA.EXAMPLE', 'firma-zaprasza.example', 'KRS'], [$known['website'], $known['host'], Prospects::facts($known)['website_from']]);
    },
    'Strona firmy i zaproszenie do ofert: cytat ze strony, potwierdzenie, podstawa kontaktu i ryzyko wiadomości' => function () use ($org) {
        [$orgId, $uid] = $org();
        $db = Db::get();
        // Zaproszeniem jest zdanie o ofertach z adresem e-mail. Samo „zapraszamy do kontaktu” nim nie jest.
        eq(null, Prospects::inviteIn('<p>Zapraszamy do kontaktu: biuro@firma.example</p>', 'https://firma.example/'));
        eq(null, Prospects::inviteIn('<p>Oferty handlowe prosimy kierować do działu zakupów.</p><p>Biuro: biuro@firma.example</p>', 'https://firma.example/'), 'adres w innym akapicie');
        eq(null, Prospects::inviteIn('<script>var t = "oferty handlowe prosimy kierować na adres a@b.example";</script>', 'https://firma.example/'));
        eq('zakupy@firma.example', Prospects::inviteIn('<div>Propozycje współpracy: <a href="mailto:zakupy@firma.example">zakupy@firma.example</a></div>', 'https://firma.example/')['email']);
        eq('dostawcy@firma.example', Prospects::inviteIn('<li>Dla dostawców: dostawcy@firma.example</li>', 'https://firma.example/')['email']);

        http('POST', '/klienci/potencjalni/lista', ['lines' => 'firma-zaprasza.example Hurtownia Budowlana']);
        $id = (int) $db->val('SELECT id FROM prospects');
        $url = '/klienci/potencjalni/' . $id;
        contains('Panel otworzy stronę główną i podstrony o kontakcie albo współpracy', http('GET', $url)->body);
        // Sprawdzenie strony: braki widoczne na stronie głównej i zdanie z zaproszeniem znalezione na podstronie.
        eq(303, http('POST', $url . '/strona', ['website' => 'firma-zaprasza.example'])->status);
        $p = Prospects::find($orgId, $id);
        $f = Prospects::facts($p);
        eq(true, $f['site']['ok']);
        eq(['Brak opisu strony dla wyszukiwarek (meta description).', 'Brak danych strukturalnych (schema.org), z których wyszukiwarki i asystenci AI biorą dane o firmie.'], array_slice($f['site']['findings'], 0, 2));
        eq(3, count($f['site']['findings']), 'tytuł, H1, viewport i https są w porządku');
        eq(['https://firma-zaprasza.example/', 'https://firma-zaprasza.example/kontakt', 'https://firma-zaprasza.example/dla-dostawcow'], $f['site']['pages'], 'tylko ta sama witryna, podstrony o kontakcie i dostawcach');
        eq(['Oferty handlowe prosimy kierować na adres: Oferty@Firma-Zaprasza.example. Odpowiadamy na wybrane propozycje.', 'oferty@firma-zaprasza.example', 'https://firma-zaprasza.example/dla-dostawcow'],
            [$f['invite_found']['quote'], $f['invite_found']['email'], $f['invite_found']['url']]);
        ok(empty($f['invite']), 'znalezione zdanie nie jest podstawą, dopóki użytkownik go nie potwierdzi');
        eq(20, array_column(json_in($p['score_json']), 'points', 'label')['Powód kontaktu'], 'trzy rzeczy do poprawy na stronie to powód kontaktu');
        $card = http('GET', $url)->body;
        contains('Na stronie firmy jest zaproszenie do ofert: potwierdź je', $card);
        contains('„Oferty handlowe prosimy kierować na adres: Oferty@Firma-Zaprasza.example.', $card);
        contains('Na stronie firmy: brak opisu strony dla wyszukiwarek', $card);
        contains('Wyślij ofertę e-mailem bez zgody', $card, 'bez potwierdzenia e-mail nadal jest wysyłką bez zgody');
        contains('zaproszenie do potwierdzenia', http('GET', '/klienci/potencjalni')->body);

        // Potwierdzenie wymaga zaznaczenia. Po nim zaproszenie jest podstawą kontaktu, a droga „e-mail bez zgody” znika.
        http('POST', $url . '/zaproszenie', []);
        ok(empty(Prospects::facts(Prospects::find($orgId, $id))['invite']), 'bez zaznaczenia nic się nie zapisuje');
        eq(303, http('POST', $url . '/zaproszenie', ['confirm' => '1'])->status);
        $inv = Prospects::facts(Prospects::find($orgId, $id))['invite'];
        eq(['oferty@firma-zaprasza.example', $uid, false], [$inv['email'], $inv['confirmed_by'], $inv['manual']]);
        $card = http('GET', $url)->body;
        contains('Wyślij ofertę na adres, który firma podała do ofert', $card);
        contains('Art. 398 ust. 2 Prawa komunikacji elektronicznej', $card);
        ok(!str_contains($card, 'Wyślij ofertę e-mailem bez zgody'), 'jest zgoda przez udostępnienie adresu');
        contains('zaprasza do ofert', http('GET', '/klienci/potencjalni')->body);

        // Oferta na adres z zaproszenia: kontakt z dowodem i niskie ryzyko na ekranie wiadomości.
        $r = http('POST', $url . '/kontakt', ['email' => 'Oferty@firma-zaprasza.example']);
        $c = $db->one('SELECT * FROM contacts');
        eq('/klienci/' . $c['id'] . '/wiadomosc?kind=consented', $r->headers['Location']);
        eq(['invite', 'https://firma-zaprasza.example/dla-dostawcow', 'https://firma-zaprasza.example', 'Hurtownia Budowlana'], [$c['basis'], $c['basis_url'], $c['website'], $c['company_name']]);
        contains('Oferty handlowe prosimy kierować na adres', (string) $c['basis_detail']);
        contains('Powód kontaktu: Na stronie firmy: brak opisu strony', (string) $c['notes']);
        $compose = http('GET', '/klienci/' . $c['id'] . '/wiadomosc', [], ['kind' => 'consented'])->body;
        contains('Firma sama opublikowała adres do przesyłania ofert', $compose);
        contains('Zgoda obejmuje oferty, o jakie firma prosi', $compose);
        eq('low', RiskPolicy::assess('consented', $c)['level']);
        // Inny adres tej samej firmy nie korzysta z zaproszenia: to oferta bez zgody z ekranem ryzyka i potwierdzeniem.
        $r = http('POST', $url . '/kontakt', ['email' => 'prezes@firma-zaprasza.example', 'person' => 'Jan Prezes']);
        $c2 = $db->one("SELECT * FROM contacts WHERE email = 'prezes@firma-zaprasza.example'");
        eq(['none', '/klienci/' . $c2['id'] . '/wiadomosc?kind=cold_offer'], [$c2['basis'], $r->headers['Location']]);
        $compose = http('GET', '/klienci/' . $c2['id'] . '/wiadomosc', [], ['kind' => 'cold_offer'])->body;
        contains('bardzo wysokie', $compose);
        contains('Zaznacz oświadczenie o zapoznaniu się z ryzykiem', http('POST', '/klienci/' . $c2['id'] . '/wiadomosc', ['kind' => 'cold_offer', 'action' => 'save', 'subject' => 'Oferta', 'body' => 'Treść'])->body);
        eq(0, (int) $db->val('SELECT COUNT(*) FROM messages'), 'bez potwierdzenia ryzyka wiadomość się nie zapisuje');
        http('POST', $url . '/kontakt', ['email' => 'to nie adres']);
        contains('Podaj poprawny adres e-mail firmy.', http('GET', $url)->body);

        // Wysłana wiadomość przenosi firmę do „po kontakcie”.
        eq(303, http('POST', '/klienci/' . $c2['id'] . '/wiadomosc', ['kind' => 'cold_offer', 'action' => 'save', 'subject' => 'Oferta', 'body' => 'Treść', 'ack' => '1'])->status);
        eq('new', $db->val('SELECT status FROM prospects'), 'sam szkic nie zmienia stanu');
        http('POST', '/wiadomosci/' . (int) $db->val('SELECT id FROM messages') . '/wyslana');
        eq(['contacted', 'email'], array_values((array) $db->one('SELECT status, contacted_how FROM prospects')));
        contains('Wiadomość wysłana', http('GET', '/klienci/potencjalni', [], ['stan' => 'contacted'])->body);

        // Zaproszenie można odrzucić i wpisać ręcznie: cytat, link i adres są wymagane.
        eq(303, http('POST', $url . '/zaproszenie', ['drop' => '1'])->status);
        ok(empty(Prospects::facts(Prospects::find($orgId, $id))['invite']), 'zaproszenie usunięte');
        foreach ([[['quote' => 'krótko', 'url' => 'https://x.example/', 'email' => 'a@x.example'], 'Wklej zdanie ze strony firmy'],
            [['quote' => 'Oferty handlowe prosimy przesyłać e-mailem.', 'url' => 'javascript:alert(1)', 'email' => 'a@x.example'], 'Podaj link do strony'],
            [['quote' => 'Oferty handlowe prosimy przesyłać e-mailem.', 'url' => 'https://x.example/', 'email' => 'brak'], 'Podaj adres e-mail z zaproszenia']] as [$in, $msg]) {
            http('POST', $url . '/zaproszenie', $in + ['manual' => '1', 'confirm' => '1']);
            contains($msg, http('GET', $url)->body);
        }
        ok(empty(Prospects::facts(Prospects::find($orgId, $id))['invite']), 'błędne wpisy nie tworzą podstawy');
        http('POST', $url . '/zaproszenie', ['manual' => '1', 'confirm' => '1', 'quote' => 'Oferty <b>handlowe</b> prosimy przesyłać na zakupy@x.example', 'url' => 'https://x.example/wspolpraca', 'email' => 'Zakupy@x.example']);
        $inv = Prospects::facts(Prospects::find($orgId, $id))['invite'];
        eq(['zakupy@x.example', true], [$inv['email'], $inv['manual']]);
        $card = http('GET', $url)->body;
        contains('Oferty &lt;b&gt;handlowe&lt;/b&gt; prosimy', $card);
        ok(!str_contains($card, '<b>handlowe</b>'), 'cytat jako tekst');

        // Strona, która nie odpowiada: komunikat zamiast faktów. Zmiana adresu czyści poprzedni wynik.
        eq(303, http('POST', $url . '/strona', ['website' => 'strona-bez-odpowiedzi.example'])->status);
        $f = Prospects::facts(Prospects::find($orgId, $id));
        eq(false, $f['site']['ok']);
        contains('Nie udało się otworzyć strony strona-bez-odpowiedzi.example', http('GET', $url)->body);
    },
    'List pocztą, informacja RODO dla osoby prowadzącej działalność i sprzeciw firmy' => function () use ($win, $org) {
        [$orgId, $uid] = $org();
        $db = Db::get();
        $db->insert('tender_results', $win());
        $db->update('organizations', ['address' => 'ul. Nadawcy 5, 00-002 Warszawa', 'phone' => '22 111 22 33'], 'id = ?', [$orgId]);
        $db->insert('library_items', ['org_id' => $orgId, 'kind' => 'text', 'title' => 'O firmie', 'body' => 'Dostarczamy kruszywa na budowy dróg w całym województwie.', 'created_at' => now_utc(), 'updated_at' => now_utc()]);
        http('POST', '/klienci/potencjalni/z-przetargow', ['nips' => ['1234563218']]);
        Prospects::enrichPending($orgId);
        $id = (int) $db->val('SELECT id FROM prospects');
        $url = '/klienci/potencjalni/' . $id;

        // Spółka: list z faktów (wygrane zamówienie, opis z biblioteki), adres z rejestru, prośba o adres do szczegółów, zdanie o rezygnacji.
        $page = http('GET', $url . '/list')->body;
        contains('PRZYKŁADOWA SPÓŁKA BUDOWLANA SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', $page);
        contains('UL. PRZYKŁADOWA 1, 00-001 WARSZAWA', $page);
        contains('zawarła niedawno umowę na zamówienie „Remont drogi gminnej w Testowie”. Dostarczamy kruszywa na budowy dróg', $page);
        contains('o wskazanie adresu e-mail, na który możemy przesłać szczegóły', $page);
        contains('Jeśli nie chcą Państwo otrzymywać od nas korespondencji, prosimy o informację na adres wlasciciel@example.com. Nie napiszemy ponownie.', $page);
        contains('tel. 22 111 22 33', $page);
        ok(!str_contains($page, 'Informacja o danych osobowych'), 'spółka nie jest osobą fizyczną');
        ok(!str_contains(http('GET', $url)->body, 'chroni RODO'), 'bez not o RODO dla spółki');

        // Pobranie .docx z poprawioną treścią i oznaczenie wysyłki.
        $r = http('POST', $url . '/list', ['action' => 'docx', 'body' => "Szanowni Państwo,\n\nto jest poprawiona treść <b>listu</b> & oferta."]);
        eq(200, $r->status);
        contains('wordprocessingml.document', $r->headers['Content-Type']);
        contains('list-przykladowa-spolka-budowlana', $r->headers['Content-Disposition']);
        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($tmp, $r->body);
        $zip = new ZipArchive();
        ok($zip->open($tmp) === true, 'poprawny plik .docx');
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($tmp);
        contains('to jest poprawiona treść &lt;b&gt;listu&lt;/b&gt; &amp; oferta.', $xml);
        eq(303, http('POST', $url . '/list', ['action' => 'docx', 'body' => '   '])->status, 'pusty list nie jest plikiem');
        eq(303, http('POST', $url . '/list', ['action' => 'sent', 'body' => 'Treść'])->status);
        eq(['contacted', 'letter'], array_values((array) $db->one('SELECT status, contacted_how FROM prospects')));
        contains('List wysłany', http('GET', '/klienci/potencjalni', [], ['stan' => 'contacted'])->body);

        // Osoba prowadząca działalność: nota o terminie informacji i klauzula w liście. Po kontakcie nota znika.
        $db->update('companies', ['legal_form' => Prospects::PERSON_FORM], "nip = '1234563218'");
        $db->update('prospects', ['status' => 'new', 'created_at' => gmdate('Y-m-d H:i:s', time() - 26 * 86400 - 60)], 'id = ?', [$id]);
        $card = http('GET', $url)->body;
        contains('To osoba prowadząca działalność, więc jej dane chroni RODO.', $card);
        contains('Zostało 4 dni.', $card);
        $letter = http('GET', $url . '/list')->body;
        contains('Informacja o danych osobowych: administratorem danych jest Firma Testowa sp. z o.o., ul. Nadawcy 5, 00-002 Warszawa.', $letter);
        contains('wykaz podatników VAT, ogłoszenia o wynikach zamówień publicznych', $letter);
        contains('art. 6 ust. 1 lit. f RODO', $letter);
        contains('sprzeciwu wobec przetwarzania do celów marketingu oraz skargi do Prezesa Urzędu Ochrony Danych Osobowych', $letter);
        $db->update('prospects', ['created_at' => gmdate('Y-m-d H:i:s', time() - 40 * 86400)], 'id = ?', [$id]);
        contains('Termin minął.', http('GET', $url)->body);
        $db->update('prospects', ['status' => 'contacted'], 'id = ?', [$id]);
        ok(!str_contains(http('GET', $url)->body, 'najpóźniej w ciągu miesiąca'), 'po kontakcie nota znika');

        // Otwarte zapytanie ofertowe tej firmy jest pierwszą drogą kontaktu.
        http('POST', '/klienci/zapytania', ['company_name' => 'Przykładowa Budowlana', 'nip' => '1234563218', 'subject' => 'Dostawa kruszywa 0-31,5', 'url' => 'https://przyklad.example/zapytanie']);
        $card = http('GET', $url)->body;
        contains('Odpowiedz na zapytanie ofertowe tej firmy', $card);
        ok(strpos($card, 'Odpowiedz na zapytanie ofertowe tej firmy') < strpos($card, 'Wyślij list pocztą'), 'zapytanie przed listem');

        // Sprzeciw: koniec listów i ofert, kontakt wypisany. Odpowiedź na własne zapytanie firmy zostaje.
        http('POST', $url . '/kontakt', ['email' => 'biuro@przykladowa.example']);
        $cid = (int) $db->val('SELECT contact_id FROM prospects');
        eq(303, http('POST', $url . '/stan', ['status' => 'objected'])->status);
        eq('objected', $db->val('SELECT status FROM prospects'));
        ok($db->val('SELECT unsubscribed_at FROM contacts WHERE id = ?', [$cid]) !== null, 'kontakt wypisany');
        $card = http('GET', $url)->body;
        contains('Firma zgłosiła sprzeciw', $card);
        contains('Odpowiedz na zapytanie ofertowe tej firmy', $card);
        ok(!str_contains($card, 'Wyślij list pocztą') && !str_contains($card, 'e-mailem bez zgody') && !str_contains($card, 'Przygotuj list'), 'po sprzeciwie nie ma dróg marketingowych');
        eq(303, http('GET', $url . '/list')->status);
        eq('/klienci/potencjalni/' . $id, http('POST', $url . '/kontakt', ['email' => 'inny@przykladowa.example'])->headers['Location']);
        eq(1, (int) $db->val('SELECT COUNT(*) FROM contacts'), 'po sprzeciwie nie powstaje nowy kontakt');
        http('POST', $url . '/list', ['action' => 'sent', 'body' => 'Treść']);
        eq('objected', $db->val('SELECT status FROM prospects'), 'sprzeciwu nie da się nadpisać wysyłką');
        contains('sprzeciw', http('GET', '/klienci/potencjalni', [], ['stan' => 'skipped'])->body);
        // Usunięcie z listy zostawia kontakt (i zapisany sprzeciw).
        eq(303, http('POST', $url . '/stan', ['delete' => '1'])->status);
        eq([0, 1], [(int) $db->val('SELECT COUNT(*) FROM prospects'), (int) $db->val('SELECT COUNT(*) FROM contacts WHERE unsubscribed_at IS NOT NULL')]);
    },
];
