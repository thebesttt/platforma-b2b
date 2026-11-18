<?php

use App\Core\Db;
use App\Modules\Companies\Companies;
use App\Modules\Companies\Receivables;
use App\Modules\Companies\Risk;
use App\Modules\Companies\Uokik;

$fx = __DIR__ . '/../fixtures/http/';

return [
    'UOKiK: parsowanie listy decyzji i nazwa bez formy prawnej' => function () use ($fx) {
        $rows = Uokik::parseList((string) file_get_contents($fx . 'uokik_zatory_2026.html'));
        eq(2, count($rows));
        eq('DZP-26/2026', $rows[0]['signature']);
        eq('2026-10-13', $rows[0]['decided_at']);
        eq('valeo autosystemy', $rows[0]['search']);
        eq('cedo', Uokik::core('CEDO spółkę z ograniczoną odpowiedzialnością'));
    },
    'Karta firmy: biała lista + KRS + UOKiK → wysokie ryzyko' => function () {
        Uokik::sync(3);
        $res = Companies::lookup('1234563218');
        ok($res['ok'], $res['error'] ?? '');
        $c = $res['company'];
        eq('0000123456', $c['krs']);
        ok((int) $c['risk_score'] >= 70, 'ryzyko: ' . $c['risk_score']);
        $s = json_in($c['signals_json']);
        eq('nazwa', $s['uokik'][0]['matched_by'], 'dopasowanie decyzji po nazwie');
        $titles = array_column($s['risk']['signals'], 'title');
        ok(in_array('Wpisy o zaległościach lub wierzytelnościach w KRS', $titles, true));
    },
    'Karta firmy: nieprawidłowy NIP i firma spoza wykazu' => function () {
        eq(false, Companies::lookup('1234563219')['ok']);
        $r = Companies::lookup('1111111111');
        eq(false, $r['ok'], 'brak w wykazie i KRS');
    },
    'Ryzyko: firma bez sygnałów ma niską ocenę' => function () {
        $r = Risk::assess(['statusVat' => 'Czynny', 'accountCount' => 2], ['legal_form' => 'SPÓŁKA AKCYJNA', 'registered_at' => '2005-01-01', 'last_statement_period_end' => date('Y-m-d', strtotime('-8 months'))], []);
        eq('low', $r['level']);
        ok($r['score'] < 20);
    },
    'Obserwowanie: zmiana w rejestrze tworzy alert dla obserwujących' => function () {
        [$org] = make_org();
        $c = Companies::lookup('7181968222')['company'];
        Db::get()->insert('watchlist', ['org_id' => $org, 'company_id' => (int) $c['id'], 'created_at' => now_utc()]);
        // Symulujemy wcześniejszy stan: inny zestaw kont na białej liście.
        $s = json_in($c['signals_json']);
        $s['snapshot']['accounts'] = 'stary-hash';
        Db::get()->update('companies', ['signals_json' => json_out($s), 'refreshed_at' => '2020-01-01 00:00:00'], 'id = ?', [(int) $c['id']]);
        $res = Companies::lookup('7181968222', true);
        ok(count($res['changes']) === 1, json_encode($res['changes'], JSON_UNESCAPED_UNICODE));
        $a = Db::get()->one('SELECT * FROM alerts WHERE org_id = ?', [$org]);
        eq('high', $a['level']);
        contains('rachunków', $a['detail']);
    },
    'Należności: warunki wpisu do BIG' => function () {
        $now = strtotime('2026-11-18 12:00:00');
        $r = ['amount' => 1200, 'due_at' => '2026-09-01', 'paid_at' => null, 'demand_sent_at' => '2026-10-01', 'demand_bureau' => 'KRD BIG S.A., Wrocław'];
        ok(Receivables::bigEligibility($r, $now)['eligible'], 'wszystkie warunki spełnione');
        eq(false, Receivables::bigEligibility(['amount' => 400] + $r, $now)['eligible'], 'kwota < 500 zł');
        eq(false, Receivables::bigEligibility(['demand_bureau' => ''] + $r, $now)['eligible'], 'bez nazwy biura');
        $late = Receivables::bigEligibility(['demand_sent_at' => '2026-11-10'] + $r, $now);
        eq(false, $late['eligible'], 'za mało czasu od wezwania');
        eq('2026-12-10', $late['eligible_from']);
        eq(78, Receivables::daysOverdue($r, $now));
        eq(40, Receivables::compensationEur(5000));
        eq(70, Receivables::compensationEur(5000.01));
        eq(100, Receivables::compensationEur(60000));
    },
    'Wezwanie do zapłaty zawiera ostrzeżenie z nazwą biura' => function () {
        $letter = Receivables::demandLetter(['amount' => 1200, 'invoice_no' => 'FV/1/2026', 'issued_at' => '2026-08-01', 'due_at' => '2026-09-01', 'demand_bureau' => 'Krajowy Rejestr Długów BIG S.A.'], ['name' => 'Dłużnik sp. z o.o.', 'nip' => '1234563218', 'address' => 'Warszawa'], ['name' => 'Wierzyciel sp. z o.o.', 'nip' => '7181968222']);
        contains("1\u{00A0}200,00\u{00A0}zł", $letter['body']);
        contains('Krajowy Rejestr Długów BIG S.A.', $letter['body']);
        contains('art. 14 ust. 1 pkt 4', $letter['body']);
        contains('40 euro', $letter['body']);
    },
];
