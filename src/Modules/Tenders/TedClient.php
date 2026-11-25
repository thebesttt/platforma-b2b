<?php

declare(strict_types=1);

namespace App\Modules\Tenders;

use App\Core\Http;
use App\Core\Nip;

/** TED Search API v3 - ogłoszenia unijne z Polski. Dostęp anonimowy, bez klucza. */
final class TedClient
{
    public const URL = 'https://api.ted.europa.eu/v3/notices/search';
    public const FIELDS = [
        'publication-number', 'notice-title', 'notice-type', 'publication-date',
        'buyer-name', 'organisation-identifier-buyer', 'organisation-city-buyer',
        'classification-cpv', 'main-classification-proc', 'contract-nature-main-proc', 'procedure-type',
        'deadline-receipt-tender-date-lot', 'deadline-receipt-tender-time-lot',
        'place-of-performance-subdiv-lot', 'document-url-lot',
        'estimated-value-proc', 'estimated-value-cur-proc', 'estimated-value-glo', 'estimated-value-cur-glo',
    ];

    /** Ogłoszenia o zamówieniu opublikowane od podanego dnia (Y-m-d). */
    public function since(string $fromDate, int $maxPages = 20, ?callable $sleep = null): array
    {
        $out = [];
        $query = sprintf(
            'buyer-country=POL AND notice-type IN (cn-standard cn-social cn-desg) AND publication-date>=%s',
            date('Ymd', strtotime($fromDate))
        );
        for ($page = 1; $page <= $maxPages; $page++) {
            $r = Http::postJson(self::URL, [
                'query' => $query,
                'fields' => self::FIELDS,
                'page' => $page,
                'limit' => 100,
                'scope' => 'ACTIVE',
                'paginationMode' => 'PAGE_NUMBER',
            ]);
            if ($r['status'] !== 200) {
                break;
            }
            $j = json_decode($r['body'], true);
            $notices = is_array($j) ? ($j['notices'] ?? []) : [];
            foreach ($notices as $n) {
                if (!empty($n['publication-number'])) {
                    $out[$n['publication-number']] = self::normalize($n);
                }
            }
            if (count($notices) < 100) {
                break;
            }
            $sleep ? $sleep() : usleep(200000);
        }
        return array_values($out);
    }

    /** Pola wstępnych ogłoszeń informacyjnych (PIN): zapowiedź jest opisana w częściach („part”), a nie w zadaniach („lot”). */
    public const PLAN_FIELDS = [
        'publication-number', 'notice-title', 'notice-type', 'publication-date',
        'buyer-name', 'organisation-identifier-buyer', 'organisation-city-buyer',
        'classification-cpv', 'contract-nature-main-proc', 'title-part', 'description-part',
        'place-of-performance-subdiv-part', 'place-of-performance-subdiv-proc',
        'estimated-value-part', 'estimated-value-proc', 'estimated-value-cur-proc', 'future-notice',
    ];

    /** Typy zapowiedzi: wstępne ogłoszenie informacyjne, także na profilu nabywcy i skracające terminy. */
    public const PLAN_TYPES = 'pin-only pin-buyer pin-rtl';

    /**
     * Wstępne ogłoszenia informacyjne z Polski od podanego dnia (Y-m-d): zamawiający zapowiada zamówienie, zanim je ogłosi.
     * Każde ogłoszenie to jeden plan z jedną pozycją, bo części PIN rzadko mają osobne wartości i terminy.
     * @return array<int, array{plan: array, items: array<int, array>}>
     */
    public function plansSince(string $fromDate, int $maxPages = 10, ?callable $sleep = null): array
    {
        $out = [];
        $query = sprintf('buyer-country=POL AND notice-type IN (%s) AND publication-date>=%s', self::PLAN_TYPES, date('Ymd', strtotime($fromDate)));
        for ($page = 1; $page <= $maxPages; $page++) {
            $r = Http::postJson(self::URL, [
                'query' => $query, 'fields' => self::PLAN_FIELDS, 'page' => $page, 'limit' => 100,
                'scope' => 'ALL', 'paginationMode' => 'PAGE_NUMBER',
            ]);
            if ($r['status'] !== 200) {
                break;
            }
            $j = json_decode($r['body'], true);
            $notices = is_array($j) ? ($j['notices'] ?? []) : [];
            foreach ($notices as $n) {
                if (!empty($n['publication-number'])) {
                    $out[(string) $n['publication-number']] = self::normalizePlan($n);
                }
            }
            if (count($notices) < 100) {
                break;
            }
            $sleep ? $sleep() : usleep(200000);
        }
        return array_values($out);
    }

    /** @return array{plan: array, items: array<int, array>} */
    public static function normalizePlan(array $n): array
    {
        $t = self::normalize($n + ['place-of-performance-subdiv-lot' => $n['place-of-performance-subdiv-part'] ?? $n['place-of-performance-subdiv-proc'] ?? null]);
        $parts = array_values(array_filter(array_map('trim', self::langList($n['description-part'] ?? null))));
        $titles = array_values(array_filter(array_map('trim', self::langList($n['title-part'] ?? null))));
        $value = self::scalar($n['estimated-value-proc'] ?? null);
        if (!is_numeric($value)) {
            $sum = 0.0;
            foreach ((array) ($n['estimated-value-part'] ?? []) as $v) {
                $sum += is_numeric($v) ? (float) $v : 0.0;
            }
            $value = $sum > 0 ? $sum : null;
        }
        $future = (string) (self::scalar($n['future-notice'] ?? null) ?? '');
        $month = preg_match('/^\d{4}-(\d{2})-\d{2}/', $future, $fm) ? (int) $fm[1] : null;
        $year = preg_match('/^(\d{4})-/', $future, $fy) ? (int) $fy[1] : null;
        $subject = $t['title'];
        if ($titles && !in_array($subject, $titles, true) && count($titles) > 1) {
            $subject .= ' (części: ' . implode('; ', array_slice($titles, 0, 5)) . ')';
        }
        $plan = [
            'source' => 'ted',
            'plan_key' => $t['external_id'],
            'notice_number' => $t['notice_number'],
            'version' => 1,
            'year' => $year ?? (int) substr((string) $t['published_at'], 0, 4),
            'buyer_name' => $t['buyer_name'],
            'buyer_nip' => $t['buyer_nip'],
            'buyer_city' => $t['buyer_city'],
            'voivodeship' => $t['voivodeship'],
            'published_at' => $t['published_at'],
            'url' => $t['url'],
        ];
        $item = [
            'position' => '1',
            'section' => 'above',
            'category' => $t['order_type'],
            'subject' => mb_substr($subject, 0, 1000),
            'mode' => 'Wstępne ogłoszenie informacyjne',
            'value_estimated' => is_numeric($value) ? (float) $value : null,
            'currency' => $t['currency'],
            'term' => $future !== '' ? 'Ogłoszenie planowane na ' . substr($future, 0, 10) : '',
            'quarter' => $month ? intdiv($month - 1, 3) + 1 : null,
            'month' => $month,
            'info' => mb_substr(implode("\n", $parts), 0, 600),
            'sme' => null,
            'cpv' => $t['cpv_all'],
            'update_note' => '',
            'status' => 'active',
        ];
        return ['plan' => $plan, 'items' => [$item]];
    }

    /** Pole wielojęzyczne z listą wartości (np. opis każdej części): wartości po polsku, potem po angielsku. */
    private static function langList(mixed $v): array
    {
        if (is_string($v)) {
            return [$v];
        }
        if (!is_array($v)) {
            return [];
        }
        foreach (['pol', 'eng', 'mul'] as $l) {
            if (isset($v[$l])) {
                return array_map('strval', (array) $v[$l]);
            }
        }
        $first = reset($v);
        return $first === false ? [] : array_map('strval', (array) $first);
    }

    public static function normalize(array $n): array
    {
        $pub = (string) $n['publication-number'];
        $title = self::lang($n['notice-title'] ?? null);
        // Tytuł TED ma postać „Polska - Kategoria CPV - właściwy tytuł”.
        $parts = preg_split('/\s+–\s+/u', $title, 3);
        if (is_array($parts) && count($parts) === 3) {
            $title = $parts[2];
        }
        $nip = null;
        foreach ((array) ($n['organisation-identifier-buyer'] ?? []) as $id) {
            if (preg_match('/(\d{3}-?\d{3}-?\d{2}-?\d{2})/', (string) $id, $m) && Nip::valid($m[1])) {
                $nip = Nip::clean($m[1]);
                break;
            }
        }
        $cpvAll = array_values(array_unique(array_map(static fn ($c) => substr((string) $c, 0, 8), (array) ($n['classification-cpv'] ?? []))));
        $cpvMain = (string) (((array) ($n['main-classification-proc'] ?? []))[0] ?? ($cpvAll[0] ?? ''));
        $date = ((array) ($n['deadline-receipt-tender-date-lot'] ?? []))[0] ?? null;
        $time = ((array) ($n['deadline-receipt-tender-time-lot'] ?? []))[0] ?? null;
        $value = self::scalar($n['estimated-value-proc'] ?? $n['estimated-value-glo'] ?? null);
        $currency = self::scalar($n['estimated-value-cur-proc'] ?? $n['estimated-value-cur-glo'] ?? null);
        $nature = self::scalar($n['contract-nature-main-proc'] ?? null);
        return [
            'source' => 'ted',
            'external_id' => $pub,
            'notice_number' => $pub,
            'notice_type' => $n['notice-type'] ?? null,
            'title' => $title !== '' ? $title : '(bez tytułu)',
            'order_type' => in_array($nature, ['works', 'services', 'supplies'], true) ? $nature : null,
            'buyer_name' => self::lang($n['buyer-name'] ?? null) ?: null,
            'buyer_nip' => $nip,
            'buyer_city' => ((array) ($n['organisation-city-buyer'] ?? []))[0] ?? null,
            'voivodeship' => Regions::fromNuts(((array) ($n['place-of-performance-subdiv-lot'] ?? []))[0] ?? null),
            'cpv_main' => $cpvMain !== '' ? substr($cpvMain, 0, 8) : null,
            'cpv_all' => implode(' ', $cpvAll),
            'value_estimated' => is_numeric($value) ? (float) $value : null,
            'currency' => $currency ?: null,
            'published_at' => self::dateUtc(self::scalar($n['publication-date'] ?? null), null),
            'deadline_at' => self::dateUtc($date, $time),
            'url' => 'https://ted.europa.eu/pl/notice/-/detail/' . rawurlencode($pub),
            'documents_url' => ((array) ($n['document-url-lot'] ?? []))[0] ?? null,
            'raw_json' => json_out($n),
        ];
    }

    /** Pole wielojęzyczne: polski, potem angielski, potem pierwszy dostępny. */
    private static function lang(mixed $v): string
    {
        if (is_string($v)) {
            return trim($v);
        }
        if (!is_array($v)) {
            return '';
        }
        foreach (['pol', 'eng', 'mul'] as $l) {
            if (isset($v[$l])) {
                return trim(is_array($v[$l]) ? (string) ($v[$l][0] ?? '') : (string) $v[$l]);
            }
        }
        $first = reset($v);
        return trim(is_array($first) ? (string) ($first[0] ?? '') : (string) $first);
    }

    private static function scalar(mixed $v): mixed
    {
        while (is_array($v)) {
            $v = reset($v);
        }
        return $v === false ? null : $v;
    }

    /** „2026-11-30+01:00” i „10:00:00+01:00” → UTC. */
    private static function dateUtc(?string $date, ?string $time): ?string
    {
        if (!$date || !preg_match('/^(\d{4}-\d{2}-\d{2})(Z|[+-]\d{2}:\d{2})?/', $date, $d)) {
            return null;
        }
        $tz = $d[2] ?? '+01:00';
        $clock = '00:00:00';
        if ($time && preg_match('/^(\d{2}:\d{2}(?::\d{2})?)(Z|[+-]\d{2}:\d{2})?/', $time, $t)) {
            $clock = strlen($t[1]) === 5 ? $t[1] . ':00' : $t[1];
            $tz = $t[2] ?? $tz;
        }
        try {
            return (new \DateTimeImmutable($d[1] . 'T' . $clock . ($tz === 'Z' ? '+00:00' : $tz)))
                ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
