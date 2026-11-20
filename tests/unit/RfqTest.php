<?php

use App\Core\Brand;
use App\Core\Db;
use App\Modules\Notify\Digest;
use App\Modules\Outreach\Rfqs;
use App\Modules\Outreach\Sequences;

$day = static fn (int $plus): string => date('Y-m-d', strtotime(today_pl() . ' ' . ($plus >= 0 ? '+' : '') . $plus . ' days'));

return [
    'Zapytania z rynku: strona pokazuje, gdzie szukać, i nie zapisuje niepełnych wpisów' => function () {
        [$org, $uid] = make_org();
        login_as($uid);
        $db = Db::get();
        $page = http('GET', '/klienci/zapytania')->body;
        // Przewodnik po źródłach z adresami.
        contains('href="https://bazakonkurencyjnosci.funduszeeuropejskie.gov.pl/"', $page);
        contains('href="https://platformazakupowa.pl/all"', $page);
        contains('zakładka „Oferty”', $page);
        contains('Nie masz zapytań do odpowiedzi', $page);
        contains('Nie masz jeszcze profilu przetargowego', $page);
        // Słowa z profilu przetargowego jako podpowiedź do wyszukiwarek.
        $db->insert('tender_profiles', ['org_id' => $org, 'name' => 'Drogi', 'cpv' => '45233', 'keywords' => "nawierzchni\nchodnik, parking\nab", 'active' => 1, 'created_at' => now_utc()]);
        $page = http('GET', '/klienci/zapytania')->body;
        contains('<code class="copy">nawierzchni</code><code class="copy">chodnik</code><code class="copy">parking</code>', $page);
        ok(!str_contains($page, '<code class="copy">ab</code>'), 'dwuliterowe słowo nie jest podpowiedzią');
        // Link z listy kontaktów.
        contains('href="/klienci/zapytania">Zapytania z rynku</a>', http('GET', '/klienci')->body);
        // Niepełne i błędne wpisy: komunikat, wpisane wartości zostają w formularzu, nic się nie zapisuje.
        $bad = [
            [['subject' => 'Remont placu', 'url' => 'https://firma.example/zapytanie'], 'Podaj nazwę firmy'],
            [['company_name' => 'Firma A', 'url' => 'https://firma.example/zapytanie'], 'Wpisz, czego dotyczy zapytanie'],
            [['company_name' => 'Firma A', 'subject' => 'Remont placu'], 'Podaj link do zapytania albo adres e-mail'],
            [['company_name' => 'Firma A', 'subject' => 'Remont placu', 'email' => 'zakupy(at)firma'], 'Adres e-mail ma błąd'],
            [['company_name' => 'Firma A', 'subject' => 'Remont placu', 'url' => 'javascript:alert(1)'], 'Link do zapytania musi zaczynać się'],
            [['company_name' => 'Firma A', 'subject' => 'Remont placu', 'url' => 'https://firma.example/z', 'nip' => '1234567890'], 'NIP ma błąd'],
            [['company_name' => 'Firma A', 'subject' => 'Remont placu', 'url' => 'https://firma.example/z', 'deadline_at' => '2026-02-31'], 'Data ma nieczytelny zapis'],
        ];
        foreach ($bad as [$post, $msg]) {
            $r = http('POST', '/klienci/zapytania', $post);
            eq(422, $r->status, $msg);
            contains($msg, $r->body);
        }
        contains('value="Remont placu"', $r->body, 'wpisany temat zostaje w formularzu');
        eq(0, (int) $db->val('SELECT COUNT(*) FROM rfqs') + (int) $db->val('SELECT COUNT(*) FROM contacts'));
    },
    'Zapytanie z adresem e-mail: kontakt z podstawą „zapytanie ofertowe”, odpowiedź o niskim ryzyku, stan po wysłaniu' => function () use ($day) {
        [$org, $uid] = make_org();
        login_as($uid);
        $db = Db::get();
        $r = http('POST', '/klienci/zapytania', [
            'company_name' => 'Zakład Komunalny <b>Test</b>', 'nip' => '718-196-82-22', 'subject' => 'Remont nawierzchni placu <script>x</script>', 'url' => 'https://zk.example/zapytania/12',
            'source' => 'www', 'deadline_at' => $day(6), 'published_at' => $day(-2), 'email' => 'Zakupy@ZK.example', 'person' => 'Ewa Nowak', 'notes' => "wymagane referencje\nwadium 2000 zł",
        ]);
        eq(303, $r->status);
        $q = $db->one('SELECT * FROM rfqs');
        eq(['new', 'www', '7181968222', 'zakupy@zk.example', $day(6)], [$q['status'], $q['source'], $q['nip'], $q['email'], substr((string) $q['deadline_at'], 0, 10)]);
        // Kontakt z dowodem: temat, link i data ogłoszenia.
        $c = $db->one('SELECT * FROM contacts WHERE id = ?', [(int) $q['contact_id']]);
        eq(['rfq', 'Remont nawierzchni placu <script>x</script>', 'https://zk.example/zapytania/12', $day(-2), 'Ewa Nowak'],
            [$c['basis'], $c['basis_detail'], $c['basis_url'], substr((string) $c['basis_at'], 0, 10), $c['person']]);
        $page = http('GET', '/klienci/zapytania')->body;
        contains('Remont nawierzchni placu &lt;script&gt;x&lt;/script&gt;', $page);
        contains('Zakład Komunalny &lt;b&gt;Test&lt;/b&gt;', $page);
        ok(!str_contains($page, '<script>x</script>') && !str_contains($page, '<b>Test</b>'), 'wpisane dane jako tekst');
        contains('<a href="/kontrahenci/7181968222">', $page, 'NIP prowadzi do karty firmy');
        contains('za 6 dni (' . date('d.m.Y', strtotime($day(6))) . ')', $page);
        contains('Strona firmy · Ewa Nowak, zakupy@zk.example', $page);
        contains('href="/klienci/' . $c['id'] . '/wiadomosc?kind=rfq_reply">Przygotuj odpowiedź</a>', $page);
        // Odpowiedź: niskie ryzyko, temat zapytania w szkicu, bez oświadczenia o ryzyku.
        $compose = http('GET', '/klienci/' . $c['id'] . '/wiadomosc', [], ['kind' => 'rfq_reply'])->body;
        contains('Firma sama poprosiła o oferty', $compose);
        contains('Odpowiedź na zapytanie ofertowe: Remont nawierzchni placu', $compose);
        eq(303, http('POST', '/klienci/' . $c['id'] . '/wiadomosc', ['kind' => 'rfq_reply', 'action' => 'save', 'subject' => 'Oferta: remont placu', 'body' => 'Treść oferty.'])->status);
        $mid = (int) $db->val('SELECT id FROM messages');
        eq('low', $db->val('SELECT risk FROM messages WHERE id = ?', [$mid]));
        contains('href="/wiadomosci/' . $mid . '">Otwórz przygotowaną odpowiedź</a>', http('GET', '/klienci/zapytania')->body);
        // Po wysłaniu odpowiedzi zapytanie przechodzi do zamkniętych, a przypomnienia startują.
        eq(303, http('POST', '/wiadomosci/' . $mid . '/wyslana', ['sequence' => '1'])->status);
        eq('replied', $db->val('SELECT status FROM rfqs'));
        $page = http('GET', '/klienci/zapytania')->body;
        contains('Nie masz zapytań do odpowiedzi', $page);
        contains('oferta złożona', $page);
        $seq = $db->one('SELECT * FROM sequences');
        eq(['active', 'rfq_reply'], [$seq['status'], $seq['kind']]);
        // Termin składania ofert minął: przypomnienie nie wychodzi, sekwencja zatrzymuje się z powodem.
        $db->update('sequences', ['next_at' => gmdate('Y-m-d H:i:s', time() - 3600)], 'id = ?', [(int) $seq['id']]);
        eq(1, Sequences::dueCount($org));
        $db->update('rfqs', ['deadline_at' => $day(-1)], 'id = ?', [(int) $q['id']]);
        eq([], Sequences::due($org));
        eq(['stopped', 'deadline'], array_values((array) $db->one('SELECT status, stop_reason FROM sequences')));
        contains('minął termin składania ofert', http('GET', '/klienci/przypomnienia')->body);
        // Drugie zapytanie od tej samej firmy podpina się do istniejącego kontaktu i odświeża dowód.
        http('POST', '/klienci/zapytania', ['company_name' => 'Zakład Komunalny', 'subject' => 'Dostawa kruszywa', 'email' => 'zakupy@zk.example', 'deadline_at' => $day(2)]);
        eq(1, (int) $db->val('SELECT COUNT(*) FROM contacts'));
        eq('Dostawa kruszywa', $db->val('SELECT basis_detail FROM contacts'));
    },
    'Zapytanie bez e-maila, istniejące kontakty, stany i izolacja firm' => function () use ($day) {
        [$org, $uid] = make_org();
        login_as($uid);
        $db = Db::get();
        // Bez adresu e-mail: nie powstaje kontakt, oferta idzie przez stronę zapytania.
        eq(303, http('POST', '/klienci/zapytania', ['company_name' => 'Spółka Wodna', 'subject' => 'Budowa parkingu', 'url' => 'https://bazakonkurencyjnosci.funduszeeuropejskie.gov.pl/ogloszenia/1', 'source' => 'bk', 'deadline_at' => $day(0)])->status);
        $id = (int) $db->val('SELECT id FROM rfqs');
        eq([null, 0], [$db->val('SELECT contact_id FROM rfqs'), (int) $db->val('SELECT COUNT(*) FROM contacts')]);
        $page = http('GET', '/klienci/zapytania')->body;
        contains('Otwórz zapytanie i złóż ofertę', $page);
        contains('termin dziś', $page);
        contains('Baza Konkurencyjności', $page);
        // Kontakt ze zgodą zachowuje zgodę. Kontakt bez podstawy dostaje podstawę „zapytanie”.
        $consent = $db->insert('contacts', ['org_id' => $org, 'company_name' => 'Klient Stały', 'email' => 'biuro@staly.example', 'basis' => 'consent', 'basis_detail' => 'zgoda z formularza', 'created_at' => now_utc()]);
        $none = $db->insert('contacts', ['org_id' => $org, 'company_name' => 'Firma Nowa', 'email' => 'kontakt@nowa.example', 'basis' => 'none', 'created_at' => now_utc()]);
        http('POST', '/klienci/zapytania', ['company_name' => 'Klient Stały', 'subject' => 'Naprawa chodnika', 'email' => 'biuro@staly.example']);
        http('POST', '/klienci/zapytania', ['company_name' => 'Firma Nowa', 'subject' => 'Odwodnienie placu', 'email' => 'KONTAKT@nowa.example', 'deadline_at' => $day(9)]);
        eq(['consent', 'zgoda z formularza'], array_values((array) $db->one('SELECT basis, basis_detail FROM contacts WHERE id = ?', [$consent])));
        eq(['rfq', 'Odwodnienie placu'], array_values((array) $db->one('SELECT basis, basis_detail FROM contacts WHERE id = ?', [$none])));
        eq(2, (int) $db->val('SELECT COUNT(*) FROM contacts'), 'istniejące kontakty nie są dublowane');
        // Kolejność: najbliższy termin najpierw, bez terminu na końcu.
        eq(['Budowa parkingu', 'Odwodnienie placu', 'Naprawa chodnika'], array_column(Rfqs::open($org), 'subject'));
        // Poprawka terminu przez formularz edycji.
        contains('value="Budowa parkingu"', http('GET', '/klienci/zapytania', [], ['edytuj' => $id])->body);
        eq(303, http('POST', '/klienci/zapytania/' . $id, ['company_name' => 'Spółka Wodna', 'subject' => 'Budowa parkingu', 'url' => 'https://bazakonkurencyjnosci.funduszeeuropejskie.gov.pl/ogloszenia/1', 'source' => 'bk', 'deadline_at' => $day(2)])->status);
        eq([$day(2), 'new'], [substr((string) $db->val('SELECT deadline_at FROM rfqs WHERE id = ?', [$id]), 0, 10), $db->val('SELECT status FROM rfqs WHERE id = ?', [$id])]);
        // Bliski termin: notka na liście kontaktów i pozycja w porannym przeglądzie.
        contains('Kończy się termin zapytania ofertowego', http('GET', '/klienci')->body);
        contains('Zapytania z rynku (3)', http('GET', '/klienci')->body);
        $orgRow = (array) $db->one('SELECT * FROM organizations WHERE id = ?', [$org]);
        $mail = Digest::compose($orgRow, now_utc(), 'https://panel.example');
        contains('ZAPYTANIA OFERTOWE: KOŃCZY SIĘ TERMIN (1)', $mail['body']);
        contains('- za 2 dni: Budowa parkingu (Spółka Wodna)', $mail['body']);
        contains('https://panel.example/klienci/zapytania', $mail['body']);
        // Stany: pominięte, z powrotem, oferta złożona ręcznie.
        eq(303, http('POST', '/klienci/zapytania/' . $id . '/stan', ['status' => 'skipped'])->status);
        eq('skipped', $db->val('SELECT status FROM rfqs WHERE id = ?', [$id]));
        eq(null, Digest::compose($orgRow, now_utc(), 'https://panel.example'), 'pominięte zapytanie nie wraca w przeglądzie');
        http('POST', '/klienci/zapytania/' . $id . '/stan', ['status' => 'new']);
        http('POST', '/klienci/zapytania/' . $id . '/stan', ['status' => 'cokolwiek']);
        eq('new', $db->val('SELECT status FROM rfqs WHERE id = ?', [$id]));
        http('POST', '/klienci/zapytania/' . $id . '/stan', ['status' => 'replied']);
        eq('replied', $db->val('SELECT status FROM rfqs WHERE id = ?', [$id]));
        // Druga firma nie widzi rejestru, nie zmieni stanu, nie poprawi ani nie usunie cudzego wpisu.
        [$org2, $uid2] = make_org('obca@example.com', 'Obca sp. z o.o.');
        login_as($uid2);
        $other = http('GET', '/klienci/zapytania')->body;
        ok(!str_contains($other, 'Budowa parkingu') && !str_contains($other, 'Naprawa chodnika'), 'cudze zapytania niewidoczne');
        eq(404, http('GET', '/klienci/zapytania', [], ['edytuj' => $id])->status);
        eq(404, http('POST', '/klienci/zapytania/' . $id . '/stan', ['status' => 'skipped'])->status);
        eq(404, http('POST', '/klienci/zapytania/' . $id . '/stan', ['delete' => '1'])->status);
        eq(404, http('POST', '/klienci/zapytania/' . $id, ['company_name' => 'X', 'subject' => 'Y', 'url' => 'https://x.example'])->status);
        eq(['replied', 'Budowa parkingu'], array_values((array) $db->one('SELECT status, subject FROM rfqs WHERE id = ?', [$id])));
        eq([], Rfqs::open($org2));
        // Usunięcie zostawia kontakt.
        login_as($uid);
        eq(303, http('POST', '/klienci/zapytania/' . $id . '/stan', ['delete' => '1'])->status);
        eq([2, 2], [(int) $db->val('SELECT COUNT(*) FROM rfqs'), (int) $db->val('SELECT COUNT(*) FROM contacts')]);
        // Firma bez modułu „Klienci” nie dostaje tej części przeglądu.
        $db->update('rfqs', ['deadline_at' => $day(1)], 'org_id = ?', [$org]);
        contains('ZAPYTANIA OFERTOWE', (string) (Digest::compose($orgRow, now_utc(), 'https://panel.example')['body'] ?? ''));
        Brand::save($org, ['modules' => ['przetargi']]);
        eq(null, Digest::compose((array) $db->one('SELECT * FROM organizations WHERE id = ?', [$org]), now_utc(), 'https://panel.example'));
    },
];
