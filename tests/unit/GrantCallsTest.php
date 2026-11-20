<?php

use App\Core\Brand;
use App\Core\Db;
use App\Modules\Notify\Digest;
use App\Modules\Offers\GrantCalls;

$day = static fn (int $plus): string => date('Y-m-d', strtotime(today_pl() . ' ' . ($plus >= 0 ? '+' : '') . $plus . ' days'));

return [
    'Nabory dotacji: gdzie szukać, wpis naboru, kolejność według końca i poprawki' => function () use ($day) {
        [$org, $uid] = make_org();
        login_as($uid);
        $db = Db::get();
        $page = http('GET', '/oferty/nabory')->body;
        foreach (GrantCalls::SOURCES as $s) {
            contains('href="' . $s['url'] . '"', $page);
        }
        contains('Co przepisać z ogłoszenia', $page);
        contains('Nie obserwujesz żadnego naboru', $page);
        contains('href="/oferty/nabory">Nabory dotacji</a>', http('GET', '/oferty')->body);
        // Błędne wpisy: komunikat i brak zapisu.
        $bad = [
            [['institution' => 'PARP'], 'Podaj nazwę naboru'],
            [['name' => 'Nabór A', 'url' => 'ftp://parp.example/nabor'], 'Link do naboru musi zaczynać się'],
            [['name' => 'Nabór A', 'closes_at' => '31.12.2026'], 'Data ma nieczytelny zapis'],
            [['name' => 'Nabór A', 'opens_at' => $day(10), 'closes_at' => $day(5)], 'Koniec naboru wypada przed jego początkiem'],
        ];
        foreach ($bad as [$post, $msg]) {
            $r = http('POST', '/oferty/nabory', $post);
            eq(422, $r->status, $msg);
            contains($msg, $r->body);
        }
        contains('value="Nabór A"', $r->body, 'wpisana nazwa zostaje w formularzu');
        eq(0, (int) $db->val('SELECT COUNT(*) FROM grant_calls'));
        // Trzy nabory: późny, bliski i bez daty.
        eq(303, http('POST', '/oferty/nabory', ['name' => 'Ścieżka SMART <b>2026</b>', 'institution' => 'PARP', 'url' => 'https://www.parp.gov.pl/nabor/1', 'opens_at' => $day(-20), 'closes_at' => $day(40),
            'amount_note' => 'do 500 000 zł, 50%', 'audience_note' => 'MŚP', 'notes' => "wkład własny 50%\n<i>uwaga</i>"])->status);
        http('POST', '/oferty/nabory', ['name' => 'Bon na cyfryzację', 'closes_at' => $day(5)]);
        http('POST', '/oferty/nabory', ['name' => 'Program regionalny', 'opens_at' => $day(12)]);
        eq(['Bon na cyfryzację', 'Ścieżka SMART <b>2026</b>', 'Program regionalny'], array_column(GrantCalls::current($org), 'name'));
        $page = http('GET', '/oferty/nabory')->body;
        contains('Ścieżka SMART &lt;b&gt;2026&lt;/b&gt;', $page);
        ok(!str_contains($page, '<b>2026</b>') && !str_contains($page, '<i>uwaga</i>'), 'wpisane dane jako tekst');
        contains('<strong>Ile:</strong> do 500 000 zł, 50%', $page);
        contains('<strong>Dla kogo:</strong> MŚP', $page);
        contains('koniec za 5 dni (' . date('d.m.Y', strtotime($day(5))) . ')', $page);
        contains('do ' . date('d.m.Y', strtotime($day(40))) . ' (40 dni)', $page);
        contains('start ' . date('d.m.Y', strtotime($day(12))) . ' (za 12 dni)', $page);
        contains('Obserwowane nabory', $page);
        // Poprawka daty przez formularz edycji.
        $id = (int) $db->val("SELECT id FROM grant_calls WHERE name = 'Bon na cyfryzację'");
        contains('value="Bon na cyfryzację"', http('GET', '/oferty/nabory', [], ['edytuj' => $id])->body);
        eq(303, http('POST', '/oferty/nabory/' . $id, ['name' => 'Bon na cyfryzację', 'closes_at' => $day(7), 'institution' => 'PARP'])->status);
        eq([$day(7), 'PARP', 'watch'], [substr((string) $db->val('SELECT closes_at FROM grant_calls WHERE id = ?', [$id]), 0, 10), $db->val('SELECT institution FROM grant_calls WHERE id = ?', [$id]), $db->val('SELECT status FROM grant_calls WHERE id = ?', [$id])]);
        // Lista ofert i pulpit pokazują nabór, który kończy się w ciągu 14 dni.
        contains('Kończy się obserwowany nabór', http('GET', '/oferty')->body);
        contains('Nabory dotacji (3)', http('GET', '/oferty')->body);
        $board = http('GET', '/pulpit')->body;
        contains('Zbliżające się terminy', $board);
        contains('nabór dotacji · wniosek nierozpoczęty', $board);
    },
    'Nabór dotacji: wniosek z naboru, przypomnienia o końcu, odrzucenie i izolacja firm' => function () use ($day) {
        [$org, $uid] = make_org();
        login_as($uid);
        $db = Db::get();
        http('POST', '/oferty/nabory', ['name' => 'Kredyt technologiczny', 'institution' => 'BGK', 'url' => 'https://www.bgk.example/nabor', 'closes_at' => $day(14)]);
        $id = (int) $db->val('SELECT id FROM grant_calls');
        // Start wniosku: szkic z nazwą, linkiem i terminem naboru. Drugie kliknięcie otwiera ten sam szkic.
        $r = http('POST', '/oferty/nabory/' . $id . '/stan', ['start' => '1']);
        eq(303, $r->status);
        $offer = $db->one('SELECT * FROM offers');
        eq(['grant', 'Kredyt technologiczny', 'https://www.bgk.example/nabor'], [$offer['kind'], $offer['call_name'], $offer['call_url']]);
        eq($day(14), local_dt($offer['deadline_at'], 'Y-m-d'));
        eq(['apply', (int) $offer['id']], [$db->val('SELECT status FROM grant_calls'), (int) $db->val('SELECT offer_id FROM grant_calls')]);
        http('POST', '/oferty/nabory/' . $id . '/stan', ['start' => '1']);
        eq(1, (int) $db->val('SELECT COUNT(*) FROM offers'), 'wniosek nie powstaje drugi raz');
        $page = http('GET', '/oferty/nabory')->body;
        contains('href="/oferty/' . $offer['id'] . '">Otwórz wniosek</a>', $page);
        contains('wniosek: szkic', $page);
        // Przypomnienia: 14 i 7 dni przed końcem, potem codziennie od 3 dni. W pozostałe dni cisza.
        $orgRow = (array) $db->one('SELECT * FROM organizations WHERE id = ?', [$org]);
        $remind = static function (int $plus) use ($db, $day, $orgRow): ?array {
            $db->update('grant_calls', ['closes_at' => $day($plus)], '1 = 1');
            return Digest::compose($orgRow, now_utc(), 'https://panel.example');
        };
        $mail = $remind(14);
        contains('NABORY DOTACJI: ZBLIŻA SIĘ KONIEC (1)', $mail['body']);
        contains('- za 14 dni: Kredyt technologiczny (wniosek: szkic)', $mail['body']);
        contains('https://panel.example/oferty/nabory', $mail['body']);
        eq(null, $remind(13));
        eq(null, $remind(8));
        contains('- za 7 dni: Kredyt technologiczny', $remind(7)['body']);
        eq(null, $remind(4));
        contains('- za 3 dni:', $remind(3)['body']);
        contains('- jutro:', $remind(1)['body']);
        contains('- dziś:', $remind(0)['body']);
        // Po końcu naboru: brak przypomnień, nabór przechodzi do zakończonych, wniosek zostaje dostępny.
        eq(null, $remind(-1));
        eq([], GrantCalls::current($org));
        $page = http('GET', '/oferty/nabory')->body;
        contains('Zakończone i odrzucone', $page);
        contains('zakończony ' . date('d.m.Y', strtotime($day(-1))), $page);
        // Odrzucony nabór nie przypomina o sobie, a przywrócony wraca na listę.
        $db->update('grant_calls', ['closes_at' => $day(2)], '1 = 1');
        eq(303, http('POST', '/oferty/nabory/' . $id . '/stan', ['status' => 'skip'])->status);
        eq(null, Digest::compose($orgRow, now_utc(), 'https://panel.example'));
        eq([], GrantCalls::current($org));
        http('POST', '/oferty/nabory/' . $id . '/stan', ['status' => 'watch']);
        http('POST', '/oferty/nabory/' . $id . '/stan', ['status' => 'cokolwiek']);
        eq(1, count(GrantCalls::current($org)));
        // Druga firma: nic nie widzi i niczego nie zmieni.
        [$org2, $uid2] = make_org('obca@example.com', 'Obca sp. z o.o.');
        login_as($uid2);
        ok(!str_contains(http('GET', '/oferty/nabory')->body, 'Kredyt technologiczny'), 'cudzy nabór niewidoczny');
        eq(404, http('GET', '/oferty/nabory', [], ['edytuj' => $id])->status);
        eq(404, http('POST', '/oferty/nabory/' . $id, ['name' => 'Przejęty'])->status);
        eq(404, http('POST', '/oferty/nabory/' . $id . '/stan', ['start' => '1'])->status);
        eq(404, http('POST', '/oferty/nabory/' . $id . '/stan', ['delete' => '1'])->status);
        eq(['Kredyt technologiczny', 1], [$db->val('SELECT name FROM grant_calls'), (int) $db->val('SELECT COUNT(*) FROM offers')]);
        eq(0, GrantCalls::currentCount($org2));
        // Usunięcie naboru zostawia szkic wniosku. Firma bez modułu „Oferty” nie dostaje przypomnień.
        login_as($uid);
        Brand::save($org, ['modules' => ['przetargi']]);
        eq(null, Digest::compose((array) $db->one('SELECT * FROM organizations WHERE id = ?', [$org]), now_utc(), 'https://panel.example'));
        eq(303, http('POST', '/oferty/nabory/' . $id . '/stan', ['delete' => '1'])->status);
        eq([0, 1], [(int) $db->val('SELECT COUNT(*) FROM grant_calls'), (int) $db->val('SELECT COUNT(*) FROM offers')]);
    },
];
