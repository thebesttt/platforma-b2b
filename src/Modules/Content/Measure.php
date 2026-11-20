<?php

declare(strict_types=1);

namespace App\Modules\Content;

/**
 * Przebieg audytu treści jako lista kroków. Każdy krok dostaje stan i zwraca stan uzupełniony o swoje dane.
 * Kroki są krótkie (do ok. 30 s) i niezależne od bazy, więc panel wykonuje je po kolei, a przerwany audyt
 * wznawia się od ostatniego kroku. Ten sam kod uruchamia konsola (bin/console content:audit).
 */
final class Measure
{
    public const STEPS = [
        'fetch' => 'Pobranie strony, robots.txt i strony kontaktowej',
        'bots' => 'Odpowiedź serwera dla botów wyszukiwarek i AI',
        'render' => 'Strona po uruchomieniu skryptów (telefon 360 px)',
        'speed' => 'Szybkość u użytkowników (Core Web Vitals)',
        'ai' => 'Odpowiedź na pytania (ocena AI z cytatem)',
        'score' => 'Punkty według wytycznych',
    ];

    private const BLOCK_CODES = [401, 403, 406, 429, 451, 503];

    /**
     * @param array $ctx questions (zamrożona lista pytań), siblings (inne sprawdzone strony witryny), previous (dane poprzedniego audytu)
     */
    public static function start(string $url, string $phrase, string $pageType, array $ctx = []): array
    {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        return [
            'url' => $url, 'phrase' => Text::normalize($phrase), 'page_type' => isset(Ruleset::pageTypes()[$pageType]) ? $pageType : 'other',
            'ruleset' => Ruleset::version(), 'questions' => array_values($ctx['questions'] ?? []), 'siblings' => array_values($ctx['siblings'] ?? []),
            'previous' => $ctx['previous'] ?? null, 'results' => [],
        ];
    }

    public static function step(string $name, array $s): array
    {
        return match ($name) {
            'fetch' => self::fetch($s),
            'bots' => self::bots($s),
            'render' => self::render($s),
            'speed' => self::speed($s),
            'ai' => self::ai($s),
            'score' => self::score($s),
            default => throw new \InvalidArgumentException('Nieznany krok audytu: ' . $name),
        };
    }

    /** Cały audyt naraz (konsola, zadanie cykliczne, testy). */
    public static function run(string $url, string $phrase, string $pageType, array $ctx = []): array
    {
        $s = self::start($url, $phrase, $pageType, $ctx);
        foreach (array_keys(self::STEPS) as $name) {
            $s = self::step($name, $s);
        }
        return $s;
    }

    private static function fetch(array $s): array
    {
        $got = Fetcher::follow($s['url']);
        $r = $got['final'];
        $s['chain'] = $got['chain'];
        $s['loop'] = $got['loop'];
        $s['status'] = (int) $r['status'];
        $s['error'] = $r['error'] ?? null;
        $s['ssl_error'] = !empty($r['ssl_error']);
        $s['final_url'] = (string) (end($got['chain'])['url'] ?? $s['url']);
        $isHtml = $r['status'] >= 200 && $r['status'] < 300 && !$got['loop'] && trim((string) $r['body']) !== '';
        $s['reachable'] = $isHtml;
        $s['facts'] = PageFacts::extract($isHtml ? (string) $r['body'] : '<html><body></body></html>', $s['final_url'], $r['headers'] ?? []);
        $s['text_hash'] = $isHtml ? substr(hash('sha256', mb_strtolower(Text::normalize((string) $s['facts']['text']))), 0, 32) : null;
        $s['lead_hash'] = $isHtml ? substr(hash('sha256', mb_strtolower((string) $s['facts']['lead']) . '|' . mb_strtolower($s['phrase'])), 0, 32) : null;

        $p = parse_url($s['final_url']);
        $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
        $rb = Fetcher::follow($origin . '/robots.txt', null, 4)['final'];
        $s['robots'] = ['status' => (int) $rb['status'], 'text' => (int) $rb['status'] === 200 ? mb_substr((string) $rb['body'], 0, 100000) : ''];

        if (!$isHtml) {
            return $s;
        }
        // Czy adres http przekierowuje na https (jedno zapytanie, bez podążania dalej).
        if (str_starts_with($s['final_url'], 'https://')) {
            $h = Fetcher::get('http://' . substr($s['final_url'], 8), null, 8, 20000);
            $s['http'] = ['status' => (int) $h['status'], 'location' => $h['headers']['location'][0] ?? null];
        }
        // Dane firmy ze strony kontaktowej, do porównania ze stopką (wskaźnik C5).
        $contact = $s['facts']['contact_link'] ?? null;
        if ($contact) {
            if (Fetcher::canonicalForm($contact) === Fetcher::canonicalForm($s['final_url'])) {
                $s['contact'] = ['url' => $contact, 'ids' => $s['facts']['page_ids']];
            } else {
                $c = Fetcher::follow($contact, null, 4)['final'];
                $s['contact'] = $c['status'] >= 200 && $c['status'] < 300
                    ? ['url' => $contact, 'ids' => PageFacts::identifiers(html_entity_decode(strip_tags((string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', (string) $c['body'])), ENT_QUOTES | ENT_HTML5, 'UTF-8'))]
                    : ['url' => $contact, 'error' => 'Kod ' . $c['status']];
            }
        }
        return $s;
    }

    /** Pięć zapytań z nagłówkiem User-Agent botów. Bota zablokowanego w robots.txt nie udajemy. */
    private static function bots(array $s): array
    {
        if (empty($s['reachable'])) {
            return $s;
        }
        $p = parse_url($s['final_url']);
        $path = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
        $browserWords = (int) ($s['facts']['words'] ?? 0);
        $bots = [];
        foreach (Robots::SEARCH_BOTS as $name => $ua) {
            if ((int) $s['robots']['status'] === 200 && !Robots::check((string) $s['robots']['text'], $name, $path)['allowed']) {
                $bots[$name] = ['skipped' => true];
                continue;
            }
            $r = Fetcher::follow($s['final_url'], $ua, 3)['final'];
            $status = (int) $r['status'];
            $b = ['status' => $status, 'time' => (float) $r['time'], 'blocked' => false, 'reason' => ''];
            $challenge = !empty($r['headers']['cf-mitigated']) || preg_match('/Just a moment\.\.\.|Attention Required|cf-chl-|captcha|Access Denied|Pardon Our Interruption|Checking your browser/i', substr((string) $r['body'], 0, 20000));
            if ($status === 0) {
                $b['blocked'] = true;
                $b['reason'] = 'brak odpowiedzi';
            } elseif (in_array($status, self::BLOCK_CODES, true) || $status >= 400) {
                $b['blocked'] = true;
                $b['reason'] = 'kod ' . $status . (!empty($r['headers']['cf-mitigated']) ? ' (nagłówek cf-mitigated: ' . $r['headers']['cf-mitigated'][0] . ')' : '');
            } else {
                $words = (int) PageFacts::extract((string) $r['body'], $s['final_url'], $r['headers'] ?? [])['words'];
                $b['words'] = $words;
                if ($browserWords >= 50 && $words * 2 < $browserWords) {
                    $b['blocked'] = true;
                    $b['reason'] = $challenge ? 'stronę z testem zamiast treści' : 'inną treść niż przeglądarka (' . $words . ' zamiast ' . $browserWords . ' słów)';
                }
            }
            $bots[$name] = $b;
        }
        $s['bots'] = $bots;
        return $s;
    }

    private static function render(array $s): array
    {
        if (empty($s['reachable'])) {
            return $s;
        }
        $r = Services::render($s['final_url']);
        if (!isset($r['html'])) {
            $s['render'] = ['error' => $r['error'] ?? 'Brak odpowiedzi usługi renderowania.'];
            return $s;
        }
        $facts = PageFacts::extract($r['html'], $s['final_url']);
        $s['render'] = ['words' => (int) $facts['words'], 'h1' => count(array_filter($facts['h1'])) > 0] + (isset($r['scroll']) ? ['scroll' => $r['scroll']] : []);
        return $s;
    }

    private static function speed(array $s): array
    {
        if (!empty($s['reachable'])) {
            $s['psi'] = Services::pagespeed($s['final_url']);
        }
        return $s;
    }

    private static function ai(array $s): array
    {
        if (empty($s['reachable'])) {
            return $s;
        }
        $prev = $s['previous'] ?? null;
        $reuse = static fn (string $code, string $key, string $hash): ?array => is_array($prev) && isset($prev['ai'][$code]) && ($prev[$key] ?? null) === $hash
            && !in_array($prev['ai'][$code]['status'] ?? 'none', ['none', 'na'], true) ? $prev['ai'][$code] : null;

        // Ta sama treść i ta sama fraza dają tę samą ocenę: nie pytamy modelu drugi raz.
        $d1 = $reuse('D1', 'lead_hash', (string) $s['lead_hash']);
        $s['ai']['D1'] = $d1 ? ['reused' => true] + $d1 : AiChecks::d1($s['phrase'], (string) $s['facts']['lead']);

        $qHash = substr(hash('sha256', (string) $s['text_hash'] . '|' . json_encode($s['questions'], JSON_UNESCAPED_UNICODE)), 0, 32);
        $s['questions_hash'] = $qHash;
        $d2 = $reuse('D2', 'questions_hash', $qHash);
        $s['ai']['D2'] = $d2 ? ['reused' => true] + $d2 : AiChecks::d2($s['questions'], (string) $s['facts']['text']);
        return $s;
    }

    private static function score(array $s): array
    {
        if (empty($s['reachable'])) {
            // Strony nie da się pobrać: mierzalny jest tylko kod odpowiedzi. Wynik to wtedy 0, a nie ocena z samego robots.txt.
            $results = [];
            $all = Checks::all($s + ['facts' => PageFacts::extract('<html><body></body></html>', (string) ($s['final_url'] ?? $s['url']))]);
            foreach (Ruleset::rules() as $code => $rule) {
                $results[$code] = $code === 'A1' ? $all[$code] : Checks::result($rule, 'none', 'Nie zmierzono, bo strona nie odpowiada poprawnie');
            }
        } else {
            $results = Checks::all($s);
            foreach (['D1', 'D2'] as $code) {
                $results[$code] = $s['ai'][$code] ?? Checks::result(Ruleset::rule($code), 'none', 'Krok oceny AI nie został wykonany');
            }
            $ordered = [];
            foreach (array_keys(Ruleset::rules()) as $code) {
                $ordered[$code] = $results[$code];
            }
            $results = $ordered;
        }
        $s['results'] = $results;
        $s['totals'] = Ruleset::totals($results);
        return $s;
    }

    /**
     * „Najpierw to”: do 6 zadań. Najpierw warunki konieczne, potem dostęp botów, potem według punktów do zdobycia.
     * @return array<int, array{code: string, name: string, gain: int, fix: string, good: string, required: bool, status: string, value: string}>
     */
    public static function tasks(array $results, int $limit = 6): array
    {
        $tasks = [];
        foreach (Ruleset::rules() as $code => $rule) {
            $r = $results[$code] ?? null;
            if (!$r || !in_array($r['status'], ['bad', 'mid'], true)) {
                continue;
            }
            $tasks[] = [
                'code' => $code, 'name' => $rule['name'], 'gain' => (int) $r['max'] - (int) $r['points'], 'fix' => $rule['fix'], 'good' => $rule['good'],
                'required' => !empty($rule['required']), 'status' => $r['status'], 'value' => (string) $r['value'], 'group' => $rule['group'],
            ];
        }
        usort($tasks, static fn ($a, $b) => [($b['required'] && $b['status'] === 'bad'), $b['group'] === 'A', $b['gain'], $a['code']]
            <=> [($a['required'] && $a['status'] === 'bad'), $a['group'] === 'A', $a['gain'], $b['code']]);
        return array_slice($tasks, 0, $limit);
    }
}
