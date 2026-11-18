<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Kv;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Audit\Audit;

/** Audyt strony klienta i stron konkurencji oraz informacje o module reklam. */
final class AuditController extends Controller
{
    public function index(Request $r): Response
    {
        $org = $this->orgId();
        $audits = array_map(static fn ($a) => ['row' => $a, 'view' => Audit::view($a)], Audit::recent($org));
        $own = Auth::org()['website'] ?? '';
        return $this->view('audit/index', ['audits' => $audits, 'own' => $own, 'title' => 'Audyt strony']);
    }

    public function run(Request $r): Response
    {
        $org = $this->orgId();
        $url = mb_substr($r->str('url'), 0, 300);
        if ($url === '') {
            return $this->redirect('/audyt', 'Podaj adres strony.', 'error');
        }
        if (Kv::hit('audit:' . $org . ':' . gmdate('YmdH'), 3600) > 20) {
            return $this->redirect('/audyt', 'Osiągnięto limit 20 audytów na godzinę.', 'error');
        }
        @set_time_limit(180);
        $res = Audit::run($org, Auth::id(), $url, $r->input('pagespeed') === '1', mb_substr($r->str('label'), 0, 60) ?: null);
        if (!$res['ok']) {
            return $this->redirect('/audyt', $res['error'], 'error');
        }
        return $this->redirect('/audyt/' . $res['id'], 'Audyt gotowy.');
    }

    public function show(Request $r): Response
    {
        $row = Audit::find($this->orgId(), (int) $r->param('id'));
        if (!$row) {
            return $this->notFound();
        }
        $v = Audit::view($row);
        $history = $this->db()->all('SELECT id, score, created_at FROM site_audits WHERE org_id = ? AND host = ? ORDER BY id DESC LIMIT 10', [$this->orgId(), $row['host']]);
        return $this->view('audit/show', ['row' => $row, 'v' => $v, 'history' => $history, 'title' => 'Audyt ' . $row['host']]);
    }

    public function compare(Request $r): Response
    {
        $sites = Audit::latestPerHost($this->orgId());
        return $this->view('audit/compare', ['sites' => $sites, 'title' => 'Porównanie z konkurencją']);
    }

    public function ads(Request $r): Response
    {
        return $this->view('ads/index', ['title' => 'Reklamy Google i Meta']);
    }
}
