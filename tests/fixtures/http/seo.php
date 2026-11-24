<?php
/**
 * Odpowiedzi usług używanych w części „Widoczność”: Google (token konta usługi, Search Console API, Analytics Data i Admin API),
 * Cloudflare GraphQL Analytics API oraz mapy witryn konkurentów. Formaty według dokumentacji tych usług.
 * Zmienna FIXTURE_SEO przełącza warianty: „bez-dostepu”, „api-wylaczone”, „ga4-wiele”, „cf-odmowa”, „mapa-2”.
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
    // Lekki wzrost w czasie, żeby tygodnie na wykresie się różniły.
    $daily[] = $row([$gscDay($i)], 20 + intdiv(179 - $i, 9), 600 + (179 - $i) * 2, 8.4);
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
$recent = gmdate('Y-m-d', time() - 5 * 86400);

return [
    // Token konta usługi. Konto o adresie „odrzucony@…” udaje usunięty klucz.
    '#^POST https://oauth2\.googleapis\.com/token\n.*assertion=[A-Za-z0-9_-]+\.' . preg_quote($b64('{"iss":"odrzucony@'), '#') . '#s'
        => $json(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.'], 400),
    '#^POST https://oauth2\.googleapis\.com/token\n.*grant_type=urn%3Aietf%3Aparams%3Aoauth%3Agrant-type%3Ajwt-bearer#s'
        => $json(['access_token' => 'ya29.token-testowy', 'expires_in' => 3599, 'token_type' => 'Bearer']),

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
        $row(['ekspres kolbowy ranking'], 12, 1100, 2.2),
        $row(['odkamienianie ekspresu'], 30, 940, 6.2),
        $row(['jak wybrać ekspres ciśnieniowy do domu'], 22, 800, 4.4),
        $row(['<script>alert(1)</script> ekspres'], 1, 60, 9.0),
    ], 'responseAggregationType' => 'byProperty']),
    '#^POST ' . $gsc . '\n.*"startDate":"' . $prevStart . '".*"dimensions":\["query"\]#s' => $json(['rows' => [
        $row(['sklep przykładowy'], 140, 380, 1.0),
        $row(['ekspres kolbowy ranking'], 40, 1000, 2.0),
        $row(['odkamienianie ekspresu'], 18, 700, 8.9),
        $row(['młynek do kawy'], 9, 300, 5.0),
    ]]),
    '#^POST ' . $gsc . '\n.*"startDate":"' . $curStart . '".*"dimensions":\["page"\]#s' => $json(['rows' => [
        $row(['https://sklep-przykladowy.example/'], 150, 500, 1.4),
        $row(['https://sklep-przykladowy.example/ranking-ekspresow'], 12, 1200, 2.3),
        $row(['https://sklep-przykladowy.example/poradnik/odkamienianie'], 30, 940, 6.2),
    ]]),
    '#^POST ' . $gsc . '\n.*"startDate":"' . $prevStart . '".*"dimensions":\["page"\]#s' => $json(['rows' => [
        $row(['https://sklep-przykladowy.example/'], 140, 480, 1.4),
        $row(['https://sklep-przykladowy.example/ranking-ekspresow'], 41, 1100, 2.0),
    ]]),
    '#^POST ' . $gsc . '\n.*"dimensions":\["device"\]#s' => $json(['rows' => [$row(['MOBILE'], 140, 2100, 5.1), $row(['DESKTOP'], 75, 1200, 4.2)]]),
    '#^POST ' . $gsc . '\n.*"dimensions":\["date"\]#s' => $json(['rows' => $daily]),
    '#^POST ' . $gsc . '\n.*"startDate":"' . $curStart . '"#s' => $json(['rows' => [$row([], 215, 3300, 4.8)]]),
    '#^POST ' . $gsc . '\n.*"startDate":"' . $prevStart . '"#s' => $json(['rows' => [$row([], 207, 2380, 5.3)]]),
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
    '#^POST https://api\.cloudflare\.com/client/v4/graphql\n#' => $variant === 'cf-odmowa'
        ? $json(['data' => null, 'errors' => [['message' => 'not authorized for that account', 'path' => null, 'extensions' => ['code' => 'authz']]]])
        : $cf([
            $bot(30, 2, 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot', 200),
            $bot(10, 9, 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot', 200),
            $bot(4, 2, 'Mozilla/5.0 (compatible; Claude-User/1.0; +Claude-User@anthropic.com)', 200),
            $bot(118, 3, 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)', 403),
            $bot(2, 3, 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)', 200),
            $bot(60, 4, 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)', 403),
            $bot(7, 5, 'Mozilla/5.0 (compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot)', 404),
            $bot(999, 2, 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 200),
        ]),

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
