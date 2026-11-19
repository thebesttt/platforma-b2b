<?php

use App\Core\Auth;
use App\Core\Db;
use App\Modules\Tenders\Importer;

/** Zalogowanie użytkownika w testach HTTP. */
function login_as(int $userId): void
{
    $_SESSION = [];
    Auth::actAs($userId);
}

return [
    'Rejestracja przez formularz, potem pulpit' => function () {
        $r = http('POST', '/rejestracja', ['company' => 'Nowa Firma', 'nip' => '', 'name' => 'Ola', 'email' => 'ola@example.com', 'password' => 'bardzo-dlugie-haslo', 'terms' => '1']);
        eq(303, $r->status);
        eq('/pulpit', $r->headers['Location']);
        $p = http('GET', '/pulpit');
        eq(200, $p->status);
        contains('Ustaw profil przetargowy', $p->body);
    },
    'Rejestracja wymaga akceptacji regulaminu i poprawnego NIP' => function () {
        eq(422, http('POST', '/rejestracja', ['company' => 'X', 'email' => 'x@example.com', 'password' => 'bardzo-dlugie-haslo'])->status);
        $r = http('POST', '/rejestracja', ['company' => 'X', 'nip' => '1234563219', 'email' => 'x@example.com', 'password' => 'bardzo-dlugie-haslo', 'terms' => '1']);
        contains('NIP jest nieprawidłowy', $r->body);
    },
    'Logowanie: błędne hasło i blokada po wielu próbach' => function () {
        make_org('a@example.com');
        Auth::logout();
        eq(422, http('POST', '/logowanie', ['email' => 'a@example.com', 'password' => 'zle'])->status);
        for ($i = 0; $i < 8; $i++) {
            http('POST', '/logowanie', ['email' => 'a@example.com', 'password' => 'zle']);
        }
        contains('Zbyt wiele', http('POST', '/logowanie', ['email' => 'a@example.com', 'password' => 'haslo-testowe-1'])->body);
    },
    'Strony aplikacji wymagają logowania' => function () {
        $r = http('GET', '/przetargi');
        eq(303, $r->status);
        eq('/logowanie?next=%2Fprzetargi', $r->headers['Location']);
    },
    'POST bez tokenu CSRF jest odrzucany' => function () {
        [, $uid] = make_org();
        login_as($uid);
        eq(403, http('POST', '/profile', ['_csrf' => 'zly', 'name' => 'X', 'cpv' => '45'])->status);
        eq(0, (int) App\Core\Db::get()->val('SELECT COUNT(*) FROM tender_profiles'), 'nic nie zapisano');
    },
    'Izolacja firm: cudza oferta, kontakt i należność są niewidoczne' => function () {
        [$orgA, $uidA] = make_org('a@example.com', 'Firma A');
        [$orgB, $uidB] = make_org('b@example.com', 'Firma B');
        login_as($uidA);
        http('POST', '/oferty', []);
        $offerId = (int) Db::get()->val('SELECT id FROM offers WHERE org_id = ?', [$orgA]);
        http('POST', '/klienci', ['company_name' => 'Klient A', 'email' => 'k@example.com', 'basis' => 'none']);
        $contactId = (int) Db::get()->val('SELECT id FROM contacts WHERE org_id = ?', [$orgA]);
        login_as($uidB);
        eq(404, http('GET', '/oferty/' . $offerId)->status);
        eq(404, http('GET', '/oferty/' . $offerId . '/docx')->status);
        eq(404, http('GET', '/klienci/' . $contactId)->status);
        http('POST', '/oferty/' . $offerId . '/usun');
        eq(1, (int) Db::get()->val('SELECT COUNT(*) FROM offers WHERE id = ?', [$offerId]), 'cudzej oferty nie da się usunąć');
    },
    'Profil przetargowy od razu dopasowuje zaimportowane ogłoszenia' => function () {
        import_fixtures();
        [, $uid] = make_org();
        login_as($uid);
        $r = http('POST', '/profile', ['name' => 'Drogi', 'cpv' => '45233000', 'keywords' => 'drog', 'zrodla' => ['bzp', 'ted'], 'active' => '1']);
        eq(303, $r->status);
        $list = http('GET', '/przetargi');
        contains('Rozbudowa drogi powiatowej nr 1934B', $list->body);
        $tid = (int) Db::get()->val("SELECT id FROM tenders WHERE source = 'bzp' AND title LIKE 'Rozbudowa%'");
        $show = http('GET', '/przetargi/' . $tid);
        eq(200, $show->status);
        contains('Wadium', $show->body);
        contains("46\u{00A0}000,00\u{00A0}zł", $show->body);
        $ics = http('GET', '/przetargi/' . $tid . '/kalendarz');
        contains('BEGIN:VEVENT', $ics->body);
        $deadline = (string) Db::get()->val('SELECT deadline_at FROM tenders WHERE id = ?', [$tid]);
        contains('DTSTART:' . gmdate('Ymd\\THis\\Z', strtotime($deadline . ' UTC')), $ics->body);
    },
    'Wgranie SWZ, analiza AI i pytanie do dokumentacji' => function () {
        import_fixtures();
        [, $uid] = make_org();
        login_as($uid);
        $tid = (int) Db::get()->val("SELECT id FROM tenders WHERE source = 'bzp' ORDER BY id LIMIT 1");
        $tmp = tempnam(sys_get_temp_dir(), 'swz');
        file_put_contents($tmp, "Rozdział 7. Warunki udziału.\nRozdział 12. Kary umowne.");
        $files = ['documents' => ['name' => ['SWZ.txt', 'zly.exe'], 'type' => ['text/plain', 'application/octet-stream'], 'tmp_name' => [$tmp, $tmp], 'error' => [0, 0], 'size' => [60, 60]]];
        eq(303, http('POST', '/przetargi/' . $tid . '/dokumenty', [], [], $files)->status);
        eq(1, (int) Db::get()->val('SELECT COUNT(*) FROM tender_documents'), 'tylko obsługiwany format');
        http('GET', '/pulpit'); // zużywa komunikaty
        eq(303, http('POST', '/przetargi/' . $tid . '/analiza')->status);
        eq(303, http('POST', '/przetargi/' . $tid . '/pytanie', ['question' => 'Czy mogę mieć podwykonawcę?'])->status);
        $page = http('GET', '/przetargi/' . $tid);
        contains('Rozbudowa odcinka drogi powiatowej', $page->body);
        contains('część prac możesz powierzyć podwykonawcy', $page->body);
        @unlink($tmp);
    },
    'Wiadomość wysokiego ryzyka wymaga potwierdzenia, które trafia do historii' => function () {
        [$org, $uid] = make_org();
        login_as($uid);
        http('POST', '/klienci', ['company_name' => 'Firma X', 'email' => 'x@example.com', 'basis' => 'none']);
        $cid = (int) Db::get()->val('SELECT id FROM contacts');
        $no = http('POST', '/klienci/' . $cid . '/wiadomosc', ['kind' => 'cold_offer', 'action' => 'save', 'subject' => 'Oferta', 'body' => 'Treść']);
        contains('Zaznacz oświadczenie', $no->body);
        eq(0, (int) Db::get()->val('SELECT COUNT(*) FROM messages'));
        $yes = http('POST', '/klienci/' . $cid . '/wiadomosc', ['kind' => 'cold_offer', 'action' => 'save', 'subject' => 'Oferta', 'body' => 'Treść', 'ack' => '1']);
        eq(303, $yes->status);
        $m = Db::get()->one('SELECT * FROM messages');
        eq('very_high', $m['risk']);
        eq($uid, (int) $m['ack_by']);
        ok($m['ack_at'] !== null);
        $log = Db::get()->one("SELECT * FROM audit_log WHERE action = 'message.create'");
        eq('127.0.0.1', $log['ip']);
        contains('Zapoznałem się z ryzykiem', (string) $log['data_json']);
        eq(200, http('GET', '/wiadomosci/' . $m['id'] . '/eml')->status);
    },
    'Sprzeciw odbiorcy blokuje oferty' => function () {
        [, $uid] = make_org();
        login_as($uid);
        http('POST', '/klienci', ['company_name' => 'Firma Y', 'email' => 'y@example.com', 'basis' => 'consent', 'basis_detail' => 'formularz na targach']);
        $cid = (int) Db::get()->val('SELECT id FROM contacts');
        http('POST', '/klienci/' . $cid . '/wypisz');
        $r = http('POST', '/klienci/' . $cid . '/wiadomosc', ['kind' => 'consented', 'action' => 'save', 'subject' => 'Oferta', 'body' => 'Treść', 'ack' => '1']);
        contains('wypisał się', $r->body);
        eq(0, (int) Db::get()->val('SELECT COUNT(*) FROM messages'));
    },
    'Kontrahent: karta, obserwowanie, należność i wezwanie .docx' => function () {
        [$org, $uid] = make_org();
        login_as($uid);
        $show = http('GET', '/kontrahenci/1234563218');
        eq(200, $show->status);
        contains('PRZYKŁADOWA SPÓŁKA BUDOWLANA', $show->body);
        http('POST', '/kontrahenci/1234563218/obserwuj');
        eq(1, (int) Db::get()->val('SELECT COUNT(*) FROM watchlist WHERE org_id = ?', [$org]));
        http('POST', '/kontrahenci/1234563218/naleznosci', ['invoice_no' => 'FV/7/2026', 'amount' => '2 500,50', 'due_at' => '2026-09-30']);
        $rec = Db::get()->one('SELECT * FROM receivables');
        eq(2500.5, (float) $rec['amount']);
        $doc = http('GET', '/naleznosci/' . $rec['id'] . '/wezwanie', [], ['format' => 'docx', 'bureau' => 'KRD BIG S.A.']);
        eq(200, $doc->status);
        eq('PK', substr($doc->body, 0, 2));
        eq(303, http('GET', '/kontrahenci/sprawdz', [], ['q' => '1234563219'])->status);
    },
    'Wszystkie strony aplikacji renderują się bez błędów' => function () {
        import_fixtures();
        [, $uid] = make_org();
        login_as($uid);
        http('POST', '/profile', ['name' => 'Drogi', 'cpv' => '45', 'zrodla' => ['bzp', 'ted'], 'active' => '1']);
        http('POST', '/oferty', ['tender_id' => (string) Db::get()->val('SELECT MIN(id) FROM tenders')]);
        http('POST', '/oferty', ['kind' => 'grant', 'call_name' => 'Nabór']);
        http('POST', '/biblioteka', ['kind' => 'person', 'title' => 'Jan Kowalski - kierownik budowy', 'body' => 'Uprawnienia drogowe']);
        http('POST', '/klienci', ['company_name' => 'K', 'email' => 'k@example.com', 'basis' => 'rfq', 'basis_detail' => 'zapytanie']);
        $paths = ['/pulpit', '/przetargi', '/przetargi?status=all', '/przetargi/szukaj?q=droga', '/profile', '/profile/nowy', '/profile/1',
            '/oferty', '/oferty/1', '/oferty/1?sekcja=doswiadczenie&tryb=edycja', '/oferty/2', '/biblioteka', '/biblioteka?rodzaj=person', '/biblioteka/1',
            '/kontrahenci', '/kontrahenci?q=przyk', '/klienci', '/klienci/nowy', '/klienci/1', '/klienci/1/wiadomosc', '/audyt', '/audyt/porownanie', '/reklamy', '/ustawienia'];
        foreach ($paths as $p) {
            [$path, $qs] = array_pad(explode('?', $p, 2), 2, '');
            parse_str($qs, $q);
            $r = http('GET', $path, [], $q);
            eq(200, $r->status, $p);
            ok(!str_contains($r->body, 'Coś poszło nie tak'), $p);
        }
        eq(404, http('GET', '/nie-ma-takiej-strony')->status);
    },
    'Administrator wgrywa listę decyzji UOKiK, inni nie widzą tej strony' => function () {
        [, $uid] = make_org('admin@example.com');
        login_as($uid);
        eq(404, http('GET', '/ustawienia/uokik')->status, 'bez ADMIN_EMAILS');
        App\Core\Config::set('ADMIN_EMAILS', 'inny@example.com, ADMIN@example.com');
        eq(200, http('GET', '/ustawienia/uokik')->status);
        $files = ['pages' => ['name' => ['lista.html'], 'type' => ['text/html'], 'tmp_name' => [__DIR__ . '/../fixtures/http/uokik_zatory_2026.html'], 'error' => [0], 'size' => [1000]]];
        eq(303, http('POST', '/ustawienia/uokik', [], [], $files)->status);
        eq(2, (int) Db::get()->val('SELECT COUNT(*) FROM uokik_decisions'));
        // Przycisk „Sprawdź połączenie z AI”: wynik i zużycie tokenów w komunikacie.
        eq(303, http('POST', '/ustawienia/test-ai')->status);
        $page = http('GET', '/ustawienia')->body;
        contains('AI działa.', $page);
        contains('21 wejście, 3 wyjście', $page);
        App\Core\Config::set('ADMIN_EMAILS', '');
    },
    'Zadania cykliczne: token wymagany' => function () {
        eq(401, http('GET', '/cron/run', [], ['token' => 'zly'])->status);
        $with = static fn (array $server) => App\Core\App::handle(new App\Core\Request('POST', '/cron/run', ['task' => 'watchlist'], [], [], $server))->status;
        eq(200, $with(['HTTP_X_CRON_TOKEN' => 'test-cron-token']), 'nagłówek X-Cron-Token');
        eq(200, $with(['HTTP_AUTHORIZATION' => 'Bearer test-cron-token']), 'nagłówek Authorization');
        eq(200, $with(['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer test-cron-token']), 'nagłówek po przepisaniu adresu przez Apache');
        eq(401, $with(['HTTP_X_CRON_TOKEN' => 'zly']), 'zły token w nagłówku');
        eq(401, $with([]), 'bez tokenu');
        $r = http('GET', '/cron/run', [], ['token' => 'test-cron-token', 'task' => 'import']);
        eq(200, $r->status);
        $j = json_decode($r->body, true);
        ok($j['ok']);
        eq(2, $j['result']['bzp']);
    },
];
