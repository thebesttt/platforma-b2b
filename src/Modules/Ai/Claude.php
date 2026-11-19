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

    public static function enabled(): bool
    {
        return (string) Config::get('ANTHROPIC_API_KEY') !== '';
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
        $content = $user;
        if ($cachedContext !== null && trim($cachedContext) !== '') {
            $content = [
                ['type' => 'text', 'text' => $cachedContext, 'cache_control' => ['type' => 'ephemeral']],
                ['type' => 'text', 'text' => $user],
            ];
        }
        $r = Http::postJson(self::URL, [
            'model' => (string) Config::get('AI_MODEL'),
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
        // Zużycie zapisujemy w dzienniku - podstawa do kontroli kosztów na firmę.
        try {
            \App\Core\AuditLog::add('ai.call', null, null, [
                'model' => (string) Config::get('AI_MODEL'),
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
        return ['ok' => true, 'text' => $text, 'usage' => $usage];
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
