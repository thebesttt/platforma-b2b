<?php

declare(strict_types=1);

namespace App\Modules\Tenders;

use App\Core\Db;

/** Zapis i odczyt przetargów oraz profili. */
final class Tenders
{
    public static function save(array $t): int
    {
        $now = now_utc();
        $t['search'] = search_text(($t['title'] ?? '') . ' ' . ($t['buyer_name'] ?? '') . ' ' . ($t['buyer_city'] ?? ''));
        $t['updated_at'] = $now;
        $db = Db::get();
        $existing = $db->one('SELECT id FROM tenders WHERE source = ? AND external_id = ?', [$t['source'], $t['external_id']]);
        if ($existing) {
            // Nie nadpisujemy pobranej treści ogłoszenia ani adresu dokumentów, jeśli nowy rekord ich nie ma.
            $upd = $t;
            unset($upd['source'], $upd['external_id']);
            foreach (['documents_url', 'value_estimated', 'currency', 'buyer_nip'] as $k) {
                if (array_key_exists($k, $upd) && ($upd[$k] === null || $upd[$k] === '')) {
                    unset($upd[$k]);
                }
            }
            $db->update('tenders', $upd, 'id = ?', [(int) $existing['id']]);
            return (int) $existing['id'];
        }
        $t['created_at'] = $now;
        return $db->insert('tenders', $t);
    }

    public static function find(int $id): ?array
    {
        return Db::get()->one('SELECT * FROM tenders WHERE id = ?', [$id]);
    }

    /** Dane szczegółowe z treści ogłoszenia (BZP), pobierane przy pierwszym otwarciu i zapamiętywane. */
    public static function details(array $tender, bool $fetch = true): array
    {
        $d = json_in($tender['details_json'] ?? null);
        if ($d || !$fetch || $tender['source'] !== 'bzp') {
            return $d;
        }
        $html = (new BzpClient())->noticeHtml((string) $tender['external_id']);
        if ($html === null) {
            return [];
        }
        $d = BzpNoticeParser::parse($html);
        if (empty($d['deadline']) && empty($d['descriptions']) && empty($d['criteria'])) {
            // Nieznany układ ogłoszenia: nie zapamiętujemy pustego wyniku, żeby poprawka parsera zadziałała od razu.
            error_log('[bzp] nie rozpoznano układu ogłoszenia ' . $tender['external_id']);
            return [];
        }
        $upd = ['details_json' => json_out($d), 'details_fetched_at' => now_utc()];
        if (!empty($d['value_estimated']) && empty($tender['value_estimated'])) {
            $upd['value_estimated'] = (float) $d['value_estimated'];
            $upd['currency'] = 'PLN';
        }
        if (!empty($d['documents_url'])) {
            $upd['documents_url'] = $d['documents_url'];
        }
        if (!empty($d['buyer']['nip']) && empty($tender['buyer_nip'])) {
            $upd['buyer_nip'] = $d['buyer']['nip'];
        }
        Db::get()->update('tenders', $upd, 'id = ?', [(int) $tender['id']]);
        return $d;
    }

    public static function profiles(int $orgId, bool $onlyActive = false): array
    {
        return Db::get()->all(
            'SELECT * FROM tender_profiles WHERE org_id = ?' . ($onlyActive ? ' AND active = 1' : '') . ' ORDER BY id',
            [$orgId]
        );
    }

    public static function profile(int $orgId, int $id): ?array
    {
        return Db::get()->one('SELECT * FROM tender_profiles WHERE org_id = ? AND id = ?', [$orgId, $id]);
    }

    public static function orderTypeLabel(?string $t): string
    {
        return match ($t) {
            'works' => 'roboty budowlane',
            'services' => 'usługi',
            'supplies' => 'dostawy',
            default => '',
        };
    }
}
