<?php
/** Testy bezpieczeństwa: wstrzyknięcie HTML we wszystkich polach, pełne pokrycie akcji POST, izolacja firm. */

use App\Core\Auth;
use App\Core\Db;
use App\Modules\Offers\Offers;

const XSS = '"><xss-probe x=\'';

/** Wypełnia bazę danymi, w których każde pole tekstowe zawiera próbę wstrzyknięcia HTML. Zwraca identyfikatory. */
function seed_xss(int $org, int $uid): array
{
    $db = Db::get();
    $x = XSS;
    $now = now_utc();
    $future = gmdate('Y-m-d H:i:s', time() + 10 * 86400);
    $db->update('organizations', ['name' => "Firma $x", 'address' => "ul. $x", 'phone' => $x, 'website' => 'https://example.com/' . rawurlencode($x)], 'id = ?', [$org]);
    $db->update('users', ['name' => "Jan $x"], 'id = ?', [$uid]);
    $details = ['descriptions' => ["Opis $x"], 'criteria' => [['name' => "Cena $x", 'weight' => 60.0]], 'wadium' => true, 'wadium_value' => null, 'wadium_info' => "Wadium $x",
        'period' => "8 mies. $x", 'conditions_required' => true, 'conditions' => "Warunek $x", 'other_documents' => "Dokument $x", 'procedure' => "Tryb $x",
        'deadline' => $future, 'submission_place' => "Platforma $x", 'binding_until' => '2030-01-01', 'reference' => "ZP.$x", 'advance_payments' => false, 'text' => "Treść $x"];
    $tid = $db->insert('tenders', ['source' => 'bzp', 'external_id' => 'xss-1', 'notice_number' => "2026/BZP $x", 'title' => "Przetarg $x", 'order_type' => 'works',
        'buyer_name' => "Gmina $x", 'buyer_nip' => '1234563218', 'buyer_city' => "Miasto $x", 'voivodeship' => 'mazowieckie', 'cpv_main' => '45233000', 'cpv_all' => '45233000',
        'value_estimated' => 1000000, 'published_at' => $now, 'deadline_at' => $future, 'url' => 'javascript:alert(1)', 'documents_url' => 'javascript:alert(2)',
        'search' => 'przetarg xss', 'details_json' => json_out($details), 'details_fetched_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    $pid = $db->insert('tender_profiles', ['org_id' => $org, 'name' => "Profil $x", 'cpv' => '45233', 'keywords' => "słowo $x", 'exclude_keywords' => $x, 'voivodeships' => '', 'order_types' => '', 'sources' => 'bzp ted', 'active' => 1, 'created_at' => $now]);
    $db->insert('tender_matches', ['org_id' => $org, 'profile_id' => $pid, 'tender_id' => $tid, 'score' => 90, 'reasons_json' => json_out(["Powód $x"]), 'status' => 'new', 'created_at' => $now]);
    $db->insert('tender_documents', ['org_id' => $org, 'tender_id' => $tid, 'filename' => "SWZ $x.pdf", 'text' => "Tekst $x", 'created_at' => $now]);
    $ai = ['summary' => "Podsumowanie $x", 'items' => [['label' => "Etykieta $x", 'value' => "Wartość $x", 'source' => "Źródło $x"]], 'conditions' => [['text' => "Warunek $x", 'source' => $x]],
        'risks' => [['level' => 'high', 'text' => "Ryzyko $x", 'source' => $x]], 'documents' => [['name' => "Dokument $x", 'source' => $x]], 'questions' => ["Pytanie $x"], 'documents_used' => ["SWZ $x.pdf"]];
    $db->insert('tender_analyses', ['org_id' => $org, 'tender_id' => $tid, 'kind' => 'summary', 'result_json' => json_out($ai), 'created_at' => $now]);
    $db->insert('tender_analyses', ['org_id' => $org, 'tender_id' => $tid, 'kind' => 'qa', 'question' => "Pytanie $x", 'result_json' => json_out(['answer' => "Odpowiedź $x", 'sources' => ["Źródło $x"]]), 'created_at' => $now]);
    foreach (array_keys(Offers::LIBRARY_KINDS) as $kind) {
        $db->insert('library_items', ['org_id' => $org, 'kind' => $kind, 'title' => "Pozycja $x", 'body' => "Opis $x", 'meta_json' => json_out(['client' => $x, 'value' => 1000, 'finished' => $x, 'role' => $x, 'qualifications' => $x]), 'valid_until' => '2030-01-01', 'created_at' => $now, 'updated_at' => $now]);
    }
    $oid = Offers::create($org, $uid, Db::get()->one('SELECT * FROM tenders WHERE id = ?', [$tid]));
    $db->update('offers', ['title' => "Oferta $x"], 'id = ?', [$oid]);
    $db->run("UPDATE offer_sections SET title = ?, body = ? WHERE offer_id = ? AND key = 'realizacja'", ["Sekcja $x", "Zdanie {$x} ⟦źródło: SWZ {$x}⟧\nDrugie {$x} ⟦sprawdź⟧", $oid]);
    $gid = Offers::create($org, $uid, null, 'grant', ['call_name' => "Nabór $x", 'call_url' => 'https://example.com/?' . rawurlencode($x)]);
    $db->insert('offer_documents', ['org_id' => $org, 'offer_id' => $gid, 'filename' => "Regulamin $x.pdf", 'text' => $x, 'created_at' => $now]);
    $signals = ['risk' => ['score' => 88, 'label' => "Wysokie $x", 'level' => 'high', 'signals' => [['level' => 'high', 'title' => "Sygnał $x", 'detail' => "Szczegół $x", 'source' => "Źródło $x", 'weight' => 35]]],
        'uokik' => [['signature' => "DZP-$x", 'company_name' => "Spółka $x", 'decided_at' => '2026-01-01', 'summary' => $x, 'url' => 'javascript:alert(3)', 'matched_by' => 'nazwa']], 'snapshot' => []];
    $cid = $db->insert('companies', ['nip' => '1234563218', 'regon' => $x, 'krs' => '0000123456', 'name' => "Spółka $x", 'search' => 'spolka xss', 'legal_form' => "Forma $x", 'vat_status' => "Czynny $x",
        'address' => "Adres $x", 'registered_at' => '2020-01-01', 'whitelist_json' => json_out(['accountCount' => 1]), 'krs_json' => json_out(['krs' => "0000 $x", 'legal_form' => "Forma $x", 'capital' => 5000, 'pkd' => ["PKD $x"],
        'last_statement_period_end' => '2024-12-31', 'board' => ["Prezes $x"], 'representation' => "Reprezentacja $x", 'website' => "www.$x"]), 'signals_json' => json_out($signals), 'risk_score' => 88, 'refreshed_at' => $now, 'created_at' => $now]);
    $db->insert('watchlist', ['org_id' => $org, 'company_id' => $cid, 'note' => "Notatka $x", 'created_at' => $now]);
    $db->insert('alerts', ['org_id' => $org, 'company_id' => $cid, 'level' => 'high', 'title' => "Alert $x", 'detail' => "Zmiana $x", 'created_at' => $now]);
    $rid = $db->insert('receivables', ['org_id' => $org, 'company_id' => $cid, 'invoice_no' => "FV $x", 'amount' => 1000, 'due_at' => '2026-01-01', 'demand_sent_at' => '2026-02-01', 'demand_bureau' => "Biuro $x", 'notes' => "Uwagi $x", 'created_at' => $now]);
    $kid = $db->insert('contacts', ['org_id' => $org, 'company_name' => "Klient $x", 'nip' => '1234563218', 'person' => "Osoba $x", 'email' => 'k@example.com', 'phone' => $x, 'website' => 'https://example.com',
        'basis' => 'rfq', 'basis_detail' => "Zapytanie $x", 'basis_url' => 'javascript:alert(4)', 'basis_at' => '2026-01-01', 'notes' => "Notatki $x", 'created_at' => $now]);
    $mid = $db->insert('messages', ['org_id' => $org, 'contact_id' => $kid, 'kind' => 'cold_offer', 'risk' => 'very_high', 'subject' => "Temat $x", 'body' => "Treść $x", 'ack_text' => "Potwierdzenie $x", 'ack_by' => $uid, 'ack_at' => $now, 'status' => 'draft', 'created_by' => $uid, 'created_at' => $now]);
    $audit = ['scanner_version' => '1.3.0', 'input' => $x, 'host' => "host$x", 'scanned_at' => gmdate('c'), 'final_url' => 'javascript:alert(5)', 'score' => 40, 'grade' => 'poor', 'label' => "Etykieta $x",
        'categories' => ['seo' => ['label' => "SEO $x", 'score' => 40]], 'counts' => ['critical' => 1, 'warning' => 0, 'info' => 0, 'ok' => 1], 'services' => [], 'psi_done' => false,
        'meta' => ['prestashop' => ['is' => false], 'psi' => ['perf' => $x]],
        'findings' => [['id' => 'a', 'cat' => 'seo', 'sev' => 'critical', 'title' => "Tytuł strony: $x", 'detail' => "Szczegół $x", 'fix' => "Poprawka $x", 'service' => 'seo', 'points' => 5],
            ['id' => 'b', 'cat' => 'seo', 'sev' => 'ok', 'title' => "W porządku $x", 'detail' => $x, 'fix' => null, 'service' => null, 'points' => 0]]];
    $aid = $db->insert('site_audits', ['org_id' => $org, 'url' => 'javascript:alert(6)', 'host' => "host$x", 'score' => 40, 'result_json' => json_out($audit), 'created_by' => $uid, 'created_at' => $now]);
    return compact('tid', 'pid', 'oid', 'gid', 'cid', 'rid', 'kid', 'mid', 'aid');
}

return [
    'Wstrzyknięcie HTML: żadne pole nie trafia na stronę bez escapowania' => function () {
        [$org, $uid] = make_org();
        $_SESSION = [];
        Auth::actAs($uid);
        $id = seed_xss($org, $uid);
        \App\Core\Config::set('ADMIN_EMAILS', 'wlasciciel@example.com');
        $paths = ['/pulpit', '/przetargi', '/przetargi?status=all&q=przetarg', '/przetargi/szukaj?q=przetarg', "/przetargi/{$id['tid']}", '/profile', "/profile/{$id['pid']}",
            '/oferty', "/oferty/{$id['oid']}", "/oferty/{$id['oid']}?sekcja=realizacja", "/oferty/{$id['oid']}?sekcja=realizacja&tryb=edycja", "/oferty/{$id['oid']}?sekcja=doswiadczenie",
            "/oferty/{$id['oid']}?sekcja=zespol", "/oferty/{$id['oid']}?sekcja=dokumenty", "/oferty/{$id['gid']}", '/biblioteka', '/biblioteka?rodzaj=person', '/biblioteka/1', '/biblioteka/2',
            '/kontrahenci', '/kontrahenci?q=spolka', '/kontrahenci/1234563218', "/naleznosci/{$id['rid']}/wezwanie", "/naleznosci/{$id['rid']}/wezwanie?bureau=" . rawurlencode(XSS) . '&miejsce=' . rawurlencode(XSS),
            '/klienci', '/klienci?q=klient', "/klienci/{$id['kid']}", "/klienci/{$id['kid']}/wiadomosc", "/klienci/{$id['kid']}/wiadomosc?kind=cold_offer", "/wiadomosci/{$id['mid']}",
            '/audyt', "/audyt/{$id['aid']}", '/audyt/porownanie', '/reklamy', '/ustawienia', '/ustawienia/uokik', '/nie-ma?x=' . rawurlencode(XSS)];
        $checked = 0;
        foreach ($paths as $p) {
            [$path, $qs] = array_pad(explode('?', $p, 2), 2, '');
            parse_str($qs, $q);
            $r = http('GET', $path, [], $q);
            ok(in_array($r->status, [200, 404], true), "$p zwraca {$r->status}");
            ok(!str_contains($r->body, '<xss-probe'), "$p: niezabezpieczony znacznik HTML");
            ok(!str_contains($r->body, '"><xss'), "$p: wyjście z atrybutu HTML");
            ok(!preg_match('/(href|src|action)\s*=\s*["\']?\s*javascript:/i', $r->body), "$p: odnośnik javascript:");
            $checked += $r->status === 200 ? 1 : 0;
        }
        ok($checked >= count($paths) - 1, "sprawdzono $checked stron");
        // Komunikaty po zapisie (flash) też zawierają dane użytkownika.
        http('POST', "/oferty/{$id['oid']}/sekcje/realizacja", ['title' => 'Sekcja ' . XSS, 'body' => 'x']);
        ok(!str_contains(http('GET', "/oferty/{$id['oid']}")->body, '<xss-probe'), 'komunikat po zapisie sekcji');
        http('POST', '/kontrahenci/1234563218/odswiez');
        ok(!str_contains(http('GET', '/kontrahenci')->body, '<xss-probe'), 'komunikat po odświeżeniu firmy');
        \App\Core\Config::set('ADMIN_EMAILS', '');
    },
    'Każda akcja zapisu działa (także na PostgreSQL)' => function () {
        import_fixtures();
        [$org, $uid] = make_org();
        $_SESSION = [];
        Auth::actAs($uid);
        $db = Db::get();
        $tid = (int) $db->val("SELECT id FROM tenders WHERE source = 'bzp' ORDER BY id LIMIT 1");
        $post = static function (string $path, array $data = [], int $expect = 303) {
            $r = http('POST', $path, $data);
            eq($expect, $r->status, "POST $path");
            return $r;
        };
        // Profile i przetargi
        $post('/profile', ['name' => 'P1', 'cpv' => '45', 'keywords' => "drog\nchodnik", 'woj' => ['podlaskie', 'slaskie'], 'rodzaj' => ['works'], 'zrodla' => ['bzp'], 'min_value' => '100 000', 'max_value' => '5 000 000,50', 'active' => '1']);
        $pid = (int) $db->val('SELECT id FROM tender_profiles');
        eq(5000000.5, (float) $db->val('SELECT max_value FROM tender_profiles'));
        $post('/profile/' . $pid, ['name' => 'P1 po zmianie', 'cpv' => '4523', 'zrodla' => ['bzp', 'ted'], 'active' => '1']);
        $post("/przetargi/$tid/status", ['status' => 'saved']);
        eq('saved', $db->val('SELECT status FROM tender_matches WHERE tender_id = ?', [$tid]));
        $post("/przetargi/$tid/status", ['status' => 'dismissed']);
        $post("/przetargi/$tid/status", ['status' => 'new']);
        $db->insert('tender_documents', ['org_id' => $org, 'tender_id' => $tid, 'filename' => 'a.txt', 'text' => 'x', 'created_at' => now_utc()]);
        $post("/przetargi/$tid/dokumenty/" . $db->val('SELECT id FROM tender_documents') . '/usun');
        eq(0, (int) $db->val('SELECT COUNT(*) FROM tender_documents'));
        // Biblioteka
        $post('/biblioteka', ['kind' => 'insurance', 'title' => 'Polisa OC', 'valid_until' => '2027-06-30']);
        $post('/biblioteka', ['kind' => 'reference', 'title' => 'Droga', 'meta_client' => 'Gmina', 'meta_value' => '1 250 000,00', 'meta_finished' => '09.2025', 'body' => 'Opis']);
        $lid = (int) $db->val("SELECT id FROM library_items WHERE kind = 'reference'");
        $post('/biblioteka/' . $lid, ['kind' => 'reference', 'title' => 'Droga gminna', 'meta_value' => '1300000']);
        $post('/biblioteka', ['kind' => 'text', 'title' => 'Do usunięcia', 'body' => 'x']);
        $post('/biblioteka/' . $db->val("SELECT id FROM library_items WHERE title = 'Do usunięcia'") . '/usun');
        eq(2, (int) $db->val('SELECT COUNT(*) FROM library_items'));
        // Oferty i wnioski
        $post('/oferty', ['tender_id' => (string) $tid]);
        $oid = (int) $db->val('SELECT id FROM offers');
        $post("/oferty/$oid", ['status' => 'review', 'title' => 'Nowy tytuł']);
        $post("/oferty/$oid/sekcje/formularz", ['title' => 'Formularz', 'body' => "Cena 100 zł\n⟦sprawdź⟧", 'done' => '1']);
        eq('done', $db->val("SELECT status FROM offer_sections WHERE offer_id = ? AND key = 'formularz'", [$oid]));
        $post("/oferty/$oid/sekcje/realizacja/ai");
        contains('⟦źródło:', (string) $db->val("SELECT body FROM offer_sections WHERE offer_id = ? AND key = 'realizacja'", [$oid]));
        eq(404, http('POST', "/oferty/$oid/sekcje/nie-ma", ['body' => 'x'])->status);
        eq(200, http('GET', "/oferty/$oid/docx")->status);
        $post('/oferty', ['kind' => 'grant', 'call_name' => 'Nabór A', 'call_url' => 'https://example.com/nabor', 'deadline' => '2027-01-31']);
        $gid = (int) $db->val("SELECT id FROM offers WHERE kind = 'grant'");
        ok($db->val('SELECT deadline_at FROM offers WHERE id = ?', [$gid]) !== null);
        $post('/oferty', ['kind' => 'grant', 'call_name' => '']);
        eq(1, (int) $db->val("SELECT COUNT(*) FROM offers WHERE kind = 'grant'"), 'wniosek bez nazwy naboru nie powstaje');
        $post("/oferty/$gid/usun");
        eq(0, (int) $db->val('SELECT COUNT(*) FROM offer_sections WHERE offer_id = ?', [$gid]), 'sekcje usunięte razem z wnioskiem');
        // Kontrahenci i należności
        eq(200, http('GET', '/kontrahenci/1234563218')->status);
        $post('/kontrahenci/1234563218/obserwuj', ['note' => 'podwykonawca']);
        $post('/kontrahenci/1234563218/naleznosci', ['invoice_no' => 'FV/1', 'amount' => '1 000,00', 'issued_at' => '2026-08-01', 'due_at' => '2026-08-31', 'notes' => 'x']);
        $post('/kontrahenci/1234563218/naleznosci', ['invoice_no' => '', 'amount' => 'abc', 'due_at' => 'zła-data']);
        eq(1, (int) $db->val('SELECT COUNT(*) FROM receivables'), 'błędna należność odrzucona');
        $rid = (int) $db->val('SELECT id FROM receivables');
        $post("/naleznosci/$rid", ['action' => 'demand', 'demand_bureau' => '']);
        eq(null, $db->val('SELECT demand_sent_at FROM receivables'), 'wezwanie bez nazwy biura nie jest zapisywane');
        $post("/naleznosci/$rid", ['action' => 'demand', 'demand_bureau' => 'KRD BIG S.A., Wrocław', 'demand_sent_at' => '2026-10-01']);
        eq('2026-10-01', $db->val('SELECT demand_sent_at FROM receivables'));
        $post("/naleznosci/$rid", ['action' => 'paid', 'paid_at' => '2026-11-01']);
        $post("/naleznosci/$rid", ['action' => 'unpaid']);
        eq(null, $db->val('SELECT paid_at FROM receivables'));
        $db->insert('alerts', ['org_id' => $org, 'level' => 'high', 'title' => 'A', 'detail' => 'B', 'created_at' => now_utc()]);
        $post('/kontrahenci/alerty');
        eq(0, (int) $db->val('SELECT COUNT(*) FROM alerts WHERE read_at IS NULL'));
        $post("/naleznosci/$rid", ['action' => 'delete']);
        $post('/kontrahenci/1234563218/obserwuj');
        eq(0, (int) $db->val('SELECT COUNT(*) FROM watchlist'));
        // Klienci i wiadomości
        eq(422, http('POST', '/klienci', ['company_name' => 'X', 'email' => 'zly-adres', 'basis' => 'none'])->status);
        eq(422, http('POST', '/klienci', ['company_name' => 'X', 'email' => 'x@example.com', 'basis' => 'consent'])->status, 'zgoda bez opisu dowodu');
        $post('/klienci', ['company_name' => 'Klient', 'nip' => '1234563218', 'person' => 'Anna', 'email' => 'A@Example.com', 'website' => 'example.com', 'basis' => 'rfq', 'basis_detail' => 'zapytanie', 'basis_at' => '2026-11-01']);
        $kid = (int) $db->val('SELECT id FROM contacts');
        eq('a@example.com', $db->val('SELECT email FROM contacts'));
        eq('https://example.com', $db->val('SELECT website FROM contacts'));
        $post("/klienci/$kid", ['company_name' => 'Klient 2', 'email' => 'a@example.com', 'basis' => 'client', 'basis_detail' => 'umowa 2025']);
        eq(200, http('POST', "/klienci/$kid/wiadomosc", ['kind' => 'rfq_reply', 'action' => 'switch'])->status);
        eq(200, http('POST', "/klienci/$kid/wiadomosc", ['kind' => 'consented', 'action' => 'ai', 'hint' => 'krótko'])->status);
        $post("/klienci/$kid/wiadomosc", ['kind' => 'consented', 'action' => 'save', 'subject' => 'Oferta', 'body' => 'Treść']);
        $mid = (int) $db->val('SELECT id FROM messages');
        eq('low', $db->val('SELECT risk FROM messages'));
        $post("/wiadomosci/$mid/wyslana");
        eq('sent', $db->val('SELECT status FROM messages'));
        $post("/klienci/$kid/wypisz");
        $post("/klienci/$kid/wypisz");
        eq(null, $db->val('SELECT unsubscribed_at FROM contacts'));
        // Ustawienia
        $post('/ustawienia', ['name' => 'Firma po zmianie', 'nip' => '123-456-32-18', 'address' => 'ul. Nowa 1', 'phone' => '600', 'website' => 'firma.example', 'user_name' => 'Jan Nowy']);
        eq('https://firma.example', $db->val('SELECT website FROM organizations WHERE id = ?', [$org]));
        $post('/ustawienia/haslo', ['current' => 'złe', 'new' => 'nowe-haslo-12345']);
        ok(password_verify('haslo-testowe-1', (string) $db->val('SELECT password_hash FROM users WHERE id = ?', [$uid])), 'złe obecne hasło nie zmienia hasła');
        $post('/ustawienia/haslo', ['current' => 'haslo-testowe-1', 'new' => 'nowe-haslo-12345']);
        ok(password_verify('nowe-haslo-12345', (string) $db->val('SELECT password_hash FROM users WHERE id = ?', [$uid])));
        $post('/ustawienia/zespol', ['name' => 'Marek', 'email' => 'marek@example.com', 'password' => 'haslo-startowe-1']);
        $member = (int) $db->val("SELECT id FROM users WHERE email = 'marek@example.com'");
        eq($org, (int) $db->val('SELECT org_id FROM users WHERE id = ?', [$member]));
        eq(404, http('POST', '/ustawienia/test-ai')->status, 'test AI tylko dla administratora');
        // Członek zespołu: widzi dane firmy, ale nie zmienia ich i nie dodaje osób
        $_SESSION = [];
        Auth::actAs($member);
        eq(200, http('GET', '/oferty/' . $oid)->status);
        $post('/ustawienia', ['name' => 'Przejęta', 'user_name' => 'Marek M.']);
        eq('Firma po zmianie', $db->val('SELECT name FROM organizations WHERE id = ?', [$org]));
        eq('Marek M.', $db->val('SELECT name FROM users WHERE id = ?', [$member]));
        $post('/ustawienia/zespol', ['email' => 'obcy@example.com', 'password' => 'haslo-startowe-1']);
        eq(0, (int) $db->val("SELECT COUNT(*) FROM users WHERE email = 'obcy@example.com'"));
        // Usunięcie profilu usuwa dopasowania; wylogowanie kończy sesję
        $_SESSION = [];
        Auth::actAs($uid);
        $post('/profile/' . $pid . '/usun');
        eq(0, (int) $db->val('SELECT COUNT(*) FROM tender_matches'));
        $post('/wyloguj');
        eq(303, http('GET', '/pulpit')->status);
    },
    'Izolacja firm: cudzych danych nie da się odczytać ani zmienić żadną akcją' => function () {
        import_fixtures();
        [$orgA, $uidA] = make_org('a@example.com', 'Firma A');
        [$orgB, $uidB] = make_org('b@example.com', 'Firma B');
        $_SESSION = [];
        Auth::actAs($uidA);
        $id = seed_xss($orgA, $uidA);
        $db = Db::get();
        $before = [];
        foreach (['offers', 'offer_sections', 'library_items', 'contacts', 'messages', 'receivables', 'watchlist', 'alerts', 'tender_profiles', 'tender_matches', 'tender_documents', 'tender_analyses', 'site_audits', 'offer_documents'] as $t) {
            $before[$t] = json_encode($db->all("SELECT * FROM $t ORDER BY 1"));
        }
        $_SESSION = [];
        Auth::actAs($uidB);
        foreach (["/oferty/{$id['oid']}", "/oferty/{$id['oid']}/docx", "/oferty/{$id['gid']}", '/biblioteka/1', "/profile/{$id['pid']}", "/naleznosci/{$id['rid']}/wezwanie",
            "/klienci/{$id['kid']}", "/klienci/{$id['kid']}/wiadomosc", "/wiadomosci/{$id['mid']}", "/wiadomosci/{$id['mid']}/eml", "/audyt/{$id['aid']}"] as $p) {
            eq(404, http('GET', $p)->status, "GET $p");
        }
        $tenderPage = http('GET', "/przetargi/{$id['tid']}")->body;
        ok(!str_contains($tenderPage, 'Podsumowanie'), 'analiza AI innej firmy niewidoczna');
        ok(!str_contains($tenderPage, 'SWZ &quot;'), 'dokumenty innej firmy niewidoczne');
        foreach ([["/oferty/{$id['oid']}", ['title' => 'x']], ["/oferty/{$id['oid']}/sekcje/realizacja", ['body' => 'x']], ["/oferty/{$id['oid']}/sekcje/realizacja/ai", []], ["/oferty/{$id['oid']}/usun", []],
            ["/oferty/{$id['gid']}/dokumenty", []], ['/biblioteka/1', ['kind' => 'text', 'title' => 'x']], ['/biblioteka/1/usun', []], ["/profile/{$id['pid']}", ['name' => 'x', 'cpv' => '45']], ["/profile/{$id['pid']}/usun", []],
            ["/przetargi/{$id['tid']}/status", ['status' => 'dismissed']], ["/przetargi/{$id['tid']}/dokumenty/1/usun", []], ["/naleznosci/{$id['rid']}", ['action' => 'delete']], ["/naleznosci/{$id['rid']}", ['action' => 'paid']],
            ["/klienci/{$id['kid']}", ['company_name' => 'x', 'email' => 'x@example.com', 'basis' => 'none']], ["/klienci/{$id['kid']}/wypisz", []], ["/klienci/{$id['kid']}/wiadomosc", ['kind' => 'rfq_reply', 'action' => 'save', 'subject' => 's', 'body' => 'b']],
            ["/wiadomosci/{$id['mid']}/wyslana", []], ['/kontrahenci/1234563218/obserwuj', []], ['/kontrahenci/alerty', []]] as [$p, $data]) {
            http('POST', $p, $data);
        }
        // Firma B mogła dodać własną obserwację tej samej (publicznej) firmy – usuwamy ją z porównania.
        $db->run('DELETE FROM watchlist WHERE org_id = ?', [$orgB]);
        foreach ($before as $t => $snapshot) {
            eq($snapshot, json_encode($db->all("SELECT * FROM $t ORDER BY 1")), "tabela $t zmieniona przez inną firmę");
        }
    },
];
