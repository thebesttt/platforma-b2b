<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\Auth;
use App\Core\Db;

/** Liczniki w menu bocznym (nowe przetargi, alerty kontrahentów). */
final class Nav
{
    private static ?array $counts = null;

    public static function counts(): array
    {
        if (self::$counts !== null) {
            return self::$counts;
        }
        $org = Auth::orgId();
        if (!$org) {
            return self::$counts = ['matches' => 0, 'alerts' => 0, 'profiles' => 0];
        }
        $db = Db::get();
        return self::$counts = [
            'matches' => (int) $db->val(
                "SELECT COUNT(*) FROM tender_matches m JOIN tenders t ON t.id = m.tender_id WHERE m.org_id = ? AND m.status = 'new' AND (t.deadline_at IS NULL OR t.deadline_at > ?)",
                [$org, now_utc()]
            ),
            'alerts' => (int) $db->val('SELECT COUNT(*) FROM alerts WHERE org_id = ? AND read_at IS NULL', [$org]),
            'profiles' => (int) $db->val('SELECT COUNT(*) FROM tender_profiles WHERE org_id = ? AND active = 1', [$org]),
        ];
    }
}
