<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\AuditLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Kv;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Modules\Ai\Claude;
use App\Modules\Content\ContentAudit;
use App\Modules\Content\Measure;
use App\Modules\Content\Ruleset;
use App\Modules\Content\Services;
use App\Modules\Content\Sitemap;
use App\Modules\Outreach\Outreach;

/**
 * Audyt treści pod wyszukiwarki i AI: lista stron z frazą, raport z 24 wskaźnikami, porównanie „przed i po”,
 * wytyczne wskaźników i raport dla klienta pod linkiem bez logowania.
 */
final class ContentController extends Controller
{
    /** Audytów na firmę w ciągu godziny. Każdy to kilkanaście zapytań do cudzej strony i do płatnych usług. */
    private const HOURLY = 30;

    public function index(Request $r): Response
    {
        $org = $this->orgId();
        $contactId = $r->int('klient');
        return $this->view('content/index', [
            'targets' => ContentAudit::targets($org, $contactId ?: null),
            'contacts' => $this->db()->all('SELECT id, company_name FROM contacts WHERE org_id = ? ORDER BY company_name LIMIT 300', [$org]),
            'contactId' => $contactId, 'services' => self::services(), 'title' => 'Audyt AI',
        ]);
    }

    /** Podstrony z mapy witryny: wpisujesz frazę przy tych, które chcesz sprawdzić. */
    public function sitemap(Request $r): Response
    {
        $org = $this->orgId();
        $site = mb_substr($r->str('url'), 0, 300);
        $res = null;
        $known = [];
        if ($site !== '') {
            if (Kv::hit('content-sitemap:' . $org . ':' . gmdate('YmdH'), 3600) > 20) {
                $res = ['ok' => false, 'error' => 'Za dużo prób w tej godzinie. Spróbuj później.'];
            } else {
                $res = Sitemap::urls($site);
            }
            foreach ($this->db()->all('SELECT url FROM content_targets WHERE org_id = ?', [$org]) as $t) {
                $known[\App\Modules\Content\Fetcher::canonicalForm((string) $t['url'])] = true;
            }
        }
        return $this->view('content/sitemap', ['site' => $site, 'res' => $res, 'known' => $known, 'title' => 'Strony z mapy witryny']);
    }

    /** Dodaje do audytu podstrony z wpisaną frazą. Audyty trafiają do kolejki i wykonują się po kolei na liście stron. */
    public function sitemapAdd(Request $r): Response
    {
        $org = $this->orgId();
        $urls = (array) ($r->post['u'] ?? []);
        $phrases = (array) ($r->post['p'] ?? []);
        $types = (array) ($r->post['t'] ?? []);
        $added = 0;
        $errors = [];
        $limited = false;
        foreach ($urls as $i => $url) {
            $phrase = is_string($phrases[$i] ?? null) ? trim($phrases[$i]) : '';
            if (!is_string($url) || $phrase === '') {
                continue;
            }
            if ($added >= 20) {
                $limited = true;
                break;
            }
            $res = ContentAudit::addTarget($org, Auth::id(), $url, $phrase, is_string($types[$i] ?? null) ? $types[$i] : 'other');
            if (!$res['ok']) {
                $errors[] = truncate($url, 60) . ': ' . $res['error'];
                continue;
            }
            if (!empty($res['existing'])) {
                continue;
            }
            if ($this->limit($org)) {
                $limited = true;
                break;
            }
            ContentAudit::start(ContentAudit::target($org, (int) $res['id']), Auth::id());
            $added++;
        }
        if ($added === 0 && !$errors) {
            return $this->back($r, '/audyt/tresc/mapa', 'Wpisz frazę przy co najmniej jednej stronie. Strony bez frazy pomijamy.', 'error');
        }
        $msg = 'Dodano ' . $added . ' ' . plural($added, 'stronę', 'strony', 'stron') . '. Audyty wykonują się po kolei, zostaw tę kartę otwartą.'
            . ($limited ? ' Resztę dodaj za godzinę: jednorazowo 20 stron i ' . self::HOURLY . ' audytów na godzinę.' : '') . ($errors ? ' Pominięte: ' . implode('; ', array_slice($errors, 0, 3)) . '.' : '');
        return $this->redirect('/audyt/tresc', $msg, $added ? 'success' : 'error');
    }

    /** Które usługi pomiarowe są podłączone: od tego zależy, ile wskaźników da się zmierzyć. */
    public static function services(): array
    {
        return ['ai' => Claude::enabled(), 'render' => Services::renderEnabled(), 'psi_key' => (string) config('PSI_KEY') !== ''];
    }

    public function add(Request $r): Response
    {
        $org = $this->orgId();
        $res = ContentAudit::addTarget($org, Auth::id(), $r->str('url'), $r->str('phrase'), $r->str('page_type'), $r->int('contact_id') ?: null);
        if (!$res['ok']) {
            return $this->redirect('/audyt/tresc', (string) $res['error'], 'error');
        }
        $target = ContentAudit::target($org, (int) $res['id']);
        if (!empty($res['existing'])) {
            return $this->redirect('/audyt/tresc/' . $target['id'], 'Ta strona z tą frazą jest już na liście.', 'info');
        }
        if ($limit = $this->limit($org)) {
            return $this->redirect('/audyt/tresc/' . $target['id'], $limit, 'error');
        }
        ContentAudit::start($target, Auth::id(), $r->str('label'));
        return $this->redirect('/audyt/tresc/' . $target['id']);
    }

    private function limit(int $org): ?string
    {
        return Kv::hit('content-audit:' . $org . ':' . gmdate('YmdH'), 3600) > self::HOURLY ? 'Osiągnięto limit ' . self::HOURLY . ' audytów na godzinę. Spróbuj później.' : null;
    }

    public function show(Request $r): Response
    {
        $org = $this->orgId();
        $target = ContentAudit::target($org, (int) $r->param('id'));
        if (!$target) {
            return $this->notFound();
        }
        $audits = ContentAudit::audits($org, (int) $target['id']);
        $open = ContentAudit::openAudit($org, (int) $target['id']);
        $picked = $r->int('audyt');
        $audit = null;
        foreach ($audits as $a) {
            if ((int) $a['id'] === $picked) {
                $audit = $a;
            }
        }
        $audit ??= $audits[0] ?? null;
        $checks = $audit ? ContentAudit::checks($org, (int) $audit['id']) : [];
        $failed = $this->db()->one("SELECT id, error, created_at FROM content_audits WHERE org_id = ? AND target_id = ? AND status = 'failed' ORDER BY id DESC LIMIT 1", [$org, (int) $target['id']]);
        return $this->view('content/show', [
            'target' => $target, 'audits' => $audits, 'audit' => $audit, 'checks' => $checks, 'open' => $open,
            'openSteps' => $open ? json_in($open['steps_json']) : [],
            'failed' => $failed && (!$audits || (int) $failed['id'] > (int) $audits[0]['id']) ? $failed : null,
            'tasks' => Measure::tasks($checks),
            'totals' => $checks ? Ruleset::totals($checks) : null,
            'questions' => ContentAudit::questions($target),
            'contact' => !empty($target['contact_id']) ? Outreach::contact($org, (int) $target['contact_id']) : null,
            'shares' => ContentAudit::shares($org, (int) $target['id']),
            'newLink' => $r->str('link') !== '' && preg_match('/^[a-f0-9]{40}$/', $r->str('link')) ? $r->baseUrl() . '/r/' . $r->str('link') : null,
            'services' => self::services(),
            'title' => 'Audyt AI: ' . truncate((string) ($audit['title'] ?? $target['url']), 60),
        ]);
    }

    public function run(Request $r): Response
    {
        $org = $this->orgId();
        $target = ContentAudit::target($org, (int) $r->param('id'));
        if (!$target) {
            return $this->notFound();
        }
        if (!ContentAudit::openAudit($org, (int) $target['id']) && ($limit = $this->limit($org))) {
            return $this->redirect('/audyt/tresc/' . $target['id'], $limit, 'error');
        }
        ContentAudit::start($target, Auth::id(), $r->str('label'));
        return $this->redirect('/audyt/tresc/' . $target['id']);
    }

    /** Jeden krok audytu. Wywołuje go skrypt na stronie raportu, aż audyt się skończy. */
    public function step(Request $r): Response
    {
        $org = $this->orgId();
        $a = ContentAudit::audit($org, (int) $r->param('id'));
        if (!$a) {
            return Response::json(['done' => true, 'failed' => true, 'error' => 'Nie ma takiego audytu.'], 404);
        }
        @set_time_limit(120);
        $res = ContentAudit::advance($org, (int) $a['id']);
        $labels = [];
        foreach (Measure::STEPS as $k => $label) {
            $labels[] = ['key' => $k, 'label' => $label, 'state' => $res['steps'][$k] ?? 'pending'];
        }
        return Response::json(['done' => $res['done'], 'failed' => $res['failed'], 'error' => $res['error'] ?? null, 'steps' => $labels]);
    }

    /** Wszystkie pozostałe kroki w jednym zapytaniu: dla przeglądarki bez JavaScript. */
    public function finish(Request $r): Response
    {
        $org = $this->orgId();
        $a = ContentAudit::audit($org, (int) $r->param('id'));
        if (!$a) {
            return $this->notFound();
        }
        @set_time_limit(300);
        $res = ContentAudit::runAll($org, (int) $a['id']);
        return $this->redirect('/audyt/tresc/' . $a['target_id'], $res['failed'] ? ($res['error'] ?? 'Audyt się nie udał.') : 'Audyt gotowy.', $res['failed'] ? 'error' : 'success');
    }

    public function questions(Request $r): Response
    {
        $org = $this->orgId();
        $target = ContentAudit::target($org, (int) $r->param('id'));
        if (!$target) {
            return $this->notFound();
        }
        $to = '/audyt/tresc/' . $target['id'] . '#pytania';
        if ($r->str('action') === 'propose') {
            $res = ContentAudit::proposeQuestions($target);
            return $this->redirect($to, $res['ok'] ? 'Model AI zaproponował ' . $res['count'] . ' pytań. Popraw je i zatwierdź.' : (string) $res['error'], $res['ok'] ? 'success' : 'error');
        }
        $lock = $r->str('action') === 'lock';
        $res = ContentAudit::saveQuestions($target, preg_split('/\R/u', (string) $r->input('questions', '')) ?: [], $lock);
        if (!$res['ok']) {
            return $this->redirect($to, (string) $res['error'], 'error');
        }
        if ($lock) {
            AuditLog::add('content.questions_lock', 'content_target', (int) $target['id'], ['count' => $res['count']], $r->ip());
        }
        return $this->redirect($to, $lock ? 'Zatwierdzono ' . $res['count'] . ' pytań. Następny audyt policzy wskaźnik D2.' : 'Zapisano wersję roboczą pytań.');
    }

    public function compare(Request $r): Response
    {
        $org = $this->orgId();
        $target = ContentAudit::target($org, (int) $r->param('id'));
        if (!$target) {
            return $this->notFound();
        }
        $audits = ContentAudit::audits($org, (int) $target['id']);
        $byId = array_column($audits, null, 'id');
        $b = $byId[$r->int('po')] ?? ($audits[0] ?? null);
        $a = $byId[$r->int('przed')] ?? (count($audits) > 1 ? end($audits) : null);
        return $this->view('content/compare', [
            'target' => $target, 'audits' => $audits, 'a' => $a, 'b' => $b,
            'ca' => $a ? ContentAudit::checks($org, (int) $a['id']) : [], 'cb' => $b ? ContentAudit::checks($org, (int) $b['id']) : [],
            'title' => 'Przed i po: ' . truncate((string) ($b['title'] ?? $target['url']), 50),
        ]);
    }

    public function share(Request $r): Response
    {
        $org = $this->orgId();
        $target = ContentAudit::target($org, (int) $r->param('id'));
        if (!$target) {
            return $this->notFound();
        }
        if ($r->str('action') === 'revoke') {
            $n = ContentAudit::revokeShares($org, (int) $target['id']);
            AuditLog::add('content.share_revoke', 'content_target', (int) $target['id'], ['links' => $n], $r->ip());
            return $this->redirect('/audyt/tresc/' . $target['id'] . '#link', 'Wyłączono linki dla klienta. Stare adresy już nie działają.');
        }
        if (!ContentAudit::audits($org, (int) $target['id'])) {
            return $this->redirect('/audyt/tresc/' . $target['id'], 'Najpierw wykonaj audyt. Link pokazuje gotowy raport.', 'error');
        }
        $token = ContentAudit::share($target, Auth::id());
        AuditLog::add('content.share', 'content_target', (int) $target['id'], [], $r->ip());
        return $this->redirect('/audyt/tresc/' . $target['id'] . '?link=' . $token . '#link');
    }

    public function delete(Request $r): Response
    {
        $org = $this->orgId();
        $target = ContentAudit::target($org, (int) $r->param('id'));
        if (!$target) {
            return $this->notFound();
        }
        ContentAudit::deleteTarget($org, (int) $target['id']);
        AuditLog::add('content.target_delete', 'content_target', (int) $target['id'], ['url' => $target['url']], $r->ip());
        return $this->redirect('/audyt/tresc', 'Usunięto stronę razem z jej audytami i linkami dla klienta.');
    }

    public function guidelines(Request $r): Response
    {
        return $this->view('content/guidelines', ['title' => 'Wytyczne wskaźników']);
    }

    /** Raport dla klienta: bez logowania, tylko do odczytu, poza indeksem wyszukiwarek. */
    public function publicReport(Request $r): Response
    {
        $target = ContentAudit::byToken($r->param('token'));
        $noindex = ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'private, no-store', 'Content-Type' => 'text/html; charset=utf-8'];
        if (!$target) {
            return new Response(View::render('errors/message', ['title' => 'Link nie działa', 'message' => 'Ten link do raportu wygasł albo został wyłączony. Poproś o nowy.', 'bare' => true], 'public'), 404, $noindex);
        }
        $org = (int) $target['org_id'];
        $audits = ContentAudit::audits($org, (int) $target['id']);
        if (!$audits) {
            return new Response(View::render('errors/message', ['title' => 'Raport nie jest jeszcze gotowy', 'message' => 'Audyt tej strony jeszcze się nie zakończył.', 'bare' => true], 'public'), 404, $noindex);
        }
        $audit = $audits[0];
        $first = count($audits) > 1 ? end($audits) : null;
        $checks = ContentAudit::checks($org, (int) $audit['id']);
        $orgRow = $this->db()->one('SELECT name FROM organizations WHERE id = ?', [$org]);
        return new Response(View::render('content/public', [
            'target' => $target, 'audit' => $audit, 'first' => $first, 'checks' => $checks, 'tasks' => Measure::tasks($checks), 'totals' => Ruleset::totals($checks),
            'firstChecks' => $first ? ContentAudit::checks($org, (int) $first['id']) : [], 'agency' => (string) ($orgRow['name'] ?? ''),
            'title' => 'Raport: ' . truncate((string) ($audit['title'] ?? $target['url']), 60), 'bare' => true, 'wrap' => 'pub-wide',
        ], 'public'), 200, $noindex);
    }
}
