<?php

declare(strict_types=1);

namespace App\Modules\Companies;

/**
 * Ocena ryzyka płatniczego wyłącznie z danych publicznych.
 * Każdy sygnał ma poziom, opis, źródło i wagę. Nie zawiera informacji o długach zgłaszanych przez użytkowników -
 * udostępnianie ich innym to działalność zastrzeżona dla biur informacji gospodarczej.
 */
final class Risk
{
    /**
     * @param array|null $wl dane z białej listy (subject)
     * @param array $krs wynik Registries::krsSummary()
     * @param array<int, array> $uokik decyzje UOKiK dotyczące firmy
     * @return array{score: int, label: string, level: string, signals: array<int, array>}
     */
    public static function assess(?array $wl, array $krs, array $uokik, ?int $now = null): array
    {
        $now ??= time();
        $signals = [];
        $add = static function (string $level, string $title, string $detail, string $source, int $weight) use (&$signals): void {
            $signals[] = compact('level', 'title', 'detail', 'source', 'weight');
        };

        foreach ($uokik as $d) {
            $add('high', 'Decyzja UOKiK o nadmiernym opóźnianiu płatności',
                trim(($d['signature'] ?? '') . ($d['decided_at'] ? ', wydana ' . date('d.m.Y', strtotime($d['decided_at'])) : '') . '. ' . ($d['summary'] ?? '')),
                'Decyzje Prezesa UOKiK (zatory płatnicze)', 35);
        }

        if ($wl === null) {
            $add('mid', 'Brak firmy w wykazie podatników VAT', 'Firma nie figuruje na białej liście. Przy fakturach powyżej 15 000 zł przelew na konto spoza wykazu może pozbawić Cię kosztu podatkowego.', 'Wykaz podatników VAT', 15);
        } else {
            $status = (string) ($wl['statusVat'] ?? '');
            if ($status === 'Czynny') {
                $add('none', 'Czynny podatnik VAT', 'Firma jest zarejestrowana jako czynny podatnik VAT.', 'Wykaz podatników VAT', 0);
            } elseif ($status === 'Zwolniony') {
                $add('none', 'Podatnik zwolniony z VAT', 'To nie jest sygnał ryzyka, ale faktury będą bez VAT.', 'Wykaz podatników VAT', 0);
            } else {
                $add('high', 'Firma wykreślona lub niezarejestrowana jako podatnik VAT',
                    trim('Status: ' . ($status ?: 'brak') . ($wl['removalDate'] ?? null ? ', wykreślenie ' . $wl['removalDate'] : '') . ($wl['removalBasis'] ?? null ? ' (' . $wl['removalBasis'] . ')' : '')),
                    'Wykaz podatników VAT', 30);
            }
            $restored = (string) ($wl['restorationDate'] ?? '');
            if ($status === 'Czynny' && $restored !== '' && strtotime($restored) > strtotime('-12 months', $now)) {
                $add('mid', 'Niedawno przywrócony jako podatnik VAT',
                    'Przywrócenie do rejestru ' . date('d.m.Y', strtotime($restored)) . (!empty($wl['removalDate']) ? ', wcześniej wykreślenie ' . date('d.m.Y', strtotime((string) $wl['removalDate'])) : '') . '. Przy fakturach z okresu przed przywróceniem sprawdź status VAT na dzień faktury.',
                    'Wykaz podatników VAT', 8);
            }
            if (($wl['accountCount'] ?? 0) === 0 && $status === 'Czynny') {
                $add('mid', 'Brak rachunku na białej liście', 'Czynny podatnik VAT bez zgłoszonego rachunku firmowego. Przed przelewem potwierdź numer konta.', 'Wykaz podatników VAT', 10);
            }
        }

        if ($krs) {
            if (!empty($krs['dzial4'])) {
                $add('high', 'Wpisy o zaległościach lub wierzytelnościach w KRS',
                    'Dział 4 odpisu KRS zawiera wpisy (zaległości podatkowe lub składkowe, wierzytelności albo oddalenie wniosku o upadłość z powodu braku majątku).',
                    'KRS, dział 4', 30);
            }
            $d6 = mb_strtolower((string) ($krs['dzial6_text'] ?? ''));
            if (preg_match('/likwidac|upad[lł]o|restruktury|zarz[aą]d przymusow/u', $d6)) {
                $add('high', 'Likwidacja, upadłość lub restrukturyzacja w KRS', 'Dział 6 odpisu KRS zawiera informację o likwidacji, upadłości albo restrukturyzacji.', 'KRS, dział 6', 40);
            }
            if (!empty($krs['registered_at'])) {
                $ageDays = ($now - strtotime($krs['registered_at'])) / 86400;
                if ($ageDays < 365) {
                    $add('mid', 'Młoda spółka', 'Wpis do KRS ' . date('d.m.Y', strtotime($krs['registered_at'])) . ' - mniej niż rok historii.', 'KRS', 10);
                }
            }
            $form = mb_strtolower((string) ($krs['legal_form'] ?? ''));
            if (str_contains($form, 'ograniczon') || str_contains($form, 'akcyjn')) {
                $end = $krs['last_statement_period_end'] ?? null;
                $limit = strtotime('-20 months', $now);
                $registeredLongAgo = empty($krs['registered_at']) || strtotime($krs['registered_at']) < strtotime('-24 months', $now);
                if ($registeredLongAgo && (!$end || strtotime($end) < $limit)) {
                    $add('mid', 'Brak aktualnego sprawozdania finansowego', $end
                        ? 'Ostatnie złożone sprawozdanie dotyczy okresu do ' . date('d.m.Y', strtotime($end)) . '.'
                        : 'W KRS nie ma wzmianki o złożonym sprawozdaniu finansowym.', 'KRS, dział 3', 15);
                }
            }
            if (isset($krs['capital']) && $krs['capital'] !== null && $krs['capital'] <= 5000 && str_contains($form, 'ograniczon')) {
                $add('info', 'Minimalny kapitał zakładowy', 'Kapitał 5 000 zł - za długi spółki odpowiada głównie jej majątek.', 'KRS, dział 1', 3);
            }
        }

        $score = 5;
        foreach ($signals as $s) {
            $score += $s['weight'];
        }
        $score = max(0, min(100, $score));
        [$label, $level] = match (true) {
            $score >= 70 => ['Wysokie', 'high'],
            $score >= 45 => ['Podwyższone', 'mid'],
            $score >= 20 => ['Umiarkowane', 'mid'],
            default => ['Niskie', 'low'],
        };
        usort($signals, static fn ($a, $b) => $b['weight'] <=> $a['weight']);
        return ['score' => $score, 'label' => $label, 'level' => $level, 'signals' => $signals];
    }
}
