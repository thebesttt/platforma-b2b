<?php

use App\Core\Auth;
use App\Core\Db;
use App\Modules\Offers\Offers;

/** Druga osoba w tej samej firmie. */
$member = static function (int $org, string $email, string $name): int {
    return Db::get()->insert('users', ['org_id' => $org, 'email' => $email, 'name' => $name, 'password_hash' => Auth::hash('haslo-testowe-2'), 'role' => 'member', 'created_at' => now_utc()]);
};

return [
    'Lista dokumentów do złożenia: pozycje z ogłoszenia, odhaczanie, dopisywanie i usuwanie' => function () {
        [$org, $uid] = make_org();
        login_as($uid);
        import_fixtures();
        $db = Db::get();
        $tender = $db->one("SELECT * FROM tenders WHERE source = 'bzp' ORDER BY id LIMIT 1");
        $id = Offers::create($org, $uid, $tender);
        $list = Offers::checklist($org, $id);
        ok(count($list) >= 3, 'pozycje startowe z ogłoszenia: ' . count($list));
        ok($list[0]['source'] !== null && $list[0]['source'] !== '', 'pozycja wskazuje, skąd wynika');
        $page = http('GET', '/oferty/' . $id, [], ['sekcja' => '_dokumenty'])->body;
        contains('Dokumenty do złożenia', $page);
        contains('0 z ' . count($list) . ' gotowe', $page);
        contains(e($list[0]['label']), $page);
        // Odhaczenie zapisuje, kto i kiedy. Drugie kliknięcie cofa.
        eq(303, http('POST', '/oferty/' . $id . '/lista/' . $list[0]['id'])->status);
        $row = $db->one('SELECT * FROM offer_checklist WHERE id = ?', [$list[0]['id']]);
        eq([1, $uid], [(int) $row['done'], (int) $row['done_by']]);
        ok($row['done_at'] !== null, 'data odhaczenia');
        contains('1 z ' . count($list) . ' gotowe', http('GET', '/oferty/' . $id, [], ['sekcja' => '_dokumenty'])->body);
        http('POST', '/oferty/' . $id . '/lista/' . $list[0]['id']);
        eq([0, null], [(int) $db->val('SELECT done FROM offer_checklist WHERE id = ?', [$list[0]['id']]), $db->val('SELECT done_by FROM offer_checklist WHERE id = ?', [$list[0]['id']])]);
        // Własna pozycja: kod HTML z pola jest tekstem.
        http('POST', '/oferty/' . $id . '/lista', ['label' => 'Polisa OC <img src=x onerror=alert(1)>']);
        $page = http('GET', '/oferty/' . $id, [], ['sekcja' => '_dokumenty'])->body;
        contains('Polisa OC &lt;img src=x onerror=alert(1)&gt;', $page);
        ok(!str_contains($page, '<img src=x'), 'nazwa dokumentu jako tekst');
        contains('dopisane przez zespół', $page);
        eq(303, http('POST', '/oferty/' . $id . '/lista', ['label' => '   '])->status);
        eq(count($list) + 1, count(Offers::checklist($org, $id)), 'pusta nazwa nie tworzy pozycji');
        // Uzupełnienie z ogłoszenia nie dubluje pozycji, a po usunięciu jednej przywraca tylko ją.
        eq(0, Offers::syncChecklist($org, Offers::find($org, $id)));
        http('POST', '/oferty/' . $id . '/lista/' . $list[1]['id'], ['delete' => '1']);
        eq(count($list), count(Offers::checklist($org, $id)));
        http('POST', '/oferty/' . $id . '/lista', ['sync' => '1']);
        eq(count($list) + 1, count(Offers::checklist($org, $id)));
        // Lista ofert pokazuje postęp.
        contains('dokumenty: 0 z ' . (count($list) + 1), http('GET', '/oferty')->body);
        // Cudza firma nie odhaczy ani nie dopisze pozycji.
        [, $uid2] = make_org('obca@example.com', 'Obca sp. z o.o.');
        login_as($uid2);
        eq(404, http('POST', '/oferty/' . $id . '/lista/' . $list[0]['id'])->status);
        eq(404, http('POST', '/oferty/' . $id . '/lista', ['label' => 'Obcy wpis'])->status);
        eq(0, (int) $db->val("SELECT COUNT(*) FROM offer_checklist WHERE label = 'Obcy wpis' OR done = 1"));
    },
    'Przydzielenie sekcji: tylko osobie z firmy, z e-mailem do niej i listą „Twoje sekcje”' => function () use ($member) {
        [$org, $uid] = make_org();
        $anna = $member($org, 'anna@example.com', 'Anna Kowalska');
        [$org2, $uid2] = make_org('obca@example.com', 'Obca sp. z o.o.');
        login_as($uid);
        $db = Db::get();
        $id = Offers::create($org, $uid, null);
        $key = Offers::sections($id)[1]['key'];
        with_config(['RESEND_API_KEY' => 're_test', 'MAIL_FROM' => 'Panel <panel@perfectdata.pl>'], function () use ($id, $key, $anna, $uid, $uid2, $db) {
            // Osoba z innej firmy: odmowa i brak zmiany.
            eq(303, http('POST', '/oferty/' . $id . '/sekcje/' . $key . '/osoba', ['user_id' => $uid2])->status);
            eq(null, $db->val('SELECT assignee_id FROM offer_sections WHERE offer_id = ? AND key = ?', [$id, $key]));
            eq(0, (int) $db->val("SELECT COUNT(*) FROM outbox WHERE kind = 'offer_assign'"));
            // Koleżanka z firmy: zapis i e-mail z linkiem do sekcji.
            http('POST', '/oferty/' . $id . '/sekcje/' . $key . '/osoba', ['user_id' => $anna]);
            eq($anna, (int) $db->val('SELECT assignee_id FROM offer_sections WHERE offer_id = ? AND key = ?', [$id, $key]));
            $mail = $db->one("SELECT * FROM outbox WHERE kind = 'offer_assign'");
            eq('anna@example.com', $mail['recipient']);
            contains('Jan Test przydziela Ci sekcję', $mail['body']);
            contains('/oferty/' . $id . '?sekcja=' . $key, $mail['body']);
            // Ponowne wybranie tej samej osoby i przydzielenie sekcji sobie nie wysyła e-maila.
            http('POST', '/oferty/' . $id . '/sekcje/' . $key . '/osoba', ['user_id' => $anna]);
            http('POST', '/oferty/' . $id . '/sekcje/' . Offers::sections($id)[2]['key'] . '/osoba', ['user_id' => $uid]);
            eq(1, (int) $db->val("SELECT COUNT(*) FROM outbox WHERE kind = 'offer_assign'"));
        });
        $page = http('GET', '/oferty/' . $id)->body;
        contains('<span class="sec-who">Anna K.</span>', $page, 'osoba pod tytułem sekcji');
        contains('<span class="sec-who">Ty</span>', $page);
        contains('Twoje sekcje do napisania', http('GET', '/oferty')->body);
        contains('Twoje sekcje do napisania', http('GET', '/pulpit')->body);
        // Anna widzi swoją sekcję, a po oznaczeniu jej jako gotowej sekcja znika z listy.
        login_as($anna);
        $mine = Offers::mySections($org, $anna);
        eq([$key], array_column($mine, 'key'));
        contains('Twoje sekcje do napisania', http('GET', '/pulpit')->body);
        http('POST', '/oferty/' . $id . '/sekcje/' . $key, ['body' => 'Gotowy tekst.', 'title' => 'Sekcja', 'done' => '1', 'version' => '1']);
        eq([], Offers::mySections($org, $anna));
        ok(!str_contains(http('GET', '/pulpit')->body, 'Twoje sekcje do napisania'), 'pusta lista nie zajmuje miejsca');
        contains('Sekcje gotowe: 1 z ', http('GET', '/oferty')->body);
        // Cudza firma nie przydzieli sekcji w naszej ofercie.
        login_as($uid2);
        eq(404, http('POST', '/oferty/' . $id . '/sekcje/' . $key . '/osoba', ['user_id' => $uid2])->status);
        eq($anna, (int) $db->val('SELECT assignee_id FROM offer_sections WHERE offer_id = ? AND key = ?', [$id, $key]));
        eq([], Offers::mySections($org2, $uid2));
    },
    'Dwie osoby edytują tę samą sekcję: drugi zapis nie nadpisuje pierwszego i nie gubi tekstu' => function () use ($member) {
        [$org, $uid] = make_org();
        $anna = $member($org, 'anna@example.com', 'Anna Kowalska');
        $db = Db::get();
        $id = Offers::create($org, $uid, null);
        $key = Offers::sections($id)[0]['key'];
        $url = '/oferty/' . $id . '/sekcje/' . $key;
        // Obie osoby otwierają formularz z wersją 1.
        login_as($uid);
        contains('name="version" value="1"', http('GET', '/oferty/' . $id, [], ['sekcja' => $key, 'tryb' => 'edycja'])->body);
        // Anna zapisuje pierwsza.
        login_as($anna);
        eq(303, http('POST', $url, ['body' => 'Wersja Anny.', 'title' => 'Formularz', 'version' => '1'])->status);
        // Jan zapisuje ze starą wersją: odmowa, jego tekst zostaje w polu, obok widać wersję Anny.
        login_as($uid);
        $r = http('POST', $url, ['body' => 'Wersja Jana <b>ważna</b>.', 'title' => 'Formularz', 'version' => '1']);
        eq(409, $r->status);
        contains('Nie zapisaliśmy Twojej wersji', $r->body);
        contains('zapisał(a) Anna Kowalska', $r->body);
        contains('Wersja Jana &lt;b&gt;ważna&lt;/b&gt;.</textarea>', $r->body, 'tekst Jana w polu, jako tekst');
        contains('Aktualna wersja w bazie', $r->body);
        contains('Wersja Anny.', $r->body);
        contains('name="version" value="2"', $r->body, 'formularz dostaje bieżącą wersję');
        eq('Wersja Anny.', $db->val('SELECT body FROM offer_sections WHERE offer_id = ? AND key = ?', [$id, $key]));
        // Po porównaniu Jan zapisuje świadomie z bieżącą wersją.
        eq(303, http('POST', $url, ['body' => 'Wersja wspólna.', 'title' => 'Formularz', 'version' => '2'])->status);
        $row = $db->one('SELECT * FROM offer_sections WHERE offer_id = ? AND key = ?', [$id, $key]);
        eq(['Wersja wspólna.', 3, $uid], [$row['body'], (int) $row['version'], (int) $row['updated_by']]);
        contains('Ostatnia zmiana: Ty', http('GET', '/oferty/' . $id, [], ['sekcja' => $key])->body);
        // Nowa wersja od AI też unieważnia otwarty formularz.
        eq(303, http('POST', $url . '/ai')->status);
        eq(4, (int) $db->val('SELECT version FROM offer_sections WHERE offer_id = ? AND key = ?', [$id, $key]));
        eq(409, http('POST', $url, ['body' => 'Stary formularz.', 'title' => 'Formularz', 'version' => '3'])->status);
        // Zapis bez numeru wersji (np. z zewnętrznego narzędzia) działa jak dotąd.
        eq(303, http('POST', $url, ['body' => 'Bez wersji.', 'title' => 'Formularz'])->status);
        eq('Bez wersji.', $db->val('SELECT body FROM offer_sections WHERE offer_id = ? AND key = ?', [$id, $key]));
    },
];
