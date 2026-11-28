<?php
/**
 * Odpowiedzi usług używanych w części „Widoczność”: Google (token konta usługi, Search Console API, Analytics Data i Admin API),
 * Cloudflare GraphQL Analytics API oraz mapy witryn konkurentów. Formaty według dokumentacji tych usług.
 * Zmienna FIXTURE_SEO przełącza warianty: „bez-dostepu”, „api-wylaczone”, „ga4-wiele”, „cf-odmowa”, „mapa-2”, „oauth-cofniety”.
 *
 * @var callable $json
 * @var callable $page
 */
$variant = (string) App\Core\Config::get('FIXTURE_SEO', '');
$b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

// Okna dat liczone tak samo jak w kodzie: Search Console kończy 3 dni temu, Analytics wczoraj.
$gscEnd = gmdate('Y-m-d', time() - 3 * 86400);
$gscDay = static fn (int $back): string => gmdate('Y-m-d', strtotime($gscEnd . ' UTC') - $back * 86400);
$curStart = $gscDay(27);
$prevStart = $gscDay(55);
$gaEnd = gmdate('Y-m-d', time() - 86400);
$gaCurStart = gmdate('Y-m-d', strtotime($gaEnd . ' UTC') - 27 * 86400);
$gaPrevStart = gmdate('Y-m-d', strtotime($gaEnd . ' UTC') - 55 * 86400);
$gsc = 'https://www\.googleapis\.com/webmasters/v3/sites/sc-domain%3Asklep-przykladowy\.example/searchAnalytics/query';
$row = static fn (array $keys, int $clicks, int $impr, float $pos): array => ['keys' => $keys, 'clicks' => $clicks, 'impressions' => $impr, 'ctr' => $impr ? $clicks / $impr : 0, 'position' => $pos];
$daily = [];
for ($i = 179; $i >= 0; $i--) {
    // Powolny wzrost: z 12 do 21 kliknięć dziennie. Ostatnie 28 dni dają razem 578 kliknięć i 13 034 wyświetlenia, a 28 dni wcześniej 538 i 12 250.
    $daily[] = $row([$gscDay($i)], 12 + intdiv(179 - $i, 18), 300 + (179 - $i), 5.8);
}
$ga = static fn (array $rows): array => [
    'dimensionHeaders' => [], 'metricHeaders' => [['name' => 'sessions', 'type' => 'TYPE_INTEGER']],
    'rows' => array_map(static fn (array $r): array => ['dimensionValues' => array_map(static fn (string $v): array => ['value' => $v], $r[0]), 'metricValues' => [['value' => (string) $r[1]]]], $rows),
    'rowCount' => count($rows), 'kind' => 'analyticsData#runReport',
];
$cf = static fn (array $groups) => $json(['data' => ['viewer' => ['zones' => [['httpRequestsAdaptiveGroups' => $groups]]]], 'errors' => null]);
$hour = static fn (int $daysBack, int $h = 10): string => gmdate('Y-m-d', time() - $daysBack * 86400) . 'T' . sprintf('%02d', $h) . ':00:00Z';
$bot = static fn (int $n, int $daysBack, string $ua, int $status): array => ['count' => $n, 'dimensions' => ['datetimeHour' => $hour($daysBack), 'userAgent' => $ua, 'edgeResponseStatus' => $status]];
$sitemap = static fn (array $urls): string => '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
    . implode('', array_map(static fn (array $u): string => '<url><loc>' . htmlspecialchars($u[0], ENT_XML1) . '</loc>' . (isset($u[1]) ? '<lastmod>' . $u[1] . '</lastmod>' : '') . '</url>', $urls)) . '</urlset>';
$uaChat = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot';
$uaPplx = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)';
$uaSearch = 'Mozilla/5.0 (compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot)';
// Wiersze z jednego dnia (ile dni temu). ChatGPT-User przychodzi codziennie i coraz częściej, reszta w wybrane dni.
$cfDays = static function (int $d) use ($bot, $uaChat, $uaPplx, $uaSearch): array {
    $rows = [$bot(8 - intdiv($d, 5), $d, $uaChat, 200)];
    $extra = [
        2 => [$bot(4, 2, 'Mozilla/5.0 (compatible; Claude-User/1.0; +Claude-User@anthropic.com)', 200), $bot(999, 2, 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 200)],
        3 => [$bot(60, 3, $uaPplx, 403), $bot(2, 3, $uaPplx, 200)],
        4 => [$bot(60, 4, 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)', 403)],
        5 => [$bot(7, 5, $uaSearch, 404)],
        6 => [$bot(21, 6, $uaSearch, 200)],
        9 => [$bot(3, 9, 'Mozilla/5.0 (compatible; Claude-User/1.0; +Claude-User@anthropic.com)', 200)],
        10 => [$bot(58, 10, $uaPplx, 403)],
        12 => [$bot(35, 12, 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)', 200)],
        13 => [$bot(18, 13, $uaSearch, 200)],
        16 => [$bot(2, 16, 'Mozilla/5.0 (compatible; Claude-User/1.0; +Claude-User@anthropic.com)', 200)],
    ];
    return array_merge($rows, $extra[$d] ?? []);
};
$recent = gmdate('Y-m-d', time() - 5 * 86400);
// GA4 dzień po dniu (alarm pomiaru): 70 dni do wczoraj. Wariant „pomiar-zdarzenia”: od 6 dni zdarzeń kluczowych 0 przy zwykłym
// ruchu. Wariant „pomiar-ruch”: od 6 dni prawie nie ma sesji (tag zniknął ze strony).
$gaDaily = static function (string $mode): array {
    $rows = [];
    for ($i = 70; $i >= 1; $i--) {
        $ts = time() - $i * 86400;
        $weekend = (int) gmdate('N', $ts) >= 6;
        $s = $weekend ? 60 : 110 + ($i % 5) * 3;
        $e = $weekend ? 2 : 4 + ($i % 3);
        if ($mode === 'pomiar-zdarzenia' && $i <= 6) {
            $e = 0;
        }
        if ($mode === 'pomiar-ruch' && $i <= 6) {
            [$s, $e] = [3, 0];
        }
        $rows[] = ['dimensionValues' => [['value' => gmdate('Ymd', $ts)]], 'metricValues' => [['value' => (string) $s], ['value' => (string) $e]]];
    }
    return ['dimensionHeaders' => [['name' => 'date']], 'metricHeaders' => [['name' => 'sessions', 'type' => 'TYPE_INTEGER'], ['name' => 'keyEvents', 'type' => 'TYPE_INTEGER']],
        'rows' => $rows, 'rowCount' => count($rows), 'kind' => 'analyticsData#runReport'];
};

return [
    // Token konta usługi. Konto o adresie „odrzucony@…” udaje usunięty klucz.
    '#^POST https://oauth2\.googleapis\.com/token\n.*assertion=[A-Za-z0-9_-]+\.' . preg_quote($b64('{"iss":"odrzucony@'), '#') . '#s'
        => $json(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.'], 400),
    '#^POST https://oauth2\.googleapis\.com/token\n.*grant_type=urn%3Aietf%3Aparams%3Aoauth%3Agrant-type%3Ajwt-bearer#s'
        => $json(['access_token' => 'ya29.token-testowy', 'expires_in' => 3599, 'token_type' => 'Bearer']),

    // Konto Google połączone przez firmę (OAuth): wymiana kodu z ekranu zgody, odświeżenie dostępu i cofnięcie.
    '#^POST https://oauth2\.googleapis\.com/token\n.*grant_type=authorization_code&code=kod-bez-search-console#s'
        => $json(['access_token' => 'ya29.oauth-tylko-analytics', 'expires_in' => 3599, 'refresh_token' => '1//tylko-analytics', 'scope' => 'https://www.googleapis.com/auth/analytics.readonly', 'token_type' => 'Bearer']),
    '#^POST https://oauth2\.googleapis\.com/token\n.*grant_type=authorization_code&code=zly-kod#s'
        => $json(['error' => 'invalid_grant', 'error_description' => 'Malformed auth code.'], 400),
    '#^POST https://oauth2\.googleapis\.com/token\n.*grant_type=authorization_code#s'
        => $json(['access_token' => 'ya29.oauth-pierwszy', 'expires_in' => 3599, 'refresh_token' => '1//staly-dostep-firmy',
            'scope' => 'https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/analytics.readonly', 'token_type' => 'Bearer']),
    '#^POST https://oauth2\.googleapis\.com/token\n.*grant_type=refresh_token#s' => $variant === 'oauth-cofniety'
        ? $json(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)
        : $json(['access_token' => 'ya29.oauth-odswiezony', 'expires_in' => 3599, 'scope' => 'https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/analytics.readonly', 'token_type' => 'Bearer']),
    '#^POST https://oauth2\.googleapis\.com/revoke\n#' => $json([]),

    // Search Console: lista usług konta.
    '#^GET https://www\.googleapis\.com/webmasters/v3/sites\n#' => $variant === 'api-wylaczone'
        ? $json(['error' => ['code' => 403, 'message' => 'Google Search Console API has not been used in project 123 before or it is disabled.', 'status' => 'PERMISSION_DENIED']], 403)
        : $json(['siteEntry' => $variant === 'bez-dostepu'
            ? [['siteUrl' => 'https://inna-witryna.example/', 'permissionLevel' => 'siteRestrictedUser']]
            : [['siteUrl' => 'https://inna-witryna.example/', 'permissionLevel' => 'siteFullUser'], ['siteUrl' => 'sc-domain:sklep-przykladowy.example', 'permissionLevel' => 'siteRestrictedUser'],
                ['siteUrl' => 'https://niezweryfikowana.example/', 'permissionLevel' => 'siteUnverifiedUser']]]),

    // Search Console: zapytania o dane. Kolejność wzorców od najbardziej szczegółowych.
    '#^POST ' . $gsc . '\n.*"dimensions":\["query","page"\]#s' => $json(['rows' => [
        $row(['ekspres kolbowy ranking', 'https://sklep-przykladowy.example/ranking-ekspresow'], 9, 700, 2.1),
        $row(['ekspres kolbowy ranking', 'https://sklep-przykladowy.example/ekspresy-kolbowe'], 3, 400, 4.9),
        $row(['ekspres kolbowy ranking', 'https://sklep-przykladowy.example/blog/stary-wpis'], 0, 20, 31.0),
        $row(['odkamienianie ekspresu', 'https://sklep-przykladowy.example/poradnik/odkamienianie'], 30, 900, 6.2),
        $row(['odkamienianie ekspresu', 'https://sklep-przykladowy.example/kontakt'], 0, 40, 44.0),
    ], 'responseAggregationType' => 'byPage']),
    '#^POST ' . $gsc . '\n.*"startDate":"' . $curStart . '".*"dimensions":\["query"\]#s' => $json(['rows' => [
        $row(['sklep przykładowy'], 150, 400, 1.0),
        $row(['jaki ekspres ciśnieniowy do domu'], 96, 1500, 3.2),
        $row(['ekspres kolbowy czy automatyczny'], 61, 980, 2.8),
        $row(['ekspres ciśnieniowy do 1000 zł'], 44, 520, 1.6),
        $row(['sklep przykładowy opinie'], 38, 120, 1.2),
        $row(['odkamienianie ekspresu'], 30, 940, 6.2),
        $row(['jak odkamienić ekspres kolbowy krok po kroku'], 22, 800, 4.4),
        $row(['ile kawy na espresso'], 18, 300, 2.1),
        $row(['ekspres do małej kuchni'], 14, 620, 8.6),
        $row(['ekspres kolbowy ranking'], 12, 1100, 2.2),
        $row(['kawa do ekspresu ciśnieniowego'], 9, 1300, 11.8),
        $row(['czy ekspres kolbowy jest trudny w obsłudze'], 7, 260, 5.1),
        $row(['młynek do kawy żarnowy'], 4, 700, 13.9),
        $row(['ekspres przelewowy'], 3, 90, 18.0),
        $row(['<script>alert(1)</script> ekspres'], 1, 60, 9.0),
    ], 'responseAggregationType' => 'byProperty']),
    '#^POST ' . $gsc . '\n.*"startDate":"' . $prevStart . '".*"dimensions":\["query"\]#s' => $json(['rows' => [
        $row(['sklep przykładowy'], 140, 380, 1.0),
        $row(['jaki ekspres ciśnieniowy do domu'], 80, 1400, 3.6),
        $row(['ekspres kolbowy czy automatyczny'], 66, 990, 2.7),
        $row(['ekspres kolbowy ranking'], 40, 1000, 2.0),
        $row(['sklep przykładowy opinie'], 35, 110, 1.2),
        $row(['ekspres ciśnieniowy do 1000 zł'], 21, 400, 3.4),
        $row(['ile kawy na espresso'], 20, 310, 2.0),
        $row(['odkamienianie ekspresu'], 18, 700, 8.9),
        $row(['kawa do ekspresu ciśnieniowego'], 16, 1250, 9.1),
        $row(['ekspres do małej kuchni'], 15, 600, 8.4),
        $row(['jak odkamienić ekspres kolbowy krok po kroku'], 10, 500, 6.0),
        $row(['młynek do kawy'], 9, 300, 5.0),
        $row(['ekspres przelewowy'], 3, 85, 17.0),
    ]]),
    '#^POST ' . $gsc . '\n.*"startDate":"' . $curStart . '".*"dimensions":\["page"\]#s' => $json(['rows' => [
        $row(['https://sklep-przykladowy.example/'], 190, 560, 1.3),
        $row(['https://sklep-przykladowy.example/poradnik/ekspres-cisnieniowy-do-domu'], 157, 2480, 3.0),
        $row(['https://sklep-przykladowy.example/poradnik/odkamienianie'], 52, 1740, 5.4),
        $row(['https://sklep-przykladowy.example/ekspresy-kolbowe'], 48, 900, 3.0),
        $row(['https://sklep-przykladowy.example/poradnik/ekspres-do-malej-kuchni'], 14, 620, 8.6),
        $row(['https://sklep-przykladowy.example/ranking-ekspresow'], 12, 1200, 2.3),
        $row(['https://sklep-przykladowy.example/kawa'], 9, 1300, 11.8),
    ]]),
    '#^POST ' . $gsc . '\n.*"startDate":"' . $prevStart . '".*"dimensions":\["page"\]#s' => $json(['rows' => [
        $row(['https://sklep-przykladowy.example/'], 175, 500, 1.3),
        $row(['https://sklep-przykladowy.example/poradnik/ekspres-cisnieniowy-do-domu'], 146, 2390, 3.2),
        $row(['https://sklep-przykladowy.example/ekspresy-kolbowe'], 50, 880, 2.9),
        $row(['https://sklep-przykladowy.example/ranking-ekspresow'], 41, 1100, 2.0),
        $row(['https://sklep-przykladowy.example/poradnik/odkamienianie'], 28, 1200, 7.7),
        $row(['https://sklep-przykladowy.example/kawa'], 16, 1250, 9.1),
    ]]),
    '#^POST ' . $gsc . '\n.*"dimensions":\["device"\]#s' => $json(['rows' => [$row(['MOBILE'], 372, 8900, 5.1), $row(['DESKTOP'], 198, 4000, 4.2), $row(['TABLET'], 8, 134, 6.0)]]),
    '#^POST ' . $gsc . '\n.*"dimensions":\["date"\]#s' => $json(['rows' => $daily]),
    '#^POST ' . $gsc . '\n.*"startDate":"' . $curStart . '"#s' => $json(['rows' => [$row([], 578, 13034, 5.6)]]),
    '#^POST ' . $gsc . '\n.*"startDate":"' . $prevStart . '"#s' => $json(['rows' => [$row([], 538, 12250, 6.0)]]),
    // Usługa, do której konto nie ma dostępu.
    '#^POST https://www\.googleapis\.com/webmasters/v3/sites/[^/]+/searchAnalytics/query#' => $json(['error' => ['code' => 403, 'message' => "User does not have sufficient permission for site 'x'.", 'status' => 'PERMISSION_DENIED']], 403),

    // Google Analytics Admin API: usługi konta i ich strumienie danych.
    '#^GET https://analyticsadmin\.googleapis\.com/v1beta/accountSummaries#' => $variant === 'bez-dostepu' ? $json(['accountSummaries' => []]) : $json(['accountSummaries' => [[
        'name' => 'accountSummaries/1', 'account' => 'accounts/1', 'displayName' => 'Konto testowe',
        'propertySummaries' => $variant === 'ga4-wiele'
            ? [['property' => 'properties/111111111', 'displayName' => 'Inna witryna', 'propertyType' => 'PROPERTY_TYPE_ORDINARY'], ['property' => 'properties/123456789', 'displayName' => 'Sklep', 'propertyType' => 'PROPERTY_TYPE_ORDINARY']]
            : [['property' => 'properties/123456789', 'displayName' => 'Sklep', 'propertyType' => 'PROPERTY_TYPE_ORDINARY']],
    ]]]),
    '#^GET https://analyticsadmin\.googleapis\.com/v1beta/properties/111111111/dataStreams#' => $json(['dataStreams' => [
        ['name' => 'properties/111111111/dataStreams/1', 'type' => 'WEB_DATA_STREAM', 'webStreamData' => ['measurementId' => 'G-AAAA', 'defaultUri' => 'https://inna-witryna.example']]]]),
    '#^GET https://analyticsadmin\.googleapis\.com/v1beta/properties/123456789/dataStreams#' => $json(['dataStreams' => [
        ['name' => 'properties/123456789/dataStreams/7', 'type' => 'ANDROID_APP_DATA_STREAM'],
        ['name' => 'properties/123456789/dataStreams/2', 'type' => 'WEB_DATA_STREAM', 'webStreamData' => ['measurementId' => 'G-BBBB', 'defaultUri' => 'https://www.sklep-przykladowy.example/']]]]),

    '#^POST https://analyticsdata\.googleapis\.com/v1beta/properties/123456789:runReport\n.*"keyEvents"#s' => $json($gaDaily((string) $variant)),
    // Google Analytics Data API: sesje. Filtr źródła zwraca też wiersz spoza listy asystentów (szerokie wyrażenie).
    '#^POST https://analyticsdata\.googleapis\.com/v1beta/properties/123456789:runReport\n.*"landingPage"#s' => $json($ga([
        [['/poradnik/odkamienianie', 'chatgpt.com'], 21], [['/poradnik/odkamienianie', 'chat.openai.com'], 4], [['/ranking-ekspresow', 'perplexity.ai'], 9], [['(not set)', 'chatgpt.com'], 2],
    ])),
    '#^POST https://analyticsdata\.googleapis\.com/v1beta/properties/123456789:runReport\n.*"startDate":"' . $gaCurStart . '".*"dimensionFilter"#s' => $json($ga([
        [['chatgpt.com'], 34], [['chat.openai.com'], 6], [['perplexity.ai'], 11], [['copilot.microsoft.com'], 3], [['grokipedia-fan.example'], 50],
    ])),
    '#^POST https://analyticsdata\.googleapis\.com/v1beta/properties/123456789:runReport\n.*"startDate":"' . $gaPrevStart . '".*"dimensionFilter"#s' => $json($ga([
        [['chatgpt.com'], 20], [['perplexity.ai'], 12],
    ])),
    '#^POST https://analyticsdata\.googleapis\.com/v1beta/properties/123456789:runReport\n.*"startDate":"' . $gaCurStart . '"#s' => $json($ga([[[], 2700]])),
    '#^POST https://analyticsdata\.googleapis\.com/v1beta/properties/123456789:runReport\n#' => $json($ga([[[], 2500]])),
    '#^POST https://analyticsdata\.googleapis\.com/v1beta/properties/\d+:runReport#' => $json(['error' => ['code' => 403, 'message' => 'User does not have sufficient permissions for this property.', 'status' => 'PERMISSION_DENIED']], 403),

    // Cloudflare: strefa po nazwie domeny i statystyki botów (GraphQL).
    '#^GET https://api\.cloudflare\.com/client/v4/zones\?name=sklep-przykladowy\.example#' => $variant === 'cf-bez-strefy'
        ? $json(['success' => true, 'result' => []]) : $json(['success' => true, 'result' => [['id' => 'abcdef0123456789abcdef0123456789', 'name' => 'sklep-przykladowy.example']]]),
    '#^POST https://api\.cloudflare\.com/client/v4/graphql\n.*clientRequestPath#s' => $cf([
        ['count' => 40, 'dimensions' => ['userAgent' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot', 'clientRequestPath' => '/poradnik/odkamienianie']],
        ['count' => 12, 'dimensions' => ['userAgent' => 'Mozilla/5.0 (compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot)', 'clientRequestPath' => '/poradnik/odkamienianie']],
        ['count' => 9, 'dimensions' => ['userAgent' => 'Mozilla/5.0 (compatible; Claude-User/1.0; +Claude-User@anthropic.com)', 'clientRequestPath' => '/ranking-ekspresow']],
        ['count' => 30, 'dimensions' => ['userAgent' => 'Mozilla/5.0 (compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot)', 'clientRequestPath' => '/robots.txt']],
        ['count' => 5, 'dimensions' => ['userAgent' => 'Mozilla/5.0 (compatible; OAI-SearchBot/1.0)', 'clientRequestPath' => '/assets/app.css']],
    ]),
    ...($variant === 'cf-odmowa' ? ['#^POST https://api\.cloudflare\.com/client/v4/graphql\n.*viewer#s' => $json(['data' => null, 'errors' => [['message' => 'not authorized for that account', 'path' => null, 'extensions' => ['code' => 'authz']]]])] : []),
    // Cztery zapytania po 7 dni: każde dostaje wiersze ze swojego tygodnia.
    ...array_merge(...array_map(static function (int $back) use ($cf, $cfDays): array {
        $from = gmdate('Y-m-d', strtotime(gmdate('Y-m-d') . ' UTC') - $back * 86400) . 'T00:00:00Z';
        return ['#^POST https://api\.cloudflare\.com/client/v4/graphql\n.*datetime_geq: \\\\"' . $from . '#s' => $cf(array_merge(...array_map($cfDays, range($back, $back - 6))))];
    }, [28, 21, 14, 7])),
    '#^POST https://api\.cloudflare\.com/client/v4/graphql\n#' => $cf([]),

    // Mapy witryn konkurentów. Wariant „mapa-2” to drugie pobranie: doszła jedna strona, jedna zniknęła.
    '#^GET https://konkurent-a\.example/robots\.txt\n#' => $page("User-agent: *\nDisallow:\nSitemap: https://konkurent-a.example/sitemap_index.xml\nSitemap: https://obca-domena.example/sitemap.xml\n"),
    '#^GET https://konkurent-a\.example/sitemap_index\.xml\n#' => $page('<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        . '<sitemap><loc>https://konkurent-a.example/post-sitemap.xml</loc></sitemap><sitemap><loc>https://konkurent-a.example/post_tag-sitemap.xml</loc></sitemap></sitemapindex>'),
    '#^GET https://konkurent-a\.example/post-sitemap\.xml\n#' => $page($sitemap(array_merge([
        ['https://konkurent-a.example/'],
        ['https://konkurent-a.example/blog/mlynek-zarnowy-czy-nozowy', $recent],
        ['https://konkurent-a.example/blog/mlynek-do-kawy-ranking', '2026-01-10'],
        ['https://konkurent-a.example/blog/odkamienianie-ekspresu-krok-po-kroku', '2025-12-01'],
        ['https://konkurent-a.example/tag/kawa'],
        ['https://konkurent-a.example/img/zdjecie.jpg'],
        ['https://konkurent-a.example/blog/<b>xss</b>-w-adresie'],
    ], $variant === 'mapa-2' ? [['https://konkurent-a.example/blog/spieniacz-do-mleka-jak-wybrac', gmdate('Y-m-d')]] : [['https://konkurent-a.example/blog/stary-wpis-do-usuniecia']]))),
    '#^GET https://konkurent-b\.example/robots\.txt\n#' => $page("User-agent: *\nDisallow:\n"),
    '#^GET https://konkurent-b\.example/sitemap\.xml\n#' => $page($sitemap([
        ['https://konkurent-b.example/poradniki/mlynek-reczny-do-kawy'], ['https://konkurent-b.example/poradniki/spieniacz-elektryczny'], ['https://konkurent-b.example/oferta/ekspresy-kolbowe'],
    ])),
    '#^GET https://bez-mapy\.example/#' => $page('<html><body>Nie ma</body></html>', 404),
];
