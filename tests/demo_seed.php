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
