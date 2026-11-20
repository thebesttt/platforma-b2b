<?php

declare(strict_types=1);

namespace App\Modules\Inbound;

/**
 * Zasady kwalifikacji zapytań: pytania, odpowiedzi z punktami, punkty za telefon i progi.
 * Ocenę liczy program z tej tabeli. Te same odpowiedzi dają zawsze tę samą ocenę, a AI jej nie zmienia.
 * Komplet punktów to zawsze 100, żeby oceny były porównywalne po zmianie pytań.
 */
final class Rules
{
    public const TOTAL = 100;

    public const LEVELS = [
        'hot' => ['label' => 'Pilne', 'chip' => 'info'],
        'talk' => ['label' => 'Do rozmowy', 'chip' => 'low'],
        'low' => ['label' => 'Niski priorytet', 'chip' => 'neutral'],
    ];

    /** Zasady startowe: 4 pytania i telefon, progi wynikają z cennika usług. Firma zmienia je w ustawieniach. */
    public static function defaults(): array
    {
        return [
            'questions' => [
                ['label' => 'Potrzeba', 'text' => 'W czym możemy pomóc?', 'options' => [
                    ['SEO i GEO: widoczność w Google i w odpowiedziach AI', 25], ['Google Ads', 25], ['Meta Ads', 20],
                    ['Strona lub landing page', 20], ['Jeszcze nie wiem, potrzebuję diagnozy', 10],
                ]],
                ['label' => 'Rodzaj firmy', 'text' => 'Co sprzedajesz?', 'options' => [
                    ['Produkty lub usługi dla firm', 15], ['Prowadzę sklep internetowy', 15], ['Usługi dla klientów indywidualnych', 10], ['Coś innego', 5],
                ]],
                ['label' => 'Termin', 'text' => 'Kiedy chcesz zacząć?', 'options' => [
                    ['Od razu', 25], ['W ciągu miesiąca', 20], ['W tym kwartale', 10], ['Na razie się rozglądam', 0],
                ]],
                ['label' => 'Budżet', 'text' => 'Jaki miesięczny budżet na obsługę przewidujesz? Bez budżetu na reklamy.', 'options' => [
                    ['Powyżej 5000 zł', 25], ['2000-5000 zł', 20], ['1000-2000 zł', 15], ['Poniżej 1000 zł', 5], ['Jeszcze nie wiem', 10],
                ]],
            ],
            'phone_points' => 10,
            'levels' => ['hot' => 70, 'talk' => 40],
        ];
    }

    /** Największa możliwa ocena: najwyżej punktowana odpowiedź w każdym pytaniu plus telefon. */
    public static function maxTotal(array $rules): int
    {
        $sum = (int) ($rules['phone_points'] ?? 0);
        foreach ($rules['questions'] ?? [] as $q) {
            $sum += max(array_map(static fn ($o) => (int) $o[1], $q['options'] ?: [[0, 0]]));
        }
        return $sum;
    }

    /** Zasady jako tekst do edycji: „# Etykieta | Pytanie”, pod spodem „Odpowiedź = punkty”. */
    public static function format(array $rules): string
    {
        $blocks = [];
        foreach ($rules['questions'] ?? [] as $q) {
            $lines = ['# ' . $q['label'] . ' | ' . $q['text']];
            foreach ($q['options'] as [$text, $pts]) {
                $lines[] = $text . ' = ' . (int) $pts;
            }
            $blocks[] = implode("\n", $lines);
        }
        return implode("\n\n", $blocks);
    }

    /**
     * Czyta zasady z tekstu i sprawdza je. Błędy są po polsku i mówią, co poprawić.
     * @return array{ok: bool, rules: array, errors: string[]}
     */
    public static function parse(string $text, int $phonePoints, int $hot, int $talk): array
    {
        $errors = [];
        $questions = [];
        $current = null;
        foreach (preg_split('/\R/u', $text) ?: [] as $n => $raw) {
            $line = trim($raw);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '#')) {
                if ($current !== null) {
                    $questions[] = $current;
                }
                $parts = array_map('trim', explode('|', ltrim($line, "# \t"), 2));
                $label = mb_substr($parts[0], 0, 40);
                $current = ['label' => $label, 'text' => mb_substr($parts[1] ?? $parts[0], 0, 200), 'options' => []];
                if ($label === '') {
                    $errors[] = 'Wiersz ' . ($n + 1) . ': po znaku # wpisz etykietę i pytanie, np. „# Termin | Kiedy chcesz zacząć?”.';
                }
                continue;
            }
            if ($current === null) {
                $errors[] = 'Wiersz ' . ($n + 1) . ': odpowiedź musi być pod pytaniem. Zacznij od wiersza „# Etykieta | Pytanie”.';
                continue;
            }
            $pos = mb_strrpos($line, '=');
            $pts = $pos === false ? '' : trim(mb_substr($line, $pos + 1));
            $answer = $pos === false ? $line : trim(mb_substr($line, 0, $pos));
            if ($pos === false || !preg_match('/^\d{1,3}$/', $pts) || (int) $pts > self::TOTAL || $answer === '') {
                $errors[] = 'Wiersz ' . ($n + 1) . ': zapisz odpowiedź jako „Tekst odpowiedzi = punkty”, punkty od 0 do 100.';
                continue;
            }
            $current['options'][] = [mb_substr($answer, 0, 120), (int) $pts];
        }
        if ($current !== null) {
            $questions[] = $current;
        }
        if (!$questions) {
            $errors[] = 'Dodaj co najmniej jedno pytanie.';
        }
        if (count($questions) > 8) {
            $errors[] = 'Najwyżej 8 pytań. Każde kolejne pytanie zmniejsza liczbę osób, które dojdą do końca.';
        }
        foreach ($questions as $q) {
            if (count($q['options']) < 2 || count($q['options']) > 8) {
                $errors[] = 'Pytanie „' . $q['label'] . '” musi mieć od 2 do 8 odpowiedzi.';
            }
        }
        if ($phonePoints < 0 || $phonePoints > 50) {
            $errors[] = 'Punkty za telefon: od 0 do 50.';
        }
        if (!($talk > 0 && $talk < $hot && $hot <= self::TOTAL)) {
            $errors[] = 'Progi: „Do rozmowy” musi być większe od 0 i mniejsze niż „Pilne”, a „Pilne” najwyżej 100.';
        }
        $rules = ['questions' => $questions, 'phone_points' => $phonePoints, 'levels' => ['hot' => $hot, 'talk' => $talk]];
        if (!$errors && self::maxTotal($rules) !== self::TOTAL) {
            $errors[] = 'Komplet punktów to teraz ' . self::maxTotal($rules) . ', a ma być ' . self::TOTAL
                . '. Liczymy najwyżej punktowaną odpowiedź z każdego pytania plus punkty za telefon.';
        }
        return ['ok' => !$errors, 'rules' => $rules, 'errors' => $errors];
    }

    public static function level(array $rules, int $score): string
    {
        return $score >= (int) $rules['levels']['hot'] ? 'hot' : ($score >= (int) $rules['levels']['talk'] ? 'talk' : 'low');
    }

    /**
     * @param array<int, int|null> $answers numer wybranej odpowiedzi dla kolejnych pytań (null = bez odpowiedzi)
     * @return array{score: int, level: string, rows: array<int, array{label: string, answer: ?string, points: int, max: int}>}
     */
    public static function score(array $rules, array $answers, bool $phone): array
    {
        $rows = [];
        $score = 0;
        foreach ($rules['questions'] as $i => $q) {
            $max = max(array_map(static fn ($o) => (int) $o[1], $q['options']));
            $pick = $answers[$i] ?? null;
            $opt = is_int($pick) && isset($q['options'][$pick]) ? $q['options'][$pick] : null;
            $pts = $opt ? (int) $opt[1] : 0;
            $score += $pts;
            $rows[] = ['label' => (string) $q['label'], 'answer' => $opt ? (string) $opt[0] : null, 'points' => $pts, 'max' => $max];
        }
        $pp = (int) $rules['phone_points'];
        $score += $phone ? $pp : 0;
        $rows[] = ['label' => 'Telefon', 'answer' => $phone ? 'Podany' : 'Nie podano', 'points' => $phone ? $pp : 0, 'max' => $pp];
        $score = max(0, min(self::TOTAL, $score));
        return ['score' => $score, 'level' => self::level($rules, $score), 'rows' => $rows];
    }

    /** Pytania bez punktów: tylko to wolno wysłać do przeglądarki odwiedzającego. */
    public static function publicQuestions(array $rules): array
    {
        return array_map(static fn ($q) => ['text' => $q['text'], 'options' => array_map(static fn ($o) => (string) $o[0], $q['options'])], $rules['questions']);
    }

    /** Test dwóch proporcji: czy różnica konwersji między wariantami jest pewna (|z| >= 1,96 to pewność 95%). */
    public static function ztest(int $n1, int $x1, int $n2, int $x2): array
    {
        if ($n1 <= 0 || $n2 <= 0) {
            return ['z' => null, 'sure' => false, 'p1' => null, 'p2' => null];
        }
        $p1 = $x1 / $n1;
        $p2 = $x2 / $n2;
        $p = ($x1 + $x2) / ($n1 + $n2);
        $se = sqrt($p * (1 - $p) * (1 / $n1 + 1 / $n2));
        $z = $se > 0 ? ($p2 - $p1) / $se : null;
        return ['z' => $z, 'sure' => $z !== null && abs($z) >= 1.96, 'p1' => $p1, 'p2' => $p2];
    }
}
