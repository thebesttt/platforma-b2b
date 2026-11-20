<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\AuditLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Kv;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Modules\Ai\Claude;
use App\Modules\Ai\DocumentText;
use App\Modules\Companies\Companies;
use App\Modules\Tenders\Analysis;
use App\Modules\Tenders\Matcher;
use App\Modules\Tenders\Regions;
use App\Modules\Tenders\Results;
use App\Modules\Tenders\Tenders;

final class TenderController extends Controller
{
    public const PER_PAGE = 40;

    /** Dopasowania z profili firmy. */
    public function index(Request $r): Response
    {
        $org = $this->orgId();
        $status = in_array($r->str('status'), ['new', 'saved', 'dismissed', 'all'], true) ? $r->str('status') : 'active';
        $profileId = $r->int('profil');
        $q = $r->str('q');
        $page = max(1, $r->int('strona', 1));

        $where = ['m.org_id = ?', '(t.deadline_at IS NULL OR t.deadline_at > ?)'];
        $params = [$org, now_utc()];
        if ($status === 'active') {
            $where[] = "m.status IN ('new', 'saved')";
        } elseif ($status !== 'all') {
            $where[] = 'm.status = ?';
            $params[] = $status;
        }
        if ($profileId) {
            $where[] = 'm.profile_id = ?';
            $params[] = $profileId;
        }
        if ($q !== '') {
            $where[] = 't.search LIKE ?';
            $params[] = '%' . search_text($q) . '%';
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) $this->db()->val("SELECT COUNT(*) FROM tender_matches m JOIN tenders t ON t.id = m.tender_id WHERE $sqlWhere", $params);
        $rows = $this->db()->all(
            "SELECT m.id AS match_id, m.score, m.reasons_json, m.status, m.profile_id, t.*, c.risk_score AS buyer_risk
               FROM tender_matches m JOIN tenders t ON t.id = m.tender_id LEFT JOIN companies c ON c.nip = t.buyer_nip
              WHERE $sqlWhere
              ORDER BY m.score DESC, t.deadline_at
              LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $params
        );
        $profiles = Tenders::profiles($org);
        return $this->view('tenders/index', compact('rows', 'total', 'page', 'status', 'profileId', 'q', 'profiles') + ['title' => 'Przetargi']);
    }

    /** Przeszukiwanie wszystkich zaimportowanych ogłoszeń (także poza profilem). */
    public function search(Request $r): Response
    {
        $q = $r->str('q');
        $region = array_key_exists($r->str('woj'), Regions::ALL) ? $r->str('woj') : '';
        $type = in_array($r->str('rodzaj'), ['works', 'services', 'supplies'], true) ? $r->str('rodzaj') : '';
        $cpv = preg_replace('/\D/', '', $r->str('cpv')) ?? '';
        $page = max(1, $r->int('strona', 1));
        $where = ['(t.deadline_at IS NULL OR t.deadline_at > ?)'];
        $params = [now_utc()];
        foreach (array_filter(explode(' ', search_text($q))) as $word) {
            $where[] = 't.search LIKE ?';
            $params[] = '%' . $word . '%';
        }
        if ($region) {
            $where[] = 't.voivodeship = ?';
            $params[] = $region;
        }
        if ($type) {
            $where[] = 't.order_type = ?';
            $params[] = $type;
        }
        if (Matcher::cpvPrefix($cpv) !== '') {
            // Kody w kolumnie są rozdzielone spacjami, więc spacja przed prefiksem dopasowuje tylko początek kodu.
            $where[] = "(' ' || t.cpv_all) LIKE ?";
            $params[] = '% ' . Matcher::cpvPrefix($cpv) . '%';
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) $this->db()->val("SELECT COUNT(*) FROM tenders t WHERE $sqlWhere", $params);
        $rows = $this->db()->all(
            "SELECT t.*, c.risk_score AS buyer_risk FROM tenders t LEFT JOIN companies c ON c.nip = t.buyer_nip WHERE $sqlWhere ORDER BY t.published_at DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $params
        );
        $count = (int) $this->db()->val('SELECT COUNT(*) FROM tenders');
        return $this->view('tenders/search', compact('rows', 'total', 'page', 'q', 'region', 'type', 'cpv', 'count') + ['title' => 'Wyszukiwarka przetargów']);
    }

    /** Wyniki przetargów: kto wygrywa i za ile. Domyślnie w branży i regionie z pierwszego profilu firmy. */
    public function results(Request $r): Response
    {
        $org = $this->orgId();
        $profiles = Tenders::profiles($org, true);
        // Brak parametru = pierwszy profil. „0” = bez profilu (wszystkie branże).
        $profileId = $r->str('profil') === '' ? (int) ($profiles[0]['id'] ?? 0) : $r->int('profil');
        $profile = null;
        foreach ($profiles as $p) {
            if ((int) $p['id'] === $profileId) {
                $profile = $p;
            }
        }
        $profileId = $profile ? $profileId : 0;
        $days = array_key_exists($r->int('dni'), Results::PERIODS) ? $r->int('dni') : 90;
        $q = $r->str('q');
        $region = array_key_exists($r->str('woj'), Regions::ALL) ? $r->str('woj') : '';
        $cpv = preg_replace('/\D/', '', $r->str('cpv')) ?? '';
        $page = max(1, $r->int('strona', 1));
        $f = ['q' => $q, 'woj' => $region, 'cpv' => $cpv, 'dni' => $days, 'profile' => $profile];
        $list = Results::search($f, $page);
        // Wyniki, które mają w naszej bazie ogłoszenie o zamówieniu, prowadzą do karty przetargu.
        $cards = [];
        $ocids = array_values(array_unique(array_filter(array_column($list['rows'], 'ocid'))));
        if ($ocids) {
            foreach ($this->db()->all('SELECT id, ocid FROM tenders WHERE ocid IN (' . implode(', ', array_fill(0, count($ocids), '?')) . ')', $ocids) as $t) {
                $cards[(string) $t['ocid']] = (int) $t['id'];
            }
        }
        return $this->view('tenders/results', [
            'rows' => $list['rows'], 'total' => $list['total'], 'page' => $page, 'cards' => $cards,
            'ranking' => $page === 1 ? Results::ranking($f, 12) : [],
            'totals' => Results::totals($f), 'coverage' => Results::coverage(),
            'profiles' => $profiles, 'profileId' => $profileId, 'days' => $days, 'q' => $q, 'region' => $region, 'cpv' => $cpv,
            'title' => 'Wyniki przetargów',
        ]);
    }

    public function show(Request $r): Response
    {
        $tender = Tenders::find((int) $r->param('id'));
        if (!$tender) {
            return $this->notFound('Nie ma takiego przetargu');
        }
        $org = $this->orgId();
        $d = Tenders::details($tender);
        if ($d && !$tender['details_json']) {
            $tender = Tenders::find((int) $tender['id']) ?? $tender;
        }
        $match = $this->db()->one('SELECT * FROM tender_matches WHERE org_id = ? AND tender_id = ? ORDER BY score DESC LIMIT 1', [$org, (int) $tender['id']]);
        $rules = Analysis::rules($tender, $d);
        $ai = Analysis::latest($org, (int) $tender['id']);
        $questions = Analysis::questions($org, (int) $tender['id']);
        $documents = $this->db()->all('SELECT id, filename, size, created_at, LENGTH(text) AS chars FROM tender_documents WHERE org_id = ? AND tender_id = ? ORDER BY id', [$org, (int) $tender['id']]);
        $buyer = $tender['buyer_nip'] ? Companies::byNip((string) $tender['buyer_nip']) : null;
        $offers = $this->db()->all('SELECT id, title, status, updated_at FROM offers WHERE org_id = ? AND tender_id = ? ORDER BY id DESC', [$org, (int) $tender['id']]);
        // Wynik tego postępowania, a gdy go jeszcze nie ma: kto wcześniej wygrywał u tego zamawiającego.
        $results = $tender['source'] === 'bzp' ? Results::forTender($tender) : [];
        if ($results) {
            // Otwarcie karty to przeczytanie alertu o rozstrzygnięciu.
            $this->db()->run('UPDATE alerts SET read_at = ? WHERE org_id = ? AND tender_id = ? AND company_id IS NULL AND read_at IS NULL', [now_utc(), $org, (int) $tender['id']]);
        }
        $history = $results ? [] : Results::buyerHistory($tender['buyer_nip'] ?: null, $tender['cpv_main'] ?: null, 5, ($tender['ocid'] ?? '') ?: null);
        return $this->view('tenders/show', compact('tender', 'd', 'match', 'rules', 'ai', 'questions', 'documents', 'buyer', 'offers', 'results', 'history') + [
            'title' => truncate((string) $tender['title'], 70),
            'aiEnabled' => Claude::enabled(),
        ]);
    }

    public function status(Request $r): Response
    {
        $id = (int) $r->param('id');
        $status = $r->str('status');
        if (!in_array($status, ['new', 'saved', 'dismissed'], true)) {
            return $this->back($r, '/przetargi');
        }
        $this->db()->update('tender_matches', ['status' => $status], 'org_id = ? AND tender_id = ?', [$this->orgId(), $id]);
        $msg = ['saved' => 'Zapisano przetarg na liście obserwowanych.', 'dismissed' => 'Przetarg ukryty. Nie pokażemy go więcej na liście dopasowań.', 'new' => 'Przywrócono przetarg.'][$status];
        return $this->back($r, '/przetargi/' . $id, $msg);
    }

    public function upload(Request $r): Response
    {
        $tender = Tenders::find((int) $r->param('id'));
        if (!$tender) {
            return $this->notFound();
        }
        $org = $this->orgId();
        $files = self::normalizeFiles($r->files['documents'] ?? null);
        if (!$files) {
            return $this->back($r, '/przetargi/' . $tender['id'], 'Wybierz pliki SWZ, OPZ lub wzoru umowy (PDF, DOCX albo TXT).', 'error');
        }
        $existing = (int) $this->db()->val('SELECT COUNT(*) FROM tender_documents WHERE org_id = ? AND tender_id = ?', [$org, (int) $tender['id']]);
        $res = self::ingest($files, 12 - $existing, static function (string $name, string $mime, int $size, string $text) use ($org, $tender): void {
            \App\Core\Db::get()->insert('tender_documents', [
                'org_id' => $org,
                'tender_id' => (int) $tender['id'],
                'filename' => $name,
                'mime' => $mime,
                'size' => $size,
                'text' => $text,
                'uploaded_by' => Auth::id(),
                'created_at' => now_utc(),
            ]);
        });
        if ($res['saved']) {
            Session::flash('success', 'Dodano ' . $res['saved'] . ' ' . plural($res['saved'], 'dokument', 'dokumenty', 'dokumentów') . '. Możesz uruchomić analizę AI.');
        }
        foreach ($res['errors'] as $err) {
            Session::flash('error', $err);
        }
        return $this->redirect('/przetargi/' . $tender['id'] . '#dokumenty');
    }

    /**
     * Wspólne wgrywanie dokumentów (SWZ dla przetargów, regulamin dla naborów): walidacja, wyciąganie tekstu.
     * @return array{saved: int, errors: string[]}
     */
    public static function ingest(array $files, int $slots, callable $store): array
    {
        $maxBytes = (int) config('UPLOAD_MAX_MB', 15) * 1024 * 1024;
        $saved = 0;
        $errors = [];
        foreach ($files as $f) {
            $name = mb_substr(basename((string) $f['name']), 0, 180);
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $errors[] = $name . ': nie udało się przesłać pliku' . ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE ? ' (za duży)' : '') . '.';
                continue;
            }
            if ($saved >= $slots) {
                $errors[] = $name . ': osiągnięto limit 12 dokumentów.';
                continue;
            }
            if (PHP_SAPI !== 'cli' && !is_uploaded_file((string) $f['tmp_name'])) {
                $errors[] = $name . ': nieprawidłowy plik.';
                continue;
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf', 'docx', 'txt'], true)) {
                $errors[] = $name . ': obsługujemy pliki PDF, DOCX i TXT. Pliki .doc zapisz najpierw jako .docx.';
                continue;
            }
            if ((int) $f['size'] > $maxBytes) {
                $errors[] = $name . ': plik jest większy niż ' . config('UPLOAD_MAX_MB', 15) . ' MB.';
                continue;
            }
            $mime = (string) (function_exists('mime_content_type') ? (@mime_content_type($f['tmp_name']) ?: '') : '');
            $text = DocumentText::extract((string) $f['tmp_name'], $mime, $name);
            if (trim($text) === '') {
                $errors[] = $name . ': nie udało się odczytać tekstu (to może być skan). Wgraj wersję z warstwą tekstową.';
                continue;
            }
            $store($name, $mime, (int) $f['size'], $text);
            $saved++;
        }
        return ['saved' => $saved, 'errors' => $errors];
    }

    /** $_FILES['x'] z wieloma plikami → lista plików. */
    public static function normalizeFiles(?array $f): array
    {
        if (!$f || !isset($f['name'])) {
            return [];
        }
        if (!is_array($f['name'])) {
            return $f['error'] === UPLOAD_ERR_NO_FILE ? [] : [$f];
        }
        $out = [];
        foreach ($f['name'] as $i => $name) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = ['name' => $name, 'type' => $f['type'][$i] ?? '', 'tmp_name' => $f['tmp_name'][$i] ?? '', 'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $f['size'][$i] ?? 0];
        }
        return $out;
    }

    public function deleteDocument(Request $r): Response
    {
        $this->db()->run('DELETE FROM tender_documents WHERE org_id = ? AND tender_id = ? AND id = ?', [$this->orgId(), (int) $r->param('id'), (int) $r->param('doc')]);
        return $this->redirect('/przetargi/' . (int) $r->param('id') . '#dokumenty', 'Usunięto dokument.');
    }

    public function analyze(Request $r): Response
    {
        $tender = Tenders::find((int) $r->param('id'));
        if (!$tender) {
            return $this->notFound();
        }
        if ($err = self::aiGuard()) {
            return $this->redirect('/przetargi/' . $tender['id'], $err, 'error');
        }
        $res = Analysis::ai($this->orgId(), Auth::id(), $tender, Tenders::details($tender));
        if (!$res['ok']) {
            return $this->redirect('/przetargi/' . $tender['id'], $res['error'], 'error');
        }
        AuditLog::add('tender.ai_analysis', 'tender', (int) $tender['id'], [], $r->ip());
        return $this->redirect('/przetargi/' . $tender['id'] . '#analiza', 'Analiza gotowa. Sprawdź źródła przy każdym punkcie.');
    }

    public function ask(Request $r): Response
    {
        $tender = Tenders::find((int) $r->param('id'));
        if (!$tender) {
            return $this->notFound();
        }
        $q = mb_substr($r->str('question'), 0, 500);
        if ($q === '') {
            return $this->redirect('/przetargi/' . $tender['id'] . '#pytania', 'Wpisz pytanie.', 'error');
        }
        if ($err = self::aiGuard()) {
            return $this->redirect('/przetargi/' . $tender['id'] . '#pytania', $err, 'error');
        }
        $res = Analysis::ask($this->orgId(), Auth::id(), $tender, Tenders::details($tender), $q);
        return $res['ok']
            ? $this->redirect('/przetargi/' . $tender['id'] . '#pytania')
            : $this->redirect('/przetargi/' . $tender['id'] . '#pytania', $res['error'], 'error');
    }

    /** Wspólne warunki dla funkcji AI: klucz API i limit użycia na firmę. */
    public static function aiGuard(): ?string
    {
        if (!Claude::enabled()) {
            return 'Funkcje AI są wyłączone. Administrator musi ustawić klucz ANTHROPIC_API_KEY.';
        }
        if (Kv::hit('ai:' . Auth::orgId() . ':' . gmdate('YmdH'), 3600) > 40) {
            return 'Osiągnięto limit 40 zapytań AI na godzinę. Spróbuj za chwilę.';
        }
        return null;
    }

    /** Plik .ics z terminem składania ofert (i otwarcia), do dodania w kalendarzu. */
    public function calendar(Request $r): Response
    {
        $tender = Tenders::find((int) $r->param('id'));
        if (!$tender || !$tender['deadline_at']) {
            return $this->notFound('Brak terminu w tym przetargu');
        }
        $d = Tenders::details($tender, false);
        $esc = static fn (string $s) => str_replace(["\\", ';', ',', "\n"], ["\\\\", '\;', '\,', '\n'], $s);
        $ev = static function (string $uid, string $startUtc, string $summary, string $desc, string $url) use ($esc): string {
            $start = gmdate('Ymd\THis\Z', strtotime($startUtc . ' UTC'));
            $alarm = "BEGIN:VALARM\r\nACTION:DISPLAY\r\nDESCRIPTION:" . $esc($summary) . "\r\nTRIGGER:-P2D\r\nEND:VALARM\r\n";
            return "BEGIN:VEVENT\r\nUID:" . $uid . "\r\nDTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\nDTSTART:" . $start . "\r\nDURATION:PT30M\r\nSUMMARY:" . $esc($summary)
                . "\r\nDESCRIPTION:" . $esc($desc) . "\r\nURL:" . $url . "\r\n" . $alarm . "END:VEVENT\r\n";
        };
        $base = \App\Core\App::$request?->baseUrl() ?? '';
        $link = $base . '/przetargi/' . $tender['id'];
        $title = truncate((string) $tender['title'], 120);
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//PlatformaB2B//PL\r\nCALSCALE:GREGORIAN\r\n";
        $ics .= $ev('deadline-' . $tender['id'] . '@platforma', (string) $tender['deadline_at'], 'Termin składania ofert: ' . $title, $tender['buyer_name'] . "\n" . $link, $link);
        if (!empty($d['wadium']) && $d['wadium']) {
            $ics .= $ev('wadium-' . $tender['id'] . '@platforma', gmdate('Y-m-d H:i:s', strtotime($tender['deadline_at'] . ' UTC') - 3 * 86400), 'Wadium - ostatni dzwonek: ' . $title, 'Wadium musi wpłynąć przed terminem składania ofert. ' . $link, $link);
        }
        $ics .= "END:VCALENDAR\r\n";
        return Response::download($ics, 'przetarg-' . $tender['id'] . '.ics', 'text/calendar; charset=utf-8');
    }

    // --- Profile przetargowe ---------------------------------------------------

    public function profiles(Request $r): Response
    {
        $org = $this->orgId();
        $profiles = $this->db()->all(
            "SELECT p.*, (SELECT COUNT(*) FROM tender_matches m WHERE m.profile_id = p.id AND m.status <> 'dismissed') AS matches FROM tender_profiles p WHERE p.org_id = ? ORDER BY p.id",
            [$org]
        );
        return $this->view('tenders/profiles', ['profiles' => $profiles, 'title' => 'Profile przetargowe']);
    }

    public function profileForm(Request $r): Response
    {
        $id = (int) $r->param('id');
        $p = $id ? Tenders::profile($this->orgId(), $id) : null;
        if ($id && !$p) {
            return $this->notFound();
        }
        $p ??= ['id' => 0, 'name' => '', 'cpv' => '', 'keywords' => '', 'exclude_keywords' => '', 'voivodeships' => '', 'order_types' => '', 'min_value' => null, 'max_value' => null, 'sources' => 'bzp ted', 'active' => 1];
        return $this->view('tenders/profile_form', ['p' => $p, 'title' => $id ? 'Edycja profilu' : 'Nowy profil przetargowy']);
    }

    public function profileSave(Request $r): Response
    {
        $org = $this->orgId();
        $id = (int) $r->param('id');
        if ($id && !Tenders::profile($org, $id)) {
            return $this->notFound();
        }
        $regions = array_values(array_intersect((array) ($r->post['woj'] ?? []), array_keys(Regions::ALL)));
        $types = array_values(array_intersect((array) ($r->post['rodzaj'] ?? []), ['works', 'services', 'supplies']));
        $sources = array_values(array_intersect((array) ($r->post['zrodla'] ?? []), ['bzp', 'ted']));
        preg_match_all('/\d{2,8}/', $r->str('cpv'), $cm);
        $money = static function (string $v): ?float {
            $v = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $v);
            return is_numeric($v) ? (float) $v : null;
        };
        $data = [
            'name' => mb_substr($r->str('name'), 0, 120) ?: 'Mój profil',
            'cpv' => implode(' ', array_unique($cm[0])),
            'keywords' => mb_substr($r->str('keywords'), 0, 3000),
            'exclude_keywords' => mb_substr($r->str('exclude_keywords'), 0, 2000),
            'voivodeships' => implode(' ', $regions),
            'order_types' => implode(' ', $types),
            'min_value' => $money($r->str('min_value')),
            'max_value' => $money($r->str('max_value')),
            'sources' => implode(' ', $sources ?: ['bzp', 'ted']),
            'active' => $r->input('active') === '1' ? 1 : 0,
            'matched_until' => null,
        ];
        if ($data['cpv'] === '' && trim($data['keywords']) === '') {
            Session::flash('error', 'Podaj co najmniej jeden kod CPV albo słowo kluczowe - bez nich nie da się dobrać ogłoszeń.');
            return $this->view('tenders/profile_form', ['p' => $data + ['id' => $id], 'title' => 'Profil przetargowy']);
        }
        if ($id) {
            $this->db()->update('tender_profiles', $data, 'id = ? AND org_id = ?', [$id, $org]);
        } else {
            $data['org_id'] = $org;
            $data['created_at'] = now_utc();
            $id = $this->db()->insert('tender_profiles', $data);
        }
        // Od razu dopasowujemy ogłoszenia z ostatnich 30 dni, żeby lista nie była pusta do jutra.
        $new = $data['active'] ? Matcher::run($org, $id, gmdate('Y-m-d H:i:s', time() - 30 * 86400)) : 0;
        return $this->redirect('/przetargi?profil=' . $id, 'Profil zapisany. ' . ($new ? 'Znaleźliśmy ' . $new . ' ' . plural($new, 'pasujące ogłoszenie', 'pasujące ogłoszenia', 'pasujących ogłoszeń') . '.' : 'Nowe ogłoszenia pojawią się po najbliższym imporcie.'));
    }

    public function profileDelete(Request $r): Response
    {
        $this->db()->run('DELETE FROM tender_profiles WHERE id = ? AND org_id = ?', [(int) $r->param('id'), $this->orgId()]);
        return $this->redirect('/profile', 'Usunięto profil.');
    }
}
