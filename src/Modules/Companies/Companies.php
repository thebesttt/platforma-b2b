<?php

declare(strict_types=1);

namespace App\Modules\Companies;

use App\Core\Db;
use App\Core\Nip;

/** Karta firmy po NIP: pobranie z rejestrów, ocena ryzyka, obserwowanie i alerty o zmianach. */
final class Companies
{
    public const FRESH_FOR = 86400; // biała lista ma dzienny limit zapytań

    public static function byNip(string $nip): ?array
    {
        return Db::get()->one('SELECT * FROM companies WHERE nip = ?', [Nip::clean($nip)]);
    }

    public static function find(int $id): ?array
    {
        return Db::get()->one('SELECT * FROM companies WHERE id = ?', [$id]);
    }

    /**
     * Zwraca kartę firmy, odświeżając dane z rejestrów, jeśli są starsze niż doba (albo $force).
     * @return array{ok: bool, company?: array, error?: string, changes?: string[]}
     */
    public static function lookup(string $nip, bool $force = false): array
    {
        $nip = Nip::clean($nip);
        if (!Nip::valid($nip)) {
            return ['ok' => false, 'error' => 'Numer NIP jest nieprawidłowy – sprawdź cyfry.'];
        }
        $existing = self::byNip($nip);
        if ($existing && !$force && $existing['refreshed_at'] && strtotime($existing['refreshed_at'] . ' UTC') > time() - self::FRESH_FOR) {
            return ['ok' => true, 'company' => $existing, 'changes' => []];
        }

        $wl = Registries::whiteList($nip);
        if (!$wl['ok']) {
            if ($existing) {
                return ['ok' => true, 'company' => $existing, 'changes' => [], 'error' => $wl['error'] . ' Pokazujemy dane z ' . local_dt($existing['refreshed_at']) . '.'];
            }
            return ['ok' => false, 'error' => $wl['error']];
        }
        $subject = $wl['subject'];
        $krsNo = $subject['krs'] ?? ($existing['krs'] ?? null);
        $krsSummary = [];
        $krsRaw = null;
        if ($krsNo) {
            $k = Registries::krs((string) $krsNo);
            if ($k['ok'] && $k['odpis']) {
                $krsRaw = $k['odpis'];
                $krsSummary = Registries::krsSummary($k['odpis']);
            } elseif ($existing && $existing['krs_json']) {
                $krsSummary = json_in($existing['krs_json']);
            }
        }
        $name = $subject['name'] ?? ($krsSummary['name'] ?? ($existing['name'] ?? null));
        if (!$subject && !$krsSummary && !$existing) {
            return ['ok' => false, 'error' => 'Nie znaleźliśmy firmy o tym numerze NIP w wykazie podatników VAT.'];
        }
        $uokik = Uokik::forCompany($nip, (string) $name);
        $risk = Risk::assess($subject, $krsSummary, $uokik);

        $snapshot = [
            'vat' => $subject['statusVat'] ?? null,
            'accounts' => $subject['accountsHash'] ?? null,
            'name' => $name,
            'board' => $krsSummary['board'] ?? null,
            'dzial4' => !empty($krsSummary['dzial4']),
            'dzial6' => $krsSummary['dzial6_keys'] ?? [],
            'uokik' => array_column($uokik, 'signature'),
            'statement' => $krsSummary['last_statement_period_end'] ?? null,
        ];
        $hash = substr(hash('sha256', json_out($snapshot)), 0, 32);
        $changes = $existing ? self::diff(json_in($existing['signals_json'])['snapshot'] ?? [], $snapshot) : [];

        $row = [
            'nip' => $nip,
            'regon' => $subject['regon'] ?? ($existing['regon'] ?? null),
            'krs' => $krsNo ? str_pad((string) $krsNo, 10, '0', STR_PAD_LEFT) : null,
            'name' => $name,
            'search' => search_text((string) $name),
            'legal_form' => $krsSummary['legal_form'] ?? ($subject['pesel'] ?? null ? 'Jednoosobowa działalność gospodarcza' : null),
            'vat_status' => $subject['statusVat'] ?? null,
            'address' => $subject['workingAddress'] ?? ($subject['residenceAddress'] ?? ($krsSummary['address'] ?? null)),
            'pkd' => isset($krsSummary['pkd'][0]) ? $krsSummary['pkd'][0] : null,
            'registered_at' => $krsSummary['registered_at'] ?? ($subject['registrationLegalDate'] ?? null),
            'whitelist_json' => $subject ? json_out(array_diff_key($subject, ['pesel' => 1])) : null,
            'krs_json' => $krsSummary ? json_out($krsSummary) : null,
            'signals_json' => json_out(['risk' => $risk, 'uokik' => $uokik, 'snapshot' => $snapshot]),
            'risk_score' => $risk['score'],
            'snapshot_hash' => $hash,
            'refreshed_at' => now_utc(),
        ];
        $db = Db::get();
        if ($existing) {
            $db->update('companies', $row, 'id = ?', [(int) $existing['id']]);
            $id = (int) $existing['id'];
        } else {
            $row['created_at'] = now_utc();
            $id = $db->insert('companies', $row);
        }
        if ($changes) {
            self::alertWatchers($id, (string) $name, $changes, $risk);
        }
        unset($krsRaw);
        return ['ok' => true, 'company' => self::find($id), 'changes' => $changes];
    }

    /** @return string[] opisy zmian zrozumiałe dla użytkownika */
    public static function diff(array $old, array $new): array
    {
        if (!$old) {
            return [];
        }
        $out = [];
        if (($old['vat'] ?? null) !== ($new['vat'] ?? null)) {
            $out[] = 'Zmiana statusu VAT: ' . ($old['vat'] ?? 'brak') . ' → ' . ($new['vat'] ?? 'brak');
        }
        if (($old['accounts'] ?? null) !== ($new['accounts'] ?? null)) {
            $out[] = 'Zmiana rachunków bankowych na białej liście VAT – potwierdź numer konta przed przelewem';
        }
        if (($old['board'] ?? null) !== ($new['board'] ?? null) && $old['board'] !== null) {
            $out[] = 'Zmiana składu zarządu w KRS';
        }
        if (empty($old['dzial4']) && !empty($new['dzial4'])) {
            $out[] = 'Nowe wpisy o zaległościach lub wierzytelnościach w KRS (dział 4)';
        }
        if (($old['dzial6'] ?? []) !== ($new['dzial6'] ?? [])) {
            $out[] = 'Zmiana w dziale 6 KRS (likwidacja, upadłość, przekształcenia)';
        }
        $newDecisions = array_diff($new['uokik'] ?? [], $old['uokik'] ?? []);
        if ($newDecisions) {
            $out[] = 'Nowa decyzja UOKiK o opóźnianiu płatności: ' . implode(', ', $newDecisions);
        }
        if (($old['statement'] ?? null) !== ($new['statement'] ?? null) && $new['statement']) {
            $out[] = 'Nowe sprawozdanie finansowe w KRS';
        }
        if (($old['name'] ?? null) !== ($new['name'] ?? null) && $old['name']) {
            $out[] = 'Zmiana nazwy firmy';
        }
        return $out;
    }

    private static function alertWatchers(int $companyId, string $name, array $changes, array $risk): void
    {
        $db = Db::get();
        $level = $risk['level'] === 'high' ? 'high' : 'mid';
        foreach ($db->all('SELECT org_id FROM watchlist WHERE company_id = ?', [$companyId]) as $w) {
            foreach ($changes as $c) {
                $db->insert('alerts', [
                    'org_id' => $w['org_id'],
                    'company_id' => $companyId,
                    'level' => str_contains($c, 'UOKiK') || str_contains($c, 'dział 4') || str_contains($c, 'dziale 6') || str_contains($c, 'rachunków') ? 'high' : $level,
                    'title' => $name,
                    'detail' => $c,
                    'created_at' => now_utc(),
                ]);
            }
        }
    }

    /** Odświeża obserwowane firmy (zadanie codzienne). */
    public static function refreshWatched(int $limit = 80): array
    {
        $rows = Db::get()->all(
            'SELECT DISTINCT c.nip, c.refreshed_at FROM companies c JOIN watchlist w ON w.company_id = c.id ORDER BY c.refreshed_at LIMIT ' . (int) $limit
        );
        $done = 0;
        $changed = 0;
        foreach ($rows as $r) {
            $res = self::lookup($r['nip'], true);
            $done++;
            $changed += count($res['changes'] ?? []);
            usleep(300000);
        }
        return ['checked' => $done, 'changes' => $changed];
    }

    public static function isWatched(int $orgId, int $companyId): bool
    {
        return (bool) Db::get()->val('SELECT 1 FROM watchlist WHERE org_id = ? AND company_id = ?', [$orgId, $companyId]);
    }
}
