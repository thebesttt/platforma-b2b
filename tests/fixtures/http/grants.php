<?php
/**
 * Wyszukiwarka naborów: odpowiedzi Messages API z narzędziem web search w formacie z dokumentacji Anthropic
 * (server_tool_use, web_search_tool_result, tekst z cytowaniami, usage.server_tool_use.web_search_requests).
 * Zmienna FIXTURE_GRANTS przełącza warianty: „wylaczona” (wyszukiwarka wyłączona na koncie), „bez-listy”.
 * Opis ze słowem WSTRZYMAJ daje najpierw odpowiedź pause_turn, a dopiero po odesłaniu jej dalszy ciąg.
 *
 * @var callable $json
 */
$variant = (string) App\Core\Config::get('FIXTURE_GRANTS', '');
$in = static fn (int $days): string => gmdate('Y-m-d', time() + $days * 86400);
$results = [
    ['type' => 'web_search_result', 'url' => 'https://www.parp.gov.pl/component/grants/grants/granty-na-eurogranty', 'title' => 'Granty na Eurogranty - PARP', 'encrypted_content' => 'EqgfCioIARgB', 'page_age' => '23 października 2026'],
    ['type' => 'web_search_result', 'url' => 'https://funduszeuedlamazowsza.eu/nabory/cyfryzacja-msp/', 'title' => 'Cyfryzacja MŚP - Fundusze Europejskie dla Mazowsza', 'encrypted_content' => 'EqgfCioIARgC', 'page_age' => null],
    ['type' => 'web_search_result', 'url' => 'https://www.gov.pl/web/ncbr/sciezka-smart', 'title' => 'Ścieżka SMART - NCBR', 'encrypted_content' => 'EqgfCioIARgD', 'page_age' => '2 listopada 2026'],
    ['type' => 'web_search_result', 'url' => 'https://portal-doradczy.example/zakonczony-nabor', 'title' => 'Bon na cyfryzację - nabór', 'encrypted_content' => 'EqgfCioIARgE', 'page_age' => '1 marca 2026'],
];
$calls = ['calls' => [
    ['name' => 'Granty na Eurogranty', 'institution' => 'Polska Agencja Rozwoju Przedsiębiorczości', 'url' => 'https://www.parp.gov.pl/component/grants/grants/granty-na-eurogranty',
        'opens_at' => $in(-5), 'closes_at' => $in(40), 'amount' => 'do 280 060 zł', 'audience' => 'mikro, małe i średnie firmy', 'fit' => 'Pasuje, jeśli firma planuje projekt w programie zarządzanym przez Komisję Europejską.'],
    // Adres zapisany inaczej niż w wynikach (bez „www”, bez ukośnika): to ta sama strona.
    ['name' => 'Cyfryzacja MŚP <script>alert(1)</script>', 'institution' => null, 'url' => 'https://www.funduszeuedlamazowsza.eu/nabory/cyfryzacja-msp',
        'opens_at' => null, 'closes_at' => null, 'amount' => null, 'audience' => 'MŚP z województwa mazowieckiego', 'fit' => 'Termin nie jest podany na stronie, trzeba go sprawdzić.'],
    ['name' => 'Ścieżka SMART', 'institution' => 'NCBR', 'url' => 'https://www.gov.pl/web/ncbr/sciezka-smart', 'opens_at' => $in(10), 'closes_at' => '31 grudnia', 'amount' => 'null', 'audience' => null, 'fit' => null],
    // Nabór zakończony: nie trafia na listę.
    ['name' => 'Bon na cyfryzację', 'institution' => 'PARP', 'url' => 'https://portal-doradczy.example/zakonczony-nabor', 'opens_at' => null, 'closes_at' => $in(-1), 'amount' => null, 'audience' => null, 'fit' => null],
    // Adres, którego nie było w wynikach wyszukiwarki: model go wymyślił, więc odpada.
    ['name' => 'Dotacja wymyślona', 'institution' => null, 'url' => 'https://www.gov.pl/web/nie-ma-takiej-strony', 'opens_at' => null, 'closes_at' => $in(30), 'amount' => null, 'audience' => null, 'fit' => null],
    ['name' => '', 'url' => 'https://www.parp.gov.pl/component/grants/grants/granty-na-eurogranty'],
    ['name' => 'Zły adres', 'url' => 'javascript:alert(1)'],
]];
$toolUse = ['type' => 'server_tool_use', 'id' => 'srvtoolu_01Test', 'name' => 'web_search', 'input' => ['query' => 'nabory dofinansowanie cyfryzacja MŚP 2026']];
$toolResult = ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_01Test', 'content' => $results];
$answer = static fn (array $payload): array => [
    ['type' => 'text', 'text' => 'Sprawdzę aktualne nabory.'],
    $toolUse,
    $toolResult,
    // Wyszukiwanie, które przekroczyło limit: w treści jest obiekt błędu zamiast listy wyników.
    ['type' => 'server_tool_use', 'id' => 'srvtoolu_02Test', 'name' => 'web_search', 'input' => ['query' => 'nabory PARP grudzień 2026']],
    ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_02Test', 'content' => ['type' => 'web_search_tool_result_error', 'error_code' => 'max_uses_exceeded']],
    ['type' => 'text', 'text' => 'Nabór trwa do końca wskazanego okresu ' . "\u{2013}" . ' wnioski składa się przez generator.', 'citations' => [[
        'type' => 'web_search_result_location', 'url' => 'https://www.parp.gov.pl/component/grants/grants/granty-na-eurogranty', 'title' => 'Granty na Eurogranty - PARP',
        'encrypted_index' => 'Eo8BCioIAhgB', 'cited_text' => 'Koniec przyjmowania wniosków ' . "\u{2013}" . ' 31 marca 2027. O grant mogą ubiegać się MŚP.']]],
    ['type' => 'text', 'text' => "\n```json\n" . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n```"],
];
$message = static fn (array $content, string $stop = 'end_turn', int $searches = 2) => $json([
    'id' => 'msg_search_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'content' => $content, 'stop_reason' => $stop,
    'usage' => ['input_tokens' => 6039, 'output_tokens' => 931, 'server_tool_use' => ['web_search_requests' => $searches]],
]);
$base = '#^POST https://api\.anthropic\.com/v1/messages\n.*Szukasz naborów wniosków o dofinansowanie';

return [
    $base . '.*"role":"assistant"#s' => $message($answer($calls), 'end_turn', 1),
    $base . '.*WSTRZYMAJ#s' => $message([['type' => 'text', 'text' => 'Szukam dalej.'], $toolUse, $toolResult], 'pause_turn', 1),
    $base . '#s' => match ($variant) {
        'wylaczona' => $json(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'Web search is not enabled for this organization.']], 400),
        'bez-listy' => $message([['type' => 'text', 'text' => 'Nie udało mi się niczego ustalić.']], 'end_turn', 0),
        default => $message($answer($calls)),
    },
];
