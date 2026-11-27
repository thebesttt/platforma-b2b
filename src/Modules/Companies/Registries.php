<?php

declare(strict_types=1);

namespace App\Modules\Companies;

use App\Core\Http;
use App\Core\Kv;
use App\Core\Nip;
use App\Core\Nrb;

/** Publiczne rejestry: biała lista VAT (Ministerstwo Finansów) i KRS (Ministerstwo Sprawiedliwości). */
final class Registries
{
    public const WL = 'https://wl-api.mf.gov.pl/api/search/nip/';
    public const WL_MANY = 'https://wl-api.mf.gov.pl/api/search/nips/';
    public const WL_CHECK = 'https://wl-api.mf.gov.pl/api/check/nip/';
    public const KRS = 'https://api-krs.ms.gov.pl/api/krs/OdpisAktualny/';

    /**
     * Limit API wykazu VAT: 100 zapytań na dobę (do 30 podmiotów w jednym), po przekroczeniu blokada do północy.
     * Panel ma jeden adres dla wszystkich firm, więc liczymy zapytania wspólnie.
     */
    public const WL_DAILY = 100;

    /**
     * Wykaz podatników VAT na dzień $date (domyślnie dziś). Limit API jest dzienny, dlatego wynik zapisujemy w bazie
     * i odświeżamy najwyżej raz na dobę. 'request' to klucz zapytania z MF: potwierdzenie, że sprawdzenie wykonano.
     * @return array{ok: bool, subject?: ?array, request?: array{id: string, at: string, date: string}, error?: string, code?: string}
     */
    public static function whiteList(string $nip, ?string $date = null): array
    {
        $nip = Nip::clean($nip);
        $date ??= self::today();
        $r = Http::get(self::WL . $nip . '?date=' . $date, ['Accept' => 'application/json']);
        self::count($r['status']);
        $j = json_decode($r['body'], true);
        if ($r['status'] === 400 && is_array($j)) {
            return ['ok' => false, 'error' => (string) ($j['message'] ?? 'Nieprawidłowy NIP.'), 'code' => (string) ($j['code'] ?? '')];
        }
        if ($r['status'] !== 200 || !is_array($j)) {
            return ['ok' => false, 'error' => self::down($r['status'])];
        }
        $s = $j['result']['subject'] ?? null;
        return ['ok' => true, 'subject' => is_array($s) ? self::normalizeSubject($s) : null, 'request' => self::request($j['result'] ?? [], $date)];
    }

    /**
     * Czy rachunek jest przypisany do czynnego podatnika VAT o tym NIP-ie w danym dniu (metoda „check” wykazu).
     * Odpowiedź TAK albo NIE z kluczem zapytania. Numer musi mieć poprawną sumę kontrolną, inaczej API zwraca błąd WL-111.
     * @return array{ok: bool, assigned?: bool, request?: array{id: string, at: string, date: string}, error?: string, code?: string}
     */
    public static function checkAccount(string $nip, string $account, ?string $date = null): array
    {
        $nip = Nip::clean($nip);
        $account = Nrb::clean($account);
        $date ??= self::today();
        $r = Http::get(self::WL_CHECK . $nip . '/bank-account/' . $account . '?date=' . $date, ['Accept' => 'application/json']);
        self::count($r['status']);
        $j = json_decode($r['body'], true);
        if ($r['status'] === 400 && is_array($j)) {
            return ['ok' => false, 'error' => (string) ($j['message'] ?? 'Nieprawidłowe dane zapytania.'), 'code' => (string) ($j['code'] ?? '')];
        }
        $answer = is_array($j) ? (string) ($j['result']['accountAssigned'] ?? '') : '';
        if ($r['status'] !== 200 || !in_array($answer, ['TAK', 'NIE'], true)) {
            return ['ok' => false, 'error' => self::down($r['status'])];
        }
        return ['ok' => true, 'assigned' => $answer === 'TAK', 'request' => self::request($j['result'], $date)];
    }

    /** Ile zapytań do wykazu VAT zostało dziś dla całego panelu (szacunek z naszego licznika). */
    public static function whiteListLeft(): int
    {
        if (Kv::get('wl:blocked:' . self::today())) {
            return 0;
        }
        return max(0, self::WL_DAILY - (int) (Kv::get('wl:calls:' . self::today()) ?? 0));
    }

    /** Dzień w Polsce: limit API liczy się do północy czasu polskiego, a data zapytania to dzień polski. */
    public static function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Warsaw')))->format('Y-m-d');
    }

    private static function count(int $status): void
    {
        $key = 'wl:calls:' . self::today();
        Kv::set($key, (string) ((int) (Kv::get($key) ?? 0) + 1), 2 * 86400);
        if ($status === 429) {
            Kv::set('wl:blocked:' . self::today(), '1', 2 * 86400);
        }
    }

    private static function down(int $status): string
    {
        return 'Biała lista VAT nie odpowiada' . ($status === 429 ? ' (limit zapytań na dziś, odblokuje się o północy).' : '.');
    }

    /** Klucz zapytania z MF: identyfikator, czas zapytania (w MF „27-11-2026 21:43:58”, czas polski) i dzień, którego dotyczy. */
    private static function request(array $result, string $date): array
    {
        $at = (string) ($result['requestDateTime'] ?? '');
        $dt = \DateTimeImmutable::createFromFormat('d-m-Y H:i:s', $at, new \DateTimeZone('Europe/Warsaw'));
        return [
            'id' => mb_substr((string) ($result['requestId'] ?? ''), 0, 60),
            'at' => $dt ? $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') : now_utc(),
            'date' => $date,
        ];
    }

    /**
     * Wiele firm jednym zapytaniem (do 30 NIP) - oszczędza dzienny limit zapytań API przy odświeżaniu obserwowanych firm.
     * @param string[] $nips
     * @return array<string, array{ok: bool, subject?: ?array, error?: string}> wynik dla każdego NIP
     */
    public static function whiteListMany(array $nips): array
    {
        $nips = array_values(array_unique(array_filter(array_map([Nip::class, 'clean'], $nips), [Nip::class, 'valid'])));
        $out = [];
        foreach (array_chunk($nips, 30) as $chunk) {
            $r = Http::get(self::WL_MANY . implode(',', $chunk) . '?date=' . self::today(), ['Accept' => 'application/json']);
            self::count($r['status']);
            $j = json_decode($r['body'], true);
            if ($r['status'] !== 200 || !is_array($j)) {
                $err = self::down($r['status']);
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

    /** Listy kont są długie - przechowujemy liczbę i skrót do wykrywania zmian. */
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
            // Rejestr może zwracać puste struktury ({} albo listy bez pozycji) - sygnałem są tylko rzeczywiste wpisy.
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
