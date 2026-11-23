<?php

declare(strict_types=1);

namespace App\Modules\Companies;

use App\Core\Config;
use App\Core\Db;
use App\Core\Http;
use App\Core\Kv;
use App\Core\Nip;
use App\Modules\Tenders\Regions;

/**
 * Wyszukiwanie jednoosobowych działalności w CEIDG (hurtownia danych biznes.gov.pl, API v3).
 *
 * Dostęp jest bezpłatny, ale wymaga tokenu: właściciel składa na biznes.gov.pl „Wniosek o dostęp do raportów CEIDG”
 * i wpisuje token w zmiennej CEIDG_TOKEN. Bez tokenu moduł jest wyłączony, a panel pokazuje, jak go zdobyć.
 * Z odpowiedzi bierzemy tylko nazwę, NIP, REGON, adres i datę rozpoczęcia. Adresów e-mail i telefonów nie pobieramy:
 * lista potencjalnych klientów nie jest bazą adresów do wysyłki.
 *
 * Wywołanie jest napisane według dokumentacji dla integratorów (wersja 1.1) i sprawdzone na odpowiedzi w jej formacie,
 * bez prawdziwego tokenu.
 */
final class Ceidg
{
    private const BASE = 'https://dane.biznes.gov.pl/api/ceidg/v3';

    /** Największa strona wyników, jaką przyjmuje API. */
    public const PAGE = 25;

    /** API pozwala na 50 zapytań w 3 minuty. Zostawiamy zapas. */
    private const WINDOW_LIMIT = 40;

    /** Okresy „założone w ostatnich…” w dniach. Zero: bez ograniczenia. */
    public const SINCE = [0 => 'kiedykolwiek', 30 => 'w ostatnich 30 dniach', 90 => 'w ostatnich 90 dniach', 365 => 'w ostatnim roku'];

    public static function enabled(): bool
    {
        return trim((string) Config::get('CEIDG_TOKEN')) !== '';
    }

    /** Kod PKD w zapisie API: bez kropek i odstępów, np. „62.01.Z” to „6201Z”. Null, gdy to nie jest kod podklasy. */
    public static function pkd(string $code): ?string
    {
        $c = mb_strtoupper((string) preg_replace('/[\s.]+/u', '', $code));
        return preg_match('/^\d{4}[A-Z]$/', $c) ? $c : null;
    }

    /**
     * Aktywne działalności o podanych kodach PKD, w województwie i mieście, założone w podanym okresie.
     * @param array{pkd?: string, woj?: string, miasto?: string, dni?: int} $f
     * @return array{ok: bool, rows?: array<int, array>, count?: int, error?: string}
     */
    public static function search(array $f, int $page = 0): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'error' => 'Wyszukiwanie w CEIDG wymaga tokenu (zmienna CEIDG_TOKEN).'];
        }
        $codes = [];
        foreach (preg_split('/[,;\s]+/u', (string) ($f['pkd'] ?? '')) ?: [] as $raw) {
            if ($raw === '') {
                continue;
            }
            $c = self::pkd($raw);
            if ($c === null) {
                return ['ok' => false, 'error' => 'Kod PKD „' . truncate($raw, 20) . '” ma zły zapis. Podaj kod podklasy, np. 62.01.Z albo 4120Z.'];
            }
            $codes[$c] = true;
        }
        if (!$codes) {
            return ['ok' => false, 'error' => 'Podaj co najmniej jeden kod PKD.'];
        }
        if (count($codes) > 5) {
            return ['ok' => false, 'error' => 'Najwyżej 5 kodów PKD w jednym wyszukiwaniu.'];
        }
        $region = isset(Regions::ALL[(string) ($f['woj'] ?? '')]) ? Regions::ALL[(string) $f['woj']][0] : '';
        $city = mb_substr(utrim((string) ($f['miasto'] ?? '')), 0, 60);
        $days = (int) ($f['dni'] ?? 0);
        $days = isset(self::SINCE[$days]) ? $days : 0;

        $build = static function (string $regionValue) use ($codes, $city, $days, $page): string {
            $q = ['status=AKTYWNY'];
            foreach (array_keys($codes) as $c) {
                $q[] = 'pkd=' . rawurlencode($c);
            }
            if ($regionValue !== '') {
                $q[] = 'wojewodztwo=' . rawurlencode($regionValue);
            }
            if ($city !== '') {
                $q[] = 'miasto=' . rawurlencode($city);
            }
            if ($days > 0) {
                $q[] = 'dataod=' . date('Y-m-d', time() - $days * 86400);
            }
            $q[] = 'limit=' . self::PAGE;
            $q[] = 'page=' . max(0, $page);
            return self::BASE . '/firmy?' . implode('&', $q);
        };
        // Wielkość liter w wartościach ma znaczenie, a dokumentacja nie podaje zapisu województw.
        // Pytamy wielkimi literami (tak zapisuje je TERYT), a przy pustym wyniku jeszcze raz małymi.
        $res = self::call($build($region !== '' ? mb_strtoupper($region) : ''));
        if ($res['ok'] && $res['count'] === 0 && $region !== '') {
            $res = self::call($build(mb_strtolower($region)));
        }
        return $res;
    }

    /** Zapytanie kontrolne do Ustawień: sprawdza token i połączenie. @return array{ok: bool, error?: string, count?: int} */
    public static function check(): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'error' => 'Brak tokenu. Wpisz go w zmiennej CEIDG_TOKEN.'];
        }
        return self::call(self::BASE . '/firmy?status=AKTYWNY&pkd=6201Z&limit=1&page=0');
    }

    /** @return array{ok: bool, rows?: array, count?: int, error?: string} */
    private static function call(string $url): array
    {
        if (Kv::get('ceidg:blocked')) {
            return ['ok' => false, 'error' => 'CEIDG wstrzymał zapytania po przekroczeniu limitu. Spróbuj ponownie za 3 minuty.'];
        }
        if (Kv::hit('ceidg:okno:' . intdiv(time(), 180), 180) > self::WINDOW_LIMIT) {
            return ['ok' => false, 'error' => 'CEIDG pozwala na 50 zapytań w 3 minuty. Spróbuj ponownie za chwilę.'];
        }
        $r = Http::get($url, ['Authorization' => 'Bearer ' . trim((string) Config::get('CEIDG_TOKEN')), 'Accept' => 'application/json'], 25);
        if ($r['status'] === 204) {
            return ['ok' => true, 'rows' => [], 'count' => 0];
        }
        if ($r['status'] === 429) {
            Kv::set('ceidg:blocked', 1, 190);
            return ['ok' => false, 'error' => 'CEIDG wstrzymał zapytania po przekroczeniu limitu. Spróbuj ponownie za 3 minuty.'];
        }
        if ($r['status'] === 401 || $r['status'] === 403) {
            return ['ok' => false, 'error' => 'CEIDG odrzucił token. Sprawdź zmienną CEIDG_TOKEN: sam token, bez słowa „Bearer” i bez spacji.'];
        }
        if ($r['status'] === 400) {
            return ['ok' => false, 'error' => 'CEIDG nie przyjął zapytania. Sprawdź kod PKD i nazwę miasta.'];
        }
        if ($r['status'] !== 200) {
            error_log('[ceidg] HTTP ' . $r['status'] . ' ' . ($r['error'] ?? ''));
            return ['ok' => false, 'error' => 'CEIDG nie odpowiada. Spróbuj ponownie później.'];
        }
        $j = json_decode($r['body'], true);
        if (!is_array($j) || !is_array($j['firmy'] ?? null)) {
            return ['ok' => false, 'error' => 'CEIDG odpowiedział w nieznanym formacie.'];
        }
        $rows = [];
        foreach ($j['firmy'] as $firma) {
            if (is_array($firma) && ($row = self::row($firma))) {
                $rows[$row['nip']] = $row;
            }
        }
        return ['ok' => true, 'rows' => array_values($rows), 'count' => max(count($rows), (int) ($j['count'] ?? 0))];
    }

    /**
     * Jedna firma z odpowiedzi API w naszym układzie. Wpis bez poprawnego NIP-u albo nieaktywny pomijamy.
     * @return ?array{nip: string, regon: ?string, name: string, owner: string, address: string, city: string, voivodeship: string, started: ?string}
     */
    public static function row(array $firma): ?array
    {
        $owner = is_array($firma['wlasciciel'] ?? null) ? $firma['wlasciciel'] : [];
        $nip = Nip::clean((string) ($owner['nip'] ?? ''));
        if (!Nip::valid($nip) || (isset($firma['status']) && $firma['status'] !== 'AKTYWNY')) {
            return null;
        }
        $a = is_array($firma['adresDzialalnosci'] ?? null) ? array_map(static fn ($v) => is_scalar($v) ? utrim((string) $v) : '', $firma['adresDzialalnosci']) : [];
        $ownerName = utrim(trim((string) ($owner['imie'] ?? '')) . ' ' . trim((string) ($owner['nazwisko'] ?? '')));
        $name = mb_substr(utrim((string) ($firma['nazwa'] ?? '')) ?: $ownerName, 0, 200);
        if ($name === '') {
            return null;
        }
        $street = trim(($a['ulica'] ?? '') . ' ' . ($a['budynek'] ?? '') . (($a['lokal'] ?? '') !== '' ? ' lok. ' . $a['lokal'] : ''));
        $city = (string) ($a['miasto'] ?? '');
        $zip = preg_match('/^\d{2}-?\d{3}$/', (string) ($a['kod'] ?? '')) ? substr_replace(str_replace('-', '', $a['kod']), '-', 2, 0) : '';
        // Wieś bez nazw ulic: sam numer budynku przy nazwie miejscowości.
        $line1 = ($a['ulica'] ?? '') === '' && $street !== '' && $city !== '' ? $city . ' ' . $street : $street;
        $address = trim(implode(', ', array_filter([$line1, trim($zip . ' ' . $city)])));
        $slug = '';
        foreach (Regions::ALL as $key => $r) {
            if (mb_strtolower($r[0]) === mb_strtolower((string) ($a['wojewodztwo'] ?? ''))) {
                $slug = $key;
                break;
            }
        }
        $started = (string) ($firma['dataRozpoczecia'] ?? '');
        return [
            'nip' => $nip, 'regon' => preg_match('/^\d{9}(\d{5})?$/', (string) ($owner['regon'] ?? '')) ? (string) $owner['regon'] : null,
            'name' => $name, 'owner' => mb_substr($ownerName, 0, 120), 'address' => mb_substr($address, 0, 300), 'city' => mb_substr($city, 0, 80),
            'voivodeship' => $slug, 'started' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $started) ? $started : null,
        ];
    }

    /**
     * Zapis firmy z CEIDG w bazie firm. Wpis z wykazu VAT albo KRS ma pierwszeństwo: uzupełniamy tylko to, czego brakuje.
     * @return int identyfikator firmy
     */
    public static function saveCompany(array $row): int
    {
        $db = Db::get();
        $existing = Companies::byNip($row['nip']);
        $data = ['name' => $row['name'], 'search' => search_text($row['name']), 'address' => $row['address'] !== '' ? $row['address'] : null,
            'regon' => $row['regon'], 'registered_at' => $row['started'], 'legal_form' => 'Jednoosobowa działalność gospodarcza'];
        if ($existing) {
            $upd = [];
            foreach ($data as $k => $v) {
                if (empty($existing[$k]) && $v !== null && $v !== '') {
                    $upd[$k] = $v;
                }
            }
            if ($upd) {
                $db->update('companies', $upd, 'id = ?', [(int) $existing['id']]);
            }
            return (int) $existing['id'];
        }
        return $db->insert('companies', $data + ['nip' => $row['nip'], 'created_at' => now_utc()]);
    }
}
