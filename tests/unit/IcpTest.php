<?php

use App\Core\Db;
use App\Core\Http;
use App\Modules\Outreach\Icp;

return [
    'Idealny klient: opis ze strony firmy, grupy tylko z cytatem, ręczna poprawka' => function () {
        [$org, $uid] = make_org();
        login_as($uid);
        $db = Db::get();
        $get = static fn () => Icp::get((array) $db->one('SELECT * FROM organizations WHERE id = ?', [$org]));
        // Bez adresu strony: podpowiedź i czytelny błąd zamiast pytania do modelu.
        contains('wpisz adres strony firmy', http('GET', '/klienci/idealny-klient')->body);
        http('POST', '/klienci/idealny-klient', ['action' => 'ai']);
        contains('Wpisz adres strony firmy w Ustawieniach', http('GET', '/klienci/idealny-klient')->body);
        eq(null, $get());

        $db->update('organizations', ['website' => 'firma-uslugowa.example'], 'id = ?', [$org]);
        eq(303, http('POST', '/klienci/idealny-klient', ['action' => 'ai'])->status);
        $icp = $get();
        contains('Sklepy internetowe i małe firmy usługowe', $icp['summary']);
        eq(['Sklepy internetowe', 'Firmy usługowe na ryczałcie'], array_column($icp['segments'], 'name'), 'grupa ze zmyślonym cytatem odpada');
        eq(1, $icp['dropped']);
        // Czytamy stronę główną i podstronę oferty. Kontakt, blog i obca witryna nie są potrzebne.
        eq(['https://firma-uslugowa.example/', 'https://firma-uslugowa.example/oferta'], $icp['pages']);
        eq(['Spółki giełdowe', 'Firmy zatrudniające ponad 50 osób'], $icp['not_for']);
        $page = http('GET', '/klienci/idealny-klient')->body;
        contains('Grupy klientów', $page);
        contains('Pakiet dla firm usługowych na ryczałcie obejmuje ewidencję przychodów', $page);
        contains('Odrzuciliśmy 1 grupę bez cytatu', $page);
        ok(!str_contains($page, 'Kancelarie prawne'), 'zmyślonej grupy nie ma na ekranie');
        contains('Idealny klient', http('GET', '/klienci')->body);

        // Ręczna poprawka: opis jako tekst, grupy z cytatami zostają.
        http('POST', '/klienci/idealny-klient', ['action' => 'save', 'summary' => 'Sklepy internetowe z obrotem <b>ponad 1 mln zł</b> rocznie.']);
        $icp = $get();
        eq('Sklepy internetowe z obrotem <b>ponad 1 mln zł</b> rocznie.', $icp['summary']);
        ok(!empty($icp['edited_at']) && count($icp['segments']) === 2, 'data poprawki i zachowane grupy');
        $page = http('GET', '/klienci/idealny-klient')->body;
        contains('&lt;b&gt;ponad 1 mln zł&lt;/b&gt;', $page);
        ok(!str_contains($page, '<b>ponad 1 mln zł</b>'), 'opis jako tekst');
        http('POST', '/klienci/idealny-klient', ['action' => 'save', 'summary' => '   ']);
        eq('Sklepy internetowe z obrotem <b>ponad 1 mln zł</b> rocznie.', $get()['summary'], 'pusty opis nie kasuje zapisanego');

        // Szkic AI oferty dostaje opis, prośba o zgodę nie.
        $cid = $db->insert('contacts', ['org_id' => $org, 'company_name' => 'Sklep Testowy', 'email' => 'sklep@test.example', 'basis' => 'none', 'created_at' => now_utc()]);
        $sentToModel = static function (string $kind) use ($cid): string {
            Http::$log = [];
            http('POST', '/klienci/' . $cid . '/wiadomosc', ['action' => 'ai', 'kind' => $kind, 'subject' => '', 'body' => '']);
            foreach (Http::$log as $l) {
                if (str_contains($l['url'], 'anthropic')) {
                    return (string) ($l['body'] ?? '');
                }
            }
            return '';
        };
        contains('Idealny klient nadawcy: Sklepy internetowe z obrotem', json_decode($sentToModel('cold_offer'), true)['messages'][0]['content'] ?? '');
        $consent = $sentToModel('consent_request');
        ok($consent !== '' && !str_contains($consent, 'Idealny klient nadawcy'), 'prośba o zgodę bez opisu klienta');

        // Opis należy do firmy: druga firma go nie widzi.
        [, $uid2] = make_org('obca@example.com', 'Obca sp. z o.o.');
        login_as($uid2);
        ok(!str_contains(http('GET', '/klienci/idealny-klient')->body, 'ponad 1 mln'), 'cudzy opis niewidoczny');
    },
    'Idealny klient: strona nie odpowiada albo ma za mało tekstu' => function () {
        eq(false, Icp::siteText('')['ok']);
        $bad = Icp::siteText('https://sklep-przykladowy.example/brak');
        eq(false, $bad['ok']);
        contains('Nie udało się pobrać strony sklep-przykladowy.example', $bad['error']);
        $ok = Icp::siteText('firma-uslugowa.example');
        eq(true, $ok['ok']);
        contains('=== STRONA: https://firma-uslugowa.example/oferta ===', $ok['text']);
        contains('Abonament zaczyna się od 300 zł miesięcznie.', $ok['text']);
        ok(!str_contains($ok['text'], 'inna-firma.example'), 'obca witryna poza materiałem');
    },
];
