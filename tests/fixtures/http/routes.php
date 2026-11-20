<?php
/**
 * Odpowiedzi zewnętrznych API w testach (HTTP_FIXTURES). Klucz: wyrażenie regularne dopasowane do
 * „METODA adres” + "\n" + treść zapytania. Wartość: plik w tym katalogu albo tablica odpowiedzi.
 * Pierwsze dopasowanie wygrywa.
 */
$json = static fn (mixed $v, int $status = 200) => ['status' => $status, 'body' => json_encode($v, JSON_UNESCAPED_UNICODE)];

// Strony przykładowe do audytu treści. Wariant wybiera zmienna FIXTURE_SITE: wzor (domyślnie), po, przed, zero.
require_once __DIR__ . '/site.php';
$site = (string) App\Core\Config::get('FIXTURE_SITE', 'wzor');
$page = static fn (string $body, int $status = 200, array $headers = []) => ['status' => $status, 'body' => $body, 'headers' => $headers];
$claude = static fn (mixed $answer) => $json([
    'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'end_turn',
    'content' => [['type' => 'text', 'text' => json_encode($answer, JSON_UNESCAPED_UNICODE)]],
    'usage' => ['input_tokens' => 900, 'output_tokens' => 120],
]);
$robots = "User-agent: *\nDisallow: /koszyk\n\nUser-agent: GPTBot\nDisallow: /\n" . ($site === 'przed' ? "\nUser-agent: OAI-SearchBot\nDisallow: /\n" : '');
$article = 'sklep-przykladowy\.example/poradnik/ekspres-cisnieniowy-do-domu';

return [
    // Audyt treści: strona przykładowa, jej robots.txt, strona kontaktowa i odpowiedzi dla botów.
    '#^GET https://sklep-przykladowy\.example/robots\.txt\n#' => $page($robots),
    '#^GET http://' . $article . '\n#' => $page('', 301, ['location' => [SITE_URL]]),
    '#^GET https://' . $article . '\nUser-Agent: .*(PerplexityBot|Claude-SearchBot)#' => $site === 'przed'
        ? $page('<html><head><title>Just a moment...</title></head><body>Checking your browser</body></html>', 403, ['cf-mitigated' => ['challenge']])
        : $page(site_page($site)),
    '#^GET https://' . $article . '\n#' => $page(site_page($site)),
    '#^GET https://sklep-przykladowy\.example/kontakt#' => $page(site_contact()),
    '#^GET https://sklep-przykladowy\.example/sitemap\.xml\n#' => $page('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        . '<url><loc>https://sklep-przykladowy.example/</loc></url><url><loc>' . SITE_URL . '</loc></url><url><loc>https://sklep-przykladowy.example/ekspresy-kolbowe</loc></url>'
        . '<url><loc>https://sklep-przykladowy.example/produkt/ekspres-model-x?kolor=czarny&amp;moc=1450</loc></url><url><loc>https://sklep-przykladowy.example/img/ekspres.jpg</loc></url>'
        . '<url><loc>https://obca-witryna.example/strona</loc></url></urlset>'),
    '#^GET https://sklep-przykladowy\.example/\n#' => $page(site_page($site)),
    '#^GET https://sklep-przykladowy\.example/przekierowania/1\n#' => $page('', 301, ['location' => ['/przekierowania/2']]),
    '#^GET https://sklep-przykladowy\.example/przekierowania/2\n#' => $page('', 302, ['location' => [SITE_URL]]),
    '#^GET https://sklep-przykladowy\.example/petla\n#' => $page('', 302, ['location' => ['/petla']]),
    '#^GET https://sklep-przykladowy\.example/brak\n#' => $page('<html><body>Nie ma takiej strony</body></html>', 404),
    '#^GET https://sklep-przykladowy\.example/xss\n#' => $page(site_xss()),
    '#^GET https://sklep-przykladowy\.example/naglowek-noindex\n#' => $page(site_page('wzor'), 200, ['x-robots-tag' => ['googlebot: noindex']]),
    '#^GET https://robots-blad\.example/robots\.txt\n#' => $page('Błąd serwera', 503),
    '#^GET https://robots-blad\.example/\n#' => $page(site_page('wzor')),
    '#^GET https://bez-robots\.example/robots\.txt\n#' => $page('Nie znaleziono', 404),
    '#^GET https://bez-robots\.example/\n#' => $page(site_page('wzor')),
    '#^GET http://(robots-blad|bez-robots)\.example/#' => $page('', 301, ['location' => ['https://bez-robots.example/']]),
    // Strona, która nie spełnia żadnego wskaźnika poza tym, że odpowiada.
    '#^GET http://zly-przyklad\.example/robots\.txt\n#' => $page("User-agent: Googlebot\nDisallow: /\n"),
    '#^GET http://zly-przyklad\.example/\nUser-Agent: .*bingbot#' => $page('Forbidden', 403),
    '#^GET http://zly-przyklad\.example/\n#' => $page(site_zero()),
    // PageSpeed Insights i Cloudflare Browser Run dla stron przykładowych.
    '#^GET https://www\.googleapis\.com/pagespeedonline/v5/runPagespeed\?.*zly-przyklad#' => $json(site_psi('zero')),
    '#^GET https://www\.googleapis\.com/pagespeedonline/v5/runPagespeed\?.*sklep-przykladowy#' => $json(site_psi($site)),
    '#^POST https://api\.cloudflare\.com/client/v4/accounts/[^/]+/browser-run/snapshot\n.*zly-przyklad#s' => $json(['success' => true, 'result' => ['content' =>
        str_replace(['<html>', '<div>'], ['<html data-pd-width="980/360">', '<div><h1>Nagłówek ze skryptu</h1><p>' . site_filler(60) . '</p>'], site_zero())]]),
    '#^POST https://api\.cloudflare\.com/client/v4/accounts/[^/]+/browser-run/snapshot\n.*sklep-przykladowy#s' => $json(['success' => true, 'result' => ['content' =>
        $site === 'przed' ? site_before_rendered() : str_replace('<html lang="pl">', '<html lang="pl" data-pd-width="360/360">', site_page($site))]]),
    // Ocena AI w audycie treści: zdanie z odpowiedzią, pytania poboczne i propozycja listy pytań.
    '#^POST https://api\.anthropic\.com/v1/messages\n.*Oceniasz początek strony internetowej#s' => $claude(['answer' => $site === 'zero' ? null : SITE_ANSWER]),
    '#^POST https://api\.anthropic\.com/v1/messages\n.*Sprawdzasz, na które pytania odpowiada treść strony#s' => $claude(site_d2($site)),
    '#^POST https://api\.anthropic\.com/v1/messages\n.*Układasz pytania poboczne#s' => $claude(['questions' => site_questions()]),

    // BZP - pierwsza strona ogłoszeń o zamówieniu; reszta pusta.
    '#^GET https://ezamowienia\.gov\.pl/mo-board/api/v1/Board/Search\?NoticeType=ContractNotice&.*&PageNumber=1&#' => 'bzp_search_p1.json',
    '#^GET https://ezamowienia\.gov\.pl/mo-board/api/v1/Board/Search\?#' => $json([]),
    // Ogłoszenie poniżej progu ustawy; wariant „bez-opisu”: zamawiający pominął opis (pkt 3.3) i termin (pkt 3.6).
    '#^GET https://ezamowienia\.gov\.pl/mo-board/api/v1/Board/GetNoticeHtmlBodyById\?noticeId=male-bez-opisu#' => [
        'status' => 200,
        'body' => preg_replace(
            ['#<h3 class="mb-0">3\.3\.\).*?(?=<h3 class="mb-0">3\.4\.\))#s', '#<h3 class="mb-0">3\.6\.\).*?</h3>#s'],
            '',
            (string) file_get_contents(__DIR__ . '/bzp_notice_small.html')
        ),
    ],
    '#^GET https://ezamowienia\.gov\.pl/mo-board/api/v1/Board/GetNoticeHtmlBodyById\?noticeId=male#' => 'bzp_notice_small.html',
    '#^GET https://ezamowienia\.gov\.pl/mo-board/api/v1/Board/GetNoticeHtmlBodyById\?noticeId=#' => 'bzp_notice_543096.html',

    // TED
    '#^POST https://api\.ted\.europa\.eu/v3/notices/search#' => 'ted_search_p1.json',

    // Biała lista VAT: firma testowa (NIP 1234563218), nieprawidłowy NIP, pozostałe - brak w wykazie.
    '#^GET https://wl-api\.mf\.gov\.pl/api/search/nips/#' => 'wl_nips.json',
    '#^GET https://wl-api\.mf\.gov\.pl/api/search/nip/1234563218\?#' => 'wl_1234563218.json',
    '#^GET https://wl-api\.mf\.gov\.pl/api/search/nip/7181968222\?#' => 'wl_7181968222.json',
    '#^GET https://wl-api\.mf\.gov\.pl/api/search/nip/1234563219\?#' => $json(['code' => 'WL-115', 'message' => 'Nieprawidłowy NIP.'], 400),
    '#^GET https://wl-api\.mf\.gov\.pl/api/search/nip/\d+\?#' => $json(['result' => ['subject' => null, 'requestId' => 'test', 'requestDateTime' => date('d-m-Y H:i:s')]]),

    // KRS
    '#^GET https://api-krs\.ms\.gov\.pl/api/krs/OdpisAktualny/0000123456\?rejestr=P#' => 'krs_odpis_przyklad.json',
    '#^GET https://api-krs\.ms\.gov\.pl/api/krs/OdpisAktualny/#' => ['status' => 404, 'body' => ''],

    // UOKiK - decyzje z jednego roku, pozostałe lata puste.
    '#^GET https://decyzje\.uokik\.gov\.pl/bp/dec_prez\.nsf/UOKiK\?OpenForm&Start=1&Count=9999&Expand=11\.1&#' => 'uokik_zatory_2026.html',
    '#^GET https://decyzje\.uokik\.gov\.pl/bp/dec_prez\.nsf/UOKiK\?OpenForm&Start=1&Count=9999&Expand=11\.2&#' => 'uokik_zatory_test.html',
    '#^GET https://decyzje\.uokik\.gov\.pl/#' => ['status' => 200, 'body' => '<html><body><table></table></body></html>'],

    // Anthropic - odpowiedź zależna od rodzaju zadania (rozpoznawana po treści promptu systemowego).
    '#^POST https://api\.anthropic\.com/v1/messages\n.*Analizujesz dokumentację przetargu#s' => 'claude_analysis.json',
    '#^POST https://api\.anthropic\.com/v1/messages\n.*Odpowiadasz na pytania wykonawcy#s' => 'claude_qa.json',
    '#^POST https://api\.anthropic\.com/v1/messages\n.*Piszesz fragment#s' => 'claude_section.json',
    '#^POST https://api\.anthropic\.com/v1/messages\n.*Piszesz e-mail#s' => 'claude_email.json',
    // Czat w widżecie zapytań: odpowiedź z bazy wiedzy firmy.
    '#^POST https://api\.anthropic\.com/v1/messages\n.*Jesteś asystentem na stronie firmy#s' => $json([
        'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'end_turn',
        'content' => [['type' => 'text', 'text' => 'Tak, prowadzimy kampanie Google Ads. Wycenę przygotujemy po krótkiej rozmowie.']],
        'usage' => ['input_tokens' => 310, 'output_tokens' => 24],
    ]),
    '#^POST https://api\.anthropic\.com/v1/messages\n.*Odpowiadasz jednym słowem#s' => $json([
        'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'end_turn',
        'content' => [['type' => 'text', 'text' => 'działa']],
        'usage' => ['input_tokens' => 21, 'output_tokens' => 3],
    ]),

    // Resend: wysyłka e-maili platformy. Adres zaczynający się od „odrzucony” udaje błąd usługi.
    '#^POST https://api\.resend\.com/emails\n.*"to":\["odrzucony#s' => $json(['statusCode' => 422, 'name' => 'validation_error', 'message' => 'Domena nadawcy nie jest zweryfikowana.'], 422),
    '#^POST https://api\.resend\.com/emails#' => $json(['id' => 'test-wiadomosc']),
];
