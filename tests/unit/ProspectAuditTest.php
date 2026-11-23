<?php

use App\Core\Db;
use App\Modules\Outreach\Consents;
use App\Modules\Outreach\ProspectAudit;
use App\Modules\Outreach\Prospects;
use App\Modules\Outreach\RiskPolicy;

/** Firma testowa z jedną firmą na liście (z adresem z rejestrów i stroną internetową). */
$setup = static function (string $email = 'wlasciciel@example.com', string $name = 'Agencja Testowa sp. z o.o.'): array {
    [$orgId, $uid] = make_org($email, $name);
    $db = Db::get();
    $db->update('organizations', ['nip' => '5260000005', 'address' => 'ul. Nadawcy 5, 00-002 Warszawa'], 'id = ?', [$orgId]);
    login_as($uid);
    http('POST', '/klienci/potencjalni/lista', ['lines' => '1234563218 Przykładowa Budowlana']);
    Prospects::enrichPending($orgId);
    $id = (int) $db->val('SELECT id FROM prospects WHERE org_id = ? ORDER BY id DESC', [$orgId]);
    $db->update('prospects', ['website' => 'https://przykladowa.example', 'host' => 'przykladowa.example'], 'id = ?', [$id]);
    return [$orgId, $uid, $id, '/klienci/potencjalni/' . $id];
};
/** Gotowy audyt w formacie silnika: dwie rzeczy do poprawy, jedna dotycząca bezpieczeństwa i jedna w porządku. */
$audit = static function (int $orgId, int $uid, int $score = 41): int {
    $result = ['scanner_version' => '1.3.0', 'input' => 'przykladowa.example', 'host' => 'przykladowa.example', 'scanned_at' => gmdate('c'), 'final_url' => 'https://przykladowa.example/',
        'score' => $score, 'grade' => 'poor', 'categories' => ['seo' => ['label' => 'SEO', 'score' => 40], 'speed' => ['label' => 'Szybkość', 'score' => 62]],
        'counts' => ['critical' => 2, 'warning' => 1, 'info' => 0, 'ok' => 1], 'services' => [], 'psi_done' => false, 'meta' => ['prestashop' => ['is' => false]],
        'findings' => [
            ['id' => 'sec', 'cat' => 'seo', 'sev' => 'critical', 'title' => 'Panel administracyjny jest widoczny publicznie', 'detail' => 'Adres panelu odpowiada bez ograniczeń.', 'fix' => 'Ogranicz dostęp.', 'service' => null, 'points' => 9, 'sensitive' => true],
            ['id' => 'desc', 'cat' => 'seo', 'sev' => 'critical', 'title' => 'Brak opisu strony <b>meta</b>.', 'detail' => 'Wyszukiwarka sama wybiera fragment.', 'fix' => 'Dodaj opis do 155 znaków.', 'service' => 'seo', 'points' => 6],
            ['id' => 'img', 'cat' => 'speed', 'sev' => 'warning', 'title' => 'Zdjęcia bez nowoczesnego formatu', 'detail' => 'Strona waży 4 MB.', 'fix' => 'Zapisz zdjęcia jako WebP.', 'service' => 'speed', 'points' => 4],
            ['id' => 'tls', 'cat' => 'seo', 'sev' => 'ok', 'title' => 'Strona działa po HTTPS', 'detail' => '', 'fix' => null, 'service' => null, 'points' => 0],
        ]];
    return Db::get()->insert('site_audits', ['org_id' => $orgId, 'url' => 'https://przykladowa.example/', 'host' => 'przykladowa.example', 'score' => $score,
        'result_json' => json_out($result), 'created_by' => $uid, 'created_at' => now_utc()]);
};
$guest = static function (callable $fn) {
    $saved = $_SESSION;
    $_SESSION = [];
    try {
        return $fn();
    } finally {
        $_SESSION = $saved;
    }
};

return [
    'Audyt strony jako zachęta: wynik w ofercie i liście, raport po prośbie firmy, zgoda na ofertę osobno' => function () use ($setup, $audit, $guest) {
        [$orgId, $uid, $id, $url] = $setup();
        $db = Db::get();
        $find = static fn () => $db->one('SELECT * FROM prospects WHERE id = ?', [$id]);

        // Bez audytu karta proponuje go zrobić, a strona zgody pyta tylko o ofertę.
        $card = http('GET', $url)->body;
        contains('Audyt strony jako zachęta', $card);
        contains('Zrób audyt strony', $card);
        contains('Zrób audyt strony firmy (sekcja „Strona firmy”)', $card);
        eq(null, ProspectAudit::report($find()));
        $code = (string) $db->val('SELECT consent_code FROM prospects WHERE id = ?', [$id]);
        $page = $guest(fn () => http('GET', '/z/' . $code))->body;
        contains('Zgoda na ofertę e-mailem', $page);
        ok(!str_contains($page, 'want_report') && !str_contains($page, 'Raport z audytu'), 'bez audytu nie ma mowy o raporcie');

        // Audyt przypięty do firmy: token raportu, punkty za powód kontaktu, wynik w powodach i w treści oferty.
        $aid = $audit($orgId, $uid);
        ok(ProspectAudit::attach($orgId, $find(), $aid), 'audyt przypięty');
        $report = ProspectAudit::report($find(), 'http://localhost');
        eq([41, 'przykladowa.example', 3], [$report['score'], $report['host'], $report['issues']]);
        eq(['Brak opisu strony <b>meta</b>', 'Zdjęcia bez nowoczesnego formatu'], $report['top'], 'ustalenie o bezpieczeństwie nie trafia do listu');
        ok(preg_match('/^[a-f0-9]{40}$/', $report['token']) === 1, 'losowy token raportu');
        eq('http://localhost/raport/' . $report['token'], $report['url']);
        $rows = json_in($find()['score_json']);
        eq([20, 'audyt strony: 41 na 100 punktów'], [$rows[4]['points'], $rows[4]['note']]);
        $card = http('GET', $url)->body;
        contains('Audyt strony: 41 na 100 punktów', $card);
        contains('<code class="copy wrap" title="Kliknij, żeby skopiować">http://localhost/raport/' . $report['token'] . '</code>', $card);
        contains('Strona przykladowa.example: w naszym audycie technicznym uzyskała 41 na 100 punktów.', $card);
        contains('List obiecuje raport z audytu strony.', $card);
        contains('Brak opisu strony &lt;b&gt;meta&lt;/b&gt;', $card);
        ok(!str_contains($card, '<b>meta</b>') && !str_contains($card, 'Panel administracyjny'), 'tytuły z cudzej strony jako tekst, bez ustaleń o bezpieczeństwie');

        // List: temat, wynik w treści, obietnica raportu i jasna informacja, że oferta wymaga osobnej zgody.
        $letter = html_entity_decode(http('GET', $url . '/list')->body);
        contains('Dotyczy: audyt Państwa strony i propozycja współpracy', $letter);
        contains('sprawdziliśmy Państwa stronę przykladowa.example. W naszym audycie technicznym uzyskała 41 na 100 punktów. Do poprawy są między innymi: „Brak opisu strony <b>meta</b>”, „Zdjęcia bez nowoczesnego formatu”.', $letter);
        contains('Pełny raport z audytu strony przykladowa.example przygotowaliśmy bezpłatnie: lista rzeczy do poprawy z opisem, jak to zrobić. Wyślemy go e-mailem na Państwa prośbę. Wystarczy wejść na stronę http://localhost/z/' . $code, $letter);
        contains('Ofertę prześlemy tylko wtedy, gdy zaznaczą tam Państwo zgodę.', $letter);
        ok(!str_contains($letter, 'Panel administracyjny') && !str_contains($letter, $report['token']), 'list nie zdradza ustaleń o bezpieczeństwie ani adresu raportu');

        // Strona zgody: obiecuje raport, ale go nie pokazuje. Prośba o raport i zgoda na ofertę to dwa pola, drugie nieobowiązkowe.
        $page = $guest(fn () => http('GET', '/z/' . $code))->body;
        contains('Raport z audytu strony przykladowa.example', $page);
        contains('<strong>41 na 100 punktów</strong>', $page);
        contains('Proszę o przesłanie raportu z audytu strony przykladowa.example na podany adres e-mail.', $page);
        contains('name="want_report" value="1" required>', $page, 'pole prośby o raport nie jest zaznaczone z góry');
        contains('name="agree" value="1"> <span>Zgadzam się na przesłanie przez firmę Agencja Testowa sp. z o.o. oferty handlowej na podany adres e-mail. To pole nie jest wymagane.', $page);
        contains('Pokaż i wyślij mi raport', $page);
        ok(!str_contains($page, $report['token']), 'przed prośbą strona nie zdradza adresu raportu');
        $bad = $guest(fn () => http('POST', '/z/' . $code, ['_csrf' => 'brak', 'email' => 'biuro@przykladowa.example']));
        eq(422, $bad->status);
        contains('Zaznacz, że chcą Państwo dostać raport.', $bad->body);

        // Sama prośba o raport: firma dostaje raport od razu i e-mailem, ale kontakt nie ma podstawy do ofert.
        $sent = $guest(fn () => http('POST', '/z/' . $code, ['_csrf' => 'brak', 'email' => 'Biuro@Przykladowa.example', 'want_report' => '1', 'agree_phone' => '1', 'phone' => '600 700 800']));
        eq(200, $sent->status);
        contains('Raport jest gotowy', $sent->body);
        contains('href="http://localhost/raport/' . $report['token'] . '"', $sent->body);
        contains('Chcą Państwo dostać też ofertę?', $sent->body);
        ok(!str_contains($sent->body, 'want_report'), 'po prośbie formularz pyta już tylko o ofertę');
        $c = $db->one('SELECT * FROM consents');
        eq(['report', 'biuro@przykladowa.example', null], [$c['channels'], $c['email'], $c['phone']], 'telefon bez zgody na ofertę nie jest zapisywany');
        eq('Proszę o przesłanie raportu z audytu strony przykladowa.example na podany adres e-mail.', $c['wording']);
        $contact = $db->one('SELECT * FROM contacts');
        eq(['none', null], [$contact['basis'], $contact['marketing_consent_at']]);
        contains('Zgody na ofertę nie zaznaczyła.', $contact['notes']);
        eq('very_high', RiskPolicy::assess('cold_offer', $contact)['level'], 'prośba o raport nie obniża ryzyka oferty');
        $facts = Prospects::facts($find());
        eq(['biuro@przykladowa.example', false], [$facts['report_request']['email'], isset($facts['consent'])]);
        eq(null, Consents::latest($orgId, $id));
        eq([], Consents::waiting($orgId));
        $receipt = $db->one("SELECT * FROM outbox WHERE kind = 'consent_receipt'");
        eq(['biuro@przykladowa.example', 'Raport z audytu strony przykladowa.example'], [$receipt['recipient'], $receipt['subject']]);
        contains('jest tutaj: http://localhost/raport/' . $report['token'], $receipt['body']);
        contains('Zgody na ofertę Państwo nie zaznaczyli, więc nie wyślemy żadnej innej wiadomości.', $receipt['body']);
        contains('adres można wypisać tutaj: http://localhost/wypisz/', $receipt['body']);
        $team = $db->one("SELECT * FROM outbox WHERE kind = 'consent'");
        contains('Prośba o raport z audytu: ', $team['subject']);
        contains('Oferta e-mailem nadal wymaga zgody.', $team['body']);
        eq(1, (int) $db->val("SELECT COUNT(*) FROM audit_log WHERE action = 'report.requested' AND user_id IS NULL"));
        $card = http('GET', $url)->body;
        contains('Firma poprosiła o raport z audytu', $card);
        contains('To nie jest zgoda na ofertę.', $card);
        ok(!str_contains($card, 'masz zgodę'), 'bez zgody na ofertę nie ma drogi ze zgodą');
        contains('poprosiła o raport', http('GET', '/klienci/potencjalni', [], ['stan' => 'picked'])->body);

        // Raport: bez logowania i plików cookie, poza wyszukiwarkami, z opisem poprawek i drogą do zgody na ofertę.
        $rep = $guest(fn () => http('GET', '/raport/' . $report['token']));
        eq(200, $rep->status);
        eq('noindex, nofollow', $rep->headers['X-Robots-Tag']);
        ok(!isset($rep->headers['Set-Cookie']), 'raport nie ustawia plików cookie');
        contains('przygotowała firma Agencja Testowa sp. z o.o.', $rep->body);
        contains('Brak opisu strony &lt;b&gt;meta&lt;/b&gt;.', $rep->body);
        contains('Dodaj opis do 155 znaków.', $rep->body);
        contains('Panel administracyjny jest widoczny publicznie', $rep->body, 'właściciel strony widzi w raporcie także bezpieczeństwo');
        contains('Strona działa po HTTPS', $rep->body);
        contains('href="/z/' . $code . '">Poproś o ofertę</a>', $rep->body);
        ok(!str_contains($rep->body, '<b>meta</b>') && !str_contains($rep->body, 'Zaloguj'), 'tytuły jako tekst, bez logowania');
        eq(404, $guest(fn () => http('GET', '/raport/' . str_repeat('a', 40)))->status);
        eq(404, $guest(fn () => http('GET', '/raport/zly-token'))->status);

        // Firma wraca i zaznacza zgodę na ofertę: nowy dowód, kontakt z podstawą „zgoda”, oferta z adresem raportu.
        $back = $guest(fn () => http('GET', '/z/' . $code))->body;
        contains('Otwórz raport z audytu strony', $back);
        $yes = $guest(fn () => http('POST', '/z/' . $code, ['_csrf' => 'brak', 'email' => 'biuro@przykladowa.example', 'agree' => '1']));
        contains('Dziękujemy, zgoda zapisana', $yes->body);
        eq(['report', 'email'], array_column($db->all('SELECT channels FROM consents ORDER BY id'), 'channels'));
        eq('consent', $db->val('SELECT basis FROM contacts'));
        eq(1, (int) $db->val('SELECT COUNT(*) FROM contacts'), 'ten sam kontakt, bez duplikatu');
        $again = $guest(fn () => http('POST', '/z/' . $code, ['_csrf' => 'brak', 'email' => 'biuro@przykladowa.example', 'agree' => '1']));
        eq(2, (int) $db->val('SELECT COUNT(*) FROM consents'), 'powtórka nie tworzy trzeciego dowodu');
        ok(!str_contains($guest(fn () => http('GET', '/raport/' . $report['token']))->body, 'Poproś o ofertę'), 'po zgodzie raport nie prosi o nią drugi raz');
        $cid = (int) $db->val('SELECT id FROM contacts');
        $compose = html_entity_decode(http('GET', '/klienci/' . $cid . '/wiadomosc', [], ['kind' => 'consented'])->body);
        contains('Pełny raport z audytu strony przykladowa.example: http://localhost/raport/' . $report['token'], $compose);
        contains('sprawdziliśmy Państwa stronę przykladowa.example.', $compose);
        ok(!str_contains(html_entity_decode(http('GET', $url . '/list')->body), 'Pełny raport z audytu'), 'list do firmy ze zgodą nie obiecuje raportu drugi raz');

        // Wycofanie zgody w panelu czyści też prośbę o raport z karty.
        http('POST', $url . '/zgoda', ['withdraw' => '1']);
        $facts = Prospects::facts($find());
        ok(!isset($facts['consent']) && !isset($facts['report_request']), 'po wycofaniu karta nie pokazuje zgody ani prośby');
        eq(0, (int) $db->val('SELECT COUNT(*) FROM consents WHERE withdrawn_at IS NULL'));
    },
    'Audyt strony: raport i zgoda naraz, odpięcie raportu, błąd audytu i rozdzielenie firm' => function () use ($setup, $audit, $guest) {
        [$orgId, $uid, $id, $url] = $setup();
        $db = Db::get();
        $find = static fn () => $db->one('SELECT * FROM prospects WHERE id = ?', [$id]);

        // Strona, której silnik nie może sprawdzić: czytelny komunikat, nic się nie przypina. Bez adresu strony też.
        $db->update('prospects', ['website' => 'http://127.0.0.1/'], 'id = ?', [$id]);
        eq(303, http('POST', $url . '/audyt')->status);
        eq(null, $find()['audit_id']);
        $db->update('prospects', ['website' => null], 'id = ?', [$id]);
        http('POST', $url . '/audyt');
        contains('Najpierw wpisz adres strony firmy.', http('GET', $url)->body);
        $db->update('prospects', ['website' => 'https://przykladowa.example'], 'id = ?', [$id]);

        // Dobry wynik: mniej punktów za powód kontaktu, a zdanie nie wymyśla rzeczy do poprawy.
        $good = $db->insert('site_audits', ['org_id' => $orgId, 'url' => 'https://przykladowa.example/', 'host' => 'przykladowa.example', 'score' => 93, 'created_by' => $uid, 'created_at' => now_utc(),
            'result_json' => json_out(['host' => 'przykladowa.example', 'final_url' => 'https://przykladowa.example/', 'scanned_at' => gmdate('c'), 'score' => 93, 'grade' => 'good', 'categories' => [],
                'counts' => [], 'services' => [], 'psi_done' => false, 'meta' => ['prestashop' => ['is' => false]], 'findings' => []])]);
        ProspectAudit::attach($orgId, $find(), $good);
        $r = ProspectAudit::report($find());
        eq('W naszym audycie technicznym uzyskała 93 na 100 punktów.', ProspectAudit::sentence($r));
        eq([0, 'audyt strony: 93 na 100, niewiele do poprawy'], [json_in($find()['score_json'])[4]['points'], json_in($find()['score_json'])[4]['note']]);
        contains('Nie znaleźliśmy rzeczy do poprawy.', $guest(fn () => http('GET', '/raport/' . $r['token']))->body);

        // Nowy audyt zastępuje stary. Prośba o raport i zgoda na ofertę w jednym kroku: jeden dowód z obiema treściami.
        ProspectAudit::attach($orgId, $find(), $audit($orgId, $uid, 72));
        $report = ProspectAudit::report($find(), 'http://localhost');
        eq(72, $report['score']);
        eq(10, json_in($find()['score_json'])[4]['points']);
        http('GET', $url);
        $code = (string) $find()['consent_code'];
        $both = $guest(fn () => http('POST', '/z/' . $code, ['_csrf' => 'brak', 'email' => 'zakupy@przykladowa.example', 'want_report' => '1', 'agree' => '1', 'agree_phone' => '1', 'phone' => '600 700 800']));
        contains('Dziękujemy, zgoda zapisana', $both->body);
        contains('href="http://localhost/raport/' . $report['token'] . '"', $both->body);
        $c = $db->one('SELECT * FROM consents');
        eq('report,email,phone', $c['channels']);
        contains('Proszę o przesłanie raportu z audytu strony przykladowa.example na podany adres e-mail. Zgadzam się na przesłanie przez firmę Agencja Testowa sp. z o.o. oferty handlowej', $c['wording']);
        eq('consent', $db->val('SELECT basis FROM contacts'));
        $receipt = (string) $db->val("SELECT body FROM outbox WHERE kind = 'consent_receipt'");
        contains('jest tutaj: http://localhost/raport/' . $report['token'], $receipt);
        contains('Potwierdzamy też zapisanie zgody na przesłanie oferty firmy Agencja Testowa sp. z o.o.', $receipt);
        contains('zgodę można wycofać tutaj: http://localhost/wypisz/', $receipt);
        contains('Zgoda na ofertę e-mailem: ', (string) $db->val("SELECT subject FROM outbox WHERE kind = 'consent'"));
        eq(1, count(Consents::waiting($orgId)));

        // Druga firma w panelu nie przypnie cudzego audytu, nie uruchomi go u cudzej firmy i nie odepnie raportu.
        [$org2, $uid2, $id2, $url2] = $setup('druga@example.com', 'Druga Agencja sp. z o.o.');
        eq(404, http('POST', $url . '/audyt')->status);
        eq(404, http('POST', $url . '/audyt', ['action' => 'drop'])->status);
        ok(!ProspectAudit::attach($org2, $db->one('SELECT * FROM prospects WHERE id = ?', [$id2]), (int) $find()['audit_id']), 'cudzy audyt się nie przypina');
        eq(null, $db->val('SELECT audit_id FROM prospects WHERE id = ?', [$id2]));
        ok($find()['audit_id'] !== null, 'raport pierwszej firmy został');

        // Odpięcie raportu: adres przestaje działać, a list i strona zgody wracają do samej oferty.
        login_as($uid);
        eq(303, http('POST', $url . '/audyt', ['action' => 'drop'])->status);
        eq(null, ProspectAudit::report($find()));
        eq(404, $guest(fn () => http('GET', '/raport/' . $report['token']))->status);
        http('POST', $url . '/zgoda', ['withdraw' => '1']);
        $page = $guest(fn () => http('GET', '/z/' . $code))->body;
        contains('Zgoda na ofertę e-mailem', $page);
        ok(!str_contains($page, 'want_report'), 'bez raportu strona pyta tylko o ofertę');
        $letter = html_entity_decode(http('GET', $url . '/list')->body);
        ok(!str_contains($letter, 'Pełny raport z audytu') && !str_contains($letter, 'Dotyczy: audyt') && !str_contains($letter, 'audycie technicznym'), 'list bez raportu nie wspomina o audycie');
        contains('Dotyczy: propozycja współpracy', $letter);
    },
];
