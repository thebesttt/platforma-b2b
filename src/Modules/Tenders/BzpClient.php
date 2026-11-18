<?php

declare(strict_types=1);

namespace App\Modules\Tenders;

use App\Core\Http;
use App\Core\Nip;

/**
 * Publiczne API tablicy ogłoszeń e-Zamówienia (Biuletyn Zamówień Publicznych).
 * Wyszukiwarka zwraca najwyżej 10 ogłoszeń na stronę, treść ogłoszenia pobiera się osobno.
 */
final class BzpClient
{
    public const BASE = 'https://ezamowienia.gov.pl/mo-board/api/v1/Board';
    public const NOTICE_TYPES = ['ContractNotice', 'SmallContractNotice'];

    /**
     * Ogłoszenia opublikowane od podanej chwili (UTC), znormalizowane.
     * @return array<int, array> lista rekordów do zapisania w tabeli tenders
     */
    public function since(string $fromUtc, int $maxPages = 120, ?callable $sleep = null): array
    {
        $out = [];
        $from = gmdate('Y-m-d\TH:i:s\Z', strtotime($fromUtc . ' UTC'));
        foreach (self::NOTICE_TYPES as $type) {
            for ($page = 1; $page <= $maxPages; $page++) {
                $url = self::BASE . '/Search?' . http_build_query([
                    'NoticeType' => $type,
                    'publicationDateFrom' => $from,
                    'SortingColumnName' => 'PublicationDate',
                    'SortingDirection' => 'ASC',
                    'PageNumber' => $page,
                    'PageSize' => 10,
                ]);
                $rows = Http::getJson($url);
                if (!$rows) {
                    break;
                }
                foreach ($rows as $row) {
                    if (is_array($row) && !empty($row['objectId'])) {
                        $out[$row['objectId']] = self::normalize($row);
                    }
                }
                if (count($rows) < 10) {
                    break;
                }
                $sleep ? $sleep() : usleep(150000);
            }
        }
        return array_values($out);
    }

    public function noticeHtml(string $objectId): ?string
    {
        $r = Http::get(self::BASE . '/GetNoticeHtmlBodyById?noticeId=' . rawurlencode($objectId));
        return ($r['status'] === 200 && $r['body'] !== '') ? $r['body'] : null;
    }

    public static function normalize(array $row): array
    {
        preg_match_all('/(\d{8})-\d/', (string) ($row['cpvCode'] ?? ''), $m);
        $cpv = array_values(array_unique($m[1] ?? []));
        $nip = Nip::clean((string) ($row['organizationNationalId'] ?? ''));
        $title = trim((string) ($row['orderObject'] ?? ''), " \t\n\r\0\x0B„”\"");
        return [
            'source' => 'bzp',
            'external_id' => (string) $row['objectId'],
            'notice_number' => $row['noticeNumber'] ?? null,
            'notice_type' => $row['noticeType'] ?? null,
            'title' => $title !== '' ? $title : '(bez tytułu)',
            'order_type' => match ($row['orderType'] ?? '') {
                'Works' => 'works',
                'Services' => 'services',
                'Delivery' => 'supplies',
                default => null,
            },
            'buyer_name' => $row['organizationName'] ?? null,
            'buyer_nip' => Nip::valid($nip) ? $nip : null,
            'buyer_city' => $row['organizationCity'] ?? null,
            'voivodeship' => Regions::fromBzp($row['organizationProvince'] ?? null),
            'cpv_main' => $cpv[0] ?? null,
            'cpv_all' => implode(' ', $cpv),
            'value_estimated' => null,
            'currency' => null,
            'published_at' => self::utc($row['publicationDate'] ?? null),
            'deadline_at' => self::utc($row['submittingOffersDate'] ?? null),
            'url' => 'https://ezamowienia.gov.pl/mo-client-board/bzp/notice-details/id/' . rawurlencode((string) $row['objectId']),
            'documents_url' => null,
            'raw_json' => json_out($row),
        ];
    }

    public static function utc(?string $iso): ?string
    {
        if (!$iso) {
            return null;
        }
        try {
            // Ułamki sekund z 7 cyframi (np. .5450779Z) odcinamy – DateTime przyjmuje najwyżej 6.
            $iso = preg_replace('/(\.\d{6})\d+/', '$1', $iso) ?? $iso;
            return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
