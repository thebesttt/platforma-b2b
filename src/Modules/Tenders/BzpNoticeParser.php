<?php

declare(strict_types=1);

namespace App\Modules\Tenders;

/**
 * Zamienia treść ogłoszenia z BZP (HTML z punktami „8.1.) Termin składania ofert: …”) na dane.
 * Ogłoszenia mają stałą numerację punktów, więc parser opiera się na numerach, nie na wyglądzie.
 */
final class BzpNoticeParser
{
    /** @return array<int, array{code: string, label: string, value: string}> */
    public static function fields(string $html): array
    {
        // Błędny bajt w treści sprawiłby, że wyrażenia regularne w trybie UTF-8 zwrócą pusty wynik.
        $html = mb_scrub($html, 'UTF-8');
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? '';
        $html = preg_replace('#<!--.*?-->#s', ' ', $html) ?? '';
        $html = preg_replace('#</?(h[1-6]|p|div|li|tr|table|ul|ol)\b[^>]*>|<br\s*/?>#i', "\n", $html) ?? '';
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $lines = array_values(array_filter(array_map(
            static fn ($l) => trim(preg_replace('/[ \t]+/u', ' ', $l) ?? ''),
            explode("\n", $text)
        ), static fn ($l) => $l !== ''));

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
            if (preg_match('/^(\d{1,2}(?:\.\d{1,2}){1,3})\.?\)\s*(.*)$/u', $line, $m)) {
                if ($cur) {
                    $fields[] = $cur;
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
                $cur = ['code' => $m[1], 'label' => trim($label), 'value' => $value];
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
