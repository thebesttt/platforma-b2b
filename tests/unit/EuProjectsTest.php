<?php

use App\Core\Db;
use App\Core\Kv;
use App\Modules\Outreach\EuProjects;
use App\Modules\Outreach\Prospects;

return [
    'Dotacje UE: rozpoznanie spółek i nazwa firmy bez formy prawnej' => function () {
        foreach (['ABC Sp. z o.o.', 'abc sp. z o. o.', 'Twoja Klinika sp z o.o.', 'S PLASTIC SPÓŁKA Z OGRANICZONA ODPOWIEDZIALNOŚCIĄ', 'Newtrition Spółka z ograniczoną odpowiedzialnością spółka komandytowa', 'TECHNOLOGIE PRZYSZŁOŚCI SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', 'Orlen S.A.', 'Grupa Azoty SA', 'Kowalski i Wspólnicy sp.k.',
            'Nowak sp. j.', 'Prosta Spółka Akcyjna Innowacje', 'Spółdzielnia Mleczarska Mlekpol', 'XYZ P.S.A.'] as $yes) {
            ok(EuProjects::isCompany($yes), 'spółka: ' . $yes);
        }
        foreach (['Jan Kowalski Usługi Remontowe', 'ABC s.c. Anna Nowak, Piotr Nowak', 'Gmina Testowo', 'Uniwersytet Łódzki', 'Fundacja Rozwoju', 'Anna Sadowska'] as $no) {
            ok(!EuProjects::isCompany($no), 'nie spółka: ' . $no);
        }
        eq('technologie przyszlosci', EuProjects::key('TECHNOLOGIE PRZYSZŁOŚCI SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ'));
        eq(EuProjects::key('Technologie Przyszłości Sp. z o.o.'), EuProjects::key('TECHNOLOGIE PRZYSZŁOŚCI SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ'));
        eq('logistyka polska', EuProjects::key('Logistyka Polska Spółka Akcyjna'));
        eq('newtrition', EuProjects::key('Newtrition Spółka z ograniczoną odpowiedzialnością spółka komandytowa'));
    },
    'Dotacje UE: import zostawia tylko spółki z trwającymi projektami i pobiera plik tylko raz w miesiącu' => function () {
        $res = EuProjects::sync();
        eq([], $res['errors']);
        eq(8, $res['read']);
        eq(3, $res['kept'], 'bez osoby fizycznej, spółki cywilnej, gminy, dawno zakończonego projektu i powtórki');
        $rows = Db::get()->all('SELECT * FROM eu_projects ORDER BY contract_no');
        eq(['TECHNOLOGIE PRZYSZŁOŚCI SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', 'Logistyka Polska Spółka Akcyjna', 'Zielona Energia sp z o.o.'], array_column($rows, 'beneficiary'));
        eq(['lodzkie', '', 'mazowieckie slaskie'], array_column($rows, 'voivodeships'));
        eq('Projekt obejmuje wdrożenie platformy B2B. W ramach projektu powstanie sklep internetowy dla odbiorców hurtowych.', $rows[0]['summary'], 'opis z nową linią w jednym polu');
        eq([1250000.0, 875000.0], [(float) $rows[0]['value_total'], (float) $rows[0]['eu_amount']]);
        eq(gmdate('Y-m-d', time() + 400 * 86400), $rows[0]['end_date']);
        eq('https://funduszeeuropejskie.gov.pl/wp-content/uploads/2026/11/Lista_projektow_FE_2021_2027_01112026.csv', Kv::get('eu:source'));
        eq(true, EuProjects::sync()['skipped'] ?? false, 'ten sam plik nie jest pobierany drugi raz');
        // Nowy plik (miesiąc później) bez jednego projektu: projekt znika z bazy.
        Db::get()->run("UPDATE eu_projects SET updated_at = '2026-01-01 00:00:00'");
        with_config(['FIXTURE_EU' => 'krotka'], function () {
            $again = EuProjects::sync(true);
            eq([2, 1], [$again['kept'], $again['removed']]);
        });
        eq(0, (int) Db::get()->val("SELECT COUNT(*) FROM eu_projects WHERE beneficiary LIKE 'Zielona%'"));
    },
    'Dotacje UE: zły plik nie usuwa danych' => function () {
        EuProjects::sync();
        $tmp = tempnam(sys_get_temp_dir(), 'fe');
        file_put_contents($tmp, "kolumna;inna\n1;2\n3;4\n5;6\n7;8\n9;10\n11;12\n");
        $res = EuProjects::importFile($tmp);
        unlink($tmp);
        contains('Nazwa beneficjenta', implode(' ', $res['errors']));
        eq(3, (int) Db::get()->val('SELECT COUNT(*) FROM eu_projects'));
    },
    'Dotacje UE: ekran wyszukuje i dodaje firmy do potencjalnych klientów bez powtórek' => function () {
        EuProjects::sync();
        [$org, $uid] = make_org();
        login_as($uid);
        $page = http('GET', '/klienci/dotacje-ue')->body;
        contains('3 projekty', $page);
        contains('Zielona Energia sp z o.o.', $page);
        $pv = http('GET', '/klienci/dotacje-ue', [], ['q' => 'fotowoltaika'])->body;
        contains('Instalacja fotowoltaiczna', $pv);
        ok(!str_contains($pv, 'TECHNOLOGIE PRZYSZŁOŚCI'), 'słowo zawęża listę');
        contains('1 projekt', http('GET', '/klienci/dotacje-ue', [], ['woj' => 'lodzkie'])->body);
        contains('2 projekty', http('GET', '/klienci/dotacje-ue', [], ['min' => '5000000'])->body);
        $id = (int) Db::get()->val("SELECT id FROM eu_projects WHERE beneficiary LIKE 'Zielona%'");
        $r = http('POST', '/klienci/dotacje-ue/dodaj', ['ids' => [(string) $id]]);
        eq('/klienci/potencjalni', $r->headers['Location']);
        $p = Db::get()->one("SELECT * FROM prospects WHERE org_id = ? AND source = 'eu'", [$org]);
        eq(['Zielona Energia sp z o.o.', null, null], [$p['name'], $p['nip'], $p['voivodeship']]);
        contains('Realizuje projekt z funduszy UE: „Instalacja fotowoltaiczna i magazyn energii”', implode(' ', Prospects::reasons($p, null)));
        $reason = array_values(array_filter(json_in($p['score_json']), static fn ($row) => $row['label'] === 'Powód kontaktu'))[0];
        eq(20, $reason['points'], 'projekt ruszył w ostatnim roku');
        contains('projekt z dotacją UE w toku', $reason['note']);
        http('POST', '/klienci/dotacje-ue/dodaj', ['ids' => [(string) $id]]);
        eq(1, (int) Db::get()->val("SELECT COUNT(*) FROM prospects WHERE org_id = ?", [$org]), 'ta sama firma nie trafia drugi raz');
        contains('na liście', http('GET', '/klienci/dotacje-ue', [], ['q' => 'fotowoltaika'])->body);
        contains('dotacje UE', http('GET', '/klienci/potencjalni')->body);
        // Inna firma na platformie ma własną listę.
        [$org2, $uid2] = make_org('inna@example.com', 'Inna Firma sp. z o.o.');
        login_as($uid2);
        http('POST', '/klienci/dotacje-ue/dodaj', ['ids' => [(string) $id]]);
        eq(1, (int) Db::get()->val('SELECT COUNT(*) FROM prospects WHERE org_id = ?', [$org2]));
    },
];
