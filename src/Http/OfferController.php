<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\AuditLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Modules\Ai\Claude;
use App\Modules\Offers\Docx;
use App\Modules\Offers\Offers;
use App\Modules\Tenders\Tenders;

final class OfferController extends Controller
{
    public const STATUSES = [
        'draft' => ['label' => 'Szkic', 'chip' => 'neutral'],
        'review' => ['label' => 'Do sprawdzenia', 'chip' => 'mid'],
        'ready' => ['label' => 'Gotowa', 'chip' => 'low'],
        'submitted' => ['label' => 'Złożona', 'chip' => 'info'],
    ];

    public function index(Request $r): Response
    {
        $org = $this->orgId();
        $offers = $this->db()->all(
            'SELECT o.*, t.deadline_at AS tender_deadline, t.buyer_name,
                    (SELECT COUNT(*) FROM offer_sections s WHERE s.offer_id = o.id) AS sections
               FROM offers o LEFT JOIN tenders t ON t.id = o.tender_id WHERE o.org_id = ? ORDER BY o.updated_at DESC',
            [$org]
        );
        $libraryCount = (int) $this->db()->val('SELECT COUNT(*) FROM library_items WHERE org_id = ?', [$org]);
        return $this->view('offers/index', compact('offers', 'libraryCount') + ['title' => 'Oferty i wnioski']);
    }

    public function create(Request $r): Response
    {
        $org = $this->orgId();
        $kind = $r->str('kind') === 'grant' ? 'grant' : 'tender';
        $tender = null;
        if ($kind === 'tender' && $r->int('tender_id')) {
            $tender = Tenders::find($r->int('tender_id'));
            if (!$tender) {
                return $this->notFound();
            }
        }
        $call = [];
        if ($kind === 'grant') {
            $deadline = $r->str('deadline');
            $call = [
                'call_name' => mb_substr($r->str('call_name'), 0, 200),
                'call_url' => filter_var($r->str('call_url'), FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $r->str('call_url')) ? $r->str('call_url') : null,
                'deadline_at' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline) ? gmdate('Y-m-d H:i:s', strtotime($deadline . ' 23:59:00 Europe/Warsaw')) : null,
            ];
            if ($call['call_name'] === '') {
                return $this->redirect('/oferty', 'Podaj nazwę naboru albo programu dotacji.', 'error');
            }
        }
        $id = Offers::create($org, Auth::id(), $tender, $kind, $call);
        AuditLog::add('offer.create', 'offer', $id, ['kind' => $kind, 'tender_id' => $tender['id'] ?? null], $r->ip());
        return $this->redirect('/oferty/' . $id, $kind === 'grant'
            ? 'Utworzono szkic wniosku. Wgraj regulamin naboru i kryteria oceny, a AI pomoże napisać kolejne sekcje.'
            : 'Utworzono szkic oferty z danych ogłoszenia i Twojej biblioteki. Sprawdź oznaczone zdania.');
    }

    public function show(Request $r): Response
    {
        $offer = Offers::find($this->orgId(), (int) $r->param('id'));
        if (!$offer) {
            return $this->notFound('Nie ma takiej oferty');
        }
        $sections = Offers::sections((int) $offer['id']);
        $key = $r->str('sekcja') ?: ($sections[0]['key'] ?? '');
        $current = null;
        foreach ($sections as $s) {
            if ($s['key'] === $key) {
                $current = $s;
            }
        }
        $current ??= $sections[0] ?? null;
        $tender = $offer['tender_id'] ? Tenders::find((int) $offer['tender_id']) : null;
        $documents = $offer['kind'] === 'grant'
            ? $this->db()->all('SELECT id, filename, created_at FROM offer_documents WHERE org_id = ? AND offer_id = ? ORDER BY id', [$this->orgId(), (int) $offer['id']])
            : ($tender ? $this->db()->all('SELECT id, filename, created_at FROM tender_documents WHERE org_id = ? AND tender_id = ? ORDER BY id', [$this->orgId(), (int) $tender['id']]) : []);
        $unsourced = Offers::unsourced($sections);
        $libraryCount = (int) $this->db()->val('SELECT COUNT(*) FROM library_items WHERE org_id = ?', [$this->orgId()]);
        return $this->view('offers/show', compact('offer', 'sections', 'current', 'tender', 'documents', 'unsourced', 'libraryCount') + [
            'title' => (string) $offer['title'],
            'aiEnabled' => Claude::enabled(),
            'mode' => $r->str('tryb') === 'edycja' ? 'edit' : 'preview',
        ]);
    }

    public function update(Request $r): Response
    {
        $offer = Offers::find($this->orgId(), (int) $r->param('id'));
        if (!$offer) {
            return $this->notFound();
        }
        $upd = ['updated_at' => now_utc()];
        if (array_key_exists($r->str('status'), self::STATUSES)) {
            $upd['status'] = $r->str('status');
        }
        if ($r->str('title') !== '') {
            $upd['title'] = mb_substr($r->str('title'), 0, 200);
        }
        $this->db()->update('offers', $upd, 'id = ? AND org_id = ?', [(int) $offer['id'], $this->orgId()]);
        return $this->back($r, '/oferty/' . $offer['id'], 'Zapisano.');
    }

    public function saveSection(Request $r): Response
    {
        [$offer, $section] = $this->loadSection($r);
        if (!$section) {
            return $this->notFound();
        }
        $body = str_replace("\r\n", "\n", (string) ($r->post['body'] ?? ''));
        $this->db()->update('offer_sections', [
            'body' => mb_substr($body, 0, 60000),
            'title' => mb_substr($r->str('title') ?: $section['title'], 0, 200),
            'status' => $r->input('done') === '1' ? 'done' : 'draft',
            'updated_at' => now_utc(),
        ], 'id = ?', [(int) $section['id']]);
        $this->db()->update('offers', ['updated_at' => now_utc()], 'id = ?', [(int) $offer['id']]);
        return $this->redirect('/oferty/' . $offer['id'] . '?sekcja=' . rawurlencode($section['key']), 'Zapisano sekcję „' . $section['title'] . '”.');
    }

    public function aiSection(Request $r): Response
    {
        [$offer, $section] = $this->loadSection($r);
        if (!$section) {
            return $this->notFound();
        }
        $to = '/oferty/' . $offer['id'] . '?sekcja=' . rawurlencode($section['key']);
        if ($err = TenderController::aiGuard()) {
            return $this->redirect($to, $err, 'error');
        }
        $res = Offers::aiSection($this->orgId(), $offer, $section);
        if (!$res['ok']) {
            return $this->redirect($to, $res['error'], 'error');
        }
        $this->db()->update('offer_sections', ['body' => $res['body'], 'status' => 'draft', 'updated_at' => now_utc()], 'id = ?', [(int) $section['id']]);
        $this->db()->update('offers', ['updated_at' => now_utc()], 'id = ?', [(int) $offer['id']]);
        AuditLog::add('offer.ai_section', 'offer', (int) $offer['id'], ['section' => $section['key']], $r->ip());
        $n = substr_count($res['body'], '⟦sprawdź⟧');
        return $this->redirect($to, 'AI przygotowało nową wersję sekcji.' . ($n ? ' ' . $n . ' ' . plural($n, 'zdanie nie ma', 'zdania nie mają', 'zdań nie ma') . ' źródła – sprawdź je albo usuń.' : ''));
    }

    public function upload(Request $r): Response
    {
        $offer = Offers::find($this->orgId(), (int) $r->param('id'));
        if (!$offer) {
            return $this->notFound();
        }
        $org = $this->orgId();
        $files = TenderController::normalizeFiles($r->files['documents'] ?? null);
        if (!$files) {
            return $this->back($r, '/oferty/' . $offer['id'], 'Wybierz pliki (PDF, DOCX lub TXT).', 'error');
        }
        if ($offer['kind'] !== 'grant' && $offer['tender_id']) {
            return $this->redirect('/przetargi/' . $offer['tender_id'] . '#dokumenty', 'Dokumenty przetargu wgrywasz na stronie przetargu.', 'info');
        }
        $existing = (int) $this->db()->val('SELECT COUNT(*) FROM offer_documents WHERE org_id = ? AND offer_id = ?', [$org, (int) $offer['id']]);
        $res = TenderController::ingest($files, 12 - $existing, static function (string $name, string $mime, int $size, string $text) use ($org, $offer): void {
            Db::get()->insert('offer_documents', [
                'org_id' => $org, 'offer_id' => (int) $offer['id'], 'filename' => $name, 'mime' => $mime, 'size' => $size,
                'text' => $text, 'uploaded_by' => Auth::id(), 'created_at' => now_utc(),
            ]);
        });
        if ($res['saved']) {
            Session::flash('success', 'Dodano ' . $res['saved'] . ' ' . plural($res['saved'], 'dokument', 'dokumenty', 'dokumentów') . ' naboru.');
        }
        foreach ($res['errors'] as $err) {
            Session::flash('error', $err);
        }
        return $this->redirect('/oferty/' . $offer['id']);
    }

    public function docx(Request $r): Response
    {
        $offer = Offers::find($this->orgId(), (int) $r->param('id'));
        if (!$offer) {
            return $this->notFound();
        }
        $sections = array_map(static fn ($s) => ['title' => $s['title'], 'body' => (string) $s['body']], Offers::sections((int) $offer['id']));
        $bin = Docx::build((string) $offer['title'], $sections, $r->str('zrodla') !== '1');
        $name = trim(preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_substr((string) $offer['title'], 0, 60)) ?? 'oferta', '-') ?: 'oferta';
        return Response::download($bin, $name . '.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }

    public function delete(Request $r): Response
    {
        $this->db()->run('DELETE FROM offers WHERE id = ? AND org_id = ?', [(int) $r->param('id'), $this->orgId()]);
        return $this->redirect('/oferty', 'Usunięto szkic.');
    }

    /** @return array{0: ?array, 1: ?array} */
    private function loadSection(Request $r): array
    {
        $offer = Offers::find($this->orgId(), (int) $r->param('id'));
        if (!$offer) {
            return [null, null];
        }
        $section = $this->db()->one('SELECT * FROM offer_sections WHERE offer_id = ? AND key = ?', [(int) $offer['id'], $r->param('key')]);
        return [$offer, $section];
    }
}
