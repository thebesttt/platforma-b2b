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

    /** @return array{ok: bool, text?: string, error?: string, usage?: array} */
    public static function complete(string $system, string $user, int $maxTokens = 4000): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'error' => 'Funkcje AI są wyłączone: brak klucza ANTHROPIC_API_KEY.'];
        }
        $r = Http::postJson(self::URL, [
            'model' => (string) Config::get('AI_MODEL'),
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $user]],
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
        return ['ok' => true, 'text' => $text, 'usage' => $j['usage'] ?? []];
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
