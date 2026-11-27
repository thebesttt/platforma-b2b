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

    // Plany zamówień z BZP i zapowiedzi z TED (przed ogólnymi odpowiedziami BZP i TED niżej).
    ...require __DIR__ . '/plans.php',
    // Lista projektów z funduszy UE 2021-2027 (strona i plik CSV).
    ...require __DIR__ . '/eu.php',

    // BZP - pierwsza strona ogłoszeń o zamówieniu; reszta pusta.
    '#^GET https://ezamowienia\.gov\.pl/mo-board/api/v1/Board/Search\?NoticeType=ContractNotice&.*&PageNumber=1&#' => 'bzp_search_p1.json',
    // Ogłoszenia o wyniku: prawdziwe ogłoszenia z 16.11.2026 (jedna część, dwie części, konsorcjum, część nierozstrzygnięta,
    // zamówienie z wolnej ręki, ogłoszenie tylko o unieważnionej części 4). Treść każdego pobiera się osobno.
    '#^GET https://ezamowienia\.gov\.pl/mo-board/api/v1/Board/Search\?NoticeType=TenderResultNotice&.*&PageNumber=1&#' => 'bzp_results_p1.json',
    '#^GET https://ezamowienia\.gov\.pl/mo-board/api/v1/Board/Search\?#' => $json([]),
    // Ogłoszenie poniżej progu ustawy; wariant „bez-opisu”: zamawiający pominął opis (pkt 3.3) i termin (pkt 3.6).
    '#^GET https://ezamowienia\.gov\.pl/mo-board/api/v1/Board/GetNoticeHtmlBodyById\?noticeId=08df43d2-7d1d-f757#' => 'bzp_result_jedna.html',
    '#^GET https://ezamowienia\.gov\.pl/mo-board/api/v1/Board/GetNoticeHtmlBodyById\?noticeId=08df43d4-41e2-3c9f#' => 'bzp_result_dwie.html',
    '#^GET https://ezamowienia\.gov\.pl/mo-board/api/v1/Board/GetNoticeHtmlBodyById\?noticeId=08df43d4-c7eb-fd8e#' => 'bzp_result_konsorcjum.html',
    '#^GET https://ezamowienia\.gov\.pl/mo-board/api/v1/Board/GetNoticeHtmlBodyById\?noticeId=08df43d5-1bbf-0759#' => 'bzp_result_trzy.html',
    '#^GET https://ezamowienia\.gov\.pl/mo-board/api/v1/Board/GetNoticeHtmlBodyById\?noticeId=08df43d4-4409-fa94#' => 'bzp_result_wolna_reka.html',
    '#^GET https://ezamowienia\.gov\.pl/mo-board/api/v1/Board/GetNoticeHtmlBodyById\?noticeId=08df43d7-b031-2d40#' => 'bzp_result_czesc4.html',
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
    // Biała lista: sprawdzenie rachunku (metoda „check”), odpowiedzi w formacie z prawdziwego API (27.11.2026).
    '#^GET https://wl-api\.mf\.gov\.pl/api/check/nip/\d+/bank-account/\d+\?date=2020-01-02\n#' => $json(['message' => 'Data sprzed zakresu rejestru.', 'code' => 'WL-118'], 400),
    '#^GET https://wl-api\.mf\.gov\.pl/api/check/nip/1234563218/bank-account/61109010140000071219812874\?date=\d{4}-\d\d-\d\d\n#' => $json(['result' => ['accountAssigned' => 'TAK', 'requestDateTime' => '27-11-2026 21:43:58', 'requestId' => 'KPYYH-99ch9dm']]),
    '#^GET https://wl-api\.mf\.gov\.pl/api/check/nip/\d+/bank-account/89114020040000350212345678\?#' => ['status' => 429, 'body' => ''],
    '#^GET https://wl-api\.mf\.gov\.pl/api/check/nip/\d+/bank-account/\d+\?date=#' => $json(['result' => ['accountAssigned' => 'NIE', 'requestDateTime' => '27-11-2026 21:44:10', 'requestId' => 'byswY-99ch9ea']]),
    // Status na dzień z przeszłości: wtedy firma była zwolniona z VAT.
    '#^GET https://wl-api\.mf\.gov\.pl/api/search/nip/1234563218\?date=2025-03-10\n#' => $json(['result' => ['subject' => ['name' => 'PRZYKŁADOWA SPÓŁKA BUDOWLANA SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', 'nip' => '1234563218', 'statusVat' => 'Zwolniony', 'regon' => '123456785', 'krs' => '0000123456', 'registrationLegalDate' => '2024-03-01', 'accountNumbers' => ['61109010140000071219812874'], 'hasVirtualAccounts' => false], 'requestDateTime' => '27-11-2026 21:50:00', 'requestId' => 'Hist1-99ch9fa']]),
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
    // Strona firmy do opisu idealnego klienta: strona główna z linkami do oferty, kontaktu i bloga oraz podstrona oferty.
    '#^GET https://firma-uslugowa\.example/\n#' => $page('<!doctype html><html lang="pl"><head><title>Biuro rachunkowe dla sklepów internetowych</title>'
        . '<meta name="description" content="Księgowość dla sklepów internetowych i firm usługowych."></head><body><nav><a href="/oferta">Oferta</a> <a href="/kontakt">Kontakt</a> <a href="/blog">Blog</a>'
        . ' <a href="https://inna-firma.example/oferta">Partner</a></nav><main><h1>Księgowość dla sklepów internetowych</h1>'
        . '<p>Prowadzimy księgi sklepów internetowych, które sprzedają na Allegro i we własnym sklepie. Rozliczamy sprzedaż wysyłkową do krajów Unii Europejskiej.</p>'
        . '<p>Nie obsługujemy spółek giełdowych ani firm zatrudniających ponad 50 osób.</p></main></body></html>'),
    '#^GET https://firma-uslugowa\.example/oferta\n#' => $page('<!doctype html><html lang="pl"><head><title>Oferta</title></head><body><main><h1>Oferta</h1>'
        . '<p>Pakiet dla firm usługowych na ryczałcie obejmuje ewidencję przychodów i rozliczenie roczne. Abonament zaczyna się od 300 zł miesięcznie.</p></main></body></html>'),
    // Treść oferty dla potencjalnego klienta pisana przez AI.
    '#^POST https://api\.anthropic\.com/v1/messages\n.*Piszesz treść oferty dla potencjalnego klienta#s' => $claude([
        'pitch' => "Państwa firma zawarła niedawno umowę na remont drogi, więc w najbliższych miesiącach będzie potrzebować kruszywa.\n\nDostarczamy kruszywa na budowy dróg. Termin i cenę podamy po rozmowie: [CENA], [TERMIN].",
    ]),
    // Strona potencjalnego klienta: strona główna bez opisu, podstrona kontaktowa i podstrona z zaproszeniem do ofert.
    '#^GET https://firma-zaprasza\.example/\n#' => $page('<!doctype html><html lang="pl"><head><title>Hurtownia materiałów budowlanych</title>'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"></head><body><nav><a href="/oferta">Oferta</a> <a href="/kontakt">Kontakt</a> <a href="/dla-dostawcow">Dla dostawców</a>'
        . ' <a href="https://obca-witryna.example/oferty">Oferty partnera</a></nav><main><h1>Hurtownia materiałów budowlanych</h1><p>Sprzedajemy cement, kruszywa i kostkę brukową.</p></main>'
        . '<footer><p>Zapraszamy do kontaktu: biuro@firma-zaprasza.example</p></footer></body></html>'),
    '#^GET https://firma-zaprasza\.example/kontakt\n#' => $page('<!doctype html><html lang="pl"><head><title>Kontakt</title></head><body><main><h1>Kontakt</h1>'
        . '<p>Biuro: biuro@firma-zaprasza.example, tel. 22 000 00 00.</p></main></body></html>'),
    '#^GET https://firma-zaprasza\.example/dla-dostawcow\n#' => $page('<!doctype html><html lang="pl"><head><title>Dla dostawców</title></head><body><main><h1>Dla dostawców</h1>'
        . '<p>Szukamy stałych dostawców.</p><p>Oferty handlowe prosimy kierować na adres: Oferty@Firma-Zaprasza.example. Odpowiadamy na wybrane propozycje.</p>'
        . '<script>var x = "oferty handlowe prosimy kierować na adres zly@skrypt.example";</script></main></body></html>'),
    '#^GET https?://strona-bez-odpowiedzi\.example/#' => ['status' => 0, 'body' => '', 'error' => 'Serwer nie odpowiada'],
    '#^POST https://api\.anthropic\.com/v1/messages\n.*Opisujesz idealnego klienta firmy#s' => $claude([
        'summary' => 'Sklepy internetowe i małe firmy usługowe, które sprzedają także za granicę i nie chcą same pilnować rozliczeń VAT.',
        'segments' => [
            ['name' => 'Sklepy internetowe', 'why' => 'Strona wprost mówi o sprzedaży na Allegro i we własnym sklepie.', 'quote' => 'Prowadzimy księgi sklepów internetowych, które sprzedają na Allegro i we własnym sklepie.'],
            ['name' => 'Firmy usługowe na ryczałcie', 'why' => 'Jest dla nich osobny pakiet.', 'quote' => 'Pakiet dla firm usługowych na ryczałcie obejmuje ewidencję przychodów i rozliczenie roczne.'],
            ['name' => 'Kancelarie prawne', 'why' => 'Często szukają księgowości.', 'quote' => 'Obsługujemy kancelarie prawne w całej Polsce.'],
        ],
        'signals' => ['Sprzedaje przez internet', 'Wysyła towar do krajów Unii Europejskiej'],
        'not_for' => ['Spółki giełdowe', 'Firmy zatrudniające ponad 50 osób'],
    ]),
    // Warunki naboru: jedna pozycja z prawdziwym cytatem i potwierdzeniem w danych firmy, jedna z prawdziwym cytatem, ale ze zmyślonym
    // potwierdzeniem, jedna sprzeczna z danymi firmy, jedna bez danych i jedna ze zmyślonym cytatem z regulaminu.
    '#^POST https://api\.anthropic\.com/v1/messages\n.*Sprawdzasz, czy firma spełnia warunki naboru#s' => $claude(['conditions' => [
        ['condition' => 'Siedziba w województwie mazowieckim', 'quote' => 'Wnioskodawca prowadzi działalność na terenie województwa mazowieckiego.', 'doc' => 'regulamin.txt',
            'status' => 'met', 'evidence' => 'ul. Testowa 1, 00-001 Warszawa', 'note' => 'Adres firmy jest w Warszawie.'],
        ['condition' => 'Status MŚP', 'quote' => 'O dofinansowanie mogą ubiegać się wyłącznie mikro, mali i średni przedsiębiorcy.', 'doc' => 'regulamin.txt',
            'status' => 'met', 'evidence' => 'Firma zatrudnia 12 osób', 'note' => 'Firma jest małym przedsiębiorcą.'],
        ['condition' => 'Okres działalności', 'quote' => 'Wnioskodawca prowadzi działalność od co najmniej 24 miesięcy – licząc do dnia złożenia wniosku.', 'doc' => 'nieznany.pdf',
            'status' => 'not_met', 'evidence' => 'Firma działa od marca 2026 r.', 'note' => 'Od marca 2026 r. nie minęły 24 miesiące.'],
        ['condition' => 'Wkład własny', 'quote' => 'Minimalny wkład własny wynosi 15% kosztów kwalifikowalnych.', 'doc' => 'regulamin.txt',
            'status' => 'unknown', 'evidence' => null, 'note' => 'Sprawdź, czy firma ma środki na 15% kosztów projektu.'],
        ['condition' => 'Certyfikat ISO 9001', 'quote' => 'Wnioskodawca posiada certyfikat ISO 9001 wydany przed dniem ogłoszenia naboru.', 'doc' => 'regulamin.txt',
            'status' => 'not_met', 'evidence' => null, 'note' => 'Brak certyfikatu.'],
    ]]),
    '#^POST https://api\.anthropic\.com/v1/messages\n.*Piszesz fragment#s' => 'claude_section.json',
    '#^POST https://api\.anthropic\.com/v1/messages\n.*Piszesz e-mail#s' => 'claude_email.json',
    // Kampania: wersja wiadomości dopasowana do odbiorcy (z długim myślnikiem, który panel zamienia na zwykły łącznik).
    '#^POST https://api\.anthropic\.com/v1/messages\n.*Dopasowujesz wiadomość kampanii#s' => $claude([
        'subject' => "Strona Państwa firmy \u{2014} propozycja",
        'body' => "Dzień dobry,\n\nwidzimy, że realizują Państwo projekt z funduszy europejskich \u{2014} gratulujemy.\n\nProponujemy przegląd strony w 2 tygodnie.\n\nZ poważaniem\nJan Test",
    ]),
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

    // CEIDG, hurtownia danych (API v3): odpowiedzi w formacie z dokumentacji dla integratorów. Znaczniki w zapytaniu udają błędy usługi.
    '#^GET https://dane\.biznes\.gov\.pl/api/ceidg/v3/firmy\?.*miasto=Odmowa#' => $json(['message' => 'Unauthorized', 'http_status_code' => 401], 401),
    '#^GET https://dane\.biznes\.gov\.pl/api/ceidg/v3/firmy\?.*miasto=Limit#' => $json(['message' => 'Too Many Requests'], 429),
    '#^GET https://dane\.biznes\.gov\.pl/api/ceidg/v3/firmy\?.*miasto=Awaria#' => $json(['message' => 'Internal Server Error'], 500),
    '#^GET https://dane\.biznes\.gov\.pl/api/ceidg/v3/firmy\?.*pkd=9999Z#' => ['status' => 204, 'body' => ''],
    // Województwo wielkimi literami nie daje wyniku, małymi daje: sprawdza drugie podejście z innym zapisem.
    '#^GET https://dane\.biznes\.gov\.pl/api/ceidg/v3/firmy\?.*wojewodztwo=LUBELSKIE#' => ['status' => 204, 'body' => ''],
    '#^GET https://dane\.biznes\.gov\.pl/api/ceidg/v3/firmy\?#' => $json([
        'firmy' => [
            ['id' => 'a1b2c3d4-0000-4000-8000-000000000001', 'nazwa' => 'Usługi Budowlane Jan Kowalski',
                'adresDzialalnosci' => ['ulica' => 'ul. Polna', 'budynek' => '3', 'lokal' => '5', 'miasto' => 'Piaseczno', 'wojewodztwo' => 'MAZOWIECKIE', 'powiat' => 'piaseczyński',
                    'gmina' => 'Piaseczno', 'kraj' => 'PL', 'kod' => '05-500', 'terc' => '1418043', 'simc' => '0920806'],
                'wlasciciel' => ['imie' => 'Jan', 'nazwisko' => 'Kowalski', 'nip' => '8410000015', 'regon' => '140000001'],
                'dataRozpoczecia' => date('Y-m-d', time() - 20 * 86400), 'status' => 'AKTYWNY', 'link' => 'https://dane.biznes.gov.pl/api/ceidg/v3/firma/a1b2c3d4-0000-4000-8000-000000000001'],
            ['id' => 'a1b2c3d4-0000-4000-8000-000000000002', 'nazwa' => 'Sklep <b>Ogrodnik</b> Ewa Zielona',
                'adresDzialalnosci' => ['ulica' => '', 'budynek' => '12', 'lokal' => '', 'miasto' => 'Wólka', 'wojewodztwo' => 'LUBELSKIE', 'powiat' => 'lubartowski', 'gmina' => 'Lubartów', 'kraj' => 'PL', 'kod' => '21100'],
                'wlasciciel' => ['imie' => 'Ewa', 'nazwisko' => 'Zielona', 'nip' => '8410000021', 'regon' => '140000002'],
                'dataRozpoczecia' => '2015-04-01', 'status' => 'AKTYWNY', 'link' => 'https://dane.biznes.gov.pl/api/ceidg/v3/firma/a1b2c3d4-0000-4000-8000-000000000002'],
            // Wpis, który jest już firmą testową z wykazu VAT (ten sam NIP co w innych testach).
            ['id' => 'a1b2c3d4-0000-4000-8000-000000000003', 'nazwa' => 'Przykładowa Budowlana Adam Nowak',
                'adresDzialalnosci' => ['ulica' => 'ul. Przykładowa', 'budynek' => '1', 'miasto' => 'Warszawa', 'wojewodztwo' => 'MAZOWIECKIE', 'kod' => '00-001'],
                'wlasciciel' => ['imie' => 'Adam', 'nazwisko' => 'Nowak', 'nip' => '1234563218', 'regon' => '140000003'],
                'dataRozpoczecia' => '2012-01-02', 'status' => 'AKTYWNY', 'link' => ''],
            // Wpisy do pominięcia: zawieszona działalność i błędny NIP.
            ['id' => 'a1b2c3d4-0000-4000-8000-000000000004', 'nazwa' => 'Zawieszona Firma', 'adresDzialalnosci' => ['miasto' => 'Radom', 'kod' => '26-600'],
                'wlasciciel' => ['imie' => 'Piotr', 'nazwisko' => 'Cichy', 'nip' => '8410000038'], 'dataRozpoczecia' => '2010-01-01', 'status' => 'ZAWIESZONY', 'link' => ''],
            ['id' => 'a1b2c3d4-0000-4000-8000-000000000005', 'nazwa' => 'Błędny NIP', 'adresDzialalnosci' => ['miasto' => 'Radom'],
                'wlasciciel' => ['imie' => 'Anna', 'nazwisko' => 'Błędna', 'nip' => '1234567890'], 'dataRozpoczecia' => '2010-01-01', 'status' => 'AKTYWNY', 'link' => ''],
        ],
        'count' => 31,
        'links' => ['first' => '', 'prev' => '', 'self' => '', 'next' => '', 'last' => ''],
        'properties' => ['dc:title' => 'Lista firm', 'schema:provider' => 'CEIDG'],
    ]),

    // Widoczność: Google, Cloudflare i mapy witryn konkurentów.
    ...require __DIR__ . '/seo.php',
    ...require __DIR__ . '/grants.php',

    // Resend: wysyłka e-maili platformy. Adres zaczynający się od „odrzucony” udaje błąd usługi.
    '#^POST https://api\.resend\.com/emails\n.*"to":\["odrzucony#s' => $json(['statusCode' => 422, 'name' => 'validation_error', 'message' => 'Domena nadawcy nie jest zweryfikowana.'], 422),
    '#^POST https://api\.resend\.com/emails#' => $json(['id' => 'test-wiadomosc']),
];
