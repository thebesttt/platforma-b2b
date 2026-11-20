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
use App\Modules\Offers\Eligibility;
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
        $progress = Offers::progress($org);
        $mine = Offers::mySections($org, Auth::id());
        $calls = \App\Modules\Offers\GrantCalls::currentCount($org);
        $callsSoon = \App\Modules\Offers\GrantCalls::closingWithin($org, \App\Modules\Offers\GrantCalls::BOARD_DAYS);
        return $this->view('offers/index', compact('offers', 'libraryCount', 'progress', 'mine', 'calls', 'callsSoon') + ['title' => 'Oferty i wnioski']);
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

    /** Klucz pseudo-sekcji z listą dokumentów do złożenia. */
    public const DOCS_KEY = '_dokumenty';

    /** Klucz pseudo-sekcji z warunkami naboru (tylko wnioski o dotację). */
    public const TERMS_KEY = '_warunki';

    public function show(Request $r): Response
    {
        $offer = Offers::find($this->orgId(), (int) $r->param('id'));
        if (!$offer) {
            return $this->notFound('Nie ma takiej oferty');
        }
        return $this->render($offer, $r->str('sekcja'), $r->str('tryb') === 'edycja' ? 'edit' : 'preview');
    }

    /**
     * Ekran oferty. $draft i $conflict służą do pokazania formularza po odrzuconym zapisie: tekst użytkownika zostaje w polu,
     * a obok widać wersję, którą w międzyczasie zapisał ktoś inny.
     */
    private function render(array $offer, string $key, string $mode, ?array $draft = null, ?array $conflict = null, int $status = 200): Response
    {
        $org = $this->orgId();
        $sections = Offers::sections((int) $offer['id']);
        $showDocs = $key === self::DOCS_KEY;
        $showTerms = $key === self::TERMS_KEY && $offer['kind'] === 'grant';
        $current = null;
        if (!$showDocs && !$showTerms) {
            foreach ($sections as $s) {
                if ($s['key'] === $key) {
                    $current = $s;
                }
            }
            $current ??= $sections[0] ?? null;
        }
        $tender = $offer['tender_id'] ? Tenders::find((int) $offer['tender_id']) : null;
        $documents = $offer['kind'] === 'grant'
            ? $this->db()->all('SELECT id, filename, created_at FROM offer_documents WHERE org_id = ? AND offer_id = ? ORDER BY id', [$org, (int) $offer['id']])
            : ($tender ? $this->db()->all('SELECT id, filename, created_at FROM tender_documents WHERE org_id = ? AND tender_id = ? ORDER BY id', [$org, (int) $tender['id']]) : []);
        $unsourced = Offers::unsourced($sections);
        $libraryCount = (int) $this->db()->val('SELECT COUNT(*) FROM library_items WHERE org_id = ?', [$org]);
        $resp = $this->view('offers/show', compact('offer', 'sections', 'current', 'tender', 'documents', 'unsourced', 'libraryCount', 'showDocs', 'showTerms', 'draft', 'conflict') + [
            'eligibility' => $offer['kind'] === 'grant' ? Eligibility::latest($org, (int) $offer['id']) : null,
            'title' => (string) $offer['title'],
            'aiEnabled' => Claude::enabled(),
            'mode' => $mode,
            'team' => Offers::team($org),
            'me' => Auth::id(),
            'checklist' => Offers::checklist($org, (int) $offer['id']),
        ]);
        $resp->status = $status;
        return $resp;
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
        $title = $r->str('title');
        $done = $r->input('done') === '1';
        $base = $r->str('version') !== '' ? $r->int('version') : null;
        $res = Offers::saveSection($section, $body, $title, $done, Auth::id(), $base);
        if (!$res['ok']) {
            // Ktoś zapisał tę sekcję po otwarciu formularza. Nie nadpisujemy jego pracy i nie gubimy tekstu tej osoby.
            $draft = ['body' => mb_substr($body, 0, 60000), 'title' => $title !== '' ? $title : (string) $section['title'], 'done' => $done];
            return $this->render($offer, (string) $section['key'], 'edit', $draft, $res['conflict'], 409);
        }
        return $this->redirect('/oferty/' . $offer['id'] . '?sekcja=' . rawurlencode($section['key']), 'Zapisano sekcję „' . $section['title'] . '”.');
    }

    /** Przydzielenie sekcji osobie z zespołu. */
    public function assign(Request $r): Response
    {
        [$offer, $section] = $this->loadSection($r);
        if (!$section) {
            return $this->notFound();
        }
        $to = '/oferty/' . $offer['id'] . '?sekcja=' . rawurlencode($section['key']);
        $userId = $r->int('user_id') ?: null;
        $res = Offers::assign($this->orgId(), $offer, $section, $userId, Auth::id(), $r->baseUrl());
        if (!$res['ok']) {
            return $this->redirect($to, $res['error'], 'error');
        }
        AuditLog::add('offer.assign', 'offer', (int) $offer['id'], ['section' => $section['key'], 'user_id' => $userId], $r->ip());
        $name = $userId ? (Offers::team($this->orgId())[$userId]['name'] ?? '') : '';
        return $this->redirect($to, $userId
            ? 'Sekcję „' . $section['title'] . '” prowadzi teraz ' . $name . '.' . (!empty($res['mailed']) ? ' Wysłaliśmy powiadomienie e-mailem.' : '')
            : 'Sekcja „' . $section['title'] . '” nie ma już przydzielonej osoby.');
    }

    /** Dotacje: sprawdzenie warunków naboru na podstawie wgranych dokumentów i danych firmy. */
    public function eligibility(Request $r): Response
    {
        $offer = Offers::find($this->orgId(), (int) $r->param('id'));
        if (!$offer || $offer['kind'] !== 'grant') {
            return $this->notFound();
        }
        $to = '/oferty/' . $offer['id'] . '?sekcja=' . self::TERMS_KEY;
        if ($err = TenderController::aiGuard()) {
            return $this->redirect($to, $err, 'error');
        }
        $res = Eligibility::check($this->orgId(), $offer, Auth::id());
        if (!$res['ok']) {
            return $this->redirect($to, $res['error'], 'error');
        }
        AuditLog::add('offer.eligibility', 'offer', (int) $offer['id'], [], $r->ip());
        return $this->redirect($to, 'Sprawdziliśmy warunki naboru. Stan „Brak danych” oznacza, że trzeba to sprawdzić samodzielnie.');
    }

    /** Lista dokumentów do złożenia: dopisanie pozycji albo uzupełnienie z ogłoszenia i analizy. */
    public function checkAdd(Request $r): Response
    {
        $offer = Offers::find($this->orgId(), (int) $r->param('id'));
        if (!$offer) {
            return $this->notFound();
        }
        $to = '/oferty/' . $offer['id'] . '?sekcja=' . self::DOCS_KEY;
        if ($r->str('sync') === '1') {
            $n = Offers::syncChecklist($this->orgId(), $offer);
            return $this->redirect($to, $n ? 'Dopisano ' . $n . ' ' . plural($n, 'pozycję', 'pozycje', 'pozycji') . ' z ogłoszenia i analizy dokumentów.' : 'Lista zawiera już wszystkie pozycje z ogłoszenia i analizy dokumentów.', $n ? 'success' : 'info');
        }
        if (!Offers::addCheck($this->orgId(), (int) $offer['id'], $r->str('label'))) {
            return $this->redirect($to, 'Wpisz nazwę dokumentu (lista mieści 60 pozycji).', 'error');
        }
        return $this->redirect($to, 'Dopisano dokument do listy.');
    }

    /** Odhaczenie albo cofnięcie odhaczenia pozycji, albo jej usunięcie. */
    public function checkUpdate(Request $r): Response
    {
        $org = $this->orgId();
        $offer = Offers::find($org, (int) $r->param('id'));
        $item = $offer ? $this->db()->one('SELECT * FROM offer_checklist WHERE id = ? AND org_id = ? AND offer_id = ?', [(int) $r->param('item'), $org, (int) $offer['id']]) : null;
        if (!$item) {
            return $this->notFound();
        }
        $to = '/oferty/' . $offer['id'] . '?sekcja=' . self::DOCS_KEY;
        if ($r->str('delete') === '1') {
            $this->db()->run('DELETE FROM offer_checklist WHERE id = ? AND org_id = ?', [(int) $item['id'], $org]);
            return $this->redirect($to, 'Usunięto pozycję z listy.');
        }
        $done = !(bool) (int) $item['done'];
        $this->db()->update('offer_checklist', ['done' => $done ? 1 : 0, 'done_by' => $done ? Auth::id() : null, 'done_at' => $done ? now_utc() : null], 'id = ?', [(int) $item['id']]);
        $this->db()->update('offers', ['updated_at' => now_utc()], 'id = ?', [(int) $offer['id']]);
        return $this->redirect($to);
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
        $this->db()->update('offer_sections', ['body' => $res['body'], 'status' => 'draft', 'updated_by' => Auth::id(), 'version' => (int) ($section['version'] ?? 1) + 1, 'updated_at' => now_utc()], 'id = ?', [(int) $section['id']]);
        $this->db()->update('offers', ['updated_at' => now_utc()], 'id = ?', [(int) $offer['id']]);
        AuditLog::add('offer.ai_section', 'offer', (int) $offer['id'], ['section' => $section['key']], $r->ip());
        $n = substr_count($res['body'], '⟦sprawdź⟧');
        return $this->redirect($to, 'AI przygotowało nową wersję sekcji.' . ($n ? ' ' . $n . ' ' . plural($n, 'zdanie nie ma', 'zdania nie mają', 'zdań nie ma') . ' źródła - sprawdź je albo usuń.' : ''));
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
