<?php

declare(strict_types=1);

namespace App\Modules\Content;

/**
 * Zamienia kod HTML strony na fakty, z których liczymy wskaźniki: tytuł, nagłówki, akapity treści głównej,
 * zdjęcia, linki, dane strukturalne, autor, daty. Nic tu nie jest oceniane i nic nie pochodzi z AI.
 */
final class PageFacts
{
    /** Elementy, które nie są treścią główną. */
    private const SKIP = ['script', 'style', 'noscript', 'template', 'svg', 'nav', 'aside', 'form', 'footer', 'iframe', 'button', 'select', 'dialog', 'canvas', 'object'];

    /** Elementy, które rozdzielają tekst na bloki. */
    private const BLOCK = ['p', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'td', 'th', 'blockquote', 'figcaption', 'dd', 'dt', 'pre', 'summary', 'caption', 'address',
        'div', 'section', 'article', 'main', 'header', 'ul', 'ol', 'table', 'thead', 'tbody', 'tr', 'dl', 'figure', 'details', 'hr'];

    private const MONTHS = ['stycznia' => 1, 'lutego' => 2, 'marca' => 3, 'kwietnia' => 4, 'maja' => 5, 'czerwca' => 6, 'lipca' => 7, 'sierpnia' => 8,
        'września' => 9, 'października' => 10, 'listopada' => 11, 'grudnia' => 12];

    public static function extract(string $html, string $url, array $headers = []): array
    {
        $html = self::utf8($html, $headers);
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $x = new \DOMXPath($doc);
        $lc = "translate(%s, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')";
        $attr = static function (string $query) use ($x): ?string {
            $n = $x->query($query);
            return $n && $n->length ? Text::normalize((string) $n->item(0)->nodeValue) : null;
        };

        $f = ['url' => $url];
        $f['title'] = $attr('//head/title') ?? $attr('//title');
        $f['description'] = $attr('//meta[' . sprintf($lc, '@name') . "='description']/@content");
        $f['lang'] = $attr('/html/@lang');
        $f['viewport'] = ($v = $attr('//meta[' . sprintf($lc, '@name') . "='viewport']/@content")) !== null && str_contains(strtolower($v), 'width=');

        // Dyrektywy dla robotów: znaczniki meta i nagłówek X-Robots-Tag (także z nazwą bota na początku).
        $robots = [];
        foreach ($x->query('//meta[' . sprintf($lc, '@name') . "='robots' or " . sprintf($lc, '@name') . "='googlebot' or " . sprintf($lc, '@name') . "='bingbot']/@content") ?: [] as $n) {
            $robots[] = (string) $n->nodeValue;
        }
        foreach ((array) ($headers['x-robots-tag'] ?? []) as $h) {
            $robots[] = (string) preg_replace('/^\s*[a-z0-9\-_]+bot[a-z0-9\-_]*\s*:/i', '', (string) $h);
        }
        $f['robots'] = array_values(array_unique(array_filter(array_map(static fn ($d) => strtolower(trim($d)), explode(',', implode(',', $robots))))));

        $canon = $attr("//link[contains(concat(' ', " . sprintf($lc, 'normalize-space(@rel)') . ", ' '), ' canonical ')]/@href");
        $f['canonical'] = $canon !== null && $canon !== '' ? Fetcher::absolute($url, $canon) : null;

        // Nagłówki H1 liczymy na całej stronie, bo bywają w nagłówku strony poza treścią główną.
        $f['h1'] = [];
        foreach ($x->query('//h1') ?: [] as $n) {
            $f['h1'][] = Text::normalize($n->textContent);
        }

        $f['jsonld'] = self::jsonld($x);
        $f['mixed'] = [];
        foreach ($x->query('//img/@src | //script/@src | //link[' . sprintf($lc, '@rel') . "='stylesheet']/@href | //iframe/@src | //source/@src | //video/@src | //audio/@src") ?: [] as $n) {
            if (stripos(trim((string) $n->nodeValue), 'http://') === 0) {
                $f['mixed'][] = mb_substr(trim((string) $n->nodeValue), 0, 200);
            }
        }
        $f['mixed'] = array_slice(array_values(array_unique($f['mixed'])), 0, 10);

        // Linki wewnętrzne z całej strony (menu i stopka): szukamy stron „Kontakt” i „O nas”.
        $host = Fetcher::host($url);
        $f['contact_link'] = null;
        $f['about_link'] = null;
        foreach ($x->query('//a[@href]') ?: [] as $a) {
            $href = Fetcher::absolute($url, trim((string) $a->getAttribute('href')));
            if ($href === null || !Fetcher::sameSite($host, Fetcher::host($href))) {
                continue;
            }
            $label = mb_strtolower(Text::normalize($a->textContent)) . ' ' . strtolower((string) parse_url($href, PHP_URL_PATH));
            if ($f['contact_link'] === null && preg_match('/\bkontakt|contact/u', $label)) {
                $f['contact_link'] = $href;
            }
            if ($f['about_link'] === null && preg_match('/o[ -]nas\b|o[ -]firmie|kim[ -]jeste[sś]my|about|nasz[ -]zesp[oó][lł]|\/zespol|o[ -]mnie/u', $label)) {
                $f['about_link'] = $href;
            }
        }

        $footer = '';
        foreach ($x->query('//footer') ?: [] as $n) {
            $footer .= ' ' . $n->textContent;
        }
        $body = $x->query('//body')->item(0);
        $bodyText = $body ? Text::normalize($body->textContent) : '';
        $f['footer_ids'] = self::identifiers($footer !== '' ? $footer : mb_substr($bodyText, -2500));
        $f['page_ids'] = self::identifiers($bodyText);

        // Treść główna: <main>, element z rolą main, największy <article>, a w ostateczności <body>.
        $main = $x->query('//main')->item(0) ?? $x->query("//*[@role='main']")->item(0);
        if (!$main) {
            $best = 0;
            foreach ($x->query('//article') ?: [] as $a) {
                $len = mb_strlen($a->textContent);
                if ($len > $best) {
                    $best = $len;
                    $main = $a;
                }
            }
        }
        $f['main_source'] = $main ? strtolower($main->nodeName) : 'body';
        $main = ($main ?? $body);
        $blocks = [];
        if ($main) {
            $buf = '';
            self::blocks($main, $blocks, $buf, 'div');
            self::flush($blocks, $buf, 'div');
        }
        $f['headings'] = [];
        $texts = [];
        $paragraphs = [];
        $afterH1 = [];
        $seenH1 = false;
        foreach ($blocks as [$tag, $text]) {
            if (preg_match('/^h([1-6])$/', $tag, $m)) {
                $f['headings'][] = [(int) $m[1], mb_substr($text, 0, 200)];
                if ($tag === 'h1') {
                    $seenH1 = true;
                    $afterH1 = [];
                    continue;
                }
            } else {
                $paragraphs[] = Text::count($text);
            }
            $texts[] = $text;
            if ($seenH1) {
                $afterH1[] = $text;
            }
        }
        $text = implode("\n", $texts);
        $f['words'] = Text::count($text);
        $f['text'] = mb_substr($text, 0, 60000);
        $f['paragraphs'] = array_values(array_filter($paragraphs));
        // Początek treści: pierwsze 300 słów po H1 (albo od początku treści, gdy H1 jest poza nią).
        $leadSource = implode("\n", $seenH1 && $afterH1 ? $afterH1 : $texts);
        $f['lead'] = self::firstWords($leadSource, 300);
        $f['first_paragraph'] = '';
        foreach ($seenH1 && $afterH1 ? $afterH1 : $texts as $t) {
            if (Text::count($t) >= 8 && !in_array($t, array_column($f['headings'], 1), true)) {
                $f['first_paragraph'] = mb_substr($t, 0, 600);
                break;
            }
        }

        $mx = $main ? $x : null;
        $f['images'] = ['total' => 0, 'with_alt' => 0];
        $f['pdf_links'] = 0;
        $f['external'] = [];
        $f['nosnippet_words'] = 0;
        if ($main && $mx) {
            foreach ($mx->query('.//img', $main) ?: [] as $img) {
                if (self::skipped($img) || $img->getAttribute('role') === 'presentation' || $img->getAttribute('aria-hidden') === 'true'
                    || in_array($img->getAttribute('width'), ['0', '1'], true) || in_array($img->getAttribute('height'), ['0', '1'], true)) {
                    continue;
                }
                $f['images']['total']++;
                if (trim($img->getAttribute('alt')) !== '') {
                    $f['images']['with_alt']++;
                }
            }
            foreach ($mx->query('.//a[@href]', $main) ?: [] as $a) {
                if (self::skipped($a)) {
                    continue;
                }
                $href = Fetcher::absolute($url, trim((string) $a->getAttribute('href')));
                if ($href === null) {
                    continue;
                }
                if (preg_match('/\.pdf(\?|#|$)/i', $href)) {
                    $f['pdf_links']++;
                }
                $h = Fetcher::host($href);
                if ($h !== '' && !Fetcher::sameSite($host, $h) && !self::notASource($h)) {
                    $f['external'][] = mb_substr(preg_replace('/#.*$/', '', $href), 0, 300);
                }
            }
            foreach ($mx->query('.//*[@data-nosnippet]', $main) ?: [] as $n) {
                if (!self::skipped($n)) {
                    $f['nosnippet_words'] += Text::count($n->textContent);
                }
            }
        }
        $f['external'] = array_slice(array_values(array_unique($f['external'])), 0, 60);

        $f['author'] = self::author($x, $text, $url);
        $f['dates'] = self::dates($x, $text);
        return $f;
    }

    private static function utf8(string $html, array $headers): string
    {
        $charset = '';
        if (preg_match('/charset=["\']?([a-z0-9_\-]+)/i', implode(' ', (array) ($headers['content-type'] ?? [])), $m)) {
            $charset = $m[1];
        } elseif (preg_match('/<meta[^>]+charset=["\']?\s*([a-z0-9_\-]+)/i', substr($html, 0, 4096), $m)) {
            $charset = $m[1];
        }
        $charset = strtoupper($charset);
        if ($charset !== '' && $charset !== 'UTF-8' && $charset !== 'UTF8') {
            // iconv zna strony kodowe Windows (np. windows-1250 na starszych polskich stronach), mbstring nie wszystkie.
            $converted = function_exists('iconv') ? @iconv($charset, 'UTF-8//IGNORE', $html) : false;
            if (!is_string($converted) || $converted === '') {
                try {
                    $converted = mb_convert_encoding($html, 'UTF-8', $charset);
                } catch (\Throwable) {
                    $converted = false;
                }
            }
            if (is_string($converted) && $converted !== '') {
                // Po zamianie na UTF-8 poprawiamy też deklarację w kodzie strony, inaczej parser zdekoduje tekst drugi raz.
                $html = (string) preg_replace('/(<meta[^>]+charset=["\']?)\s*[a-z0-9_\-]+/i', '$1utf-8', $converted, 1);
            }
        }
        return mb_check_encoding($html, 'UTF-8') ? $html : mb_scrub($html, 'UTF-8');
    }

    private static function skipped(\DOMNode $n): bool
    {
        for ($p = $n; $p instanceof \DOMElement; $p = $p->parentNode) {
            if (in_array(strtolower($p->nodeName), self::SKIP, true)) {
                return true;
            }
        }
        return false;
    }

    /** Dzieli treść na bloki tekstu w kolejności ze strony. @param array<int, array{0: string, 1: string}> $out */
    private static function blocks(\DOMNode $node, array &$out, string &$buf, string $tag): void
    {
        foreach ($node->childNodes as $c) {
            if ($c instanceof \DOMText) {
                $buf .= $c->nodeValue;
                continue;
            }
            if (!$c instanceof \DOMElement) {
                continue;
            }
            $name = strtolower($c->nodeName);
            if (in_array($name, self::SKIP, true) || ($name === 'header' && self::pageHeader($c))) {
                continue;
            }
            if ($name === 'br') {
                $buf .= ' ';
            } elseif (in_array($name, self::BLOCK, true)) {
                self::flush($out, $buf, $tag);
                $inner = '';
                self::blocks($c, $out, $inner, $name);
                self::flush($out, $inner, $name);
            } else {
                self::blocks($c, $out, $buf, $tag);
            }
        }
    }

    /** <header> całej strony to menu i logo. <header> wewnątrz artykułu albo sekcji zwykle zawiera H1 i podpis autora. */
    private static function pageHeader(\DOMElement $header): bool
    {
        for ($p = $header->parentNode; $p instanceof \DOMElement; $p = $p->parentNode) {
            if (in_array(strtolower($p->nodeName), ['article', 'main', 'section'], true)) {
                return false;
            }
        }
        return true;
    }

    private static function flush(array &$out, string &$buf, string $tag): void
    {
        $t = Text::normalize($buf);
        $buf = '';
        if ($t !== '') {
            $out[] = [$tag, $t];
        }
    }

    private static function firstWords(string $text, int $n): string
    {
        if (!preg_match_all('/\S+/u', $text, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $count = 0;
        foreach ($m[0] as [$token, $offset]) {
            if (preg_match('/[\p{L}\p{N}]/u', $token) && ++$count > $n) {
                return rtrim(substr($text, 0, $offset));
            }
        }
        return $text;
    }

    /** Dane strukturalne JSON-LD: lista obiektów z @type i liczba bloków z błędem składni. */
    private static function jsonld(\DOMXPath $x): array
    {
        $items = [];
        $errors = [];
        $blocks = 0;
        foreach ($x->query("//script[translate(@type, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')='application/ld+json']") ?: [] as $s) {
            $raw = trim((string) $s->textContent);
            if ($raw === '') {
                continue;
            }
            $blocks++;
            $j = json_decode($raw, true);
            if (!is_array($j)) {
                $errors[] = mb_substr(Text::normalize($raw), 0, 120);
                continue;
            }
            self::collect($j, $items, 0);
        }
        $types = [];
        foreach ($items as $it) {
            foreach ((array) $it['@type'] as $t) {
                $types[] = (string) $t;
            }
        }
        return ['blocks' => $blocks, 'errors' => $errors, 'items' => array_slice($items, 0, 40), 'types' => array_values(array_unique($types))];
    }

    private static function collect(array $node, array &$items, int $depth): void
    {
        if ($depth > 6) {
            return;
        }
        if (array_is_list($node)) {
            foreach ($node as $n) {
                if (is_array($n)) {
                    self::collect($n, $items, $depth + 1);
                }
            }
            return;
        }
        if (isset($node['@graph']) && is_array($node['@graph'])) {
            self::collect($node['@graph'], $items, $depth + 1);
        }
        if (isset($node['@type'])) {
            $items[] = $node;
        }
        // Obiekty zagnieżdżone (autor, wydawca, oferta) też zbieramy, żeby dało się je znaleźć po typie.
        foreach (['author', 'publisher', 'offers', 'mainEntity', 'mainEntityOfPage', 'isPartOf', 'about'] as $k) {
            if (isset($node[$k]) && is_array($node[$k])) {
                self::collect($node[$k], $items, $depth + 1);
            }
        }
    }

    /** E-maile, telefony i NIP z tekstu: po nich porównujemy stopkę ze stroną kontaktową. */
    public static function identifiers(string $text): array
    {
        preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $text, $e);
        preg_match_all('/(?<![\d])(?:\+?48[ \-]?)?(\d{3}[ \-]?\d{3}[ \-]?\d{3}|\d{2}[ \-]?\d{3}[ \-]?\d{2}[ \-]?\d{2})(?![\d])/', $text, $p);
        preg_match_all('/NIP[:\s]*((?:PL)?[\d \-]{10,13})/iu', $text, $n);
        $digits = static fn (array $a): array => array_values(array_unique(array_filter(array_map(static fn ($v) => preg_replace('/\D/', '', (string) $v), $a))));
        return [
            'emails' => array_slice(array_values(array_unique(array_map('strtolower', $e[0]))), 0, 10),
            'phones' => array_slice(array_values(array_filter($digits($p[1]), static fn ($d) => strlen($d) === 9)), 0, 10),
            'nips' => array_slice(array_values(array_filter($digits($n[1]), static fn ($d) => strlen($d) === 10)), 0, 5),
        ];
    }

    private static function notASource(string $host): bool
    {
        return (bool) preg_match('/(^|\.)(facebook|instagram|twitter|x|linkedin|youtube|youtu|tiktok|pinterest|whatsapp|wa|messenger|t|telegram|snapchat|threads'
            . '|allegro|amazon|ceneo|olx|ebay|aliexpress|empik|skapiec|zalando|temu|shopee|etsy'
            . '|google|goo|bing|apple|play)\.(com|pl|me|be|net|co|gl|de|co\.uk)$/', $host);
    }

    /** Widoczny podpis autora i link do strony o autorze. */
    private static function author(\DOMXPath $x, string $mainText, string $url): array
    {
        $name = null;
        $link = null;
        $isName = static fn (string $s): bool => (bool) preg_match('/^\p{Lu}[\p{Ll}\'’]+(?:[ \-]\p{Lu}[\p{Ll}\'’]+){1,3}$/u', $s);
        $queries = [
            "//*[contains(concat(' ', normalize-space(@rel), ' '), ' author ')]",
            "//*[@itemprop='author']",
            "//*[contains(@class, 'author') or contains(@class, 'autor') or contains(@class, 'byline')]",
        ];
        foreach ($queries as $q) {
            foreach ($x->query($q) ?: [] as $n) {
                if (!$n instanceof \DOMElement || in_array(strtolower($n->nodeName), ['meta', 'link', 'script', 'body', 'html'], true)) {
                    continue;
                }
                $t = Text::normalize((string) preg_replace('/^\s*(autor(ka)?|napisa[lł]a?|tekst|opracowa[lł]a?|przez|by)\s*:?\s+/iu', '', Text::normalize($n->textContent)));
                $candidate = null;
                if ($isName($t)) {
                    $candidate = $t;
                } elseif (preg_match('/\p{Lu}[\p{Ll}\'’]+(?:[ \-]\p{Lu}[\p{Ll}\'’]+){1,3}/u', mb_substr($t, 0, 160), $m)) {
                    $candidate = $m[0];
                }
                if ($candidate === null) {
                    continue;
                }
                $name ??= $candidate;
                $a = strtolower($n->nodeName) === 'a' ? $n : $x->query('.//a[@href]', $n)->item(0);
                if ($a instanceof \DOMElement && $a->getAttribute('href') !== '' && $link === null) {
                    $link = Fetcher::absolute($url, $a->getAttribute('href'));
                }
            }
            if ($name !== null) {
                break;
            }
        }
        if ($name === null && preg_match('/\b(?:Autor(?:ka)?|Napisa[lł]a?|Opracowa[lł]a?)\s*:\s*(\p{Lu}[\p{Ll}\'’]+(?:[ \-]\p{Lu}[\p{Ll}\'’]+){1,3})/u', $mainText, $m)) {
            $name = $m[1];
        }
        if ($name !== null && $link === null) {
            foreach ($x->query('//a[@href]') ?: [] as $a) {
                if (Text::normalize($a->textContent) === $name) {
                    $link = Fetcher::absolute($url, $a->getAttribute('href'));
                    break;
                }
            }
        }
        return ['name' => $name, 'link' => $link];
    }

    /** Widoczne daty: znaczniki <time> i daty zapisane w treści. @return string[] daty w zapisie RRRR-MM-DD */
    private static function dates(\DOMXPath $x, string $mainText): array
    {
        $out = [];
        foreach ($x->query('//time[@datetime]') ?: [] as $t) {
            if (!self::skipped($t) && preg_match('/^(20\d{2})-(\d{2})-(\d{2})/', trim($t->getAttribute('datetime')), $m) && trim($t->textContent) !== '') {
                $out[] = "$m[1]-$m[2]-$m[3]";
            }
        }
        if (preg_match_all('/(?<!\d)(\d{1,2})[.\/](\d{1,2})[.\/](20\d{2})(?!\d)/', $mainText, $m, PREG_SET_ORDER)) {
            foreach ($m as $d) {
                if (checkdate((int) $d[2], (int) $d[1], (int) $d[3])) {
                    $out[] = sprintf('%04d-%02d-%02d', $d[3], $d[2], $d[1]);
                }
            }
        }
        if (preg_match_all('/(?<!\d)(20\d{2})-(\d{2})-(\d{2})(?!\d)/', $mainText, $m, PREG_SET_ORDER)) {
            foreach ($m as $d) {
                if (checkdate((int) $d[2], (int) $d[3], (int) $d[1])) {
                    $out[] = "$d[1]-$d[2]-$d[3]";
                }
            }
        }
        if (preg_match_all('/(?<!\d)(\d{1,2}) (' . implode('|', array_keys(self::MONTHS)) . ') (20\d{2})(?!\d)/u', mb_strtolower($mainText), $m, PREG_SET_ORDER)) {
            foreach ($m as $d) {
                $out[] = sprintf('%04d-%02d-%02d', $d[3], self::MONTHS[$d[2]], $d[1]);
            }
        }
        return array_slice(array_values(array_unique($out)), 0, 12);
    }
}
