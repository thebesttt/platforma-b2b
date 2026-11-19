<?php

declare(strict_types=1);

namespace App\Modules\Companies;

use App\Core\Db;
use App\Core\Nip;

/**
 * Należności od kontrahentów: przeterminowanie, wezwanie do zapłaty i warunki wpisu do biura informacji gospodarczej (BIG).
 * Dane o długach zgłoszone przez użytkownika NIE są pokazywane innym użytkownikom platformy - do BIG (np. KRD)
 * przekazuje je sam wierzyciel, po spełnieniu warunków ustawy o udostępnianiu informacji gospodarczych.
 *
 * Podstawa: art. 15 ust. 1 (dłużnik niebędący konsumentem) - co najmniej 500 zł, wymagalne od co najmniej 30 dni,
 * co najmniej miesiąc od wysłania wezwania z ostrzeżeniem (firma i adres biura); art. 15a ust. 2 - wezwanie
 * informuje o możliwości zgłoszenia sprzeciwu. Art. 14 dotyczy konsumentów (próg 200 zł) i tu nie ma zastosowania.
 */
final class Receivables
{
    public const BIG_MIN_AMOUNT = 500.0; // art. 15 ust. 1 pkt 2
    public const BIG_MIN_DAYS = 30;      // art. 15 ust. 1 pkt 2

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
        // „Co najmniej miesiąc” liczymy kalendarzowo: wezwanie z 14.10 → wpis możliwy od 14.11.
        $waitUntil = $demandAt !== null ? strtotime('+1 month', $demandAt) : null;
        $waitOk = $waitUntil !== null && $now >= $waitUntil;
        $checks = [
            ['ok' => empty($r['paid_at']), 'text' => 'Należność nie została zapłacona'],
            ['ok' => (float) $r['amount'] >= self::BIG_MIN_AMOUNT, 'text' => 'Łączna kwota zaległości co najmniej 500 zł (dłużnik niebędący konsumentem)'],
            ['ok' => $days >= self::BIG_MIN_DAYS, 'text' => 'Wymagalna od co najmniej 30 dni (teraz: ' . $days . ' ' . plural($days, 'dzień', 'dni', 'dni') . ' po terminie)'],
            ['ok' => $demandAt !== null, 'text' => 'Wysłane wezwanie do zapłaty z ostrzeżeniem o zamiarze przekazania danych do biura i informacją o prawie do sprzeciwu'],
            ['ok' => trim((string) ($r['demand_bureau'] ?? '')) !== '', 'text' => 'W wezwaniu podano firmę i adres siedziby biura informacji gospodarczej'],
            ['ok' => $waitOk, 'text' => 'Minął co najmniej miesiąc od wysłania wezwania'],
        ];
        $eligible = !in_array(false, array_column($checks, 'ok'), true);
        $from = null;
        if (!$eligible && $waitUntil !== null && !$waitOk) {
            $from = date('Y-m-d', $waitUntil);
        }
        return ['eligible' => $eligible, 'checks' => $checks, 'eligible_from' => $from];
    }

    /** Rekompensata za koszty odzyskiwania należności (art. 10 ustawy o przeciwdziałaniu nadmiernym opóźnieniom). */
    public static function compensationEur(float $amount): int
    {
        // Art. 10 ust. 1: 40 euro do 5000 zł włącznie, 70 euro powyżej 5000 zł i poniżej 50 000 zł, 100 euro od 50 000 zł.
        return $amount <= 5000 ? 40 : ($amount < 50000 ? 70 : 100);
    }

    /** Treść wezwania do zapłaty z ostrzeżeniem o przekazaniu danych do BIG. */
    public static function demandLetter(array $r, array $company, array $org, string $place = ''): array
    {
        $amount = money_pl((float) $r['amount']);
        $bureau = trim((string) ($r['demand_bureau'] ?? ''));
        $title = 'Wezwanie do zapłaty - faktura ' . $r['invoice_no'];
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
                . $bureau . ' (art. 15 ust. 1 pkt 3 ustawy z dnia 9 kwietnia 2010 r. o udostępnianiu informacji gospodarczych i wymianie danych gospodarczych).';
            $lines[] = 'Informujemy o możliwości zgłoszenia nam sprzeciwu wobec zamiaru przekazania danych do biura (art. 15a tej ustawy). W sprzeciwie można zakwestionować istnienie zobowiązania w całości lub w części albo wskazać na jego przedawnienie.';
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
