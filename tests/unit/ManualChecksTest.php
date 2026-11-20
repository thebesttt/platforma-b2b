<?php

use App\Core\Db;
use App\Modules\Companies\Companies;
use App\Modules\Companies\ManualChecks;

return [
    'Kwoty wpisywane ręcznie: spacje, przecinek, kropki i minus' => function () {
        eq(1234567.89, ManualChecks::number('1 234 567,89'));
        eq(1234567.89, ManualChecks::number("1\u{00A0}234\u{00A0}567,89 zł"));
        eq(1234567.89, ManualChecks::number('1.234.567,89'));
        eq(1234567.89, ManualChecks::number('1234567.89'));
        eq(-12000.0, ManualChecks::number('-12 000'));
        eq(1234567.0, ManualChecks::number('1.234.567'));
        eq(null, ManualChecks::number(''));
        eq(null, ManualChecks::number('ok. 5 mln'));
        eq(null, ManualChecks::number('12,5,3'));
    },
    'Czas płacenia dostawcom z liczb sprawozdania: wzór i progi skali' => function () {
        // 200 000 zł zobowiązań przy 1 200 000 zł zakupów rocznie to 61 dni.
        $f = ManualChecks::finance(['payables' => 200000, 'purchases' => 1200000, 'revenue' => null, 'receivables' => null]);
        eq([61, 'mid', 'długo', null], [$f['dpo'], $f['level'], $f['label'], $f['dso']]);
        eq([60, 'low'], array_values(array_intersect_key(ManualChecks::finance(['payables' => 197260, 'purchases' => 1200000]), ['dpo' => 1, 'level' => 1])));
        eq([90, 'mid'], array_values(array_intersect_key(ManualChecks::finance(['payables' => 295890, 'purchases' => 1200000]), ['dpo' => 1, 'level' => 1])));
        eq([91, 'high'], array_values(array_intersect_key(ManualChecks::finance(['payables' => 299178, 'purchases' => 1200000]), ['dpo' => 1, 'level' => 1])));
        // Należności i przychody dają czas, po którym firma sama dostaje zapłatę. Strata i ujemny kapitał to osobne uwagi.
        $f = ManualChecks::finance(['payables' => 100000, 'purchases' => 1000000, 'revenue' => 2000000, 'receivables' => 250000, 'net_result' => -5000, 'equity' => -1]);
        eq([37, 46], [$f['dpo'], $f['dso']]);
        eq(2, count($f['notes']));
    },
    'Karta firmy: gdzie sprawdzić KRZ i sprawozdanie, wpis wyniku, widoczność tylko dla własnej firmy' => function () {
        [$org, $uid] = make_org();
        login_as($uid);
        $db = Db::get();
        $nip = '1234563218';
        $url = '/kontrahenci/' . $nip;
        $page = http('GET', $url)->body;
        $c = Companies::byNip($nip);
        // Przewodnik: adresy rejestrów i numery firmy do skopiowania.
        contains('href="https://krz.ms.gov.pl/"', $page);
        contains('href="https://ekrs.ms.gov.pl/"', $page);
        contains('Przeglądarka dokumentów finansowych', $page);
        contains('<code class="copy">' . $nip . '</code>', $page);
        contains('nie sprawdzono', $page);
        // Błędne wpisy: czytelny komunikat, nic się nie zapisuje.
        http('POST', $url . '/sprawdzenia', ['kind' => 'krz']);
        http('POST', $url . '/sprawdzenia', ['kind' => 'krz', 'result' => 'found']);
        http('POST', $url . '/sprawdzenia', ['kind' => 'krz', 'result' => 'none', 'checked_on' => gmdate('Y-m-d', time() + 5 * 86400)]);
        http('POST', $url . '/sprawdzenia', ['kind' => 'finance', 'year' => '1990', 'payables' => '1', 'purchases' => '1']);
        http('POST', $url . '/sprawdzenia', ['kind' => 'finance', 'year' => '2025', 'payables' => 'dużo', 'purchases' => '100']);
        http('POST', $url . '/sprawdzenia', ['kind' => 'finance', 'year' => '2025', 'payables' => '100', 'purchases' => '0']);
        http('POST', $url . '/sprawdzenia', ['kind' => 'inne']);
        eq(0, (int) $db->val('SELECT COUNT(*) FROM company_checks'));
        // Brak wpisów w KRZ.
        eq(303, http('POST', $url . '/sprawdzenia', ['kind' => 'krz', 'result' => 'none'])->status);
        $page = http('GET', $url)->body;
        contains('brak wpisów', $page);
        contains('Sprawdził(a) Jan Test, ' . date('d.m.Y'), $page);
        // Nowsze sprawdzenie z wpisem zastępuje stan na karcie i dodaje znacznik w nagłówku.
        http('POST', $url . '/sprawdzenia', ['kind' => 'krz', 'result' => 'found', 'type' => 'restructuring', 'signature' => 'WA1M/GRs/7/2026 <b>x</b>', 'entry_date' => '2026-10-02', 'note' => 'postępowanie sanacyjne']);
        $page = http('GET', $url)->body;
        contains('KRZ: postępowanie restrukturyzacyjne', $page);
        contains('sygn. WA1M/GRs/7/2026 &lt;b&gt;x&lt;/b&gt;, z 02.10.2026', $page);
        ok(!str_contains($page, '<b>x</b>'), 'sygnatura jako tekst');
        // Liczby ze sprawozdania: szacunek i uwagi.
        eq(303, http('POST', $url . '/sprawdzenia', ['kind' => 'finance', 'year' => '2025', 'payables' => '300 000,00', 'purchases' => '1.200.000', 'revenue' => '2 000 000', 'receivables' => '250000', 'net_result' => '-40 000', 'equity' => '500000'])->status);
        $page = http('GET', $url)->body;
        contains('ok. 91 dni, bardzo długo', $page);
        contains('ok. 46 dni', $page, 'czas, po którym firma sama dostaje zapłatę');
        contains('2025: Rok zakończył się stratą netto.', $page);
        // Poprawka tego samego roku zastępuje wcześniejszy wpis na karcie, starszy rok zostaje w tabeli.
        http('POST', $url . '/sprawdzenia', ['kind' => 'finance', 'year' => '2025', 'payables' => '100000', 'purchases' => '1200000']);
        http('POST', $url . '/sprawdzenia', ['kind' => 'finance', 'year' => '2024', 'payables' => '200000', 'purchases' => '1200000']);
        $checks = ManualChecks::forCompany($org, (int) $c['id']);
        eq([2025, 2024], array_map(static fn ($f) => (int) $f['data']['year'], $checks['finance']));
        eq([30, 61], array_map(static fn ($f) => $f['calc']['dpo'], $checks['finance']));
        contains('ok. 30 dni, typowo', http('GET', $url)->body);
        // Ocena ryzyka z danych publicznych się nie zmienia.
        eq($c['risk_score'], Companies::byNip($nip)['risk_score']);
        // Druga firma nie widzi wpisów, nie usunie ich, a jej własne są osobne.
        [$org2, $uid2] = make_org('obca@example.com', 'Obca sp. z o.o.');
        login_as($uid2);
        $other = http('GET', $url)->body;
        ok(!str_contains($other, 'WA1M/GRs') && !str_contains($other, 'ok. 30 dni'), 'cudze wpisy niewidoczne');
        contains('nie sprawdzono', $other);
        $id = (int) $db->val("SELECT id FROM company_checks WHERE org_id = ? AND kind = 'krz' ORDER BY id DESC LIMIT 1", [$org]);
        eq(404, http('POST', $url . '/sprawdzenia/' . $id . '/usun')->status);
        eq(null, ManualChecks::forCompany($org2, (int) $c['id'])['krz']);
        login_as($uid);
        $fid = (int) $checks['finance'][1]['id'];
        eq(303, http('POST', $url . '/sprawdzenia/' . $fid . '/usun')->status);
        eq([2025], array_map(static fn ($f) => (int) $f['data']['year'], ManualChecks::forCompany($org, (int) $c['id'])['finance']));
    },
    'Obserwowane firmy: przypomnienie, gdy sprawdzenie w KRZ jest starsze niż 90 dni albo go nie ma' => function () {
        [$org, $uid] = make_org();
        login_as($uid);
        $db = Db::get();
        http('GET', '/kontrahenci/1234563218');
        $c = Companies::byNip('1234563218');
        http('POST', '/kontrahenci/1234563218/obserwuj');
        contains('1 obserwowana firma nie ma aktualnego sprawdzenia w Krajowym Rejestrze Zadłużonych', http('GET', '/kontrahenci')->body);
        http('POST', '/kontrahenci/1234563218/sprawdzenia', ['kind' => 'krz', 'result' => 'none']);
        ok(!str_contains(http('GET', '/kontrahenci')->body, 'aktualnego sprawdzenia w Krajowym Rejestrze'), 'po sprawdzeniu notka znika');
        // Po 91 dniach sprawdzenie jest nieaktualne: karta i lista proszą o powtórkę.
        $db->update('company_checks', ['checked_at' => gmdate('Y-m-d', time() - 91 * 86400)], 'org_id = ?', [$org]);
        eq(true, ManualChecks::forCompany($org, (int) $c['id'])['krz_stale']);
        contains('sprawdź ponownie', http('GET', '/kontrahenci/1234563218')->body);
        contains('aktualnego sprawdzenia w Krajowym Rejestrze', http('GET', '/kontrahenci')->body);
    },
];
