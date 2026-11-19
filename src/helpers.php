<?php
/** Krótkie funkcje używane w widokach i kodzie. */

declare(strict_types=1);

use App\Core\Config;
use App\Core\Csrf;

function e(mixed $value): string
{
    if (is_array($value) || is_object($value)) {
        $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '';
    }
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Dowolna wartość (np. pole z odpowiedzi modelu AI) jako tekst jednej linii logicznej. */
function to_text(mixed $v): string
{
    if (is_string($v)) {
        return trim($v);
    }
    if (is_scalar($v)) {
        return (string) $v;
    }
    if (is_array($v)) {
        return trim(implode('; ', array_map('to_text', $v)));
    }
    return '';
}

function url(string $path = '/', array $query = []): string
{
    $q = $query ? '?' . http_build_query($query) : '';
    return $path . $q;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

/** Data i godzina w strefie Europe/Warsaw z zapisu UTC. */
function local_dt(?string $utc, string $format = 'd.m.Y H:i'): string
{
    if (!$utc) {
        return '';
    }
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Warsaw'))->format($format);
    } catch (Throwable) {
        return '';
    }
}

function local_date(?string $utc): string
{
    return local_dt($utc, 'd.m.Y');
}

/** Liczba dni od teraz do daty (ujemna = w przeszłości). */
function days_until(?string $utc): ?int
{
    if (!$utc) {
        return null;
    }
    $d = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->getTimestamp() - time();
    return (int) floor($d / 86400);
}

function money_pl(float|int|string|null $value, string $currency = 'zł'): string
{
    if ($value === null || $value === '') {
        return '';
    }
    // Twarda spacja: kwota nie rozdziela się między wiersze.
    return number_format((float) $value, 2, ',', "\u{00A0}") . "\u{00A0}" . $currency;
}

function short_money(float|int|string|null $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $v = (float) $value;
    if ($v >= 1_000_000) {
        return str_replace('.', ',', rtrim(rtrim(number_format($v / 1_000_000, 1, '.', ''), '0'), '.')) . ' mln zł';
    }
    if ($v >= 1_000) {
        return number_format($v / 1000, 0, ',', ' ') . ' tys. zł';
    }
    return number_format($v, 0, ',', ' ') . ' zł';
}

/** Odmiana liczebników: plural(5, 'przetarg', 'przetargi', 'przetargów'). */
function plural(int $n, string $one, string $few, string $many): string
{
    $n = abs($n);
    if ($n === 1) {
        return $one;
    }
    $mod10 = $n % 10;
    $mod100 = $n % 100;
    if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
        return $few;
    }
    return $many;
}

/** Tekst do wyszukiwania: małe litery, bez polskich znaków i interpunkcji. */
function search_text(string $s): string
{
    $s = mb_strtolower($s);
    $s = strtr($s, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
    $s = preg_replace('/[^a-z0-9]+/u', ' ', $s) ?? '';
    return trim($s);
}

function truncate(string $s, int $len): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return mb_strlen($s) > $len ? rtrim(mb_substr($s, 0, $len - 1)) . '…' : $s;
}

function json_out(mixed $v): string
{
    // JSON_INVALID_UTF8_SUBSTITUTE: błędny znak w danych z zewnętrznego serwisu nie może przerwać zapisu.
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
}

function json_in(?string $s): array
{
    if ($s === null || $s === '') {
        return [];
    }
    $v = json_decode($s, true);
    return is_array($v) ? $v : [];
}

/** Ścieżka bieżącej strony (bez parametrów). */
function current_path(): string
{
    return App\Core\App::$request?->path ?? '/';
}

/** Klasa „on” dla aktywnej pozycji menu. */
function nav_on(string $prefix): string
{
    $p = current_path();
    return ($p === $prefix || str_starts_with($p, rtrim($prefix, '/') . '/')) ? ' class="on" aria-current="page"' : '';
}

/** Chip z poziomem ryzyka płatniczego (0-100). */
function risk_chip(?int $score): string
{
    if ($score === null) {
        return '<span class="chip neutral">Nie sprawdzono</span>';
    }
    [$cls, $label] = match (true) {
        $score >= 70 => ['high', 'Wysokie'],
        $score >= 45 => ['mid', 'Podwyższone'],
        $score >= 20 => ['mid', 'Umiarkowane'],
        default => ['low', 'Niskie'],
    };
    return '<span class="chip ' . $cls . '">' . $label . ' · ' . $score . '/100</span>';
}

/** Kolor oceny dopasowania przetargu. */
function score_class(int $score): string
{
    return $score >= 85 ? 'low' : ($score >= 60 ? 'info' : 'neutral');
}

/** Tekst ze znacznikami ⟦źródło: …⟧ / ⟦sprawdź⟧ jako HTML z etykietami źródeł. */
function marked_html(string $text): string
{
    $out = [];
    foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
        if (trim($line) === '') {
            continue;
        }
        $nosrc = str_contains($line, '⟦sprawdź⟧');
        $h = e($line);
        $h = preg_replace('/\s*⟦źródło:\s*([^⟧]*)⟧/u', ' <span class="src ok">$1</span>', $h) ?? $h;
        $h = str_replace('⟦sprawdź⟧', '<span class="chip high">Brak źródła - sprawdź lub usuń</span>', $h);
        $out[] = '<p' . ($nosrc ? ' class="nosrc"' : '') . '>' . $h . '</p>';
    }
    return implode("\n", $out);
}

/** Adres do atrybutu href: tylko http(s) i ścieżki lokalne, inaczej „#” (ochrona przed javascript: itp.). */
function safe_url(?string $url): string
{
    $url = trim((string) $url);
    if ($url !== '' && (preg_match('#^https?://#i', $url) || ($url[0] === '/' && !str_starts_with($url, '//')))) {
        return $url;
    }
    return '#';
}

/** Adres pliku statycznego z wersją z czasu modyfikacji - po wdrożeniu przeglądarka pobiera nowy plik. */
function asset(string $path): string
{
    $file = APP_ROOT . '/public' . $path;
    return $path . '?v=' . (is_file($file) ? substr((string) filemtime($file), -8) : APP_VERSION);
}

/**
 * Usuwa z początku i końca tekstu odstępy oraz cudzysłowy, także typograficzne.
 * Wbudowane trim() działa na bajtach: lista ze znakami „ ” ucinała pojedyncze bajty i psuła kodowanie UTF-8.
 */
function utrim(string $s): string
{
    $s = mb_scrub($s, 'UTF-8');
    return preg_replace('/^[\s\x{00A0}„”“"«»\x{2018}\x{2019}\x{201A}]+|[\s\x{00A0}„”“"«»\x{2018}\x{2019}\x{201A}]+$/u', '', $s) ?? trim($s);
}
