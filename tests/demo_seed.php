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
    }

    $db->insert('contacts', ['org_id' => $org, 'company_name' => 'Przykładowa Firma Instalacyjna sp. z o.o.', 'person' => 'Piotr Przykładowy', 'email' => 'zamowienia@example.com',
        'basis' => 'rfq', 'basis_detail' => 'zapytanie ofertowe „Utwardzenie placu manewrowego” na stronie firmy', 'basis_at' => date('Y-m-d', strtotime('-4 days')), 'created_at' => now_utc()]);
    $db->insert('contacts', ['org_id' => $org, 'company_name' => 'Przykładowy Deweloper sp. z o.o.', 'email' => 'biuro@example.org', 'basis' => 'none', 'website' => 'https://example.org', 'created_at' => now_utc()]);
    $db->insert('contacts', ['org_id' => $org, 'company_name' => 'Spółdzielnia Mieszkaniowa (przykład)', 'person' => 'Ewa Przykładowa', 'email' => 'zarzad@example.net', 'basis' => 'client', 'basis_detail' => 'remont parkingu w 2025 r., prośba o kolejne oferty', 'created_at' => now_utc()]);
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

    return ['ok' => true, 'org_id' => $org, 'login' => 'demo@example.com / demo-haslo-123'];
}
