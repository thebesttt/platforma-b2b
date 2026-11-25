<?php

declare(strict_types=1);

namespace App\Modules\Tenders;

/**
 * Treść planu postępowań z BZP (art. 23 Pzp) → lista pozycji.
 *
 * Plan to dokument HTML z tabelami „table-positions”: najpierw zamówienia poniżej progów unijnych, potem od progów w górę.
 * W tabeli wiersz z jedną komórką na całą szerokość to kategoria (ROBOTY BUDOWLANE, DOSTAWY, USŁUGI, litery rozstrzelone
 * twardymi spacjami), a wiersz z siedmioma komórkami to pozycja: numer, przedmiot, tryb, wartość, termin, informacje, aktualizacja.
 * Plan nie ma kodów CPV, więc dopasowanie do profilu idzie po słowach z przedmiotu i informacji dodatkowych.
 */
final class PlanParser
{
    private const MONTHS = [
        'styczen' => 1, 'stycznia' => 1, 'styczniu' => 1, 'luty' => 2, 'lutego' => 2, 'lutym' => 2, 'marzec' => 3, 'marca' => 3, 'marcu' => 3,
        'kwiecien' => 4, 'kwietnia' => 4, 'kwietniu' => 4, 'maj' => 5, 'maja' => 5, 'maju' => 5, 'czerwiec' => 6, 'czerwca' => 6, 'czerwcu' => 6,
        'lipiec' => 7, 'lipca' => 7, 'lipcu' => 7, 'sierpien' => 8, 'sierpnia' => 8, 'sierpniu' => 8, 'wrzesien' => 9, 'wrzesnia' => 9, 'wrzesniu' => 9,
        'pazdziernik' => 10, 'pazdziernika' => 10, 'pazdzierniku' => 10, 'listopad' => 11, 'listopada' => 11, 'listopadzie' => 11,
        'grudzien' => 12, 'grudnia' => 12, 'grudniu' => 12,
    ];

    /**
     * @return array{year: ?int, version: ?int, buyer_nip: ?string, items: array<int, array>}
     */
    public static function parse(string $html): array
    {
        $out = ['year' => null, 'version' => null, 'buyer_nip' => null, 'items' => []];
        if (trim($html) === '') {
            return $out;
        }
        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET | LIBXML_COMPACT);
        $xp = new \DOMXPath($doc);

        // Rok i wersja są w tabelkach nagłówka, po jednej cyfrze w komórce („2 | 0 | 2 | 6”).
        foreach ($xp->query('//table[contains(@class, "table-attachment-Title")]//tr') ?: [] as $tr) {
            $cells = [];
            foreach ($tr->getElementsByTagName('td') as $td) {
                $cells[] = self::text($td);
            }
            $label = mb_strtolower($cells[0] ?? '');
            $digits = preg_replace('/\D/', '', implode('', array_slice($cells, 1))) ?? '';
            if ($digits === '') {
                continue;
            }
            if (str_contains($label, 'plan postępowań') && strlen($digits) === 4) {
                $out['year'] = (int) $digits;
            } elseif (str_contains($label, 'wersja')) {
                $out['version'] = (int) $digits;
            }
        }
        if (preg_match('/Krajowy numer identyfikacyjny:\s*(?:<sup>\d+<\/sup>)?\s*([0-9\- ]{9,13})/u', $html, $m)) {
            $nip = preg_replace('/\D/', '', $m[1]) ?? '';
            $out['buyer_nip'] = strlen($nip) === 10 ? $nip : null;
        }

        $tables = $xp->query('//table[contains(@class, "table-positions")]');
        foreach ($tables ?: [] as $table) {
            $section = self::section($xp, $table);
            $category = null;
            foreach ($xp->query('.//tbody/tr', $table) ?: [] as $tr) {
                $cells = [];
                foreach ($tr->childNodes as $c) {
                    if ($c instanceof \DOMElement && in_array($c->nodeName, ['td', 'th'], true)) {
                        $cells[] = $c;
                    }
                }
                if (count($cells) === 1) {
                    $category = self::category(self::text($cells[0]));
                    continue;
                }
                if (count($cells) < 6) {
                    continue;
                }
                $subject = self::text($cells[1]);
                if ($subject === '') {
                    continue;
                }
                $term = self::text($cells[4]);
                [$quarter, $month] = self::when($term);
                [$value, $currency] = self::value(self::text($cells[3]));
                $info = self::text($cells[5], true);
                $update = isset($cells[6]) ? self::text($cells[6]) : '';
                $out['items'][] = [
                    'position' => mb_substr(self::text($cells[0]), 0, 40),
                    'section' => $section,
                    'category' => $category,
                    'subject' => mb_substr($subject, 0, 1000),
                    'mode' => mb_substr(self::mode(self::text($cells[2])), 0, 200),
                    'value_estimated' => $value,
                    'currency' => $currency,
                    'term' => mb_substr($term, 0, 120),
                    'quarter' => $quarter,
                    'month' => $month,
                    'info' => mb_substr($info, 0, 600),
                    'sme' => preg_match('/odpowiednie dla MŚP:\s*TAK/iu', $info) ? 1 : (preg_match('/odpowiednie dla MŚP:\s*NIE/iu', $info) ? 0 : null),
                    'update_note' => mb_substr($update, 0, 120),
                    'status' => preg_match('/rezygnac|usuni|wykreśl/iu', $update) ? 'removed' : 'active',
                ];
            }
        }
        return $out;
    }

    /**
     * Termin wszczęcia z planu → [kwartał, miesiąc]. Zamawiający piszą go różnie: „II Kwartał”, „3 kwartał”, „I i II kwartał”,
     * „II / III kwartał”, „marzec”, „03.2026”, „kwiecień 2026”. Przy kilku kwartałach bierzemy najwcześniejszy.
     * @return array{0: ?int, 1: ?int}
     */
    public static function when(string $term): array
    {
        $t = search_text($term);
        if ($t === '') {
            return [null, null];
        }
        foreach (explode(' ', $t) as $w) {
            if (isset(self::MONTHS[$w])) {
                $m = self::MONTHS[$w];
                return [intdiv($m - 1, 3) + 1, $m];
            }
        }
        if (preg_match('/\b(0?[1-9]|1[0-2])[.\/-](20\d\d)\b/', $term, $mm)) {
            $m = (int) $mm[1];
            return [intdiv($m - 1, 3) + 1, $m];
        }
        if (preg_match('/\b(i|ii|1|2)\s+polrocz/', $t, $h)) {
            return [in_array($h[1], ['i', '1'], true) ? 1 : 3, null];
        }
        if (str_contains($t, 'kwarta') || preg_match('/\b(i{1,3}|iv)\b/', $t)) {
            $roman = ['iv' => 4, 'iii' => 3, 'ii' => 2, 'i' => 1];
            $found = [];
            foreach (explode(' ', preg_replace('/\b20\d\d\b/', ' ', $t) ?? $t) as $w) {
                if (isset($roman[$w])) {
                    $found[] = $roman[$w];
                } elseif (preg_match('/^[1-4]$/', $w)) {
                    $found[] = (int) $w;
                }
            }
            if ($found) {
                return [min($found), null];
            }
        }
        return [null, null];
    }

    /** „2 115 037,00 PLN” → [2115037.0, 'PLN']. */
    public static function value(string $s): array
    {
        $currency = preg_match('/\b(PLN|EUR|USD|zł)\b/iu', $s, $c) ? strtoupper(str_replace('zł', 'PLN', $c[1])) : null;
        $num = preg_replace('/[^\d,\.]/', '', str_replace(["\u{00A0}", ' '], '', $s)) ?? '';
        if ($num === '') {
            return [null, $currency];
        }
        if (str_contains($num, ',')) {
            $num = str_replace(['.', ','], ['', '.'], $num);
        }
        return is_numeric($num) ? [(float) $num, $currency ?? 'PLN'] : [null, $currency];
    }

    /** Tryb z planu bywa długi („Zamówienie udzielane jest w trybie podstawowym na podstawie: art. 275 pkt 1 ustawy”). */
    private static function mode(string $s): string
    {
        $s = preg_replace('/^Zamówienie udzielane jest w trybie\s+/u', 'Tryb ', $s) ?? $s;
        return utrim($s);
    }

    private static function category(string $s): ?string
    {
        $t = str_replace(' ', '', search_text($s));
        return match (true) {
            str_contains($t, 'robotybudowlane') => 'works',
            str_contains($t, 'dostawy') => 'supplies',
            str_contains($t, 'uslugi') => 'services',
            default => null,
        };
    }

    /** Sekcja planu: nagłówek „1. … mniejszej niż progi unijne” albo „2. … równej lub przekraczającej progi unijne” przed tabelą. */
    private static function section(\DOMXPath $xp, \DOMElement $table): string
    {
        $prev = $xp->query('preceding-sibling::div[contains(@class, "bold")][1]', $table);
        $label = $prev && $prev->length ? mb_strtolower(self::text($prev->item(0))) : '';
        return str_contains($label, 'równej lub przekraczającej') || str_contains($label, 'przekraczającej') ? 'above' : 'below';
    }

    private static function text(\DOMNode $n, bool $keepBreaks = false): string
    {
        if ($keepBreaks) {
            $parts = [];
            $buf = '';
            foreach ($n->childNodes as $c) {
                if ($c->nodeName === 'br') {
                    $parts[] = $buf;
                    $buf = '';
                } else {
                    $buf .= $c->textContent;
                }
            }
            $parts[] = $buf;
            $lines = array_values(array_filter(array_map(static fn ($p) => utrim(preg_replace('/[\s\x{00A0}]+/u', ' ', $p) ?? ''), $parts), static fn ($p) => $p !== ''));
            return implode("\n", $lines);
        }
        return utrim(preg_replace('/[\s\x{00A0}]+/u', ' ', $n->textContent) ?? '');
    }
}
