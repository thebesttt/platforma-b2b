<?php

declare(strict_types=1);

namespace App\Modules\Content;

/**
 * 22 wskaźniki liczone programem (wszystkie poza D1 i D2, które ocenia model AI z cytatem).
 * Każda funkcja dostaje fakty o stronie i zwraca stan, zmierzoną wartość i dowód. Progi są w wytycznych
 * (resources/audyt-ai-rules.json) i w testach granicznych. Te same dane wejściowe dają zawsze ten sam wynik.
 */
final class Checks
{
    private const GENERIC_TITLES = ['strona główna', 'strona glowna', 'home', 'start', 'witamy', 'bez tytułu', 'untitled', 'untitled document', 'nowa strona', 'index', 'document', 'tytuł strony', 'moja strona'];

    private const GENERIC_HEADINGS = ['informacje', 'informacje ogólne', 'dodatkowe informacje', 'więcej informacji', 'dowiedz się więcej', 'czytaj więcej', 'zobacz więcej',
        'więcej', 'opis', 'szczegóły', 'inne', 'pozostałe', 'różne', 'nagłówek', 'tytuł', 'sekcja', 'tekst', 'treść', 'wstęp', 'kliknij tutaj', 'uwaga', 'lorem ipsum'];

    private const ORG_TYPES = ['Organization', 'Corporation', 'LocalBusiness', 'OnlineStore', 'OnlineBusiness', 'ProfessionalService', 'Store', 'NGO', 'EducationalOrganization', 'MedicalOrganization', 'NewsMediaOrganization'];

    private const ARTICLE_TYPES = ['Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'Report', 'ScholarlyArticle'];

    /** Jednostki, po których rozpoznajemy konkretną daną: liczba i jednostka w jednym zdaniu (wskaźnik C4). */
    private const UNITS = '%|proc\.?|zł|złotych|pln|eur|euro|€|usd|\$|gr|kg|dag|g|mg|t|ton\p{L}*|l|ml|litr\p{L}*|m|cm|mm|km|m2|m²|m3|m³|ha|kw|kwh|mw|mah|bar\p{L}*|°c|st\.|stopni\p{L}*'
        . '|h|godz\.?|godzin\p{L}*|min\.?|minut\p{L}*|s|sek\.?|sekund\p{L}*|ms|dni|dzień|dnia|tygod\p{L}+|miesi\p{L}+|lat|lata|rok\p{L}*|kwarta\p{L}+'
        . '|szt\.?|sztuk\p{L}*|os[oó]b\p{L}*|klient\p{L}*|firm\p{L}*|gb|mb|tb|kb|hz|khz|mhz|ghz|px|pikseli|cali|cale|cal|mpx|razy|krotnie|pkt\.?|punkt\p{L}*|stron\p{L}*|słów|znak\p{L}*|krok\p{L}*|etap\p{L}*';

    /**
     * @param array $s stan audytu: odpowiedź serwera, fakty o stronie, robots.txt, odpowiedzi dla botów, render, szybkość
     * @return array<string, array{status: string, points: int, max: int, value: string, evidence: string}>
     */
    public static function all(array $s): array
    {
        $out = [];
        foreach (Ruleset::rules() as $code => $rule) {
            if (!empty($rule['ai'])) {
                continue;
            }
            if (!Ruleset::applies($rule, (string) ($s['page_type'] ?? 'other'))) {
                $out[$code] = self::result($rule, 'na', 'Nie dotyczy: ' . mb_strtolower(Ruleset::pageTypes()[$s['page_type']] ?? 'ten rodzaj strony'));
                continue;
            }
            $r = self::{'check' . $code}($s);
            $out[$code] = self::result($rule, $r[0], $r[1], $r[2] ?? '', $r[3] ?? null, $r[4] ?? null);
        }
        return $out;
    }

    /** Wynik wskaźnika. Punkty wynikają ze stanu, chyba że wskaźnik ma części (E1) i podaje je sam. */
    public static function result(array $rule, string $status, string $value, string $evidence = '', ?int $points = null, ?int $max = null): array
    {
        $measured = !in_array($status, ['none', 'na'], true);
        return [
            'status' => $status,
            'points' => $points ?? Ruleset::points($rule, $status),
            'max' => $max ?? ($measured ? (int) $rule['max'] : 0),
            'value' => $value,
            'evidence' => $evidence,
        ];
    }

    private static function list(array $items): string
    {
        $items = array_values($items);
        return count($items) <= 1 ? (string) ($items[0] ?? '') : implode(', ', array_slice($items, 0, -1)) . ' i ' . end($items);
    }

    // --- A. Dostęp ---------------------------------------------------------------------------------------------------

    private static function checkA1(array $s): array
    {
        $chain = $s['chain'] ?? [];
        $status = (int) ($s['status'] ?? 0);
        if (!empty($s['loop'])) {
            return ['bad', 'Pętla przekierowań', self::chain($chain)];
        }
        if ($status === 0) {
            return ['bad', 'Brak odpowiedzi serwera', (string) ($s['error'] ?? 'Serwer nie odpowiedział w 15 s.')];
        }
        if ($status >= 400 || $status < 200) {
            return ['bad', 'Kod ' . $status, self::chain($chain)];
        }
        if ($status >= 300) {
            return ['bad', 'Kod ' . $status . ' bez adresu docelowego', self::chain($chain)];
        }
        $redirects = max(0, count($chain) - 1);
        if ($redirects === 0) {
            return ['good', 'Kod ' . $status . ', bez przekierowań'];
        }
        $first = $chain[0]['url'] ?? '';
        $last = end($chain)['url'] ?? '';
        $kind = '';
        if ($redirects === 1) {
            $kind = str_starts_with($first, 'http://') && str_starts_with($last, 'https://') ? ' (z http na https)' : ' (' . self::short($first) . ' → ' . self::short($last) . ')';
        }
        return [$redirects <= 1 ? 'good' : 'mid', 'Kod ' . $status . ', ' . $redirects . ' ' . plural($redirects, 'przekierowanie', 'przekierowania', 'przekierowań') . $kind, $redirects > 1 ? self::chain($chain) : ''];
    }

    private static function chain(array $chain): string
    {
        return implode(' → ', array_map(static fn ($c) => self::short((string) $c['url']) . ' (' . ($c['status'] ?: 'brak odpowiedzi') . ')', array_slice($chain, 0, 8)));
    }

    private static function short(string $url): string
    {
        return truncate((string) preg_replace('#^https?://(www\.)?#', '', $url), 70);
    }

    private static function checkA2(array $s): array
    {
        $f = $s['facts'];
        $dir = $f['robots'] ?? [];
        if (in_array('noindex', $dir, true) || in_array('none', $dir, true)) {
            return ['bad', 'Strona ma dyrektywę noindex', 'Dyrektywy dla robotów: ' . implode(', ', $dir) . '.'];
        }
        $canon = $f['canonical'] ?? null;
        if ($canon === null) {
            return ['mid', 'Brak noindex, brak adresu kanonicznego'];
        }
        if (Fetcher::canonicalForm($canon) !== Fetcher::canonicalForm((string) $s['final_url'])) {
            return ['bad', 'Adres kanoniczny wskazuje inną stronę', 'rel="canonical": ' . truncate($canon, 160) . '. Sprawdzana strona: ' . truncate((string) $s['final_url'], 160) . '.'];
        }
        return ['good', 'Brak noindex, adres kanoniczny wskazuje tę stronę'];
    }

    private static function checkA3(array $s): array
    {
        $f = $s['facts'];
        $dir = $f['robots'] ?? [];
        $max = null;
        foreach ($dir as $d) {
            if (preg_match('/^max-snippet\s*:\s*(-?\d+)$/', $d, $m)) {
                $max = (int) $m[1];
            }
        }
        $words = max(1, (int) ($f['words'] ?? 0));
        $hidden = (int) ($f['nosnippet_words'] ?? 0);
        if (in_array('nosnippet', $dir, true)) {
            return ['bad', 'Dyrektywa nosnippet zakazuje pokazywania fragmentu'];
        }
        if ($max === 0) {
            return ['bad', 'max-snippet:0 zakazuje pokazywania fragmentu'];
        }
        if ($hidden / $words >= 0.9) {
            return ['bad', 'Cała treść główna jest w data-nosnippet'];
        }
        $issues = [];
        if ($max !== null && $max > 0 && $max < 160) {
            $issues[] = 'max-snippet:' . $max . ' (poniżej 160 znaków)';
        }
        if (in_array('noarchive', $dir, true)) {
            $issues[] = 'noarchive (wyłącza treść z odpowiedzi Copilota)';
        }
        if ($hidden > 0) {
            $issues[] = 'data-nosnippet obejmuje ' . $hidden . ' z ' . $words . ' słów treści';
        }
        return $issues ? ['mid', 'Ograniczenie fragmentu: ' . implode(', ', $issues)] : ['good', 'Brak ograniczeń fragmentu'];
    }

    private static function checkA4(array $s): array
    {
        $rb = $s['robots'] ?? null;
        if ($rb === null || (int) $rb['status'] === 0) {
            return ['none', 'Nie udało się pobrać robots.txt'];
        }
        $status = (int) $rb['status'];
        if ($status >= 500) {
            return ['bad', 'robots.txt zwraca kod ' . $status, 'Przy błędzie serwera Google traktuje witrynę jak zablokowaną w całości.'];
        }
        if ($status >= 400) {
            return ['good', 'Brak pliku robots.txt (kod ' . $status . '): 5 z 5 botów ma dostęp'];
        }
        $p = parse_url((string) $s['final_url']);
        $path = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
        $blocked = [];
        $proof = [];
        foreach (array_keys(Robots::SEARCH_BOTS) as $bot) {
            $c = Robots::check((string) $rb['text'], $bot, $path);
            if (!$c['allowed']) {
                $blocked[] = $bot;
                $proof[] = $bot . ': wiersz ' . $c['line'] . ' „' . $c['rule'] . '” w grupie „User-agent: ' . $c['group'] . '”';
            }
        }
        $training = [];
        foreach (Robots::TRAINING_BOTS as $bot) {
            $training[] = $bot . ' ' . (Robots::check((string) $rb['text'], $bot, $path)['allowed'] ? 'dozwolony' : 'zablokowany');
        }
        $evidence = trim(($proof ? 'robots.txt: ' . implode('; ', $proof) . '. ' : '') . 'Bez punktów (boty treningowe): ' . implode(', ', $training) . '.');
        $n = 5 - count($blocked);
        if (in_array('Googlebot', $blocked, true) || in_array('Bingbot', $blocked, true)) {
            return ['bad', $n . ' z 5 botów ma dostęp, zablokowany ' . self::list($blocked), $evidence];
        }
        if ($blocked) {
            return ['mid', $n . ' z 5 botów ma dostęp, zablokowany ' . self::list($blocked), $evidence];
        }
        return ['good', '5 z 5 botów ma dostęp', $evidence];
    }

    private static function checkA5(array $s): array
    {
        $bots = $s['bots'] ?? null;
        if (!is_array($bots) || !$bots) {
            return ['none', 'Test odpowiedzi dla botów nie został wykonany'];
        }
        $tested = array_filter($bots, static fn ($b) => empty($b['skipped']));
        $skipped = array_keys(array_filter($bots, static fn ($b) => !empty($b['skipped'])));
        $note = $skipped ? ' Pominięte, bo blokuje je robots.txt: ' . self::list($skipped) . '.' : '';
        if (!$tested) {
            return ['none', 'Wszystkie boty blokuje robots.txt, test serwera pominięty'];
        }
        $blocked = [];
        $slow = [];
        foreach ($tested as $name => $b) {
            if (!empty($b['blocked'])) {
                $blocked[$name] = (string) $b['reason'];
            } elseif ((float) $b['time'] > 5.0) {
                $slow[$name] = Text::num((float) $b['time']) . ' s';
            }
        }
        $total = count($tested);
        if ($blocked) {
            $by = [];
            foreach ($blocked as $name => $reason) {
                $by[$reason][] = $name;
            }
            $parts = [];
            foreach ($by as $reason => $names) {
                $parts[] = self::list($names) . ' ' . (count($names) > 1 ? 'dostają' : 'dostaje') . ' ' . $reason;
            }
            return ['bad', ($total - count($blocked)) . ' z ' . $total . ': ' . implode('; ', $parts), trim('Test wychodzi z naszego serwera z nagłówkiem User-Agent bota. Ostateczne potwierdzenie daje dziennik serwera.' . $note)];
        }
        if ($slow) {
            $parts = [];
            foreach ($slow as $name => $t) {
                $parts[] = $name . ' ' . $t;
            }
            return ['mid', 'Kod 200 dla wszystkich, ale odpowiedź trwa ponad 5 s: ' . implode(', ', $parts), trim($note)];
        }
        return ['good', $total . ' z ' . $total . ' zapytań z kodem 200 i tą samą treścią co dla przeglądarki', trim($note)];
    }

    private static function checkA6(array $s): array
    {
        $r = $s['render'] ?? null;
        if (!is_array($r) || !isset($r['words'])) {
            return ['none', 'Wymaga uruchomienia strony w przeglądarce', (string) ($r['error'] ?? 'Usługa renderowania stron nie jest podłączona.')];
        }
        $server = (int) ($s['facts']['words'] ?? 0);
        $rendered = (int) $r['words'];
        if ($rendered < 20) {
            return ['none', 'Po uruchomieniu skryptów strona ma za mało treści do porównania', 'Słów po uruchomieniu skryptów: ' . $rendered . '.'];
        }
        $pct = (int) min(100, floor(100 * $server / $rendered));
        $h1 = count(array_filter($s['facts']['h1'] ?? [])) > 0;
        $value = $pct . '% słów treści w HTML z serwera';
        $proof = 'HTML z serwera: ' . $server . ' słów. Po uruchomieniu skryptów: ' . $rendered . ' słów.';
        if (!$h1) {
            return ['bad', $value . ', brak H1 w HTML z serwera', $proof];
        }
        return [$pct >= 90 ? 'good' : ($pct >= 50 ? 'mid' : 'bad'), $value, $proof];
    }

    // --- B. Struktura ------------------------------------------------------------------------------------------------

    private static function checkB1(array $s): array
    {
        $title = trim((string) ($s['facts']['title'] ?? ''));
        if ($title === '') {
            return ['bad', 'Brak znacznika title albo jest pusty'];
        }
        $len = mb_strlen($title);
        $hint = $len > 60 ? 'Wskazówka bez punktów: tytuł ponad 60 znaków może zostać ucięty w wynikach.' : '';
        if (in_array(mb_strtolower(trim($title, " \t.!-|")), self::GENERIC_TITLES, true)) {
            return ['mid', 'Tytuł „' . truncate($title, 60) . '” jest ogólnikiem', $hint];
        }
        foreach ($s['siblings'] ?? [] as $sib) {
            if (mb_strtolower(trim((string) $sib['title'])) === mb_strtolower($title)) {
                return ['mid', 'Ten sam tytuł ma inna sprawdzona strona', 'Powtarza się na: ' . self::short((string) $sib['url']) . '. ' . $hint];
            }
        }
        return ['good', (!empty($s['siblings']) ? 'Unikalny, ' : 'Jest, ') . $len . ' ' . plural($len, 'znak', 'znaki', 'znaków'),
            trim((empty($s['siblings']) ? 'Unikalność sprawdzimy, gdy dodasz kolejne strony tej witryny. ' : '') . $hint)];
    }

    private static function checkB2(array $s): array
    {
        $d = trim((string) ($s['facts']['description'] ?? ''));
        if ($d === '') {
            return ['bad', 'Brak meta description'];
        }
        foreach ($s['siblings'] ?? [] as $sib) {
            if (mb_strtolower(trim((string) $sib['description'])) === mb_strtolower($d)) {
                return ['mid', 'Opis taki sam jak na innej sprawdzonej stronie', 'Powtarza się na: ' . self::short((string) $sib['url']) . '.'];
            }
        }
        $len = mb_strlen($d);
        return ['good', 'Jest, ' . $len . ' ' . plural($len, 'znak', 'znaki', 'znaków') . (!empty($s['siblings']) ? ', nie powtarza się' : '')];
    }

    private static function checkB3(array $s): array
    {
        $h1 = array_values(array_filter($s['facts']['h1'] ?? [], static fn ($t) => trim((string) $t) !== ''));
        if (!$h1) {
            return ['bad', 'Brak nagłówka H1'];
        }
        if (count($h1) > 1) {
            return ['mid', count($h1) . ' nagłówki H1 zamiast jednego', 'Pierwsze: „' . truncate($h1[0], 70) . '”, „' . truncate($h1[1], 70) . '”.'];
        }
        // Słowa H1 bez spójników i bez nazwy marki (słów z nazwy domeny).
        $brand = Text::words(str_replace(['-', '.'], ' ', explode('.', Fetcher::host((string) $s['final_url']))[0] ?? ''));
        $words = array_values(array_filter(array_unique(Text::content($h1[0])), static fn ($w) => !in_array($w, $brand, true)));
        if (!$words) {
            return ['mid', '1 H1: „' . truncate($h1[0], 60) . '”, bez słów opisujących treść'];
        }
        $hits = Text::hits($words, (string) ($s['facts']['title'] ?? ''));
        $n = count($words);
        if ($hits * 2 >= $n) {
            return ['good', '1 H1, ' . $hits . ' z ' . $n . ' ' . plural($n, 'słowa', 'słów', 'słów') . ' jest też w tytule'];
        }
        return ['mid', '1 H1: „' . truncate($h1[0], 60) . '”, ' . $hits . ' z ' . $n . ' ' . plural($n, 'słowa', 'słów', 'słów') . ' jest w tytule'];
    }

    private static function checkB4(array $s): array
    {
        $f = $s['facts'];
        $heads = $f['headings'] ?? [];
        $words = (int) ($f['words'] ?? 0);
        $h2 = count(array_filter($heads, static fn ($h) => $h[0] === 2));
        $ok = [];
        $fail = [];
        if ($words <= 300 || $h2 >= 2) {
            $ok[] = $words <= 300 ? 'strona do 300 słów nie wymaga podziału' : $h2 . ' ' . plural($h2, 'nagłówek H2', 'nagłówki H2', 'nagłówków H2');
        } else {
            $fail[] = $h2 === 0 ? 'brak nagłówków H2 na stronie z ' . $words . ' słowami' : 'tylko 1 nagłówek H2 na stronie z ' . $words . ' słowami';
        }
        $jump = null;
        $prev = null;
        foreach ($heads as [$level, $text]) {
            if ($prev !== null && $level > $prev + 1) {
                $jump = 'przeskok z H' . $prev . ' do H' . $level . ' przy „' . truncate((string) $text, 50) . '”';
                break;
            }
            $prev = $level;
        }
        $jump === null ? $ok[] = 'poziomy po kolei' : $fail[] = $jump;
        $generic = null;
        foreach ($heads as [$level, $text]) {
            if ($level >= 2 && in_array(mb_strtolower(trim((string) $text, " \t.:!?")), self::GENERIC_HEADINGS, true)) {
                $generic = 'nagłówek „' . $text . '” jest ogólnikiem';
                break;
            }
        }
        $generic === null ? $ok[] = 'bez ogólników' : $fail[] = $generic;
        $n = count($ok);
        return [$n === 3 ? 'good' : ($n === 2 ? 'mid' : 'bad'), $n . ' z 3: ' . implode(', ', $fail ?: $ok), $fail ? 'Spełnione: ' . (implode(', ', $ok) ?: 'żaden warunek') . '.' : ''];
    }

    private static function checkB5(array $s): array
    {
        $p = $s['facts']['paragraphs'] ?? [];
        $long = array_values(array_filter($p, static fn ($n) => $n > 150));
        rsort($long);
        $n = count($long);
        if ($n === 0) {
            return ['good', $p ? 'Najdłuższy akapit ma ' . max($p) . ' ' . plural(max($p), 'słowo', 'słowa', 'słów') : 'Brak akapitów powyżej 150 słów'];
        }
        return [$n <= 2 ? 'mid' : 'bad', $n . ' ' . plural($n, 'akapit', 'akapity', 'akapitów') . ' powyżej 150 słów (' . self::list(array_slice($long, 0, 5)) . ' słów)'];
    }

    private static function checkB6(array $s): array
    {
        $f = $s['facts'];
        $total = (int) ($f['images']['total'] ?? 0);
        $alt = (int) ($f['images']['with_alt'] ?? 0);
        $words = (int) ($f['words'] ?? 0);
        if ($words < 100 && ($total > 0 || (int) ($f['pdf_links'] ?? 0) > 0)) {
            return ['bad', 'Treść główna to tylko ' . $words . ' ' . plural($words, 'słowo', 'słowa', 'słów') . ' tekstu',
                'Na stronie jest ' . $total . ' ' . plural($total, 'zdjęcie', 'zdjęcia', 'zdjęć') . ' i ' . (int) $f['pdf_links'] . ' ' . plural((int) $f['pdf_links'], 'link', 'linki', 'linków') . ' do PDF. Informacje z obrazu lub PDF nie trafią do odpowiedzi.'];
        }
        if ($total === 0) {
            return ['good', 'Brak zdjęć w treści, informacje są tekstem'];
        }
        $pct = (int) floor(100 * $alt / $total);
        $value = $alt . ' z ' . $total . ' zdjęć ma opis alt' . ($alt < $total ? ' (' . $pct . '%)' : '');
        return [$pct >= 90 ? 'good' : ($pct >= 50 ? 'mid' : 'bad'), $value];
    }

    /** @return array<int, array> obiekty danych strukturalnych podanych typów */
    private static function ofType(array $items, array $types): array
    {
        return array_values(array_filter($items, static fn ($it) => array_intersect((array) ($it['@type'] ?? []), $types)));
    }

    private static function str(mixed $v): string
    {
        if (is_array($v)) {
            $v = $v['name'] ?? $v['@value'] ?? $v['url'] ?? (array_is_list($v) ? ($v[0] ?? '') : '');
        }
        return is_scalar($v) ? trim((string) $v) : (is_array($v) ? self::str($v) : '');
    }

    private static function checkB7(array $s): array
    {
        $f = $s['facts'];
        $ld = $f['jsonld'] ?? ['blocks' => 0, 'errors' => [], 'items' => [], 'types' => []];
        if ($ld['errors']) {
            return ['bad', 'Błąd składni JSON-LD w ' . count($ld['errors']) . ' z ' . $ld['blocks'] . ' bloków', 'Początek błędnego bloku: ' . $ld['errors'][0]];
        }
        $items = $ld['items'];
        if (!$items) {
            return ['mid', 'Brak danych strukturalnych'];
        }
        $types = self::list(array_slice($ld['types'], 0, 5));
        $visible = mb_strtolower(($f['title'] ?? '') . ' ' . implode(' ', $f['h1'] ?? []));
        $similar = static function (string $name) use ($visible): bool {
            $w = array_unique(Text::content($name));
            return !$w || Text::hits($w, $visible) / count($w) >= 0.3;
        };
        $type = (string) ($s['page_type'] ?? 'other');
        if ($type === 'article') {
            $a = self::ofType($items, self::ARTICLE_TYPES)[0] ?? null;
            if (!$a) {
                return ['mid', 'Brak typu Article lub BlogPosting. Są: ' . $types];
            }
            $headline = self::str($a['headline'] ?? $a['name'] ?? '');
            $author = self::str($a['author'] ?? '');
            if ($headline !== '' && !$similar($headline)) {
                return ['bad', 'Tytuł w danych strukturalnych nie zgadza się z tytułem i H1', 'headline: „' . truncate($headline, 100) . '”.'];
            }
            if ($author !== '' && !str_contains(mb_strtolower((string) ($f['text'] ?? '')) . ' ' . mb_strtolower((string) ($f['author']['name'] ?? '')), mb_strtolower($author))) {
                return ['bad', 'Autor z danych strukturalnych nie jest widoczny na stronie', 'author: „' . truncate($author, 80) . '”.'];
            }
            $missing = array_keys(array_filter(['tytułu (headline)' => $headline === '', 'autora' => $author === '', 'daty publikacji' => self::str($a['datePublished'] ?? '') === '']));
            if ($missing) {
                return ['mid', self::str($a['@type']) . ' bez ' . self::list($missing)];
            }
            return ['good', $types . ', wartości zgodne z treścią'];
        }
        if ($type === 'product') {
            $p = self::ofType($items, ['Product', 'ProductGroup'])[0] ?? null;
            if (!$p) {
                return ['mid', 'Brak typu Product. Są: ' . $types];
            }
            $name = self::str($p['name'] ?? '');
            $offer = $p['offers'] ?? null;
            $offer = is_array($offer) && array_is_list($offer) ? ($offer[0] ?? null) : $offer;
            $price = is_array($offer) ? self::str($offer['price'] ?? $offer['lowPrice'] ?? '') : '';
            if ($name !== '' && !$similar($name)) {
                return ['bad', 'Nazwa produktu w danych strukturalnych nie zgadza się z tytułem i H1', 'name: „' . truncate($name, 100) . '”.'];
            }
            if ($price !== '' && !self::priceVisible($price, (string) ($f['text'] ?? ''))) {
                return ['bad', 'Cena z danych strukturalnych nie jest widoczna na stronie', 'price: ' . $price . '.'];
            }
            $missing = array_keys(array_filter(['nazwy' => $name === '', 'ceny w ofercie (offers)' => $price === '']));
            return $missing ? ['mid', 'Product bez ' . self::list($missing)] : ['good', $types . ', wartości zgodne z treścią'];
        }
        if ($type === 'home') {
            $org = self::ofType($items, self::ORG_TYPES)[0] ?? null;
            $site = self::ofType($items, ['WebSite'])[0] ?? null;
            $missing = array_keys(array_filter(['Organization' => !$org, 'WebSite' => !$site]));
            if ($missing) {
                return ['mid', 'Brak typu ' . self::list($missing) . ($types !== '' ? '. Są: ' . $types : '')];
            }
            if (self::str($org['name'] ?? '') === '' || self::str($org['url'] ?? '') === '') {
                return ['mid', 'Organization bez nazwy albo adresu (name, url)'];
            }
            return ['good', $types . ', wartości zgodne z treścią'];
        }
        $meaningful = array_diff($ld['types'], ['FAQPage', 'BreadcrumbList', 'WebSite', 'ImageObject', 'SearchAction', 'ListItem', 'Person', 'Question', 'Answer', 'Offer', 'PostalAddress']);
        if (!$meaningful) {
            return ['mid', 'Dane niepełne: tylko ' . $types, in_array('FAQPage', $ld['types'], true) ? 'Typ FAQPage od 7.05.2026 nie daje wyników rozszerzonych w Google.' : ''];
        }
        foreach (self::ofType($items, ['WebPage', 'Service', 'Product', 'Article', 'BlogPosting']) as $it) {
            $name = self::str($it['headline'] ?? $it['name'] ?? '');
            if ($name !== '' && !$similar($name)) {
                return ['bad', 'Nazwa w danych strukturalnych nie zgadza się z tytułem i H1', self::str($it['@type']) . ': „' . truncate($name, 100) . '”.'];
            }
        }
        return ['good', $types . ', wartości zgodne z treścią'];
    }

    private static function priceVisible(string $price, string $text): bool
    {
        $n = (float) str_replace([' ', ','], ['', '.'], $price);
        if ($n <= 0) {
            return true;
        }
        $int = number_format(floor($n), 0, '', '');
        $spaced = number_format(floor($n), 0, '', ' ');
        $flat = str_replace(["\u{00A0}", "\u{202F}"], ' ', $text);
        foreach (array_unique([$int, $spaced, str_replace(' ', '.', $spaced)]) as $v) {
            if (preg_match('/(?<![\d])' . preg_quote($v, '/') . '(?![\d])/', $flat) || preg_match('/(?<![\d])' . preg_quote($v, '/') . '(?![\d])/', str_replace(' ', '', $flat))) {
                return true;
            }
        }
        return false;
    }

    // --- C. Wiarygodność ---------------------------------------------------------------------------------------------

    private static function organization(array $s): ?array
    {
        return self::ofType($s['facts']['jsonld']['items'] ?? [], self::ORG_TYPES)[0] ?? null;
    }

    private static function checkC1(array $s): array
    {
        $f = $s['facts'];
        $items = $f['jsonld']['items'] ?? [];
        if (($s['page_type'] ?? 'other') === 'article') {
            $name = $f['author']['name'] ?? null;
            $link = $f['author']['link'] ?? null;
            $person = null;
            foreach (self::ofType($items, self::ARTICLE_TYPES) as $a) {
                $au = $a['author'] ?? null;
                $au = is_array($au) && array_is_list($au) ? ($au[0] ?? null) : $au;
                if (is_array($au) && self::str($au['name'] ?? '') !== '') {
                    $person = $au;
                    break;
                }
            }
            $schemaFull = $person && in_array('Person', (array) ($person['@type'] ?? []), true) && self::str($person['url'] ?? '') !== '';
            if ($name === null) {
                return $person ? ['mid', 'Autor jest tylko w danych strukturalnych, brak widocznego podpisu', 'author: „' . truncate(self::str($person['name']), 80) . '”.'] : ['bad', 'Brak podpisu autora'];
            }
            $missing = array_keys(array_filter(['linku do strony o autorze' => !$link, 'autora w danych strukturalnych (Person z name i url)' => !$schemaFull]));
            return $missing ? ['mid', 'Podpis „' . $name . '”, brakuje ' . self::list($missing)] : ['good', 'Podpis, strona autora i Person w danych strukturalnych', 'Autor: ' . $name . '.'];
        }
        // Strony produktów, ofert i strona główna: zamiast autora sprawdzamy wydawcę.
        $org = self::organization($s);
        $orgName = $org ? self::str($org['name'] ?? '') : '';
        $ok = [];
        $fail = [];
        ($org && $orgName !== '' && self::str($org['url'] ?? '') !== '') ? $ok[] = 'Organization w danych strukturalnych' : $fail[] = 'Organization z nazwą i adresem w danych strukturalnych';
        $visible = $orgName !== ''
            ? str_contains(mb_strtolower((string) ($f['text'] ?? '') . ' ' . ($f['title'] ?? '')), mb_strtolower($orgName)) || !empty($f['footer_ids']['nips'])
            : (!empty($f['footer_ids']['nips']) || !empty($f['page_ids']['nips']));
        $visible ? $ok[] = 'nazwa albo NIP firmy na stronie' : $fail[] = 'nazwy albo NIP firmy na stronie';
        !empty($f['about_link']) ? $ok[] = 'link do strony „O nas”' : $fail[] = 'linku do strony „O nas”';
        $n = count($ok);
        return [$n === 3 ? 'good' : ($n >= 1 ? 'mid' : 'bad'), 'Wydawca: ' . $n . ' z 3' . ($fail ? ', brakuje ' . self::list($fail) : ''), $ok ? 'Jest: ' . implode(', ', $ok) . '.' : ''];
    }

    private static function pl(string $ymd): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m) ? (int) $m[3] . '.' . $m[2] . '.' . $m[1] : $ymd;
    }

    private static function checkC2(array $s): array
    {
        $f = $s['facts'];
        $visible = $f['dates'] ?? [];
        if (!$visible) {
            return ['bad', 'Brak widocznej daty publikacji lub aktualizacji'];
        }
        $prev = $s['previous'] ?? null;
        if ($prev && !empty($prev['text_hash']) && $prev['text_hash'] === ($s['text_hash'] ?? null) && !empty($prev['dates']) && $prev['dates'] !== $visible) {
            return ['bad', 'Data zmieniła się od poprzedniego audytu, a treść nie', 'Poprzednio: ' . self::list(array_map([self::class, 'pl'], array_slice($prev['dates'], 0, 3)))
                . '. Teraz: ' . self::list(array_map([self::class, 'pl'], array_slice($visible, 0, 3))) . '. Google ostrzega przed zmianą daty bez zmiany treści.'];
        }
        $schema = [];
        foreach ($f['jsonld']['items'] ?? [] as $it) {
            foreach (['dateModified', 'datePublished'] as $k) {
                if (preg_match('/^(\d{4}-\d{2}-\d{2})/', self::str($it[$k] ?? ''), $m)) {
                    $schema[$k] = $schema[$k] ?? $m[1];
                }
            }
        }
        if (!$schema) {
            return ['mid', 'Data widoczna (' . self::pl($visible[0]) . '), brak jej w danych strukturalnych'];
        }
        foreach ($schema as $key => $d) {
            foreach ($visible as $v) {
                if (abs(strtotime($d) - strtotime($v)) <= 86400) {
                    return ['good', 'Data ' . self::pl($v) . ' widoczna i zgodna z ' . $key, $prev && !empty($prev['text_hash']) && $prev['text_hash'] !== ($s['text_hash'] ?? null) && ($prev['dates'] ?? []) !== $visible
                        ? 'Treść zmieniła się od poprzedniego audytu (inny skrót treści), więc zmiana daty jest uzasadniona.' : ''];
                }
            }
        }
        return ['mid', 'Data widoczna (' . self::pl($visible[0]) . ') różni się od danych strukturalnych o ponad 1 dzień', implode(', ', array_map(static fn ($k, $d) => $k . ': ' . self::pl($d), array_keys($schema), $schema)) . '.'];
    }

    private static function checkC3(array $s): array
    {
        $n = count($s['facts']['external'] ?? []);
        $words = (int) ($s['facts']['words'] ?? 0);
        $need = max(2, (int) ceil(2 * $words / 1000));
        $value = $n . ' ' . plural($n, 'źródło', 'źródła', 'źródeł') . ' na ' . $words . ' ' . plural($words, 'słowo', 'słowa', 'słów');
        if ($n === 0) {
            return ['bad', $value];
        }
        $proof = 'Linki: ' . implode(', ', array_map([self::class, 'short'], array_slice($s['facts']['external'], 0, 5))) . ($n > 5 ? ' i ' . ($n - 5) . ' kolejnych' : '') . '.';
        return $n >= $need ? ['good', $value, $proof] : ['mid', $value . ', potrzeba ' . $need, $proof];
    }

    /** Ile zdań treści zawiera liczbę z jednostką. */
    public static function dataSentences(string $text): int
    {
        $n = 0;
        foreach (Text::sentences($text) as $sentence) {
            // Waty, wolty i ampery tylko wielką literą: „w” i „a” to w polszczyźnie zwykłe słowa („2 w 1”).
            if (preg_match('/(?<![\p{L}\d])\d[\d \x{00A0}.,]*\s?(?:' . self::UNITS . ')(?![\p{L}\d])/iu', $sentence)
                || preg_match('/(?<![\p{L}\d])\d[\d \x{00A0}.,]*\s?(?:W|V|A)(?![\p{L}\d])/u', $sentence)) {
                $n++;
            }
        }
        return $n;
    }

    private static function checkC4(array $s): array
    {
        $words = (int) ($s['facts']['words'] ?? 0);
        if ($words === 0) {
            return ['bad', 'Brak treści do policzenia'];
        }
        $count = self::dataSentences((string) ($s['facts']['text'] ?? ''));
        $rate = round($count / $words * 1000, 1);
        $shown = Text::num($rate);
        $value = $shown . ' ' . ((float) $rate === floor($rate) ? plural((int) $rate, 'zdanie', 'zdania', 'zdań') : 'zdania') . ' z liczbą i jednostką na 1000 słów';
        return [$rate >= 5 ? 'good' : ($rate >= 2 ? 'mid' : 'bad'), $value, 'W treści: ' . $count . ' ' . plural($count, 'takie zdanie', 'takie zdania', 'takich zdań') . ' na ' . $words . ' słów.'];
    }

    private static function checkC5(array $s): array
    {
        $f = $s['facts'];
        $org = self::organization($s);
        $ok = [];
        $fail = [];
        if ($org && self::str($org['name'] ?? '') !== '' && self::str($org['url'] ?? '') !== '' && !empty($org['logo'])) {
            $ok[] = 'Organization z nazwą, adresem i logo';
        } else {
            $fail[] = $org ? 'Organization bez ' . self::list(array_keys(array_filter(['nazwy' => self::str($org['name'] ?? '') === '', 'adresu' => self::str($org['url'] ?? '') === '', 'logo' => empty($org['logo'])]))) : 'brak Organization w danych strukturalnych';
        }
        if (!empty($f['contact_link']) && !empty($f['about_link'])) {
            $ok[] = 'linki do „Kontakt” i „O nas”';
        } else {
            $fail[] = 'brak ' . self::list(array_keys(array_filter(['linku do strony „Kontakt”' => empty($f['contact_link']), 'linku do strony „O nas”' => empty($f['about_link'])])));
        }
        $contact = $s['contact'] ?? null;
        $shared = [];
        if (is_array($contact) && !empty($contact['ids'])) {
            foreach (['nips' => 'NIP', 'phones' => 'telefon', 'emails' => 'e-mail'] as $k => $label) {
                if (array_intersect($f['footer_ids'][$k] ?? [], $contact['ids'][$k] ?? [])) {
                    $shared[] = $label;
                }
            }
        }
        if ($shared) {
            $ok[] = 'te same dane w stopce i na stronie kontaktowej (' . implode(', ', $shared) . ')';
        } else {
            $fail[] = empty($f['contact_link']) ? 'nie ma strony kontaktowej do porównania danych' : (is_array($contact) && isset($contact['error']) ? 'strona kontaktowa nie odpowiada' : 'w stopce nie ma danych firmy zgodnych ze stroną kontaktową');
        }
        $n = count($ok);
        return [$n === 3 ? 'good' : ($n === 2 ? 'mid' : 'bad'), $n . ' z 3' . ($fail ? ': ' . implode('; ', $fail) : ': ' . implode(', ', $ok)), $fail && $ok ? 'Spełnione: ' . implode('; ', $ok) . '.' : ''];
    }

    // --- D. Odpowiedzi (D1 i D2 ocenia AI, patrz AiChecks) -----------------------------------------------------------

    private static function checkD3(array $s): array
    {
        $f = $s['facts'];
        $topic = array_slice(array_values(array_unique(Text::content((string) ($s['phrase'] ?? '')))), 0, 5);
        if (!$topic) {
            return ['none', 'Brak frazy, z którą można porównać nazwę tematu'];
        }
        $need = (int) ceil(count($topic) * 0.6);
        $schemaName = '';
        foreach ($f['jsonld']['items'] ?? [] as $it) {
            if (array_intersect((array) ($it['@type'] ?? []), array_merge(self::ARTICLE_TYPES, ['Product', 'WebPage', 'Service', 'CollectionPage', 'ItemPage', 'AboutPage', 'ContactPage', 'WebSite'], self::ORG_TYPES))) {
                $schemaName .= ' ' . self::str($it['headline'] ?? '') . ' ' . self::str($it['name'] ?? '') . ' ' . self::str($it['description'] ?? '');
            }
        }
        $places = ['tytule' => (string) ($f['title'] ?? ''), 'H1' => (string) ($f['h1'][0] ?? ''), 'pierwszym akapicie' => (string) ($f['first_paragraph'] ?? ''), 'danych strukturalnych' => $schemaName];
        $missing = [];
        foreach ($places as $label => $text) {
            if (Text::hits($topic, $text) < $need) {
                $missing[] = $label;
            }
        }
        $n = 4 - count($missing);
        return [$n === 4 ? 'good' : ($n === 3 ? 'mid' : 'bad'),
            $n . ' z 4: ' . ($missing ? 'słów z frazy brakuje w ' . self::list($missing) : 'słowa z frazy są w tytule, H1, pierwszym akapicie i danych strukturalnych'),
            'Fraza: „' . $s['phrase'] . '”. Szukamy jej słów (' . implode(', ', $topic) . ') w dowolnej odmianie. Miejsce zaliczamy, gdy zawiera co najmniej ' . $need . ' z ' . count($topic) . '.'];
    }

    // --- E. Szybkość -------------------------------------------------------------------------------------------------

    /** Punkty za jedną metrykę Core Web Vitals: 2 = dobrze, 1 = do poprawy, 0 = źle. */
    public static function vital(string $metric, float $v): int
    {
        return match ($metric) {
            'lcp' => $v <= 2500 ? 2 : ($v <= 4000 ? 1 : 0),
            'inp' => $v <= 200 ? 2 : ($v <= 500 ? 1 : 0),
            default => $v <= 0.1 ? 2 : ($v <= 0.25 ? 1 : 0),
        };
    }

    private static function checkE1(array $s): array
    {
        $p = $s['psi'] ?? null;
        if (!is_array($p) || (!isset($p['lcp']) && !isset($p['inp']) && !isset($p['cls']))) {
            return ['none', 'Brak pomiaru szybkości', (string) ($p['error'] ?? 'PageSpeed Insights nie odpowiedział.')];
        }
        $points = 0;
        $max = 0;
        $parts = [];
        foreach (['lcp' => 'LCP', 'inp' => 'INP', 'cls' => 'CLS'] as $k => $label) {
            if (!isset($p[$k])) {
                continue;
            }
            $v = (float) $p[$k];
            $points += self::vital($k, $v);
            $max += 2;
            $parts[] = $label . ' ' . match ($k) {
                'lcp' => Text::num($v / 1000) . ' s',
                'inp' => (int) round($v) . ' ms',
                default => Text::num($v, 2),
            };
        }
        $status = $points === $max ? 'good' : ($points * 2 >= $max ? 'mid' : 'bad');
        $source = match ($p['source'] ?? 'field') {
            'lab' => 'Pomiar laboratoryjny, bo Google nie ma danych użytkowników dla tego adresu. INP nie da się zmierzyć w laboratorium, więc liczymy punkty z LCP i CLS.',
            'origin' => 'Dane użytkowników Chrome z 28 dni dla całej witryny (ten adres ma za mało odwiedzin), telefon.',
            default => 'Dane użytkowników Chrome z 28 dni, telefon.',
        };
        return [$status, implode('; ', $parts), $source, $points, $max];
    }

    private static function checkE2(array $s): array
    {
        if (empty($s['facts']['viewport'])) {
            return ['bad', 'Brak meta viewport'];
        }
        $scroll = $s['render']['scroll'] ?? null;
        if (!is_array($scroll) || count($scroll) !== 2 || (int) $scroll[1] <= 0) {
            return ['none', 'Meta viewport jest, przewijania przy 360 px nie zmierzono', (string) ($s['render']['error'] ?? 'Pomiar wymaga uruchomienia strony w przeglądarce. Usługa renderowania stron nie jest podłączona.')];
        }
        [$sw, $cw] = array_map('intval', $scroll);
        return $sw > $cw + 1
            ? ['mid', 'Meta viewport jest, ale strona przewija się w poziomie', 'Szerokość treści ' . $sw . ' px przy ekranie ' . $cw . ' px.']
            : ['good', 'Meta viewport jest, brak przewijania w poziomie'];
    }

    private static function checkE3(array $s): array
    {
        if (!str_starts_with((string) ($s['final_url'] ?? ''), 'https://')) {
            return ['bad', 'Strona działa po http, bez szyfrowania'];
        }
        if (!empty($s['ssl_error'])) {
            return ['bad', 'Błąd certyfikatu HTTPS'];
        }
        $issues = [];
        $http = $s['http'] ?? null;
        if (is_array($http) && (int) $http['status'] >= 200 && (int) $http['status'] < 300) {
            $issues[] = 'adres http nie przekierowuje na https';
        }
        $mixed = $s['facts']['mixed'] ?? [];
        if ($mixed) {
            $issues[] = count($mixed) . ' ' . plural(count($mixed), 'zasób ładuje się', 'zasoby ładują się', 'zasobów ładuje się') . ' po http';
        }
        if ($issues) {
            return ['mid', 'HTTPS działa, ale ' . implode(' i ', $issues), $mixed ? 'Np. ' . implode(', ', array_map([self::class, 'short'], array_slice($mixed, 0, 3))) . '.' : ''];
        }
        return ['good', 'HTTPS działa, brak zasobów po http'];
    }
}
