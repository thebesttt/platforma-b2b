<?php

declare(strict_types=1);

namespace App\Modules\Tenders;

use App\Core\Kv;

/** Codzienny import ogłoszeń z BZP i TED, potem dopasowanie do profili. */
final class Importer
{
    /** @return array{bzp: int, ted: int, matches: int, errors: string[]} */
    public static function run(?string $sinceUtc = null): array
    {
        $errors = [];
        $result = ['bzp' => 0, 'ted' => 0, 'matches' => 0, 'errors' => []];
        $startedAt = now_utc();

        $bzpSince = $sinceUtc ?? (Kv::get('import:bzp:until') ?: gmdate('Y-m-d H:i:s', time() - 3 * 86400));
        try {
            foreach ((new BzpClient())->since((string) $bzpSince) as $t) {
                Tenders::save($t);
                $result['bzp']++;
            }
            Kv::set('import:bzp:until', gmdate('Y-m-d H:i:s', strtotime($startedAt . ' UTC') - 600));
        } catch (\Throwable $e) {
            $errors[] = 'BZP: ' . $e->getMessage();
        }

        $tedSince = $sinceUtc ? substr($sinceUtc, 0, 10) : (Kv::get('import:ted:until') ?: gmdate('Y-m-d', time() - 3 * 86400));
        try {
            foreach ((new TedClient())->since((string) $tedSince) as $t) {
                Tenders::save($t);
                $result['ted']++;
            }
            Kv::set('import:ted:until', gmdate('Y-m-d', time() - 86400));
        } catch (\Throwable $e) {
            $errors[] = 'TED: ' . $e->getMessage();
        }

        $result['matches'] = Matcher::run(null, null, null);
        $result['errors'] = $errors;
        Kv::set('import:last', $result + ['at' => now_utc()]);
        return $result;
    }
}
