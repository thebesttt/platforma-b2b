<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\Auth;
use App\Core\Brand;
use App\Core\Controller;
use App\Core\Kv;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Companies\Receivables;
use App\Modules\Content\ContentAudit;
use App\Modules\Content\Measure;
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
            'alerts' => (int) $db->val('SELECT COUNT(*) FROM alerts WHERE org_id = ? AND read_at IS NULL AND (company_id IS NOT NULL OR tender_id IS NULL)', [$org]),
        ];
        $alerts = $db->all(
            'SELECT a.*, c.nip FROM alerts a LEFT JOIN companies c ON c.id = a.company_id WHERE a.org_id = ? AND (a.company_id IS NOT NULL OR a.tender_id IS NULL) ORDER BY a.read_at IS NOT NULL, a.id DESC LIMIT 5',
            [$org]
        );
        // Zapisane przetargi, które rozstrzygnięto: nieprzeczytane i z ostatnich 14 dni.
        $resolved = $db->all(
            'SELECT * FROM alerts WHERE org_id = ? AND company_id IS NULL AND tender_id IS NOT NULL AND (read_at IS NULL OR created_at > ?) ORDER BY read_at IS NOT NULL, id DESC LIMIT 4',
            [$org, gmdate('Y-m-d H:i:s', time() - 14 * 86400)]
        );
        // Sekcje ofert przydzielone zalogowanej osobie.
        $mySections = \App\Modules\Offers\Offers::mySections($org, Auth::id(), 5);
        $profiles = Tenders::profiles($org, true);
        $receivables = Receivables::summary($org);
        $import = Kv::get('import:last');
        $messages = $db->all(
            'SELECT m.id, m.subject, m.kind, m.risk, m.status, m.created_at, c.company_name FROM messages m JOIN contacts c ON c.id = m.contact_id WHERE m.org_id = ? ORDER BY m.id DESC LIMIT 4',
            [$org]
        );

        // Zapytania ze strony i audyt AI: pulpit pokazuje tylko moduły włączone dla tej firmy.
        $mods = Brand::modules(Auth::org());
        $leads = in_array('zapytania', $mods, true)
            ? $db->all("SELECT id, name, email, score, level, summary, status, created_at FROM inbound_leads WHERE org_id = ? AND status IN ('new', 'contact') ORDER BY CASE WHEN status = 'new' THEN 0 ELSE 1 END, score DESC, id DESC LIMIT 6", [$org])
            : [];
        $stats['leads_new'] = (int) $db->val("SELECT COUNT(*) FROM inbound_leads WHERE org_id = ? AND status = 'new'", [$org]);
        $stats['leads_hot'] = (int) $db->val("SELECT COUNT(*) FROM inbound_leads WHERE org_id = ? AND status = 'new' AND level = 'hot'", [$org]);
        $pages = [];
        if (in_array('audyt', $mods, true)) {
            $pages = array_values(array_filter(ContentAudit::targets($org), static fn ($t) => $t['score'] !== null));
            usort($pages, static fn ($a, $b) => [(int) $b['blocked'], (int) $a['score']] <=> [(int) $a['blocked'], (int) $b['score']]);
            $stats['pages'] = count($pages);
            $pages = array_slice($pages, 0, 5);
            foreach ($pages as &$pg) {
                $task = Measure::tasks(ContentAudit::checks($org, (int) $pg['audit_id']), 1)[0] ?? null;
                $pg['task'] = $task ? $task['code'] . ' ' . $task['name'] . ' (+' . $task['gain'] . ' pkt)' : null;
            }
            unset($pg);
        }

        return $this->view('dashboard', compact('matches', 'stats', 'alerts', 'resolved', 'mySections', 'profiles', 'receivables', 'import', 'messages', 'mods', 'leads', 'pages') + ['title' => 'Pulpit']);
    }
}
