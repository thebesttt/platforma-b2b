<?php
/**
 * Strony przykładowe do audytu treści (testy i konto demonstracyjne). Dane są fikcyjne.
 *   wzor  - strona, która spełnia wszystkie wytyczne (100 pkt),
 *   po    - ta sama strona po pracach, z dwoma drobnymi brakami,
 *   przed - strona przed pracami: typowe błędy sklepu z poradnikiem,
 *   zero  - strona, która nie spełnia żadnego wskaźnika poza tym, że odpowiada.
 */

declare(strict_types=1);

const SITE_URL = 'https://sklep-przykladowy.example/poradnik/ekspres-cisnieniowy-do-domu';
const SITE_ANSWER = 'Do domu najczęściej wystarczy ekspres kolbowy albo automatyczny o ciśnieniu 15 barów.';

/** Odpowiedzi na pytania poboczne: zdanie, które jest w treści strony „po”. Kolejność jak w site_questions(). */
function site_quotes(): array
{
    return [
        'Ekspres kolbowy wymaga ręcznego ubijania kawy w kolbie, a automatyczny sam mieli ziarna i parzy napój po naciśnięciu przycisku.',
        'Pompa o ciśnieniu 15 barów w zupełności wystarcza, bo samo parzenie espresso odbywa się przy około 9 barach.',
        'Dobry ekspres kolbowy do domu kosztuje od 600 do 1500 zł, a automatyczny od 1500 do 4000 zł.',
        'Wbudowany młynek podnosi cenę o około 400 zł, ale świeżo zmielone ziarna dają wyraźnie lepszy smak.',
        'Przy twardej wodzie ekspres trzeba odkamieniać co 2 miesiące, przy miękkiej wystarczy raz na 4 miesiące.',
        'Do małej kuchni wybierz model o szerokości do 20 cm, bo zmieści się obok czajnika.',
        'Ekspres o mocy 1450 W zużywa około 0,05 kWh na jedną kawę, czyli mniej niż 10 groszy.',
        'Najlepiej sprawdza się kawa ziarnista palona nie dawniej niż 3 miesiące temu.',
        'Dysza pary spieni 150 ml mleka w około 40 sekund.',
        null,
    ];
}

function site_questions(): array
{
    return [
        'Czym różni się ekspres kolbowy od automatycznego?', 'Jakie ciśnienie powinien mieć ekspres do domu?', 'Ile kosztuje dobry ekspres ciśnieniowy do domu?',
        'Czy warto dopłacić do ekspresu z młynkiem?', 'Jak często trzeba odkamieniać ekspres?', 'Jaki ekspres wybrać do małej kuchni?',
        'Ile prądu zużywa ekspres ciśnieniowy?', 'Jaka kawa nadaje się do ekspresu ciśnieniowego?', 'Czy ekspres ciśnieniowy spieni mleko?',
        'Ile lat działa ekspres i jak wygląda serwis?',
    ];
}

function site_page(string $variant): string
{
    if ($variant === 'przed') {
        return site_before();
    }
    $q = site_quotes();
    $about = $variant === 'po' ? '' : '<a href="/o-nas">O nas</a> ';
    $ld = [
        '@context' => 'https://schema.org',
        '@graph' => [
            ['@type' => 'Article', 'headline' => 'Jaki ekspres ciśnieniowy do domu wybrać', 'datePublished' => '2026-02-03', 'dateModified' => '2026-11-12',
                'author' => ['@type' => 'Person', 'name' => 'Marta Kawowska', 'url' => 'https://sklep-przykladowy.example/autor/marta-kawowska'],
                'publisher' => ['@id' => 'https://sklep-przykladowy.example/#firma']],
            ['@type' => 'Organization', '@id' => 'https://sklep-przykladowy.example/#firma', 'name' => 'Sklep Przykładowy', 'url' => 'https://sklep-przykladowy.example/',
                'logo' => 'https://sklep-przykladowy.example/logo.png'],
            ['@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 1, 'name' => 'Poradnik', 'item' => 'https://sklep-przykladowy.example/poradnik']]],
        ],
    ];
    return '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Jaki ekspres ciśnieniowy do domu wybrać? Poradnik 2026</title>
<meta name="description" content="Kolbowy czy automatyczny, jakie ciśnienie i ile to kosztuje. Porównanie ekspresów ciśnieniowych do domu z cenami, parametrami i kosztami używania.">
<link rel="canonical" href="' . SITE_URL . '">
<link rel="stylesheet" href="/style.css">
<script type="application/ld+json">' . json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>
</head><body>
<header><a href="/">Sklep Przykładowy</a><nav><a href="/ekspresy-kolbowe">Ekspresy kolbowe</a> <a href="/poradnik">Poradnik</a> ' . $about . '<a href="/kontakt">Kontakt</a></nav></header>
<main><article>
<h1>Jaki ekspres ciśnieniowy do domu wybrać</h1>
<p class="byline">Autor: <a rel="author" href="/autor/marta-kawowska">Marta Kawowska</a>, aktualizacja <time datetime="2026-11-12">12 listopada 2026</time></p>
<p>' . SITE_ANSWER . ' Kolbowy jest tańszy i daje pełną kontrolę nad parzeniem, automatyczny robi kawę jednym przyciskiem. Poniżej porównujemy oba rodzaje ekspresów ciśnieniowych do domu: ceny, parametry i koszty używania.</p>
<img src="/img/ekspres-kolbowy.jpg" alt="Ekspres kolbowy z kolbą i ubijakiem na blacie kuchennym" width="800" height="500">
<h2>Ekspres kolbowy czy automatyczny</h2>
<p>' . $q[0] . ' W ekspresie kolbowym na jedno espresso potrzeba 18 g zmielonej kawy i 25 sekund parzenia. Automat odmierza porcję sam, zwykle od 7 do 14 g. Według <a href="https://sca.coffee/research/protocols-best-practices">zaleceń Specialty Coffee Association</a> woda powinna mieć od 90 do 96 °C.</p>
<p>' . $q[2] . ' Różnica w cenie wynika głównie z młynka i automatyki. ' . $q[3] . '</p>
<h2>Jakie ciśnienie i moc wybrać</h2>
<p>' . $q[1] . ' Wyższa liczba na pudełku nie oznacza lepszej kawy. Ważniejsza jest stabilna temperatura, dlatego szukaj grzałki o mocy co najmniej 1300 W.</p>
<p>' . $q[6] . ' Dane o zużyciu energii producenci podają na etykiecie zgodnej z <a href="https://eur-lex.europa.eu/legal-content/PL/TXT/?uri=CELEX:32017R1369">rozporządzeniem UE 2017/1369</a>.</p>
<h3>Ekspres do małej kuchni</h3>
<p>' . $q[5] . ' Zbiornik na wodę o pojemności 1 l wystarcza na 6 kaw.</p>
<img src="/img/ekspres-automatyczny.jpg" alt="Ekspres automatyczny z pojemnikiem na mleko" width="800" height="500">
<h2>Kawa, mleko i odkamienianie</h2>
<p>' . $q[7] . ' Po otwarciu paczki zużyj ją w ciągu 4 tygodni. ' . $q[8] . '</p>
<p>' . $q[4] . ' Twardość wody w swojej miejscowości sprawdzisz na stronie wodociągów albo w <a href="https://www.gov.pl/web/gis/jakosc-wody">serwisie Głównego Inspektoratu Sanitarnego</a>. Odkamienianie trwa około 30 minut.</p>
<h2>Gwarancja i serwis</h2>
<p>Standardowa gwarancja producenta trwa 2 lata. Część marek przedłuża ją do 3 lat po rejestracji urządzenia. Uszczelkę w głowicy ekspresu kolbowego wymienia się co 12 miesięcy, a koszt części to około 30 zł.</p>
</article></main>
<footer><p>Sklep Przykładowy sp. z o.o., ul. Kawowa 5, 00-001 Warszawa. NIP: 123-456-32-18. Telefon: 22 123 45 67, e-mail: kontakt@sklep-przykladowy.example</p></footer>
</body></html>';
}

/** Akapit z podanej liczby zdań ogólnych, bez liczb: materiał na „ścianę tekstu”. */
function site_filler(int $sentences, int $seed = 0): string
{
    $pool = [
        'Wybór ekspresu to decyzja, którą warto dobrze przemyśleć, ponieważ urządzenie będzie stało w kuchni przez długi czas.',
        'Na rynku jest dziś bardzo wiele modeli i trudno się w nich połapać bez wcześniejszego przygotowania.',
        'Każdy miłośnik kawy ma swoje przyzwyczajenia, dlatego nie istnieje jedno rozwiązanie dobre dla wszystkich.',
        'Producenci prześcigają się w nowych funkcjach, choć nie każda z nich jest potrzebna w codziennym użytkowaniu.',
        'Warto zastanowić się, kto będzie korzystał z ekspresu i jak często będzie przygotowywana kawa.',
        'Dla jednych najważniejsza jest wygoda, dla innych smak napoju albo wygląd urządzenia na blacie.',
        'Nie bez znaczenia pozostaje także łatwość czyszczenia, o której wiele osób przypomina sobie dopiero po zakupie.',
        'Dobrze dobrany sprzęt odwdzięczy się aromatycznym naparem i bezproblemową pracą.',
        'Przed zakupem dobrze jest przeczytać opinie innych użytkowników oraz porównać kilka propozycji.',
        'Pamiętajmy również, że sama kawa ma ogromny wpływ na efekt końcowy w filiżance.',
        'Kolejną kwestią jest miejsce w kuchni, ponieważ nie każdy model zmieści się pod szafkami.',
        'Niektóre urządzenia pracują głośniej od innych, co bywa uciążliwe wczesnym rankiem.',
    ];
    $out = [];
    for ($i = 0; $i < $sentences; $i++) {
        $out[] = $pool[($i + $seed) % count($pool)];
    }
    return implode(' ', $out);
}

function site_before(): string
{
    $q = site_quotes();
    $imgs = '';
    for ($i = 1; $i <= 12; $i++) {
        $imgs .= '<img src="/img/model-' . $i . '.jpg"' . ($i <= 7 ? ' alt="Ekspres ciśnieniowy, model ' . $i . ' z oferty sklepu"' : '') . ' width="400" height="300">';
    }
    return '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Jaki ekspres ciśnieniowy do domu wybrać w 2026 roku? Ranking | Sklep Przykładowy</title>
<link rel="canonical" href="' . SITE_URL . '">
<script type="application/ld+json">{"@context":"https://schema.org","@type":"Article","headline":"Jaki ekspres ciśnieniowy do domu wybrać"}</script>
</head><body>
<header><a href="/">Sklep Przykładowy</a><nav><a href="/ekspresy-kolbowe">Ekspresy kolbowe</a> <a href="/poradnik">Poradnik</a> <a href="/kontakt">Kontakt</a></nav></header>
<main><article>
<h1>Poradnik</h1>
<p>Opublikowano 3.02.2026</p>
<p>' . site_filler(12) . '</p>
<h2>Informacje</h2>
<p>' . site_filler(1, 3) . ' ' . SITE_ANSWER . ' ' . site_filler(12, 4) . '</p>
<h2>Ekspres kolbowy czy automatyczny</h2>
<p>' . $q[0] . ' ' . site_filler(3, 5) . '</p>
<p>' . $q[3] . ' ' . site_filler(2, 8) . '</p>
<h2>Jakie ciśnienie wybrać</h2>
<p>' . $q[1] . ' ' . site_filler(3, 1) . '</p>
<h2>Kawa i mleko</h2>
<p>' . $q[7] . ' ' . $q[8] . ' ' . site_filler(3, 6) . '</p>
<div id="tabela-modeli"></div>
' . $imgs . '
</article></main>
<footer><p>Sklep Przykładowy sp. z o.o., ul. Kawowa 5, 00-001 Warszawa. NIP: 123-456-32-18. Telefon: 22 123 45 67, e-mail: kontakt@sklep-przykladowy.example</p></footer>
</body></html>';
}

/** Tabela, którą strona „przed” dokleja skryptem: po uruchomieniu skryptów treści jest wyraźnie więcej. */
function site_before_rendered(): string
{
    $rows = '';
    for ($i = 1; $i <= 18; $i++) {
        $rows .= '<tr><td>Model przykładowy numer ' . $i . '</td><td>ekspres kolbowy z ręcznym spieniaczem mleka</td><td>ciśnienie pompy piętnaście barów, grzałka z termoblokiem</td><td>gwarancja producenta i serwis na terenie kraju</td></tr>';
    }
    return str_replace(['<div id="tabela-modeli"></div>', '<html lang="pl">'], ['<div id="tabela-modeli"><table>' . $rows . '</table></div>', '<html lang="pl" data-pd-width="360/360">'], site_before());
}

function site_contact(): string
{
    return '<!doctype html><html lang="pl"><head><meta charset="utf-8"><title>Kontakt | Sklep Przykładowy</title></head><body><main><h1>Kontakt</h1>
<p>Sklep Przykładowy sp. z o.o., ul. Kawowa 5, 00-001 Warszawa. NIP 1234563218.</p><p>Telefon: 22 123 45 67. E-mail: kontakt@sklep-przykladowy.example</p></main></body></html>';
}

function site_zero(): string
{
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex, nosnippet">
<script type="application/ld+json">{"@context": "https://schema.org", "@type": "Article", "headline": }</script></head><body>
<div><h3>Informacje</h3><h5>Więcej</h5>
<p>' . site_filler(14) . '</p><p>' . site_filler(14, 4) . '</p><p>' . site_filler(14, 7) . '</p>
<img src="http://zly-przyklad.example/a.jpg"><img src="http://zly-przyklad.example/b.jpg"><img src="/c.jpg">
</div></body></html>';
}

function site_psi(string $variant): array
{
    [$lcp, $inp, $cls] = match ($variant) {
        'przed' => [3400, 180, 21],
        'po' => [2300, 170, 12],
        'zero' => [6200, 800, 50],
        default => [2300, 170, 8],
    };
    return ['loadingExperience' => ['metrics' => [
        'LARGEST_CONTENTFUL_PAINT_MS' => ['percentile' => $lcp, 'category' => 'x'],
        'INTERACTION_TO_NEXT_PAINT' => ['percentile' => $inp, 'category' => 'x'],
        'CUMULATIVE_LAYOUT_SHIFT_SCORE' => ['percentile' => $cls, 'category' => 'x'],
    ]], 'lighthouseResult' => ['audits' => []]];
}

/** Odpowiedź modelu dla wskaźnika D2: na stronie „przed” jest 5 odpowiedzi, na pozostałych 9. */
function site_d2(string $variant): array
{
    $answered = $variant === 'przed' ? [0, 1, 3, 7, 8] : ($variant === 'zero' ? [] : [0, 1, 2, 3, 4, 5, 6, 7, 8]);
    $out = [];
    foreach (site_quotes() as $i => $quote) {
        $ok = in_array($i, $answered, true);
        $out[] = ['n' => $i + 1, 'answered' => $ok, 'quote' => $ok ? $quote : null];
    }
    return ['answers' => $out];
}

/** Strona z kodem w tytule, nagłówku i danych strukturalnych: raport ma to pokazać jako tekst. */
function site_xss(): string
{
    return '<!doctype html><html lang="pl"><head><meta charset="utf-8"><title>&lt;script&gt;alert(1)&lt;/script&gt; ekspres</title>
<meta name="description" content="&quot;&gt;&lt;img src=x onerror=alert(2)&gt;">
<script type="application/ld+json">{"@context":"https://schema.org","@type":"Article","headline":"<img src=x onerror=alert(3)>","author":{"@type":"Person","name":"<b>Zły</b> Autor"}}</script>
</head><body><main><h1>&lt;img src=x onerror=alert(4)&gt; ekspres ciśnieniowy do domu</h1>
<h2>&lt;svg onload=alert(5)&gt;</h2><p>Autor: &lt;script&gt;alert(6)&lt;/script&gt;. Ekspres ciśnieniowy do domu to temat tej strony testowej, która sprawdza wyświetlanie kodu jako tekstu.</p></main></body></html>';
}
