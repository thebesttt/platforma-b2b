<?php

use App\Core\Db;
use App\Core\Holidays;
use App\Core\Http;
use App\Modules\Audit\Audit;
use App\Modules\Notify\Digest;
use App\Modules\Notify\Monitors;
use App\Modules\Notify\Notices;
use App\Modules\Seo\Insights;
use App\Modules\Seo\MonthlyReport;
use App\Modules\Seo\Sites;
use App\Modules\Seo\Stats;
use App\Modules\Seo\Tracking;

/** Klucz konta usługi Google do testów (ten sam format co w Google Cloud). */
function mr_service_account(): string
{
    static $json = null;
    if ($json === null) {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $json = (string) json_encode(['type' => 'service_account', 'client_email' => 'panel@projekt-testowy.iam.gserviceaccount.com', 'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token']);
    }
    return $json;
}

/** Dni GA4 do alarmu: 70 dni kończących się dzień przed $today. $fn(dzień, numer dnia tygodnia) => [sesje, zdarzenia]. */
function mr_ga4_days(string $today, callable $fn, int $days = 70): array
{
    $out = [];
    for ($i = $days; $i >= 1; $i--) {
        $day = gmdate('Y-m-d', strtotime($today . ' UTC') - $i * 86400);
        [$s, $e] = $fn($day, (int) gmdate('N', strtotime($day . ' UTC')));
        $out[] = ['day' => $day, 'clicks' => $s, 'value' => (float) $e];
    }
    return $out;
}

/** Dzień GSC: kliknięcia, wyświetlenia, pozycja. */
function mr_gsc_days(array $site, string $from, string $to, int $clicks, int $impr, float $pos = 8.0): void
{
    $rows = [];
    for ($ts = strtotime($from . ' UTC'); $ts <= strtotime($to . ' UTC'); $ts += 86400) {
        $rows[] = ['day' => gmdate('Y-m-d', $ts), 'clicks' => $clicks, 'impressions' => $impr, 'position' => $pos];
    }
    Stats::putDaily($site, 'gsc', 'date', $rows);
}

return [
    'Święta: Wielkanoc i święta ruchome, Wigilia od 2025 r., tygodnie ze świętem pod wykresem' => function () {
        eq(['2024-03-31', '2025-04-20', '2026-04-05', '2027-03-28'], array_map([Holidays::class, 'easter'], [2024, 2025, 2026, 2027]));
        $y = Holidays::forYear(2026);
        eq(14, count($y));
        eq(['Zielone Świątki', 'Boże Ciało', 'Wigilia', 'Poniedziałek Wielkanocny'], [$y['2026-05-24'], $y['2026-06-04'], $y['2026-12-24'], $y['2026-04-06']]);
        eq(13, count(Holidays::forYear(2024)), 'w 2024 r. Wigilia była dniem pracy');
        ok(!Holidays::is('2024-12-24') && Holidays::is('2025-12-24') && Holidays::is('2026-11-11') && !Holidays::is('2026-11-12'), 'pojedyncze dni');
        eq(['2026-12-24', '2026-12-25', '2026-12-26', '2027-01-01', '2027-01-06'], array_keys(Holidays::between('2026-12-20', '2027-01-07')));
        // Tydzień od poniedziałku. Zielone Świątki wypadają w niedzielę, więc ich tydzień nie dostaje podpisu.
        eq([
            '2026-12-21' => ['Wigilia', 'Boże Narodzenie', 'drugi dzień Bożego Narodzenia'],
            '2026-12-28' => ['Nowy Rok'],
            '2026-11-09' => ['Święto Niepodległości'],
        ], Insights::holidayWeeks(['2026-12-21', '2026-12-28', '2026-11-09', '2026-05-18', '2026-11-16']));
    },

    'Alarm pomiaru: progi z 8 tygodni historii witryny, dwa dni z rzędu, z opóźnieniem i bez świąt' => function () {
        $today = '2026-11-20'; // piątek: sprawdzamy środę 18.11 i wtorek 17.11
        $normal = static fn (string $d, int $dow): array => $dow >= 6 ? [60, 2] : [120, 5];
        eq('ok', Tracking::evaluate(mr_ga4_days($today, $normal), $today)['status']);
        $r = Tracking::evaluate(mr_ga4_days($today, static fn (string $d, int $dow): array => in_array($d, ['2026-11-17', '2026-11-18'], true) ? [118, 0] : $normal($d, $dow)), $today);
        eq(['events', ['2026-11-18', '2026-11-17']], [$r['status'], $r['days']]);
        contains('17.11 i 18.11: 118 i 118 sesji', $r['detail']);
        contains('były w 100% takich dni', $r['detail']);
        // Jeden dzień bez zdarzeń to jeszcze nie alarm: bywa, że nikt nie wysłał formularza.
        eq('ok', Tracking::evaluate(mr_ga4_days($today, static fn (string $d, int $dow): array => $d === '2026-11-18' ? [118, 0] : $normal($d, $dow)), $today)['status']);
        // Dzień sprzed opóźnienia (wczoraj) się nie liczy: dane GA4 z wczoraj bywają niepełne.
        eq('ok', Tracking::evaluate(mr_ga4_days($today, static fn (string $d, int $dow): array => $d >= '2026-11-19' ? [10, 0] : $normal($d, $dow)), $today)['status']);
        $r = Tracking::evaluate(mr_ga4_days($today, static fn (string $d, int $dow): array => $d >= '2026-11-17' ? [4, 0] : $normal($d, $dow)), $today);
        eq('sessions', $r['status'], 'ruch spadł do kilku sesji: tag mógł zniknąć');
        contains('a zwykle w te dni tygodnia około 120', $r['detail']);
        // Mała witryna (10 sesji dziennie): zero sesji to za mało, żeby wołać o tag.
        eq('ok', Tracking::evaluate(mr_ga4_days($today, static fn (string $d, int $dow): array => $d >= '2026-11-17' ? [0, 0] : [10, 1]), $today)['status']);
        // Zdarzenia rzadkie (co czwarty dzień): brak zdarzeń przez dwa dni jest normalny.
        eq('ok', Tracking::evaluate(mr_ga4_days($today, static fn (string $d, int $dow): array => [120, (int) substr($d, 8) % 4 === 0 && $d < '2026-11-17' ? 3 : 0]), $today)['status']);
        // Święto: 13.11 sprawdzamy 10.11 i 9.11, bo 11.11 jest wolny. Zero zdarzeń w Święto Niepodległości nie jest alarmem.
        $r = Tracking::evaluate(mr_ga4_days('2026-11-13', static fn (string $d, int $dow): array => $d === '2026-11-11' ? [40, 0] : $normal($d, $dow)), '2026-11-13');
        eq(['ok', ['2026-11-10', '2026-11-09']], [$r['status'], $r['days']]);
        eq('nodata', Tracking::evaluate(mr_ga4_days($today, $normal, 10), $today)['status']);
    },

    'Alarm pomiaru po pobraniu z GA4: powiadomienie raz na początku i przy powrocie, ekran, pulpit i poranny przegląd' => function () {
        [$orgId, $uid] = make_org();
        $db = Db::get();
        login_as($uid);
        $db->update('organizations', ['plan' => 'extended'], 'id = ?', [$orgId]);
        $siteId = Sites::add($orgId, 'sklep-przykladowy.example')['id'];
        $base = '/audyt/widocznosc/' . $siteId;
        $calls = static fn (string $part): array => array_values(array_filter(Http::$log, static fn (array $l): bool => str_contains($l['url'], $part) && str_contains((string) $l['body'], $part === ':runReport' ? 'keyEvents' : '')));
        with_config(['ADMIN_EMAILS' => 'wlasciciel@example.com', 'GOOGLE_SERVICE_ACCOUNT' => mr_service_account(), 'FIXTURE_SEO' => 'pomiar-zdarzenia'], function () use ($orgId, $siteId, $base, $db, $calls) {
            Http::$log = [];
            http('POST', $base . '/pobierz', ['zrodlo' => 'google']);
            // Jedno zapytanie dzień po dniu: sesje i zdarzenia kluczowe z 70 dni do wczoraj.
            $q = json_decode($calls(':runReport')[0]['body'], true);
            $end = gmdate('Y-m-d', time() - 86400);
            eq([['startDate' => gmdate('Y-m-d', strtotime($end . ' UTC') - 69 * 86400), 'endDate' => $end]], $q['dateRanges']);
            eq([[['name' => 'date']], [['name' => 'sessions'], ['name' => 'keyEvents']]], [$q['dimensions'], $q['metrics']]);
            eq(70, (int) $db->val("SELECT COUNT(*) FROM seo_stats WHERE site_id = ? AND source = 'ga4' AND dim = 'date'", [$siteId]));
            $n = $db->all("SELECT * FROM notices WHERE org_id = ? AND kind = 'tracking'", [$orgId]);
            eq(1, count($n));
            eq(['high', 'Zdarzenia kluczowe przestały się liczyć: sklep-przykladowy.example', $base . '/dane#pomiar'], [$n[0]['level'], $n[0]['title'], $n[0]['url']]);
            contains('zdarzeń kluczowych jest 0', (string) $n[0]['detail']);
            $page = http('GET', $base)->body;
            contains('<div class="notice high" role="alert"', $page);
            contains('Zdarzenia kluczowe przestały się liczyć</b> (od ', $page);
            $data = http('GET', $base . '/dane')->body;
            ok((bool) preg_match('#id="pomiar".*<span class="state s-bad">alarm</span>#s', $data), 'sekcja pomiaru z alarmem');
            contains('w 8 poprzednich tygodniach', $data);
            // Pulpit i menu: powiadomienie widać od razu, z licznikiem nieprzeczytanych.
            $dash = http('GET', '/pulpit')->body;
            contains('Monitory i pomiar', $dash);
            contains('Zdarzenia kluczowe przestały się liczyć: sklep-przykladowy.example', $dash);
            ok((bool) preg_match('#href="/monitory"[^>]*>Monitory <span class="chip info">1</span>#', $dash), 'licznik w menu');
            // Poranny przegląd.
            $mail = Digest::compose($db->one('SELECT * FROM organizations WHERE id = ?', [$orgId]), gmdate('Y-m-d H:i:s', time() - 3600), 'https://panel.example');
            contains('MONITORY, POMIAR I RAPORTY (1)', $mail['body']);
            contains('https://panel.example' . $base . '/dane#pomiar', $mail['body']);
            // Drugie pobranie przy trwającym alarmie nie powiadamia drugi raz.
            http('POST', $base . '/pobierz', ['zrodlo' => 'google']);
            eq(1, (int) $db->val("SELECT COUNT(*) FROM notices WHERE org_id = ? AND kind = 'tracking'", [$orgId]));
        });
        with_config(['ADMIN_EMAILS' => 'wlasciciel@example.com', 'GOOGLE_SERVICE_ACCOUNT' => mr_service_account(), 'FIXTURE_SEO' => ''], function () use ($orgId, $siteId, $base, $db) {
            http('POST', $base . '/pobierz', ['zrodlo' => 'google']);
            $last = $db->one("SELECT * FROM notices WHERE org_id = ? AND kind = 'tracking' ORDER BY id DESC", [$orgId]);
            eq(['info', 'Pomiar znów działa: sklep-przykladowy.example'], [$last['level'], $last['title']]);
            ok(!str_contains(http('GET', $base)->body, 'role="alert"'), 'bez alarmu na zakładce Google');
            contains('<span class="state s-good">działa</span>', http('GET', $base . '/dane')->body);
        });
        with_config(['ADMIN_EMAILS' => 'wlasciciel@example.com', 'GOOGLE_SERVICE_ACCOUNT' => mr_service_account(), 'FIXTURE_SEO' => 'pomiar-ruch'], function () use ($orgId, $siteId, $base, $db) {
            http('POST', $base . '/pobierz', ['zrodlo' => 'google']);
            eq('sessions', Tracking::state(Sites::find($orgId, $siteId))['status']);
            contains('Google Analytics przestał liczyć ruch: sklep-przykladowy.example', (string) $db->val("SELECT title FROM notices WHERE org_id = ? ORDER BY id DESC", [$orgId]));
        });
        // Oznaczenie jako przeczytane zeruje licznik.
        http('POST', '/monitory/przeczytane');
        eq(0, Notices::unread($orgId));
    },

    'Monitory: zdania rozpoznane bez AI, brakujące dane do wyboru w formularzu' => function () {
        $ctx = ['sites' => [5 => 'sklep-przykladowy.example'], 'modules' => array_keys(App\Core\Brand::MODULES), 'rivals' => [5 => ['konkurent.example']]];
        $p = static function (string $s, ?array $c = null) use ($ctx): array {
            $r = Monitors::parse($s, $c ?? $ctx);
            return $r['ok'] ? [$r['kind'], array_filter($r['params'], static fn ($v) => $v !== null), $r['missing']] : [false];
        };
        eq(['gsc_clicks', ['pct' => 40, 'site_id' => 5], []], $p('Powiadom mnie, gdy kliknięcia z Google spadną o 40%'));
        eq(['site_down', ['url' => 'https://sklep-przykladowy.example/kontakt', 'seconds' => 4], []], $p('Daj znać, gdy strona sklep-przykladowy.example/kontakt nie działa albo ładuje się dłużej niż 4 s'));
        eq(['inbound_silence', ['days' => 7], []], $p('Powiadom, gdy przez tydzień nie przyjdzie żadne zapytanie ze strony'));
        eq(['tender', ['phrase' => 'pozycjonowanie stron', 'min_value' => 50000.0], []], $p('Powiadom o przetargach na „pozycjonowanie stron” od 50 tys. zł'));
        eq(['tender', ['phrase' => 'remont dróg', 'min_value' => 1500000.0], []], $p('Nowe przetargi na remont dróg powyżej 1,5 mln zł'));
        eq(['gsc_position', ['query' => 'ekspres kolbowy', 'position' => 10, 'site_id' => 5], []], $p('Daj znać, gdy „ekspres kolbowy” spadnie poza top 10'));
        eq(['rival_pages', ['rival' => 'konkurent.example', 'count' => 3, 'site_id' => 5], []], $p('Powiadom, gdy konkurent.example opublikuje 3 nowe strony'));
        eq(['tender', [], ['phrase']], $p('Powiadom o nowych przetargach'));
        eq([false], $p('Zrób mi kawę i przynieś gazetę'));
        $two = ['sites' => [5 => 'sklep-przykladowy.example', 6 => 'drugi.example']] + $ctx;
        eq(['gsc_clicks', ['pct' => 30], ['site_id']], $p('Gdy kliknięcia z Google spadną o 30%', $two), 'dwie witryny: którą, wybiera użytkownik');
        eq(['gsc_clicks', ['pct' => 25, 'site_id' => 6], []], $p('Gdy kliknięcia na drugi.example spadną o 25%', $two));
        // Walidacja: domyślne progi, granice, obca witryna, konkurent spoza listy.
        eq(['ok' => true, 'params' => ['site_id' => 5, 'pct' => 30], 'errors' => []], Monitors::normalize('gsc_clicks', ['site_id' => 5], $ctx));
        eq(90, Monitors::normalize('gsc_clicks', ['site_id' => 5, 'pct' => 400], $ctx)['params']['pct']);
        eq(['Wybierz witrynę.'], Monitors::normalize('gsc_clicks', ['site_id' => 99], $ctx)['errors']);
        contains('nie ma na liście konkurentów', Monitors::normalize('rival_pages', ['site_id' => 5, 'rival' => 'obcy.example'], $ctx)['errors'][0]);
        eq(['Podaj adres strony, np. https://firma.pl/kontakt.'], Monitors::normalize('site_down', ['url' => 'localhost'], $ctx)['errors']);
        contains('wymaga modułu', Monitors::normalize('tender', ['phrase' => 'drogi'], ['modules' => ['audyt']] + $ctx)['errors'][0]);
    },

    'Monitory: zapis po potwierdzeniu, codzienne sprawdzanie bez AI, powiadomienie przy zmianie, limit i izolacja firm' => function () {
        [$orgId, $uid] = make_org();
        $db = Db::get();
        login_as($uid);
        $siteId = Sites::add($orgId, 'sklep-przykladowy.example')['id'];
        $site = Sites::find($orgId, $siteId);
        $from = gmdate('Y-m-d', time() - 16 * 86400);
        mr_gsc_days($site, $from, gmdate('Y-m-d', time() - 10 * 86400), 50, 900);
        mr_gsc_days($site, gmdate('Y-m-d', time() - 9 * 86400), gmdate('Y-m-d', time() - 3 * 86400), 20, 800);

        // Zdanie → reguła do potwierdzenia, z progiem słowami. AI nie jest potrzebne.
        $r = http('POST', '/monitory/rozpoznaj', ['zdanie' => 'Powiadom mnie, gdy kliknięcia z Google spadną o 40% <b>pilne</b>']);
        eq('/monitory#nowy', $r->headers['Location'] ?? '');
        $page = http('GET', '/monitory')->body;
        contains('Tak to rozumiem: Spadek kliknięć z Google.', $page);
        contains('Kliknięcia z Google na sklep-przykladowy.example w ostatnich 7 dniach spadną o co najmniej 40% wobec 7 dni wcześniej', $page);
        contains('&lt;b&gt;pilne&lt;/b&gt;', $page);
        $r = http('POST', '/monitory', ['rodzaj' => 'gsc_clicks', 'site_id' => (string) $siteId, 'pct' => '40']);
        eq('/monitory', $r->headers['Location'] ?? '');
        $m = $db->one('SELECT * FROM monitors WHERE org_id = ?', [$orgId]);
        eq(['gsc_clicks', 'panel', '{"site_id":' . $siteId . ',"pct":40}'], [$m['kind'], $m['parsed_by'], $m['params_json']]);
        // Pierwsze sprawdzenie od razu: 50 → 20 kliknięć dziennie to -60%.
        eq(true, json_in($m['state_json'])['hit']);
        contains('Kliknięcia ', (string) json_in($m['state_json'])['detail']);
        contains('(-60% średnio na dzień)', (string) json_in($m['state_json'])['detail']);
        eq(1, (int) $db->val("SELECT COUNT(*) FROM notices WHERE org_id = ? AND kind = 'monitor'", [$orgId]));
        contains('Monitor: Kliknięcia z Google na sklep-przykladowy.example', (string) $db->val('SELECT title FROM notices WHERE org_id = ?', [$orgId]));
        // Trwający stan nie powiadamia codziennie. Powrót do normy i ponowny spadek: nowe powiadomienie.
        Monitors::daily();
        eq(1, (int) $db->val("SELECT COUNT(*) FROM notices WHERE org_id = ? AND kind = 'monitor'", [$orgId]));
        mr_gsc_days($site, gmdate('Y-m-d', time() - 9 * 86400), gmdate('Y-m-d', time() - 3 * 86400), 48, 800);
        Monitors::daily();
        eq(false, json_in($db->val('SELECT state_json FROM monitors WHERE org_id = ?', [$orgId]))['hit']);
        mr_gsc_days($site, gmdate('Y-m-d', time() - 9 * 86400), gmdate('Y-m-d', time() - 3 * 86400), 10, 800);
        Monitors::daily();
        eq(2, (int) $db->val("SELECT COUNT(*) FROM notices WHERE org_id = ? AND kind = 'monitor'", [$orgId]));

        // Rodzaj z listy: przetarg z frazą. Pierwszy raz sprawdza ogłoszenia dodane po zapisaniu monitora.
        http('POST', '/monitory/rozpoznaj', ['rodzaj' => 'tender']);
        contains('Tak to rozumiem: Nowy przetarg z frazą.', http('GET', '/monitory')->body);
        http('POST', '/monitory', ['rodzaj' => 'tender', 'phrase' => 'nawierzchni', 'min_value' => '100 000']);
        $tm = $db->one("SELECT * FROM monitors WHERE org_id = ? AND kind = 'tender'", [$orgId]);
        eq('{"phrase":"nawierzchni","min_value":100000}', $tm['params_json']);
        $later = gmdate('Y-m-d H:i:s', time() + 60);
        $tid = $db->insert('tenders', ['source' => 'bzp', 'external_id' => 'mon-1', 'title' => 'Remont NAWIERZCHNI ulicy Polnej', 'value_estimated' => 250000, 'created_at' => $later, 'updated_at' => $later]);
        $db->insert('tenders', ['source' => 'bzp', 'external_id' => 'mon-2', 'title' => 'Remont nawierzchni chodnika', 'value_estimated' => 40000, 'created_at' => $later, 'updated_at' => $later]);
        $db->insert('tenders', ['source' => 'bzp', 'external_id' => 'mon-3', 'title' => 'Dostawa soli drogowej', 'value_estimated' => 900000, 'created_at' => $later, 'updated_at' => $later]);
        $res = Monitors::run($tm);
        eq([true, 1], [$res['hit'], count($res['items'])], 'tylko przetarg z frazą i wartością od 100 tys. zł');
        $n = $db->one("SELECT * FROM notices WHERE monitor_id = ?", [(int) $tm['id']]);
        eq('/przetargi/' . $tid, $n['url']);
        contains('Remont NAWIERZCHNI ulicy Polnej', (string) $n['detail']);
        // Następne sprawdzenie bierze tylko nowsze ogłoszenia.
        $db->update('monitors', ['checked_at' => gmdate('Y-m-d H:i:s', time() + 120)], 'id = ?', [(int) $tm['id']]);
        eq(false, Monitors::run(Monitors::find($orgId, (int) $tm['id']))['hit']);
        // Moduł wyłączony: monitor czeka bez sprawdzania.
        App\Core\Brand::save($orgId, ['modules' => ['audyt', 'klienci', 'kontrahenci']]);
        $d = Monitors::daily();
        ok($d['skipped'] >= 1, 'monitor przetargów pominięty');
        App\Core\Brand::save($orgId, ['modules' => array_keys(App\Core\Brand::MODULES)]);

        // Strona nie działa (404) i znów działa: dwa powiadomienia, drugie informacyjne.
        http('POST', '/monitory/rozpoznaj', ['zdanie' => 'Daj znać, gdy strona sklep-przykladowy.example/brak nie działa']);
        http('POST', '/monitory', ['rodzaj' => 'site_down', 'url' => 'https://sklep-przykladowy.example/brak', 'seconds' => '5']);
        $sd = $db->one("SELECT * FROM monitors WHERE org_id = ? AND kind = 'site_down'", [$orgId]);
        contains('kod 404', (string) json_in($sd['state_json'])['detail']);
        $db->update('monitors', ['params_json' => json_out(['url' => 'https://sklep-przykladowy.example/', 'seconds' => 5])], 'id = ?', [(int) $sd['id']]);
        Monitors::run(Monitors::find($orgId, (int) $sd['id']));
        eq(['high', 'info'], array_column($db->all('SELECT level FROM notices WHERE monitor_id = ? ORDER BY id', [(int) $sd['id']]), 'level'));

        // Błędy w formularzu: komunikat, monitor się nie zapisuje.
        $count = (int) $db->val('SELECT COUNT(*) FROM monitors WHERE org_id = ?', [$orgId]);
        http('POST', '/monitory', ['rodzaj' => 'tender', 'phrase' => 'ab']);
        contains('Podaj frazę z tytułu przetargu', http('GET', '/monitory')->body);
        http('POST', '/monitory', ['rodzaj' => 'gsc_position', 'site_id' => (string) $siteId, 'query' => '']);
        eq($count, (int) $db->val('SELECT COUNT(*) FROM monitors WHERE org_id = ?', [$orgId]));
        http('POST', '/monitory/anuluj');

        // Limit pakietu Podstawowego: 5 monitorów.
        http('POST', '/monitory', ['rodzaj' => 'inbound_silence', 'days' => '5']);
        http('POST', '/monitory', ['rodzaj' => 'gsc_position', 'site_id' => (string) $siteId, 'query' => 'ekspres kolbowy', 'position' => '10']);
        eq(5, (int) $db->val('SELECT COUNT(*) FROM monitors WHERE org_id = ?', [$orgId]));
        http('POST', '/monitory', ['rodzaj' => 'inbound_silence', 'days' => '9']);
        eq(5, (int) $db->val('SELECT COUNT(*) FROM monitors WHERE org_id = ?', [$orgId]));
        contains('Limit monitorów w pakiecie Podstawowy to 5', http('GET', '/monitory')->body);
        http('POST', '/monitory/anuluj');
        contains('Masz 5 z 5 monitorów w pakiecie', http('GET', '/monitory')->body);
        // Brak zapytań: monitor czeka, aż przyjdzie pierwsze, potem liczy dni.
        $im = $db->one("SELECT * FROM monitors WHERE org_id = ? AND kind = 'inbound_silence'", [$orgId]);
        eq(true, !empty(json_in($im['state_json'])['na']));
        $db->insert('inbound_leads', ['org_id' => $orgId, 'score' => 60, 'level' => 'talk', 'email' => 'klient@example.com', 'created_at' => gmdate('Y-m-d H:i:s', time() - 6 * 86400)]);
        $res = Monitors::run(Monitors::find($orgId, (int) $im['id']));
        eq(true, $res['hit']);
        contains('6 dni temu', $res['detail']);

        // Wstrzymanie, sprawdzenie teraz i usunięcie.
        http('POST', '/monitory/' . (int) $im['id'] . '/wlacz');
        eq(0, (int) $db->val('SELECT active FROM monitors WHERE id = ?', [(int) $im['id']]));
        http('POST', '/monitory/' . (int) $im['id'] . '/sprawdz');
        contains('Warunek spełniony.', http('GET', '/monitory')->body);

        // Druga firma nie widzi, nie sprawdza i nie usuwa cudzych monitorów.
        [$org2, $uid2] = make_org('obcy@example.com', 'Obca Firma sp. z o.o.');
        login_as($uid2);
        ok(!str_contains(http('GET', '/monitory')->body, 'sklep-przykladowy.example'), 'cudze monitory niewidoczne');
        foreach (['sprawdz', 'wlacz', 'usun'] as $a) {
            eq(404, http('POST', '/monitory/' . (int) $im['id'] . '/' . $a)->status, $a);
        }
        login_as($uid);
        http('POST', '/monitory/' . (int) $im['id'] . '/usun');
        eq(4, (int) $db->val('SELECT COUNT(*) FROM monitors WHERE org_id = ?', [$orgId]));
        eq(2, (int) $db->val("SELECT COUNT(*) FROM audit_log WHERE action IN ('monitor.create', 'monitor.delete') AND entity_id = ?", [(string) $im['id']]));
        // Usunięcie witryny usuwa jej monitory.
        Sites::delete($orgId, $siteId);
        eq(0, (int) $db->val('SELECT COUNT(*) FROM monitors WHERE site_id = ?', [$siteId]));
    },

    'Monitory: zdanie, którego panel nie rozpozna, rozpoznaje AI (jedno użycie), a bez klucza panel prosi o wybór z listy' => function () {
        [$orgId, $uid] = make_org();
        $db = Db::get();
        login_as($uid);
        Sites::add($orgId, 'sklep-przykladowy.example');
        with_config(['ANTHROPIC_API_KEY' => ''], function () {
            http('POST', '/monitory/rozpoznaj', ['zdanie' => 'Chcę wiedzieć, kiedy coś pójdzie nie tak z widocznością']);
            $page = http('GET', '/monitory')->body;
            contains('Panel nie rozpoznał tego zdania. Wybierz rodzaj monitora z listy.', $page);
            contains('id="mn-kind2"', $page);
            http('POST', '/monitory/anuluj');
        });
        with_config(['ANTHROPIC_API_KEY' => 'sk-test'], function () use ($orgId, $db) {
            http('POST', '/monitory/rozpoznaj', ['zdanie' => 'Chcę wiedzieć, kiedy coś pójdzie nie tak z widocznością']);
            $page = http('GET', '/monitory')->body;
            contains('Zdanie rozpoznało AI (jedno użycie z pakietu)', $page);
            contains('spadną o co najmniej 25%', $page);
            eq(1, (int) $db->val("SELECT calls FROM ai_usage WHERE org_id = ? AND kind = 'ops'", [$orgId]));
            http('POST', '/monitory', ['rodzaj' => 'gsc_clicks', 'site_id' => (string) $db->val('SELECT id FROM seo_sites WHERE org_id = ?', [$orgId]), 'pct' => '25']);
            eq('ai', $db->val('SELECT parsed_by FROM monitors WHERE org_id = ?', [$orgId]));
            // Odpowiedź AI spoza katalogu nie staje się monitorem.
            http('POST', '/monitory/rozpoznaj', ['zdanie' => 'A potem usuń wszystkie dane klienta']);
            contains('Tego zdania nie da się zamienić na żaden monitor z listy', http('GET', '/monitory')->body);
            eq(1, (int) $db->val('SELECT COUNT(*) FROM monitors WHERE org_id = ?', [$orgId]));
        });
    },

    'Raport miesięczny: liczby za miesiąc kalendarzowy, porównania, notatki agencji, link dla klienta bez plików cookie' => function () {
        [$orgId, $uid] = make_org('wlasciciel@example.com', 'Agencja Przykładowa <b>sp. z o.o.</b>');
        $db = Db::get();
        login_as($uid);
        $siteId = Sites::add($orgId, 'sklep-przykladowy.example')['id'];
        $site = Sites::find($orgId, $siteId);
        $month = MonthlyReport::previousMonth();
        [$from, $to] = MonthlyReport::range($month);
        $prevMonth = gmdate('Y-m', strtotime($from . ' UTC') - 86400);
        [$pf, $pt] = MonthlyReport::range($prevMonth);
        $yf = ((int) substr($from, 0, 4) - 1) . substr($from, 4);
        mr_gsc_days($site, $from, $to, 100, 2000, 6.0);
        mr_gsc_days($site, $pf, $pt, 80, 1800, 7.0);
        mr_gsc_days($site, $yf, gmdate('Y-m-t', strtotime($yf . ' UTC')), 50, 1000, 9.0);
        $days = Stats::days($from, $to);
        $pdays = Stats::days($pf, $pt);
        Stats::replace($site, 'gsc', 'query', $pf, $pt, [['k' => 'ekspres kolbowy', 'clicks' => 200, 'impressions' => 3000, 'position' => 4.2]]);
        Stats::replace($site, 'gsc', 'query', $from, $to, [['k' => 'ekspres kolbowy', 'clicks' => 260, 'impressions' => 3100, 'position' => 3.1], ['k' => 'odkamienianie <script>', 'clicks' => 40, 'impressions' => 900, 'position' => 6.5]]);
        Stats::putDaily($site, 'gsc_ai', 'date', [['day' => $from, 'impressions' => 30], ['day' => $to, 'impressions' => 12]]);
        Stats::replace($site, 'ga4_ai', 'source', $from, $to, [['k' => 'ChatGPT', 'value' => 40], ['k' => 'Perplexity', 'value' => 9]]);
        $tid = $db->insert('content_targets', ['org_id' => $orgId, 'url' => 'https://sklep-przykladowy.example/poradnik', 'host' => 'sklep-przykladowy.example', 'phrase' => 'ekspres', 'created_at' => now_utc()]);
        foreach ([61, 78] as $score) {
            $db->insert('content_audits', ['org_id' => $orgId, 'target_id' => $tid, 'status' => 'done', 'ruleset' => 'x', 'score' => $score, 'finished_at' => now_utc(), 'created_at' => now_utc()]);
        }
        $db->insert('site_audits', ['org_id' => $orgId, 'url' => 'https://sklep-przykladowy.example/', 'host' => 'sklep-przykladowy.example', 'score' => 82, 'result_json' => '{}', 'created_at' => now_utc()]);
        $wid = $db->insert('inbound_widgets', ['org_id' => $orgId, 'public_id' => 'w-test', 'name' => 'Formularz', 'rules_json' => '{}', 'origins' => "https://www.sklep-przykladowy.example\nhttps://inna.example", 'created_at' => now_utc()]);
        foreach ([[$from . ' 10:00:00', 'hot'], [$from . ' 12:00:00', 'talk'], [$to . ' 18:00:00', 'hot'], [$pt . ' 09:00:00', 'low']] as [$at, $lvl]) {
            $db->insert('inbound_leads', ['org_id' => $orgId, 'widget_id' => $wid, 'score' => 50, 'level' => $lvl, 'email' => 'k@example.com', 'created_at' => $at]);
        }
        Notices::add($orgId, 'tracking', 'high', 'Zdarzenia kluczowe przestały się liczyć: sklep-przykladowy.example', 'x', '', $siteId);
        $db->run("UPDATE notices SET created_at = ? WHERE org_id = ?", [$from . ' 08:00:00', $orgId]);

        $d = MonthlyReport::build($site, $month);
        eq([100 * $days, 2000 * $days, 6.0, $days], [$d['gsc']['clicks'], $d['gsc']['impressions'], $d['gsc']['position'], count($d['gsc']['daily'])]);
        eq([80 * $pdays, 50 * Stats::days($yf, gmdate('Y-m-t', strtotime($yf . ' UTC')))], [$d['gsc']['prev']['clicks'], $d['gsc']['yoy']['clicks']]);
        eq(0.05, round($d['gsc']['ctr'], 4));
        eq([260, 200, 3.1, 4.2], [$d['queries']['rows'][0]['clicks'], $d['queries']['rows'][0]['clicks_prev'], $d['queries']['rows'][0]['position'], $d['queries']['rows'][0]['position_prev']]);
        eq([42, 49, ['ChatGPT', 40]], [$d['ai']['overviews']['impressions'], $d['ai']['assistants']['sessions'], $d['ai']['assistants']['top'][0]]);
        eq([['score' => 78, 'prev' => 61], ['score' => 82, 'prev' => null]], [array_intersect_key($d['audits']['content'][0], ['score' => 1, 'prev' => 1]), array_intersect_key($d['audits']['tech'], ['score' => 1, 'prev' => 1])]);
        eq(['count' => 3, 'prev' => 1, 'hot' => 2], $d['leads'], 'widżet na www.: liczy się ta domena');
        eq(1, count($d['tracking']['incidents']));

        // Ekran agencji: przygotowanie, notatki, podgląd.
        $base = '/audyt/widocznosc/' . $siteId;
        contains('Raporty dla klienta', http('GET', $base)->body);
        $r = http('POST', $base . '/raporty', ['miesiac' => $month]);
        $rid = (int) $db->val('SELECT id FROM seo_reports WHERE site_id = ?', [$siteId]);
        eq($base . '/raporty/' . $rid, $r->headers['Location'] ?? '');
        $page = http('GET', $base . '/raporty/' . $rid)->body;
        contains('Podgląd: tak raport zobaczy klient.', $page);
        contains('Wejścia z Google', $page);
        contains('odkamienianie &lt;script&gt;', $page);
        contains(num_pl(100 * $days) . ' wejść z wyników Google (+', $page);
        contains('rok do roku', $page);
        // Bez notatek raport nie wychodzi do klienta.
        http('POST', $base . '/raporty/' . $rid, ['akcja' => 'udostepnij', 'zrobione' => '', 'plan' => '']);
        eq(null, $db->val('SELECT token FROM seo_reports WHERE id = ?', [$rid]));
        contains('Przed udostępnieniem dopisz, co zrobiliście', http('GET', $base . '/raporty/' . $rid)->body);
        http('POST', $base . '/raporty/' . $rid, ['akcja' => 'udostepnij', 'zrobione' => "Poprawiliśmy tytuły 12 stron.\n<script>alert(1)</script>", 'plan' => 'Sekcja pytań na stronie usług.']);
        $token = (string) $db->val('SELECT token FROM seo_reports WHERE id = ?', [$rid]);
        eq(40, strlen($token));
        eq(1, (int) $db->val("SELECT COUNT(*) FROM audit_log WHERE action = 'report.share'"));
        contains('/m/' . $token, http('GET', $base . '/raporty/' . $rid)->body);
        contains('udostępniony klientowi', http('GET', $base . '/raporty')->body);

        // Strona klienta: wygląd agencji, bez sesji i plików cookie, poza wyszukiwarkami.
        $_SESSION = [];
        $pub = http('GET', '/m/' . $token);
        eq(200, $pub->status);
        eq('noindex, nofollow', $pub->headers['X-Robots-Tag'] ?? '');
        ok(!isset($pub->headers['Set-Cookie']), 'bez plików cookie');
        contains('Agencja Przykładowa &lt;b&gt;sp. z o.o.&lt;/b&gt;', $pub->body);
        contains('Poprawiliśmy tytuły 12 stron.', $pub->body);
        contains('&lt;script&gt;alert(1)&lt;/script&gt;', $pub->body);
        ok(!str_contains($pub->body, '<script'), 'strona klienta bez skryptów');
        contains('Plan na następny miesiąc', $pub->body);
        contains('Linki do strony w odpowiedziach AI Google', $pub->body);
        contains('3 zapytania ze strony (w poprzednim miesiącu 1)', $pub->body);
        contains('Audyt techniczny (bezpieczeństwo, szybkość, SEO techniczne): <b>82/100</b>', $pub->body);
        contains('Zdarzenia kluczowe przestały się liczyć', $pub->body);
        contains('Wersja do druku', $pub->body);
        eq(404, http('GET', '/m/' . str_repeat('a', 40))->status);
        login_as($uid);

        // Wygląd: kolor, stopka i logo z pliku. Zły kolor i plik, który nie jest obrazem, dają komunikat.
        $png = tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        http('POST', $base . '/raporty/wyglad', ['color' => '#d4a94a', 'footer' => 'Opiekun: Anna Nowak'], [], ['logo' => ['name' => 'logo.png', 'type' => 'image/png', 'tmp_name' => $png, 'error' => UPLOAD_ERR_OK, 'size' => filesize($png)]]);
        $pub = http('GET', '/m/' . $token)->body;
        contains('--rep: #D4A94A', $pub);
        contains('<img src="data:image/png;base64,', $pub);
        contains('Opiekun: Anna Nowak', $pub);
        login_as($uid);
        $txt = tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($txt, 'to nie obraz');
        http('POST', $base . '/raporty/wyglad', ['color' => 'zielony'], [], ['logo' => ['name' => 'logo.txt', 'type' => 'text/plain', 'tmp_name' => $txt, 'error' => UPLOAD_ERR_OK, 'size' => 12]]);
        $page = http('GET', $base . '/raporty')->body;
        contains('Kolor podaj w zapisie #RRGGBB', $page);
        contains('Logo musi być plikiem PNG, JPEG, WebP albo SVG.', $page);
        eq('#D4A94A', MonthlyReport::brand($db->one('SELECT * FROM organizations WHERE id = ?', [$orgId]))['color'], 'zły kolor niczego nie zmienił');

        // Odświeżenie liczb, wyłączenie linku, miesiąc z przyszłości.
        mr_gsc_days($site, $from, $to, 110, 2000, 6.0);
        http('POST', $base . '/raporty/' . $rid, ['akcja' => 'odswiez']);
        eq(110 * $days, json_in($db->val('SELECT data_json FROM seo_reports WHERE id = ?', [$rid]))['gsc']['clicks']);
        http('POST', $base . '/raporty/' . $rid, ['akcja' => 'wylacz']);
        eq(404, http('GET', '/m/' . $token)->status);
        login_as($uid);
        http('POST', $base . '/raporty', ['miesiac' => gmdate('Y-m', strtotime('+2 month'))]);
        contains('Wybierz miesiąc, który już się zaczął.', http('GET', $base . '/raporty')->body);

        // Druga firma nie otwiera, nie zmienia i nie usuwa cudzego raportu.
        [$org2, $uid2] = make_org('obcy@example.com', 'Obca Firma sp. z o.o.');
        login_as($uid2);
        eq(404, http('GET', $base . '/raporty/' . $rid)->status);
        eq(404, http('GET', $base . '/raporty')->status);
        eq(404, http('POST', $base . '/raporty/' . $rid, ['akcja' => 'udostepnij', 'zrobione' => 'x'])->status);
        eq(404, http('POST', $base . '/raporty/' . $rid . '/usun')->status);
        login_as($uid);
        http('POST', $base . '/raporty/' . $rid . '/usun');
        eq(0, (int) $db->val('SELECT COUNT(*) FROM seo_reports WHERE site_id = ?', [$siteId]));
    },

    'Raport miesięczny: szkic za poprzedni miesiąc powstaje sam od 4. dnia, raz, z powiadomieniem' => function () {
        [$orgId, $uid] = make_org();
        $db = Db::get();
        $siteId = Sites::add($orgId, 'sklep-przykladowy.example')['id'];
        $site = Sites::find($orgId, $siteId);
        $today = gmdate('Y-m') . '-03';
        $month = MonthlyReport::previousMonth($today);
        [$from, $to] = MonthlyReport::range($month);
        mr_gsc_days($site, $from, gmdate('Y-m-d', strtotime($from . ' UTC') + 18 * 86400), 30, 500);
        eq(0, MonthlyReport::daily($today)['created'], 'przed 4. dniem miesiąca');
        eq(0, MonthlyReport::daily(gmdate('Y-m') . '-04')['created'], '19 dni danych to za mało');
        mr_gsc_days($site, $from, $to, 30, 500);
        eq(1, MonthlyReport::daily(gmdate('Y-m') . '-04')['created']);
        eq(0, MonthlyReport::daily(gmdate('Y-m') . '-05')['created'], 'drugi raz nie powstaje');
        $n = $db->one("SELECT * FROM notices WHERE org_id = ? AND kind = 'report'", [$orgId]);
        contains('Raport za ' . MonthlyReport::label($month) . ' gotowy do przejrzenia', (string) $n['title']);
        eq('/audyt/widocznosc/' . $siteId . '/raporty/' . $db->val('SELECT id FROM seo_reports WHERE site_id = ?', [$siteId]), $n['url']);
        eq(null, $db->val('SELECT created_by FROM seo_reports WHERE site_id = ?', [$siteId]));
        // Firma bez modułu Audyt nie dostaje szkiców.
        [$org2] = make_org('druga@example.com', 'Druga Firma');
        App\Core\Brand::save($org2, ['modules' => ['przetargi']]);
        $s2 = Sites::find($org2, Sites::add($org2, 'druga.example')['id']);
        mr_gsc_days($s2, $from, $to, 30, 500);
        eq(0, MonthlyReport::daily(gmdate('Y-m') . '-06')['created']);
    },

    'Audyt strony: skrypty śledzące a zgoda na cookies (bez skryptów nie ma ostrzeżenia, skrypt reklamowy bez blokady, ciasteczka z serwera)' => function () {
        $s = Audit::scanner();
        $ids = static fn (string $html, array $h = []): array => array_map(static fn (array $f): string => $f['id'] . '/' . $f['sev'], $s->marketing_findings($html, $h));
        $gtag = '<script async src="https://www.googletagmanager.com/gtag/js?id=G-ABC1234"></script>';
        $pixel = '<script>!function(f,b,e,v){};fbq("init","123");</script><script src="https://connect.facebook.net/en_US/fbevents.js"></script>';
        eq(['ga4/warning', 'pixel/info'], $ids('<html><body>Strona bez pomiaru</body></html>'), 'strona bez skryptów nie potrzebuje banera');
        eq(['ga4/ok', 'consent/warning'], $ids($gtag . $pixel));
        $f = $s->marketing_findings($gtag . $pixel);
        contains('Strona ładuje: Google Analytics / Tag Manager, Meta Pixel.', $f[1]['detail']);
        contains('art. 399 Prawa komunikacji elektronicznej', $f[1]['detail']);
        // Baner zgody jest, ale LinkedIn i Hotjar wpisane zwykłym znacznikiem: mogą ładować się przed zgodą.
        $early = '<script src="https://consent.cookiebot.com/uc.js"></script>' . $gtag . '<script src="https://snap.licdn.com/li.lms-analytics/insight.min.js"></script><script src="https://static.hotjar.com/c/hotjar-1.js"></script>';
        eq(['ga4/ok', 'consent_early/warning', 'pixel/info'], $ids($early));
        contains('LinkedIn Insight Tag, Hotjar są wpisane w kod strony', $s->marketing_findings($early)[1]['detail']);
        // Skrypty zablokowane przez baner (type="text/plain", data-src) i tryb zgody Google: w porządku.
        $blocked = '<script src="https://cdn.cookielaw.org/otSDKStub.js"></script><script type="text/plain" class="optanon-category-C0004" src="https://connect.facebook.net/en_US/fbevents.js"></script>'
            . '<script type="text/plain" data-category="marketing">fbq("init","1")</script><script data-src="https://snap.licdn.com/li.lms-analytics/insight.min.js"></script>';
        eq(['ga4/warning', 'consent/ok'], $ids($blocked));
        eq(['ga4/ok', 'consent/ok', 'pixel/info'], $ids('<script>gtag("consent","default",{ad_storage:"denied"});</script>' . $gtag), 'tryb zgody Google bez banera z listy');
        // Serwer zapisuje _ga już w pierwszej odpowiedzi.
        eq(['ga4/ok', 'consent/warning', 'consent_cookies/warning', 'pixel/info'], $ids($gtag, ['set-cookie' => ['_ga=GA1.1.123; Path=/; Max-Age=63072000', 'PHPSESSID=abc; Path=/']]));
        contains('_ga', $s->marketing_findings($gtag, ['set-cookie' => ['_ga=1', '_fbp=fb.1']])[2]['detail']);
        eq(['Meta Pixel' => ['google' => false, 'direct' => false]], PrestaCheck_Scanner::trackers('<script type="text/plain">fbq("init","1")</script>'));
    },
];
