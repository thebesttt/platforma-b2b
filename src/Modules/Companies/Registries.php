<?php

declare(strict_types=1);

namespace App\Modules\Companies;

use App\Core\Http;
use App\Core\Nip;

/** Publiczne rejestry: biała lista VAT (Ministerstwo Finansów) i KRS (Ministerstwo Sprawiedliwości). */
final class Registries
{
    public const WL = 'https://wl-api.mf.gov.pl/api/search/nip/';
    public const WL_MANY = 'https://wl-api.mf.gov.pl/api/search/nips/';
    public const KRS = 'https://api-krs.ms.gov.pl/api/krs/OdpisAktualny/';

    /**
     * Wykaz podatników VAT. Limit API jest dzienny, dlatego wynik zapisujemy w bazie i odświeżamy najwyżej raz na dobę.
     * @return array{ok: bool, subject?: ?array, error?: string}
     */
    public static function whiteList(string $nip): array
    {
        $nip = Nip::clean($nip);
        $r = Http::get(self::WL . $nip . '?date=' . date('Y-m-d'), ['Accept' => 'application/json']);
        $j = json_decode($r['body'], true);
        if ($r['status'] === 400 && is_array($j)) {
            return ['ok' => false, 'error' => (string) ($j['message'] ?? 'Nieprawidłowy NIP.')];
        }
        if ($r['status'] !== 200 || !is_array($j)) {
            return ['ok' => false, 'error' => 'Biała lista VAT nie odpowiada' . ($r['status'] === 429 ? ' (limit zapytań na dziś).' : '.')];
        }
        $s = $j['result']['subject'] ?? null;
        return ['ok' => true, 'subject' => is_array($s) ? self::normalizeSubject($s) : null];
    }

    /**
     * Wiele firm jednym zapytaniem (do 30 NIP) – oszczędza dzienny limit zapytań API przy odświeżaniu obserwowanych firm.
     * @param string[] $nips
     * @return array<string, array{ok: bool, subject?: ?array, error?: string}> wynik dla każdego NIP
     */
    public static function whiteListMany(array $nips): array
    {
        $nips = array_values(array_unique(array_filter(array_map([Nip::class, 'clean'], $nips), [Nip::class, 'valid'])));
        $out = [];
        foreach (array_chunk($nips, 30) as $chunk) {
            $r = Http::get(self::WL_MANY . implode(',', $chunk) . '?date=' . date('Y-m-d'), ['Accept' => 'application/json']);
            $j = json_decode($r['body'], true);
            if ($r['status'] !== 200 || !is_array($j)) {
                $err = 'Biała lista VAT nie odpowiada' . ($r['status'] === 429 ? ' (limit zapytań na dziś).' : '.');
                foreach ($chunk as $nip) {
                    $out[$nip] = ['ok' => false, 'error' => $err];
                }
                continue;
            }
            foreach ($chunk as $nip) {
                $out[$nip] = ['ok' => true, 'subject' => null];
            }
            // Odpowiedź: result.entries[] = {identifier, subjects[]}; starsze wersje API: result.subjects[].
            foreach ((array) ($j['result']['entries'] ?? []) as $entry) {
                $id = Nip::clean((string) ($entry['identifier'] ?? ''));
                $s = $entry['subjects'][0] ?? null;
                if (isset($out[$id]) && is_array($s)) {
                    $out[$id]['subject'] = self::normalizeSubject($s);
                }
            }
            foreach ((array) ($j['result']['subjects'] ?? []) as $s) {
                $id = Nip::clean((string) ($s['nip'] ?? ''));
                if (isset($out[$id]) && is_array($s)) {
                    $out[$id]['subject'] = self::normalizeSubject($s);
                }
            }
        }
        return $out;
    }

    /** Listy kont są długie – przechowujemy liczbę i skrót do wykrywania zmian. */
    private static function normalizeSubject(array $s): array
    {
        $accounts = (array) ($s['accountNumbers'] ?? []);
        sort($accounts);
        $s['accountCount'] = count($accounts);
        $s['accountsHash'] = $accounts ? substr(hash('sha256', implode(',', $accounts)), 0, 16) : null;
        $s['accountSample'] = array_slice($accounts, 0, 5);
        unset($s['accountNumbers']);
        return $s;
    }

    /** @return array{ok: bool, odpis?: ?array, error?: string} */
    public static function krs(string $krs): array
    {
        $krs = str_pad(preg_replace('/\D/', '', $krs) ?? '', 10, '0', STR_PAD_LEFT);
        foreach (['P', 'S'] as $rejestr) {
            $r = Http::get(self::KRS . $krs . '?rejestr=' . $rejestr . '&format=json', ['Accept' => 'application/json']);
            if ($r['status'] === 200) {
                $j = json_decode($r['body'], true);
                if (is_array($j) && isset($j['odpis'])) {
                    return ['ok' => true, 'odpis' => $j['odpis']];
                }
            }
            if ($r['status'] !== 404 && $r['status'] !== 200) {
                return ['ok' => false, 'error' => 'KRS nie odpowiada.'];
            }
        }
        return ['ok' => true, 'odpis' => null];
    }

    /** Najważniejsze dane z odpisu KRS. */
    public static function krsSummary(?array $odpis): array
    {
        if (!$odpis) {
            return [];
        }
        $h = $odpis['naglowekA'] ?? [];
        $d = $odpis['dane'] ?? [];
        $p = $d['dzial1']['danePodmiotu'] ?? [];
        $adr = $d['dzial1']['siedzibaIAdres']['adres'] ?? [];
        $kap = $d['dzial1']['kapital']['wysokoscKapitaluZakladowego'] ?? null;
        $board = [];
        foreach ((array) ($d['dzial2']['reprezentacja']['sklad'] ?? []) as $m) {
            $board[] = trim(($m['funkcjaWOrganie'] ?? '') . (!empty($m['czyZawieszona']) ? ' (zawieszony)' : ''));
        }
        $pkd = [];
        foreach ((array) ($d['dzial3']['przedmiotDzialalnosci']['przedmiotPrzewazajacejDzialalnosci'] ?? []) as $x) {
            $pkd[] = sprintf('%s.%s.%s %s', $x['kodDzial'] ?? '', $x['kodKlasa'] ?? '', $x['kodPodklasa'] ?? '', mb_convert_case(mb_strtolower((string) ($x['opis'] ?? '')), MB_CASE_TITLE));
        }
        $statements = (array) ($d['dzial3']['wzmiankiOZlozonychDokumentach']['wzmiankaOZlozeniuRocznegoSprawozdaniaFinansowego'] ?? []);
        $lastPeriodEnd = null;
        $lastFiled = null;
        foreach ($statements as $s) {
            if (preg_match('/DO\s+(\d{2})\.(\d{2})\.(\d{4})/u', (string) ($s['zaOkresOdDo'] ?? ''), $m)) {
                $end = "$m[3]-$m[2]-$m[1]";
                if ($lastPeriodEnd === null || $end > $lastPeriodEnd) {
                    $lastPeriodEnd = $end;
                    $lastFiled = $s['dataZlozenia'] ?? null;
                }
            }
        }
        return [
            'krs' => $h['numerKRS'] ?? null,
            'name' => $p['nazwa'] ?? null,
            'legal_form' => $p['formaPrawna'] ?? null,
            'registered_at' => self::plDate($h['dataRejestracjiWKRS'] ?? null),
            'last_entry_at' => self::plDate($h['dataOstatniegoWpisu'] ?? null),
            'address' => trim(implode(' ', array_filter([($adr['ulica'] ?? '') . ' ' . ($adr['nrDomu'] ?? '') . (isset($adr['nrLokalu']) ? '/' . $adr['nrLokalu'] : ''), ($adr['kodPocztowy'] ?? '') . ' ' . ($adr['miejscowosc'] ?? '')]))),
            'capital' => $kap ? (float) str_replace(',', '.', (string) ($kap['wartosc'] ?? '0')) : null,
            'board' => $board,
            'representation' => $d['dzial2']['reprezentacja']['sposobReprezentacji'] ?? null,
            'pkd' => $pkd,
            'statements_count' => count($statements),
            'last_statement_period_end' => $lastPeriodEnd,
            'last_statement_filed' => self::plDate($lastFiled),
            // Rejestr może zwracać puste struktury ({} albo listy bez pozycji) – sygnałem są tylko rzeczywiste wpisy.
            'dzial4' => self::prune($d['dzial4'] ?? null) ?: null,
            'dzial6_keys' => array_keys((array) (self::prune($d['dzial6'] ?? null) ?? [])),
            'dzial6_text' => mb_substr(json_out(self::prune($d['dzial6'] ?? null) ?? []), 0, 2000),
            'website' => $d['dzial1']['siedzibaIAdres']['adresStronyInternetowej'] ?? null,
        ];
    }

    /** Usuwa z drzewa puste wartości (null, '', [], {}). Zwraca null, gdy nic nie zostało. */
    public static function prune(mixed $v): mixed
    {
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $x) {
                $x = self::prune($x);
                if ($x !== null) {
                    $out[$k] = $x;
                }
            }
            return $out ?: null;
        }
        if ($v === null || (is_string($v) && trim($v) === '')) {
            return null;
        }
        return $v;
    }

    private static function plDate(?string $d): ?string
    {
        if ($d && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})/', $d, $m)) {
            return "$m[3]-$m[2]-$m[1]";
        }
        return null;
    }
}
