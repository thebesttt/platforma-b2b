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
    public const RESULT_TYPE = 'TenderResultNotice';

    /** Gdy pobieranie przerwał limit stron: chwila publikacji (UTC), od której trzeba kontynuować. Null = pobrano wszystko. */
    public ?string $continueFrom = null;

    /** Liczba zapytań, które nie zwróciły poprawnej odpowiedzi (do raportu z importu). */
    public int $failedRequests = 0;

    /** Przerwa między stronami wyników w mikrosekundach (nie obciążamy serwera BZP). Testy ustawiają 0. */
    public static int $pauseUs = 150000;

    /**
     * Ogłoszenia o zamówieniu opublikowane od podanej chwili (UTC), znormalizowane.
     * @return array<int, array> lista rekordów do zapisania w tabeli tenders
     */
    public function since(string $fromUtc, int $maxPages = 120, ?callable $sleep = null): array
    {
        return array_values(array_map([self::class, 'normalize'], $this->fetch(self::NOTICE_TYPES, $fromUtc, $maxPages, $sleep)));
    }

    /**
     * Ogłoszenia o wyniku postępowania opublikowane od podanej chwili (UTC). Jeden rekord na część zamówienia.
     * Wyszukiwarka podaje wynik i wykonawcę. Ceny i liczba ofert są tylko w treści ogłoszenia (BzpNoticeParser::results).
     * @return array<int, array> lista rekordów do zapisania w tabeli tender_results
     */
    public function resultsSince(string $fromUtc, int $maxPages = 120, ?callable $sleep = null): array
    {
        $out = [];
        foreach ($this->fetch([self::RESULT_TYPE], $fromUtc, $maxPages, $sleep) as $row) {
            foreach (self::normalizeResult($row) as $r) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /** @return array<string, array> surowe wiersze wyszukiwarki, klucz to identyfikator ogłoszenia */
    private function fetch(array $types, string $fromUtc, int $maxPages, ?callable $sleep): array
    {
        $out = [];
        $this->continueFrom = null;
        $this->failedRequests = 0;
        $from = gmdate('Y-m-d\TH:i:s\Z', strtotime($fromUtc . ' UTC'));
        foreach ($types as $type) {
            $lastPublished = null;
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
                if ($rows === null) {
                    // Błąd sieci lub serwera: jedna ponowna próba, potem kontynuacja od ostatniego pobranego ogłoszenia w kolejnym imporcie.
                    usleep(self::$pauseUs * 10);
                    $rows = Http::getJson($url);
                    if ($rows === null) {
                        $this->failedRequests++;
                        $this->markContinue($lastPublished ?? gmdate('Y-m-d H:i:s', strtotime($fromUtc . ' UTC')));
                        break;
                    }
                }
                if (!$rows) {
                    break;
                }
                foreach ($rows as $row) {
                    if (is_array($row) && !empty($row['objectId'])) {
                        $out[(string) $row['objectId']] = $row;
                        $lastPublished = self::utc($row['publicationDate'] ?? null) ?? $lastPublished;
                    }
                }
                if (count($rows) < 10) {
                    break;
                }
                if ($page === $maxPages) {
                    // Więcej ogłoszeń niż limit stron - nic nie gubimy, następne pobranie zaczyna od tego miejsca.
                    $this->markContinue($lastPublished ?? gmdate('Y-m-d H:i:s', strtotime($fromUtc . ' UTC')));
                }
                $sleep ? $sleep() : usleep(self::$pauseUs);
            }
        }
        return $out;
    }

    private function markContinue(string $utc): void
    {
        if ($this->continueFrom === null || $utc < $this->continueFrom) {
            $this->continueFrom = $utc;
        }
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
        $title = utrim((string) ($row['orderObject'] ?? ''));
        return [
            'source' => 'bzp',
            'external_id' => (string) $row['objectId'],
            'notice_number' => $row['noticeNumber'] ?? null,
            'notice_type' => $row['noticeType'] ?? null,
            'ocid' => !empty($row['tenderId']) ? (string) $row['tenderId'] : null,
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

    /**
     * Ogłoszenie o wyniku → po jednym rekordzie na część. W polu procedureResult wyniki części są rozdzielone średnikami
     * w kolejności numerów części, a pusta pozycja oznacza część, której to ogłoszenie nie dotyczy.
     * @return array<int, array>
     */
    public static function normalizeResult(array $row): array
    {
        $t = self::normalize($row);
        $results = explode(';', (string) ($row['procedureResult'] ?? ''));
        $contractors = is_array($row['contractors'] ?? null) ? array_values($row['contractors']) : [];
        $out = [];
        for ($i = 0, $n = max(count($results), count($contractors)); $i < $n; $i++) {
            $c = is_array($contractors[$i] ?? null) ? $contractors[$i] : [];
            $name = utrim((string) ($c['contractorName'] ?? ''));
            $result = match (trim((string) ($results[$i] ?? ''))) {
                'zawarcieUmowy' => 'contract',
                'uniewaznienie' => 'cancelled',
                'nieRozstrzygnieto' => 'unresolved',
                default => $name !== '' ? 'contract' : null,
            };
            if ($result === null) {
                continue;
            }
            $nip = Nip::clean((string) ($c['contractorNationalId'] ?? ''));
            $won = $result === 'contract' && $name !== '';
            $out[] = [
                'source' => 'bzp',
                'external_id' => $t['external_id'],
                'part' => $i + 1,
                'notice_number' => $t['notice_number'],
                'ocid' => $t['ocid'],
                'title' => $t['title'],
                'order_type' => $t['order_type'],
                'buyer_name' => $t['buyer_name'],
                'buyer_nip' => $t['buyer_nip'],
                'buyer_city' => $t['buyer_city'],
                'voivodeship' => $t['voivodeship'],
                'cpv_main' => $t['cpv_main'],
                'cpv_all' => $t['cpv_all'],
                'result' => $result,
                'winner_name' => $won ? $name : null,
                'winner_nip' => $won && Nip::valid($nip) ? $nip : null,
                'winner_city' => $won ? (utrim((string) ($c['contractorCity'] ?? '')) ?: null) : null,
                'winner_voivodeship' => $won ? Regions::fromBzp($c['contractorProvince'] ?? null) : null,
                'published_at' => $t['published_at'],
                'url' => $t['url'],
            ];
        }
        return $out;
    }

    public static function utc(?string $iso): ?string
    {
        if (!$iso) {
            return null;
        }
        try {
            // Ułamki sekund z 7 cyframi (np. .5450779Z) odcinamy - DateTime przyjmuje najwyżej 6.
            $iso = preg_replace('/(\.\d{6})\d+/', '$1', $iso) ?? $iso;
            return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
