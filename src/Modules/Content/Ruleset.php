<?php

declare(strict_types=1);

namespace App\Modules\Content;

/**
 * Wytyczne audytu treści: 24 wskaźniki, 100 punktów, progi i podstawa każdego wskaźnika.
 * Jedno źródło prawdy to plik resources/audyt-ai-rules.json. Z niego powstaje punktacja w silniku,
 * strona „Wytyczne” w panelu i dokument dla zespołu (mockup/audyt-ai/WYTYCZNE.md).
 */
final class Ruleset
{
    public const FILE = '/resources/audyt-ai-rules.json';

    public const STATES = [
        'good' => ['label' => 'Dobrze', 'chip' => 'low'],
        'mid' => ['label' => 'Do poprawy', 'chip' => 'mid'],
        'bad' => ['label' => 'Źle', 'chip' => 'high'],
        'none' => ['label' => 'Brak pomiaru', 'chip' => 'neutral'],
        'na' => ['label' => 'Nie dotyczy', 'chip' => 'neutral'],
    ];

    private static ?array $data = null;

    public static function data(): array
    {
        if (self::$data === null) {
            $j = json_decode((string) file_get_contents(APP_ROOT . self::FILE), true);
            if (!is_array($j) || empty($j['rules'])) {
                throw new \RuntimeException('Nie można odczytać wytycznych audytu: ' . self::FILE);
            }
            self::$data = $j;
        }
        return self::$data;
    }

    public static function version(): string
    {
        return (string) self::data()['version'];
    }

    /** @return array<string, array> wskaźniki po kodzie, w kolejności z pliku */
    public static function rules(): array
    {
        return array_column(self::data()['rules'], null, 'code');
    }

    public static function rule(string $code): ?array
    {
        return self::rules()[$code] ?? null;
    }

    public static function groups(): array
    {
        return self::data()['groups'];
    }

    public static function levels(): array
    {
        return self::data()['levels'];
    }

    public static function sources(): array
    {
        return self::data()['sources'];
    }

    public static function unscored(): array
    {
        return self::data()['unscored'];
    }

    /** @return array<string, string> rodzaje stron: klucz => nazwa */
    public static function pageTypes(): array
    {
        return self::data()['page_types'];
    }

    public static function applies(array $rule, string $pageType): bool
    {
        return empty($rule['applies']) || in_array($pageType, $rule['applies'], true);
    }

    /** Punkty za stan wskaźnika. Stany „brak pomiaru” i „nie dotyczy” nie dają punktów i nie liczą się do maksimum. */
    public static function points(array $rule, string $status): int
    {
        return $status === 'good' ? (int) $rule['max'] : ($status === 'mid' ? (int) $rule['partial'] : 0);
    }

    public static function level(int $score): string
    {
        $l = self::levels();
        return $score >= (int) $l['good'] ? 'good' : ($score >= (int) $l['mid'] ? 'mid' : 'bad');
    }

    /**
     * Wynik 0-100 z wyników wskaźników. Punkty, których nie dało się zmierzyć albo które nie dotyczą tego rodzaju strony,
     * odejmujemy od maksimum i liczymy wynik proporcjonalnie z pozostałych.
     *
     * @param array<string, array{status: string, points: int, max: int}> $results
     * @return array{score: ?int, points: int, max: int, groups: array, blocked: string[], unmeasured: string[]}
     */
    public static function totals(array $results): array
    {
        $groups = [];
        foreach (self::groups() as $g) {
            $groups[$g['id']] = ['points' => 0, 'max' => 0, 'full' => 0];
        }
        $points = 0;
        $max = 0;
        $blocked = [];
        $unmeasured = [];
        foreach (self::rules() as $code => $rule) {
            $res = $results[$code] ?? ['status' => 'none', 'points' => 0, 'max' => 0];
            $groups[$rule['group']]['full'] += (int) $rule['max'];
            if (in_array($res['status'], ['none', 'na'], true) && (int) ($res['max'] ?? 0) === 0) {
                if ($res['status'] === 'none') {
                    $unmeasured[] = $code;
                }
                continue;
            }
            $points += (int) $res['points'];
            $max += (int) $res['max'];
            $groups[$rule['group']]['points'] += (int) $res['points'];
            $groups[$rule['group']]['max'] += (int) $res['max'];
            if (!empty($rule['required']) && $res['status'] === 'bad') {
                $blocked[] = $code;
            }
        }
        return [
            'score' => $max > 0 ? (int) round(100 * $points / $max) : null,
            'points' => $points, 'max' => $max, 'groups' => $groups, 'blocked' => $blocked, 'unmeasured' => $unmeasured,
        ];
    }
}
