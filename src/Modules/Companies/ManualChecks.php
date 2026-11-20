<?php

declare(strict_types=1);

namespace App\Modules\Companies;

use App\Core\Db;

/**
 * Sprawdzenia, których panel nie zrobi sam, bo rejestr nie udostępnia danych programom:
 *  - Krajowy Rejestr Zadłużonych (upadłości, restrukturyzacje, umorzone egzekucje),
 *  - sprawozdanie finansowe z Repozytorium Dokumentów Finansowych (do oszacowania, po ilu dniach firma płaci dostawcom).
 * Karta firmy pokazuje, gdzie to znaleźć, a użytkownik wpisuje wynik. Wpisy widzi tylko jego firma i nie zmieniają one
 * oceny ryzyka liczonej z danych publicznych.
 */
final class ManualChecks
{
    public const KRZ_URL = 'https://krz.ms.gov.pl/';
    public const RDF_URL = 'https://ekrs.ms.gov.pl/';
    public const RDF_HELP_URL = 'https://ekrs.ms.gov.pl/rdf/rd/';

    public const KRZ_TYPES = [
        'bankruptcy' => 'Postępowanie upadłościowe',
        'restructuring' => 'Postępowanie restrukturyzacyjne',
        'enforcement' => 'Umorzona egzekucja',
        'ban' => 'Zakaz prowadzenia działalności gospodarczej',
        'other' => 'Inny wpis',
    ];

    /** Po tylu dniach sprawdzenie w KRZ uznajemy za nieaktualne i prosimy o powtórkę. */
    public const KRZ_FRESH_DAYS = 90;

    /** @return array{ok: bool, error?: string} */
    public static function saveKrz(int $orgId, int $companyId, int $userId, array $in): array
    {
        $found = ($in['result'] ?? '') === 'found';
        if (!$found && ($in['result'] ?? '') !== 'none') {
            return ['ok' => false, 'error' => 'Zaznacz, czy w rejestrze jest wpis o tej firmie.'];
        }
        $on = self::date((string) ($in['checked_on'] ?? '')) ?? gmdate('Y-m-d');
        if ($on > gmdate('Y-m-d', time() + 86400)) {
            return ['ok' => false, 'error' => 'Data sprawdzenia nie może być w przyszłości.'];
        }
        $data = ['result' => $found ? 'found' : 'none'];
        if ($found) {
            $type = (string) ($in['type'] ?? '');
            if (!isset(self::KRZ_TYPES[$type])) {
                return ['ok' => false, 'error' => 'Wybierz rodzaj wpisu w rejestrze.'];
            }
            $data += [
                'type' => $type,
                'signature' => mb_substr(utrim((string) ($in['signature'] ?? '')), 0, 80),
                'entry_date' => self::date((string) ($in['entry_date'] ?? '')),
                'note' => mb_substr(utrim((string) ($in['note'] ?? '')), 0, 500),
            ];
        }
        Db::get()->insert('company_checks', [
            'org_id' => $orgId, 'company_id' => $companyId, 'kind' => 'krz', 'data_json' => json_out($data),
            'checked_at' => $on, 'created_by' => $userId, 'created_at' => now_utc(),
        ]);
        return ['ok' => true];
    }

    /** @return array{ok: bool, error?: string} */
    public static function saveFinance(int $orgId, int $companyId, int $userId, array $in): array
    {
        $year = (int) ($in['year'] ?? 0);
        if ($year < 2000 || $year > (int) gmdate('Y')) {
            return ['ok' => false, 'error' => 'Podaj rok obrotowy sprawozdania, np. ' . ((int) gmdate('Y') - 1) . '.'];
        }
        $payables = self::number((string) ($in['payables'] ?? ''));
        $purchases = self::number((string) ($in['purchases'] ?? ''));
        if ($payables === null || $payables < 0) {
            return ['ok' => false, 'error' => 'Wpisz zobowiązania z tytułu dostaw i usług (kwota z bilansu, bez liter).'];
        }
        if ($purchases === null || $purchases <= 0) {
            return ['ok' => false, 'error' => 'Wpisz koszty zakupów od dostawców (kwota większa od zera).'];
        }
        $data = ['year' => $year, 'payables' => $payables, 'purchases' => $purchases];
        foreach (['revenue', 'receivables', 'net_result', 'equity'] as $k) {
            $v = self::number((string) ($in[$k] ?? ''));
            if (trim((string) ($in[$k] ?? '')) !== '' && $v === null) {
                return ['ok' => false, 'error' => 'Jedna z kwot ma nieczytelny zapis. Wpisuj same liczby, np. 1 234 567,89.'];
            }
            $data[$k] = $v;
        }
        Db::get()->insert('company_checks', [
            'org_id' => $orgId, 'company_id' => $companyId, 'kind' => 'finance', 'data_json' => json_out($data),
            'checked_at' => gmdate('Y-m-d'), 'created_by' => $userId, 'created_at' => now_utc(),
        ]);
        return ['ok' => true];
    }

    public static function delete(int $orgId, int $companyId, int $id): bool
    {
        return Db::get()->run('DELETE FROM company_checks WHERE id = ? AND org_id = ? AND company_id = ?', [$id, $orgId, $companyId])->rowCount() > 0;
    }

    /**
     * Stan sprawdzeń firmy dla tej organizacji.
     * @return array{krz: ?array, krz_age: ?int, krz_stale: bool, finance: array<int, array>}
     */
    public static function forCompany(int $orgId, int $companyId): array
    {
        $rows = Db::get()->all(
            'SELECT k.*, u.name AS user_name, u.email AS user_email FROM company_checks k LEFT JOIN users u ON u.id = k.created_by
              WHERE k.org_id = ? AND k.company_id = ? ORDER BY k.checked_at DESC, k.id DESC',
            [$orgId, $companyId]
        );
        $krz = null;
        $finance = [];
        foreach ($rows as $r) {
            $r['data'] = json_in($r['data_json']);
            $r['by'] = trim((string) $r['user_name']) ?: (string) $r['user_email'];
            if ($r['kind'] === 'krz') {
                $krz ??= $r;
            } elseif ($r['kind'] === 'finance' && !isset($finance[(int) $r['data']['year']])) {
                // Dla każdego roku liczy się najnowszy wpis.
                $finance[(int) $r['data']['year']] = $r + ['calc' => self::finance($r['data'])];
            }
        }
        krsort($finance);
        $age = $krz ? (int) floor((strtotime(gmdate('Y-m-d')) - strtotime((string) $krz['checked_at'])) / 86400) : null;
        return ['krz' => $krz, 'krz_age' => $age, 'krz_stale' => $age !== null && $age > self::KRZ_FRESH_DAYS, 'finance' => array_values($finance)];
    }

    /**
     * Wskaźniki z liczb sprawozdania. Czas płacenia dostawcom to zobowiązania handlowe podzielone przez roczne zakupy, razy 365.
     * Skala jest umowna: do 60 dni typowo, 61-90 długo, powyżej 90 bardzo długo.
     */
    public static function finance(array $d): array
    {
        $dpo = (float) $d['purchases'] > 0 ? (int) round((float) $d['payables'] / (float) $d['purchases'] * 365) : null;
        $dso = !empty($d['revenue']) && (float) $d['revenue'] > 0 && $d['receivables'] !== null ? (int) round((float) $d['receivables'] / (float) $d['revenue'] * 365) : null;
        $level = $dpo === null ? 'neutral' : ($dpo > 90 ? 'high' : ($dpo > 60 ? 'mid' : 'low'));
        $notes = [];
        if (($d['equity'] ?? null) !== null && (float) $d['equity'] < 0) {
            $notes[] = 'Kapitał własny jest ujemny: zobowiązania przewyższają majątek.';
        }
        if (($d['net_result'] ?? null) !== null && (float) $d['net_result'] < 0) {
            $notes[] = 'Rok zakończył się stratą netto.';
        }
        return [
            'dpo' => $dpo,
            'dso' => $dso,
            'level' => $level,
            'label' => $dpo === null ? '' : ($dpo > 90 ? 'bardzo długo' : ($dpo > 60 ? 'długo' : 'typowo')),
            'notes' => $notes,
        ];
    }

    /** „1 234 567,89”, „1.234.567,89”, „1234567.89”, „-12 000” → liczba. Null, gdy to nie kwota. */
    public static function number(string $s): ?float
    {
        $s = preg_replace('/[\s\x{00A0}]|zł|PLN/iu', '', trim($s)) ?? '';
        if ($s === '' || !preg_match('/^-?[\d.,]+$/', $s)) {
            return null;
        }
        if (str_contains($s, ',')) {
            $s = str_replace(',', '.', str_replace('.', '', $s));
        } elseif (substr_count($s, '.') > 1 || preg_match('/\.\d{3}$/', $s)) {
            $s = str_replace('.', '', $s);
        }
        return is_numeric($s) ? round((float) $s, 2) : null;
    }

    private static function date(string $s): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) !== false ? $s : null;
    }

    /** Obserwowane firmy bez aktualnego sprawdzenia w KRZ (do przypomnienia na liście kontrahentów). */
    public static function watchedWithoutKrz(int $orgId): array
    {
        $limit = gmdate('Y-m-d', time() - self::KRZ_FRESH_DAYS * 86400);
        return Db::get()->all(
            "SELECT c.nip, c.name, (SELECT MAX(k.checked_at) FROM company_checks k WHERE k.org_id = w.org_id AND k.company_id = c.id AND k.kind = 'krz') AS last_krz
               FROM watchlist w JOIN companies c ON c.id = w.company_id
              WHERE w.org_id = ?
                AND NOT EXISTS (SELECT 1 FROM company_checks k WHERE k.org_id = w.org_id AND k.company_id = c.id AND k.kind = 'krz' AND k.checked_at >= ?)
              ORDER BY c.name LIMIT 50",
            [$orgId, $limit]
        );
    }
}
