<?php

use App\Core\Db;
use App\Modules\Tenders\Analysis;
use App\Modules\Tenders\BzpClient;
use App\Modules\Tenders\BzpNoticeParser;
use App\Modules\Tenders\Importer;
use App\Modules\Tenders\Matcher;
use App\Modules\Tenders\Regions;
use App\Modules\Tenders\TedClient;
use App\Modules\Tenders\Tenders;

$fx = __DIR__ . '/../fixtures/http/';

return [
    'Parser BZP: wadium, kryteria, termin, CPV' => function () use ($fx) {
        $d = BzpNoticeParser::parse((string) file_get_contents($fx . 'bzp_notice_543096.html'));
        eq(46000.0, $d['wadium_value']);
        eq(true, $d['wadium']);
        eq('2026-12-02 09:00:00', $d['deadline'], 'termin w UTC');
        $names = array_column($d['criteria'], 'name');
        ok(in_array('Cena', $names, true), 'kryterium ceny');
        eq(60.0, $d['criteria'][0]['weight']);
        ok($d['cpv_main'] !== '', 'główny CPV');
        ok(str_contains($d['text'], '8.1)'), 'pełna treść w punktach');
    },
    'BZP: normalizacja rekordu wyszukiwarki' => function () use ($fx) {
        $rows = json_decode((string) file_get_contents($fx . 'bzp_search_p1.json'), true);
        $t = BzpClient::normalize($rows[0]);
        eq('bzp', $t['source']);
        eq('works', $t['order_type']);
        eq('podlaskie', $t['voivodeship'], 'PL20 w BZP = podlaskie');
        eq('7181968222', $t['buyer_nip']);
        ok(str_contains($t['cpv_all'], '45233000'));
        eq('2026-12-02 09:00:00', $t['deadline_at']);
    },
    'TED: normalizacja - tytuł, NIP z prefiksem, termin, wartość' => function () use ($fx) {
        $j = json_decode((string) file_get_contents($fx . 'ted_search_p1.json'), true);
        $a = TedClient::normalize($j['notices'][0]);
        $b = TedClient::normalize($j['notices'][1]);
        eq('ted', $a['source']);
        ok(!str_starts_with($a['title'], 'Polska'), 'tytuł bez prefiksu kraju');
        eq('6321880539', $b['buyer_nip'], 'NIP z „NIP: …”');
        ok($a['deadline_at'] !== null);
        ok($a['value_estimated'] === 3500000.0 || $b['value_estimated'] === 3500000.0, 'wartość szacunkowa');
    },
    'Regiony: ten sam kod PL22 w BZP i NUTS to różne województwa' => function () {
        eq('pomorskie', Regions::fromBzp('PL22'));
        eq('slaskie', Regions::fromNuts('PL22A'));
        eq('mazowieckie', Regions::fromNuts('PL911'));
        eq('lodzkie', Regions::fromName('łódzkie'));
    },
    'Import z BZP i TED zapisuje ogłoszenia bez duplikatów' => function () {
        $r1 = Importer::run('2026-11-15 00:00:00');
        eq(2, $r1['bzp']);
        eq(2, $r1['ted']);
        eq([], $r1['errors']);
        Importer::run('2026-11-15 00:00:00');
        eq(4, (int) Db::get()->val('SELECT COUNT(*) FROM tenders'));
    },
    'Dopasowanie: CPV, słowa kluczowe, wykluczenia, województwa' => function () {
        $t = ['title' => 'Przebudowa drogi gminnej i chodnika', 'source' => 'bzp', 'cpv_main' => '45233000', 'cpv_all' => '45233000 45112500', 'voivodeship' => 'mazowieckie', 'order_type' => 'works', 'value_estimated' => 2000000, 'deadline_at' => gmdate('Y-m-d H:i:s', time() + 10 * 86400), 'details_json' => null];
        $p = ['cpv' => '4523', 'keywords' => "chodnik\nnawierzchnia", 'exclude_keywords' => '', 'voivodeships' => 'mazowieckie lodzkie', 'order_types' => 'works', 'min_value' => 500000, 'max_value' => 5000000, 'sources' => 'bzp ted'];
        $r = Matcher::score($t, $p);
        ok($r !== null && $r['score'] >= 85, 'wysokie dopasowanie: ' . json_encode($r));
        eq(null, Matcher::score($t, ['exclude_keywords' => 'chodnik'] + $p), 'słowo wykluczające');
        eq(null, Matcher::score(['voivodeship' => 'slaskie'] + $t, $p), 'inne województwo');
        eq(null, Matcher::score($t, ['cpv' => '7231', 'keywords' => 'oprogramowanie'] + $p), 'brak CPV i słów');
        eq(null, Matcher::score(['deadline_at' => '2020-01-01 00:00:00'] + $t, $p), 'termin minął');
        $out = Matcher::score(['value_estimated' => 9000000] + $t, $p);
        ok(in_array('Wartość poza Twoim zakresem', $out['reasons'], true));
    },
    'Szczegóły BZP pobierane leniwie i zapamiętywane' => function () {
        import_fixtures();
        $t = Db::get()->one("SELECT * FROM tenders WHERE source = 'bzp' ORDER BY id LIMIT 1");
        $d = Tenders::details($t);
        eq(46000.0, $d['wadium_value']);
        $t2 = Tenders::find((int) $t['id']);
        ok($t2['details_json'] !== null, 'zapisane w bazie');
        $rules = Analysis::rules($t2, $d);
        ok(count($rules['items']) >= 4, 'punkty z ogłoszenia');
        ok(str_starts_with($rules['items'][0]['source'], 'Ogłoszenie, pkt'), 'każdy punkt ma źródło');
    },
];
