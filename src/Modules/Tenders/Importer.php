<?php

declare(strict_types=1);

namespace App\Modules\Tenders;

use App\Core\Kv;

/** Codzienny import ogłoszeń z BZP i TED, potem dopasowanie do profili. */
final class Importer
{
    /** Ile razy w jednym imporcie wolno kontynuować pobieranie po osiągnięciu limitu stron (każda runda to do 2400 ogłoszeń). */
    public const MAX_ROUNDS = 6;

    /** @return array{bzp: int, ted: int, matches: int, errors: string[]} */
    public static function run(?string $sinceUtc = null): array
    {
        $errors = [];
        $skipped = []; // pojedyncze ogłoszenia, których nie udało się zapisać (nie przerywają importu)
        $result = ['bzp' => 0, 'ted' => 0, 'matches' => 0, 'errors' => []];
        $startedAt = now_utc();

        $bzpSince = $sinceUtc ?? (Kv::get('import:bzp:until') ?: gmdate('Y-m-d H:i:s', time() - 3 * 86400));
        try {
            $bzp = new BzpClient();
            $from = (string) $bzpSince;
            // Jedno pobranie ma limit stron; przy zaległościach (pierwszy import, przerwa) kontynuujemy od ostatniego ogłoszenia.
            for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
                $rows = $bzp->since($from);
                foreach ($rows as $t) {
                    try {
                        Tenders::save($t);
                        $result['bzp']++;
                    } catch (\Throwable $e) {
                        $skipped[] = 'BZP ' . ($t['external_id'] ?? '?') . ': ' . truncate($e->getMessage(), 160);
                    }
                }
                if ($bzp->failedRequests > 0) {
                    $errors[] = 'BZP: serwer nie odpowiedział na ' . $bzp->failedRequests . ' ' . plural($bzp->failedRequests, 'zapytanie', 'zapytania', 'zapytań') . ' - brakujące ogłoszenia pobierzemy przy następnym imporcie.';
                }
                if ($bzp->continueFrom === null || $bzp->continueFrom <= $from || $bzp->failedRequests > 0) {
                    break;
                }
                $from = $bzp->continueFrom;
            }
            // Punkt startu następnego importu: miejsce przerwania albo chwila rozpoczęcia tego importu (z zakładką 10 minut).
            Kv::set('import:bzp:until', $bzp->continueFrom ?? gmdate('Y-m-d H:i:s', strtotime($startedAt . ' UTC') - 600));
        } catch (\Throwable $e) {
            $errors[] = 'BZP: ' . $e->getMessage();
        }

        $tedSince = $sinceUtc ? substr($sinceUtc, 0, 10) : (Kv::get('import:ted:until') ?: gmdate('Y-m-d', time() - 3 * 86400));
        try {
            foreach ((new TedClient())->since((string) $tedSince) as $t) {
                try {
                    Tenders::save($t);
                    $result['ted']++;
                } catch (\Throwable $e) {
                    $skipped[] = 'TED ' . ($t['external_id'] ?? '?') . ': ' . truncate($e->getMessage(), 160);
                }
            }
            Kv::set('import:ted:until', gmdate('Y-m-d', time() - 86400));
        } catch (\Throwable $e) {
            $errors[] = 'TED: ' . $e->getMessage();
        }

        if ($skipped) {
            $errors[] = 'Pominięto ' . count($skipped) . ' ' . plural(count($skipped), 'ogłoszenie', 'ogłoszenia', 'ogłoszeń') . ': ' . implode(' | ', array_slice($skipped, 0, 5));
        }
        try {
            $result['matches'] = Matcher::run(null, null, null);
        } catch (\Throwable $e) {
            $errors[] = 'Dopasowanie do profili: ' . $e->getMessage();
        }
        $result['errors'] = $errors;
        Kv::set('import:last', $result + ['at' => now_utc()]);
        return $result;
    }
}
