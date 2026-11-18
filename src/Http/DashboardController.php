<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\Controller;
use App\Core\Kv;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Companies\Receivables;
use App\Modules\Tenders\Tenders;

final class DashboardController extends Controller
{
    public function index(Request $r): Response
    {
        $org = $this->orgId();
        $db = $this->db();
        $now = now_utc();
        $weekEnd = gmdate('Y-m-d H:i:s', time() + 7 * 86400);

        $matches = $db->all(
            "SELECT m.id AS match_id, m.score, m.reasons_json, m.status, t.*, c.risk_score AS buyer_risk
               FROM tender_matches m
               JOIN tenders t ON t.id = m.tender_id
               LEFT JOIN companies c ON c.nip = t.buyer_nip
              WHERE m.org_id = ? AND m.status <> 'dismissed' AND (t.deadline_at IS NULL OR t.deadline_at > ?)
              ORDER BY CASE WHEN m.status = 'new' THEN 0 ELSE 1 END, m.score DESC, t.deadline_at
              LIMIT 8",
            [$org, $now]
        );
        $stats = [
            'new' => (int) $db->val(
                "SELECT COUNT(*) FROM tender_matches m JOIN tenders t ON t.id = m.tender_id WHERE m.org_id = ? AND m.status = 'new' AND (t.deadline_at IS NULL OR t.deadline_at > ?)",
                [$org, $now]
            ),
            'week' => (int) $db->val(
                "SELECT COUNT(*) FROM tender_matches m JOIN tenders t ON t.id = m.tender_id WHERE m.org_id = ? AND m.status <> 'dismissed' AND t.deadline_at > ? AND t.deadline_at <= ?",
                [$org, $now, $weekEnd]
            ),
            'next_deadline' => $db->val(
                "SELECT MIN(t.deadline_at) FROM tender_matches m JOIN tenders t ON t.id = m.tender_id WHERE m.org_id = ? AND m.status <> 'dismissed' AND t.deadline_at > ?",
                [$org, $now]
            ),
            'offers' => (int) $db->val("SELECT COUNT(*) FROM offers WHERE org_id = ? AND status IN ('draft', 'review')", [$org]),
            'offers_review' => (int) $db->val("SELECT COUNT(*) FROM offers WHERE org_id = ? AND status = 'review'", [$org]),
            'alerts' => (int) $db->val('SELECT COUNT(*) FROM alerts WHERE org_id = ? AND read_at IS NULL', [$org]),
        ];
        $alerts = $db->all(
            'SELECT a.*, c.nip FROM alerts a LEFT JOIN companies c ON c.id = a.company_id WHERE a.org_id = ? ORDER BY a.read_at IS NOT NULL, a.id DESC LIMIT 5',
            [$org]
        );
        $profiles = Tenders::profiles($org, true);
        $receivables = Receivables::summary($org);
        $import = Kv::get('import:last');
        $messages = $db->all(
            'SELECT m.id, m.subject, m.kind, m.risk, m.status, m.created_at, c.company_name FROM messages m JOIN contacts c ON c.id = m.contact_id WHERE m.org_id = ? ORDER BY m.id DESC LIMIT 4',
            [$org]
        );

        return $this->view('dashboard', compact('matches', 'stats', 'alerts', 'profiles', 'receivables', 'import', 'messages') + ['title' => 'Pulpit']);
    }
}
