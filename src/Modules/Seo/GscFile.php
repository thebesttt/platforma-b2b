<?php

declare(strict_types=1);

namespace App\Modules\Seo;

/**
 * Import pliku wyeksportowanego z Google Search Console (przycisk „Eksportuj” w raporcie).
 *
 * Eksport to paczka ZIP z kilkoma plikami CSV (zapytania, strony, daty, kraje, urządzenia) albo pojedynczy CSV.
 * Nazwy plików i kolumn zależą od języka konta, dlatego rodzaj tabeli rozpoznajemy po nagłówkach, a nie po nazwie pliku.
 * Obsługujemy dwa raporty: „Skuteczność” (kliknięcia, wyświetlenia, pozycja) i „Funkcje generatywnej AI” (same wyświetlenia).
 * Użytkownik wgrywa plik tak, jak go pobrał. Raport i okres rozpoznajemy sami.
 */
final class GscFile
{
    public const MAX_UPLOAD = 12_000_000;

    private const MAX_CSV = 8_000_000;

    private const MAX_ROWS = 25_000;

    private const MAX_FILES = 20;

    /** Wymiary, które zapisujemy. Pozostałe tabele z paczki (filtry, wygląd w wyszukiwarce) pomijamy. */
    public const DIMS = [
        'query' => ['zapytanie', 'zapytania', 'zapytań'], 'page' => ['strona', 'strony', 'stron'], 'date' => ['dzień', 'dni', 'dni'],
        'country' => ['kraj', 'kraje', 'krajów'], 'device' => ['urządzenie', 'urządzenia', 'urządzeń'],
    ];

    /**
     * Czyta plik z dysku (ZIP albo CSV).
     * @return array{ok: bool, error?: string, sets?: array<string, array{rows: array, clicks: bool, position: bool}>, skipped?: string[]}
     */
    public static function read(string $path, string $name): array
    {
        $size = (int) @filesize($path);
        if ($size <= 0 || $size > self::MAX_UPLOAD) {
            return ['ok' => false, 'error' => 'Plik jest pusty albo większy niż 12 MB.'];
        }
        $head = (string) @file_get_contents($path, false, null, 0, 4);
        $files = [];
        if (str_starts_with($head, "PK\x03\x04")) {
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                return ['ok' => false, 'error' => 'Nie udało się otworzyć paczki ZIP. Pobierz eksport jeszcze raz.'];
            }
            $total = 0;
            for ($i = 0; $i < min($zip->numFiles, self::MAX_FILES); $i++) {
                $st = $zip->statIndex($i);
                // Rozmiar po rozpakowaniu sprawdzamy przed czytaniem: pojedynczy plik do 8 MB, cała paczka do 24 MB.
                if (!$st || !preg_match('/\.csv$/i', (string) $st['name']) || (int) $st['size'] > self::MAX_CSV || $total + (int) $st['size'] > 3 * self::MAX_CSV) {
                    continue;
                }
                $total += (int) $st['size'];
                $files[basename((string) $st['name'])] = (string) $zip->getFromIndex($i, self::MAX_CSV);
            }
            $zip->close();
            if (!$files) {
                return ['ok' => false, 'error' => 'W paczce nie ma plików CSV. Wybierz w Search Console „Eksportuj” i „Pobierz plik CSV”.'];
            }
        } elseif (preg_match('/\.(csv|tsv|txt)$/i', $name)) {
            $files[basename($name)] = (string) file_get_contents($path);
        } else {
            return ['ok' => false, 'error' => 'Wgraj paczkę ZIP albo plik CSV z eksportu Search Console. Plik Excela zapisz najpierw jako CSV.'];
        }
        return self::parseFiles($files);
    }

    /**
     * @param array<string, string> $files nazwa => treść CSV
     * @return array{ok: bool, error?: string, sets?: array, skipped?: string[]}
     */
    public static function parseFiles(array $files): array
    {
        $sets = [];
        $skipped = [];
        foreach ($files as $name => $csv) {
            $t = self::table($csv);
            if (!empty($t['compare'])) {
                return ['ok' => false, 'error' => 'Plik „' . $name . '” to eksport z porównaniem dwóch okresów. Wyłącz porównanie w Search Console i wyeksportuj każdy okres osobno.'];
            }
            if ($t['dim'] === null || !isset(self::DIMS[$t['dim']]) || !$t['rows']) {
                $skipped[] = (string) $name;
                continue;
            }
            // Gdy paczka ma dwie tabele tego samego wymiaru, zostaje większa.
            if (!isset($sets[$t['dim']]) || count($t['rows']) > count($sets[$t['dim']]['rows'])) {
                $sets[$t['dim']] = ['rows' => $t['rows'], 'clicks' => $t['clicks'], 'position' => $t['position']];
            }
        }
        if (!$sets) {
            return ['ok' => false, 'error' => 'Nie rozpoznaliśmy żadnej tabeli. Potrzebne są kolumny z zapytaniami, stronami albo datami oraz z wyświetleniami.'];
        }
        return ['ok' => true, 'sets' => $sets, 'skipped' => $skipped];
    }

    /**
     * Zapisuje rozpoznane tabele. Raport rozpoznajemy sami: tabele z kliknięciami to „Skuteczność”,
     * tabele z samymi wyświetleniami to „Funkcje generatywnej AI” (ten raport nie ma kliknięć).
     * @return array{ok: bool, error?: string, report?: string, start?: string, end?: string, saved?: array<string, int>}
     */
    public static function store(array $site, array $sets, ?string $from = null, ?string $to = null): array
    {
        $withClicks = array_filter($sets, static fn (array $s): bool => $s['clicks']);
        if ($withClicks && count($withClicks) < count($sets)) {
            // Mieszanka: zostają tabele z kliknięciami, żeby nie pomylić dwóch raportów w jednym imporcie.
            $sets = $withClicks;
        }
        $report = $withClicks ? 'search' : 'ai';
        $source = $report === 'ai' ? 'gsc_ai' : 'gsc';
        $days = $sets['date']['rows'] ?? [];
        if ($days) {
            $from = min(array_column($days, 'k'));
            $to = max(array_column($days, 'k'));
        }
        if (!$from || !$to || !self::isDate($from) || !self::isDate($to)) {
            return ['ok' => false, 'error' => 'W pliku nie ma tabeli z datami, więc podaj w formularzu okres, którego dotyczy eksport (od i do).'];
        }
        if ($from > $to || $to > gmdate('Y-m-d', time() + 86400) || Stats::days($from, $to) > 500) {
            return ['ok' => false, 'error' => 'Okres jest błędny: data „od” musi być przed datą „do”, a całość nie dłuższa niż 16 miesięcy i nie z przyszłości.'];
        }
        $saved = [];
        foreach ($sets as $dim => $set) {
            if ($dim === 'date') {
                $saved[$dim] = Stats::putDaily($site, $source, 'date', array_map(
                    static fn (array $r): array => ['day' => $r['k'], 'clicks' => $r['clicks'], 'impressions' => $r['impressions'], 'position' => $r['position']],
                    $set['rows']
                ));
                continue;
            }
            $saved[$dim] = Stats::replace($site, $source, $dim, $from, $to, $set['rows']);
        }
        // Suma okresu: z tabeli dat (zgodna z wykresem w Search Console). Bez niej sumujemy widoczne wiersze, a to mniej niż całość.
        $base = $days ?: ($sets['page']['rows'] ?? $sets['query']['rows'] ?? []);
        if ($base) {
            Stats::replace($site, $source, 'total', $from, $to, [self::total($base) + ['k' => $days ? 'dates' : 'rows']]);
        }
        Stats::prune($site, $source);
        return ['ok' => true, 'report' => $report, 'start' => $from, 'end' => $to, 'saved' => $saved];
    }

    /** Suma kliknięć i wyświetleń oraz pozycja ważona wyświetleniami. */
    public static function total(array $rows): array
    {
        $c = 0;
        $i = 0;
        $pos = 0.0;
        $posW = 0;
        foreach ($rows as $r) {
            $c += (int) $r['clicks'];
            $i += (int) $r['impressions'];
            if ($r['position'] !== null && (int) $r['impressions'] > 0) {
                $pos += (float) $r['position'] * (int) $r['impressions'];
                $posW += (int) $r['impressions'];
            }
        }
        return ['clicks' => $c, 'impressions' => $i, 'position' => $posW ? round($pos / $posW, 2) : null];
    }

    /**
     * Jedna tabela CSV: wymiar z pierwszej kolumny i liczby z kolumn rozpoznanych po nagłówku.
     * @return array{dim: ?string, rows: array, clicks: bool, position: bool, compare?: bool}
     */
    public static function table(string $csv): array
    {
        $none = ['dim' => null, 'rows' => [], 'clicks' => false, 'position' => false];
        $csv = self::utf8($csv);
        $lines = self::lines($csv);
        if (count($lines) < 2) {
            return $none;
        }
        $header = array_map(static fn ($c): string => Sites::fold((string) $c), $lines[0]);
        $dim = self::dimension($header[0] ?? '');
        $cols = ['clicks' => [], 'impressions' => [], 'position' => []];
        foreach ($header as $i => $h) {
            if ($i === 0) {
                continue;
            }
            if (preg_match('/click|klikni/', $h) && !str_contains($h, 'ctr')) {
                $cols['clicks'][] = $i;
            } elseif (preg_match('/impression|wyswietl/', $h)) {
                $cols['impressions'][] = $i;
            } elseif (preg_match('/position|pozycj/', $h)) {
                $cols['position'][] = $i;
            }
        }
        if (count($cols['clicks']) > 1 || count($cols['impressions']) > 1) {
            return $none + ['compare' => true];
        }
        if ($dim === null || !$cols['impressions']) {
            return $none;
        }
        $ci = $cols['clicks'][0] ?? null;
        $ii = $cols['impressions'][0];
        $pi = $cols['position'][0] ?? null;
        $rows = [];
        foreach (array_slice($lines, 1, self::MAX_ROWS) as $line) {
            $k = trim((string) ($line[0] ?? ''));
            if ($k === '') {
                continue;
            }
            if ($dim === 'date') {
                $k = self::date($k);
                if ($k === null) {
                    continue;
                }
            }
            $rows[$k] = [
                'k' => mb_substr($k, 0, 500),
                'clicks' => $ci === null ? 0 : self::int($line[$ci] ?? ''),
                'impressions' => self::int($line[$ii] ?? ''),
                'position' => $pi === null ? null : self::float($line[$pi] ?? ''),
            ];
        }
        return ['dim' => $dim, 'rows' => array_values($rows), 'clicks' => $ci !== null, 'position' => $pi !== null];
    }

    /** @return array<int, array<int, ?string>> wiersze CSV; separator rozpoznany z nagłówka */
    public static function lines(string $csv): array
    {
        $first = strtok($csv, "\n") ?: '';
        $delim = ',';
        $best = 0;
        foreach ([',', ';', "\t"] as $d) {
            if (substr_count($first, $d) > $best) {
                $best = substr_count($first, $d);
                $delim = $d;
            }
        }
        $h = fopen('php://temp', 'r+');
        fwrite($h, $csv);
        rewind($h);
        $out = [];
        while (($row = fgetcsv($h, null, $delim, '"', '')) !== false && count($out) <= self::MAX_ROWS) {
            if ($row !== [null]) {
                $out[] = $row;
            }
        }
        fclose($h);
        return $out;
    }

    public static function isDate(string $s): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && checkdate((int) substr($s, 5, 2), (int) substr($s, 8, 2), (int) substr($s, 0, 4));
    }

    /** Liczba całkowita zapisana z odstępami albo separatorami tysięcy („1 234”, „1,234”). */
    public static function int(?string $s): int
    {
        $s = trim((string) $s);
        if (preg_match('/^\d+[.,]\d{1,2}$/', $s)) {
            return (int) round((float) str_replace(',', '.', $s));
        }
        return (int) preg_replace('/\D+/', '', $s);
    }

    /** Liczba z przecinkiem albo kropką („7,1”, „7.1”). Null, gdy pole jest puste. */
    public static function float(?string $s): ?float
    {
        $s = str_replace(["\u{a0}", ' ', '%'], '', trim((string) $s));
        if ($s === '' || !preg_match('/\d/', $s)) {
            return null;
        }
        if (str_contains($s, ',') && str_contains($s, '.')) {
            $s = str_replace(',', '', $s);
        }
        return (float) str_replace(',', '.', $s);
    }

    private static function dimension(string $h): ?string
    {
        return match (true) {
            (bool) preg_match('/appearance|wyglad/', $h) => 'appearance',
            (bool) preg_match('/^filt/', $h) => 'filter',
            (bool) preg_match('/quer|zapyta|keyword|fraz/', $h) => 'query',
            (bool) preg_match('/page|stron|url|adres/', $h) => 'page',
            (bool) preg_match('/^dat|^day|^dzien/', $h) => 'date',
            (bool) preg_match('/countr|kraj/', $h) => 'country',
            (bool) preg_match('/device|urzadz/', $h) => 'device',
            default => null,
        };
    }

    private static function date(string $s): ?string
    {
        if (self::isDate($s)) {
            return $s;
        }
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $s, $m)) {
            $iso = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
            return self::isDate($iso) ? $iso : null;
        }
        return null;
    }

    private static function utf8(string $s): string
    {
        if (str_starts_with($s, "\xFF\xFE") || str_starts_with($s, "\xFE\xFF")) {
            $s = (string) mb_convert_encoding($s, 'UTF-8', 'UTF-16');
        }
        if (str_starts_with($s, "\xEF\xBB\xBF")) {
            $s = substr($s, 3);
        }
        return mb_check_encoding($s, 'UTF-8') ? $s : (string) mb_convert_encoding($s, 'UTF-8', 'Windows-1250');
    }
}
