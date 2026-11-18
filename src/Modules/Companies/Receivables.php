<?php

declare(strict_types=1);

namespace App\Modules\Companies;

use App\Core\Db;
use App\Core\Nip;

/**
 * Należności od kontrahentów: przeterminowanie, wezwanie do zapłaty i warunki wpisu do biura informacji gospodarczej (BIG).
 * Dane o długach zgłoszone przez użytkownika NIE są pokazywane innym użytkownikom platformy – trafiają tylko do BIG
 * (np. KRD) przez partnera, po spełnieniu warunków ustawy o udostępnianiu informacji gospodarczych.
 */
final class Receivables
{
    public const BIG_MIN_AMOUNT = 500.0;   // art. 14 ust. 1 pkt 2 – dług przedsiębiorcy
    public const BIG_MIN_DAYS = 30;        // art. 14 ust. 1 pkt 3
    public const BIG_DEMAND_WAIT_DAYS = 30; // co najmniej miesiąc od wysłania wezwania z ostrzeżeniem (art. 14 ust. 1 pkt 4)

    public static function forCompany(int $orgId, int $companyId): array
    {
        return Db::get()->all('SELECT * FROM receivables WHERE org_id = ? AND company_id = ? ORDER BY due_at', [$orgId, $companyId]);
    }

    public static function find(int $orgId, int $id): ?array
    {
        return Db::get()->one('SELECT * FROM receivables WHERE org_id = ? AND id = ?', [$orgId, $id]);
    }

    public static function daysOverdue(array $r, ?int $now = null): int
    {
        if (!empty($r['paid_at'])) {
            return 0;
        }
        $now ??= time();
        $due = strtotime((string) $r['due_at'] . ' 00:00:00');
        return max(0, (int) floor(($now - $due) / 86400));
    }

    /**
     * Lista warunków wpisu do BIG z informacją, które są spełnione.
     * @return array{eligible: bool, checks: array<int, array{ok: bool, text: string}>, eligible_from: ?string}
     */
    public static function bigEligibility(array $r, ?int $now = null): array
    {
        $now ??= time();
        $days = self::daysOverdue($r, $now);
        $demandAt = !empty($r['demand_sent_at']) ? strtotime((string) $r['demand_sent_at'] . ' 00:00:00') : null;
        $waitOk = $demandAt !== null && $now - $demandAt >= self::BIG_DEMAND_WAIT_DAYS * 86400;
        $checks = [
            ['ok' => empty($r['paid_at']), 'text' => 'Należność nie została zapłacona'],
            ['ok' => (float) $r['amount'] >= self::BIG_MIN_AMOUNT, 'text' => 'Kwota co najmniej 500 zł (dla dłużnika-przedsiębiorcy)'],
            ['ok' => $days >= self::BIG_MIN_DAYS, 'text' => 'Co najmniej 30 dni po terminie płatności (teraz: ' . $days . ' ' . plural($days, 'dzień', 'dni', 'dni') . ')'],
            ['ok' => $demandAt !== null, 'text' => 'Wysłane wezwanie do zapłaty z ostrzeżeniem o zamiarze przekazania danych do BIG'],
            ['ok' => trim((string) ($r['demand_bureau'] ?? '')) !== '', 'text' => 'W wezwaniu podano nazwę i adres siedziby biura informacji gospodarczej'],
            ['ok' => $waitOk, 'text' => 'Minął co najmniej miesiąc od wysłania wezwania'],
        ];
        $eligible = !in_array(false, array_column($checks, 'ok'), true);
        $from = null;
        if (!$eligible && $demandAt !== null && !$waitOk) {
            $from = date('Y-m-d', $demandAt + self::BIG_DEMAND_WAIT_DAYS * 86400);
        }
        return ['eligible' => $eligible, 'checks' => $checks, 'eligible_from' => $from];
    }

    /** Rekompensata za koszty odzyskiwania należności (art. 10 ustawy o przeciwdziałaniu nadmiernym opóźnieniom). */
    public static function compensationEur(float $amount): int
    {
        return $amount <= 5000 ? 40 : ($amount <= 50000 ? 70 : 100);
    }

    /** Treść wezwania do zapłaty z ostrzeżeniem o przekazaniu danych do BIG. */
    public static function demandLetter(array $r, array $company, array $org, string $place = ''): array
    {
        $amount = money_pl((float) $r['amount']);
        $bureau = trim((string) ($r['demand_bureau'] ?? ''));
        $title = 'Wezwanie do zapłaty – faktura ' . $r['invoice_no'];
        $creditor = ($org['name'] ?? '') . (!empty($org['nip']) ? ', NIP ' . Nip::format((string) $org['nip']) : '') . (!empty($org['address']) ? ', ' . $org['address'] : '');
        $debtor = ($company['name'] ?? '') . ', NIP ' . Nip::format((string) $company['nip']) . (!empty($company['address']) ? ', ' . $company['address'] : '');
        $lines = [
            ($place !== '' ? $place . ', ' : '') . date('d.m.Y'),
            '',
            'Wierzyciel: ' . $creditor,
            'Dłużnik: ' . $debtor,
            '',
            'WEZWANIE DO ZAPŁATY',
            '',
            'Wzywamy do zapłaty kwoty ' . $amount . ' z tytułu faktury nr ' . $r['invoice_no']
                . (!empty($r['issued_at']) ? ' z dnia ' . date('d.m.Y', strtotime((string) $r['issued_at'])) : '')
                . ', której termin płatności upłynął ' . date('d.m.Y', strtotime((string) $r['due_at'])) . ',',
            'wraz z odsetkami ustawowymi za opóźnienie w transakcjach handlowych liczonymi od dnia następującego po terminie płatności do dnia zapłaty',
            'oraz rekompensatą za koszty odzyskiwania należności w wysokości równowartości ' . self::compensationEur((float) $r['amount']) . ' euro (art. 10 ustawy o przeciwdziałaniu nadmiernym opóźnieniom w transakcjach handlowych),',
            'w terminie 7 dni od dnia otrzymania niniejszego wezwania, na rachunek bankowy wskazany na fakturze.',
            '',
        ];
        if ($bureau !== '') {
            $lines[] = 'Jednocześnie informujemy, że w przypadku braku zapłaty w terminie miesiąca od dnia doręczenia niniejszego wezwania zamierzamy przekazać dane dotyczące zobowiązania do biura informacji gospodarczej: '
                . $bureau . ' (art. 14 ust. 1 pkt 4 ustawy z dnia 9 kwietnia 2010 r. o udostępnianiu informacji gospodarczych i wymianie danych gospodarczych).';
            $lines[] = '';
        }
        $lines[] = 'Brak zapłaty spowoduje skierowanie sprawy na drogę postępowania sądowego, co narazi Państwa na dodatkowe koszty.';
        $lines[] = '';
        $lines[] = 'Z poważaniem';
        $lines[] = $org['name'] ?? '';
        return ['title' => $title, 'body' => implode("\n", $lines)];
    }

    /** Podsumowanie należności firmy: suma przeterminowanych, liczba faktur. */
    public static function summary(int $orgId): array
    {
        $rows = Db::get()->all('SELECT r.*, c.name AS company_name, c.nip FROM receivables r JOIN companies c ON c.id = r.company_id WHERE r.org_id = ? AND r.paid_at IS NULL ORDER BY r.due_at', [$orgId]);
        $overdue = 0.0;
        $count = 0;
        foreach ($rows as $r) {
            if (self::daysOverdue($r) > 0) {
                $overdue += (float) $r['amount'];
                $count++;
            }
        }
        return ['rows' => $rows, 'overdue_sum' => $overdue, 'overdue_count' => $count];
    }
}
