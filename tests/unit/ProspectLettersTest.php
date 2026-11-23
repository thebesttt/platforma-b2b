<?php

use App\Core\Db;
use App\Core\Qr;
use App\Modules\Outreach\ProspectEvents;
use App\Modules\Outreach\Prospects;

return [
    'Listy seryjnie: jeden plik z kodami QR, adresy na koperty i zbiorcze oznaczenie wysyłki' => function () {
        [$orgId, $uid] = make_org('wlasciciel@example.com', 'Firma Testowa sp. z o.o.');
        $db = Db::get();
        login_as($uid);
        http('POST', '/klienci/potencjalni/lista', ['lines' => "1234563218 Przykładowa Budowlana\nbez-adresu.example Firma Bez Adresu\n5252344078 Druga z adresem"]);
        Prospects::enrichPending($orgId);
        [$a, $b, $c] = array_map('intval', array_column($db->all('SELECT id FROM prospects WHERE org_id = ? ORDER BY id', [$orgId]), 'id'));
        // Druga firma z adresem: wpis w bazie firm jak po odczycie z rejestrów. Nazwa zaczyna się od znaku formuły arkusza.
        $cid = $db->insert('companies', ['nip' => '5252344078', 'name' => '=HURTOWNIA "ALFA" <b>S.A.</b>', 'address' => 'AL. JEROZOLIMSKIE 100 LOK. 5, 00-807 WARSZAWA', 'created_at' => now_utc()]);
        $db->update('prospects', ['company_id' => $cid, 'status' => 'picked'], 'id = ?', [$c]);
        $db->update('prospects', ['status' => 'picked'], 'id = ?', [$a]);

        // Ekran: firmy z adresem są do zaznaczenia, wybrane bez listu zaznaczone z góry. Firma bez adresu jest poza listami z powodem.
        $page = http('GET', '/klienci/potencjalni/listy')->body;
        contains('Firmy gotowe do listu (2)', $page);
        contains('name="ids[]" value="' . $a . '" id="bl-' . $a . '" checked>', $page);
        contains('name="ids[]" value="' . $c . '" id="bl-' . $c . '" checked>', $page);
        ok(!str_contains($page, 'value="' . $b . '" id="bl-'), 'firma bez adresu nie ma pola wyboru');
        contains('brak adresu: dopisz NIP na karcie firmy', $page);
        contains('=HURTOWNIA &quot;ALFA&quot; &lt;b&gt;S.A.&lt;/b&gt;', $page);
        ok(!str_contains($page, '<b>S.A.</b>'), 'nazwa z rejestru jako tekst');
        contains('href="/klienci/potencjalni/listy">Listy seryjnie</a>', http('GET', '/klienci/potencjalni')->body);

        // Bez zaznaczenia i z samymi firmami spoza listy plik nie powstaje.
        eq(303, http('POST', '/klienci/potencjalni/listy', ['action' => 'docx'])->status);
        eq(303, http('POST', '/klienci/potencjalni/listy', ['action' => 'docx', 'ids' => [(string) $b, '999999']])->status);

        // Plik: dwa listy, drugi od nowej strony, każdy z adresem własnej strony zgody i własnym kodem QR.
        $r = http('POST', '/klienci/potencjalni/listy', ['action' => 'docx', 'ids' => [(string) $a, (string) $c, (string) $b]]);
        eq(200, $r->status);
        contains('filename="listy-' . date('Y-m-d') . '.docx"', $r->headers['Content-Disposition']);
        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($tmp, $r->body);
        $zip = new ZipArchive();
        ok($zip->open($tmp) === true, 'poprawny plik .docx');
        $xml = (string) $zip->getFromName('word/document.xml');
        $rels = (string) $zip->getFromName('word/_rels/document.xml.rels');
        $img1 = (string) $zip->getFromName('word/media/image1.png');
        $img2 = (string) $zip->getFromName('word/media/image2.png');
        ok($zip->getFromName('word/media/image3.png') === false, 'tyle obrazów, ile listów');
        $zip->close();
        unlink($tmp);
        $codeA = (string) $db->val('SELECT consent_code FROM prospects WHERE id = ?', [$a]);
        $codeC = (string) $db->val('SELECT consent_code FROM prospects WHERE id = ?', [$c]);
        ok($codeA !== '' && $codeC !== '' && $codeA !== $codeC, 'każda firma ma własny kod strony zgody');
        eq(null, $db->val('SELECT consent_code FROM prospects WHERE id = ?', [$b]), 'firma poza plikiem nie dostaje kodu');
        ok(simplexml_load_string($xml) !== false, 'dokument jest poprawnym XML');
        eq(1, substr_count($xml, '<w:br w:type="page"/>'), 'drugi list od nowej strony');
        eq(2, substr_count($xml, '<w:drawing>'));
        contains('=HURTOWNIA &quot;ALFA&quot; &lt;b&gt;S.A.&lt;/b&gt;', $xml);
        contains('AL. JEROZOLIMSKIE 100 LOK. 5, 00-807 WARSZAWA', $xml);
        ok(strpos($xml, '/z/' . $codeA) < strpos($xml, 'r:embed="rId2"') && strpos($xml, 'r:embed="rId2"') < strpos($xml, '<w:br w:type="page"/>'), 'pierwszy kod pod pierwszym listem');
        ok(strpos($xml, '<w:br w:type="page"/>') < strpos($xml, '/z/' . $codeC) && strpos($xml, '/z/' . $codeC) < strpos($xml, 'r:embed="rId3"'), 'drugi kod pod drugim listem');
        contains('Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image2.png"', $rels);
        eq(Qr::png('http://localhost/z/' . $codeA, 10), $img1, 'pierwszy kod prowadzi na stronę zgody pierwszej firmy');
        eq(Qr::png('http://localhost/z/' . $codeC, 10), $img2, 'drugi kod prowadzi na stronę zgody drugiej firmy');
        eq(0, (int) $db->val('SELECT COUNT(*) FROM prospect_events'), 'pobranie pliku nie oznacza wysyłki');

        // Adresy: plik dla arkusza z polskimi znakami, pola rozbite, wartość zaczynająca się od „=” zabezpieczona.
        $csv = http('POST', '/klienci/potencjalni/listy', ['action' => 'addresses', 'ids' => [(string) $a, (string) $c]]);
        eq(200, $csv->status);
        contains('text/csv', $csv->headers['Content-Type']);
        eq("\xEF\xBB\xBF", substr($csv->body, 0, 3));
        $lines = explode("\r\n", trim(substr($csv->body, 3)));
        eq('"Firma";"Ulica i numer";"Kod pocztowy";"Miejscowość"', $lines[0]);
        eq(3, count($lines));
        eq('"\'=HURTOWNIA ""ALFA"" <b>S.A.</b>";"AL. JEROZOLIMSKIE 100 LOK. 5";"00-807";"WARSZAWA"', $lines[2]);
        eq(['street' => 'ul. Polna 3/5', 'zip' => '05-500', 'city' => 'Piaseczno'], Prospects::addressParts('ul. Polna 3/5,  05-500   Piaseczno'));
        eq(['street' => 'Wólka 12', 'zip' => '21-100', 'city' => 'Lubartów'], Prospects::addressParts('Wólka 12 21-100 Lubartów'));
        eq(['street' => 'Adres bez kodu', 'zip' => '', 'city' => ''], Prospects::addressParts('Adres bez kodu'));

        // Oznaczenie wysyłki: zdarzenie dla każdej firmy, firmy po kontakcie, lejek liczy dwa listy.
        eq(303, http('POST', '/klienci/potencjalni/listy', ['action' => 'sent', 'ids' => [(string) $a, (string) $c]])->status);
        eq(['contacted', 'contacted', 'new'], array_column($db->all('SELECT status FROM prospects WHERE org_id = ? ORDER BY CASE id WHEN ' . $a . ' THEN 0 WHEN ' . $c . ' THEN 1 ELSE 2 END', [$orgId]), 'status'));
        eq(2, ProspectEvents::funnel($orgId)['firms']);
        $page = http('GET', '/klienci/potencjalni/listy')->body;
        contains('1, ostatni ' . local_date(now_utc()), $page);
        contains('name="ids[]" value="' . $a . '" id="bl-' . $a . '">', $page, 'firma po liście nie jest zaznaczona z góry');

        // Firma ze zgodą na e-mail wypada z listów: dostaje ofertę e-mailem.
        http('POST', '/klienci/potencjalni/' . $c . '/zgoda', ['email' => 'biuro@alfa.example', 'way' => 'letter_reply', 'detail' => 'Odpisali na list z prośbą o ofertę.', 'on' => today_pl(), 'confirm' => '1']);
        $page = http('GET', '/klienci/potencjalni/listy')->body;
        contains('Firmy gotowe do listu (1)', $page);
        contains('ma zgodę na e-mail: wyślij ofertę e-mailem', $page);
        eq(303, http('POST', '/klienci/potencjalni/listy', ['action' => 'docx', 'ids' => [(string) $c]])->status, 'firmie ze zgodą plik listu nie powstaje');

        // Druga firma w panelu nie pobierze listów cudzych firm ani nie oznaczy ich wysyłki.
        [$org2, $uid2] = make_org('druga@example.com', 'Druga sp. z o.o.');
        login_as($uid2);
        contains('Brak firm gotowych do listu', http('GET', '/klienci/potencjalni/listy')->body);
        eq(303, http('POST', '/klienci/potencjalni/listy', ['action' => 'docx', 'ids' => [(string) $a]])->status);
        eq(303, http('POST', '/klienci/potencjalni/listy', ['action' => 'sent', 'ids' => [(string) $a]])->status);
        eq(2, (int) $db->val("SELECT COUNT(*) FROM prospect_events WHERE kind = 'letter'"), 'cudze oznaczenie nic nie zapisało');

        // Limit jednego pliku.
        login_as($uid);
        $ids = [];
        for ($i = 0; $i <= Prospects::LETTERS_LIMIT; $i++) {
            $ids[] = (string) $db->insert('prospects', ['org_id' => $orgId, 'company_id' => $cid, 'name' => 'Firma ' . $i, 'search' => 'firma ' . $i, 'source' => 'list', 'status' => 'picked',
                'score' => 10, 'created_by' => $uid, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
        }
        eq(303, http('POST', '/klienci/potencjalni/listy', ['action' => 'docx', 'ids' => $ids])->status);
        contains('Najwyżej ' . Prospects::LETTERS_LIMIT . ' listów w jednym pliku.', http('GET', '/klienci/potencjalni/listy')->body);
        eq(200, http('POST', '/klienci/potencjalni/listy', ['action' => 'docx', 'ids' => array_slice($ids, 0, Prospects::LETTERS_LIMIT)])->status);
    },
];
