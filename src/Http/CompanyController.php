<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\AuditLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Kv;
use App\Core\Nip;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Companies\Companies;
use App\Modules\Companies\Receivables;
use App\Modules\Offers\Docx;

final class CompanyController extends Controller
{
    /** Limit nowych sprawdzeń na firmę dziennie – API białej listy VAT ma wspólny dzienny limit zapytań dla całej platformy. */
    public const DAILY_LOOKUPS = 40;

    public function index(Request $r): Response
    {
        $org = $this->orgId();
        $watch = $this->db()->all(
            'SELECT c.*, w.note, w.created_at AS watched_at,
                    (SELECT COUNT(*) FROM receivables r WHERE r.company_id = c.id AND r.org_id = w.org_id AND r.paid_at IS NULL) AS open_invoices
               FROM watchlist w JOIN companies c ON c.id = w.company_id WHERE w.org_id = ? ORDER BY c.risk_score DESC, c.name',
            [$org]
        );
        $alerts = $this->db()->all(
            'SELECT a.*, c.nip FROM alerts a LEFT JOIN companies c ON c.id = a.company_id WHERE a.org_id = ? ORDER BY a.id DESC LIMIT 30',
            [$org]
        );
        $receivables = Receivables::summary($org);
        $q = $r->str('q');
        $found = [];
        if ($q !== '' && !Nip::valid($q)) {
            $found = $this->db()->all('SELECT nip, name, risk_score, address FROM companies WHERE search LIKE ? ORDER BY name LIMIT 20', ['%' . search_text($q) . '%']);
        }
        return $this->view('companies/index', compact('watch', 'alerts', 'receivables', 'q', 'found') + ['title' => 'Kontrahenci']);
    }

    /** Formularz wyszukiwania: NIP → karta firmy; nazwa → wyniki z bazy. */
    public function check(Request $r): Response
    {
        $q = $r->str('nip') ?: $r->str('q');
        $nip = Nip::clean($q);
        if (Nip::valid($nip)) {
            return $this->redirect('/kontrahenci/' . $nip);
        }
        if (preg_match('/^\d{10}$/', $nip)) {
            return $this->redirect('/kontrahenci', 'Numer NIP ' . $nip . ' ma nieprawidłową sumę kontrolną – sprawdź cyfry.', 'error');
        }
        return $this->redirect('/kontrahenci?q=' . rawurlencode($q));
    }

    public function show(Request $r): Response
    {
        $nip = Nip::clean($r->param('nip'));
        if (!Nip::valid($nip)) {
            return $this->redirect('/kontrahenci', 'Numer NIP jest nieprawidłowy – sprawdź cyfry.', 'error');
        }
        $existing = Companies::byNip($nip);
        $fresh = $existing && $existing['refreshed_at'] && strtotime($existing['refreshed_at'] . ' UTC') > time() - Companies::FRESH_FOR;
        if (!$fresh && Kv::hit('lookup:' . $this->orgId() . ':' . gmdate('Ymd'), 86400) > self::DAILY_LOOKUPS) {
            if (!$existing) {
                return $this->redirect('/kontrahenci', 'Osiągnięto dzienny limit ' . self::DAILY_LOOKUPS . ' sprawdzeń nowych firm. Spróbuj jutro.', 'error');
            }
        } else {
            $res = Companies::lookup($nip);
            if (!$res['ok']) {
                return $this->redirect('/kontrahenci', $res['error'], 'error');
            }
            if (!empty($res['error'])) {
                \App\Core\Session::flash('info', $res['error']);
            }
            $existing = $res['company'];
        }
        $c = $existing;
        $signals = json_in($c['signals_json']);
        $wl = json_in($c['whitelist_json']);
        $krs = json_in($c['krs_json']);
        $org = $this->orgId();
        $watched = Companies::isWatched($org, (int) $c['id']);
        $receivables = Receivables::forCompany($org, (int) $c['id']);
        $alerts = $this->db()->all('SELECT * FROM alerts WHERE org_id = ? AND company_id = ? ORDER BY id DESC LIMIT 10', [$org, (int) $c['id']]);
        $tenders = $this->db()->all('SELECT id, title, deadline_at, value_estimated FROM tenders WHERE buyer_nip = ? ORDER BY published_at DESC LIMIT 5', [$nip]);
        return $this->view('companies/show', compact('c', 'signals', 'wl', 'krs', 'watched', 'receivables', 'alerts', 'tenders') + ['title' => (string) ($c['name'] ?: 'NIP ' . $nip)]);
    }

    public function refresh(Request $r): Response
    {
        $nip = Nip::clean($r->param('nip'));
        $c = Companies::byNip($nip);
        if ($c && $c['refreshed_at'] && strtotime($c['refreshed_at'] . ' UTC') > time() - 3600) {
            return $this->redirect('/kontrahenci/' . $nip, 'Dane pobraliśmy mniej niż godzinę temu. Rejestry aktualizują się raz na dobę.', 'info');
        }
        $res = Companies::lookup($nip, true);
        if (!$res['ok']) {
            return $this->redirect('/kontrahenci/' . $nip, $res['error'], 'error');
        }
        $n = count($res['changes'] ?? []);
        return $this->redirect('/kontrahenci/' . $nip, $n ? 'Dane odświeżone. Zmiany: ' . implode('; ', $res['changes']) : 'Dane odświeżone – bez zmian.');
    }

    public function watch(Request $r): Response
    {
        $c = Companies::byNip($r->param('nip'));
        if (!$c) {
            return $this->notFound();
        }
        $org = $this->orgId();
        if (Companies::isWatched($org, (int) $c['id'])) {
            $this->db()->run('DELETE FROM watchlist WHERE org_id = ? AND company_id = ?', [$org, (int) $c['id']]);
            return $this->redirect('/kontrahenci/' . $c['nip'], 'Nie obserwujesz już tej firmy.');
        }
        $this->db()->insert('watchlist', ['org_id' => $org, 'company_id' => (int) $c['id'], 'note' => mb_substr($r->str('note'), 0, 300) ?: null, 'created_at' => now_utc()]);
        return $this->redirect('/kontrahenci/' . $c['nip'], 'Obserwujesz firmę. Codziennie sprawdzimy zmiany w rejestrach i powiadomimy Cię o nich.');
    }

    public function readAlerts(Request $r): Response
    {
        $this->db()->run('UPDATE alerts SET read_at = ? WHERE org_id = ? AND read_at IS NULL', [now_utc(), $this->orgId()]);
        return $this->back($r, '/kontrahenci', 'Oznaczono alerty jako przeczytane.');
    }

    public function addReceivable(Request $r): Response
    {
        $c = Companies::byNip($r->param('nip'));
        if (!$c) {
            return $this->notFound();
        }
        $amount = (float) str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $r->str('amount'));
        $due = $this->date($r->str('due_at'));
        $invoice = mb_substr($r->str('invoice_no'), 0, 60);
        if ($amount <= 0 || !$due || $invoice === '') {
            return $this->redirect('/kontrahenci/' . $c['nip'] . '#naleznosci', 'Podaj numer faktury, kwotę i termin płatności.', 'error');
        }
        $this->db()->insert('receivables', [
            'org_id' => $this->orgId(),
            'company_id' => (int) $c['id'],
            'invoice_no' => $invoice,
            'amount' => round($amount, 2),
            'currency' => 'PLN',
            'issued_at' => $this->date($r->str('issued_at')),
            'due_at' => $due,
            'notes' => mb_substr($r->str('notes'), 0, 500) ?: null,
            'created_at' => now_utc(),
        ]);
        return $this->redirect('/kontrahenci/' . $c['nip'] . '#naleznosci', 'Dodano należność. Te dane widzi tylko Twoja firma.');
    }

    public function updateReceivable(Request $r): Response
    {
        $rec = Receivables::find($this->orgId(), (int) $r->param('id'));
        if (!$rec) {
            return $this->notFound();
        }
        $c = Companies::find((int) $rec['company_id']);
        $action = $r->str('action');
        $upd = [];
        $msg = 'Zapisano.';
        if ($action === 'paid') {
            $upd['paid_at'] = $this->date($r->str('paid_at')) ?: date('Y-m-d');
            $msg = 'Oznaczono jako zapłaconą.';
        } elseif ($action === 'unpaid') {
            $upd['paid_at'] = null;
        } elseif ($action === 'demand') {
            $bureau = mb_substr($r->str('demand_bureau'), 0, 300);
            if ($bureau === '') {
                return $this->redirect('/kontrahenci/' . $c['nip'] . '#naleznosci', 'Podaj nazwę i adres biura informacji gospodarczej, które wskazujesz w wezwaniu.', 'error');
            }
            $upd['demand_bureau'] = $bureau;
            $upd['demand_sent_at'] = $this->date($r->str('demand_sent_at')) ?: date('Y-m-d');
            $msg = 'Zapisano datę wysłania wezwania. Wpis do BIG będzie możliwy najwcześniej po miesiącu.';
        } elseif ($action === 'delete') {
            $this->db()->run('DELETE FROM receivables WHERE id = ? AND org_id = ?', [(int) $rec['id'], $this->orgId()]);
            return $this->redirect('/kontrahenci/' . $c['nip'] . '#naleznosci', 'Usunięto należność.');
        }
        if ($upd) {
            $this->db()->update('receivables', $upd, 'id = ? AND org_id = ?', [(int) $rec['id'], $this->orgId()]);
            AuditLog::add('receivable.' . $action, 'receivable', (int) $rec['id'], $upd, $r->ip());
        }
        return $this->redirect('/kontrahenci/' . $c['nip'] . '#naleznosci', $msg);
    }

    /** Wezwanie do zapłaty: podgląd albo plik .docx do podpisania. */
    public function demand(Request $r): Response
    {
        $rec = Receivables::find($this->orgId(), (int) $r->param('id'));
        if (!$rec) {
            return $this->notFound();
        }
        $c = Companies::find((int) $rec['company_id']);
        $draft = $rec;
        if ($r->str('bureau') !== '') {
            $draft['demand_bureau'] = mb_substr($r->str('bureau'), 0, 300);
        }
        $letter = Receivables::demandLetter($draft, $c, Auth::org() ?? [], $r->str('miejsce'));
        if ($r->str('format') === 'docx') {
            $bin = Docx::build($letter['title'], [['title' => '', 'body' => $letter['body']]], false);
            return Response::download($bin, 'wezwanie-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string) $rec['invoice_no']) . '.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        }
        return $this->view('companies/demand', ['rec' => $draft, 'c' => $c, 'letter' => $letter, 'title' => $letter['title']]);
    }

    private function date(string $v): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $v;
        }
        return null;
    }
}
