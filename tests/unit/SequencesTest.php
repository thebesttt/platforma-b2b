<?php

use App\Core\Brand;
use App\Core\Db;
use App\Modules\Notify\Digest;
use App\Modules\Outreach\Outreach;
use App\Modules\Outreach\RiskPolicy;
use App\Modules\Outreach\Sequences;

$contact = static function (int $org, array $over = []): int {
    return Db::get()->insert('contacts', $over + [
        'org_id' => $org, 'company_name' => 'Hurtownia Volt sp. z o.o.', 'person' => 'Anna Nowak', 'email' => 'anna.nowak@volt.example', 'basis' => 'rfq',
        'basis_detail' => 'Zapytanie o montaż instalacji', 'basis_at' => gmdate('Y-m-d'), 'created_at' => now_utc(),
    ]);
};
/** Przygotowanie wiadomości przez formularz (z potwierdzeniem ryzyka, gdy trzeba) i zwrot jej numeru. */
$prepare = static function (int $contactId, array $post = []): int {
    $r = http('POST', '/klienci/' . $contactId . '/wiadomosc', $post + ['action' => 'save', 'kind' => 'rfq_reply', 'subject' => 'Odpowiedź na zapytanie', 'body' => 'Treść oferty.', 'ack' => '1']);
    eq(303, $r->status, 'zapis wiadomości');
    return (int) Db::get()->val('SELECT MAX(id) FROM messages WHERE contact_id = ?', [$contactId]);
};
/** Przesuwa termin kolejnego kroku w przeszłość, żeby przypomnienie było „na dziś”. */
$makeDue = static fn (int $seqId) => Db::get()->update('sequences', ['next_at' => gmdate('Y-m-d H:i:s', time() - 3600)], 'id = ?', [$seqId]);

return [
    'Link wypisania: w stopce oferty, strona bez logowania, wypisuje dopiero przycisk' => function () use ($contact) {
        [$org, $uid] = make_org();
        login_as($uid);
        $db = Db::get();
        $cid = $contact($org, ['basis' => 'none']);
        $page = http('GET', '/klienci/' . $cid . '/wiadomosc', [], ['kind' => 'cold_offer'])->body;
        ok(preg_match('~http://localhost/wypisz/([a-f0-9]{40})~', $page, $m) === 1, 'adres wypisania w treści szkicu');
        $token = $m[1];
        eq($token, $db->val('SELECT unsub_token FROM contacts WHERE id = ?', [$cid]));
        contains($token, http('GET', '/klienci/' . $cid . '/wiadomosc', [], ['kind' => 'consented'])->body, 'ten sam adres w każdej wiadomości');
        ok(!str_contains(http('GET', '/klienci/' . $cid . '/wiadomosc', [], ['kind' => 'consent_request'])->body, '/wypisz/'), 'prośba o zgodę bez linku');
        // Szkic AI oferty też kończy się adresem wypisania.
        $ai = http('POST', '/klienci/' . $cid . '/wiadomosc', ['action' => 'ai', 'kind' => 'cold_offer', 'subject' => '', 'body' => ''])->body;
        contains('Rezygnacja z wiadomości: http://localhost/wypisz/' . $token, $ai);

        // Odbiorca nie jest zalogowany. Otwarcie strony niczego nie zmienia (skanery poczty otwierają linki).
        $_SESSION = [];
        $form = http('GET', '/wypisz/' . $token);
        eq(200, $form->status);
        eq('noindex, nofollow', $form->headers['X-Robots-Tag'] ?? null);
        contains('Firma Testowa sp. z o.o.', $form->body);
        contains('@volt.example', $form->body);
        ok(!str_contains($form->body, 'anna.nowak@'), 'adres pokazany częściowo');
        ok(!str_contains($form->body, 'Zaloguj'), 'bez przycisków panelu');
        eq(null, $db->val('SELECT unsubscribed_at FROM contacts WHERE id = ?', [$cid]));
        // Przycisk (albo program pocztowy metodą jednego kliknięcia) wypisuje. Token CSRF nie jest potrzebny.
        $done = http('POST', '/wypisz/' . $token, ['_csrf' => 'brak', 'List-Unsubscribe' => 'One-Click']);
        eq(200, $done->status);
        contains('Adres wypisany', $done->body);
        ok($db->val('SELECT unsubscribed_at FROM contacts WHERE id = ?', [$cid]) !== null, 'sprzeciw zapisany');
        $log = $db->one("SELECT * FROM audit_log WHERE action = 'contact.unsubscribe'");
        eq([$org, (string) $cid, 'link'], [(int) $log['org_id'], $log['entity_id'], json_in($log['data_json'])['via']]);
        http('POST', '/wypisz/' . $token);
        eq(1, (int) $db->val("SELECT COUNT(*) FROM audit_log WHERE action = 'contact.unsubscribe'"), 'drugie kliknięcie nie dubluje wpisu');
        contains('Adres wypisany', http('GET', '/wypisz/' . $token)->body);
        // Zły token: 404, bez informacji o kontaktach.
        eq(404, http('GET', '/wypisz/' . str_repeat('a', 40))->status);
        eq(404, http('GET', '/wypisz/zly-token')->status);
        eq(404, http('POST', '/wypisz/' . str_repeat('b', 40))->status);
        // Po wypisaniu panel blokuje ofertę do tej firmy.
        login_as($uid);
        ok(RiskPolicy::assess('cold_offer', Outreach::contact($org, $cid))['blocked'], 'oferta zablokowana po wypisaniu');
    },
    'Przypomnienia: trzy kroki, terminy po 4 i 10 dniach, koniec po ostatniej wiadomości' => function () use ($contact, $prepare, $makeDue) {
        [$org, $uid] = make_org();
        login_as($uid);
        $db = Db::get();
        $cid = $contact($org);
        $mid = $prepare($cid);
        contains('Przypomnij mi o kolejnych krokach', http('GET', '/wiadomosci/' . $mid)->body);
        http('POST', '/wiadomosci/' . $mid . '/wyslana', ['sequence' => '1']);
        $seq = Sequences::activeFor($org, $cid);
        ok($seq !== null, 'sekwencja wystartowała');
        eq([0, 'rfq_reply'], [(int) $seq['step'], $seq['kind']]);
        $days = (strtotime($seq['next_at'] . ' UTC') - time()) / 86400;
        ok($days > 3.99 && $days < 4.01, 'przypomnienie po 4 dniach: ' . round($days, 2));
        eq([(int) $seq['id'], 0], array_map('intval', array_values($db->one('SELECT sequence_id, step FROM messages WHERE id = ?', [$mid]))));
        // Przed terminem nic nie ma na liście „Do wysłania dziś”.
        eq([], Sequences::due($org));
        contains('Dziś nie ma nic do wysłania', http('GET', '/klienci/przypomnienia')->body);
        contains('W toku', http('GET', '/klienci/przypomnienia')->body);
        // Termin minął: krok 2 z gotową treścią nawiązującą do pierwszej wiadomości.
        $makeDue((int) $seq['id']);
        $list = http('GET', '/klienci/przypomnienia')->body;
        contains('/klienci/' . $cid . '/wiadomosc?sekwencja=' . $seq['id'], $list);
        contains('Przypomnienie', $list);
        contains('Przypomnienia (1 na dziś)', http('GET', '/klienci')->body);
        $compose = http('GET', '/klienci/' . $cid . '/wiadomosc', [], ['sekwencja' => $seq['id']])->body;
        contains('Krok 2 z 3: przypomnienie', $compose);
        contains('value="Re: Odpowiedź na zapytanie"', $compose);
        contains('wracam do wiadomości z ' . date('d.m.Y'), $compose);
        contains('name="sequence_id" value="' . $seq['id'] . '"', $compose);
        // Pierwsza wiadomość wyszła 8 dni temu, a przypomnienie wysyłamy dopiero dziś (z opóźnieniem).
        $db->update('sequences', ['started_at' => gmdate('Y-m-d H:i:s', time() - 8 * 86400)], 'id = ?', [(int) $seq['id']]);
        $m2 = $prepare($cid, ['sequence_id' => $seq['id'], 'subject' => 'Re: Odpowiedź na zapytanie', 'body' => 'Wracam do wiadomości.']);
        eq([(int) $seq['id'], 1], array_map('intval', array_values($db->one('SELECT sequence_id, step FROM messages WHERE id = ?', [$m2]))));
        contains('To krok 2 z 3', http('GET', '/wiadomosci/' . $m2)->body);
        http('POST', '/wiadomosci/' . $m2 . '/wyslana');
        $seq = Sequences::find($org, (int) $seq['id']);
        eq([1, 'active'], [(int) $seq['step'], $seq['status']]);
        $days = (strtotime($seq['next_at'] . ' UTC') - time()) / 86400;
        ok($days > 5.99 && $days < 6.01, 'ostatnia wiadomość 6 dni po spóźnionym przypomnieniu, a nie 2 dni: ' . round($days, 2));
        // Ponowne oznaczenie tej samej wiadomości niczego nie przesuwa.
        http('POST', '/wiadomosci/' . $m2 . '/wyslana');
        eq($seq['next_at'], Sequences::find($org, (int) $seq['id'])['next_at']);
        // Ostatni krok kończy sekwencję.
        $makeDue((int) $seq['id']);
        contains('to ostatnia wiadomość w sprawie', http('GET', '/klienci/' . $cid . '/wiadomosc', [], ['sekwencja' => $seq['id']])->body);
        $m3 = $prepare($cid, ['sequence_id' => $seq['id'], 'subject' => 'Re: Odpowiedź na zapytanie', 'body' => 'Ostatnia wiadomość.']);
        http('POST', '/wiadomosci/' . $m3 . '/wyslana');
        $seq = Sequences::find($org, (int) $seq['id']);
        eq(['done', 'finished', null, 2], [$seq['status'], $seq['stop_reason'], $seq['next_at'], (int) $seq['step']]);
        contains('wysłano wszystkie kroki', http('GET', '/klienci/przypomnienia')->body);
        eq(0, Sequences::dueCount($org));
    },
    'Przypomnienia zatrzymuje odpowiedź, wypisanie i ręczne zatrzymanie. Prośba o zgodę ich nie ma' => function () use ($contact, $prepare, $makeDue) {
        [$org, $uid] = make_org();
        login_as($uid);
        $db = Db::get();
        $start = static function (int $cid) use ($prepare, $org): array {
            http('POST', '/wiadomosci/' . $prepare($cid) . '/wyslana', ['sequence' => '1']);
            return (array) Sequences::activeFor($org, $cid);
        };
        // Odpowiedź.
        $a = $contact($org, ['company_name' => 'Firma A <script>alert(1)</script>', 'email' => 'a@a.example']);
        $sa = $start($a);
        $makeDue((int) $sa['id']);
        $list = http('GET', '/klienci/przypomnienia')->body;
        ok(!str_contains($list, '<script>alert(1)</script>') && str_contains($list, 'Firma A &lt;script&gt;'), 'nazwa firmy jako tekst');
        http('POST', '/klienci/' . $a . '/odpowiedz', ['back' => 'sekwencje']);
        eq(['stopped', 'replied'], array_values(array_intersect_key(Sequences::find($org, (int) $sa['id']), ['status' => 1, 'stop_reason' => 1])));
        ok($db->val('SELECT replied_at FROM contacts WHERE id = ?', [$a]) !== null, 'data odpowiedzi');
        eq(0, Sequences::dueCount($org));
        // Po odpowiedzi nie proponujemy nowych przypomnień.
        $mid = $prepare($a);
        ok(!str_contains(http('GET', '/wiadomosci/' . $mid)->body, 'Przypomnij mi o kolejnych krokach'), 'bez przypomnień po odpowiedzi');
        http('POST', '/wiadomosci/' . $mid . '/wyslana', ['sequence' => '1']);
        eq(null, Sequences::activeFor($org, $a));
        // Wypisanie w panelu.
        $b = $contact($org, ['company_name' => 'Firma B', 'email' => 'b@b.example']);
        $sb = $start($b);
        http('POST', '/klienci/' . $b . '/wypisz');
        eq('unsubscribed', Sequences::find($org, (int) $sb['id'])['stop_reason']);
        // Wypisanie linkiem z wiadomości.
        $c = $contact($org, ['company_name' => 'Firma C', 'email' => 'c@c.example']);
        $sc = $start($c);
        $token = basename(Outreach::unsubUrl(Outreach::contact($org, $c), 'http://localhost'));
        $_SESSION = [];
        http('POST', '/wypisz/' . $token);
        eq('unsubscribed', Sequences::find($org, (int) $sc['id'])['stop_reason']);
        // Ręczne zatrzymanie. Cudza firma nie zatrzyma naszej sekwencji i nie użyje jej numeru.
        login_as($uid);
        $d = $contact($org, ['company_name' => 'Firma D', 'email' => 'd@d.example']);
        $sd = $start($d);
        [$org2, $uid2] = make_org('obca@example.com', 'Obca sp. z o.o.');
        login_as($uid2);
        eq(404, http('POST', '/klienci/przypomnienia/' . $sd['id'] . '/zatrzymaj')->status);
        eq(404, http('POST', '/klienci/' . $d . '/odpowiedz')->status);
        ok(!str_contains(http('GET', '/klienci/przypomnienia')->body, 'Firma D'), 'cudze przypomnienia niewidoczne');
        $own = $contact($org2, ['company_name' => 'Kontakt Obcej', 'email' => 'x@x.example']);
        $mid = $prepare($own, ['sequence_id' => $sd['id']]);
        eq(null, $db->val('SELECT sequence_id FROM messages WHERE id = ?', [$mid]), 'numer cudzej sekwencji jest ignorowany');
        login_as($uid);
        eq(303, http('POST', '/klienci/przypomnienia/' . $sd['id'] . '/zatrzymaj')->status);
        eq('manual', Sequences::find($org, (int) $sd['id'])['stop_reason']);
        // Prośba o zgodę: bez przypomnień, nawet gdy ktoś wyśle pole ręcznie.
        $e = $contact($org, ['company_name' => 'Firma E', 'email' => 'e@e.example', 'basis' => 'none']);
        $mid = $prepare($e, ['kind' => 'consent_request']);
        ok(!str_contains(http('GET', '/wiadomosci/' . $mid)->body, 'Przypomnij mi o kolejnych krokach'), 'prośba o zgodę bez pola');
        http('POST', '/wiadomosci/' . $mid . '/wyslana', ['sequence' => '1']);
        eq(null, Sequences::activeFor($org, $e));
    },
    'Dzienny limit: lista na dziś mieści się w limicie, ostrzeżenie przy wysyłce, przypomnienia w porannym przeglądzie' => function () use ($contact, $prepare, $makeDue) {
        [$org, $uid] = make_org();
        login_as($uid);
        $db = Db::get();
        eq(Sequences::DEFAULT_LIMIT, Sequences::limit((array) $db->one('SELECT * FROM organizations WHERE id = ?', [$org])));
        foreach (['Alfa', 'Beta', 'Gamma'] as $i => $name) {
            $cid = $contact($org, ['company_name' => 'Firma ' . $name, 'email' => strtolower($name) . '@firma.example']);
            http('POST', '/wiadomosci/' . $prepare($cid) . '/wyslana', ['sequence' => '1']);
            $makeDue((int) Sequences::activeFor($org, $cid)['id']);
        }
        eq(3, Sequences::sentToday($org));
        eq(3, Sequences::dueCount($org));
        // Błędny limit i limit ustawiany przez osobę bez uprawnień.
        http('POST', '/klienci/przypomnienia/limit', ['limit' => '0']);
        http('POST', '/klienci/przypomnienia/limit', ['limit' => '500']);
        eq(Sequences::DEFAULT_LIMIT, Sequences::limit((array) $db->one('SELECT * FROM organizations WHERE id = ?', [$org])));
        http('POST', '/klienci/przypomnienia/limit', ['limit' => '4']);
        eq(4, Sequences::limit((array) $db->one('SELECT * FROM organizations WHERE id = ?', [$org])));
        // Limit 4, wysłane 3: na dziś jedno przypomnienie, dwa czekają.
        $list = http('GET', '/klienci/przypomnienia')->body;
        contains('Dziś wysłano 3 z 4', $list);
        contains('Firma Alfa', $list);
        contains('Jeszcze 2 przypomnienia czekają na jutro', $list);
        ok(substr_count($list, '?sekwencja=') === 1, 'jedno przypomnienie na liście');
        // Limit wyczerpany: komunikat na liście i ostrzeżenie przy kolejnej wiadomości. Decyzja zostaje po stronie użytkownika.
        http('POST', '/klienci/przypomnienia/limit', ['limit' => '3']);
        contains('Dzisiejszy limit jest wykorzystany', http('GET', '/klienci/przypomnienia')->body);
        $extra = $prepare($contact($org, ['company_name' => 'Firma Delta', 'email' => 'delta@firma.example']));
        contains('limit firmy to 3 dziennie', http('GET', '/wiadomosci/' . $extra)->body);
        eq(303, http('POST', '/wiadomosci/' . $extra . '/wyslana')->status, 'limit ostrzega, nie blokuje');
        eq(4, Sequences::sentToday($org));
        // Poranny przegląd wymienia przypomnienia na dziś.
        $mail = Digest::compose((array) $db->one('SELECT * FROM organizations WHERE id = ?', [$org]), gmdate('Y-m-d H:i:s', time() - 60), 'https://panel.example');
        contains('PRZYPOMNIENIA DO WYSŁANIA DZIŚ (3)', $mail['body']);
        contains('- Firma Alfa: przypomnienie', $mail['body']);
        contains('https://panel.example/klienci/przypomnienia', $mail['body']);
        // Firma bez modułu „Klienci” nie dostaje tej części.
        Brand::save($org, ['modules' => ['przetargi']]);
        eq(null, Digest::compose((array) $db->one('SELECT * FROM organizations WHERE id = ?', [$org]), gmdate('Y-m-d H:i:s', time() - 60), 'https://panel.example'));
    },
];
