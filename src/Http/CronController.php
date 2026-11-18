<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\Config;
use App\Core\Db;
use App\Core\Kv;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Companies\Companies;
use App\Modules\Companies\Uokik;
use App\Modules\Tenders\Importer;

/**
 * Zadania cykliczne uruchamiane z zewnątrz (GitHub Actions co rano, cron hostingu):
 *   GET/POST /cron/run?task=daily  z nagłówkiem „Authorization: Bearer <CRON_TOKEN>” albo parametrem token.
 */
final class CronController
{
    public function run(Request $r): Response
    {
        $token = (string) Config::get('CRON_TOKEN');
        $sent = (string) ($r->server['HTTP_AUTHORIZATION'] ?? '');
        $sent = str_starts_with($sent, 'Bearer ') ? substr($sent, 7) : $r->str('token');
        if ($token === '' || !hash_equals($token, $sent)) {
            return Response::json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        @set_time_limit(900);
        ignore_user_abort(true);
        $task = $r->str('task', 'daily');
        $result = match ($task) {
            'import' => Importer::run(),
            'uokik' => Uokik::sync(),
            'watchlist' => Companies::refreshWatched(),
            default => self::daily(),
        };
        return Response::json(['ok' => true, 'task' => $task, 'result' => $result]);
    }

    /** Codzienny zestaw: decyzje UOKiK (raz w tygodniu), import przetargów, odświeżenie obserwowanych firm, sprzątanie. */
    public static function daily(): array
    {
        $out = [];
        $lastUokik = Kv::get('uokik:last_sync');
        if (!$lastUokik || strtotime((string) $lastUokik . ' UTC') < time() - 6 * 86400) {
            try {
                $out['uokik'] = Uokik::sync();
                Kv::set('uokik:last_sync', now_utc());
            } catch (\Throwable $e) {
                $out['uokik'] = ['error' => $e->getMessage()];
            }
        }
        $out['import'] = Importer::run();
        try {
            $out['watchlist'] = Companies::refreshWatched();
        } catch (\Throwable $e) {
            $out['watchlist'] = ['error' => $e->getMessage()];
        }
        $db = Db::get();
        $db->run('DELETE FROM sessions WHERE expires_at < ?', [time()]);
        $db->run('DELETE FROM kv WHERE expires_at IS NOT NULL AND expires_at < ?', [time()]);
        // Ogłoszenia po terminie starsze niż 120 dni bez ofert użytkowników – usuwamy, żeby baza nie rosła bez końca.
        $db->run(
            'DELETE FROM tenders WHERE deadline_at < ? AND id NOT IN (SELECT tender_id FROM offers WHERE tender_id IS NOT NULL) AND id NOT IN (SELECT tender_id FROM tender_documents)',
            [gmdate('Y-m-d H:i:s', time() - 120 * 86400)]
        );
        return $out;
    }
}
