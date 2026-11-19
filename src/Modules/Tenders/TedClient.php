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
