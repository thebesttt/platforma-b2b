<?php

declare(strict_types=1);

namespace App\Modules\Content;

/**
 * Czytanie robots.txt według RFC 9309: grupy „User-agent”, reguły Allow i Disallow, znaki * i $.
 * Wygrywa najdłuższa pasująca reguła, przy remisie Allow. Bot bez własnej grupy podlega grupie „*”.
 */
final class Robots
{
    /** Boty, które budują wyniki wyszukiwania i odpowiedzi AI (wskaźniki A4 i A5). */
    public const SEARCH_BOTS = [
        'Googlebot' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        'Bingbot' => 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
        'OAI-SearchBot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot',
        'PerplexityBot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)',
        'Claude-SearchBot' => 'Mozilla/5.0 (compatible; Claude-SearchBot/1.0; +https://www.anthropic.com/claude-searchbot)',
    ];

    /** Boty treningowe: pokazujemy je bez punktów, bo ich blokada nie wpływa na wyszukiwanie. */
    public const TRAINING_BOTS = ['GPTBot', 'ClaudeBot', 'Google-Extended'];

    /**
     * @return array<int, array{agents: string[], rules: array<int, array{allow: bool, path: string, line: int}>}>
     */
    public static function parse(string $txt): array
    {
        $groups = [];
        $current = null;
        $lastWasAgent = false;
        foreach (preg_split('/\R/u', $txt) ?: [] as $i => $raw) {
            $line = trim((string) preg_replace('/#.*$/', '', $raw));
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $key = strtolower($key);
            if ($key === 'user-agent') {
                if (!$lastWasAgent || $current === null) {
                    $groups[] = ['agents' => [], 'rules' => []];
                    $current = count($groups) - 1;
                }
                $groups[$current]['agents'][] = strtolower($value);
                $lastWasAgent = true;
                continue;
            }
            if (($key === 'allow' || $key === 'disallow') && $current !== null) {
                // Pusta reguła Disallow niczego nie blokuje.
                if ($value !== '') {
                    $groups[$current]['rules'][] = ['allow' => $key === 'allow', 'path' => $value, 'line' => $i + 1];
                }
            }
            if ($key !== 'user-agent') {
                $lastWasAgent = false;
            }
        }
        return $groups;
    }

    /**
     * Czy bot o podanej nazwie może pobrać ścieżkę.
     * @return array{allowed: bool, line: ?int, rule: ?string, group: ?string}
     */
    public static function check(string $txt, string $agent, string $path): array
    {
        $agent = strtolower($agent);
        $own = [];
        $star = [];
        $matched = false;
        foreach (self::parse($txt) as $g) {
            foreach ($g['agents'] as $a) {
                if ($a === '*') {
                    $star = array_merge($star, $g['rules']);
                } elseif ($a === $agent) {
                    // Nazwę bota porównujemy w całości: grupa „bot” nie dotyczy bota „Googlebot”.
                    $own = array_merge($own, $g['rules']);
                    $matched = true;
                }
            }
        }
        $rules = $matched ? $own : $star;
        $group = $matched ? $agent : ($star ? '*' : null);
        $best = null;
        foreach ($rules as $r) {
            if (!self::matches($r['path'], $path)) {
                continue;
            }
            $len = strlen($r['path']);
            if ($best === null || $len > strlen($best['path']) || ($len === strlen($best['path']) && $r['allow'] && !$best['allow'])) {
                $best = $r;
            }
        }
        if ($best === null) {
            return ['allowed' => true, 'line' => null, 'rule' => null, 'group' => $group];
        }
        return ['allowed' => $best['allow'], 'line' => $best['line'], 'rule' => ($best['allow'] ? 'Allow: ' : 'Disallow: ') . $best['path'], 'group' => $group];
    }

    private static function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }
        $re = '';
        foreach (explode('*', $pattern) as $i => $part) {
            $re .= ($i > 0 ? '.*' : '') . preg_quote($part, '#');
        }
        return (bool) preg_match('#^' . $re . ($anchored ? '$' : '') . '#', $path);
    }
}
