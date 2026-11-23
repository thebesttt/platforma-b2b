<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\Controller;
use App\Core\Kv;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Modules\Outreach\Consents;
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
        $state = $p['status'] === 'objected' ? 'refused' : 'form';
        $error = null;
        $email = '';
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
                    $state = 'done';
                    $email = (string) $res['email'];
                    if (empty($res['repeated'])) {
                        $this->log($p, 'consent.given', ['channels' => ($r->str('agree_phone') === '1' ? 'email,phone' : 'email')], $r);
                    }
                } else {
                    $error = (string) $res['error'];
                    $state = 'form';
                }
            }
        }
        return $render('outreach/consent', [
            'p' => $p, 'state' => $state, 'error' => $error, 'email' => $email, 'in' => $r->method === 'POST' ? $r->post : [],
            'wording' => Consents::wording((string) $p['org_name']), 'code' => (string) $r->param('code'), 'title' => 'Zgoda na ofertę e-mailem',
        ], $error !== null ? 422 : 200);
    }

    /** Wpis w dzienniku firmy, do której należy lista (odwiedzający nie jest zalogowany). */
    private function log(array $p, string $action, array $data, Request $r): void
    {
        $this->db()->insert('audit_log', ['org_id' => (int) $p['org_id'], 'user_id' => null, 'action' => $action, 'entity' => 'prospect',
            'entity_id' => (string) $p['id'], 'data_json' => json_out($data), 'ip' => $r->ip() ?: null, 'created_at' => now_utc()]);
    }
}
