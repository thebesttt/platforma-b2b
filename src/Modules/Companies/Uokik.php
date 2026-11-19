<?php

declare(strict_types=1);

namespace App\Modules\Companies;

use App\Core\Db;
use App\Core\Http;
use App\Core\Nip;

/**
 * Decyzje Prezesa UOKiK w sprawach zatorów płatniczych (sygnatury DZP-…).
 * Rejestr jest jawny i podaje nazwy firm; dopasowujemy po NIP, a gdy go brak - po nazwie bez formy prawnej.
 */
final class Uokik
{
    public const BASE = 'https://decyzje.uokik.gov.pl';
    public const CATEGORY = 11; // „Zatory Płatnicze” w drzewie kategorii rejestru

    /** Pobiera listę decyzji z kolejnych lat kategorii „Zatory Płatnicze”. */
    public static function sync(int $years = 6): array
    {
        $saved = 0;
        $errors = [];
        for ($i = 1; $i <= $years; $i++) {
            $r = Http::get(self::listUrl($i), [], 60);
            if ($r['status'] !== 200) {
                if ($r['status'] === 0 && $i === 1 && str_contains((string) ($r['error'] ?? ''), 'timed out')) {
                    $errors[] = "rok $i: HTTP 0 (" . $r['error'] . ')';
                    break; // serwer nie odpowiada - nie czekamy na kolejne lata
                }
                $errors[] = "rok $i: HTTP {$r['status']}" . (!empty($r['error']) ? ' (' . $r['error'] . ')' : '');
                continue;
            }
            $saved += self::store(self::parseList($r['body']));
        }
        if ($errors && $saved === 0 && str_contains(implode(' ', $errors), 'timed out')) {
            $errors[] = 'Rejestr UOKiK nie przyjmuje połączeń z tego serwera (prawdopodobnie blokada adresów spoza Polski). Wgraj listę decyzji ręcznie: Ustawienia → Decyzje UOKiK.';
        }
        return ['saved' => $saved, 'errors' => $errors];
    }

    /** Zapis decyzji (bez duplikatów po sygnaturze). Zwraca liczbę zapisanych pozycji. */
    public static function store(array $rows): int
    {
        $now = now_utc();
        $unique = [];
        foreach ($rows as $d) {
            $unique[(string) $d['signature']] = [
                'signature' => $d['signature'], 'company_name' => $d['company_name'], 'search' => $d['search'], 'decided_at' => $d['decided_at'] ?? null,
                'summary' => $d['summary'] ?? null, 'url' => $d['url'] ?? null, 'nip' => $d['nip'] ?? null, 'created_at' => $now,
            ];
        }
        foreach (array_chunk(array_values($unique), 200) as $chunk) {
            Db::get()->upsertMany('uokik_decisions', $chunk, ['signature'], [], ['nip', 'created_at']);
        }
        return count($rows);
    }

    /** Adres listy decyzji z danego roku (1 = najnowszy) - do pobrania w przeglądarce i wgrania ręcznego. */
    public static function listUrl(int $i): string
    {
        return self::BASE . '/bp/dec_prez.nsf/UOKiK?OpenForm&Start=1&Count=9999&Expand=' . self::CATEGORY . '.' . $i . '&Seq=2';
    }

    /** @return array<int, array{signature: string, company_name: string, search: string, decided_at: ?string, summary: string, url: ?string, nip: ?string}> */
    public static function parseList(string $html): array
    {
        $out = [];
        if (!preg_match_all('#<tr\b[^>]*>(.*?)</tr>#is', $html, $rows)) {
            return [];
        }
        foreach ($rows[1] as $row) {
            $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' ', $row)), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
            if (!preg_match('/Numer decyzji:\s*(DZP[^\s]*(?:\s*\d+\/\d{4})?)\s*Data decyzji:\s*(\d{2})\.(\d{2})\.(\d{4})\s*(.+)$/u', $text, $m)) {
                continue;
            }
            $signature = trim($m[1]);
            $rest = trim($m[5]);
            $name = trim((string) preg_replace('/\s+z siedzib[ąa].*$/iu', '', $rest));
            $url = null;
            if (preg_match('#href="(/bp/dec_prez\.nsf/[0-9a-f]{32}/[0-9a-f]{32}\?OpenDocument)"#i', $row, $lm)) {
                $url = self::BASE . html_entity_decode($lm[1]);
            }
            $nip = null;
            if (preg_match('/NIP[:\s]*([\d\- ]{10,13})/u', $text, $nm) && Nip::valid($nm[1])) {
                $nip = Nip::clean($nm[1]);
            }
            $out[] = [
                'signature' => $signature,
                'company_name' => $name,
                'search' => self::core($name),
                'decided_at' => "$m[4]-$m[3]-$m[2]",
                'summary' => mb_substr($rest, 0, 400),
                'url' => $url,
                'nip' => $nip,
            ];
        }
        return $out;
    }

    /** Nazwa bez formy prawnej, małymi literami i bez polskich znaków. */
    public static function core(string $name): string
    {
        $s = ' ' . search_text($name) . ' ';
        $forms = [
            'spolka z ograniczona odpowiedzialnoscia', 'spolke z ograniczona odpowiedzialnoscia', 'spolki z ograniczona odpowiedzialnoscia',
            'spolka akcyjna', 'spolke akcyjna', 'prosta spolka akcyjna', 'spolka komandytowa', 'spolka komandytowo akcyjna', 'spolka jawna',
            'sp z o o', 'sp zoo', 's a', 'sp k', 'sp j', 'w likwidacji', 'w restrukturyzacji',
        ];
        foreach ($forms as $f) {
            $s = str_replace(' ' . $f . ' ', ' ', $s);
        }
        return trim(preg_replace('/\s+/', ' ', $s) ?? '');
    }

    /** Decyzje dotyczące firmy: po NIP albo po identycznej nazwie (bez formy prawnej). */
    public static function forCompany(string $nip, string $name): array
    {
        $db = Db::get();
        $rows = $db->all('SELECT signature, company_name, decided_at, summary, url, nip FROM uokik_decisions WHERE nip = ?', [Nip::clean($nip)]);
        $core = self::core($name);
        if ($core !== '' && mb_strlen($core) >= 4) {
            foreach ($db->all('SELECT signature, company_name, decided_at, summary, url, nip FROM uokik_decisions WHERE search = ? AND (nip IS NULL OR nip = ?)', [$core, Nip::clean($nip)]) as $r) {
                if (!in_array($r['signature'], array_column($rows, 'signature'), true)) {
                    $r['matched_by'] = 'nazwa';
                    $rows[] = $r;
                }
            }
        }
        return $rows;
    }
}
