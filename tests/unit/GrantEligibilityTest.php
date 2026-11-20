<?php

use App\Core\Db;
use App\Modules\Offers\Eligibility;
use App\Modules\Offers\Offers;

return [
    'Warunki naboru: warunek tylko z cytatem z regulaminu, ocena tylko z potwierdzeniem w danych firmy' => function () {
        [$org, $uid] = make_org();
        login_as($uid);
        $db = Db::get();
        $db->update('organizations', ['address' => 'ul. Testowa 1, 00-001 Warszawa'], 'id = ?', [$org]);
        $db->insert('library_items', ['org_id' => $org, 'kind' => 'text', 'title' => 'O firmie', 'body' => 'Firma działa od marca 2026 r. i wykonuje roboty drogowe.', 'created_at' => now_utc(), 'updated_at' => now_utc()]);
        $id = Offers::create($org, $uid, null, 'grant', ['call_name' => 'Nabór testowy 2026', 'call_url' => null, 'deadline_at' => null]);
        $url = '/oferty/' . $id . '/warunki';
        $page = '/oferty/' . $id;

        // Bez dokumentów naboru nie pytamy modelu.
        contains('Wgraj regulamin naboru albo kryteria wyboru projektów', http('GET', $page, [], ['sekcja' => '_warunki'])->body);
        eq(303, http('POST', $url)->status);
        contains('Najpierw wgraj regulamin naboru', http('GET', $page, [], ['sekcja' => '_warunki'])->body);
        eq(null, Eligibility::latest($org, $id));

        // Regulamin z czterema warunkami. W trzecim jest półpauza, którą klient modelu zamienia na łącznik.
        $db->insert('offer_documents', ['org_id' => $org, 'offer_id' => $id, 'filename' => 'regulamin.txt', 'mime' => 'text/plain', 'size' => 500, 'uploaded_by' => $uid, 'created_at' => now_utc(),
            'text' => "§ 4. Kto może złożyć wniosek\n1. O dofinansowanie mogą ubiegać się wyłącznie mikro, mali i średni przedsiębiorcy.\n"
                . "2. Wnioskodawca prowadzi działalność na terenie   województwa mazowieckiego.\n"
                . "3. Wnioskodawca prowadzi działalność od co najmniej 24 miesięcy \u{2013} licząc do dnia złożenia wniosku.\n"
                . "§ 5. Finansowanie\n1. Minimalny wkład własny wynosi 15% kosztów kwalifikowalnych.\n"]);
        eq(303, http('POST', $url)->status);
        $e = Eligibility::latest($org, $id);
        $by = array_column($e['conditions'], null, 'condition');
        eq(4, count($e['conditions']), 'warunek ze zmyślonym cytatem odpada');
        eq(1, $e['dropped']);
        ok(!isset($by['Certyfikat ISO 9001']), 'zmyślonego warunku nie ma na liście');
        // Potwierdzenie jest w danych firmy: ocena zostaje.
        eq(['met', 'ul. Testowa 1, 00-001 Warszawa'], [$by['Siedziba w województwie mazowieckim']['status'], $by['Siedziba w województwie mazowieckim']['evidence']]);
        eq(['not_met', 'Firma działa od marca 2026 r.', 'regulamin.txt'], [$by['Okres działalności']['status'], $by['Okres działalności']['evidence'], $by['Okres działalności']['doc']], 'ocena z biblioteki, nazwa pliku poprawiona');
        // „Firma zatrudnia 12 osób” nie ma w danych firmy: stan wraca do „brak danych”.
        eq(['unknown', ''], [$by['Status MŚP']['status'], $by['Status MŚP']['evidence']]);
        contains('bez potwierdzenia w danych firmy', $by['Status MŚP']['note']);
        eq('unknown', $by['Wkład własny']['status']);
        eq(['not_met', 'unknown', 'unknown', 'met'], array_column($e['conditions'], 'status'), 'najpierw to, co blokuje');
        eq(['met' => 1, 'not_met' => 1, 'unknown' => 2], $e['counts']);

        $body = http('GET', $page, [], ['sekcja' => '_warunki'])->body;
        contains('Nie spełnia: 1', $body);
        contains('Brak danych: 2', $body);
        contains('Spełnia: 1', $body);
        contains('1 nie spełnia', $body, 'znacznik w menu sekcji');
        contains('Pominęliśmy 1 pozycję bez dosłownego cytatu', $body);
        contains('Sprawdź ponownie', $body);
        // Tekst z odpowiedzi modelu i z dokumentów jest na ekranie tekstem, nie kodem HTML.
        $db->insert('offer_eligibility', ['org_id' => $org, 'offer_id' => $id, 'created_by' => $uid, 'created_at' => now_utc(), 'result_json' => json_out(['dropped' => 0, 'docs' => ['<i>plik</i>.pdf'], 'conditions' => [
            ['condition' => 'Warunek <script>alert(1)</script>', 'quote' => 'Cytat <img src=x onerror=alert(2)>', 'doc' => '<b>doc</b>', 'status' => 'unknown', 'evidence' => '<u>dowód</u>', 'note' => 'Uwaga <b>ważna</b>'],
        ]])]);
        $x = http('GET', $page, [], ['sekcja' => '_warunki'])->body;
        foreach (['<script>alert(1)</script>', '<img src=x', '<b>doc</b>', '<u>dowód</u>', '<b>ważna</b>', '<i>plik</i>'] as $bad) {
            ok(!str_contains($x, $bad), 'kod HTML na ekranie warunków: ' . $bad);
        }
        contains('Warunek &lt;script&gt;alert(1)&lt;/script&gt;', $x);
        $db->run('DELETE FROM offer_eligibility WHERE id = (SELECT MAX(id) FROM offer_eligibility)');
        // Dane firmy, które widzi model, to te same dane, z którymi porównujemy cytaty.
        contains('Adres: ul. Testowa 1, 00-001 Warszawa', Eligibility::companyFacts($org));
        contains('Firma działa od marca 2026 r.', Eligibility::companyFacts($org));

        // Oferta przetargowa nie ma warunków naboru. Cudza firma nie uruchomi sprawdzenia ani nie zobaczy wyniku.
        $tenderOffer = Offers::create($org, $uid, null);
        eq(404, http('POST', '/oferty/' . $tenderOffer . '/warunki')->status);
        ok(!str_contains(http('GET', '/oferty/' . $tenderOffer)->body, 'Warunki naboru'), 'oferta bez pozycji „Warunki naboru”');
        [$org2, $uid2] = make_org('obca@example.com', 'Obca sp. z o.o.');
        login_as($uid2);
        eq(404, http('POST', $url)->status);
        eq(404, http('GET', $page, [], ['sekcja' => '_warunki'])->status);
        eq(null, Eligibility::latest($org2, $id));
        eq(1, (int) $db->val('SELECT COUNT(*) FROM offer_eligibility'));
    },
    'Warunki naboru bez klucza AI: czytelny komunikat zamiast błędu' => function () {
        [$org, $uid] = make_org();
        login_as($uid);
        $id = Offers::create($org, $uid, null, 'grant', ['call_name' => 'Nabór testowy 2026', 'call_url' => null, 'deadline_at' => null]);
        Db::get()->insert('offer_documents', ['org_id' => $org, 'offer_id' => $id, 'filename' => 'regulamin.txt', 'text' => 'Minimalny wkład własny wynosi 15% kosztów kwalifikowalnych.', 'uploaded_by' => $uid, 'created_at' => now_utc()]);
        with_config(['ANTHROPIC_API_KEY' => ''], function () use ($id) {
            eq(303, http('POST', '/oferty/' . $id . '/warunki')->status);
            $body = http('GET', '/oferty/' . $id, [], ['sekcja' => '_warunki'])->body;
            contains('Funkcje AI są wyłączone', $body);
        });
        eq(0, (int) Db::get()->val('SELECT COUNT(*) FROM offer_eligibility'));
        ok(Eligibility::found('Wkład własny - 15%', "wkład  własny \u{2014} 15% kosztów"), 'pauza i łącznik to ten sam znak przy porównaniu');
        ok(!Eligibility::found('wkład własny 20%', 'wkład własny 15%'), 'inna liczba to inny cytat');
    },
];
