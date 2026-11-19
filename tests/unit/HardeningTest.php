<?php
/** Testy poprawek z przeglądu przed uruchomieniem: bezpieczeństwo, import, AI, rejestry. */

use App\Core\Auth;
use App\Core\Config;
use App\Core\Db;
use App\Core\Http;
use App\Modules\Ai\DocumentText;
use App\Modules\Companies\Companies;
use App\Modules\Companies\Registries;
use App\Modules\Companies\Risk;
use App\Modules\Offers\Docx;
use App\Modules\Tenders\Analysis;
use App\Modules\Tenders\BzpClient;
use App\Modules\Tenders\Tenders;

/** Katalog z odpowiedziami BZP: $pages stron po 10 ogłoszeń, potem strona z 3 (albo błąd serwera). */
function bzp_fixture_dir(int $fullPages, bool $failAfter = false): string
{
    $dir = sys_get_temp_dir() . '/bzpfx' . bin2hex(random_bytes(4));
    mkdir($dir);
    $n = 0;
    $routes = "<?php\nreturn [\n";
    for ($p = 1; $p <= $fullPages + 1; $p++) {
        $count = $p <= $fullPages ? 10 : 3;
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $n++;
            $rows[] = ['objectId' => 'id-' . $n, 'noticeType' => 'ContractNotice', 'orderObject' => 'Zamówienie ' . $n, 'orderType' => 'Works',
                'publicationDate' => gmdate('Y-m-d\TH:i:s', strtotime('2026-11-17 08:00:00 UTC') + $n * 60) . '.0000000Z',
                'submittingOffersDate' => '2030-01-01T09:00:00Z', 'cpvCode' => '45233000-9 (Roboty)', 'organizationName' => 'Gmina', 'organizationProvince' => 'PL14'];
        }
        file_put_contents("$dir/p$p.json", json_encode($rows));
        $resp = ($failAfter && $p === $fullPages + 1) ? "['status' => 500, 'body' => 'błąd']" : "'p$p.json'";
        $routes .= "    '#Search\\?NoticeType=ContractNotice&.*&PageNumber=$p&#' => $resp,\n";
    }
    $routes .= "    '#Search\\?#' => ['status' => 200, 'body' => '[]'],\n];\n";
    file_put_contents("$dir/routes.php", $routes);
    return $dir;
}

return [
    'Produkcja bez DATABASE_URL zgłasza błąd zamiast pisać do pliku tymczasowego' => function () {
        Config::set('APP_ENV', 'production');
        try {
            $threw = false;
            try {
                Db::useUrl('');
            } catch (RuntimeException $e) {
                $threw = str_contains($e->getMessage(), 'DATABASE_URL');
            }
            ok($threw, 'oczekiwano wyjątku o braku DATABASE_URL');
        } finally {
            Config::set('APP_ENV', 'test');
            fresh_db();
        }
    },
    'Rejestracja zamknięta: tylko z kodem zaproszenia' => function () {
        Config::set('SIGNUP_OPEN', '0');
        Config::set('SIGNUP_CODE', 'tajny-kod');
        try {
            eq(403, http('GET', '/rejestracja')->status, 'bez kodu');
            eq(403, http('GET', '/rejestracja', [], ['kod' => 'zly'])->status, 'zły kod');
            $form = http('GET', '/rejestracja', [], ['kod' => 'tajny-kod']);
            eq(200, $form->status);
            contains('name="kod" value="tajny-kod"', $form->body);
            $data = ['company' => 'Pilot', 'email' => 'pilot@example.com', 'password' => 'bardzo-dlugie-haslo', 'terms' => '1'];
            eq('/rejestracja', http('POST', '/rejestracja', $data)->headers['Location'], 'POST bez kodu odrzucony');
            eq(0, (int) Db::get()->val('SELECT COUNT(*) FROM users'));
            eq('/pulpit', http('POST', '/rejestracja', $data + ['kod' => 'tajny-kod'])->headers['Location']);
            Auth::logout();
            ok(!str_contains(http('GET', '/')->body, 'href="/rejestracja"'), 'strona główna bez przycisku rejestracji');
            Config::set('SIGNUP_CODE', '');
            eq(403, http('GET', '/rejestracja', [], ['kod' => ''])->status, 'pusty kod nigdy nie działa');
        } finally {
            Config::set('SIGNUP_OPEN', '1');
            Config::set('SIGNUP_CODE', '');
        }
    },
    'Logowanie: limit na adres e-mail działa mimo zmiany adresu IP' => function () {
        make_org('ofiara@example.com');
        Auth::logout();
        for ($i = 0; $i < 20; $i++) {
            Auth::attempt('ofiara@example.com', 'zle-haslo', '10.0.0.' . $i);
        }
        $r = Auth::attempt('ofiara@example.com', 'haslo-testowe-1', '10.9.9.9');
        eq(false, $r['ok'], 'po 20 nieudanych próbach z różnych IP konto jest chwilowo zablokowane');
    },
    'Import BZP: limit stron nie gubi ogłoszeń, a błąd serwera wznawia od ostatniego' => function () {
        $dir = bzp_fixture_dir(3);
        Config::set('HTTP_FIXTURES', $dir);
        try {
            $c = new BzpClient();
            $rows = $c->since('2026-11-17 00:00:00', 2, static fn () => null);
            eq(20, count($rows));
            eq($rows[19]['published_at'], $c->continueFrom, 'kontynuacja od 20. ogłoszenia');
            $all = $c->since('2026-11-17 00:00:00', 10, static fn () => null);
            eq(33, count($all));
            eq(null, $c->continueFrom, 'pobrano wszystko');
            Config::set('HTTP_FIXTURES', bzp_fixture_dir(2, true));
            $part = $c->since('2026-11-17 00:00:00', 10, static fn () => null);
            eq(20, count($part));
            eq(1, $c->failedRequests);
            eq($part[19]['published_at'], $c->continueFrom, 'po błędzie serwera wznawiamy od ostatniego pobranego');
        } finally {
            Config::set('HTTP_FIXTURES', __DIR__ . '/../fixtures/http');
        }
    },
    'Eksport .docx usuwa znaki sterujące z tekstu PDF' => function () {
        $bin = Docx::build('Oferta', [['title' => 'Sekcja', 'body' => "Tekst\x0Cz PDF\x0B i\x00 znak\x1F końca strony"]]);
        $tmp = tempnam(sys_get_temp_dir(), 'dx');
        file_put_contents($tmp, $bin);
        $z = new ZipArchive();
        $z->open($tmp);
        $xml = (string) $z->getFromName('word/document.xml');
        $z->close();
        unlink($tmp);
        ok(simplexml_load_string($xml) !== false, 'XML poprawny mimo znaków sterujących');
        contains('Tekstz PDF i znak końca strony', $xml);
    },
    'Dokument: tekst bez znaku NUL i z limitem długości' => function () {
        $tmp = tempnam(sys_get_temp_dir(), 'tx');
        file_put_contents($tmp, "Rozdział 1\0\n" . str_repeat('ą', DocumentText::MAX_CHARS + 50));
        $t = DocumentText::extract($tmp, 'text/plain', 'swz.txt');
        unlink($tmp);
        ok(!str_contains($t, "\0"), 'bez znaku NUL (PostgreSQL go nie przyjmuje)');
        ok(mb_strlen($t) <= DocumentText::MAX_CHARS + 40, 'tekst skrócony do limitu');
        contains('dokument skrócony', $t);
    },
    'KRS: puste struktury działu 4 i 6 nie są sygnałem ryzyka' => function () {
        $odpis = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/http/krs_odpis_przyklad.json'), true)['odpis'];
        $odpis['dane']['dzial4'] = ['zaleglosci' => [], 'wierzytelnosci' => [], 'informacjeOOddaleniuWnioskuOOgloszenieUpadlosci' => []];
        $odpis['dane']['dzial6'] = ['likwidacja' => [], 'postepowanieUpadlosciowe' => [], 'postepowaniaRestrukturyzacyjne' => (object) [], 'rozwiazanieUniewaznienie' => null];
        $s = Registries::krsSummary(json_decode(json_encode($odpis), true));
        eq(null, $s['dzial4']);
        eq([], $s['dzial6_keys']);
        $titles = array_column(Risk::assess(['statusVat' => 'Czynny', 'accountCount' => 1], $s, [])['signals'], 'title');
        ok(!in_array('Wpisy o zaległościach lub wierzytelnościach w KRS', $titles, true), 'brak fałszywego sygnału o zaległościach');
        ok(!in_array('Likwidacja, upadłość lub restrukturyzacja w KRS', $titles, true), 'brak fałszywego sygnału o likwidacji');
        $odpis['dane']['dzial6'] = ['likwidacja' => [['otwarcieLikwidacji' => ['data' => '01.02.2026']]]];
        $s2 = Registries::krsSummary($odpis);
        $titles2 = array_column(Risk::assess(['statusVat' => 'Czynny', 'accountCount' => 1], $s2, [])['signals'], 'title');
        ok(in_array('Likwidacja, upadłość lub restrukturyzacja w KRS', $titles2, true), 'prawdziwy wpis o likwidacji nadal jest sygnałem');
    },
    'Biała lista: jedno zapytanie zbiorcze przy odświeżaniu obserwowanych firm' => function () {
        [$org] = make_org();
        foreach (['1234563218', '7181968222'] as $nip) {
            $c = Companies::lookup($nip)['company'];
            Db::get()->insert('watchlist', ['org_id' => $org, 'company_id' => (int) $c['id'], 'created_at' => now_utc()]);
        }
        $many = Registries::whiteListMany(['1234563218', '7181968222', '1111111111', 'zly']);
        eq('Czynny', $many['1234563218']['subject']['statusVat']);
        eq(2, $many['7181968222']['subject']['accountCount']);
        eq(null, $many['1111111111']['subject'], 'firma spoza wykazu');
        ok(!isset($many['zly']));
        Http::$log = [];
        $res = Companies::refreshWatched();
        eq(2, $res['checked']);
        $wlCalls = array_values(array_filter(Http::$log, static fn ($l) => str_contains($l['url'], 'wl-api.mf.gov.pl')));
        eq(1, count($wlCalls), 'jedno zapytanie do białej listy na obie firmy');
        contains('/search/nips/', $wlCalls[0]['url']);
    },
    'AI: dokumentacja idzie jako blok z pamięcią podręczną, a analiza i pytania mają wspólny początek' => function () {
        import_fixtures();
        [$org, $uid] = make_org();
        $t = Db::get()->one("SELECT * FROM tenders WHERE source = 'bzp' ORDER BY id LIMIT 1");
        $d = Tenders::details($t);
        Http::$log = [];
        $ai = Analysis::ai($org, $uid, $t, $d);
        ok($ai['ok']);
        ok(!preg_match('/[\x{2013}\x{2014}]/u', json_encode($ai['result'], JSON_UNESCAPED_UNICODE)), 'tekst z AI bez pauz i półpauz');
        contains('Wadium 46 000 zł - gwarancję', json_encode($ai['result'], JSON_UNESCAPED_UNICODE));
        ok(Analysis::ask($org, $uid, $t, $d, 'Czy jest wadium?')['ok']);
        $calls = array_values(array_filter(Http::$log, static fn ($l) => str_contains($l['url'], 'anthropic.com')));
        eq(2, count($calls));
        $a = json_decode($calls[0]['body'], true);
        $q = json_decode($calls[1]['body'], true);
        eq($a['system'], $q['system'], 'ten sam prompt systemowy');
        eq(['type' => 'ephemeral'], $a['messages'][0]['content'][0]['cache_control']);
        eq($a['messages'][0]['content'][0]['text'], $q['messages'][0]['content'][0]['text'], 'ta sama dokumentacja na początku');
        contains('Czy jest wadium?', $q['messages'][0]['content'][1]['text']);
        eq(2, (int) Db::get()->val("SELECT COUNT(*) FROM audit_log WHERE action = 'ai.call'"), 'zużycie zapisane w dzienniku');
    },
    'AI: nietypowa odpowiedź modelu nie psuje strony przetargu' => function () {
        $n = Analysis::normalize([
            'summary' => ['a', 'b'],
            'items' => [['label' => 'Wadium', 'value' => ['kwota' => '5000 zł'], 'source' => null], 'sam tekst', ['label' => '', 'value' => '']],
            'risks' => [['level' => 'KRYTYCZNE', 'text' => ['x' => '<script>alert(1)</script>']]],
            'documents' => 'Formularz',
            'questions' => [['text' => 'Pytanie?'], 7],
            'nieznane' => ['x'],
        ]);
        eq('a; b', $n['summary']);
        eq('5000 zł', $n['items'][0]['value']);
        eq('sam tekst', $n['items'][1]['label']);
        eq(2, count($n['items']), 'pusty wiersz pominięty');
        eq('mid', $n['risks'][0]['level'], 'nieznany poziom ryzyka → średni');
        eq('Formularz', $n['documents'][0]['name']);
        eq(['Pytanie?', '7'], $n['questions']);
        ok(!isset($n['nieznane']));
        import_fixtures();
        [$org, $uid] = make_org();
        $_SESSION = [];
        Auth::actAs($uid);
        $tid = (int) Db::get()->val('SELECT MIN(id) FROM tenders');
        Db::get()->insert('tender_analyses', ['org_id' => $org, 'tender_id' => $tid, 'kind' => 'summary', 'result_json' => json_out($n), 'created_at' => now_utc()]);
        $page = http('GET', '/przetargi/' . $tid);
        eq(200, $page->status);
        ok(!str_contains($page->body, '<script>alert(1)</script>'), 'treść z AI jest escapowana');
    },
    'Wiadomość: Enter w formularzu nie podmienia wpisanej treści' => function () {
        [, $uid] = make_org();
        $_SESSION = [];
        Auth::actAs($uid);
        http('POST', '/klienci', ['company_name' => 'Firma', 'email' => 'f@example.com', 'basis' => 'none']);
        $cid = (int) Db::get()->val('SELECT id FROM contacts');
        foreach (['keep', '', 'cos-innego'] as $action) {
            $r = http('POST', '/klienci/' . $cid . '/wiadomosc', ['kind' => 'cold_offer', 'action' => $action, 'subject' => 'Mój temat', 'body' => 'Moja ręcznie wpisana treść', 'ack' => '1']);
            eq(200, $r->status);
            contains('Moja ręcznie wpisana treść', $r->body, "akcja „{$action}” zachowuje treść");
        }
        eq(0, (int) Db::get()->val('SELECT COUNT(*) FROM messages'), 'nic nie zostało zapisane bez kliknięcia przycisku zapisu');
        $form = http('GET', '/klienci/' . $cid . '/wiadomosc')->body;
        ok(strpos($form, 'value="keep"') < strpos($form, 'value="switch"'), 'domyślny przycisk formularza nie zmienia treści');
    },
    'Tytuły w cudzysłowach typograficznych nie psują kodowania (błąd z prawdziwych danych BZP)' => function () {
        // trim() z listą „ ” ucinał pojedyncze bajty: tytuł zaczynający się od “ zostawał z błędnym bajtem 0x9C i PostgreSQL odrzucał zapis.
        foreach (['“Budowa drogi” – etap I', '„Remont szkoły”', "\u{201C}Dostawa sprzętu\u{201D}", ' "Usługa sprzątania" ', '«Ācs»', 'Zwykły tytuł'] as $raw) {
            $t = BzpClient::normalize(['objectId' => 'x', 'orderObject' => $raw, 'publicationDate' => '2026-11-17T08:00:00Z']);
            ok(mb_check_encoding($t['title'], 'UTF-8'), "poprawny UTF-8: $raw");
            ok(!preg_match('/^[„”“"«»\s]|[„”“"«»\s]$/u', $t['title']), "bez cudzysłowów na brzegach: {$t['title']}");
        }
        eq('Budowa drogi” – etap I', BzpClient::normalize(['objectId' => 'x', 'orderObject' => '“Budowa drogi” – etap I'])['title']);
        eq('Ācs', utrim('«Ācs»'));
        // Zapis ogłoszenia z błędnym bajtem i znakiem NUL nie może przerwać importu.
        $id = Tenders::save(['source' => 'bzp', 'external_id' => 'zly-utf8', 'title' => "Remont \x9C drogi\0", 'buyer_name' => "Gmina \xC5", 'raw_json' => json_out(['a' => "\x9C"])]);
        $row = Tenders::find($id);
        ok(mb_check_encoding($row['title'], 'UTF-8') && !str_contains($row['title'], "\0"));
        contains('Remont', $row['title']);
    },
    'NIP nie zaczyna się od zera (numer KRS nie przejdzie jako NIP)' => function () {
        ok(!App\Core\Nip::valid('0000189262'), 'numer KRS z poprawną „sumą kontrolną”');
        ok(!App\Core\Nip::valid('0000047102'));
        ok(App\Core\Nip::valid('1132869037'));
        eq(null, BzpClient::normalize(['objectId' => 'x', 'orderObject' => 'T', 'organizationNationalId' => '0000189262'])['buyer_nip']);
    },
    'Import: jedno błędne ogłoszenie nie zatrzymuje pozostałych' => function () {
        $dir = bzp_fixture_dir(1);
        // Drugie ogłoszenie bez tytułu i z niepoprawnym kodowaniem - ma zostać zapisane albo pominięte, ale nie przerwać importu.
        $rows = json_decode((string) file_get_contents("$dir/p1.json"), true);
        $rows[1]['orderObject'] = "\x9C\xE2\x80";
        file_put_contents("$dir/p1.json", json_encode($rows, JSON_INVALID_UTF8_IGNORE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        Config::set('HTTP_FIXTURES', $dir);
        try {
            $res = App\Modules\Tenders\Importer::run('2026-11-17 00:00:00');
            eq(13, $res['bzp'], 'wszystkie ogłoszenia zapisane');
            eq(13, (int) Db::get()->val("SELECT COUNT(*) FROM tenders WHERE source = 'bzp'"));
        } finally {
            Config::set('HTTP_FIXTURES', __DIR__ . '/../fixtures/http');
        }
    },
    'Dopasowanie wielu profili jednym odczytem ogłoszeń daje te same wyniki' => function () {
        import_fixtures();
        [$org] = make_org();
        $db = Db::get();
        $p1 = $db->insert('tender_profiles', ['org_id' => $org, 'name' => 'A', 'cpv' => '45233', 'keywords' => '', 'sources' => 'bzp ted', 'active' => 1, 'created_at' => now_utc()]);
        $p2 = $db->insert('tender_profiles', ['org_id' => $org, 'name' => 'B', 'cpv' => '', 'keywords' => 'drog', 'sources' => 'bzp', 'active' => 1, 'matched_until' => gmdate('Y-m-d H:i:s', time() + 3600), 'created_at' => now_utc()]);
        $n = App\Modules\Tenders\Matcher::run();
        ok($n >= 1, 'profil A ma dopasowania');
        eq(0, (int) $db->val('SELECT COUNT(*) FROM tender_matches WHERE profile_id = ?', [$p2]), 'profil B: nic nowszego niż jego znacznik');
        ok((int) $db->val('SELECT COUNT(*) FROM tender_matches WHERE profile_id = ?', [$p1]) >= 1);
        eq(0, App\Modules\Tenders\Matcher::run(), 'powtórne uruchomienie nie tworzy duplikatów');
    },
    'safe_url przepuszcza tylko http(s) i ścieżki lokalne' => function () {
        eq('https://example.com/a', safe_url('https://example.com/a'));
        eq('/przetargi/1', safe_url('/przetargi/1'));
        eq('#', safe_url('javascript:alert(1)'));
        eq('#', safe_url('//evil.example'));
        eq('#', safe_url('data:text/html,x'));
        eq('#', safe_url(null));
    },
];
