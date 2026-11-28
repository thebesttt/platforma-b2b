<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\AuditLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Modules\Seo\MonthlyReport;
use App\Modules\Seo\Sites;
use App\Modules\Seo\Tracking;

/**
 * Raporty miesięczne dla klientów agencji: lista witryny, przygotowanie, notatki, link dla klienta i wygląd raportu.
 */
final class ReportController extends Controller
{
    public function index(Request $r): Response
    {
        if (!($site = Sites::find($this->orgId(), (int) $r->param('id')))) {
            return $this->notFound('Nie ma takiej witryny');
        }
        $months = [];
        $ts = strtotime(substr(Tracking::today(), 0, 7) . '-01 UTC');
        for ($i = 0; $i < 13; $i++) {
            $months[] = gmdate('Y-m', strtotime('-' . $i . ' month', $ts));
        }
        return $this->view('seo/reports', [
            'site' => $site, 'tab' => 'raporty', 'sites' => Sites::all($this->orgId()), 'reports' => MonthlyReport::forSite($this->orgId(), (int) $site['id']),
            'months' => $months, 'default' => MonthlyReport::previousMonth(), 'brand' => MonthlyReport::brand(Auth::org()),
            'title' => 'Raporty miesięczne: ' . $site['host'],
        ]);
    }

    public function create(Request $r): Response
    {
        if (!($site = Sites::find($this->orgId(), (int) $r->param('id')))) {
            return $this->notFound('Nie ma takiej witryny');
        }
        $res = MonthlyReport::create($site, $r->str('miesiac'), Auth::id());
        if (!$res['ok']) {
            return $this->redirect('/audyt/widocznosc/' . (int) $site['id'] . '/raporty', (string) $res['error'], 'error');
        }
        return $this->redirect('/audyt/widocznosc/' . (int) $site['id'] . '/raporty/' . (int) $res['id']);
    }

    public function brand(Request $r): Response
    {
        if (!($site = Sites::find($this->orgId(), (int) $r->param('id')))) {
            return $this->notFound('Nie ma takiej witryny');
        }
        $errors = MonthlyReport::saveBrand($this->orgId(), ['color' => $r->str('color'), 'footer' => $r->str('footer'), 'remove_logo' => $r->str('usun_logo') === '1'], $r->files['logo'] ?? null);
        return $this->redirect('/audyt/widocznosc/' . (int) $site['id'] . '/raporty#wyglad', $errors ? implode(' ', $errors) : 'Wygląd raportów zapisany. Dotyczy wszystkich raportów firmy.', $errors ? 'error' : 'success');
    }

    public function show(Request $r): Response
    {
        if (!($site = Sites::find($this->orgId(), (int) $r->param('id'))) || !($rep = MonthlyReport::find($this->orgId(), (int) $site['id'], (int) $r->param('rid')))) {
            return $this->notFound('Nie ma takiego raportu');
        }
        return $this->view('seo/report', [
            'site' => $site, 'tab' => 'raporty', 'sites' => Sites::all($this->orgId()), 'rep' => $rep, 'mr' => json_in($rep['data_json']),
            'brand' => MonthlyReport::brand(Auth::org()), 'link' => $rep['token'] ? $r->baseUrl() . '/m/' . $rep['token'] : null,
            'title' => 'Raport za ' . MonthlyReport::label((string) $rep['month']) . ': ' . $site['host'],
        ]);
    }

    /** Zapis notatek, odświeżenie liczb, udostępnienie i wyłączenie linku. */
    public function update(Request $r): Response
    {
        if (!($site = Sites::find($this->orgId(), (int) $r->param('id'))) || !($rep = MonthlyReport::find($this->orgId(), (int) $site['id'], (int) $r->param('rid')))) {
            return $this->notFound('Nie ma takiego raportu');
        }
        $back = '/audyt/widocznosc/' . (int) $site['id'] . '/raporty/' . (int) $rep['id'];
        $db = $this->db();
        $action = $r->str('akcja');
        $notes = ['done_text' => mb_substr(trim($r->str('zrobione')), 0, 6000), 'next_text' => mb_substr(trim($r->str('plan')), 0, 6000), 'updated_at' => now_utc()];
        switch ($action) {
            case 'odswiez':
                $db->update('seo_reports', ['data_json' => json_out(MonthlyReport::build($site, (string) $rep['month'])), 'updated_at' => now_utc()], 'id = ?', [(int) $rep['id']]);
                return $this->redirect($back, 'Liczby w raporcie odświeżone z aktualnych danych.');
            case 'udostepnij':
                $db->update('seo_reports', $notes, 'id = ?', [(int) $rep['id']]);
                if ($notes['done_text'] === '') {
                    return $this->redirect($back . '#notatki', 'Przed udostępnieniem dopisz, co zrobiliście w tym miesiącu. Klient powinien widzieć pracę, nie tylko liczby.', 'error');
                }
                MonthlyReport::share($rep);
                AuditLog::add('report.share', 'seo_report', (int) $rep['id'], ['month' => $rep['month']], $r->ip());
                return $this->redirect($back, 'Link dla klienta jest gotowy. Klient otworzy raport bez logowania.');
            case 'wylacz':
                $db->update('seo_reports', ['token' => null, 'shared_at' => null, 'updated_at' => now_utc()], 'id = ?', [(int) $rep['id']]);
                return $this->redirect($back, 'Link wyłączony. Klient zobaczy komunikat, że raport jest niedostępny.');
            default:
                $db->update('seo_reports', $notes, 'id = ?', [(int) $rep['id']]);
                return $this->redirect($back . '#notatki', 'Notatki zapisane.');
        }
    }

    public function delete(Request $r): Response
    {
        if (!($site = Sites::find($this->orgId(), (int) $r->param('id'))) || !($rep = MonthlyReport::find($this->orgId(), (int) $site['id'], (int) $r->param('rid')))) {
            return $this->notFound('Nie ma takiego raportu');
        }
        $this->db()->run('DELETE FROM seo_reports WHERE id = ? AND org_id = ?', [(int) $rep['id'], $this->orgId()]);
        return $this->redirect('/audyt/widocznosc/' . (int) $site['id'] . '/raporty', 'Raport za ' . MonthlyReport::label((string) $rep['month']) . ' usunięty.');
    }

    /** Raport dla klienta: bez logowania, sesji i plików cookie, z wyglądem agencji. */
    public function publicReport(Request $r): Response
    {
        $headers = ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'private, no-store', 'Content-Type' => 'text/html; charset=utf-8', 'Referrer-Policy' => 'no-referrer'];
        $rep = MonthlyReport::byToken($r->param('token'));
        if (!$rep) {
            return new Response(View::render('errors/message', ['title' => 'Link nie działa', 'message' => 'Ten raport nie jest już udostępniony. Poproś agencję o nowy link.', 'bare' => true, 'footNote' => ''], 'public'), 404, $headers);
        }
        $org = $this->db()->one('SELECT * FROM organizations WHERE id = ?', [(int) $rep['org_id']]);
        $data = json_in($rep['data_json']);
        return new Response(View::render('seo/report_public', [
            'rep' => $rep, 'mr' => $data, 'brand' => MonthlyReport::brand($org),
            'title' => 'Raport za ' . MonthlyReport::label((string) $rep['month']) . ': ' . ($data['host'] ?? ''),
        ], 'report'), 200, $headers);
    }
}
