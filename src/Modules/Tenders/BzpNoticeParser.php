<?php

declare(strict_types=1);

namespace App\Modules\Tenders;

/**
 * Zamienia treść ogłoszenia z BZP (HTML z punktami „8.1.) Termin składania ofert: …”) na dane.
 * Ogłoszenia mają stałą numerację punktów, więc parser opiera się na numerach, nie na wyglądzie.
 */
final class BzpNoticeParser
{
    /** Treść ogłoszenia jako wiersze tekstu: bez znaczników, skryptów i pustych wierszy. */
    private static function lines(string $html): array
    {
        // Błędny bajt w treści sprawiłby, że wyrażenia regularne w trybie UTF-8 zwrócą pusty wynik.
        $html = mb_scrub($html, 'UTF-8');
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? '';
        $html = preg_replace('#<!--.*?-->#s', ' ', $html) ?? '';
        $html = preg_replace('#</?(h[1-6]|p|div|li|tr|table|ul|ol)\b[^>]*>|<br\s*/?>#i', "\n", $html) ?? '';
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        return array_values(array_filter(array_map(
            static fn ($l) => trim(preg_replace('/[ \t]+/u', ' ', $l) ?? ''),
            explode("\n", $text)
        ), static fn ($l) => $l !== ''));
    }

    /** Wiersz „8.1.) Termin składania ofert: …” rozbity na numer punktu, etykietę i wartość. Null, gdy to nie punkt. */
    private static function point(string $line): ?array
    {
        if (!preg_match('/^(\d{1,2}(?:\.\d{1,2}){1,3})\.?\)\s*(.*)$/u', $line, $m)) {
            return null;
        }
        $rest = $m[2];
        $label = $rest;
        $value = '';
        $p = mb_strpos($rest, ': ');
        if ($p !== false) {
            $label = mb_substr($rest, 0, $p);
            $value = trim(mb_substr($rest, $p + 2));
        } elseif (str_ends_with($rest, ':')) {
            $label = rtrim($rest, ':');
        }
        return ['code' => $m[1], 'label' => trim($label), 'value' => $value];
    }

    /** @return array<int, array{code: string, label: string, value: string}> */
    public static function fields(string $html): array
    {
        $lines = self::lines($html);

        $fields = [];
        $cur = null;
        foreach ($lines as $line) {
            if (preg_match('/^Kryterium \d+$/u', $line)) {
                continue;
            }
            if (preg_match('/^SEKCJA [IVX]+/u', $line)) {
                if ($cur) {
                    $fields[] = $cur;
                }
                $cur = null;
                continue;
            }
            if ($pt = self::point($line)) {
                if ($cur) {
                    $fields[] = $cur;
                }
                $cur = $pt;
                continue;
            }
            if ($cur) {
                $cur['value'] = trim($cur['value'] === '' ? $line : $cur['value'] . "\n" . $line);
            }
        }
        if ($cur) {
            $fields[] = $cur;
        }
        return $fields;
    }

    /**
     * Ogłoszenie o wyniku postępowania: dla każdej części wynik, liczba i ceny ofert, wykonawca i umowa.
     * Numer części bierzemy z nagłówków „Część 2” i „SEKCJA V … (dla części 2)”. Ogłoszenie bez części ma jedną, o numerze 1.
     *
     * @return array{ocid: ?string, notice: ?string, base_notice: ?string, est_value: ?float, parts: array<int, array>}
     */
    public static function results(string $html): array
    {
        $out = ['ocid' => null, 'notice' => null, 'base_notice' => null, 'est_value' => null, 'parts' => []];
        $part = 1;
        $est = [];
        $cur = null;
        $points = [];
        $flush = static function () use (&$cur, &$points): void {
            if ($cur) {
                $points[] = $cur;
            }
            $cur = null;
        };
        foreach (self::lines($html) as $line) {
            if (preg_match('/^SEKCJA [IVX]+/u', $line)) {
                $flush();
                if (preg_match('/\(dla części (\d+)\)/u', $line, $m)) {
                    $part = (int) $m[1];
                }
                continue;
            }
            if (preg_match('/^Część (\d+)$/u', $line, $m)) {
                $flush();
                $part = (int) $m[1];
                continue;
            }
            if ($line === 'Wykonawca') {
                $flush();
                continue;
            }
            if ($pt = self::point($line)) {
                $flush();
                $cur = $pt + ['part' => $part];
                continue;
            }
            if ($cur) {
                $cur['value'] = trim($cur['value'] === '' ? $line : $cur['value'] . "\n" . $line);
            }
        }
        $flush();

        $parts = [];
        foreach ($points as $f) {
            $code = $f['code'];
            $v = $f['value'];
            $no = (int) $f['part'];
            if ($code === '2.4') {
                $out['ocid'] = self::oneLine($v) ?: null;
            } elseif ($code === '2.5') {
                $out['notice'] = self::oneLine($v) ?: null;
            } elseif ($code === '2.14') {
                // Numer ogłoszenia o zamówieniu bywa z wersją („/01”) albo bez niej.
                $out['base_notice'] = preg_match('~(\d{4}/BZP \d+)~u', $v, $m) ? $m[1] : null;
            } elseif ($code === '4.3.1' || ($code === '4.3' && $out['est_value'] === null)) {
                $out['est_value'] = self::money($v)[0] ?? $out['est_value'];
            } elseif ($code === '4.5.5') {
                $est[$no] = self::money($v)[0];
            }
            if (!preg_match('/^[5-8]\./', $code)) {
                continue;
            }
            if ($code === '5.1') {
                // W ogłoszeniu o zamówieniu punkt 5.1 dotyczy podstaw wykluczenia. Wynik rozpoznajemy po etykiecie.
                if (!str_starts_with(mb_strtolower($f['label']), 'postępowanie zakończyło')) {
                    continue;
                }
                $low = mb_strtolower($v);
                $parts[$no] = [
                    'part' => $no,
                    'result' => str_contains($low, 'zawarciem umowy') ? 'contract' : (str_contains($low, 'unieważnieniem') ? 'cancelled' : 'unresolved'),
                    'winners' => [],
                ];
                continue;
            }
            if (!isset($parts[$no])) {
                continue;
            }
            $p = &$parts[$no];
            $win = count($p['winners']) - 1;
            switch ($code) {
                case '5.2': $p['cancel_basis'] = self::oneLine($v); break;
                case '5.2.1': $p['cancel_reason'] = self::clip($v); break;
                case '6.1': $p['offers'] = self::count($v); break;
                case '6.1.3': $p['offers_sme'] = self::count($v); break;
                case '6.1.6': $p['offers_rejected'] = self::count($v); break;
                case '6.2': [$p['price_lowest'], $p['currency']] = self::money($v) + [null, $p['currency'] ?? null]; break;
                case '6.3': $p['price_highest'] = self::money($v)[0] ?? null; break;
                case '6.4': $p['price_winner'] = self::money($v)[0] ?? null; break;
                case '7.1': $p['consortium'] = self::yes($v) === true; break;
                case '7.2':
                    $p['winners'][] = ['size' => self::oneLine($v)];
                    break;
                case '7.3.1':
                    // Przy konsorcjum pierwszy wiersz to lider, dalej lista pozostałych wykonawców.
                    $rows = explode("\n", $v);
                    if ($win < 0 || isset($p['winners'][$win]['name'])) {
                        $p['winners'][] = [];
                        $win = count($p['winners']) - 1;
                    }
                    $p['winners'][$win]['name'] = self::oneLine($rows[0]);
                    $others = trim(implode(' ', array_slice($rows, 1)));
                    if ($others !== '') {
                        $p['partners'] = self::clip(preg_replace('/^Nazwy \(firmy\) pozostałych wykonawców[^:]*:\s*/u', '', $others) ?? $others);
                    }
                    break;
                case '7.3.2': if ($win >= 0) { $p['winners'][$win]['nip'] = preg_replace('/\D/', '', $v) ?: null; } break;
                case '7.3.4': if ($win >= 0) { $p['winners'][$win]['city'] = self::oneLine($v); } break;
                case '7.3.6': if ($win >= 0) { $p['winners'][$win]['voivodeship'] = Regions::fromName(self::oneLine($v)); } break;
                case '7.3.7': if ($win >= 0) { $p['winners'][$win]['country'] = self::oneLine($v); } break;
                case '8.1': $p['contract_date'] = preg_match('/\d{4}-\d{2}-\d{2}/', $v, $m) ? $m[0] : null; break;
                case '8.2':
                    $mv = self::money($v);
                    $p['contract_value'] = $mv[0] ?? null;
                    $p['currency'] = $mv[1] ?? ($p['currency'] ?? null);
                    break;
                case '8.3': $p['period'] = self::oneLine($v); break;
            }
            unset($p);
        }
        foreach ($parts as $no => &$p) {
            $p['est_value'] = $est[$no] ?? (count($parts) === 1 && !$est ? $out['est_value'] : null);
            $p['winners'] = array_values(array_filter($p['winners'], static fn (array $w) => !empty($w['name'])));
        }
        unset($p);
        ksort($parts);
        $out['parts'] = array_values($parts);
        return $out;
    }

    /** „453023,83 PLN”, „1 392 360 PLN”, „763787.2 PLN” → [kwota, waluta]. Pusta tablica, gdy nie ma kwoty. */
    public static function money(string $v): array
    {
        if (!preg_match('/(\d[\d\s.,]*)\s*([A-Z]{3}|zł)?/u', str_replace("\u{00A0}", ' ', $v), $m)) {
            return [];
        }
        $n = preg_replace('/\s+/u', '', $m[1]) ?? '';
        $n = rtrim($n, '.,');
        if (str_contains($n, ',')) {
            $n = str_replace(',', '.', str_replace('.', '', $n));
        } elseif (substr_count($n, '.') > 1 || preg_match('/\.\d{3}$/', $n)) {
            // Kropki jako separator tysięcy („1.392.360”).
            $n = str_replace('.', '', $n);
        }
        if (!is_numeric($n)) {
            return [];
        }
        $cur = (string) ($m[2] ?? '');
        return [round((float) $n, 2), $cur === '' || mb_strtolower($cur) === 'zł' ? 'PLN' : strtoupper($cur)];
    }

    private static function count(string $v): ?int
    {
        return preg_match('/^\s*(\d+)/', $v, $m) ? (int) $m[1] : null;
    }

    /** Najważniejsze dane ogłoszenia w prostej strukturze. */
    public static function parse(string $html): array
    {
        $fields = self::fields($html);
        $first = static function (string $code) use ($fields): string {
            foreach ($fields as $f) {
                if ($f['code'] === $code) {
                    return $f['value'];
                }
            }
            return '';
        };
        $all = static function (string $code) use ($fields): array {
            $out = [];
            foreach ($fields as $f) {
                if ($f['code'] === $code && $f['value'] !== '') {
                    $out[] = $f['value'];
                }
            }
            return $out;
        };

        // Ogłoszenia o zamówieniach poniżej progu ustawy mają krótszy formularz z inną numeracją punktów.
        // Rozpoznajemy go po etykiecie punktu 3.1 („Nazwa zamówienia”); termin z pkt 3.6 bywa pominięty.
        $label31 = '';
        foreach ($fields as $f) {
            if ($f['code'] === '3.1') {
                $label31 = mb_strtolower($f['label']);
                break;
            }
        }
        if ($first('8.1') === '' && str_starts_with($label31, 'nazwa zamówienia')) {
            return self::parseSmall($fields, $first);
        }

        // Kryteria oceny: pary „4.3.5 Nazwa kryterium” + „4.3.6 Waga”.
        $criteria = [];
        $last = null;
        foreach ($fields as $f) {
            if ($f['code'] === '4.3.5') {
                $last = count($criteria);
                $criteria[] = ['name' => self::oneLine($f['value']), 'weight' => null];
            } elseif ($f['code'] === '4.3.6' && $last !== null) {
                $criteria[$last]['weight'] = preg_match('/^\s*(\d+(?:[.,]\d+)?)/', $f['value'], $wm) ? (float) str_replace(',', '.', $wm[1]) : null;
            }
        }
        // Przy kilku częściach kryteria się powtarzają - zostawiamy unikalne nazwy.
        $seen = [];
        $criteria = array_values(array_filter($criteria, static function ($c) use (&$seen) {
            $k = mb_strtolower($c['name']);
            if (isset($seen[$k])) {
                return false;
            }
            return $seen[$k] = true;
        }));

        $wadiumInfo = $first('6.4.1');
        $wadiumValue = null;
        if (preg_match('/([\d\s.]+(?:,\d{1,2})?)\s*(PLN|zł|EUR)/iu', $wadiumInfo, $m)) {
            $wadiumValue = (float) str_replace([' ', '.', ','], ['', '', '.'], $m[1]);
        }

        $docsUrl = '';
        if (preg_match('#https?://\S+#u', $first('3.1') ?: $first('3.5'), $m)) {
            $docsUrl = rtrim($m[0], '.,;)');
        }

        $nationalId = $first('1.4');
        $buyerNip = null;
        $buyerRegon = null;
        if (preg_match('/NIP\D*(\d{10})/u', $nationalId, $m)) {
            $buyerNip = $m[1];
        }
        if (preg_match('/REGON\D*(\d{9,14})/u', $nationalId, $m)) {
            $buyerRegon = $m[1];
        }

        $cpvMain = '';
        if (preg_match('/(\d{8})-\d/', $first('4.2.6'), $m)) {
            $cpvMain = $m[1];
        }
        $cpvExtra = [];
        foreach ($all('4.2.7') as $v) {
            if (preg_match_all('/(\d{8})-\d/', $v, $mm)) {
                $cpvExtra = array_merge($cpvExtra, $mm[1]);
            }
        }

        return [
            'buyer' => [
                'name' => self::oneLine($first('1.2')),
                'nip' => $buyerNip,
                'regon' => $buyerRegon,
                'street' => $first('1.5.1'),
                'city' => $first('1.5.2'),
                'postcode' => $first('1.5.3'),
                'voivodeship' => Regions::fromName($first('1.5.4')),
                'email' => $first('1.5.9'),
                'website' => $first('1.5.10'),
                'kind' => self::oneLine($first('1.6')),
            ],
            'title' => self::oneLine(utrim($first('2.3'))),
            'notice_number' => $first('2.5'),
            'procedure' => self::oneLine($first('2.16')),
            'eu_funds' => self::yes($first('2.14')),
            'documents_url' => $docsUrl,
            'reference' => $first('4.1.2'),
            'order_kind' => $first('4.1.3'),
            'partial_offers' => self::yes($first('4.1.8')),
            'parts' => (int) ($first('4.1.9') ?: 1),
            'descriptions' => array_map([self::class, 'clip'], $all('4.2.2')),
            'cpv_main' => $cpvMain,
            'cpv_extra' => array_values(array_unique($cpvExtra)),
            'period' => self::oneLine($first('4.2.10')),
            'criteria' => $criteria,
            'conditions_required' => self::yes($first('5.3')),
            'conditions' => self::clip($first('5.4')),
            'exclusions_optional' => self::yes($first('5.1')),
            'other_documents' => self::clip($first('5.11')),
            'variants' => self::yes($first('6.1')),
            'auction' => self::yes($first('6.3')),
            'wadium' => self::yes($first('6.4')),
            'wadium_info' => self::oneLine($wadiumInfo),
            'wadium_value' => $wadiumValue,
            'performance_bond' => self::yes($first('6.5')),
            'advance_payments' => self::yes($first('7.1')),
            'contract_changes' => self::yes($first('7.3')),
            'deadline' => self::dateTime($first('8.1')),
            'submission_place' => self::oneLine($first('8.2')),
            'opening' => self::dateTime($first('8.3')),
            'binding_until' => preg_match('/(\d{4}-\d{2}-\d{2})/', $first('8.4'), $m) ? $m[1] : null,
            // Pełna treść w punktach - dla analizy AI i wyszukiwania.
            'text' => mb_substr(implode("\n", array_map(
                static fn ($f) => $f['code'] . ') ' . $f['label'] . ($f['value'] !== '' ? ': ' . $f['value'] : ''),
                array_filter($fields, static fn ($f) => !in_array($f['code'], ['3.6', '3.15', '3.16'], true))
            )), 0, 40000),
        ];
    }

    /**
     * Ogłoszenie dotyczące zamówienia, dla którego nie ma obowiązku stosowania ustawy Pzp (poniżej progu):
     * 3.1 nazwa, 3.2 rodzaj, 3.3 opis, 3.4 wartość, 3.6 termin składania ofert, 3.7 informacje dla wykonawców.
     * Formularz nie zawiera kryteriów, wadium ani kodów CPV.
     */
    private static function parseSmall(array $fields, callable $first): array
    {
        $nationalId = $first('1.3');
        $info = self::clip($first('3.7'));
        $docsUrl = '';
        if (preg_match('#https?://[^\s<>"]+#u', $info, $m)) {
            $docsUrl = rtrim($m[0], '.,;)');
        }
        $value = null;
        if (preg_match('/^\s*([\d\s.]+(?:,\d{1,2})?)\s*$/u', $first('3.4'), $m)) {
            $v = (float) str_replace([' ', "\u{00A0}", '.', ','], ['', '', '', '.'], $m[1]);
            $value = $v > 0 ? $v : null;
        }
        return [
            'form' => 'small',
            'buyer' => [
                'name' => self::oneLine($first('1.1')),
                'nip' => preg_match('/NIP\D*(\d{10})/u', $nationalId, $m) ? $m[1] : null,
                'regon' => preg_match('/REGON\D*(\d{9,14})/u', $nationalId, $m) ? $m[1] : null,
                'street' => $first('1.4.1'),
                'city' => $first('1.4.2'),
                'postcode' => $first('1.4.3'),
                'voivodeship' => Regions::fromName($first('1.4.4')),
                'email' => $first('1.4.9'),
                'website' => $first('1.4.10'),
                'kind' => self::oneLine($first('1.5')),
            ],
            'title' => self::oneLine(utrim($first('3.1'))),
            'notice_number' => $first('2.2'),
            'procedure' => 'Zamówienie poniżej progu ustawy Pzp',
            'eu_funds' => null,
            'documents_url' => $docsUrl,
            'reference' => '',
            'order_kind' => $first('3.2'),
            'partial_offers' => null,
            'parts' => 1,
            'descriptions' => array_values(array_filter([self::clip($first('3.3'))])),
            'cpv_main' => '',
            'cpv_extra' => [],
            'period' => '',
            'criteria' => [],
            'conditions_required' => null,
            'conditions' => '',
            'contractor_info' => $info,
            'value_estimated' => $value,
            'exclusions_optional' => null,
            'other_documents' => '',
            'variants' => null,
            'auction' => null,
            'wadium' => null,
            'wadium_info' => '',
            'wadium_value' => null,
            'performance_bond' => null,
            'advance_payments' => null,
            'contract_changes' => null,
            'deadline' => self::dateTime($first('3.6')),
            'submission_place' => '',
            'opening' => null,
            'binding_until' => null,
            'text' => mb_substr(implode("\n", array_map(
                static fn ($f) => $f['code'] . ') ' . $f['label'] . ($f['value'] !== '' ? ': ' . $f['value'] : ''),
                array_filter($fields, static fn ($f) => !str_starts_with($f['code'], '1.4.'))
            )), 0, 40000),
        ];
    }

    /** „2026-12-02 10:00” (czas polski) → UTC 'Y-m-d H:i:s'. */
    public static function dateTime(string $v): ?string
    {
        if (!preg_match('/(\d{4}-\d{2}-\d{2})(?:\s+(\d{1,2}:\d{2}))?/', $v, $m)) {
            return null;
        }
        $dt = new \DateTimeImmutable($m[1] . ' ' . ($m[2] ?? '00:00'), new \DateTimeZone('Europe/Warsaw'));
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function yes(string $v): ?bool
    {
        $v = mb_strtolower(trim($v));
        if (str_starts_with($v, 'tak')) {
            return true;
        }
        if (str_starts_with($v, 'nie')) {
            return false;
        }
        return null;
    }

    private static function oneLine(string $v): string
    {
        return trim(preg_replace('/\s+/u', ' ', $v) ?? '');
    }

    private static function clip(string $v): string
    {
        $v = trim($v);
        return mb_strlen($v) > 4000 ? mb_substr($v, 0, 4000) . '…' : $v;
    }
}
