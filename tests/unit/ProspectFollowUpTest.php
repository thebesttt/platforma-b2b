<?php

use App\Core\Db;
use App\Modules\Notify\Digest;
use App\Modules\Outreach\ProspectEvents;
use App\Modules\Outreach\Prospects;

return [
    'Drugi list: przypomnienie po trzech tygodniach bez odpowiedzi, krótsza treść i koniec przypomnień po dwóch listach' => function () {
        [$orgId, $uid] = make_org('wlasciciel@example.com', 'Firma Testowa sp. z o.o.');
        $db = Db::get();
        $db->update('organizations', ['nip' => '5260000005', 'address' => 'ul. Nadawcy 5, 00-002 Warszawa'], 'id = ?', [$orgId]);
        login_as($uid);
        http('POST', '/klienci/potencjalni/lista', ['lines' => "1234563218 Przykładowa Budowlana\n8410000015 Druga\n8410000021 Trzecia\n8410000038 Czwarta\n8410000044 Piąta"]);
        Prospects::enrichPending($orgId);
        $ids = array_map('intval', array_column($db->all('SELECT id FROM prospects WHERE org_id = ? ORDER BY id', [$orgId]), 'id'));
        [$a, $b, $c, $d, $e] = $ids;
        // Pozostałe firmy dostają adres jak z rejestrów, żeby były gotowe do listu.
        foreach ([$b => '8410000015', $c => '8410000021', $d => '8410000038', $e => '8410000044'] as $pid => $nip) {
            $cid = $db->insert('companies', ['nip' => $nip, 'name' => 'Firma ' . $nip, 'address' => 'ul. Testowa 1, 00-001 Warszawa', 'created_at' => now_utc()]);
            $db->update('prospects', ['company_id' => $cid], 'id = ?', [$pid]);
        }
        $ago = static fn (int $days): string => gmdate('Y-m-d H:i:s', time() - $days * 86400 - 3600);
        $sendOn = static function (int $pid, int $daysAgo) use ($db, $orgId, $uid, $ago): void {
            Prospects::markContacted($orgId, $pid, 'letter', $uid);
            $db->run("UPDATE prospect_events SET created_at = ? WHERE prospect_id = ? AND kind = 'letter' AND id = (SELECT MAX(id) FROM prospect_events WHERE prospect_id = ?)", [$ago($daysAgo), $pid, $pid]);
        };
        $dueIds = static fn (?string $since = null): array => array_map(static fn ($p) => (int) $p['id'], Prospects::followUps($orgId, $since));
        $url = '/klienci/potencjalni/' . $a;

        // Pierwszy list: pełna treść. Bez listów nie ma czego przypominać.
        eq([], $dueIds());
        Prospects::savePitch($db->one('SELECT * FROM prospects WHERE id = ?', [$a]), 'Nasza treść oferty: ' . str_repeat('prowadzimy kampanie i budujemy strony dla firm budowlanych. ', 6));
        $first = html_entity_decode(http('GET', $url . '/list')->body);
        contains('Dotyczy: propozycja współpracy', $first);
        contains('Nasza treść oferty', $first);
        ok(!str_contains($first, 'To drugi, krótszy list'), 'pierwszy list bez noty o przypomnieniu');

        // List sprzed 10 dni: za wcześnie. Sprzed 22 dni bez odpowiedzi: pora na drugi.
        $sendOn($a, 10);
        eq([], $dueIds());
        ok(!str_contains(http('GET', '/klienci/potencjalni')->body, 'id="drugi-list"'), 'przed terminem lista nie przypomina');
        $db->run("UPDATE prospect_events SET created_at = ? WHERE prospect_id = ?", [$ago(22), $a]);
        eq([$a], $dueIds());

        // Firmy, które zareagowały, nie dostają przypomnienia: zgoda, odmowa, prośba o raport, odpowiedź. Firma po dwóch listach też nie.
        $sendOn($b, 30);
        http('POST', '/klienci/potencjalni/' . $b . '/zgoda', ['email' => 'b@example.com', 'way' => 'letter_reply', 'detail' => 'Odpisali na list z prośbą o ofertę.', 'on' => today_pl(), 'confirm' => '1']);
        $sendOn($c, 30);
        Prospects::object($orgId, $db->one('SELECT * FROM prospects WHERE id = ?', [$c]));
        $sendOn($d, 30);
        ProspectEvents::add($orgId, $d, 'report');
        $sendOn($e, 40);
        $sendOn($e, 5);
        eq([$a], $dueIds(), 'tylko firma z jednym listem i bez żadnej reakcji');

        // Przypomnienia: lista, karta, ekran listów seryjnych i poranny przegląd.
        $list = http('GET', '/klienci/potencjalni')->body;
        contains('1 firma nie odpowiedziała</strong>', str_replace(' na list sprzed 21 dni.', '', $list));
        contains('href="/klienci/potencjalni/listy?drugi=1">przygotuj przypomnienia</a>', $list);
        contains('Możesz wysłać drugi, krótszy list.', http('GET', $url)->body);
        ok(!str_contains(http('GET', '/klienci/potencjalni/' . $e)->body, 'Możesz wysłać drugi, krótszy list.'), 'po dwóch listach karta nie przypomina');
        $org = $db->one('SELECT * FROM organizations WHERE id = ?', [$orgId]);
        $mail = Digest::compose($org, gmdate('Y-m-d H:i:s', time() - 2 * 86400), 'http://localhost');
        contains('LISTY BEZ ODPOWIEDZI: PORA NA DRUGI, KRÓTSZY (1)', $mail['body']);
        contains('http://localhost/klienci/potencjalni/listy?drugi=1', $mail['body']);
        // Firma, której termin minął wcześniej, nie wraca w kolejnych przeglądach.
        $db->run("UPDATE prospect_events SET created_at = ? WHERE prospect_id = ?", [$ago(25), $a]);
        ok(!str_contains((string) (Digest::compose($org, gmdate('Y-m-d H:i:s', time() - 2 * 86400), 'http://localhost')['body'] ?? ''), 'LISTY BEZ ODPOWIEDZI'), 'przegląd przypomina raz');
        eq([$a], $dueIds(), 'na liście przypomnienie zostaje do czasu drugiego listu');

        // Drugi list: krótki, z datą pierwszego, tym samym adresem strony zgody, zapowiedzią końca i bez powtórzenia oferty.
        $page = http('GET', $url . '/list')->body;
        contains('To drugi, krótszy list.', $page);
        $second = html_entity_decode($page);
        $firstDate = local_date($ago(25));
        $code = (string) $db->val('SELECT consent_code FROM prospects WHERE id = ?', [$a]);
        contains('Dotyczy: nasz list z ' . $firstDate, $second);
        contains($firstDate . ' wysłaliśmy do Państwa list z propozycją współpracy. Piszemy jeszcze raz, krótko, i na tym poprzestaniemy.', $second);
        contains('Wystarczy wejść na stronę http://localhost/z/' . $code . ' albo zeskanować telefonem kod pod tym akapitem i wpisać adres. Bez Państwa zgody nie wyślemy żadnej wiadomości.', $second);
        contains('To nasz ostatni list w tej sprawie.', $second);
        ok(!str_contains($second, 'Nasza treść oferty'), 'przypomnienie nie powtarza treści oferty');
        $bodyOf = static fn (string $html): string => preg_match('~<textarea[^>]*name="body"[^>]*>(.*?)</textarea>~s', $html, $m) ? $m[1] : '';
        ok($bodyOf($second) !== '' && mb_strlen($bodyOf($second)) < mb_strlen($bodyOf($first)) * 0.85, 'drugi list jest wyraźnie krótszy');

        // Listy seryjnie w trybie drugiego listu: zaznaczona tylko firma do przypomnienia, plik ma treść przypomnienia.
        $bulk = http('GET', '/klienci/potencjalni/listy', [], ['drugi' => '1'])->body;
        contains('Zaznaczone są firmy, które nie odpowiedziały na pierwszy list: 1.', $bulk);
        contains('name="ids[]" value="' . $a . '" id="bl-' . $a . '" checked>', $bulk);
        contains('pora na drugi list', $bulk);
        ok(!str_contains($bulk, 'value="' . $e . '" id="bl-'), 'firma po dwóch listach nie wchodzi do paczki');
        contains('wyszły już 2 listy: kolejny tylko pojedynczo, z karty firmy', $bulk);
        contains('1 firma nie odpowiedziała na list sprzed 21 dni.', http('GET', '/klienci/potencjalni/listy')->body);
        $r = http('POST', '/klienci/potencjalni/listy', ['action' => 'docx', 'ids' => [(string) $a]], ['drugi' => '1']);
        eq(200, $r->status);
        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($tmp, $r->body);
        $zip = new ZipArchive();
        $zip->open($tmp);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($tmp);
        contains('To nasz ostatni list w tej sprawie.', $xml);
        contains('<w:drawing>', $xml, 'przypomnienie też ma kod QR');

        // Po drugim liście: dwa listy w lejku, koniec przypomnień, a ekran listu ostrzega przed trzecim.
        eq(303, http('POST', '/klienci/potencjalni/listy', ['action' => 'sent', 'ids' => [(string) $a]], ['drugi' => '1'])->status);
        eq([], $dueIds());
        ok(!str_contains(http('GET', '/klienci/potencjalni')->body, 'id="drugi-list"'), 'po drugim liście lista nie przypomina');
        $f = ProspectEvents::funnel($orgId);
        eq([5, 7], [$f['firms'], $f['letters']]);
        $third = http('GET', $url . '/list')->body;
        contains('Do tej firmy wyszły już 2 listy', $third);
        contains('Panel nie przypomina o kolejnych.', $third);

        // Firma z raportem z audytu: przypomnienie mówi o raporcie, który czeka.
        http('POST', '/klienci/potencjalni/lista', ['lines' => '8410000050 Szósta']);
        $g = (int) $db->val('SELECT id FROM prospects ORDER BY id DESC');
        $cid = $db->insert('companies', ['nip' => '8410000050', 'name' => 'Szósta sp. z o.o.', 'address' => 'ul. Testowa 6, 00-001 Warszawa', 'created_at' => now_utc()]);
        $db->update('prospects', ['company_id' => $cid, 'website' => 'https://szosta.example', 'host' => 'szosta.example', 'enriched_at' => now_utc()], 'id = ?', [$g]);
        $aid = $db->insert('site_audits', ['org_id' => $orgId, 'url' => 'https://szosta.example/', 'host' => 'szosta.example', 'score' => 44, 'created_by' => $uid, 'created_at' => now_utc(),
            'result_json' => json_out(['host' => 'szosta.example', 'final_url' => 'https://szosta.example/', 'scanned_at' => gmdate('c'), 'score' => 44, 'grade' => 'poor', 'categories' => [],
                'counts' => [], 'services' => [], 'psi_done' => false, 'meta' => ['prestashop' => ['is' => false]], 'findings' => []])]);
        App\Modules\Outreach\ProspectAudit::attach($orgId, $db->one('SELECT * FROM prospects WHERE id = ?', [$g]), $aid);
        $sendOn($g, 23);
        contains('Bezpłatny raport z audytu strony szosta.example nadal na Państwa czeka.', html_entity_decode(http('GET', '/klienci/potencjalni/' . $g . '/list')->body));

        // Druga firma w panelu nie widzi cudzych przypomnień.
        [$org2, $uid2] = make_org('druga@example.com', 'Druga sp. z o.o.');
        eq([], Prospects::followUps($org2));
        login_as($uid2);
        ok(!str_contains(http('GET', '/klienci/potencjalni')->body, 'id="drugi-list"'), 'cudze listy bez odpowiedzi nie są widoczne');
    },
];
