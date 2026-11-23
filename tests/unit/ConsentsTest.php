<?php

use App\Core\Db;
use App\Core\Kv;
use App\Modules\Notify\Digest;
use App\Modules\Outreach\Consents;
use App\Modules\Outreach\Prospects;
use App\Modules\Outreach\RiskPolicy;

/** Firma testowa z jedną firmą na liście potencjalnych klientów (z danymi z rejestrów, więc z adresem do listu). */
$setup = static function (string $email = 'wlasciciel@example.com', string $name = 'Firma Testowa sp. z o.o.'): array {
    [$orgId, $uid] = make_org($email, $name);
    $db = Db::get();
    $db->update('organizations', ['nip' => '5260000005', 'address' => 'ul. Nadawcy 5, 00-002 Warszawa'], 'id = ?', [$orgId]);
    login_as($uid);
    http('POST', '/klienci/potencjalni/lista', ['lines' => '1234563218 Przykładowa Budowlana']);
    Prospects::enrichPending($orgId);
    $id = (int) $db->val('SELECT id FROM prospects WHERE org_id = ? ORDER BY id DESC', [$orgId]);
    return [$orgId, $uid, $id, '/klienci/potencjalni/' . $id];
};
/** Wejście na stronę zgody tak jak firma z listu: bez logowania. */
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
    'Strona zgody: firma sama zostawia e-mail i zgodę, panel zapisuje dowód i oferta ma podstawę' => function () use ($setup, $guest) {
        [$orgId, $uid, $id, $url] = $setup();
        $db = Db::get();

        // Karta pokazuje drogę: list z adresem strony zgody. Zimny e-mail nadal jest na końcu z bardzo wysokim ryzykiem.
        $card = http('GET', $url)->body;
        contains('Wyślij list pocztą z prośbą o zgodę na e-mail', $card);
        $code = (string) $db->val('SELECT consent_code FROM prospects WHERE id = ?', [$id]);
        ok(preg_match('/^[a-z2-9]{10}$/', $code) === 1, 'kod bez znaków łatwych do pomylenia');
        contains('<code class="copy" title="Kliknij, żeby skopiować">http://localhost/z/' . $code . '</code>', $card);
        contains('Wysłany e-mailem albo SMS-em byłby prośbą o zgodę bez zgody.', $card);
        contains('Wyślij ofertę e-mailem bez zgody', $card);
        ok(!str_contains($card, 'Wyślij ofertę e-mailem: masz zgodę'), 'bez zgody nie ma drogi ze zgodą');
        http('GET', $url);
        eq($code, (string) $db->val('SELECT consent_code FROM prospects WHERE id = ?', [$id]), 'kod się nie zmienia, bo jest wydrukowany w liście');

        // Strona zgody: bez logowania, bez sesji i plików cookie, poza wyszukiwarkami. Mówi, kto pyta i o co.
        $page = $guest(fn () => http('GET', '/z/' . $code));
        eq(200, $page->status);
        eq('noindex, nofollow', $page->headers['X-Robots-Tag']);
        eq('private, no-store', $page->headers['Cache-Control']);
        ok(!isset($page->headers['Set-Cookie']), 'strona zgody nie ustawia plików cookie');
        contains('Zgoda na ofertę e-mailem', $page->body);
        contains('Firma <strong>Firma Testowa sp. z o.o.</strong> wysłała do Państwa list', $page->body);
        contains('Zgadzam się na przesłanie przez firmę Firma Testowa sp. z o.o. oferty handlowej na podany adres e-mail.', $page->body);
        contains('Administratorem danych jest Firma Testowa sp. z o.o., ul. Nadawcy 5, 00-002 Warszawa, NIP 526-000-00-05', $page->body);
        contains('art. 6 ust. 1 lit. a RODO', $page->body);
        contains('Nie chcemy korespondencji od tej firmy', $page->body);
        ok(!str_contains($page->body, 'Zaloguj') && !str_contains($page->body, '_csrf'), 'bez logowania i bez żetonu sesji');

        // Błędy: zły adres, brak zaznaczonej zgody, zgoda na telefon bez numeru. Nic się nie zapisuje.
        $bad = $guest(fn () => http('POST', '/z/' . $code, ['_csrf' => 'brak', 'email' => 'to-nie-adres', 'agree' => '1']));
        eq(422, $bad->status);
        contains('Wpisz poprawny adres e-mail.', $bad->body);
        $bad = $guest(fn () => http('POST', '/z/' . $code, ['_csrf' => 'brak', 'email' => 'zakupy@przykladowa.example']));
        eq(422, $bad->status);
        contains('Zaznacz zgodę. Bez niej nie możemy wysłać oferty.', $bad->body);
        contains('value="zakupy@przykladowa.example"', $bad->body, 'wpisany adres zostaje w formularzu');
        $bad = $guest(fn () => http('POST', '/z/' . $code, ['_csrf' => 'brak', 'email' => 'zakupy@przykladowa.example', 'agree' => '1', 'agree_phone' => '1', 'phone' => '12']));
        contains('Żeby zgodzić się na telefon, wpisz numer.', $bad->body);
        eq(0, (int) $db->val('SELECT COUNT(*) FROM consents'));
        eq(0, (int) $db->val('SELECT COUNT(*) FROM contacts'));

        // Pole-pułapka dla automatów: strona udaje powodzenie i nic nie zapisuje.
        $bot = $guest(fn () => http('POST', '/z/' . $code, ['_csrf' => 'brak', 'email' => 'bot@spam.example', 'agree' => '1', 'strona_www' => 'http://spam.example']));
        contains('Dziękujemy, zgoda zapisana', $bot->body);
        eq(0, (int) $db->val('SELECT COUNT(*) FROM consents'));

        // Zgoda: dowód z dokładną treścią, kontakt z podstawą „zgoda”, firma wraca do wybranych, powiadomienie i potwierdzenie.
        $db->update('prospects', ['status' => 'contacted', 'contacted_how' => 'letter', 'contacted_at' => now_utc()], 'id = ?', [$id]);
        $done = $guest(fn () => http('POST', '/z/' . $code, ['_csrf' => 'brak', 'email' => ' Zakupy@Przykladowa.example ', 'person' => 'Anna <b>Nowak</b>', 'agree' => '1']));
        eq(200, $done->status);
        contains('Dziękujemy, zgoda zapisana', $done->body);
        contains('na adres z', $done->body);
        ok(!str_contains($done->body, 'zakupy@przykladowa.example'), 'strona nie powtarza pełnego adresu');
        $c = $db->one('SELECT * FROM consents');
        eq(['zakupy@przykladowa.example', 'email', 'page', $id], [$c['email'], $c['channels'], $c['source'], (int) $c['prospect_id']]);
        eq('Zgadzam się na przesłanie przez firmę Firma Testowa sp. z o.o. oferty handlowej na podany adres e-mail.', $c['wording']);
        ok($c['ip_hash'] !== null && !str_contains((string) $c['ip_hash'], '127.0.0.1'), 'adres IP tylko jako skrót');
        eq(null, $c['phone']);
        $contact = $db->one('SELECT * FROM contacts');
        eq(['zakupy@przykladowa.example', 'consent', '1234563218', 'Anna <b>Nowak</b>'], [$contact['email'], $contact['basis'], $contact['nip'], $contact['person']]);
        contains('Zgoda na stronie zgody z listu', $contact['basis_detail']);
        ok($contact['marketing_consent_at'] !== null, 'czas zgody na kontakcie');
        $p = $db->one('SELECT * FROM prospects WHERE id = ?', [$id]);
        eq(['picked', (int) $contact['id']], [$p['status'], (int) $p['contact_id']]);
        eq('zakupy@przykladowa.example', Prospects::facts($p)['consent']['email']);
        $team = $db->one("SELECT * FROM outbox WHERE kind = 'consent'");
        eq('wlasciciel@example.com', $team['recipient']);
        contains('Zgoda na ofertę e-mailem: ', $team['subject']);
        contains('http://localhost/klienci/potencjalni/' . $id, $team['body']);
        $receipt = $db->one("SELECT * FROM outbox WHERE kind = 'consent_receipt'");
        eq('zakupy@przykladowa.example', $receipt['recipient']);
        contains('potwierdzamy zapisanie zgody na przesłanie oferty firmy Firma Testowa sp. z o.o.', $receipt['body']);
        ok(preg_match('~zgodę można wycofać tutaj: http://localhost/wypisz/([a-f0-9]{40})~', $receipt['body'], $m) === 1, 'potwierdzenie z linkiem wycofania');
        ok(!str_contains($receipt['body'], 'Nasza oferta') && !str_contains($receipt['body'], 'kruszyw'), 'potwierdzenie nie jest ofertą');
        eq(1, (int) $db->val("SELECT COUNT(*) FROM audit_log WHERE org_id = ? AND action = 'consent.given' AND user_id IS NULL", [$orgId]));

        // Ponowne wysłanie formularza nie tworzy drugiego dowodu ani drugiej wiadomości.
        $again = $guest(fn () => http('POST', '/z/' . $code, ['_csrf' => 'brak', 'email' => 'zakupy@przykladowa.example', 'agree' => '1']));
        contains('Dziękujemy, zgoda zapisana', $again->body);
        eq(1, (int) $db->val('SELECT COUNT(*) FROM consents'));
        eq(1, (int) $db->val("SELECT COUNT(*) FROM outbox WHERE kind = 'consent_receipt'"));

        // Panel: karta pokazuje dowód i drogę o niskim ryzyku, lista ma oznaczenie i przypomnienie.
        $card = http('GET', $url)->body;
        contains('Wyślij ofertę e-mailem: masz zgodę', $card);
        contains('Firma sama wpisała adres zakupy@przykladowa.example na stronie zgody i zaznaczyła zgodę na ofertę.', $card);
        contains('Strona zgody z listu, ', $card);
        contains('Anna &lt;b&gt;Nowak&lt;/b&gt;', $card);
        ok(!str_contains($card, 'Anna <b>Nowak</b>'), 'dane wpisane przez firmę są zabezpieczone przed HTML');
        ok(!str_contains($card, 'Wyślij ofertę e-mailem bez zgody') && !str_contains($card, 'z prośbą o zgodę na e-mail'), 'ze zgodą nie ma zimnej wiadomości ani drugiej prośby');
        ok(strpos($card, 'Wyślij ofertę e-mailem: masz zgodę') < strpos($card, 'Wyślij list pocztą'), 'zgoda przed listem');
        $list = http('GET', '/klienci/potencjalni', [], ['stan' => 'picked'])->body;
        contains('zgoda na e-mail', $list);
        contains('1 firma zostawiła zgodę na ofertę e-mailem i czeka na wiadomość:', $list);
        ok(!str_contains(http('GET', $url . '/list')->body, '/z/' . $code), 'list do firmy ze zgodą nie prosi o nią drugi raz');

        // Wiadomość: rodzaj „oferta za zgodą”, niskie ryzyko, bez potwierdzania ryzyka. Po wysłaniu firma jest po kontakcie.
        $cid = (int) $contact['id'];
        $compose = http('GET', '/klienci/' . $cid . '/wiadomosc', [], ['kind' => 'consented'])->body;
        contains('Zgoda na informacje handlowe e-mailem', $compose);
        ok(!str_contains($compose, 'bardzo wysokie'), 'oferta za zgodą nie jest ryzykowna');
        eq('low', RiskPolicy::assess('consented', $db->one('SELECT * FROM contacts WHERE id = ?', [$cid]))['level']);
        eq('low', RiskPolicy::assess('cold_offer', $db->one('SELECT * FROM contacts WHERE id = ?', [$cid]))['level'], 'ta sama oferta bez wyboru rodzaju też ma podstawę');

        // Poranny przegląd wymienia nową zgodę.
        $mail = Digest::compose($db->one('SELECT * FROM organizations WHERE id = ?', [$orgId]), gmdate('Y-m-d H:i:s', time() - 3600), 'http://localhost');
        contains('ZGODY NA OFERTĘ E-MAILEM (1)', $mail['body']);
        contains('zakupy@przykladowa.example', $mail['body']);
        contains('http://localhost/klienci/potencjalni/' . $id, $mail['body']);
    },
    'Wycofanie zgody linkiem z wiadomości i odmowa na stronie zgody' => function () use ($setup, $guest) {
        [$orgId, $uid, $id, $url] = $setup();
        $db = Db::get();
        http('GET', $url);
        $code = (string) $db->val('SELECT consent_code FROM prospects WHERE id = ?', [$id]);
        $guest(fn () => http('POST', '/z/' . $code, ['_csrf' => 'brak', 'email' => 'zakupy@przykladowa.example', 'agree' => '1', 'agree_phone' => '1', 'phone' => '+48 600 700 800 wew. 5']));
        $c = $db->one('SELECT * FROM consents');
        eq(['email,phone', '+48 600 700 800  5'], [$c['channels'], $c['phone']]);
        contains('Zgadzam się na kontakt telefoniczny firmy Firma Testowa sp. z o.o.', $c['wording']);
        contains('zgoda także na telefon: +48 600 700 800', http('GET', $url)->body);

        // Link wycofania z potwierdzenia: zgoda dostaje datę wycofania, kontakt jest wypisany, karta nie proponuje e-maila.
        preg_match('~/wypisz/([a-f0-9]{40})~', (string) $db->val("SELECT body FROM outbox WHERE kind = 'consent_receipt'"), $m);
        $guest(fn () => http('POST', '/wypisz/' . $m[1], ['_csrf' => 'brak']));
        ok($db->val('SELECT withdrawn_at FROM consents') !== null, 'zgoda wycofana');
        ok($db->val('SELECT unsubscribed_at FROM contacts') !== null, 'kontakt wypisany');
        ok(!isset(Prospects::facts($db->one('SELECT * FROM prospects WHERE id = ?', [$id]))['consent']), 'karta nie pokazuje już zgody');
        $card = http('GET', $url)->body;
        contains('Firma zgłosiła sprzeciw', $card);
        ok(!str_contains($card, 'masz zgodę') && !str_contains($card, 'Przygotuj ofertę'), 'po wycofaniu nie ma oferty e-mailem');
        ok(!str_contains(http('GET', '/klienci/potencjalni', [], ['stan' => 'picked'])->body, 'czeka na wiadomość'), 'bez przypomnienia po wycofaniu');

        // Ten sam adres nie wróci przez formularz: wypisanie jest silniejsze. Ręcznie też się nie da.
        $back = $guest(fn () => http('POST', '/z/' . $code, ['_csrf' => 'brak', 'email' => 'zakupy@przykladowa.example', 'agree' => '1']));
        eq(422, $back->status);
        contains('Ten adres jest na liście rezygnacji.', $back->body);
        eq(1, (int) $db->val('SELECT COUNT(*) FROM consents'));
        http('POST', $url . '/zgoda', ['email' => 'zakupy@przykladowa.example', 'way' => 'letter_reply', 'detail' => 'Odpisali na list, że jednak chcą ofertę.', 'on' => today_pl(), 'confirm' => '1']);
        eq(1, (int) $db->val('SELECT COUNT(*) FROM consents'), 'wypisanego adresu nie nadpisze ręczny wpis');

        // Druga firma: odmowa na stronie zgody zapisuje sprzeciw. Panel nie przygotuje listu ani oferty.
        http('POST', '/klienci/potencjalni/lista', ['lines' => '7790000008 Inna Firma']);
        $id2 = (int) $db->val('SELECT id FROM prospects ORDER BY id DESC');
        $code2 = Consents::code($db->one('SELECT * FROM prospects WHERE id = ?', [$id2]));
        $no = $guest(fn () => http('POST', '/z/' . $code2, ['_csrf' => 'brak', 'action' => 'refuse']));
        contains('Zapisane: bez korespondencji', $no->body);
        eq('objected', $db->val('SELECT status FROM prospects WHERE id = ?', [$id2]));
        eq(1, (int) $db->val("SELECT COUNT(*) FROM audit_log WHERE action = 'prospect.objection' AND entity_id = ? AND user_id IS NULL", [(string) $id2]));
        eq(303, http('GET', '/klienci/potencjalni/' . $id2 . '/list')->status, 'po odmowie nie ma listu');
        // Użytkownik nie nadpisze sprzeciwu ręczną zgodą.
        http('POST', '/klienci/potencjalni/' . $id2 . '/zgoda', ['email' => 'biuro@inna.example', 'way' => 'in_person', 'detail' => 'Podobno zgodzili się na targach.', 'on' => today_pl(), 'confirm' => '1']);
        eq('objected', $db->val('SELECT status FROM prospects WHERE id = ?', [$id2]));
        eq(1, (int) $db->val('SELECT COUNT(*) FROM consents'));
        // Firma może zmienić zdanie tylko sama, na swojej stronie zgody.
        contains('Jeśli zmienią Państwo zdanie', $guest(fn () => http('GET', '/z/' . $code2))->body);
        $yes = $guest(fn () => http('POST', '/z/' . $code2, ['_csrf' => 'brak', 'email' => 'biuro@inna.example', 'agree' => '1']));
        contains('Dziękujemy, zgoda zapisana', $yes->body);
        eq('picked', $db->val('SELECT status FROM prospects WHERE id = ?', [$id2]));
    },
    'Zgoda zapisana ręcznie: wymaga opisu i potwierdzenia, a wycofanie odbiera podstawę' => function () use ($setup) {
        [$orgId, $uid, $id, $url] = $setup();
        $db = Db::get();
        $card = http('GET', $url)->body;
        contains('Firma zgodziła się inaczej', $card);
        contains('Sama wizytówka albo adres ze strony internetowej nie są zgodą.', $card);
        $ok = ['email' => 'Biuro@Przykladowa.example', 'person' => 'Jan Kowalski', 'way' => 'letter_reply', 'detail' => 'Pan Jan Kowalski odpisał na list: proszę o ofertę e-mailem.', 'on' => today_pl(), 'confirm' => '1'];

        // Bez potwierdzenia, bez sposobu, z za krótkim opisem albo z datą z przyszłości zgoda się nie zapisze.
        foreach ([['confirm' => ''], ['way' => 'zmyslony'], ['detail' => 'tak'], ['email' => 'zly'], ['on' => date('Y-m-d', time() + 3 * 86400)]] as $over) {
            eq(303, http('POST', $url . '/zgoda', $over + $ok)->status);
            eq(0, (int) $db->val('SELECT COUNT(*) FROM consents'), 'błędny wpis: ' . json_encode($over));
        }
        contains('Opisz zgodę: kto, kiedy i co dokładnie powiedział albo napisał.', (function () use ($url, $ok) {
            http('POST', $url . '/zgoda', ['detail' => 'tak'] + $ok);
            return http('GET', $url)->body;
        })());

        // Poprawny wpis: dowód z opisem i autorem, kontakt z podstawą „zgoda”, wpis w dzienniku.
        eq(303, http('POST', $url . '/zgoda', $ok)->status);
        $c = $db->one('SELECT * FROM consents');
        eq(['biuro@przykladowa.example', 'manual', $uid, 'Odpowiedź na nasz list'], [$c['email'], $c['source'], (int) $c['created_by'], $c['detail']]);
        contains('Odpowiedź na nasz list (' . date('d.m.Y', strtotime(today_pl())) . '): Pan Jan Kowalski odpisał na list', $c['wording']);
        $contact = $db->one('SELECT * FROM contacts');
        eq(['consent', 'Jan Kowalski'], [$contact['basis'], $contact['person']]);
        contains('Zgoda zapisana ręcznie.', $contact['basis_detail']);
        eq(1, (int) $db->val("SELECT COUNT(*) FROM audit_log WHERE action = 'consent.manual' AND user_id = ?", [$uid]));
        eq(0, (int) $db->val("SELECT COUNT(*) FROM outbox WHERE kind IN ('consent', 'consent_receipt')"), 'ręczny wpis nie wysyła wiadomości do firmy');
        $card = http('GET', $url)->body;
        contains('Zapisałeś zgodę firmy na ofertę pod adresem biuro@przykladowa.example.', $card);
        contains('Zapisane ręcznie, ', $card);
        contains('Firma wycofała zgodę', $card);
        ok(!str_contains($card, 'Firma zgodziła się inaczej'), 'formularz znika, gdy zgoda jest zapisana');

        // Wycofanie bez sprzeciwu: kontakt traci podstawę, dowód zostaje z datą wycofania, list znów prosi o zgodę.
        eq(303, http('POST', $url . '/zgoda', ['withdraw' => '1'])->status);
        ok($db->val('SELECT withdrawn_at FROM consents') !== null, 'dowód zostaje z datą wycofania');
        $contact = $db->one('SELECT * FROM contacts');
        eq(['none', null, null], [$contact['basis'], $contact['marketing_consent_at'], $contact['unsubscribed_at']]);
        $card = http('GET', $url)->body;
        ok(!str_contains($card, 'masz zgodę'), 'po wycofaniu oferta e-mailem nie ma podstawy');
        contains('Wyślij list pocztą z prośbą o zgodę na e-mail', $card);
        eq('very_high', RiskPolicy::assess('cold_offer', $contact)['level']);
        eq(303, http('POST', $url . '/zgoda', ['withdraw' => '1'])->status, 'drugie wycofanie nie robi nic');

        // Kontakt będący klientem zachowuje mocniejszą podstawę, a zgoda z rozmowy na stronie nie ginie przy wycofaniu.
        $db->update('contacts', ['basis' => 'client', 'marketing_consent_at' => '2026-01-05 10:00:00', 'marketing_consent_text' => 'Zgoda z rozmowy na stronie'], 'id = ?', [(int) $contact['id']]);
        http('POST', $url . '/zgoda', $ok);
        eq(1, (int) $db->val('SELECT COUNT(*) FROM consents WHERE withdrawn_at IS NULL'), 'zgoda klienta też ma swój dowód');
        eq(['client', 'Zgoda z rozmowy na stronie'], array_values((array) $db->one('SELECT basis, marketing_consent_text FROM contacts')));
        contains('Wyślij ofertę e-mailem: masz zgodę', http('GET', $url)->body);
        http('POST', $url . '/zgoda', ['withdraw' => '1']);
        eq(0, (int) $db->val('SELECT COUNT(*) FROM consents WHERE withdrawn_at IS NULL'));
        eq(['client', 'Zgoda z rozmowy na stronie'], array_values((array) $db->one('SELECT basis, marketing_consent_text FROM contacts')));
    },
    'Strona zgody: zły kod, limit prób i rozdzielenie firm' => function () use ($setup, $guest) {
        [$orgId, $uid, $id, $url] = $setup();
        $db = Db::get();
        http('GET', $url);
        $code = (string) $db->val('SELECT consent_code FROM prospects WHERE id = ?', [$id]);

        // Kod spoza alfabetu, za krótki albo nieistniejący: 404 bez podpowiedzi, czy firma istnieje.
        foreach (['abcdefghj', strtoupper($code), 'abcdefghi1', str_repeat('z', 10), $code . 'a'] as $bad) {
            eq(404, $guest(fn () => http('GET', '/z/' . $bad))->status, 'kod: ' . $bad);
        }
        contains('Adres nie działa', $guest(fn () => http('GET', '/z/' . str_repeat('z', 10)))->body);
        eq(404, $guest(fn () => http('POST', '/z/' . str_repeat('z', 10), ['_csrf' => 'brak', 'email' => 'a@b.example', 'agree' => '1']))->status);

        // Druga firma w panelu: własna lista, własne kody. Cudzej zgody nie zapisze ani nie wycofa.
        $guest(fn () => http('POST', '/z/' . $code, ['_csrf' => 'brak', 'email' => 'zakupy@przykladowa.example', 'agree' => '1']));
        [$org2, $uid2, $id2, $url2] = $setup('druga@example.com', 'Druga Firma sp. z o.o.');
        eq(404, http('POST', $url . '/zgoda', ['withdraw' => '1'])->status);
        eq(404, http('POST', $url . '/zgoda', ['email' => 'x@y.example', 'way' => 'other', 'detail' => 'Opis cudzej zgody tutaj.', 'on' => today_pl(), 'confirm' => '1'])->status);
        eq(null, $db->val('SELECT withdrawn_at FROM consents WHERE org_id = ?', [$orgId]));
        http('GET', $url2);
        $code2 = (string) $db->val('SELECT consent_code FROM prospects WHERE id = ?', [$id2]);
        ok($code2 !== $code && $code2 !== '', 'każda firma na liście ma własny kod');
        contains('Druga Firma sp. z o.o.', $guest(fn () => http('GET', '/z/' . $code2))->body);
        ok(!str_contains(http('GET', $url2)->body, 'masz zgodę'), 'zgoda dla jednej firmy z panelu nie działa u drugiej');
        eq([], Consents::waiting($org2));
        eq(1, count(Consents::waiting($orgId)));

        // Limit prób z jednego adresu: po 40 wejściach w godzinie strona odmawia.
        Kv::set('consent:' . hash('sha256', '127.0.0.1') . ':' . gmdate('YmdH'), 40, 3600);
        $limited = $guest(fn () => http('GET', '/z/' . $code));
        eq(429, $limited->status);
        contains('Za dużo prób', $limited->body);

        // Prośba o zgodę e-mailem nadal ma bardzo wysokie ryzyko, a ocena wskazuje drogę listem.
        $a = RiskPolicy::assess('consent_request', ['basis' => 'none']);
        eq('very_high', $a['level']);
        contains('list pocztą', implode(' ', $a['reasons']));
        contains('adres strony zgody', implode(' ', $a['reasons']));
    },
];
