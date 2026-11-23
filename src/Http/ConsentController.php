<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\Controller;
use App\Core\Kv;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Modules\Audit\Audit;
use App\Modules\Outreach\Consents;
use App\Modules\Outreach\ProspectAudit;
use App\Modules\Outreach\ProspectEvents;
use App\Modules\Outreach\Prospects;

/**
 * Publiczna strona zgody. Adres jest wydrukowany w liście wysłanym pocztą, więc firma trafia tu z własnej woli.
 * Strona działa bez logowania, bez sesji i bez plików cookie.
 */
final class ConsentController extends Controller
{
    public function page(Request $r): Response
    {
        $headers = ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'private, no-store', 'Content-Type' => 'text/html; charset=utf-8'];
        $render = static fn (string $view, array $data, int $status = 200): Response
            => new Response(View::render($view, $data + ['bare' => true, 'footNote' => ''], 'public'), $status, $headers);
        if (Kv::hit('consent:' . hash('sha256', $r->ip()) . ':' . gmdate('YmdH'), 3600) > 40) {
            return $render('errors/message', ['title' => 'Za dużo prób', 'message' => 'Spróbuj ponownie za godzinę.'], 429);
        }
        $p = Consents::byCode((string) $r->param('code'));
        if (!$p) {
            return $render('errors/message', ['title' => 'Adres nie działa', 'message' => 'Sprawdź, czy adres jest przepisany dokładnie tak jak w liście. Kod ma 10 znaków: małe litery i cyfry.'], 404);
        }
        $orgId = (int) $p['org_id'];
        $report = ProspectAudit::report($p, $r->baseUrl());
        $given = Consents::latest($orgId, (int) $p['id']);
        $asked = $report ? Consents::reportRequest($orgId, (int) $p['id']) : null;
        $state = $p['status'] === 'objected' ? 'refused' : ($given ? 'already' : 'form');
        // Wejście z listu liczymy do lejka. Podgląd z panelu (parametr podglad) się nie liczy.
        if ($r->method === 'GET' && $r->str('podglad') !== '1') {
            ProspectEvents::visit($orgId, (int) $p['id'], 'visit', $r->ip());
        }
        $error = null;
        $email = (string) ($given['email'] ?? $asked['email'] ?? '');
        if ($r->method === 'POST') {
            // Pole niewidoczne dla ludzi: wypełniają je tylko automaty. Udajemy powodzenie i nic nie zapisujemy.
            if ($r->str('strona_www') !== '') {
                $state = 'done';
            } elseif ($r->str('action') === 'refuse') {
                Prospects::object((int) $p['org_id'], $p);
                $this->log($p, 'prospect.objection', ['via' => 'consent_page'], $r);
                $state = 'refused';
            } else {
                $res = Consents::give($p, $r->post, $r->ip(), $r->header('User-Agent'), $r->baseUrl());
                if ($res['ok']) {
                    $email = (string) $res['email'];
                    $given = Consents::latest($orgId, (int) $p['id']);
                    $asked = $report ? Consents::reportRequest($orgId, (int) $p['id']) : null;
                    // Sama prośba o raport: firma dostaje raport, a formularz zgody na ofertę zostaje dostępny.
                    $state = $given ? 'done' : 'sent';
                    if (empty($res['repeated'])) {
                        $this->log($p, !empty($res['offer']) ? 'consent.given' : 'report.requested', ['report' => !empty($res['report']), 'phone' => $r->str('agree_phone') === '1' && !empty($res['offer'])], $r);
                    }
                } else {
                    $error = (string) $res['error'];
                    $state = 'form';
                }
            }
        }
        return $render('outreach/consent', [
            'p' => $p, 'state' => $state, 'error' => $error, 'email' => $email, 'in' => $r->method === 'POST' ? $r->post : [],
            'wording' => Consents::wording((string) $p['org_name'], (string) ($report['host'] ?? '')), 'code' => (string) $r->param('code'),
            // Raport pokazujemy dopiero firmie, która o niego poprosiła. Wcześniej strona tylko go obiecuje.
            'report' => $report, 'reportOpen' => $asked !== null && $state !== 'refused', 'title' => $report ? 'Raport z audytu strony' : 'Zgoda na ofertę e-mailem',
        ], $error !== null ? 422 : 200);
    }

    /** Raport z audytu strony dla firmy z listy: adres z losowym tokenem, bez logowania, sesji i plików cookie. */
    public function report(Request $r): Response
    {
        $headers = ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'private, no-store', 'Content-Type' => 'text/html; charset=utf-8'];
        $row = ProspectAudit::byToken((string) $r->param('token'));
        if (!$row) {
            return new Response(View::render('errors/message', ['title' => 'Link nie działa', 'message' => 'Ten link do raportu wygasł albo został wyłączony.', 'bare' => true, 'footNote' => ''], 'public'), 404, $headers);
        }
        $p = $row['prospect'];
        if ($p && $r->str('podglad') !== '1') {
            ProspectEvents::visit((int) $row['org_id'], (int) $p['id'], 'report_view', $r->ip());
        }
        // Zachęta do zgody na ofertę tylko wtedy, gdy firma jej jeszcze nie dała i nie zgłosiła sprzeciwu.
        $consentUrl = $p && $p['status'] !== 'objected' && !empty($p['consent_code']) && !Consents::latest((int) $row['org_id'], (int) $p['id'])
            ? '/z/' . $p['consent_code'] : '';
        return new Response(View::render('audit/public', [
            'row' => $row, 'v' => Audit::view($row), 'agency' => (string) $row['org_name'], 'consentUrl' => $consentUrl,
            'title' => 'Raport z audytu strony ' . $row['host'], 'bare' => true, 'footNote' => '', 'wrap' => 'pub-wide',
        ], 'public'), 200, $headers);
    }

    /** Wpis w dzienniku firmy, do której należy lista (odwiedzający nie jest zalogowany). */
    private function log(array $p, string $action, array $data, Request $r): void
    {
        $this->db()->insert('audit_log', ['org_id' => (int) $p['org_id'], 'user_id' => null, 'action' => $action, 'entity' => 'prospect',
            'entity_id' => (string) $p['id'], 'data_json' => json_out($data), 'ip' => $r->ip() ?: null, 'created_at' => now_utc()]);
    }
}
