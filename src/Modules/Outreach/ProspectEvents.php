<?php

declare(strict_types=1);

namespace App\Modules\Outreach;

use App\Core\Db;
use App\Modules\Inbound\Inbound;

/**
 * Zdarzenia firm z listy potencjalnych klientów i lejek listów.
 *
 * List kosztuje druk i znaczek, więc panel liczy, co z niego wynika: ile firm weszło na stronę zgody, ile zostawiło zgodę,
 * ile dostało ofertę i ile odpowiedziało. Wejścia liczymy po stronie serwera, bez plików cookie i bez skryptów śledzących.
 */
final class ProspectEvents
{
    public const KINDS = [
        'letter' => 'List wysłany',
        'visit' => 'Wejście na stronę zgody',
        'report' => 'Prośba o raport z audytu',
        'report_view' => 'Otwarcie raportu',
        'consent' => 'Zgoda na ofertę e-mailem',
        'refused' => 'Odmowa korespondencji',
        'offer' => 'Oferta e-mailem wysłana',
        'reply' => 'Firma odpowiedziała',
    ];

    /** Okresy lejka w dniach. Zero oznacza cały czas. */
    public const PERIODS = [30 => 'ostatnie 30 dni', 90 => 'ostatnie 90 dni', 365 => 'ostatni rok', 0 => 'od początku'];

    /** $once: nie dopisuj, jeśli ostatnie zdarzenie tej firmy jest tego samego rodzaju (np. sprzeciw zgłoszony dwiema drogami naraz). */
    public static function add(int $orgId, int $prospectId, string $kind, ?string $detail = null, ?int $userId = null, bool $once = false): void
    {
        if (!isset(self::KINDS[$kind]) || $prospectId <= 0) {
            return;
        }
        $db = Db::get();
        if ($once && $db->val('SELECT kind FROM prospect_events WHERE org_id = ? AND prospect_id = ? ORDER BY id DESC LIMIT 1', [$orgId, $prospectId]) === $kind) {
            return;
        }
        $db->insert('prospect_events', ['org_id' => $orgId, 'prospect_id' => $prospectId, 'kind' => $kind, 'detail' => $detail !== null ? mb_substr($detail, 0, 300) : null,
            'created_by' => $userId, 'created_at' => now_utc()]);
    }

    /** Wejście na stronę zgody albo otwarcie raportu. Z jednego adresu liczymy je raz na godzinę. */
    public static function visit(int $orgId, int $prospectId, string $kind, string $ip): void
    {
        if (!in_array($kind, ['visit', 'report_view'], true) || $prospectId <= 0) {
            return;
        }
        $db = Db::get();
        $hash = Inbound::ipHash($ip);
        $recent = $db->val('SELECT 1 FROM prospect_events WHERE org_id = ? AND prospect_id = ? AND kind = ? AND ip_hash = ? AND created_at > ? LIMIT 1',
            [$orgId, $prospectId, $kind, $hash, gmdate('Y-m-d H:i:s', time() - 3600)]);
        if (!$recent) {
            $db->insert('prospect_events', ['org_id' => $orgId, 'prospect_id' => $prospectId, 'kind' => $kind, 'ip_hash' => $hash, 'created_at' => now_utc()]);
        }
    }

    /** Zdarzenie dla każdej firmy z listy powiązanej z kontaktem (oferta wysłana, odpowiedź). */
    public static function forContact(int $orgId, int $contactId, string $kind, ?int $userId = null): void
    {
        foreach (Db::get()->all('SELECT id FROM prospects WHERE org_id = ? AND contact_id = ?', [$orgId, $contactId]) as $p) {
            self::add($orgId, (int) $p['id'], $kind, null, $userId);
        }
    }

    /** Cofnięcie oznaczenia „firma odpowiedziała”. */
    public static function dropForContact(int $orgId, int $contactId, string $kind): void
    {
        Db::get()->run('DELETE FROM prospect_events WHERE org_id = ? AND kind = ? AND prospect_id IN (SELECT id FROM prospects WHERE org_id = ? AND contact_id = ?)',
            [$orgId, $kind, $orgId, $contactId]);
    }

    /** Historia firmy: od najnowszych, z liczbą powtórzeń dla wejść tego samego dnia. */
    public static function timeline(int $orgId, int $prospectId, int $limit = 40): array
    {
        $rows = Db::get()->all('SELECT kind, detail, created_at FROM prospect_events WHERE org_id = ? AND prospect_id = ? ORDER BY id DESC LIMIT ' . (int) $limit, [$orgId, $prospectId]);
        $out = [];
        foreach ($rows as $r) {
            $day = local_date((string) $r['created_at']);
            $last = $out ? count($out) - 1 : -1;
            if ($last >= 0 && $out[$last]['kind'] === $r['kind'] && $out[$last]['day'] === $day && in_array($r['kind'], ['visit', 'report_view'], true)) {
                $out[$last]['times']++;
                continue;
            }
            $out[] = ['kind' => (string) $r['kind'], 'label' => self::KINDS[$r['kind']] ?? (string) $r['kind'], 'detail' => (string) ($r['detail'] ?? ''), 'at' => (string) $r['created_at'], 'day' => $day, 'times' => 1];
        }
        return $out;
    }

    /**
     * Lejek listów: firmy, do których wyszedł list w wybranym okresie, i co się z nimi stało potem.
     * @return array{letters: int, firms: int, visit: int, report: int, consent: int, offer: int, reply: int, refused: int, other_consents: int}
     */
    public static function funnel(int $orgId, int $days = 0): array
    {
        $db = Db::get();
        $since = $days > 0 ? gmdate('Y-m-d H:i:s', time() - $days * 86400) : '1970-01-01 00:00:00';
        $cohort = "SELECT prospect_id FROM prospect_events WHERE org_id = ? AND kind = 'letter' AND created_at >= ?";
        $out = ['letters' => (int) $db->val("SELECT COUNT(*) FROM prospect_events WHERE org_id = ? AND kind = 'letter' AND created_at >= ?", [$orgId, $since]),
            'firms' => 0, 'visit' => 0, 'report' => 0, 'consent' => 0, 'offer' => 0, 'reply' => 0, 'refused' => 0, 'other_consents' => 0];
        foreach ($db->all("SELECT kind, COUNT(DISTINCT prospect_id) AS n FROM prospect_events WHERE org_id = ? AND prospect_id IN ($cohort) GROUP BY kind", [$orgId, $orgId, $since]) as $r) {
            if ($r['kind'] === 'letter') {
                $out['firms'] = (int) $r['n'];
            } elseif (isset($out[$r['kind']])) {
                $out[$r['kind']] = (int) $r['n'];
            }
        }
        // Zgody firm, do których list nie wyszedł: zapisane ręcznie albo z innych dróg.
        $out['other_consents'] = (int) $db->val(
            "SELECT COUNT(DISTINCT prospect_id) FROM prospect_events WHERE org_id = ? AND kind = 'consent' AND created_at >= ? AND prospect_id NOT IN ($cohort)",
            [$orgId, $since, $orgId, $since]
        );
        return $out;
    }

    public static function forget(int $orgId, int $prospectId): void
    {
        Db::get()->run('DELETE FROM prospect_events WHERE org_id = ? AND prospect_id = ?', [$orgId, $prospectId]);
    }
}
