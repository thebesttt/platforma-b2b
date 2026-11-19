<?php
/**
 * Próba generalna na prawdziwych danych: php tests/live_rehearsal.php
 * Pełny import z BZP i TED do bazy (DATABASE_URL), dopasowanie do przykładowych profili, parser na losowych
 * ogłoszeniach, karty prawdziwych firm (biała lista VAT, KRS). Wypisuje raport liczbowy i przykłady do oceny.
 * Uruchamiana w GitHub Actions - kontener deweloperski nie ma dostępu do tych serwisów.
 */

declare(strict_types=1);

putenv('APP_ENV=test');
putenv('HTTP_FIXTURES=');
if (!getenv('DATABASE_URL')) {
    putenv('DATABASE_URL=sqlite::memory:');
}
require __DIR__ . '/../src/bootstrap.php';

use App\Core\Auth;
use App\Core\Db;
use App\Core\Http;
use App\Core\Migrator;
use App\Core\Nip;
use App\Modules\Companies\Companies;
use App\Modules\Companies\Registries;
use App\Modules\Tenders\Analysis;
use App\Modules\Tenders\Importer;
use App\Modules\Tenders\Matcher;
use App\Modules\Tenders\Tenders;

$db = Db::get();
if ($db->driver() === 'pgsql') {
    $db->pdo()->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
}
(new Migrator($db))->migrate();
$problems = [];
$h = static function (string $t): void {
    echo "\n=== $t ===\n";
};
$pct = static fn (int $a, int $b) => $b ? round(100 * $a / $b) . '%' : '-';

// 1. Import
$h('1. Import ogłoszeń z ostatnich 2 dni (BZP + TED)');
$t0 = microtime(true);
$res = Importer::run(gmdate('Y-m-d H:i:s', time() - 2 * 86400));
$secs = round(microtime(true) - $t0);
echo "BZP: {$res['bzp']}, TED: {$res['ted']}, czas: {$secs} s, zapytań HTTP: " . count(Http::$log) . "\n";
echo 'Błędy: ' . ($res['errors'] ? implode(' | ', $res['errors']) : 'brak') . "\n";
if ($res['bzp'] < 50) {
    $problems[] = 'Import z BZP zwrócił mniej niż 50 ogłoszeń z 2 dni.';
}
$dups = (int) $db->val('SELECT COUNT(*) FROM (SELECT source, external_id FROM tenders GROUP BY source, external_id HAVING COUNT(*) > 1) d');
$badNip = (int) $db->val("SELECT COUNT(*) FROM tenders WHERE buyer_nip LIKE '0%'");
echo "Duplikaty: $dups, NIP zaczynające się od zera: $badNip\n";
if ($res['errors']) {
    $problems[] = 'Import zgłosił błędy.';
}
$total = (int) $db->val('SELECT COUNT(*) FROM tenders');
foreach (['bzp', 'ted'] as $src) {
    $n = (int) $db->val('SELECT COUNT(*) FROM tenders WHERE source = ?', [$src]);
    if (!$n) {
        continue;
    }
    $stat = static fn (string $cond) => (int) $db->val("SELECT COUNT(*) FROM tenders WHERE source = ? AND $cond", [$src]);
    echo strtoupper($src) . ": $n ogłoszeń | termin: " . $pct($stat('deadline_at IS NOT NULL'), $n) . ' | województwo: ' . $pct($stat('voivodeship IS NOT NULL'), $n)
        . ' | CPV: ' . $pct($stat("cpv_main IS NOT NULL AND cpv_main <> ''"), $n) . ' | NIP zamawiającego: ' . $pct($stat('buyer_nip IS NOT NULL'), $n)
        . ' | rodzaj: ' . $pct($stat('order_type IS NOT NULL'), $n) . ' | wartość: ' . $pct($stat('value_estimated IS NOT NULL'), $n) . "\n";
}
$open = (int) $db->val('SELECT COUNT(*) FROM tenders WHERE deadline_at > ?', [now_utc()]);
echo "Z otwartym terminem: $open z $total\n";
echo 'Rozmiar danych: ' . round((int) $db->val('SELECT SUM(LENGTH(raw_json)) FROM tenders') / 1048576, 1) . " MB (pole raw_json)\n";

// 2. Dopasowanie
$h('2. Dopasowanie do przykładowych profili');
$reg = Auth::register('Firma próbna', '', 'Próba', 'proba@example.com', 'haslo-probne-123');
$org = (int) $db->val('SELECT org_id FROM users WHERE id = ?', [$reg['user_id']]);
$profiles = [
    ['Roboty drogowe - Mazowsze i Łódzkie', '45233', "drog\nchodnik\nnawierzchni", 'mazowieckie lodzkie', 'works'],
    ['Usługi IT - cała Polska', '72 48', "oprogramowani\nsystem informatyczn\nwdrożeni", '', ''],
    ['Sprzątanie i utrzymanie czystości', '909', "sprzątani\nutrzymanie czystości", '', 'services'],
    ['Sprzęt medyczny - dostawy', '331', "sprzęt medyczn\naparatur", '', 'supplies'],
    ['Remonty budynków - Śląsk i Małopolska', '4545 4521', "remont\ntermomodernizac", 'slaskie malopolskie', 'works'],
];
foreach ($profiles as [$name, $cpv, $kw, $woj, $types]) {
    $pid = $db->insert('tender_profiles', ['org_id' => $org, 'name' => $name, 'cpv' => $cpv, 'keywords' => $kw, 'exclude_keywords' => '', 'voivodeships' => $woj,
        'order_types' => $types, 'sources' => 'bzp ted', 'active' => 1, 'created_at' => now_utc()]);
    $n = Matcher::run($org, $pid, gmdate('Y-m-d H:i:s', time() - 30 * 86400));
    echo "\n$name: $n dopasowań\n";
    $top = $db->all('SELECT m.score, m.reasons_json, t.title, t.source, t.voivodeship FROM tender_matches m JOIN tenders t ON t.id = m.tender_id WHERE m.profile_id = ? ORDER BY m.score DESC LIMIT 4', [$pid]);
    foreach ($top as $m) {
        echo '  ' . $m['score'] . ' | ' . strtoupper($m['source']) . ' | ' . truncate((string) $m['title'], 90) . ' | ' . implode(', ', array_slice(json_in($m['reasons_json']), 0, 3)) . "\n";
    }
    $low = $db->all('SELECT m.score, t.title FROM tender_matches m JOIN tenders t ON t.id = m.tender_id WHERE m.profile_id = ? ORDER BY m.score LIMIT 2', [$pid]);
    foreach ($low as $m) {
        echo '  (najsłabsze) ' . $m['score'] . ' | ' . truncate((string) $m['title'], 90) . "\n";
    }
}

// 3. Parser treści ogłoszeń BZP
$h('3. Parser treści ogłoszeń BZP (40 losowych)');
foreach ($db->all("SELECT notice_type, COUNT(*) AS n FROM tenders WHERE source = 'bzp' GROUP BY notice_type ORDER BY n DESC") as $row) {
    echo '  rodzaj ogłoszenia ' . ($row['notice_type'] ?: '(brak)') . ': ' . $row['n'] . "\n";
}
// Losowa próba 34 ogłoszeń plus do 6 ogłoszeń o małej wartości (inny formularz) i ogłoszenie, które wcześniej się nie sparsowało.
$sample = array_merge(
    $db->all("SELECT * FROM tenders WHERE source = 'bzp' AND notice_type = 'ContractNotice' ORDER BY RANDOM() LIMIT 34"),
    $db->all("SELECT * FROM tenders WHERE source = 'bzp' AND (notice_type <> 'ContractNotice' OR external_id = '08df4562-cba9-a534-c1c5-d50001945059') ORDER BY RANDOM() LIMIT 6")
);
$c = ['ok' => 0, 'deadline' => 0, 'criteria' => 0, 'wadium' => 0, 'conditions' => 0, 'docs_url' => 0, 'nip' => 0, 'period' => 0, 'desc' => 0, 'weights100' => 0];
$bad = [];
foreach ($sample as $t) {
    $d = Tenders::details($t);
    usleep(150000);
    if (!$d) {
        $bad[] = $t['external_id'] . ' (brak treści)';
        continue;
    }
    $c['ok']++;
    $c['deadline'] += !empty($d['deadline']) ? 1 : 0;
    $c['criteria'] += !empty($d['criteria']) ? 1 : 0;
    $c['wadium'] += isset($d['wadium']) ? 1 : 0;
    $c['conditions'] += isset($d['conditions_required']) ? 1 : 0;
    $c['docs_url'] += !empty($d['documents_url']) ? 1 : 0;
    $c['nip'] += !empty($d['buyer']['nip']) ? 1 : 0;
    $c['period'] += !empty($d['period']) ? 1 : 0;
    $c['desc'] += !empty($d['descriptions']) ? 1 : 0;
    $sum = array_sum(array_map(static fn ($x) => (float) ($x['weight'] ?? 0), $d['criteria'] ?? []));
    $c['weights100'] += (abs($sum - 100) < 0.6) ? 1 : 0;
    $small = ($d['form'] ?? '') === 'small';
    $c['small'] = ($c['small'] ?? 0) + ($small ? 1 : 0);
    // W krótkim formularzu opis (pkt 3.3) i termin (pkt 3.6) są nieobowiązkowe - wystarczy rozpoznany tytuł.
    if ($small ? empty($d['title']) : (empty($d['descriptions']) || empty($d['deadline']) || empty($d['criteria']))) {
        $bad[] = $t['external_id'] . ' (termin: ' . (empty($d['deadline']) ? 'brak' : 'ok') . ', kryteria: ' . count($d['criteria'] ?? []) . ', opis: ' . count($d['descriptions'] ?? []) . ')';
        // Diagnostyka: jaki to rodzaj ogłoszenia i jak wygląda jego treść.
        $html = (string) (new App\Modules\Tenders\BzpClient())->noticeHtml((string) $t['external_id']);
        $fields = App\Modules\Tenders\BzpNoticeParser::fields($html);
        echo '  [diagnostyka] ' . $t['external_id'] . ' | typ: ' . $t['notice_type'] . ' | ' . truncate((string) $t['title'], 70) . ' | HTML: ' . strlen($html) . ' B | pól: ' . count($fields) . "\n";
        echo '    pola: ' . truncate(implode(' ¦ ', array_map(static fn ($f) => $f['code'] . ' ' . truncate($f['label'], 40), array_slice($fields, 0, 14))), 700) . "\n";
        echo '    tekst: ' . truncate(html_entity_decode(strip_tags($html)), 400) . "\n";
    }
    $rules = Analysis::rules(Tenders::find((int) $t['id']) ?? $t, $d); // nie może rzucić wyjątku na żadnym ogłoszeniu
}
$n = count($sample);
echo "Pobrano treść: {$c['ok']}/$n, w tym ogłoszeń poniżej progu ustawy (krótki formularz bez kryteriów i wadium): " . ($c['small'] ?? 0) . "\n";
foreach (['deadline' => 'termin składania ofert', 'desc' => 'opis przedmiotu', 'criteria' => 'kryteria oceny', 'weights100' => 'wagi kryteriów sumują się do 100', 'wadium' => 'informacja o wadium',
    'conditions' => 'warunki udziału (tak/nie)', 'period' => 'termin realizacji', 'docs_url' => 'adres dokumentów SWZ', 'nip' => 'NIP zamawiającego'] as $k => $label) {
    echo "  $label: " . $pct($c[$k], $c['ok']) . "\n";
}
echo 'Do sprawdzenia: ' . ($bad ? implode('; ', array_slice($bad, 0, 12)) : 'brak') . "\n";
if ($c['ok'] && $c['deadline'] / $c['ok'] < 0.9) {
    $problems[] = 'Parser odczytał termin składania ofert w mniej niż 90% ogłoszeń.';
}
$one = $db->one("SELECT * FROM tenders WHERE source = 'bzp' AND details_json IS NOT NULL ORDER BY id LIMIT 1");
if ($one) {
    $d = json_in($one['details_json']);
    echo "\nPrzykład: " . truncate((string) $one['title'], 100) . "\n";
    foreach (Analysis::rules($one, $d)['items'] as $it) {
        echo '  ' . $it['label'] . ': ' . truncate(str_replace("\n", ' ', (string) $it['value']), 110) . ' [' . $it['source'] . "]\n";
    }
}

// 4. Rejestry firm
$h('4. Firmy: biała lista VAT, KRS, ocena ryzyka');
$nips = array_column($db->all("SELECT DISTINCT buyer_nip, buyer_name FROM tenders WHERE buyer_nip IS NOT NULL AND (LOWER(buyer_name) LIKE '%spółka%' OR LOWER(buyer_name) LIKE '%sp. z o.o%' OR buyer_name LIKE '%S.A.%') LIMIT 7"), 'buyer_nip');
$nips = array_merge($nips, array_column($db->all('SELECT DISTINCT buyer_nip FROM tenders WHERE buyer_nip IS NOT NULL LIMIT 4'), 'buyer_nip'));
$nips = array_values(array_unique($nips));
$levels = [];
foreach ($nips as $nip) {
    $r = Companies::lookup($nip);
    usleep(400000);
    if (!$r['ok']) {
        echo "  NIP $nip: " . $r['error'] . "\n";
        continue;
    }
    $co = $r['company'];
    $s = json_in($co['signals_json']);
    $levels[] = $s['risk']['level'] ?? '?';
    echo '  ' . truncate((string) $co['name'], 60) . ' | KRS ' . ($co['krs'] ?: '-') . ' | ryzyko ' . $co['risk_score'] . ' (' . ($s['risk']['label'] ?? '') . ') | '
        . implode('; ', array_map(static fn ($x) => $x['title'], array_filter($s['risk']['signals'] ?? [], static fn ($x) => $x['level'] !== 'none'))) . "\n";
    if (!empty($co['krs'])) {
        $raw = Registries::krs((string) $co['krs']);
        $dane = $raw['odpis']['dane'] ?? [];
        echo '      dział 4: ' . truncate(json_out($dane['dzial4'] ?? null), 160) . "\n      dział 6: " . truncate(json_out($dane['dzial6'] ?? null), 160) . "\n";
        usleep(300000);
    }
}
$high = count(array_filter($levels, static fn ($l) => $l === 'high'));
echo "Firm sprawdzonych: " . count($levels) . ", z wysokim ryzykiem: $high\n";
if (count($levels) >= 5 && $high > count($levels) / 2) {
    $problems[] = 'Ponad połowa losowych zamawiających ma wysokie ryzyko - ocena jest prawdopodobnie zbyt surowa (fałszywe sygnały).';
}

// 5. Zapytanie zbiorcze do białej listy
$h('5. Biała lista: zapytanie zbiorcze');
$many = Registries::whiteListMany(array_slice($nips, 0, 5));
$found = count(array_filter($many, static fn ($x) => $x['ok'] && $x['subject']));
echo 'Zapytano o ' . count($many) . " firm jednym zapytaniem, znaleziono: $found\n";
foreach ($many as $nip => $x) {
    echo "  $nip: " . ($x['ok'] ? ($x['subject'] ? truncate((string) $x['subject']['name'], 50) . ' | ' . ($x['subject']['statusVat'] ?? '') . ' | kont: ' . ($x['subject']['accountCount'] ?? 0) : 'brak w wykazie') : $x['error']) . "\n";
}
if (count($many) >= 3 && $found === 0) {
    $problems[] = 'Zapytanie zbiorcze do białej listy nie zwróciło żadnej firmy - sprawdź format odpowiedzi.';
}

// 5b. Strony aplikacji na prawdziwych danych (pełna ścieżka: kontroler + widok)
$h('5b. Strony aplikacji na prawdziwych danych');
$_SESSION = [];
Auth::actAs((int) $reg['user_id']);
$get = static function (string $path, array $query = []): int {
    $r = App\Core\App::handle(new App\Core\Request('GET', $path, $query, [], [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost']));
    if ($r->status !== 200) {
        echo "  $path → {$r->status}: " . truncate(strip_tags($r->body), 200) . "\n";
    }
    return $r->status;
};
$pages = 0;
$failed = 0;
foreach (array_merge(
    ['/pulpit', '/przetargi', '/przetargi/szukaj', '/profile', '/kontrahenci', '/oferty'],
    array_map(static fn ($t) => '/przetargi/' . $t['id'], $sample),
    array_map(static fn ($t) => '/przetargi/' . $t['id'], $db->all("SELECT id FROM tenders WHERE source = 'ted' ORDER BY RANDOM() LIMIT 15")),
    array_map(static fn ($c) => '/kontrahenci/' . $c['nip'], $db->all('SELECT nip FROM companies LIMIT 10'))
) as $path) {
    $pages++;
    $failed += $get($path) === 200 ? 0 : 1;
}
foreach ([['q' => 'remont'], ['q' => 'dostawa sprzętu', 'woj' => 'mazowieckie'], ['cpv' => '45233', 'rodzaj' => 'works'], ['q' => 'żółć „test” <b>']] as $q) {
    $pages++;
    $failed += $get('/przetargi/szukaj', $q) === 200 ? 0 : 1;
}
echo "Stron sprawdzonych: $pages, z błędem: $failed\n";
if ($failed) {
    $problems[] = "$failed stron aplikacji zwróciło błąd na prawdziwych danych.";
}
// Szkice ofert z prawdziwych ogłoszeń i eksport do Worda
$offers = 0;
foreach (array_slice($sample, 0, 6) as $t) {
    try {
        $oid = App\Modules\Offers\Offers::create($org, (int) $reg['user_id'], Tenders::find((int) $t['id']));
        $secs = array_map(static fn ($x) => ['title' => $x['title'], 'body' => (string) $x['body']], App\Modules\Offers\Offers::sections($oid));
        $bin = App\Modules\Offers\Docx::build('Oferta', $secs);
        $tmp = tempnam(sys_get_temp_dir(), 'dx');
        file_put_contents($tmp, $bin);
        $z = new ZipArchive();
        $z->open($tmp);
        $xmlOk = simplexml_load_string((string) $z->getFromName('word/document.xml')) !== false;
        $z->close();
        unlink($tmp);
        if (!$xmlOk || $get('/oferty/' . $oid) !== 200) {
            $problems[] = 'Szkic oferty dla ogłoszenia ' . $t['external_id'] . ' jest uszkodzony.';
        }
        $offers++;
    } catch (Throwable $e) {
        $problems[] = 'Szkic oferty: ' . $e->getMessage();
    }
}
echo "Szkice ofert z prawdziwych ogłoszeń: $offers (każdy z poprawnym plikiem .docx)\n";

// 6. Powtórny import (kontynuacja od zapisanego punktu, bez duplikatów)
$h('6. Powtórny import');
$before = (int) $db->val('SELECT COUNT(*) FROM tenders');
$t0 = microtime(true);
$again = Importer::run();
echo "Pobrano ponownie: BZP {$again['bzp']}, TED {$again['ted']}, czas: " . round(microtime(true) - $t0) . " s\n";
echo 'Błędy: ' . ($again['errors'] ? implode(' | ', $again['errors']) : 'brak') . "\n";
if ($again['errors']) {
    $problems[] = 'Powtórny import zgłosił błędy.';
}
echo 'Ogłoszeń w bazie: przed ' . $before . ', po ' . (int) $db->val('SELECT COUNT(*) FROM tenders') . "\n";

$h('Wynik');
echo $problems ? "PROBLEMY:\n - " . implode("\n - ", $problems) . "\n" : "Bez zastrzeżeń.\n";
exit($problems ? 1 : 0);
