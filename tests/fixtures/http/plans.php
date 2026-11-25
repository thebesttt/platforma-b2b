<?php
/**
 * Plany zamówień. Treść planu odwzorowuje prawdziwe ogłoszenie z BZP (plan gminy, wersja 2, pobrany przez GetNoticeQuery):
 * tabelki z cyframi roku i wersji, dwie tabele „table-positions”, wiersze kategorii z literami rozstrzelonymi twardymi spacjami.
 * Rok planu to bieżący rok, żeby dopasowanie nie odrzucało pozycji jako starych.
 * Plan „00049196” nie daje się pobrać (błąd serwera), żeby sprawdzić ponowienie. TED: jedno wstępne ogłoszenie informacyjne.
 *
 * @var callable $json
 */
require_once __DIR__ . '/plan_html.php';

$y = gmdate('Y');
$planRow = static fn (string $number, string $bzp, string $at, string $org, string $city, string $prov, string $nip): array => [
    'clientType' => '1', 'orderType' => null, 'tenderType' => null, 'noticeType' => 'TenderPlanNotice', 'noticeNumber' => $number, 'bzpNumber' => $bzp,
    'publicationDate' => $at, 'orderObject' => null, 'cpvCode' => null, 'organizationName' => $org, 'organizationCity' => $city,
    'organizationProvince' => $prov, 'organizationCountry' => 'PL', 'organizationNationalId' => $nip, 'tenderId' => null, 'htmlBody' => null,
    'contractors' => null, 'bzpTenderPlanNumber' => null, 'outdated' => false, 'objectId' => md5($number), 'pdfUrl' => null,
];

return [
    '#^GET https://ezamowienia\.gov\.pl/mo-board/api/v1/Board/Search\?NoticeType=TenderPlanNotice&.*&PageNumber=1&#' => $json([
        $planRow("$y/BZP 00037981/01/P", "$y/BZP 00037981", "$y-01-15T08:00:00.1234567Z", 'Gmina Jarczów', 'Jarczów', 'PL06', '9211980566'),
        $planRow("$y/BZP 00049196/01/P", "$y/BZP 00049196", "$y-01-20T06:49:10.1022023Z", 'Samorządowe Centrum Kultury w Strawczynie', 'Strawczyn', 'PL26', '6570311985'),
        $planRow("$y/BZP 00037981/02/P", "$y/BZP 00037981", "$y-01-20T06:12:42.2804801Z", 'Gmina Jarczów', 'Jarczów', 'PL06', '9211980566'),
    ]),
    '#^GET https://ezamowienia\.gov\.pl/mo-board/api/v1/Board/GetNoticeQuery\?noticeNumber=\d{4}%2FBZP%2000037981%2F02%2FP\n#' => $json(['html' => plan_fixture_html()]),
    '#^GET https://ezamowienia\.gov\.pl/mo-board/api/v1/Board/GetNoticeQuery\?noticeNumber=\d{4}%2FBZP%2000037981%2F01%2FP\n#' => $json(['html' => plan_fixture_html(null, 1)]),
    '#^GET https://ezamowienia\.gov\.pl/mo-board/api/v1/Board/GetNoticeQuery\?#' => ['status' => 500, 'body' => 'Internal Server Error'],
    // TED: wstępne ogłoszenie informacyjne (PIN) z polami części i datą planowanego ogłoszenia.
    '#^POST https://api\.ted\.europa\.eu/v3/notices/search\n.*pin-only#s' => $json(['totalNoticeCount' => 1, 'notices' => [[
        'publication-number' => '701234-' . $y,
        'notice-type' => 'pin-only',
        'publication-date' => "$y-10-02+02:00",
        'notice-title' => ['pol' => "Polska \u{2013} Usługi w zakresie oprogramowania \u{2013} System obsługi mieszkańców i elektroniczny obieg dokumentów"],
        'buyer-name' => ['pol' => ['Miasto Stołeczne Warszawa']],
        'organisation-identifier-buyer' => ['5252248481'],
        'organisation-city-buyer' => ['Warszawa'],
        'classification-cpv' => ['72212000', '48000000'],
        'contract-nature-main-proc' => ['services'],
        'title-part' => ['pol' => ['System obsługi mieszkańców']],
        'description-part' => ['pol' => ['Wdrożenie systemu obsługi spraw mieszkańców z integracją z ePUAP i utrzymaniem przez 36 miesięcy.']],
        'place-of-performance-subdiv-part' => ['PL911'],
        'estimated-value-proc' => ['4800000'],
        'estimated-value-cur-proc' => ['PLN'],
        'future-notice' => [((int) $y + 1) . '-02-15+01:00'],
    ]]]),
];
