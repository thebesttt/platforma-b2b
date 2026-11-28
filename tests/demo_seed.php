<?php
/**
 * Konto demonstracyjne do zrzutów ekranu i testów e2e (tylko APP_ENV=dev/test, z HTTP_FIXTURES).
 * Logowanie: demo@example.com / demo-haslo-123
 * Dodatkowe przetargi są fikcyjne i oznaczone jako przykład.
 */

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Db;
use App\Modules\Audit\Audit;
use App\Modules\Companies\Companies;
use App\Modules\Companies\Uokik;
use App\Modules\Content\ContentAudit;
use App\Modules\Inbound\Inbound;
use App\Modules\Offers\Offers;
use App\Modules\Tenders\Importer;
use App\Modules\Tenders\Matcher;
use App\Modules\Tenders\Results;
use App\Modules\Tenders\Tenders;

function demo_seed(): array
{
    $db = Db::get();
    if ($db->val("SELECT 1 FROM users WHERE email = 'demo@example.com'")) {
        return ['ok' => true, 'info' => 'Konto demo już istnieje.'];
    }
    Uokik::sync(3);
    Importer::run('2026-11-15 00:00:00');
    $future = static fn (int $days, string $hour = '09:00:00') => gmdate('Y-m-d ' . $hour, time() + $days * 86400);
    $db->run('UPDATE tenders SET deadline_at = ? WHERE deadline_at < ?', [$future(12), now_utc()]);

    $examples = [
        ['Przebudowa drogi powiatowej na odcinku 2,4 km wraz z chodnikiem (przykład)', 'Powiat Przykładowy', 'mazowieckie', '45233000 45233222 45112500', 2900000, 9, '9999999999'],
        ['Remont nawierzchni ulic w centrum miasta - etap II (przykład)', 'Gmina Przykładowo', 'mazowieckie', '45233222', 1600000, 6, null],
        ['Budowa ciągu pieszo-rowerowego wzdłuż drogi wojewódzkiej (przykład)', 'Zarząd Dróg Wojewódzkich (przykład)', 'lodzkie', '45233162 45233000', 4700000, 17, null],
        ['Wykonanie parkingu przy hali sportowej (przykład)', 'Przykładowa Spółka Komunalna sp. z o.o.', 'mazowieckie', '45233000', 800000, 3, '1234563218'],
        ['Odwodnienie i utwardzenie placu manewrowego (przykład)', 'Gmina Przykładowa Wola', 'lodzkie', '45233000 45232450', 650000, 11, null],
    ];
    foreach ($examples as $i => [$title, $buyer, $woj, $cpv, $value, $days, $nip]) {
        Tenders::save([
            'source' => 'bzp', 'external_id' => 'demo-' . $i, 'notice_number' => '2026/BZP 0000000' . $i . '/01 (przykład)', 'notice_type' => 'ContractNotice',
            'title' => $title, 'order_type' => 'works', 'buyer_name' => $buyer, 'buyer_nip' => $nip, 'buyer_city' => 'Przykładowo', 'voivodeship' => $woj,
            'cpv_main' => substr($cpv, 0, 8), 'cpv_all' => $cpv, 'value_estimated' => $value, 'currency' => 'PLN',
            'published_at' => gmdate('Y-m-d H:i:s', time() - 86400), 'deadline_at' => $future($days, '09:00:00'), 'url' => null,
            'details_json' => json_out(['descriptions' => ['Zakres: ' . $title . '. Szczegóły w SWZ.'], 'criteria' => [['name' => 'Cena', 'weight' => 60.0], ['name' => 'Okres gwarancji', 'weight' => 40.0]], 'wadium' => true, 'wadium_value' => round($value * 0.015, -3), 'period' => '8 miesięcy od dnia podpisania umowy', 'conditions_required' => true, 'conditions' => 'Co najmniej 2 roboty drogowe o wartości min. 1 mln zł brutto każda w ostatnich 5 latach.', 'advance_payments' => false, 'deadline' => $future($days, '09:00:00'), 'procedure' => 'Tryb podstawowy', 'text' => '']),
            'details_fetched_at' => now_utc(),
        ]);
    }

    // Wyniki przetargów. Prawdziwe ogłoszenia z zapisanych odpowiedzi BZP plus przykłady dla zamawiających z danych demo,
    // żeby karta przetargu pokazywała, kto u nich wygrywał. Jeden zakończony przetarg jest zapisany i ma wynik: z tego powstaje alert.
    \App\Modules\Tenders\BzpClient::$pauseUs = 0;
    Results::import('2026-11-15 00:00:00', 50, 60);
    \App\Modules\Tenders\BzpClient::$pauseUs = 150000;
    $db->run('UPDATE tender_results SET published_at = ?', [gmdate('Y-m-d H:i:s', time() - 2 * 86400)]);
    $pastId = Tenders::save([
        'source' => 'bzp', 'external_id' => 'demo-zakonczony', 'notice_number' => '2026/BZP 00000009/01 (przykład)', 'notice_type' => 'ContractNotice', 'ocid' => 'ocds-przyklad-zakonczony',
        'title' => 'Przebudowa skrzyżowania drogi powiatowej z drogą gminną (przykład)', 'order_type' => 'works', 'buyer_name' => 'Powiat Przykładowy', 'buyer_nip' => '9999999999',
        'buyer_city' => 'Przykładowo', 'voivodeship' => 'mazowieckie', 'cpv_main' => '45233000', 'cpv_all' => '45233000 45233222', 'value_estimated' => 1900000, 'currency' => 'PLN',
        'published_at' => gmdate('Y-m-d H:i:s', time() - 55 * 86400), 'deadline_at' => gmdate('Y-m-d 09:00:00', time() - 34 * 86400), 'url' => null,
        'details_json' => json_out(['descriptions' => ['Zakres: przebudowa skrzyżowania z budową lewoskrętu i chodnika. Szczegóły w SWZ.'], 'criteria' => [['name' => 'Cena', 'weight' => 60.0], ['name' => 'Okres gwarancji', 'weight' => 40.0]]]),
        'details_fetched_at' => now_utc(),
    ]);
    $won = [
        // identyfikator, postępowanie, tytuł, zamawiający, NIP zamawiającego, województwo, wykonawca, NIP, miasto, oferty, najniższa, najwyższa, wybrana, dni temu
        ['demo-w0', 'ocds-przyklad-zakonczony', 'Przebudowa skrzyżowania drogi powiatowej z drogą gminną (przykład)', 'Powiat Przykładowy', '9999999999', 'mazowieckie', 'Drogi i Mosty Przykład sp. z o.o. (przykład)', null, 'Przykładowo', 4, 1780000, 2350000, 1780000, 1],
        ['demo-w1', null, 'Remont drogi powiatowej nr 1234P na odcinku 1,8 km (przykład)', 'Powiat Przykładowy', '9999999999', 'mazowieckie', 'Drogi i Mosty Przykład sp. z o.o. (przykład)', null, 'Przykładowo', 5, 2410000, 3180000, 2410000, 46],
        ['demo-w2', null, 'Budowa chodnika przy drodze powiatowej w miejscowości Przykładowo (przykład)', 'Powiat Przykładowy', '9999999999', 'mazowieckie', 'PRZYKŁADOWA SPÓŁKA BUDOWLANA SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', '1234563218', 'Warszawa', 3, 640000, 910000, 655000, 71],
        ['demo-w3', null, 'Utwardzenie placu przy szkole podstawowej (przykład)', 'Gmina Przykładowa Wola', null, 'lodzkie', 'PRZYKŁADOWA SPÓŁKA BUDOWLANA SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', '1234563218', 'Warszawa', 6, 390000, 720000, 390000, 23],
        ['demo-w4', null, 'Remont nawierzchni ulicy Przykładowej (przykład)', 'Gmina Przykładowo', null, 'mazowieckie', 'Zakład Robót Drogowych Przykład (przykład)', null, 'Przykładowa Wola', 2, 1210000, 1390000, 1210000, 12],
    ];
    foreach ($won as [$ext, $ocid, $title, $buyer, $buyerNip, $woj, $winner, $winnerNip, $city, $offers, $low, $high, $price, $daysAgo]) {
        Results::saveMany([[
            'source' => 'bzp', 'external_id' => $ext, 'part' => 1, 'notice_number' => '2026/BZP 0000001' . substr($ext, -1) . '/01 (przykład)', 'ocid' => $ocid, 'title' => $title,
            'order_type' => 'works', 'buyer_name' => $buyer, 'buyer_nip' => $buyerNip, 'buyer_city' => 'Przykładowo', 'voivodeship' => $woj, 'cpv_main' => '45233000', 'cpv_all' => '45233000 45233222',
            'result' => 'contract', 'winner_name' => $winner, 'winner_nip' => $winnerNip, 'winner_city' => $city, 'winner_voivodeship' => 'mazowieckie',
            'published_at' => gmdate('Y-m-d H:i:s', time() - $daysAgo * 86400), 'url' => null,
        ]]);
        $db->update('tender_results', ['offers' => $offers, 'price_lowest' => $low, 'price_highest' => $high, 'price_winner' => $price, 'contract_value' => $price, 'currency' => 'PLN',
            'contract_date' => gmdate('Y-m-d', time() - ($daysAgo + 6) * 86400), 'details_json' => json_out(['period' => '90 dni']), 'details_fetched_at' => now_utc()], 'external_id = ?', [$ext]);
    }

    $reg = Auth::register('Twoja Firma Drogowa sp. z o.o.', '3333333333', 'Anna Demo', 'demo@example.com', 'demo-haslo-123');
    $uid = (int) $reg['user_id'];
    $org = (int) $db->val('SELECT org_id FROM users WHERE id = ?', [$uid]);
    $db->update('organizations', ['address' => 'ul. Przykładowa 10, 00-001 Warszawa', 'phone' => '+48 600 000 000', 'website' => 'https://example.com'], 'id = ?', [$org]);
    $profileId = $db->insert('tender_profiles', [
        'org_id' => $org, 'name' => 'Roboty drogowe - Mazowsze i Łódzkie', 'cpv' => '45233', 'keywords' => "nawierzchni\nchodnik\nparking\ndrog",
        'exclude_keywords' => 'odśnieżanie', 'voivodeships' => 'mazowieckie lodzkie podlaskie', 'order_types' => 'works',
        'min_value' => 500000, 'max_value' => 5000000, 'sources' => 'bzp ted', 'active' => 1, 'created_at' => now_utc(),
    ]);
    Matcher::run($org, $profileId, gmdate('Y-m-d H:i:s', time() - 30 * 86400));
    $db->insert('tender_matches', ['org_id' => $org, 'profile_id' => $profileId, 'tender_id' => $pastId, 'score' => 86, 'reasons_json' => json_out(['Województwo: mazowieckie', 'Kod CPV 45233000 (główny)']), 'status' => 'saved', 'created_at' => gmdate('Y-m-d H:i:s', time() - 50 * 86400)]);
    Results::alertSaved();

    $lib = [
        ['reference', 'Przebudowa ulicy Przykładowej w Gminie Przykładowo', 'Przebudowa drogi gminnej z chodnikiem i odwodnieniem, długość 1,2 km.', ['client' => 'Gmina Przykładowo', 'value' => 1800000, 'finished' => '09.2025']],
        ['reference', 'Budowa drogi gminnej z odwodnieniem', 'Nowa jezdnia 5,5 m, kanalizacja deszczowa, oznakowanie.', ['client' => 'Gmina Przykładowa Wola', 'value' => 1300000, 'finished' => '06.2024']],
        ['person', 'Marek Przykładowy - kierownik budowy', 'Uprawnienia budowlane w specjalności drogowej bez ograniczeń, 12 lat doświadczenia na budowach dróg powiatowych.', ['role' => 'kierownik budowy']],
        ['text', 'O firmie', 'Twoja Firma Drogowa wykonuje roboty drogowe od 2009 roku: przebudowy dróg gminnych i powiatowych, chodniki, parkingi i odwodnienia. Pracujemy własnym sprzętem na Mazowszu i w Łódzkiem.', []],
        ['insurance', 'Polisa OC działalności - suma gwarancyjna 5 mln zł', '', []],
    ];
    foreach ($lib as [$kind, $title, $body, $meta]) {
        $db->insert('library_items', ['org_id' => $org, 'kind' => $kind, 'title' => $title, 'body' => $body, 'meta_json' => $meta ? json_out($meta) : null,
            'valid_until' => $kind === 'insurance' ? date('Y-m-d', strtotime('+7 months')) : null, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
    }
    $best = $db->one("SELECT t.* FROM tender_matches m JOIN tenders t ON t.id = m.tender_id WHERE m.org_id = ? AND t.external_id = 'demo-0'", [$org]);
    if ($best) {
        $offerId = Offers::create($org, $uid, $best);
        $db->update('offers', ['status' => 'review'], 'id = ?', [$offerId]);
        // Zespół: druga osoba z przydzielonymi sekcjami, jedna sekcja dla właściciela i dwa odhaczone dokumenty.
        $mate = $db->insert('users', ['org_id' => $org, 'email' => 'marek@example.com', 'name' => 'Marek Przykładowy', 'password_hash' => Auth::hash('demo-haslo-456'), 'role' => 'member', 'created_at' => now_utc()]);
        $secs = Offers::sections($offerId);
        foreach ([[1, $mate], [2, $mate], [3, $uid]] as [$i, $who]) {
            if (isset($secs[$i])) {
                $db->update('offer_sections', ['assignee_id' => $who], 'id = ?', [(int) $secs[$i]['id']]);
            }
        }
        if (isset($secs[0])) {
            $db->update('offer_sections', ['status' => 'done', 'updated_by' => $uid], 'id = ?', [(int) $secs[0]['id']]);
        }
        foreach (array_slice(Offers::checklist($org, $offerId), 0, 2) as $c) {
            $db->update('offer_checklist', ['done' => 1, 'done_by' => $uid, 'done_at' => gmdate('Y-m-d H:i:s', time() - 5 * 3600)], 'id = ?', [(int) $c['id']]);
        }
    }

    // Wniosek o dotację (przykład): regulamin naboru i sprawdzone warunki. Odpowiedź modelu pochodzi z zapisanego przykładu.
    $db->update('organizations', ['address' => 'ul. Testowa 1, 00-001 Warszawa'], 'id = ?', [$org]);
    $db->insert('library_items', ['org_id' => $org, 'kind' => 'text', 'title' => 'O firmie (przykład)', 'body' => 'Firma działa od marca 2026 r. i wykonuje roboty drogowe.', 'created_at' => now_utc(), 'updated_at' => now_utc()]);
    $grantId = Offers::create($org, $uid, null, 'grant', ['call_name' => 'Wsparcie inwestycji MŚP na Mazowszu (przykład)', 'call_url' => null, 'deadline_at' => gmdate('Y-m-d 22:59:00', time() + 30 * 86400)]);
    $db->insert('offer_documents', ['org_id' => $org, 'offer_id' => $grantId, 'filename' => 'regulamin.txt', 'mime' => 'text/plain', 'size' => 520, 'uploaded_by' => $uid, 'created_at' => now_utc(),
        'text' => "Regulamin naboru (przykład)\n§ 4. Kto może złożyć wniosek\n1. O dofinansowanie mogą ubiegać się wyłącznie mikro, mali i średni przedsiębiorcy.\n"
            . "2. Wnioskodawca prowadzi działalność na terenie województwa mazowieckiego.\n3. Wnioskodawca prowadzi działalność od co najmniej 24 miesięcy - licząc do dnia złożenia wniosku.\n"
            . "§ 5. Finansowanie\n1. Minimalny wkład własny wynosi 15% kosztów kwalifikowalnych.\n"]);
    if (\App\Modules\Ai\Claude::enabled()) {
        \App\Modules\Offers\Eligibility::check($org, (array) Offers::find($org, $grantId), $uid);
    }

    // Kontrahenci: obserwowany płatnik z decyzją UOKiK i należność po terminie.
    $c = Companies::lookup('1234563218')['company'] ?? null;
    Companies::lookup('7181968222');
    if ($c) {
        $db->insert('watchlist', ['org_id' => $org, 'company_id' => (int) $c['id'], 'note' => 'podwykonawca - parking', 'created_at' => now_utc()]);
        $db->insert('alerts', ['org_id' => $org, 'company_id' => (int) $c['id'], 'level' => 'high', 'title' => (string) $c['name'], 'detail' => 'Nowa decyzja UOKiK o opóźnianiu płatności: DZP-99/2025', 'created_at' => now_utc()]);
        $db->insert('receivables', ['org_id' => $org, 'company_id' => (int) $c['id'], 'invoice_no' => 'FV/2026/09/14', 'amount' => 18450.00, 'currency' => 'PLN',
            'issued_at' => date('Y-m-d', strtotime('-80 days')), 'due_at' => date('Y-m-d', strtotime('-50 days')), 'demand_sent_at' => date('Y-m-d', strtotime('-35 days')),
            'demand_bureau' => 'Krajowy Rejestr Długów Biuro Informacji Gospodarczej S.A., ul. Danuty Siedzikówny 12, 51-214 Wrocław', 'created_at' => now_utc()]);
        // Dane wpisane ręcznie z rejestrów, których program nie pobierze: KRZ i sprawozdania finansowe.
        \App\Modules\Companies\ManualChecks::saveKrz($org, (int) $c['id'], $uid, ['result' => 'none', 'checked_on' => gmdate('Y-m-d', time() - 12 * 86400)]);
        \App\Modules\Companies\ManualChecks::saveFinance($org, (int) $c['id'], $uid, ['year' => '2024', 'payables' => '1 480 000', 'purchases' => '9 100 000', 'revenue' => '11 900 000', 'receivables' => '1 310 000', 'net_result' => '212 000', 'equity' => '2 050 000']);
        \App\Modules\Companies\ManualChecks::saveFinance($org, (int) $c['id'], $uid, ['year' => '2025', 'payables' => '2 640 000', 'purchases' => '9 800 000', 'revenue' => '12 400 000', 'receivables' => '1 520 000', 'net_result' => '-184 000', 'equity' => '1 866 000']);
    }
    // Druga obserwowana firma bez sprawdzenia w KRZ: lista kontrahentów przypomina, żeby je zrobić.
    $c2 = Companies::byNip('7181968222');
    if ($c2) {
        $db->insert('watchlist', ['org_id' => $org, 'company_id' => (int) $c2['id'], 'note' => 'dostawca kruszywa', 'created_at' => now_utc()]);
    }

    $db->insert('contacts', ['org_id' => $org, 'company_name' => 'Przykładowa Firma Instalacyjna sp. z o.o.', 'person' => 'Piotr Przykładowy', 'email' => 'zamowienia@example.com',
        'basis' => 'rfq', 'basis_detail' => 'zapytanie ofertowe „Utwardzenie placu manewrowego” na stronie firmy', 'basis_at' => date('Y-m-d', strtotime('-4 days')), 'created_at' => now_utc()]);
    $db->insert('contacts', ['org_id' => $org, 'company_name' => 'Przykładowy Deweloper sp. z o.o.', 'email' => 'biuro@example.org', 'basis' => 'none', 'website' => 'https://example.org', 'created_at' => now_utc()]);
    $db->insert('contacts', ['org_id' => $org, 'company_name' => 'Spółdzielnia Mieszkaniowa (przykład)', 'person' => 'Ewa Przykładowa', 'email' => 'zarzad@example.net', 'basis' => 'client', 'basis_detail' => 'remont parkingu w 2025 r., prośba o kolejne oferty', 'created_at' => now_utc()]);
    // Idealny klient: opis wpisany ręcznie (w panelu można go też przygotować ze strony firmy).
    \App\Modules\Outreach\Icp::saveText($org, $db->one('SELECT * FROM organizations WHERE id = ?', [$org]),
        'Zarządcy dróg powiatowych i gminnych oraz firmy z własnym placem albo parkingiem na Mazowszu i w Łódzkiem, którzy potrzebują remontu nawierzchni o wartości do 5 mln zł i cenią krótki termin.');
    // Przypomnienia: jedna firma z krokiem na dziś, jedna czeka na termin, jedna odpisała.
    $seqDemo = static function (string $company, string $email, int $sentDaysAgo, ?string $stop = null) use ($db, $org, $uid): void {
        $cid = $db->insert('contacts', ['org_id' => $org, 'company_name' => $company, 'person' => null, 'email' => $email, 'basis' => 'rfq',
            'basis_detail' => 'zapytanie o remont nawierzchni placu', 'basis_at' => gmdate('Y-m-d', time() - ($sentDaysAgo + 1) * 86400), 'created_at' => gmdate('Y-m-d H:i:s', time() - ($sentDaysAgo + 1) * 86400),
            'replied_at' => $stop === 'replied' ? gmdate('Y-m-d H:i:s', time() - 86400) : null]);
        $sent = gmdate('Y-m-d H:i:s', time() - $sentDaysAgo * 86400);
        $sid = $db->insert('sequences', ['org_id' => $org, 'contact_id' => $cid, 'kind' => 'rfq_reply', 'status' => $stop ? 'stopped' : 'active', 'stop_reason' => $stop, 'step' => 0,
            'next_at' => $stop ? null : gmdate('Y-m-d H:i:s', strtotime($sent . ' UTC') + 4 * 86400), 'started_at' => $sent, 'updated_at' => $stop ? gmdate('Y-m-d H:i:s', time() - 86400) : $sent, 'created_by' => $uid]);
        $db->insert('messages', ['org_id' => $org, 'contact_id' => $cid, 'kind' => 'rfq_reply', 'risk' => 'low', 'subject' => 'Odpowiedź na zapytanie ofertowe: remont nawierzchni placu',
            'body' => "Dzień dobry,\n\ndziękujemy za zapytanie. W załączeniu przesyłamy ofertę.\n\nZ poważaniem\nAnna Demo", 'status' => 'sent', 'sent_at' => $sent, 'created_by' => $uid, 'created_at' => $sent,
            'sequence_id' => $sid, 'step' => 0]);
    };
    $seqDemo('Hurtownia Budowlana (przykład)', 'biuro@hurtownia.example', 5);
    $seqDemo('Zakład Usług Komunalnych (przykład)', 'zamowienia@zuk.example', 2);
    $seqDemo('Centrum Logistyczne (przykład)', 'kontakt@centrum.example', 6, 'replied');

    // Zapytania ofertowe z rynku wpisane ręcznie: jedno z e-mailem i bliskim terminem, jedno z Bazy Konkurencyjności, jedno pominięte.
    $plus = static fn (int $d): string => date('Y-m-d', strtotime(today_pl() . ' ' . ($d >= 0 ? '+' : '') . $d . ' days'));
    \App\Modules\Outreach\Rfqs::save($org, $uid, ['company_name' => 'Przedsiębiorstwo Komunalne (przykład)', 'nip' => '7181968222', 'subject' => 'Remont nawierzchni placu manewrowego, ok. 1200 m2',
        'url' => 'https://example.com/zapytania/remont-placu', 'source' => 'www', 'deadline_at' => $plus(2), 'published_at' => $plus(-5), 'email' => 'zakupy@komunalne.example', 'person' => 'Jan Przykładowy',
        'notes' => 'Wymagane dwie referencje z ostatnich 3 lat. Wizja lokalna po umówieniu.']);
    \App\Modules\Outreach\Rfqs::save($org, $uid, ['company_name' => 'Fabryka Mebli (przykład)', 'subject' => 'Budowa parkingu dla pracowników w ramach projektu unijnego',
        'url' => 'https://bazakonkurencyjnosci.funduszeeuropejskie.gov.pl/', 'source' => 'bk', 'deadline_at' => $plus(9), 'published_at' => $plus(-3), 'notes' => 'Oferta przez Bazę Konkurencyjności.']);
    $skipped = \App\Modules\Outreach\Rfqs::save($org, $uid, ['company_name' => 'Zakład Produkcyjny (przykład)', 'subject' => 'Dostawa kostki brukowej 8 cm, 3000 m2',
        'url' => 'https://platformazakupowa.pl/all', 'source' => 'pz', 'deadline_at' => $plus(-4)]);
    \App\Modules\Outreach\Rfqs::setStatus($org, (int) $skipped['id'], 'skipped');

    // Nabory dotacji wpisane ręcznie: jeden powiązany z przykładowym wnioskiem, jeden bliski końca bez wniosku, jeden przed startem.
    $callId = (int) \App\Modules\Offers\GrantCalls::save($org, $uid, ['name' => 'Wsparcie inwestycji MŚP na Mazowszu (przykład)', 'institution' => 'Instytucja przykładowa',
        'url' => 'https://example.com/nabory/wsparcie-inwestycji', 'opens_at' => $plus(-20), 'closes_at' => $plus(30), 'amount_note' => 'do 800 000 zł, dofinansowanie do 50%',
        'audience_note' => 'mikro, małe i średnie firmy z województwa mazowieckiego'])['id'];
    $db->update('grant_calls', ['offer_id' => $grantId, 'status' => 'apply'], 'id = ?', [$callId]);
    \App\Modules\Offers\GrantCalls::save($org, $uid, ['name' => 'Bon na cyfryzację firmy (przykład)', 'institution' => 'Instytucja przykładowa', 'url' => 'https://example.com/nabory/bon-cyfryzacja',
        'opens_at' => $plus(-30), 'closes_at' => $plus(6), 'amount_note' => 'do 150 000 zł', 'audience_note' => 'małe i średnie firmy', 'notes' => 'Wkład własny 15%. Sprawdzić, czy zakup oprogramowania się kwalifikuje.']);
    \App\Modules\Offers\GrantCalls::save($org, $uid, ['name' => 'Zielona transformacja przedsiębiorstw (przykład)', 'url' => 'https://example.com/nabory/zielona', 'opens_at' => $plus(21), 'closes_at' => $plus(80)]);

    // Odpowiedź klienta wklejona z poczty: widać ją w „Co napisał klient” na karcie i na liście kontaktów.
    $firstContact = (int) $db->val('SELECT id FROM contacts WHERE org_id = ? ORDER BY id LIMIT 1', [$org]);
    if ($firstContact) {
        \App\Modules\Outreach\ClientVoice::addReply($org, $firstContact, $uid, "Dzień dobry,\ndziękujemy za ofertę. Interesuje nas remont placu jeszcze w tym roku.\nProszę o termin wizji lokalnej i wycenę wariantu z kostką 8 cm.\n\nPozdrawiam\nJan Przykładowy", substr($plus(-2), 0, 10));
    }

    // Wyszukiwarka naborów: jedno wyszukiwanie i trzy propozycje do przejrzenia (jedna ze strony urzędowej, jedna bez terminu).
    $searchId = $db->insert('grant_searches', ['org_id' => $org, 'query_json' => json_out(['what' => 'zakup sprzętu do robót drogowych i szkolenia operatorów', 'region' => 'mazowieckie', 'size' => 'mala']),
        'auto' => 0, 'ok' => 1, 'found' => 3, 'searches' => 4, 'created_by' => $uid, 'created_at' => now_utc()]);
    foreach ([
        ['Kredyt ekologiczny (przykład)', 'Bank przykładowy', 'https://www.gov.pl/web/przyklad/kredyt-ekologiczny', 1, $plus(-10), $plus(45), 'premia do 80% kosztów kwalifikowanych', 'mikro, małe i średnie firmy oraz small mid-caps',
            'Pasuje do wymiany maszyn na mniej energochłonne. Wymaga audytu energetycznego przed złożeniem wniosku.', 'Nabór wniosków trwa do wyczerpania środków, nie dłużej niż do wskazanego dnia.'],
        ['Szkolenia i doradztwo dla firm budowlanych (przykład)', null, 'https://example.com/nabory/szkolenia-budowlane', 0, null, null, null, 'firmy z sektora budowlanego',
            'Termin nie jest podany na stronie, trzeba go sprawdzić u operatora.', null],
        ['Inwestycje w maszyny dla MŚP na Mazowszu (przykład)', 'Instytucja przykładowa', 'https://example.com/nabory/maszyny-msp', 0, $plus(14), $plus(60), 'do 1 000 000 zł, dofinansowanie do 40%', 'małe i średnie firmy z województwa mazowieckiego',
            'Nabór dopiero się otworzy. Zakup rozściełacza mieści się w opisie kosztów kwalifikowanych.', null],
    ] as [$name, $inst, $url, $official, $opens, $closes, $amount, $audience, $fit, $quote]) {
        $db->insert('grant_finds', ['org_id' => $org, 'search_id' => $searchId, 'name' => $name, 'institution' => $inst, 'url' => $url, 'url_hash' => sha1($url), 'source_title' => $name,
            'official' => $official, 'opens_at' => $opens ? substr($opens, 0, 10) : null, 'closes_at' => $closes ? substr($closes, 0, 10) : null, 'amount_note' => $amount, 'audience_note' => $audience,
            'fit' => $fit, 'quote' => $quote, 'status' => 'new', 'created_at' => now_utc()]);
    }

    // Zapytania ze strony: widżet dopuszczony na example.com i przykładowe zapytania przeprowadzone tą samą ścieżką co prawdziwe
    // (start rozmowy, odpowiedzi, formularz), więc ocena pochodzi z tabeli punktów, a nie z ręki.
    $w = Inbound::widget($org);
    $db->update('inbound_widgets', ['origins' => 'example.com', 'texts_json' => json_out(['company' => 'Twoja Firma Drogowa', 'privacy_url' => 'https://example.com/polityka-prywatnosci'])], 'id = ?', [(int) $w['id']]);
    $w = Inbound::widget($org);
    $samples = [
        [[0, 0, 0, 0], ['name' => 'Karolina Przykładowa', 'email' => 'karolina@example.org', 'phone' => '+48 601 000 001', 'nip' => '1234563218', 'message' => 'Zależy nam na starcie przed sezonem. Proszę o telefon po 14.', 'consent' => true], 2],
        [[1, 1, 1, 2], ['name' => 'Tomasz Przykładowy', 'email' => 'tomasz@example.net', 'message' => 'Mamy sklep od roku, reklamy prowadziliśmy sami.'], 26],
        [[4, 2, 3, 4], ['email' => 'biuro@example.com'], 50],
        [[3, 0, 1, 1], ['name' => 'Ewa Przykładowa', 'email' => 'ewa@example.org', 'phone' => '602 000 002'], 75],
    ];
    foreach ($samples as $i => [$answers, $form, $hoursAgo]) {
        $start = Inbound::start($w, 'https://example.com/kontakt', '203.0.113.' . ($i + 1));
        $conv = Inbound::conversation($w, $start['token']);
        foreach ($answers as $q => $a) {
            Inbound::answer($w, Inbound::conversation($w, $start['token']), $q, $a);
        }
        $db->run('UPDATE inbound_conversations SET started_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - $hoursAgo * 3600 - 90), (int) $conv['id']]);
        $res = Inbound::lead($w, Inbound::conversation($w, $start['token']), $form, 'http://127.0.0.1');
        if (!empty($res['lead_id'])) {
            $db->update('inbound_leads', ['created_at' => gmdate('Y-m-d H:i:s', time() - $hoursAgo * 3600), 'status' => ['new', 'new', 'contact', 'offer'][$i]], 'id = ?', [(int) $res['lead_id']]);
        }
    }

    // Potencjalni klienci: firma z wyników przetargów (z danymi z rejestrów) i dwie firmy z listy wklejonej przez użytkownika.
    // Strony przykładowych firm odpowiadają tylko z plików testowych, więc bez nich sprawdzenie strony pomijamy.
    \App\Modules\Outreach\Prospects::saveCriteria($org, ['keywords' => "budow\ndrog\nhurtowni", 'regions' => [], 'min_years' => 2]);
    \App\Modules\Outreach\Prospects::addFromResults($org, $uid, ['1234563218']);
    \App\Modules\Outreach\Prospects::addList($org, $uid, "firma-zaprasza.example Hurtownia materiałów budowlanych (przykład)\nfirma-uslugowa.example Biuro rachunkowe (przykład)");
    \App\Modules\Outreach\Prospects::enrichPending($org, 10);
    if ((string) \App\Core\Config::get('HTTP_FIXTURES') !== '') {
        foreach (['firma-zaprasza.example', 'firma-uslugowa.example'] as $host) {
            if ($row = \App\Modules\Outreach\Prospects::byHost($org, $host)) {
                \App\Modules\Outreach\Prospects::checkSite($row);
            }
        }
    }
    if ($row = \App\Modules\Outreach\Prospects::byNip($org, '1234563218')) {
        \App\Modules\Outreach\Prospects::setStatus($org, (int) $row['id'], 'picked');
        // Przykładowy audyt strony tej firmy: list i strona zgody obiecują raport z niego.
        $db->update('prospects', ['website' => 'https://przykladowa-budowlana.example', 'host' => 'przykladowa-budowlana.example'], 'id = ?', [(int) $row['id']]);
        $find = static fn (string $id, string $cat, string $sev, int $points, string $title, string $detail = '', ?string $fix = null): array
            => ['id' => $id, 'cat' => $cat, 'sev' => $sev, 'title' => $title, 'detail' => $detail, 'fix' => $fix, 'service' => null, 'points' => $points];
        $aid = $db->insert('site_audits', ['org_id' => $org, 'url' => 'https://przykladowa-budowlana.example/', 'host' => 'przykladowa-budowlana.example', 'score' => 58,
            'created_by' => $uid, 'created_at' => now_utc(), 'result_json' => json_out([
                'scanner_version' => 'przykład', 'input' => 'przykladowa-budowlana.example', 'host' => 'przykladowa-budowlana.example', 'scanned_at' => gmdate('c'),
                'final_url' => 'https://przykladowa-budowlana.example/', 'score' => 58, 'grade' => 'poor', 'label' => 'Potencjalny klient (dane przykładowe)',
                'categories' => ['security' => ['label' => 'Bezpieczeństwo', 'score' => 70], 'speed' => ['label' => 'Szybkość', 'score' => 45],
                    'seo' => ['label' => 'SEO', 'score' => 55], 'marketing' => ['label' => 'Analityka i RODO', 'score' => 50]],
                'counts' => ['critical' => 1, 'warning' => 3, 'info' => 0, 'ok' => 3], 'services' => [], 'psi_done' => false, 'meta' => ['prestashop' => ['is' => false]],
                'findings' => [
                    $find('redirect', 'security', 'critical', 8, 'Adres bez szyfrowania nie przekierowuje na HTTPS', 'Wejście przez http:// zostaje na nieszyfrowanej wersji strony.', 'Ustaw stałe przekierowanie 301 z http:// na https://.'),
                    $find('desc', 'seo', 'warning', 5, 'Strona główna nie ma opisu dla wyszukiwarek', 'Wyszukiwarka sama wybiera fragment tekstu pod tytułem w wynikach.', 'Dodaj opis do 155 znaków: co firma robi i gdzie działa.'),
                    $find('img', 'speed', 'warning', 6, 'Zdjęcia realizacji nie są zmniejszone', 'Strona główna pobiera 6,2 MB zdjęć. Na telefonie ładuje się kilka sekund.', 'Zmniejsz zdjęcia do szerokości ekranu i zapisz w formacie WebP.'),
                    $find('analytics', 'marketing', 'warning', 5, 'Brak pomiaru ruchu na stronie', 'Nie widać Google Analytics ani innego narzędzia. Nie wiadomo, skąd przychodzą zapytania.', 'Podłącz pomiar ruchu z banerem zgody.'),
                    $find('tls', 'security', 'ok', 0, 'Certyfikat SSL jest ważny'),
                    $find('title', 'seo', 'ok', 0, 'Strona ma tytuł'),
                    $find('mobile', 'speed', 'ok', 0, 'Strona dopasowuje się do telefonu'),
                ]])]);
        \App\Modules\Outreach\ProspectAudit::attach($org, $row, $aid);
        // Przykładowa historia do lejka listów: list wyszedł 23 dni temu, firma weszła na stronę zgody i nie odpowiedziała. Panel proponuje drugi list.
        foreach ([['letter', 23], ['visit', 21]] as [$kind, $daysAgo]) {
            $db->insert('prospect_events', ['org_id' => $org, 'prospect_id' => (int) $row['id'], 'kind' => $kind, 'created_by' => $kind === 'letter' ? $uid : null,
                'created_at' => gmdate('Y-m-d H:i:s', time() - $daysAgo * 86400)]);
        }
        \App\Modules\Outreach\Prospects::saveLetterCost($org, '6,50');
        \App\Modules\Outreach\Prospects::saveTopics($org, "Roboty drogowe\nChodniki i parkingi\nOdwodnienia");
    }
    // Przykład zgody zapisanej ręcznie: firma poprosiła o ofertę w rozmowie. Firma z przetargów pokazuje drogę listem ze stroną zgody.
    if ($row = \App\Modules\Outreach\Prospects::byHost($org, 'firma-uslugowa.example')) {
        \App\Modules\Outreach\Consents::manual($row, $uid, ['email' => 'biuro@firma-uslugowa.example', 'person' => 'Maria Przykładowa', 'way' => 'in_person',
            'detail' => 'Na spotkaniu izby gospodarczej pani Maria Przykładowa poprosiła o ofertę na ten adres. Mam wizytówkę z dopiskiem.', 'on' => today_pl()]);
    }

    // Audyt treści: fikcyjna strona poradnika sprawdzona dwa razy, przed pracami i po nich. Odpowiedzi strony i usług
    // pomiarowych pochodzą z plików testowych, więc na czas przykładu udajemy podłączoną usługę renderowania.
    require_once APP_ROOT . '/tests/fixtures/http/site.php';
    $saved = ['FIXTURE_SITE' => \App\Core\Config::get('FIXTURE_SITE'), 'CF_ACCOUNT_ID' => \App\Core\Config::get('CF_ACCOUNT_ID'), 'CF_BROWSER_TOKEN' => \App\Core\Config::get('CF_BROWSER_TOKEN')];
    \App\Core\Config::set('CF_ACCOUNT_ID', 'przyklad');
    \App\Core\Config::set('CF_BROWSER_TOKEN', 'przyklad');
    $target = ContentAudit::addTarget($org, $uid, SITE_URL, 'jaki ekspres ciśnieniowy do domu', 'article');
    if ($target['ok']) {
        ContentAudit::saveQuestions(ContentAudit::target($org, (int) $target['id']), site_questions(), true);
        foreach ([['przed', 'przed pracami', 37], ['po', 'po pracach', 2]] as [$variant, $label, $daysAgo]) {
            \App\Core\Config::set('FIXTURE_SITE', $variant);
            $aid = ContentAudit::start(ContentAudit::target($org, (int) $target['id']), $uid, $label);
            ContentAudit::runAll($org, $aid);
            $when = gmdate('Y-m-d H:i:s', time() - $daysAgo * 86400);
            $db->update('content_audits', ['created_at' => $when, 'finished_at' => $when], 'id = ?', [$aid]);
        }
    }
    foreach ($saved as $k => $v) {
        \App\Core\Config::set($k, $v);
    }

    // Widoczność: przykładowa witryna z danymi z Search Console i Analytics, raportem odpowiedzi AI, cytowaniami z Binga,
    // botami AI z Cloudflare i dwoma konkurentami. Odpowiedzi usług pochodzą z plików testowych, więc na czas przykładu
    // udajemy podłączone klucze. Drugie pobranie mapy konkurenta pokazuje nową stronę.
    $seo = \App\Modules\Seo\Sites::add($org, 'sklep-przykladowy.example');
    if ($seo['ok']) {
        $saved = ['GOOGLE_SERVICE_ACCOUNT' => \App\Core\Config::get('GOOGLE_SERVICE_ACCOUNT'), 'CF_ANALYTICS_TOKEN' => \App\Core\Config::get('CF_ANALYTICS_TOKEN'), 'FIXTURE_SEO' => \App\Core\Config::get('FIXTURE_SEO')];
        $pem = '';
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem);
        \App\Core\Config::set('GOOGLE_SERVICE_ACCOUNT', (string) json_encode(['client_email' => 'panel@przyklad.iam.gserviceaccount.com', 'private_key' => $pem]));
        \App\Core\Config::set('CF_ANALYTICS_TOKEN', 'przyklad');
        $find = static fn (): array => \App\Modules\Seo\Sites::find($org, (int) $seo['id']);
        \App\Modules\Seo\Sync::google($find());
        \App\Modules\Seo\Sync::cloudflare($find());
        $end = gmdate('Y-m-d', time() - 3 * 86400);
        $from = gmdate('Y-m-d', time() - 30 * 86400);
        $aiDays = "Date,Impressions\n";
        for ($i = 55; $i >= 0; $i--) {
            $aiDays .= gmdate('Y-m-d', strtotime($end . ' UTC') - $i * 86400) . ',' . (14 + intdiv(55 - $i, 4) + ($i % 3)) . "\n";
        }
        $aiSets = \App\Modules\Seo\GscFile::parseFiles([
            'Pages.csv' => "Top pages,Impressions\nhttps://sklep-przykladowy.example/poradnik/odkamienianie,340\nhttps://sklep-przykladowy.example/poradnik/ekspres-cisnieniowy-do-domu,212\nhttps://sklep-przykladowy.example/ranking-ekspresow,95\n",
            'Dates.csv' => $aiDays,
        ]);
        \App\Modules\Seo\GscFile::store($find(), $aiSets['sets']);
        \App\Modules\Seo\AiSources::import($find(), 'bing_page', "https://sklep-przykladowy.example/poradnik/odkamienianie\t310\nhttps://sklep-przykladowy.example/poradnik/ekspres-cisnieniowy-do-domu\t74\nhttps://sklep-przykladowy.example/ranking-ekspresow\t28\n", $from, $end, 412);
        \App\Modules\Seo\AiSources::import($find(), 'bing_query', "jak odkamienić ekspres kolbowy\t140\nodkamieniacz do ekspresu czym zastąpić\t61\nekspres ciśnieniowy do domu jaki wybrać\t52\nranking ekspresów kolbowych\t19\n", $from, $end);
        \App\Modules\Seo\Sites::saveRivals($find(), "konkurent-a.example\nkonkurent-b.example");
        \App\Modules\Seo\Rivals::refreshAll($find(), true);
        $db->run('UPDATE seo_rival_pages SET first_seen = ? WHERE site_id = ?', [gmdate('Y-m-d H:i:s', time() - 9 * 86400), (int) $seo['id']]);
        \App\Core\Config::set('FIXTURE_SEO', 'mapa-2');
        \App\Modules\Seo\Rivals::refresh($find(), 'konkurent-a.example', true);
        foreach ($saved as $k => $v) {
            \App\Core\Config::set($k, $v);
        }
        // Raport miesięczny za poprzedni miesiąc z notatkami agencji i trzy monitory opisane jednym zdaniem.
        $rep = \App\Modules\Seo\MonthlyReport::create($find(), \App\Modules\Seo\MonthlyReport::previousMonth(), $uid);
        if ($rep['ok']) {
            $db->update('seo_reports', [
                'done_text' => "Poprawiliśmy tytuły i opisy 12 stron poradnika (przykład).\nDodaliśmy sekcję pytań i odpowiedzi na stronie o odkamienianiu.\nNaprawiliśmy 4 przekierowania po zmianie adresów kategorii.",
                'next_text' => "Nowy poradnik o młynkach do kawy pod zapytania, które rosną w Search Console.\nTest dwóch wersji tytułu strony rankingu ekspresów.",
            ], 'id = ?', [(int) $rep['id']]);
        }
        $orgRow = $db->one('SELECT * FROM organizations WHERE id = ?', [$org]);
        foreach ([
            ['Powiadom mnie, gdy kliknięcia z Google spadną o 30%.', 'gsc_clicks', ['site_id' => (int) $seo['id'], 'pct' => 30]],
            ['Daj znać, gdy przez 5 dni nie przyjdzie żadne zapytanie ze strony.', 'inbound_silence', ['days' => 5]],
            ['Powiadom o przetargach na „nawierzchni” od 100 tys. zł.', 'tender', ['phrase' => 'nawierzchni', 'min_value' => 100000]],
        ] as [$sentence, $kind, $params]) {
            $m = \App\Modules\Notify\Monitors::create($orgRow, $uid, $sentence, $kind, $params, 'panel');
            if ($m['ok'] && $kind !== 'tender') {
                \App\Modules\Notify\Monitors::run(\App\Modules\Notify\Monitors::find($org, (int) $m['id']));
            }
        }
    }

    // Plany zamówień: plan i zapowiedź z zapisanych odpowiedzi BZP i TED oraz przykładowy plan powiatu z Mazowsza.
    \App\Modules\Tenders\BzpClient::$pauseUs = 0;
    \App\Modules\Tenders\PlanImporter::run(gmdate('Y') . '-01-01 00:00:00');
    \App\Modules\Tenders\BzpClient::$pauseUs = 150000;
    $y = (int) gmdate('Y');
    $q = intdiv((int) gmdate('n') - 1, 3) + 1;
    $planItems = [
        ['1.1.1', 'Przebudowa drogi powiatowej nr 2201W wraz z budową chodnika (przykład)', 2400000, max(1, $q - 1)],
        ['1.1.2', 'Remont nawierzchni dróg powiatowych po zimie (przykład)', 1100000, $q],
        ['1.1.3', 'Budowa parkingu przy szpitalu powiatowym (przykład)', 780000, min(4, $q + 1)],
        ['1.2.1', 'Dostawa soli drogowej do zimowego utrzymania dróg (przykład)', 300000, $q],
    ];
    \App\Modules\Tenders\Plans::save([
        'source' => 'bzp', 'plan_key' => $y . '/BZP 00000001 (przykład)', 'notice_number' => $y . '/BZP 00000001/02/P (przykład)', 'version' => 2, 'year' => $y,
        'buyer_name' => 'Powiat Przykładowy', 'buyer_nip' => '9999999999', 'buyer_city' => 'Przykładowo', 'voivodeship' => 'mazowieckie', 'url' => null,
        'published_at' => $y . '-01-28 09:00:00',
    ], array_map(static fn (array $i): array => [
        'position' => $i[0], 'section' => 'below', 'category' => str_starts_with($i[0], '1.2') ? 'supplies' : 'works', 'subject' => $i[1],
        'mode' => 'Tryb podstawowy na podstawie: art. 275 pkt 1 ustawy', 'value_estimated' => $i[2], 'currency' => 'PLN', 'term' => ['', 'I', 'II', 'III', 'IV'][$i[3]] . ' kwartał',
        'quarter' => $i[3], 'month' => null, 'info' => "Zamówienie jest odpowiednie dla MŚP: TAK", 'sme' => 1, 'update_note' => '', 'status' => 'active',
    ], $planItems));
    \App\Modules\Tenders\PlanMatcher::run($org);

    // Firmy z dotacjami UE z zapisanego pliku listy projektów.
    \App\Modules\Outreach\EuProjects::sync(true);

    // Kampania e-mail w przygotowaniu: odbiorcy z kontaktów, wersje z faktów.
    $orgRow = $db->one('SELECT * FROM organizations WHERE id = ?', [$org]);
    $userRow = $db->one('SELECT * FROM users WHERE id = ?', [$uid]);
    $campaignId = \App\Modules\Outreach\Campaigns::create($org, $uid, 'Przegląd nawierzchni przed zimą (przykład)', 'Przegląd nawierzchni dla {firma}',
        "{powitanie}\n\n{powod}\n\nPrzed zimą robimy bezpłatny przegląd parkingów i dróg dojazdowych: w godzinę oceniamy ubytki i spękania, a w raporcie podajemy koszt naprawy.\n\nCzy pasuje Państwu termin w przyszłym tygodniu?\n\nZ poważaniem\n{podpis}");
    $contactIds = array_map('intval', array_column($db->all('SELECT id FROM contacts WHERE org_id = ?', [$org]), 'id'));
    \App\Modules\Outreach\Campaigns::setRecipients($org, \App\Modules\Outreach\Campaigns::find($org, $campaignId), $contactIds, $orgRow, $userRow);

    return ['ok' => true, 'org_id' => $org, 'login' => 'demo@example.com / demo-haslo-123'];
}
