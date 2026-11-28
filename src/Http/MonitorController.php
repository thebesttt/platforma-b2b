<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\AuditLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Plans;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Notify\Monitors;
use App\Modules\Notify\Notices;

/**
 * Monitory opisane jednym zdaniem: rozpoznanie zdania, potwierdzenie reguły z progami, lista z wynikiem ostatniego sprawdzenia.
 */
final class MonitorController extends Controller
{
    private const DRAFT = 'monitor_draft';

    public function index(Request $r): Response
    {
        $org = Auth::org();
        $ctx = Monitors::context($org);
        $draft = $_SESSION[self::DRAFT] ?? null;
        return $this->view('monitors/index', [
            'monitors' => Monitors::all($this->orgId()), 'ctx' => $ctx, 'draft' => is_array($draft) ? $draft : null,
            'notices' => Notices::recent($this->orgId(), 15), 'limit' => Plans::limit($org, 'monitors'),
            'used' => Plans::usage($this->orgId())['monitors'], 'aiOn' => \App\Modules\Ai\Claude::enabled(),
            'title' => 'Monitory',
        ]);
    }

    /** Krok 1: zdanie → reguła do potwierdzenia. Najpierw rozpoznanie bez AI, AI tylko gdy rodzaju nie widać. */
    public function parse(Request $r): Response
    {
        $sentence = trim(mb_substr($r->str('zdanie'), 0, 400));
        $kind = $r->str('rodzaj');
        $ctx = Monitors::context(Auth::org());
        if ($kind !== '' && isset(Monitors::KINDS[$kind])) {
            // Rodzaj wybrany z listy: formularz z domyślnymi progami.
            $_SESSION[self::DRAFT] = ['sentence' => $sentence, 'kind' => $kind, 'params' => count($ctx['sites']) === 1 ? ['site_id' => (int) array_key_first($ctx['sites'])] : [], 'by' => 'form', 'note' => ''];
            return $this->redirect('/monitory#nowy');
        }
        if (mb_strlen($sentence) < 8) {
            return $this->redirect('/monitory', 'Opisz jednym zdaniem, czego panel ma pilnować, np. „Powiadom mnie, gdy kliknięcia z Google spadną o 30%”.', 'error');
        }
        $res = Monitors::parse($sentence, $ctx);
        $by = 'panel';
        $note = '';
        if (!$res['ok']) {
            if (!\App\Modules\Ai\Claude::enabled()) {
                $_SESSION[self::DRAFT] = ['sentence' => $sentence, 'kind' => '', 'params' => [], 'by' => 'form', 'note' => 'Panel nie rozpoznał tego zdania. Wybierz rodzaj monitora z listy.'];
                return $this->redirect('/monitory#nowy');
            }
            $res = Monitors::parseAi($sentence, $ctx);
            $by = 'ai';
            if (!$res['ok']) {
                $_SESSION[self::DRAFT] = ['sentence' => $sentence, 'kind' => '', 'params' => [], 'by' => 'form', 'note' => (string) $res['error']];
                return $this->redirect('/monitory#nowy');
            }
            $note = 'Zdanie rozpoznało AI (jedno użycie z pakietu). Sprawdź regułę przed zapisaniem.';
        }
        $_SESSION[self::DRAFT] = ['sentence' => $sentence, 'kind' => $res['kind'], 'params' => $res['params'], 'by' => $by, 'note' => $note, 'missing' => $res['missing'] ?? []];
        return $this->redirect('/monitory#nowy');
    }

    /** Krok 2: zapis reguły po potwierdzeniu (z poprawionymi progami). */
    public function save(Request $r): Response
    {
        $draft = $_SESSION[self::DRAFT] ?? [];
        $kind = $r->str('rodzaj');
        $in = [];
        foreach (['site_id', 'pct', 'query', 'position', 'rival', 'count', 'url', 'seconds', 'phrase', 'min_value', 'days'] as $k) {
            if ($r->input($k) !== null) {
                $in[$k] = $r->str($k);
            }
        }
        $by = (string) ($draft['by'] ?? 'form');
        $res = Monitors::create(Auth::org(), Auth::id(), (string) ($draft['sentence'] ?? $r->str('zdanie')), $kind, $in, $by);
        if (!$res['ok']) {
            $_SESSION[self::DRAFT] = ['sentence' => (string) ($draft['sentence'] ?? ''), 'kind' => $kind, 'params' => $in, 'by' => $by, 'note' => implode(' ', $res['errors'])];
            return $this->redirect('/monitory#nowy', implode(' ', $res['errors']), 'error');
        }
        unset($_SESSION[self::DRAFT]);
        AuditLog::add('monitor.create', 'monitor', (int) $res['id'], ['kind' => $kind]);
        // Pierwsze sprawdzenie od razu, żeby użytkownik widział, czy reguła działa na jego danych.
        $m = Monitors::find($this->orgId(), (int) $res['id']);
        $check = $m && $kind !== 'tender' ? Monitors::run($m) : null;
        return $this->redirect('/monitory', 'Monitor zapisany. Panel sprawdza go codziennie rano.' . ($check ? ' Pierwsze sprawdzenie: ' . $check['detail'] : ''));
    }

    public function cancel(Request $r): Response
    {
        unset($_SESSION[self::DRAFT]);
        return $this->redirect('/monitory');
    }

    public function check(Request $r): Response
    {
        $m = Monitors::find($this->orgId(), (int) $r->param('id'));
        if (!$m) {
            return $this->notFound('Nie ma takiego monitora');
        }
        $res = Monitors::run($m);
        return $this->redirect('/monitory', ($res['hit'] ? 'Warunek spełniony. ' : (!empty($res['na']) ? 'Nie da się teraz ocenić. ' : 'Warunek niespełniony. ')) . $res['detail'], $res['hit'] ? 'error' : 'success');
    }

    public function toggle(Request $r): Response
    {
        $m = Monitors::find($this->orgId(), (int) $r->param('id'));
        if (!$m) {
            return $this->notFound('Nie ma takiego monitora');
        }
        $this->db()->update('monitors', ['active' => (int) $m['active'] ? 0 : 1], 'id = ? AND org_id = ?', [(int) $m['id'], $this->orgId()]);
        return $this->redirect('/monitory', (int) $m['active'] ? 'Monitor wstrzymany.' : 'Monitor znów działa.');
    }

    public function delete(Request $r): Response
    {
        $m = Monitors::find($this->orgId(), (int) $r->param('id'));
        if (!$m) {
            return $this->notFound('Nie ma takiego monitora');
        }
        $this->db()->run('DELETE FROM monitors WHERE id = ? AND org_id = ?', [(int) $m['id'], $this->orgId()]);
        AuditLog::add('monitor.delete', 'monitor', (int) $m['id'], ['kind' => $m['kind']]);
        return $this->redirect('/monitory', 'Monitor usunięty.');
    }

    public function read(Request $r): Response
    {
        Notices::markRead($this->orgId());
        return $this->back($r, '/monitory', 'Powiadomienia oznaczone jako przeczytane.');
    }
}
