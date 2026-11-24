<?php

declare(strict_types=1);

namespace App\Modules\Ai;

use App\Core\Config;
use App\Core\Http;

/**
 * Klient Claude (Anthropic Messages API). Bez klucza ANTHROPIC_API_KEY funkcje AI są wyłączone,
 * a aplikacja korzysta z analizy regułowej treści ogłoszenia.
 */
final class Claude
{
    public const URL = 'https://api.anthropic.com/v1/messages';

    /** Firma i rodzaj użycia dla zapytań spoza zalogowanej sesji (czat w widżecie, zadania w tle). Null: bierzemy zalogowaną firmę. */
    private static ?array $scope = null;

    public static function enabled(): bool
    {
        return (string) Config::get('ANTHROPIC_API_KEY') !== '';
    }

    /**
     * Wykonuje funkcję z użyciami AI przypisanymi do podanej firmy. $kind: ops (funkcje panelu) albo chat (czat na stronie).
     * Potrzebne tam, gdzie nikt nie jest zalogowany: widżet na stronie klienta i zadania dzienne.
     */
    public static function as(int $orgId, string $kind, callable $fn): mixed
    {
        $before = self::$scope;
        self::$scope = [$orgId, $kind === 'chat' ? 'chat' : 'ops'];
        try {
            return $fn();
        } finally {
            self::$scope = $before;
        }
    }

    /** @return array{0: int, 1: string} firma i rodzaj użycia dla bieżącego zapytania */
    private static function scope(): array
    {
        return self::$scope ?? [\App\Core\Auth::orgId(), 'ops'];
    }

    /**
     * @param ?string $cachedContext Długa, powtarzalna część zapytania (dokumentacja przetargu, biblioteka firmy).
     *   Trafia na początek wiadomości z oznaczeniem pamięci podręcznej: kolejne zapytania o tę samą dokumentację
     *   w ciągu kilku minut (analiza, potem pytania) są rozliczane ułamkiem ceny.
     * @return array{ok: bool, text?: string, error?: string, usage?: array}
     */
    public static function complete(string $system, string $user, int $maxTokens = 4000, ?string $cachedContext = null): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'error' => 'Funkcje AI są wyłączone: brak klucza ANTHROPIC_API_KEY.'];
        }
        // Przydział AI z pakietu firmy. Zapytania bez firmy (test połączenia administratora, wiersz poleceń) nie są liczone.
        [$orgId, $kind] = self::scope();
        if ($orgId > 0 && !\App\Core\Plans::aiAllowed($orgId, $kind)) {
            return ['ok' => false, 'limit' => true, 'error' => \App\Core\Plans::aiLimitMessage($orgId)];
        }
        $model = \App\Core\Plans::model($orgId);
        $content = $user;
        if ($cachedContext !== null && trim($cachedContext) !== '') {
            $content = [
                ['type' => 'text', 'text' => $cachedContext, 'cache_control' => ['type' => 'ephemeral']],
                ['type' => 'text', 'text' => $user],
            ];
        }
        $r = Http::postJson(self::URL, [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $content]],
        ], [
            'x-api-key' => (string) Config::get('ANTHROPIC_API_KEY'),
            'anthropic-version' => '2023-06-01',
        ], 180);
        $j = json_decode($r['body'], true);
        if ($r['status'] !== 200 || !is_array($j)) {
            $msg = is_array($j) ? (string) ($j['error']['message'] ?? '') : ($r['error'] ?? '');
            return ['ok' => false, 'error' => 'Model AI nie odpowiedział poprawnie' . ($msg ? ': ' . $msg : '.')];
        }
        $text = '';
        foreach ((array) ($j['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }
        $usage = (array) ($j['usage'] ?? []);
        // Zużycie: licznik pakietu (firma, miesiąc) i wpis w dzienniku z liczbą tokenów.
        try {
            if ($orgId > 0) {
                \App\Core\Plans::aiRecord($orgId, $kind, $usage);
            }
            \App\Core\AuditLog::add('ai.call', null, null, [
                'model' => $model, 'kind' => $kind,
                'input' => (int) ($usage['input_tokens'] ?? 0),
                'cache_write' => (int) ($usage['cache_creation_input_tokens'] ?? 0),
                'cache_read' => (int) ($usage['cache_read_input_tokens'] ?? 0),
                'output' => (int) ($usage['output_tokens'] ?? 0),
            ]);
        } catch (\Throwable) {
            // brak dziennika nie może zablokować odpowiedzi
        }
        if (($j['stop_reason'] ?? '') === 'max_tokens') {
            return ['ok' => false, 'error' => 'Odpowiedź modelu była za długa i została ucięta. Spróbuj ponownie albo zadaj węższe pytanie.'];
        }
        // Jedna konwencja w całej aplikacji: zwykły łącznik zamiast pauzy i półpauzy, także w tekstach z AI
        // (oferty, wiadomości, analizy).
        $text = str_replace(["\u{2013}", "\u{2014}"], '-', $text);
        return ['ok' => true, 'text' => $text, 'usage' => $usage];
    }

    /** Wersja narzędzia wyszukiwarki w Messages API: podstawowa, wywoływana bezpośrednio przez model. */
    public const SEARCH_TOOL = 'web_search_20250305';

    /**
     * Zapytanie z wyszukiwarką internetową (narzędzie web search po stronie Anthropic): model sam szuka i odpowiada z podaniem źródeł.
     *
     * Zwracamy tekst odpowiedzi i listę stron, które wyszukiwarka naprawdę zwróciła. Dzięki tej liście wywołujący może odrzucić
     * adresy, których w wynikach nie było, czyli wymyślone przez model. Jedno wywołanie liczy się jako jedno użycie AI z pakietu,
     * a liczba wyszukiwań jest ograniczona parametrem $maxSearches (każde wyszukiwanie jest dodatkowo płatne u dostawcy).
     * Wywołanie jest napisane według dokumentacji narzędzia i sprawdzone na odpowiedziach w jej formacie.
     *
     * @return array{ok: bool, text?: string, sources?: array<string, array{title: string, age: string}>, quotes?: array<string, string>, searches?: int, usage?: array, error?: string, limit?: bool}
     */
    public static function search(string $system, string $user, int $maxSearches = 5, int $maxTokens = 4000): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'error' => 'Funkcje AI są wyłączone: brak klucza ANTHROPIC_API_KEY.'];
        }
        [$orgId, $kind] = self::scope();
        if ($orgId > 0 && !\App\Core\Plans::aiAllowed($orgId, $kind)) {
            return ['ok' => false, 'limit' => true, 'error' => \App\Core\Plans::aiLimitMessage($orgId)];
        }
        // Wyszukiwanie zawsze na głównym modelu: tańszy model z pakietu Podstawowego gorzej ocenia źródła.
        $model = (string) Config::get('AI_MODEL');
        $messages = [['role' => 'user', 'content' => $user]];
        $text = '';
        $sources = [];
        $quotes = [];
        $usage = ['input_tokens' => 0, 'output_tokens' => 0];
        $searches = 0;
        $stop = '';
        // Długie wyszukiwanie API może wstrzymać (pause_turn): odsyłamy wtedy odpowiedź bez zmian i model kończy turę.
        for ($round = 0; $round < 4; $round++) {
            $r = Http::postJson(self::URL, [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'system' => $system,
                'messages' => $messages,
                'tools' => [[
                    'type' => self::SEARCH_TOOL, 'name' => 'web_search', 'max_uses' => max(1, min(10, $maxSearches)),
                    'user_location' => ['type' => 'approximate', 'country' => 'PL', 'timezone' => 'Europe/Warsaw'],
                ]],
            ], [
                'x-api-key' => (string) Config::get('ANTHROPIC_API_KEY'),
                'anthropic-version' => '2023-06-01',
            ], 240);
            $j = json_decode($r['body'], true);
            if ($r['status'] !== 200 || !is_array($j)) {
                $msg = is_array($j) ? (string) ($j['error']['message'] ?? '') : ($r['error'] ?? '');
                if (stripos($msg, 'web search') !== false && stripos($msg, 'not enabled') !== false) {
                    return ['ok' => false, 'error' => 'Wyszukiwarka internetowa jest wyłączona na koncie dostawcy AI. Włącza ją administrator panelu w ustawieniach konta Anthropic.'];
                }
                return ['ok' => false, 'error' => 'Model AI nie odpowiedział poprawnie' . ($msg ? ': ' . $msg : '.')];
            }
            foreach ((array) ($j['content'] ?? []) as $block) {
                $type = (string) ($block['type'] ?? '');
                if ($type === 'text') {
                    $text .= (string) ($block['text'] ?? '');
                    foreach ((array) ($block['citations'] ?? []) as $c) {
                        if (is_array($c) && !empty($c['url']) && !empty($c['cited_text'])) {
                            $quotes[(string) $c['url']] ??= (string) $c['cited_text'];
                        }
                    }
                } elseif ($type === 'web_search_tool_result' && array_is_list((array) ($block['content'] ?? []))) {
                    foreach ((array) $block['content'] as $res) {
                        if (is_array($res) && ($res['type'] ?? '') === 'web_search_result' && !empty($res['url'])) {
                            $sources[(string) $res['url']] = ['title' => (string) ($res['title'] ?? ''), 'age' => (string) ($res['page_age'] ?? '')];
                        }
                    }
                }
            }
            $u = (array) ($j['usage'] ?? []);
            $usage['input_tokens'] += (int) ($u['input_tokens'] ?? 0) + (int) ($u['cache_read_input_tokens'] ?? 0) + (int) ($u['cache_creation_input_tokens'] ?? 0);
            $usage['output_tokens'] += (int) ($u['output_tokens'] ?? 0);
            $searches += (int) ($u['server_tool_use']['web_search_requests'] ?? 0);
            $stop = (string) ($j['stop_reason'] ?? '');
            if ($stop !== 'pause_turn') {
                break;
            }
            $messages[] = ['role' => 'assistant', 'content' => $j['content']];
        }
        try {
            if ($orgId > 0) {
                \App\Core\Plans::aiRecord($orgId, $kind, $usage);
            }
            \App\Core\AuditLog::add('ai.search', null, null, ['model' => $model, 'kind' => $kind, 'searches' => $searches, 'input' => $usage['input_tokens'], 'output' => $usage['output_tokens']]);
        } catch (\Throwable) {
            // brak dziennika nie może zablokować odpowiedzi
        }
        if ($stop === 'max_tokens') {
            return ['ok' => false, 'error' => 'Odpowiedź modelu była za długa i została ucięta. Zawęź opis tego, czego szukasz.'];
        }
        if ($stop === 'pause_turn') {
            return ['ok' => false, 'error' => 'Wyszukiwanie trwało zbyt długo i zostało przerwane. Spróbuj ponownie z węższym opisem.'];
        }
        $clean = static fn (string $t): string => str_replace(["\u{2013}", "\u{2014}"], '-', $t);
        return ['ok' => true, 'text' => $clean($text), 'sources' => $sources, 'quotes' => array_map($clean, $quotes), 'searches' => $searches, 'usage' => $usage];
    }

    /** Wyciąga obiekt JSON z odpowiedzi modelu (także gdy jest w bloku ```json). */
    public static function json(string $text): ?array
    {
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $m)) {
            $text = $m[1];
        } else {
            $a = strpos($text, '{');
            $b = strrpos($text, '}');
            if ($a !== false && $b !== false && $b > $a) {
                $text = substr($text, $a, $b - $a + 1);
            }
        }
        $j = json_decode($text, true);
        return is_array($j) ? $j : null;
    }
}
