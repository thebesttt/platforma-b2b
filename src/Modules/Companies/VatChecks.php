<?php

declare(strict_types=1);

namespace App\Modules\Companies;

use App\Core\Db;
use App\Core\Kv;
use App\Core\Nrb;

/**
 * Sprawdzenia na białej liście VAT robione z karty kontrahenta: rachunek z faktury przed przelewem i status VAT na wybrany dzień.
 *
 * Po co: przy płatności powyżej 15 000 zł brutto dla czynnego podatnika VAT przelew na rachunek spoza wykazu (w dniu zlecenia
 * przelewu) wyłącza wydatek z kosztów podatkowych i daje solidarną odpowiedzialność za VAT sprzedawcy, chyba że w ciągu 7 dni
 * złożysz zawiadomienie ZAW-NR. Klucz zapytania z MF zapisany przy sprawdzeniu to dowód, że sprawdzenie było.
 *
 * API ma limit 100 zapytań na dobę dla całego adresu panelu, więc każda firma ma dzienny przydział, a część limitu zostaje
 * dla nocnego odświeżania obserwowanych kontrahentów.
 */
final class VatChecks
{
    /** Ile sprawdzeń dziennie może zrobić jedna firma. */
    public const DAILY_PER_ORG = 20;

    /** Tyle zapytań z dziennego limitu API zostawiamy na nocne odświeżanie obserwowanych firm. */
    public const RESERVE = 10;

    /** Próg płatności, od którego liczy się rachunek z białej listy (art. 22p ustawy o PIT, art. 15d ustawy o CIT). */
    public const THRESHOLD = 15000;

    /** Najwcześniejszy dzień, o który przyjmujemy pytanie. Starsze daty API odrzuca („Data sprzed zakresu rejestru”). */
    public const EARLIEST = '2020-01-01';

    /**
     * Sprawdzenie rachunku. Zapisuje wynik z kluczem MF.
     * @return array{ok: bool, assigned?: bool, row?: array, error?: string}
     */
    public static function checkAccount(int $orgId, ?int $userId, array $company, string $account, string $date, ?float $amount = null): array
    {
        $account = Nrb::clean($account);
        if (!Nrb::valid($account)) {
            return ['ok' => false, 'error' => 'Numer rachunku ma błąd: polski rachunek ma 26 cyfr i sumę kontrolną. Przepisz go z faktury jeszcze raz.'];
        }
        if (($err = self::checkDate($date)) !== null || ($err = self::room($orgId)) !== null) {
            return ['ok' => false, 'error' => $err];
        }
        $res = Registries::checkAccount((string) $company['nip'], $account, $date);
        self::spend($orgId);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => self::apiError($res)];
        }
        $row = self::save($orgId, $userId, $company, 'account', $date, $res['assigned'] ? 'TAK' : 'NIE', $res['request'], [], $account, $amount);
        return ['ok' => true, 'assigned' => $res['assigned'], 'row' => $row];
    }

    /**
     * Status VAT na wybrany dzień (np. dzień wystawienia faktury). Zapisuje status, daty wykreślenia lub przywrócenia i klucz MF.
     * @return array{ok: bool, status?: string, row?: array, error?: string}
     */
    public static function checkStatus(int $orgId, ?int $userId, array $company, string $date): array
    {
        if (($err = self::checkDate($date)) !== null || ($err = self::room($orgId)) !== null) {
            return ['ok' => false, 'error' => $err];
        }
        $res = Registries::whiteList((string) $company['nip'], $date);
        self::spend($orgId);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => self::apiError($res)];
        }
        $s = $res['subject'];
        $status = $s ? ((string) ($s['statusVat'] ?? '') ?: 'brak statusu') : 'brak w wykazie';
        $details = $s ? array_filter([
            'name' => $s['name'] ?? null, 'registrationLegalDate' => $s['registrationLegalDate'] ?? null,
            'removalDate' => $s['removalDate'] ?? null, 'removalBasis' => $s['removalBasis'] ?? null,
            'restorationDate' => $s['restorationDate'] ?? null, 'restorationBasis' => $s['restorationBasis'] ?? null,
            'accountCount' => $s['accountCount'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '') : [];
        $row = self::save($orgId, $userId, $company, 'status', $date, $status, $res['request'], $details);
        return ['ok' => true, 'status' => $status, 'row' => $row];
    }

    public static function forCompany(int $orgId, int $companyId, int $limit = 50): array
    {
        return Db::get()->all(
            'SELECT v.*, u.name AS user_name FROM vat_checks v LEFT JOIN users u ON u.id = v.created_by
             WHERE v.org_id = ? AND v.company_id = ? ORDER BY v.id DESC LIMIT ' . max(1, $limit),
            [$orgId, $companyId]
        );
    }

    /** Ile sprawdzeń firma może dziś jeszcze zrobić (mniejsza z liczb: przydział firmy albo to, co zostało z limitu API). */
    public static function left(int $orgId): int
    {
        $own = self::DAILY_PER_ORG - (int) (Kv::get(self::key($orgId)) ?? 0);
        return max(0, min($own, Registries::whiteListLeft() - self::RESERVE));
    }

    /**
     * Potwierdzenia sprawdzeń w CSV dla księgowości: dzień, rachunek, wynik i klucz MF.
     * Pola zaczynające się od znaku formuły dostają apostrof, żeby arkusz nie wykonał ich jako formuły.
     */
    public static function csv(int $orgId, array $company): string
    {
        $rows = Db::get()->all('SELECT v.*, u.name AS user_name FROM vat_checks v LEFT JOIN users u ON u.id = v.created_by WHERE v.org_id = ? AND v.company_id = ? ORDER BY v.id', [$orgId, (int) $company['id']]);
        $cell = static function ($v): string {
            $v = (string) $v;
            if ($v !== '' && str_contains('=+-@', $v[0])) {
                $v = "'" . $v;
            }
            return '"' . str_replace('"', '""', $v) . '"';
        };
        $out = "\xEF\xBB\xBF" . implode(';', array_map($cell, ['Kontrahent', 'NIP', 'Co sprawdzono', 'Rachunek', 'Kwota', 'Na dzień', 'Wynik', 'Klucz zapytania MF', 'Czas zapytania MF', 'Kto sprawdził'])) . "\r\n";
        foreach ($rows as $r) {
            $out .= implode(';', array_map($cell, [
                $company['name'] ?? '', $r['nip'], $r['kind'] === 'account' ? 'rachunek' : 'status VAT', $r['account'] ? Nrb::format((string) $r['account']) : '',
                $r['amount'] !== null ? number_format((float) $r['amount'], 2, ',', '') : '', $r['check_date'], $r['result'], $r['request_id'],
                local_dt((string) $r['request_at'], 'd.m.Y H:i:s'), $r['user_name'] ?? '',
            ])) . "\r\n";
        }
        return $out;
    }

    /** Opis wyniku po ludzku: co znaczy TAK albo NIE przy tej kwocie i co zrobić. */
    public static function verdict(array $row): string
    {
        $day = date('d.m.Y', strtotime((string) $row['check_date']));
        if ($row['kind'] === 'status') {
            return 'Status VAT w dniu ' . $day . ': ' . $row['result'] . '.';
        }
        if ($row['result'] === 'TAK') {
            return 'Rachunek ' . Nrb::format((string) $row['account']) . ' jest na białej liście w dniu ' . $day . '. Klucz zapytania MF: ' . $row['request_id'] . '.';
        }
        $big = $row['amount'] !== null && (float) $row['amount'] > self::THRESHOLD;
        return 'Rachunku ' . Nrb::format((string) $row['account']) . ' nie ma na białej liście w dniu ' . $day . ' (albo firma nie jest czynnym podatnikiem VAT). '
            . ($big || $row['amount'] === null
                ? 'Przy płatności powyżej 15 000 zł przelew na ten rachunek może wyłączyć wydatek z kosztów i dać solidarną odpowiedzialność za VAT. Poproś kontrahenta o rachunek z wykazu. Jeśli przelew już poszedł, złóż zawiadomienie ZAW-NR w ciągu 7 dni od zlecenia przelewu. '
                : 'Przy tej kwocie (do 15 000 zł) sankcje z białej listy nie działają, ale upewnij się, że numer jest właściwy. ')
            . 'Klucz zapytania MF: ' . $row['request_id'] . '.';
    }

    private static function save(int $orgId, ?int $userId, array $company, string $kind, string $date, string $result, array $request, array $details, ?string $account = null, ?float $amount = null): array
    {
        $db = Db::get();
        $id = $db->insert('vat_checks', [
            'org_id' => $orgId, 'company_id' => (int) $company['id'], 'nip' => (string) $company['nip'], 'kind' => $kind,
            'account' => $account, 'amount' => $amount, 'check_date' => $date, 'result' => mb_substr($result, 0, 40),
            'details_json' => $details ? json_out($details) : null, 'request_id' => $request['id'] !== '' ? $request['id'] : '(brak klucza w odpowiedzi)',
            'request_at' => $request['at'], 'created_by' => $userId, 'created_at' => now_utc(),
        ]);
        return $db->one('SELECT * FROM vat_checks WHERE id = ?', [$id]) ?? [];
    }

    private static function checkDate(string $date): ?string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            return 'Podaj dzień w formacie RRRR-MM-DD.';
        }
        if ($date > Registries::today()) {
            return 'Wykaz odpowiada tylko za dni do dziś. Sprawdź rachunek w dniu zlecenia przelewu.';
        }
        if ($date < self::EARLIEST) {
            return 'Wykaz w API obejmuje ostatnie lata. Podaj dzień od ' . date('d.m.Y', strtotime(self::EARLIEST)) . '.';
        }
        return null;
    }

    private static function room(int $orgId): ?string
    {
        if ((int) (Kv::get(self::key($orgId)) ?? 0) >= self::DAILY_PER_ORG) {
            return 'Twoja firma wykorzystała dziś ' . self::DAILY_PER_ORG . ' sprawdzeń na białej liście. Kolejne jutro albo w wyszukiwarce na podatki.gov.pl.';
        }
        if (Registries::whiteListLeft() <= self::RESERVE) {
            return 'Dzienny limit zapytań do wykazu VAT jest prawie wyczerpany (limit Ministerstwa Finansów dla całego panelu). Sprawdź rachunek w wyszukiwarce na podatki.gov.pl albo jutro.';
        }
        return null;
    }

    private static function spend(int $orgId): void
    {
        $key = self::key($orgId);
        Kv::set($key, (string) ((int) (Kv::get($key) ?? 0) + 1), 2 * 86400);
    }

    private static function key(int $orgId): string
    {
        return 'wl:org:' . $orgId . ':' . Registries::today();
    }

    private static function apiError(array $res): string
    {
        return match ($res['code'] ?? '') {
            'WL-111' => 'Wykaz odrzucił numer rachunku jako nieprawidłowy. Przepisz go z faktury jeszcze raz.',
            'WL-103' => 'Wykaz odpowiada tylko za dni do dziś.',
            'WL-118' => 'Wykaz nie ma danych na ten dzień (data sprzed zakresu rejestru). Wybierz późniejszy dzień.',
            default => (string) ($res['error'] ?? 'Biała lista VAT nie odpowiada.'),
        };
    }
}
