<?php

use App\Core\Db;
use App\Core\Http;
use App\Core\Kv;
use App\Http\CronController;
use App\Modules\Content\Fetcher;
use App\Modules\Seo\AiSources;
use App\Modules\Seo\CloudflareBots;
use App\Modules\Seo\Google;
use App\Modules\Seo\GscFile;
use App\Modules\Seo\Insights;
use App\Modules\Seo\Rivals;
use App\Modules\Seo\Sites;
use App\Modules\Seo\Stats;
use App\Modules\Seo\Sync;

/** Paczka ZIP taka jak z przycisku „Eksportuj” w Search Console: nazwa pliku => treść CSV. */
function seo_zip(array $files): array
{
    $path = tempnam(sys_get_temp_dir(), 'gsc') . '.zip';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $name => $csv) {
        $zip->addFromString($name, $csv);
    }
    $zip->close();
    return ['name' => 'sklep-przykladowy.example-Performance-on-Search.zip', 'type' => 'application/zip', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)];
}

function seo_csv_file(string $name, string $csv): array
{
    $path = tempnam(sys_get_temp_dir(), 'gsc') . '.csv';
    file_put_contents($path, $csv);
    return ['name' => $name, 'type' => 'text/csv', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)];
}

/** Tabela dat: $days dni kończących się $end, z tą samą liczbą kliknięć i wyświetleń każdego dnia. */
function seo_dates(string $end, int $days, int $clicks, int $impressions, string $header = 'Data,Kliknięcia,Wyświetlenia,CTR,Pozycja'): string
{
    $out = $header . "\n";
    for ($i = 0; $i < $days; $i++) {
        $out .= gmdate('Y-m-d', strtotime($end . ' UTC') - $i * 86400) . ",$clicks,$impressions,\"3,5%\",\"6,4\"\n";
    }
    return $out;
}

/** Klucz konta usługi wygenerowany na czas testu. Zwraca [JSON dla zmiennej, klucz publiczny]. */
function seo_service_account(string $email = 'panel@projekt-testowy.iam.gserviceaccount.com'): array
{
    static $key = null;
    $key ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    return [json_encode(['type' => 'service_account', 'client_email' => $email, 'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token']), openssl_pkey_get_details($key)['key']];
}

return [
    'Widoczność: start od adresu witryny, wgranie paczki z Search Console bez żadnych ustawień i wnioski z danych' => function () {
        [$orgId, $uid] = make_org();
        $db = Db::get();
        login_as($uid);

        // Pusty ekran: jedno pole na adres. Adres w dowolnej postaci staje się domeną.
        $page = http('GET', '/audyt/widocznosc')->body;
        contains('Dodaj witrynę', $page);
        contains('name="host"', $page);
        eq(['firma.pl', 'firma.pl', 'sklep.firma.com.pl', null, null], [Sites::host('https://www.Firma.pl/oferta?x=1'), Sites::host(' firma.pl '), Sites::host('http://sklep.firma.com.pl:8080/'), Sites::host('to nie adres'), Sites::host('localhost')]);
        $r = http('POST', '/audyt/widocznosc', ['host' => 'to nie adres']);
        eq(303, $r->status);
        eq(0, (int) $db->val('SELECT COUNT(*) FROM seo_sites'));
        $r = http('POST', '/audyt/widocznosc', ['host' => 'https://www.sklep-przykladowy.example/oferta']);
        $siteId = (int) $db->val('SELECT id FROM seo_sites WHERE host = ?', ['sklep-przykladowy.example']);
        ok($siteId > 0, 'witryna zapisana pod samą domeną');
        eq('/audyt/widocznosc/' . $siteId, $r->headers['Location'] ?? '');
        http('POST', '/audyt/widocznosc', ['host' => 'sklep-przykladowy.example']);
        eq(1, (int) $db->val('SELECT COUNT(*) FROM seo_sites'), 'ta sama witryna nie dodaje się drugi raz');
        eq(303, http('GET', '/audyt/widocznosc')->status, 'przy jednej witrynie od razu jej ekran');
        $base = '/audyt/widocznosc/' . $siteId;

        // Ekran startu: dwie drogi. Bez klucza Google panel mówi, kto włącza stałe połączenie, i nie pokazuje pustego adresu.
        with_config(['GOOGLE_SERVICE_ACCOUNT' => ''], function () use ($base) {
            $page = http('GET', $base)->body;
            contains('Wgraj plik z Search Console', $page);
            contains('name="files[]"', $page);
            contains('Nie rozpakowuj jej', $page);
            contains('włącza administrator panelu', $page);
            ok(!str_contains($page, 'value="google"'), 'bez klucza nie ma przycisku „Połącz”');
        });

        // Paczka z polskimi nagłówkami, taka jak z przycisku „Eksportuj”. Okres panel bierze z tabeli dat.
        $end = gmdate('Y-m-d', time() - 3 * 86400);
        $start = gmdate('Y-m-d', strtotime($end . ' UTC') - 27 * 86400);
        $queries = "Najczęstsze zapytania,Kliknięcia,Wyświetlenia,CTR,Pozycja\n"
            . "sklep przykładowy opinie,120,300,40%,\"1,1\"\n"
            . "pozycjonowanie sklepu internetowego,6,900,\"0,67%\",\"7,8\"\n"
            . "jak pozycjonować sklep internetowy,2,500,\"0,4%\",\"9,2\"\n"
            . "audyt seo cena,1,400,\"0,25%\",\"3,1\"\n"
            . "ekspres kolbowy ranking,12,1 100,\"1,09%\",\"2,2\"\n"
            . "\"<b>ekspres</b>, do kawy\",1,60,\"1,67%\",\"9,0\"\n"
            . "rzadkie zapytanie,0,5,0%,\"40,2\"\n";
        $pages = "Najpopularniejsze strony,Kliknięcia,Wyświetlenia,CTR,Pozycja\n"
            . "https://sklep-przykladowy.example/,120,500,24%,\"1,4\"\n"
            . "https://sklep-przykladowy.example/ranking-ekspresow,12,1200,1%,\"2,3\"\n";
        $zip = seo_zip([
            'Zapytania.csv' => "\xEF\xBB\xBF" . $queries, 'Strony.csv' => $pages, 'Daty.csv' => seo_dates($end, 28, 8, 150),
            'Urządzenia.csv' => "Urządzenie,Kliknięcia,Wyświetlenia,CTR,Pozycja\nMOBILE,150,2900,\"5,2%\",\"6,1\"\nDESKTOP,74,1300,\"5,7%\",\"7,0\"\n",
            'Filtry.csv' => "Filtr,Wartość\nTyp wyszukiwania,Sieć\nData,Ostatnie 28 dni\n",
            'Wygląd w wyszukiwarce.csv' => "Wygląd w wyszukiwarce,Kliknięcia,Wyświetlenia,CTR,Pozycja\nFragmenty z opinią,3,90,\"3,3%\",\"5,0\"\n",
        ]);
        $r = http('POST', $base . '/import', [], [], ['files' => $zip]);
        eq(303, $r->status);
        eq($base, $r->headers['Location'] ?? '', 'po wczytaniu raportu „Skuteczność” wracamy na ekran Google');
        eq([['start' => $start, 'end' => $end, 'days' => 28]], Stats::periods($siteId, 'gsc', 'query'));
        eq(7, (int) $db->val("SELECT COUNT(*) FROM seo_stats WHERE site_id = ? AND dim = 'query'", [$siteId]));
        eq(28, (int) $db->val("SELECT COUNT(*) FROM seo_stats WHERE site_id = ? AND dim = 'date'", [$siteId]));
        eq(0, (int) $db->val("SELECT COUNT(*) FROM seo_stats WHERE site_id = ? AND dim IN ('filter', 'appearance')", [$siteId]), 'tabele spoza listy pomijamy');
        eq(1100, (int) $db->val("SELECT impressions FROM seo_stats WHERE site_id = ? AND dim = 'query' AND k = ?", [$siteId, 'ekspres kolbowy ranking']), 'liczba z odstępem jako separatorem tysięcy');
        eq('7.8', (string) (float) $db->val("SELECT position FROM seo_stats WHERE site_id = ? AND dim = 'query' AND k LIKE 'pozycjonowanie%'", [$siteId]));

        $page = http('GET', $base)->body;
        // Sumy z tabeli dat (jak wykres w Search Console): 28 dni po 8 kliknięć i 150 wyświetleń.
        contains('<div class="stat-value">224</div>', $page);
        contains('<div class="stat-value">4' . "\u{a0}" . '200</div>', $page);
        contains('<div class="stat-value">5,3%</div>', $page);
        contains('Nie ma wcześniejszego okresu', $page);
        // Tuż za czołówką: pozycja 7,8 i 900 wyświetleń. Szacunek z umownych 10%: 90 - 6 = 84.
        contains('pozycjonowanie sklepu internetowego', $page);
        contains('<b>ok. 84</b>', $page);
        contains('to wartość umowna', $page);
        // Wysoko, ale mało kliknięć: pozycja 3,1 z klikalnością 0,3% przy umownych 9%.
        ok((bool) preg_match('#id="sg-weak".*audyt seo cena.*<b>0,3%</b>.*9,0%.*\(umownie\)#s', $page), 'zapytanie z pierwszej piątki z niską klikalnością');
        // Marka z adresu witryny (bez polskich znaków i łącznika): zapytanie z nazwą nie trafia na listy.
        ok(Sites::isBrand('Sklep Przykładowy opinie', Sites::brandTerms(Sites::find($orgId, $siteId))), 'marka rozpoznana z domeny');
        contains('Z nazwą marki: <b>120</b>', $page);
        contains('Ukryte przez Google: <b>82</b>', $page, '224 kliknięcia okresu minus 142 w widocznych zapytaniach');
        ok(!preg_match('#id="sg-near".*sklep przykładowy opinie.*id="sg-weak"#s', $page), 'zapytania z marką nie ma na liście okazji');
        // Zapytanie z kodem HTML wyświetla się jako tekst.
        contains('&lt;b&gt;ekspres&lt;/b&gt;, do kawy', $page);
        ok(!str_contains($page, '<b>ekspres</b>'), 'kod HTML z pliku nie trafia do strony');
        contains('Dwie strony na jedno zapytanie', $page);
        contains('wymaga stałego połączenia', $page);
        contains('data-chart', $page, '28 dni to co najmniej trzy pełne tygodnie na wykresie');

        // Drugi plik: wcześniejszy okres. Pojawiają się spadki, a w zakładce AI zapytanie z utrzymaną pozycją i mniejszą klikalnością.
        $prevEnd = gmdate('Y-m-d', strtotime($start . ' UTC') - 86400);
        $prevQueries = "Top queries,Clicks,Impressions,CTR,Position\n"
            . "ekspres kolbowy ranking,40,1000,4%,2.0\n"
            . "pozycjonowanie sklepu internetowego,5,850,0.59%,8.1\n"
            . "młynek do kawy,9,300,3%,5.0\n";
        $r = http('POST', $base . '/import', [], [], ['files' => seo_zip(['Queries.csv' => $prevQueries, 'Dates.csv' => seo_dates($prevEnd, 28, 7, 140, 'Date,Clicks,Impressions,CTR,Position')])]);
        eq(303, $r->status);
        eq(2, count(Stats::periods($siteId, 'gsc', 'query')));
        $page = http('GET', $base)->body;
        contains('Porównanie z okresem', $page);
        contains('<span class="delta up">+14%</span>', $page, '224 kliknięcia wobec 196');
        ok((bool) preg_match('#id="sg-down".*ekspres kolbowy ranking.*<b>12</b>.*40.*młynek do kawy.*zniknęło#s', $page), 'spadek i zapytanie, które zniknęło');
        $ai = http('GET', $base . '/ai')->body;
        ok((bool) preg_match('#Te same wyświetlenia, mniej kliknięć.*ekspres kolbowy ranking.*<b>1,1%</b>.*4,0%.*28#s', $ai), 'pozycja 2,2 wobec 2,0, klikalność z 4% na 1,1%');
        contains('jak pozycjonować sklep internetowy', $ai, 'pytanie na liście pytań');
        ok(Insights::isQuestion('Jak wybrać ekspres?') && Insights::isQuestion('ekspres do kawy dla dwóch osób do małej kuchni') && !Insights::isQuestion('ekspres kolbowy') && !Insights::isQuestion('jak'), 'pytania i długie zapytania');

        // Eksport z porównaniem okresów ma po dwie kolumny każdej liczby: odmawiamy z instrukcją, zamiast zgadywać.
        $compare = seo_csv_file('Zapytania.csv', "Najczęstsze zapytania,Ostatnie 28 dni Kliknięcia,Poprzednie 28 dni Kliknięcia,Ostatnie 28 dni Wyświetlenia,Poprzednie 28 dni Wyświetlenia\nabc,1,2,30,40\n");
        http('POST', $base . '/import', [], [], ['files' => $compare]);
        $page = http('GET', $base . '/dane')->body;
        contains('eksport z porównaniem dwóch okresów', $page);
        // Pojedynczy CSV bez dat: potrzebny okres z formularza. Z okresem się zapisuje.
        $single = "Top queries,Clicks,Impressions,CTR,Position\nnowe zapytanie,3,100,3%,4.0\n";
        http('POST', $base . '/import', [], [], ['files' => seo_csv_file('Queries.csv', $single)]);
        contains('podaj w formularzu okres', http('GET', $base . '/dane')->body);
        http('POST', $base . '/import', ['od' => '2026-01-01', 'do' => '2026-01-28'], [], ['files' => seo_csv_file('Queries.csv', $single)]);
        eq(3, count(Stats::periods($siteId, 'gsc', 'query')));
        // Plik, który nie jest eksportem, i data z przyszłości.
        http('POST', $base . '/import', [], [], ['files' => seo_csv_file('notatki.csv', "a,b\n1,2\n")]);
        contains('Nie rozpoznaliśmy żadnej tabeli', http('GET', $base . '/dane')->body);
        http('POST', $base . '/import', ['od' => '2026-01-01', 'do' => gmdate('Y-m-d', time() + 5 * 86400)], [], ['files' => seo_csv_file('Queries.csv', $single)]);
        contains('Okres jest błędny', http('GET', $base . '/dane')->body);
        eq(3, count(Stats::periods($siteId, 'gsc', 'query')));

        // Jeden plik z 3 miesięcy (domyślny eksport): zmiana ostatnich 28 dni wobec poprzednich liczy się z samych danych dziennych.
        $days = [];
        for ($i = 83; $i >= 0; $i--) {
            $days[] = ['day' => gmdate('Y-m-d', strtotime($end . ' UTC') - $i * 86400), 'clicks' => $i < 28 ? 12 : 10, 'impressions' => 200];
        }
        eq(['clicks' => 336, 'was' => 280, 'impressions' => 5600, 'wasImpressions' => 5600, 'from' => $start, 'to' => $end], Insights::recent($days));
        eq(null, Insights::recent(array_slice($days, -55)), 'za mało dni');
        unset($days[40]);
        eq(null, Insights::recent(array_values($days)), 'dziura w danych dziennych');

        // Druga firma nie widzi witryny ani jej danych i nie może niczego do niej wgrać.
        [$org2, $uid2] = make_org('obcy@example.com', 'Obca Firma sp. z o.o.');
        login_as($uid2);
        foreach (['', '/ai', '/konkurencja', '/dane'] as $tab) {
            eq(404, http('GET', $base . $tab)->status, 'cudza witryna: ' . $tab);
        }
        eq(404, http('POST', $base . '/import', [], [], ['files' => $zip])->status);
        eq(404, http('POST', $base . '/usun', ['potwierdzam' => '1'])->status);
        ok(!str_contains(http('GET', '/audyt/widocznosc')->body, 'sklep-przykladowy.example</a>'), 'lista drugiej firmy jest pusta');
        login_as($uid);

        // Usunięcie wymaga potwierdzenia i kasuje dane witryny.
        http('POST', $base . '/usun');
        eq(1, (int) $db->val('SELECT COUNT(*) FROM seo_sites'));
        eq(303, http('POST', $base . '/usun', ['potwierdzam' => '1'])->status);
        eq([0, 0], [(int) $db->val('SELECT COUNT(*) FROM seo_sites'), (int) $db->val('SELECT COUNT(*) FROM seo_stats')]);
    },

    'Widoczność w AI: raport Google rozpoznany z pliku, tabele wklejone z Binga i Analytics, strony bez odpowiedzi AI' => function () {
        [$orgId, $uid] = make_org();
        $db = Db::get();
        login_as($uid);
        $siteId = Sites::add($orgId, 'sklep-przykladowy.example')['id'];
        $site = Sites::find($orgId, $siteId);
        $base = '/audyt/widocznosc/' . $siteId;
        $end = gmdate('Y-m-d', time() - 3 * 86400);
        $from = gmdate('Y-m-d', time() - 30 * 86400);

        // Pusty ekran mówi przy każdym źródle, jak zdobyć dane.
        $page = http('GET', $base . '/ai')->body;
        contains('raport o generatywnej AI', $page);
        contains('AI Performance', $page);
        contains('podłącz Search Console', $page);

        // Raport o generatywnej AI ma same wyświetlenia: panel rozpoznaje go bez pytania użytkownika i prowadzi do zakładki AI.
        $aiZip = seo_zip([
            'Pages.csv' => "Top pages,Impressions\nhttps://sklep-przykladowy.example/poradnik/odkamienianie,340\nhttps://www.sklep-przykladowy.example/ranking-ekspresow/,95\n",
            'Dates.csv' => "Date,Impressions\n" . implode("\n", array_map(static fn (int $i): string => gmdate('Y-m-d', strtotime($end . ' UTC') - $i * 86400) . ',' . (10 + $i % 7), range(0, 27))) . "\n",
            'Countries.csv' => "Country,Impressions\nPoland,400\nGermany,35\n",
        ]);
        $r = http('POST', $base . '/import', [], [], ['files' => $aiZip]);
        eq($base . '/ai', $r->headers['Location'] ?? '');
        eq(0, (int) $db->val("SELECT COUNT(*) FROM seo_stats WHERE site_id = ? AND source = 'gsc'", [$siteId]), 'raport AI nie miesza się z raportem „Skuteczność”');
        eq(2, (int) $db->val("SELECT COUNT(*) FROM seo_stats WHERE site_id = ? AND source = 'gsc_ai' AND dim = 'page'", [$siteId]));
        // Raport „Skuteczność” z tymi samymi stronami: kliknięcia obok wyświetleń w AI i lista stron, których AI nie pokazuje.
        http('POST', $base . '/import', [], [], ['files' => seo_zip([
            'Pages.csv' => "Top pages,Clicks,Impressions,CTR,Position\nhttps://sklep-przykladowy.example/ranking-ekspresow,12,1200,1%,2.3\nhttps://sklep-przykladowy.example/ekspresy-kolbowe,48,900,5.3%,3.0\nhttps://sklep-przykladowy.example/bez-ruchu,0,40,0%,30\n",
            'Dates.csv' => seo_dates($end, 28, 5, 100, 'Date,Clicks,Impressions,CTR,Position'),
        ])]);
        $page = http('GET', $base . '/ai')->body;
        $sum = array_sum(array_map(static fn (int $i): int => 10 + $i % 7, range(0, 27)));
        contains('<span class="stat-value" style="margin:0">' . $sum . '</span>', $page, 'suma z tabeli dat raportu AI');
        ok((bool) preg_match('#/poradnik/odkamienianie</a></td><td class="n"><b>340</b>#', $page), 'strona z wyświetleniami w AI');
        ok((bool) preg_match('#/ranking-ekspresow/</a></td><td class="n"><b>95</b></td>\s*<td class="n">12</td>#', $page), 'kliknięcia tej samej strony mimo „www” i ukośnika w adresie');
        ok((bool) preg_match('#Strony z kliknięciami, których AI nie pokazuje.*/ekspresy-kolbowe.*48 kliknięć#s', $page), 'strona z ruchem, której nie ma w odpowiedziach AI');
        ok(!preg_match('#których AI nie pokazuje.*/bez-ruchu#s', $page), 'strona bez kliknięć nie trafia na listę');
        contains('data-chart', $page, 'wykres wyświetleń w AI tygodniami');

        // Tabela skopiowana ze strony raportu: tabulatory, odstępy, CSV i liczby z separatorem tysięcy.
        eq([['https://a.pl/x', 124], ['fraza z odstępami', 1234], ['chatgpt.com', 1234], ['perplexity.ai', 87], ['fraza, z przecinkiem', 5], ['https://b.pl/', 7]], AiSources::parseTable(
            "Adres\tCytowania\nhttps://a.pl/x\t124\t3,2%\nfraza z odstępami   1 234\nchatgpt.com 1,234\nperplexity.ai 87\n\"fraza, z przecinkiem\",5\nhttps://b.pl/;7;8\n\nsam tekst bez liczby\n"
        ));
        eq(['ChatGPT', 'ChatGPT', 'Perplexity', 'Copilot', 'Gemini', 'Claude', null, null, null], array_map(AiSources::assistant(...),
            ['chatgpt.com', 'https://chat.openai.com/c/1', 'www.perplexity.ai', 'copilot.microsoft.com', 'gemini.google.com', 'claude.ai', 'google', 'nieclaude.ai', 'chatgpt.com.evil.example']));

        // Bing: cytowane strony i frazy, którymi AI szukało treści.
        $r = http('POST', $base . '/wklej', ['rodzaj' => 'bing_page', 'od' => $from, 'do' => $end, 'suma' => '412', 'dane' => "URL\tCitations\nhttps://sklep-przykladowy.example/poradnik/odkamienianie\t310\nhttps://sklep-przykladowy.example/<i>x</i>\t12\n"]);
        eq($base . '/ai', $r->headers['Location'] ?? '');
        http('POST', $base . '/wklej', ['rodzaj' => 'bing_query', 'od' => $from, 'do' => $end, 'dane' => "jak odkamienić ekspres kolbowy  140\nodkamieniacz do ekspresu 61\n"]);
        // Analytics: z listy wszystkich źródeł zostają asystenci AI, dwie domeny ChatGPT sumują się.
        http('POST', $base . '/wklej', ['rodzaj' => 'ga4_source', 'od' => $from, 'do' => $end, 'suma' => '2 700', 'dane' => "google\t1800\n(direct)\t500\nchatgpt.com\t34\nchat.openai.com\t6\nperplexity.ai\t11\nbing\t90\n"]);
        $page = http('GET', $base . '/ai')->body;
        ok((bool) preg_match('#id="sa-b".*<span class="stat-value" style="margin:0">412</span> cytowań#s', $page), 'suma cytowań z nagłówka raportu');
        contains('jak odkamienić ekspres kolbowy', $page);
        contains('&lt;i&gt;x&lt;/i&gt;', $page);
        ok(!str_contains($page, '<i>x</i>'), 'wklejony kod HTML jako tekst');
        ok((bool) preg_match('#<span class="stat-value" style="margin:0">51</span> sesji z asystentów AI.*1,9% wszystkich sesji#s', $page), '40 + 11 sesji z 2700');
        ok((bool) preg_match('#<b>ChatGPT</b></td><td class="n">40#', $page), 'chatgpt.com i chat.openai.com razem');
        ok(!str_contains($page, '(direct)') && !preg_match('#<b>google</b>#', $page), 'źródła spoza asystentów pominięte');

        // Błędy wklejania: brak liczb, zły okres, nieznany rodzaj, same źródła spoza AI.
        $errors = [];
        foreach ([
            ['rodzaj' => 'bing_page', 'od' => $from, 'do' => $end, 'dane' => "sam tekst\ninny tekst"],
            ['rodzaj' => 'bing_page', 'od' => $end, 'do' => $from, 'dane' => "a\t1"],
            ['rodzaj' => 'cos', 'od' => $from, 'do' => $end, 'dane' => "a\t1"],
            ['rodzaj' => 'ga4_source', 'od' => $from, 'do' => $end, 'dane' => "google\t10\nbing\t2"],
        ] as $post) {
            http('POST', $base . '/wklej', $post);
            $errors[] = http('GET', $base . '/dane')->body;
        }
        contains('Nie znaleźliśmy wierszy z nazwą i liczbą', $errors[0]);
        contains('Podaj okres raportu', $errors[1]);
        contains('Wybierz, jakie dane wklejasz', $errors[2]);
        contains('nie ma asystentów AI', $errors[3]);
        eq(2, (int) $db->val("SELECT COUNT(*) FROM seo_stats WHERE site_id = ? AND source = 'bing_ai' AND dim = 'page'", [$siteId]), 'błędne próby niczego nie zmieniły');
        // Ekran danych pokazuje, co jest podłączone i z jakich okresów.
        $page = http('GET', $base . '/dane')->body;
        ok(substr_count($page, 'dane wklejone') === 2 && substr_count($page, 'dane z pliku') === 2, 'stan czterech źródeł');
    },

    'Google przez konto usługi: jeden adres e-mail, panel sam znajduje usługę Search Console i GA4, pobiera dwa okresy i dane dzienne' => function () {
        [$orgId, $uid] = make_org();
        $db = Db::get();
        login_as($uid);
        $db->update('organizations', ['plan' => 'extended'], 'id = ?', [$orgId]);
        $siteId = Sites::add($orgId, 'sklep-przykladowy.example')['id'];
        $base = '/audyt/widocznosc/' . $siteId;
        [$key, $public] = seo_service_account();
        $calls = static fn (string $part): array => array_values(array_filter(Http::$log, static fn (array $l): bool => str_contains($l['url'], $part)));

        // Klucz w złym formacie nie włącza połączenia. Przyjmujemy JSON i ten sam JSON w base64.
        with_config(['GOOGLE_SERVICE_ACCOUNT' => '{"client_email":"x@y","private_key":"to nie klucz"}'], fn () => ok(!Google::enabled(), 'klucz bez części prywatnej'));
        with_config(['GOOGLE_SERVICE_ACCOUNT' => 'śmieci'], fn () => ok(!Google::enabled(), 'tekst, który nie jest kluczem'));
        with_config(['GOOGLE_SERVICE_ACCOUNT' => base64_encode($key)], fn () => eq('panel@projekt-testowy.iam.gserviceaccount.com', Google::email()));

        with_config(['ADMIN_EMAILS' => 'wlasciciel@example.com', 'GOOGLE_SERVICE_ACCOUNT' => $key], function () use ($orgId, $siteId, $base, $db, $public, $calls) {
            // Ekran startu: adres do skopiowania i jeden przycisk. Żadnych pól na identyfikatory.
            $page = http('GET', $base)->body;
            contains('<code class="copy wrap" title="Kliknij, żeby skopiować">panel@projekt-testowy.iam.gserviceaccount.com</code>', $page);
            contains('Użytkownicy i uprawnienia', $page);
            ok((bool) preg_match('#name="zrodlo" value="google">\s*<button class="btn" type="submit"[^>]*>Połącz</button>#', $page), 'przycisk „Połącz”');
            ok(!str_contains($page, 'name="gsc_property"'), 'na starcie nie ma pól technicznych');

            Http::$log = [];
            $r = http('POST', $base . '/pobierz', ['zrodlo' => 'google']);
            eq($base, $r->headers['Location'] ?? '');

            // Token: podpis RS256 kluczem konta, tylko uprawnienia do odczytu. Jeden token na całe pobranie.
            $tokenCalls = $calls('oauth2.googleapis.com');
            eq(1, count($tokenCalls));
            parse_str($tokenCalls[0]['body'], $form);
            [$h, $c, $s] = explode('.', $form['assertion']);
            $dec = static fn (string $x): string => (string) base64_decode(strtr($x, '-_', '+/'));
            eq(1, openssl_verify($h . '.' . $c, $dec($s), $public, OPENSSL_ALGO_SHA256), 'podpis zgadza się z kluczem publicznym');
            $claims = json_decode($dec($c), true);
            eq(['panel@projekt-testowy.iam.gserviceaccount.com', 'https://oauth2.googleapis.com/token', 3600], [$claims['iss'], $claims['aud'], $claims['exp'] - $claims['iat']]);
            eq('https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/analytics.readonly', $claims['scope']);

            // Usługa znaleziona po domenie (usługa domeny ma pierwszeństwo), niezweryfikowana pominięta.
            $site = Sites::find($orgId, $siteId);
            eq('sc-domain:sklep-przykladowy.example', $site['gsc_property']);
            eq('123456789', $site['ga4_property'], 'jedyna usługa GA4 konta');
            eq([true, true], [Sites::lastSync($site, 'google')['ok'], Sites::lastSync($site, 'ga4')['ok']]);

            // Zapytania według dokumentacji: dwa okresy po 28 dni kończące się 3 dni temu, pary zapytanie + strona, pół roku dni.
            $end = gmdate('Y-m-d', time() - 3 * 86400);
            $cur = gmdate('Y-m-d', strtotime($end . ' UTC') - 27 * 86400);
            $bodies = array_map(static fn (array $l): array => json_decode($l['body'], true), $calls('searchAnalytics/query'));
            eq(9, count($bodies));
            eq(['startDate' => $cur, 'endDate' => $end, 'rowLimit' => 1, 'type' => 'web'], $bodies[0], 'suma okresu bez wymiarów');
            ok(in_array(['startDate' => $cur, 'endDate' => $end, 'dimensions' => ['query', 'page'], 'rowLimit' => 5000, 'type' => 'web'], $bodies, true), 'pary zapytanie + strona');
            eq(2, count(Stats::periods($siteId, 'gsc', 'query')));
            eq(180, (int) $db->val("SELECT COUNT(*) FROM seo_stats WHERE site_id = ? AND source = 'gsc' AND dim = 'date'", [$siteId]));

            $page = http('GET', $base)->body;
            contains('<div class="stat-value">578</div>', $page);
            contains('<span class="delta up">+7,4%</span>', $page, '578 kliknięć wobec 538');
            contains('data-chart', $page);
            contains('Kliknięcia tygodniami', $page);
            // Kanibalizacja: dwie strony z co najmniej 20% wyświetleń zapytania. Trzecia strona (20 wyświetleń) i zapytanie z jedną stroną odpadają.
            ok((bool) preg_match('#id="sg-can".*<b>ekspres kolbowy ranking</b>.*/ranking-ekspresow.*700.*/ekspresy-kolbowe.*400#s', $page), 'dwie strony na jedno zapytanie');
            ok(!preg_match('#id="sg-can".*stary-wpis.*id="sg-brand"#s', $page) && !preg_match('#id="sg-can".*<b>odkamienianie ekspresu</b>.*id="sg-brand"#s', $page), 'słabe strony i pojedyncze zapytania poza listą');
            // Spadek strony i zapytanie z kodem HTML z API jako tekst.
            ok((bool) preg_match('#id="sg-pdown".*/ranking-ekspresow.*<b>12</b>.*41#s', $page), 'strona, która traci');
            ok(!str_contains($page, '<script>alert(1)</script>'), 'kod z API nie trafia do strony');
            contains('Telefon, pozycja 5,1', $page);

            // Analytics: asystenci AI z kilku domen, obce źródło z szerokiego filtra odrzucone, udział we wszystkich sesjach.
            $ga = json_decode($calls(':runReport')[0]['body'], true);
            eq(['fieldName' => 'sessionSource', 'stringFilter' => ['matchType' => 'PARTIAL_REGEXP', 'value' => AiSources::GA4_REGEX, 'caseSensitive' => false]], $ga['dimensionFilter']['filter']);
            $ai = http('GET', $base . '/ai')->body;
            ok((bool) preg_match('#<span class="stat-value" style="margin:0">54</span> sesje z asystentów AI <span class="delta up">\+69%</span>.*2,0% wszystkich sesji#s', $ai), '40 + 11 + 3 wobec 32');
            ok((bool) preg_match('#<b>ChatGPT</b></td><td class="n">40 <span class="delta up">\+100%</span></td><td class="n">20#', $ai), 'ChatGPT z dwóch domen');
            ok(!str_contains($ai, 'grokipedia'), 'źródło spoza listy asystentów');
            ok((bool) preg_match('#/poradnik/odkamienianie <span class="muted">\(ChatGPT, 25\)#', $ai), 'strona wejścia');
            ok(!str_contains($ai, '(not set)'), 'pusta strona wejścia pominięta');

            // Drugie pobranie korzysta z zapamiętanego tokenu i zastępuje okresy, nie dokłada nowych.
            Http::$log = [];
            http('POST', $base . '/pobierz', ['zrodlo' => 'google']);
            eq(0, count($calls('oauth2.googleapis.com')));
            eq(0, count($calls('/sites' . "\n")) + count($calls('accountSummaries')), 'usług nie szukamy drugi raz');
            eq(2, count(Stats::periods($siteId, 'gsc', 'query')));
            eq(180, (int) $db->val("SELECT COUNT(*) FROM seo_stats WHERE site_id = ? AND source = 'gsc' AND dim = 'date'", [$siteId]));

            // Test połączenia w Ustawieniach: tylko administrator platformy. Mówi, jakim adresem panel się przedstawia i które witryny widzi.
            with_config(['ADMIN_EMAILS' => 'ktos-inny@example.com'], fn () => eq(404, http('POST', '/ustawienia/test-google')->status, 'zwykły użytkownik nie testuje kluczy platformy'));
            http('POST', '/ustawienia/test-google');
            $page = http('GET', '/ustawienia')->body;
            contains('Google działa: klucz przyjęty. Konto usługi panel@projekt-testowy.iam.gserviceaccount.com widzi w Search Console 2 witryny', $page);
            contains('sc-domain:sklep-przykladowy.example', $page);
            ok(!str_contains($page, 'niezweryfikowana.example'), 'usługa bez weryfikacji nie liczy się jako dostęp');

            // Zadanie dzienne odświeża tylko witryny, które użytkownik już połączył.
            $other = Sites::add($orgId, 'inna-witryna.example')['id'];
            Http::$log = [];
            $res = Sync::daily();
            eq([2, 1, 0], [$res['sites'], $res['google'], $res['errors']]);
            ok(!Stats::has($other, 'gsc'), 'witryna bez połączenia zostaje nietknięta');
        });

        // Klucz platformy widzi wszystko, do czego ktokolwiek dał mu dostęp, więc działa tylko w firmie administratora panelu.
        // Inna firma nie dostaje przycisku, a zapytanie wysłane mimo to niczego nie pobiera, także dla tej samej domeny.
        with_config(['ADMIN_EMAILS' => 'wlasciciel@example.com', 'GOOGLE_SERVICE_ACCOUNT' => $key, 'CF_ANALYTICS_TOKEN' => 'token-cf'], function () use ($uid) {
            [$org2, $uid2] = make_org('obcy@example.com', 'Obca Firma sp. z o.o.');
            ok(Sync::allowed(1) && !Sync::allowed($org2) && !Sync::allowed(0), 'kto może używać kluczy platformy');
            login_as($uid2);
            $foreign = Sites::add($org2, 'sklep-przykladowy.example')['id'];
            $page = http('GET', '/audyt/widocznosc/' . $foreign)->body;
            ok(!str_contains($page, 'value="google"') && !str_contains($page, 'gserviceaccount.com'), 'bez przycisku i bez adresu konta usługi');
            contains('Wgraj plik z Search Console', $page);
            Http::$log = [];
            foreach (['google', 'cloudflare'] as $source) {
                http('POST', '/audyt/widocznosc/' . $foreign . '/pobierz', ['zrodlo' => $source]);
            }
            eq([], Http::$log, 'żadnego zapytania do Google ani Cloudflare');
            ok(!Stats::has($foreign, 'gsc') && !Stats::has($foreign, 'cf_bot'), 'cudze dane nie trafiły do drugiej firmy');
            contains('Stałe połączenie jest dostępne w firmie, która prowadzi ten panel', http('GET', '/audyt/widocznosc/' . $foreign . '/dane')->body);
            Sites::delete($org2, $foreign);
            login_as($uid);
        });

        // Kilka usług GA4 na koncie: wybiera tę, której strumień danych ma adres witryny (z „www” i ukośnikiem).
        Kv::forget('google:token:' . sha1('panel@projekt-testowy.iam.gserviceaccount.com'));
        with_config(['ADMIN_EMAILS' => 'wlasciciel@example.com', 'GOOGLE_SERVICE_ACCOUNT' => $key, 'FIXTURE_SEO' => 'ga4-wiele'], function () use ($orgId, $siteId, $db) {
            $db->update('seo_sites', ['ga4_property' => ''], 'id = ?', [$siteId]);
            eq(true, Google::syncGa4(Sites::find($orgId, $siteId))['ok']);
            eq('123456789', Sites::find($orgId, $siteId)['ga4_property']);
        });

        // Błędy opisane po ludzku: konto nie dodane w Search Console, wyłączone API, usunięty klucz.
        $site2 = Sites::add($orgId, 'trzecia-witryna.example')['id'];
        $base2 = '/audyt/widocznosc/' . $site2;
        with_config(['ADMIN_EMAILS' => 'wlasciciel@example.com', 'GOOGLE_SERVICE_ACCOUNT' => $key, 'FIXTURE_SEO' => 'bez-dostepu'], function () use ($base2) {
            $r = http('POST', $base2 . '/pobierz', ['zrodlo' => 'google']);
            eq($base2 . '/dane', $r->headers['Location'] ?? '');
            $page = http('GET', $base2 . '/dane')->body;
            contains('Konto usługi nie widzi witryny trzecia-witryna.example w Search Console', $page);
            contains('Dodaj adres panel@projekt-testowy.iam.gserviceaccount.com jako użytkownika', $page);
            contains('nie działa', $page);
        });
        with_config(['ADMIN_EMAILS' => 'wlasciciel@example.com', 'GOOGLE_SERVICE_ACCOUNT' => $key, 'FIXTURE_SEO' => 'api-wylaczone'], function () use ($orgId, $site2) {
            contains('nie jest włączony interfejs Google Search Console API', Google::syncGsc(Sites::find($orgId, $site2))['error']);
        });
        [$bad] = seo_service_account('odrzucony@projekt-testowy.iam.gserviceaccount.com');
        with_config(['ADMIN_EMAILS' => 'wlasciciel@example.com', 'GOOGLE_SERVICE_ACCOUNT' => $bad], function () use ($orgId, $site2) {
            $res = Google::syncGsc(Sites::find($orgId, $site2));
            contains('Google odrzucił klucz konta usługi (Invalid JWT Signature.)', $res['error']);
        });
        // Usługa wpisana ręcznie, do której konto nie ma dostępu.
        with_config(['ADMIN_EMAILS' => 'wlasciciel@example.com', 'GOOGLE_SERVICE_ACCOUNT' => $key], function () use ($orgId, $site2, $db) {
            eq([], Sites::saveSettings(Sites::find($orgId, $site2), ['gsc_property' => 'https://trzecia-witryna.example/', 'brand_terms' => 'Trzecia, Trzecia Witryna, ab']));
            eq('Trzecia, Trzecia Witryna', Sites::find($orgId, $site2)['brand_terms'], 'za krótka nazwa marki odrzucona');
            contains('Konto usługi nie ma dostępu do tych danych w Search Console', Google::syncGsc(Sites::find($orgId, $site2))['error']);
            eq(3, count(Sites::saveSettings(Sites::find($orgId, $site2), ['gsc_property' => 'cokolwiek', 'ga4_property' => 'G-ABC123', 'cf_zone' => 'za-krotkie'])), 'błędne identyfikatory w ustawieniach zaawansowanych');
        });
    },

    'Boty AI z Cloudflare: zapytanie według dokumentacji, podział na asystentów, wyszukiwarki i trening, ostrzeżenie o zablokowanym bocie' => function () {
        [$orgId, $uid] = make_org();
        $db = Db::get();
        login_as($uid);
        $siteId = Sites::add($orgId, 'sklep-przykladowy.example')['id'];
        $base = '/audyt/widocznosc/' . $siteId;

        // Rozpoznawanie bota po nagłówku: podobne nazwy nie mylą się ze sobą.
        eq(['ChatGPT-User', 'GPTBot', 'OAI-SearchBot', 'Claude-User', 'Claude-SearchBot', 'ClaudeBot', 'Perplexity-User', 'PerplexityBot', null], array_map(CloudflareBots::bot(...), [
            'Mozilla/5.0; compatible; ChatGPT-User/1.0', 'compatible; GPTBot/1.2', 'OAI-SearchBot/1.0', 'Claude-User/1.0', 'Claude-SearchBot/1.0', 'ClaudeBot/1.0', 'Perplexity-User/1.0', 'PerplexityBot/1.0', 'Mozilla/5.0 Chrome/130',
        ]));
        $q = CloudflareBots::graphql('abcdef0123456789abcdef0123456789', '2026-11-01T00:00:00Z', '2026-11-08T00:00:00Z', 'datetimeHour userAgent', ['gptbot', 'chatgpt-user']);
        contains('zones(filter: { zoneTag: "abcdef0123456789abcdef0123456789" })', $q);
        contains('httpRequestsAdaptiveGroups(filter: { datetime_geq: "2026-11-01T00:00:00Z" datetime_lt: "2026-11-08T00:00:00Z" requestSource: "eyeball" OR: [{ userAgent_like: "%gptbot%" } { userAgent_like: "%chatgpt-user%" }] }', $q);

        // Bez tokenu ekran mówi, kto to włącza. Z tokenem jeden przycisk, bez wpisywania identyfikatora strefy.
        with_config(['CF_ANALYTICS_TOKEN' => '', 'CF_BROWSER_TOKEN' => ''], function () use ($base) {
            ok(!CloudflareBots::enabled());
            contains('CF_ANALYTICS_TOKEN', http('GET', $base . '/ai')->body);
        });
        with_config(['ADMIN_EMAILS' => 'wlasciciel@example.com', 'CF_ANALYTICS_TOKEN' => 'token-cf'], function () use ($orgId, $siteId, $base, $db) {
            contains('Pobierz z Cloudflare', http('GET', $base . '/ai')->body);
            Http::$log = [];
            $r = http('POST', $base . '/pobierz', ['zrodlo' => 'cloudflare']);
            eq($base . '/ai', $r->headers['Location'] ?? '');
            eq('abcdef0123456789abcdef0123456789', Sites::find($orgId, $siteId)['cf_zone'], 'strefa znaleziona po nazwie domeny');
            $gql = array_values(array_filter(Http::$log, static fn (array $l): bool => str_contains($l['url'], '/graphql')));
            eq(5, count($gql), 'cztery tygodnie pobrań i jedno zapytanie o adresy');
            ok(!str_contains($gql[4]['body'], 'gptbot') && str_contains($gql[4]['body'], 'chatgpt-user') && str_contains($gql[4]['body'], 'edgeResponseStatus_geq: 200'), 'adresy tylko dla botów, które linkują do źródeł');

            $s = CloudflareBots::summary(Sites::find($orgId, $siteId));
            $by = array_column($s['bots'], null, 'name');
            // Godziny z czterech tygodni sumują się w dni: ChatGPT-User codziennie, PerplexityBot prawie zawsze z odmową.
            eq([154, 154, 0], [$by['ChatGPT-User']['requests'], $by['ChatGPT-User']['served'], $by['ChatGPT-User']['blocked']]);
            eq([120, 2, 118], [$by['PerplexityBot']['requests'], $by['PerplexityBot']['served'], $by['PerplexityBot']['blocked']]);
            eq([46, 39, 0], [$by['OAI-SearchBot']['requests'], $by['OAI-SearchBot']['served'], $by['OAI-SearchBot']['blocked']], 'kod 404 to ani podanie, ani blokada');
            ok(!isset($by['Googlebot']), 'zwykła wyszukiwarka nie jest botem AI');
            eq(['assistant' => 163, 'search' => 166, 'training' => 95], $s['byCategory']);
            eq(28, (int) $db->val("SELECT COUNT(*) FROM seo_stats WHERE site_id = ? AND source = 'cf_bot' AND dim = 'bot' AND k = 'ChatGPT-User'", [$siteId]), 'wiersz na każdy dzień');
            eq('ChatGPT-User', $s['bots'][0]['name'], 'asystenci na górze listy');

            $page = http('GET', $base . '/ai')->body;
            ok((bool) preg_match('#<b>PerplexityBot</b> dostał odmowę w 118 z 120 pobrań#', $page), 'ostrzeżenie o zablokowanym bocie, który linkuje do źródeł');
            ok(!preg_match('#<b>GPTBot</b> dostał odmowę#', $page), 'blokada bota treningowego nie jest ostrzeżeniem');
            ok((bool) preg_match('#/poradnik/odkamienianie</a> <span class="muted">\(52: ChatGPT-User, OAI-SearchBot\)#', $page), 'adresy pobierane przez asystentów');
            ok(!str_contains($page, '/robots.txt</a>') && !str_contains($page, 'app.css</a>'), 'pliki techniczne poza listą');
            contains('Liczby z Cloudflare są próbkowane', $page);

            // Ponowne pobranie nie podwaja liczb.
            http('POST', $base . '/pobierz', ['zrodlo' => 'cloudflare']);
            eq(154, array_column(CloudflareBots::summary(Sites::find($orgId, $siteId))['bots'], null, 'name')['ChatGPT-User']['requests']);
        });
        // Token bez uprawnienia i domena, której nie ma na koncie.
        with_config(['ADMIN_EMAILS' => 'wlasciciel@example.com', 'CF_ANALYTICS_TOKEN' => 'token-cf', 'FIXTURE_SEO' => 'cf-odmowa'], function () use ($orgId, $siteId) {
            contains('Zone → Analytics → Read', CloudflareBots::sync(Sites::find($orgId, $siteId))['error']);
        });
        $other = Sites::add($orgId, 'inna-witryna.example')['id'];
        with_config(['CF_BROWSER_TOKEN' => 'token-przegladarki'], function () use ($orgId, $other) {
            ok(CloudflareBots::enabled(), 'jeden token Cloudflare może obsłużyć obie funkcje');
            contains('Wklej identyfikator strefy', CloudflareBots::sync(Sites::find($orgId, $other))['error']);
        });
    },

    'Konkurencja: mapy witryn, pierwsze pobranie jako punkt odniesienia, nowe strony i tematy, których brakuje' => function () {
        [$orgId, $uid] = make_org();
        $db = Db::get();
        login_as($uid);
        $siteId = Sites::add($orgId, 'sklep-przykladowy.example')['id'];
        $base = '/audyt/widocznosc/' . $siteId . '/konkurencja';

        contains('Wpisz konkurentów', http('GET', $base)->body);
        eq(['mlyne' => 'mlynek', 'zarno' => 'zarnowy', 'nozow' => 'nozowy'], Rivals::words('/blog/mlynek-zarnowy-czy-nozowy.html'), 'słowa tematu bez słów pustych');
        eq(['mlyne' => 'mlynek', 'kawy' => 'kawy'], Rivals::words('Młynek do kawy 2026'), 'bez polskich znaków, cyfr i krótkich słów');

        // Lista: do pięciu domen. Własna witryna, tekst i szósta domena odpadają.
        Fetcher::$log = [];
        $r = http('POST', $base, ['rivals' => "https://www.konkurent-a.example/blog\nkonkurent-b.example, sklep-przykladowy.example\nto-nie-domena\nbez-mapy.example"]);
        eq(303, $r->status);
        $site = Sites::find($orgId, $siteId);
        eq(['konkurent-a.example', 'konkurent-b.example', 'bez-mapy.example'], Sites::rivals($site));
        $page = http('GET', $base)->body;
        contains('Pominięte: sklep-przykladowy.example, to-nie-domena', $page);
        contains('Nie znaleźliśmy mapy witryny bez-mapy.example', $page);
        // Mapa z robots.txt, indeks map, mapa tagów i obca domena pominięte. Obrazek i adresy spoza witryny odrzucone.
        $fetched = array_column(Fetcher::$log, 'url');
        ok(in_array('https://konkurent-a.example/post-sitemap.xml', $fetched, true) && !in_array('https://konkurent-a.example/post_tag-sitemap.xml', $fetched, true)
            && !in_array('https://obca-domena.example/sitemap.xml', $fetched, true), 'które mapy pobraliśmy');
        eq(7, (int) $db->val('SELECT COUNT(*) FROM seo_rival_pages WHERE site_id = ? AND host = ?', [$siteId, 'konkurent-a.example']));
        eq(0, (int) $db->val("SELECT COUNT(*) FROM seo_rival_pages WHERE site_id = ? AND (url LIKE '%.jpg' OR baseline = 0)", [$siteId]), 'pierwsze pobranie to punkt odniesienia');
        eq(4, (int) $db->val('SELECT COUNT(*) FROM seo_rival_pages WHERE site_id = ? AND host = ?', [$siteId, 'sklep-przykladowy.example']), 'mapa własnej witryny');
        ok((bool) preg_match('#<b>konkurent-a\.example</b></td>\s*<td class="n">7</td>\s*<td class="n"><span class="muted">od jutra</span></td>\s*<td class="n">1</td>#', $page), 'jedna strona zmieniona według daty w mapie');
        ok((bool) preg_match('#<b>konkurent-b\.example</b>.*mapa bez dat#s', $page), 'mapa bez dat');
        ok((bool) preg_match('#<b>bez-mapy\.example</b>.*brak mapy#s', $page), 'konkurent bez mapy');
        contains('Twoja witryna', $page);
        // Strona zmieniona w ostatnich 30 dniach jest na liście nowości, a adres z kodem HTML jest tekstem.
        ok((bool) preg_match('#id="sr-new".*/blog/mlynek-zarnowy-czy-nozowy</a>.*zmieniona#s', $page), 'zmieniona strona konkurenta');
        ok(!str_contains($page, '<b>xss</b>'), 'adres z mapy jako tekst');
        // Tematy: „młynek” mają obaj konkurenci (3 strony), u nas go nie ma. „Ekspresy kolbowe” są w naszej mapie, więc nie są luką.
        $gaps = Rivals::gaps($site);
        eq('mlynek', $gaps['topics'][0]['word']);
        eq([3, ['konkurent-a.example', 'konkurent-b.example']], [$gaps['topics'][0]['pages'], $gaps['topics'][0]['hosts']]);
        ok(!in_array('ekspresy', array_column($gaps['topics'], 'word'), true) && !in_array('kolbowe', array_column($gaps['topics'], 'word'), true), 'temat, który mamy, nie jest luką');
        ok((bool) preg_match('#id="sr-gap".*<b>mlynek</b></td><td class="n">3</td>#s', $page), 'temat na ekranie');

        // Zapytania z Search Console też liczą się jako „nasze słowa”: po ich wgraniu „spieniacz” przestaje być luką.
        GscFile::store($site, GscFile::parseFiles(['Queries.csv' => "Top queries,Clicks,Impressions,CTR,Position\nspieniacz do mleka,4,200,2%,6.0\n"])['sets'], gmdate('Y-m-d', time() - 30 * 86400), gmdate('Y-m-d', time() - 3 * 86400));
        ok(!in_array('spieniacz', array_column(Rivals::gaps($site)['topics'], 'word'), true), 'temat z naszych zapytań nie jest luką');

        // Drugie pobranie: doszła jedna strona (nowa), jedna zniknęła z mapy.
        with_config(['FIXTURE_SEO' => 'mapa-2'], function () use ($site, $siteId, $db, $base) {
            eq(['ok' => true, 'skipped' => true], Rivals::refresh($site, 'konkurent-a.example'), 'tej samej mapy nie pobieramy co chwilę');
            $res = Rivals::refresh($site, 'konkurent-a.example', true);
            eq([true, 7, 1], [$res['ok'], $res['total'], $res['new']]);
            eq(['https://konkurent-a.example/blog/spieniacz-do-mleka-jak-wybrac'], array_column($db->all('SELECT url FROM seo_rival_pages WHERE site_id = ? AND baseline = 0', [$siteId]), 'url'));
            eq(0, (int) $db->val("SELECT COUNT(*) FROM seo_rival_pages WHERE url LIKE '%stary-wpis-do-usuniecia'"), 'strona usunięta z mapy');
            // Pierwsze pobranie było przed chwilą, więc tabela jeszcze nie liczy nowych. Po dobie liczy.
            $db->run("UPDATE seo_rival_pages SET first_seen = ? WHERE baseline = 1", [gmdate('Y-m-d H:i:s', time() - 3 * 86400)]);
            $page = http('GET', $base)->body;
            ok((bool) preg_match('#<b>konkurent-a\.example</b></td>\s*<td class="n">7</td>\s*<td class="n">1</td>#', $page), 'jedna nowa strona');
            ok((bool) preg_match('#id="sr-new".*spieniacz-do-mleka-jak-wybrac</a>.*nowa strona#s', $page), 'nowa strona na górze listy');
        });

        // Zdjęcie konkurenta z listy kasuje jego strony. Druga firma nie widzi niczego.
        http('POST', $base, ['rivals' => 'konkurent-b.example']);
        eq(0, (int) $db->val('SELECT COUNT(*) FROM seo_rival_pages WHERE host = ?', ['konkurent-a.example']));
        ok(!isset(json_decode((string) $db->val('SELECT synced_json FROM seo_sites WHERE id = ?', [$siteId]), true)['map:konkurent-a.example']), 'stan mapy zdjętego konkurenta posprzątany');
        // Zadanie dzienne odświeża mapy witryn, które mają konkurentów.
        Kv::forget('seo-rival:' . $siteId . ':konkurent-b.example');
        Kv::forget('seo-rival:' . $siteId . ':sklep-przykladowy.example');
        eq(2, Sync::daily()['maps']);
        ok(isset(CronController::daily()['seo']['sites']), 'zadanie dzienne obejmuje widoczność');
        [$org2, $uid2] = make_org('obcy@example.com', 'Obca Firma sp. z o.o.');
        login_as($uid2);
        eq(404, http('GET', $base)->status);
        eq(404, http('POST', $base, ['rivals' => 'zly.example'])->status);
    },
];
