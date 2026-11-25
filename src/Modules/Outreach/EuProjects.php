<?php

declare(strict_types=1);

namespace App\Modules\Outreach;

use App\Core\Db;
use App\Core\Http;
use App\Core\Kv;
use App\Modules\Tenders\Plans;
use App\Modules\Tenders\Regions;

/**
 * Firmy z dotacjami UE: lista projektów z funduszy europejskich 2021-2027 publikowana co miesiąc na funduszeeuropejskie.gov.pl.
 *
 * Plik CSV ma ok. 150 MB (UTF-8 z BOM, średnik, pola w cudzysłowach, opisy z nowymi liniami). Pierwsza linia to tytuł,
 * druga nagłówek. Lista nie podaje NIP-u. Import czyta plik strumieniowo i zostawia tylko projekty spółek (bez osób
 * fizycznych i spółek cywilnych, których nazwa to imiona i nazwiska wspólników) trwające albo zakończone w ostatnich
 * 6 miesiącach. Firma w trakcie projektu ma budżet na zakupy, a większe zakupy w projekcie zwykle ogłasza
 * w Bazie Konkurencyjności.
 */
final class EuProjects
{
    public const PAGE_URL = 'https://funduszeeuropejskie.gov.pl/raporty-i-analizy/lista-projektow-realizowanych-z-funduszy-europejskich-w-polsce-w-latach-2021-2027/';

    public const BASE_URL = 'https://funduszeeuropejskie.gov.pl';

    public const COMPETITION_URL = 'https://bazakonkurencyjnosci.funduszeeuropejskie.gov.pl/';

    public const PER_PAGE = 30;

    /** Projekt zakończony dawniej niż tyle dni temu nie trafia do bazy. */
    public const KEEP_ENDED_DAYS = 180;

    /** Nagłówki kolumn (bez polskich znaków, początek nazwy) => pole. */
    private const COLUMNS = [
        'nazwa projektu' => 'title', 'opis' => 'summary', 'numer umowy' => 'contract_no', 'nazwa beneficjenta' => 'beneficiary',
        'fundusz' => 'fund', 'program' => 'program', 'dzialanie' => 'action', 'wartosc projektu' => 'value_total',
        'dofinansowanie ue' => 'eu_amount', 'miejsce realizacji' => 'place', 'data rozpoczecia' => 'start_date',
        'data zakonczenia' => 'end_date', 'kategoria wsparcia' => 'category',
    ];

    /** Formy prawne spółek handlowych i spółdzielni. */
    private const COMPANY = '/sp\.\s*z\s*o\.\s*o|spółk[aię]\s+(z\s+ograniczon|akcyjn|komandytow|jawn|partnersk)|prosta\s+spółka\s+akcyjna|\bS\.\s?A\.?(\s|$)|\sSA$|\bsp\.\s?[kjp]\.|\bP\.\s?S\.\s?A\.?|\bS\.\s?K\.\s?A\.?|spółdzielni/iu';

    /** Spółka cywilna: w nazwie są zwykle imiona i nazwiska wspólników, więc jej nie zapisujemy. */
    private const CIVIL = '/\bs\.\s?c\.|spółka\s+cywilna/iu';

    /** Adres aktualnego pliku CSV ze strony z listą projektów. Null, gdy strona nie odpowiada albo zmieniła wygląd. */
    public static function sourceUrl(): ?string
    {
        $r = Http::get(self::PAGE_URL, [], 30);
        if ($r['status'] !== 200 || !preg_match('#(?:data-url|href)="([^"]*Lista_projektow_FE_2021_2027_[0-9]+\.csv)"#i', $r['body'], $m)) {
            return null;
        }
        $url = html_entity_decode($m[1], ENT_QUOTES);
        return str_starts_with($url, 'http') ? $url : self::BASE_URL . '/' . ltrim($url, '/');
    }

    /**
     * Comiesięczny import: pobiera nowy plik tylko wtedy, gdy na stronie jest inny niż ostatnio zaimportowany.
     * @return array{skipped?: bool, source?: string, read?: int, kept?: int, removed?: int, errors: string[]}
     */
    public static function sync(bool $force = false): array
    {
        $url = self::sourceUrl();
        if ($url === null) {
            return ['errors' => ['Nie znaleziono pliku CSV na stronie z listą projektów UE. Strona mogła zmienić wygląd albo nie odpowiada.']];
        }
        if (!$force && Kv::get('eu:source') === $url) {
            return ['skipped' => true, 'source' => $url, 'errors' => []];
        }
        $tmp = tempnam(sys_get_temp_dir(), 'fe2127');
        try {
            $d = Http::download($url, $tmp);
            if ($d['status'] !== 200 || $d['bytes'] < 1000) {
                return ['source' => $url, 'errors' => ['Nie udało się pobrać listy projektów UE: ' . ($d['error'] ?? 'HTTP ' . $d['status'])]];
            }
            $res = self::importFile($tmp);
            if ($res['kept'] > 0) {
                Kv::set('eu:source', $url);
            }
            Kv::set('eu:last', $res + ['source' => $url, 'at' => now_utc()]);
            return $res + ['source' => $url];
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Import pliku CSV z listą projektów. Zwraca liczby wierszy: przeczytanych, zapisanych i usuniętych (już nieaktualnych).
     * @return array{read: int, kept: int, removed: int, errors: string[]}
     */
    public static function importFile(string $path, ?int $now = null): array
    {
        $now ??= time();
        $out = ['read' => 0, 'kept' => 0, 'removed' => 0, 'errors' => []];
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            $out['errors'][] = 'Nie da się otworzyć pliku ' . basename($path);
            return $out;
        }
        if (fread($fh, 3) !== "\xEF\xBB\xBF") {
            rewind($fh);
        }
        $started = gmdate('Y-m-d H:i:s', $now);
        $cutoff = gmdate('Y-m-d', $now - self::KEEP_ENDED_DAYS * 86400);
        $map = null;
        $batch = [];
        $db = Db::get();
        $flush = static function () use (&$batch, &$out, $db): void {
            if ($batch) {
                $db->upsertMany('eu_projects', array_values($batch), ['contract_no'], [], ['created_at']);
                $out['kept'] += count($batch);
                $batch = [];
            }
        };
        $lines = 0;
        while (($row = fgetcsv($fh, 0, ';', '"', '')) !== false) {
            $lines++;
            if ($map === null) {
                $map = self::headerMap($row);
                if ($map === null && $lines > 5) {
                    $out['errors'][] = 'W pliku nie ma nagłówka z kolumną „Nazwa beneficjenta”.';
                    break;
                }
                continue;
            }
            $out['read']++;
            $p = self::row($row, $map, $started);
            if ($p === null || ($p['end_date'] !== null && $p['end_date'] < $cutoff)) {
                continue;
            }
            $batch[$p['contract_no']] ??= $p; // ten sam numer umowy drugi raz w pliku: zostaje pierwszy wiersz
            if (count($batch) >= 300) {
                $flush();
            }
        }
        fclose($fh);
        $flush();
        // Projekty, których nie ma w nowym pliku albo które już się skończyły, znikają z bazy.
        if ($out['kept'] > 0 && !$out['errors']) {
            $out['removed'] = $db->run('DELETE FROM eu_projects WHERE updated_at < ?', [$started])->rowCount();
        }
        return $out;
    }

    /** @return ?array<string, int> pole => numer kolumny; null, gdy to nie jest wiersz nagłówka */
    private static function headerMap(array $row): ?array
    {
        $map = [];
        foreach ($row as $i => $cell) {
            $h = search_text((string) $cell);
            foreach (self::COLUMNS as $prefix => $field) {
                if (!isset($map[$field]) && str_starts_with($h, $prefix)) {
                    $map[$field] = (int) $i;
                    break;
                }
            }
        }
        return isset($map['beneficiary'], $map['contract_no'], $map['title']) ? $map : null;
    }

    /** Wiersz CSV → rekord do zapisu albo null (nie spółka, brak numeru umowy). */
    private static function row(array $row, array $map, string $now): ?array
    {
        $get = static fn (string $f): string => isset($map[$f]) ? utrim(preg_replace('/\s+/u', ' ', (string) ($row[$map[$f]] ?? '')) ?? '') : '';
        $beneficiary = $get('beneficiary');
        $contract = $get('contract_no');
        if ($beneficiary === '' || $contract === '' || !self::isCompany($beneficiary)) {
            return null;
        }
        $place = $get('place');
        $regions = [];
        if (preg_match_all('/WOJ\.:\s*([\p{L}\-]+)/u', $place, $m)) {
            foreach ($m[1] as $name) {
                if ($slug = Regions::fromName($name)) {
                    $regions[$slug] = true;
                }
            }
        }
        $title = $get('title');
        $summary = mb_substr($get('summary'), 0, 600);
        return [
            'contract_no' => mb_substr($contract, 0, 80),
            'title' => mb_substr($title !== '' ? $title : '(bez tytułu)', 0, 500),
            'summary' => $summary !== '' ? $summary : null,
            'beneficiary' => mb_substr($beneficiary, 0, 300),
            'beneficiary_key' => self::key($beneficiary),
            'fund' => mb_substr($get('fund'), 0, 60) ?: null,
            'program' => mb_substr($get('program'), 0, 200) ?: null,
            'action' => mb_substr($get('action'), 0, 300) ?: null,
            'value_total' => self::money($get('value_total')),
            'eu_amount' => self::money($get('eu_amount')),
            'place' => mb_substr($place, 0, 300) ?: null,
            'voivodeships' => implode(' ', array_keys($regions)),
            'start_date' => self::date($get('start_date')),
            'end_date' => self::date($get('end_date')),
            'category' => mb_substr($get('category'), 0, 200) ?: null,
            'search' => search_text($title . ' ' . $summary . ' ' . $beneficiary . ' ' . $get('action')),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    public static function isCompany(string $name): bool
    {
        return (bool) preg_match(self::COMPANY, $name) && !preg_match(self::CIVIL, $name);
    }

    /** Nazwa firmy bez formy prawnej i polskich znaków: „ABC Sp. z o.o.” i „ABC SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ” dają „abc”. */
    public static function key(string $name): string
    {
        $k = ' ' . search_text($name) . ' ';
        foreach (['spolka z ograniczona odpowiedzialnoscia', 'spolka komandytowo akcyjna', 'prosta spolka akcyjna', 'spolka akcyjna', 'spolka komandytowa',
            'spolka jawna', 'spolka partnerska', 'w likwidacji', 'w upadlosci', 'sp z o o', 'sp k', 'sp j', 'sp p', 'p s a', 's k a', 's a'] as $form) {
            $k = str_replace(' ' . $form . ' ', ' ', $k);
        }
        return mb_substr(trim(preg_replace('/\s+/', ' ', $k) ?? $k), 0, 200);
    }

    private static function money(string $s): ?float
    {
        $n = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $s);
        return is_numeric($n) ? (float) $n : null;
    }

    private static function date(string $s): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
            return "$m[1]-$m[2]-$m[3]";
        }
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})/', $s, $m)) {
            return "$m[3]-$m[2]-$m[1]";
        }
        return null;
    }

    /**
     * Wyszukiwanie projektów firm.
     * @param array{q?: string, woj?: string, stan?: string, min?: ?float} $f
     * @return array{rows: array, total: int}
     */
    public static function search(array $f, int $page = 1): array
    {
        $db = Db::get();
        $today = gmdate('Y-m-d');
        $where = ['1 = 1'];
        $params = [];
        foreach (Plans::likeTerms((string) ($f['q'] ?? '')) as $term) {
            $where[] = 'search LIKE ?';
            $params[] = $term;
        }
        if (($f['woj'] ?? '') !== '') {
            $where[] = "(' ' || voivodeships || ' ') LIKE ?";
            $params[] = '% ' . $f['woj'] . ' %';
        }
        $stan = $f['stan'] ?? 'active';
        if ($stan === 'active') {
            $where[] = '(end_date IS NULL OR end_date >= ?)';
            $params[] = $today;
        } elseif ($stan === 'new') {
            $where[] = 'start_date >= ?';
            $params[] = gmdate('Y-m-d', time() - 183 * 86400);
        }
        if (!empty($f['min'])) {
            $where[] = 'value_total >= ?';
            $params[] = (float) $f['min'];
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) $db->val("SELECT COUNT(*) FROM eu_projects WHERE $sqlWhere", $params);
        $rows = $db->all(
            "SELECT * FROM eu_projects WHERE $sqlWhere ORDER BY start_date DESC, eu_amount DESC, id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (max(0, $page - 1) * self::PER_PAGE),
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /** Ile projektów firm jest w bazie i z którego miesiąca jest plik. */
    public static function coverage(): array
    {
        $last = Kv::get('eu:last');
        return [
            'projects' => (int) Db::get()->val('SELECT COUNT(*) FROM eu_projects'),
            'firms' => (int) Db::get()->val('SELECT COUNT(DISTINCT beneficiary_key) FROM eu_projects'),
            'source' => is_array($last) ? (string) ($last['source'] ?? '') : '',
            'at' => is_array($last) ? (string) ($last['at'] ?? '') : '',
        ];
    }

    public static function find(int $id): ?array
    {
        return Db::get()->one('SELECT * FROM eu_projects WHERE id = ?', [$id]);
    }

    /** Fakty o projekcie do karty potencjalnego klienta. */
    public static function facts(array $p): array
    {
        return [
            'contract' => $p['contract_no'], 'title' => truncate((string) $p['title'], 200), 'program' => $p['program'], 'action' => truncate((string) ($p['action'] ?? ''), 160),
            'value' => $p['value_total'] !== null ? (float) $p['value_total'] : null, 'eu' => $p['eu_amount'] !== null ? (float) $p['eu_amount'] : null,
            'start' => $p['start_date'], 'end' => $p['end_date'],
        ];
    }
}
