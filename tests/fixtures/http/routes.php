<?php
/**
 * Odpowiedzi zewnętrznych API w testach (HTTP_FIXTURES). Klucz: wyrażenie regularne dopasowane do
 * „METODA adres” + "\n" + treść zapytania. Wartość: plik w tym katalogu albo tablica odpowiedzi.
 * Pierwsze dopasowanie wygrywa.
 */
$json = static fn (mixed $v, int $status = 200) => ['status' => $status, 'body' => json_encode($v, JSON_UNESCAPED_UNICODE)];

return [
    // BZP – pierwsza strona ogłoszeń o zamówieniu; reszta pusta.
    '#^GET https://ezamowienia\.gov\.pl/mo-board/api/v1/Board/Search\?NoticeType=ContractNotice&.*&PageNumber=1&#' => 'bzp_search_p1.json',
    '#^GET https://ezamowienia\.gov\.pl/mo-board/api/v1/Board/Search\?#' => $json([]),
    '#^GET https://ezamowienia\.gov\.pl/mo-board/api/v1/Board/GetNoticeHtmlBodyById\?noticeId=#' => 'bzp_notice_543096.html',

    // TED
    '#^POST https://api\.ted\.europa\.eu/v3/notices/search#' => 'ted_search_p1.json',

    // Biała lista VAT: firma testowa (NIP 1234563218), nieprawidłowy NIP, pozostałe – brak w wykazie.
    '#^GET https://wl-api\.mf\.gov\.pl/api/search/nip/1234563218\?#' => 'wl_1234563218.json',
    '#^GET https://wl-api\.mf\.gov\.pl/api/search/nip/7181968222\?#' => 'wl_7181968222.json',
    '#^GET https://wl-api\.mf\.gov\.pl/api/search/nip/0000000000\?#' => $json(['code' => 'WL-115', 'message' => 'Nieprawidłowy NIP.'], 400),
    '#^GET https://wl-api\.mf\.gov\.pl/api/search/nip/\d+\?#' => $json(['result' => ['subject' => null, 'requestId' => 'test', 'requestDateTime' => date('d-m-Y H:i:s')]]),

    // KRS
    '#^GET https://api-krs\.ms\.gov\.pl/api/krs/OdpisAktualny/0000123456\?rejestr=P#' => 'krs_odpis_przyklad.json',
    '#^GET https://api-krs\.ms\.gov\.pl/api/krs/OdpisAktualny/#' => ['status' => 404, 'body' => ''],

    // UOKiK – decyzje z jednego roku, pozostałe lata puste.
    '#^GET https://decyzje\.uokik\.gov\.pl/bp/dec_prez\.nsf/UOKiK\?OpenForm&Start=1&Count=9999&Expand=11\.1&#' => 'uokik_zatory_2026.html',
    '#^GET https://decyzje\.uokik\.gov\.pl/bp/dec_prez\.nsf/UOKiK\?OpenForm&Start=1&Count=9999&Expand=11\.2&#' => 'uokik_zatory_test.html',
    '#^GET https://decyzje\.uokik\.gov\.pl/#' => ['status' => 200, 'body' => '<html><body><table></table></body></html>'],

    // Anthropic – odpowiedź zależna od rodzaju zadania (rozpoznawana po treści promptu systemowego).
    '#^POST https://api\.anthropic\.com/v1/messages\n.*Analizujesz dokumentację przetargu#s' => 'claude_analysis.json',
    '#^POST https://api\.anthropic\.com/v1/messages\n.*Odpowiadasz na pytania wykonawcy#s' => 'claude_qa.json',
    '#^POST https://api\.anthropic\.com/v1/messages\n.*Piszesz fragment#s' => 'claude_section.json',
    '#^POST https://api\.anthropic\.com/v1/messages\n.*Piszesz e-mail#s' => 'claude_email.json',
];
