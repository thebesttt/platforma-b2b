<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\AuditLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Nip;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Ai\Claude;
use App\Modules\Companies\Companies;
use App\Modules\Outreach\Outreach;
use App\Modules\Outreach\RiskPolicy;

/**
 * Klienci i wiadomości. Zasada: platforma pokazuje ryzyko prawne każdego rodzaju wiadomości,
 * a decyzję o wysyłce podejmuje klient. Potwierdzenie ryzyka zapisujemy (kto, kiedy, z jakiego adresu IP, jaki tekst).
 */
final class OutreachController extends Controller
{
    public function index(Request $r): Response
    {
        $org = $this->orgId();
        $q = $r->str('q');
        $contacts = Outreach::contacts($org, $q);
        $messages = $this->db()->all(
            'SELECT m.id, m.subject, m.kind, m.risk, m.status, m.created_at, m.sent_at, m.ack_at, c.company_name, c.id AS contact_id
               FROM messages m JOIN contacts c ON c.id = m.contact_id WHERE m.org_id = ? ORDER BY m.id DESC LIMIT 20',
            [$org]
        );
        return $this->view('outreach/index', compact('contacts', 'messages', 'q') + ['title' => 'Klienci i wiadomości']);
    }

    public function contactForm(Request $r): Response
    {
        $id = (int) $r->param('id');
        $c = $id ? Outreach::contact($this->orgId(), $id) : null;
        if ($id && !$c) {
            return $this->notFound();
        }
        $prefill = [];
        if (!$c && $r->str('nip') !== '' && ($co = Companies::byNip($r->str('nip')))) {
            $prefill = ['company_name' => $co['name'], 'nip' => $co['nip'], 'company_id' => $co['id'], 'website' => json_in($co['krs_json'])['website'] ?? ''];
        }
        $c ??= $prefill + ['id' => 0, 'company_name' => '', 'nip' => '', 'person' => '', 'email' => '', 'phone' => '', 'website' => '', 'basis' => 'none', 'basis_detail' => '', 'basis_url' => '', 'basis_at' => null, 'notes' => '', 'unsubscribed_at' => null, 'company_id' => null];
        $messages = $id ? $this->db()->all('SELECT * FROM messages WHERE org_id = ? AND contact_id = ? ORDER BY id DESC', [$this->orgId(), $id]) : [];
        $company = !empty($c['nip']) ? Companies::byNip((string) $c['nip']) : null;
        return $this->view('outreach/contact', compact('c', 'messages', 'company') + ['title' => $id ? (string) $c['company_name'] : 'Nowy kontakt']);
    }

    public function contactSave(Request $r): Response
    {
        $org = $this->orgId();
        $id = (int) $r->param('id');
        if ($id && !Outreach::contact($org, $id)) {
            return $this->notFound();
        }
        $email = mb_strtolower($r->str('email'));
        $nip = Nip::clean($r->str('nip'));
        $basis = array_key_exists($r->str('basis'), RiskPolicy::BASES) ? $r->str('basis') : 'none';
        $basisAt = $r->str('basis_at');
        $website = $r->str('website');
        if ($website !== '' && !preg_match('#^https?://#i', $website)) {
            $website = 'https://' . $website;
        }
        $data = [
            'company_name' => mb_substr($r->str('company_name'), 0, 200),
            'nip' => Nip::valid($nip) ? $nip : null,
            'person' => mb_substr($r->str('person'), 0, 120) ?: null,
            'email' => $email,
            'phone' => mb_substr($r->str('phone'), 0, 40) ?: null,
            'website' => filter_var($website, FILTER_VALIDATE_URL) ? mb_substr($website, 0, 300) : null,
            'basis' => $basis,
            'basis_detail' => mb_substr($r->str('basis_detail'), 0, 500) ?: null,
            'basis_url' => filter_var($r->str('basis_url'), FILTER_VALIDATE_URL) ? mb_substr($r->str('basis_url'), 0, 500) : null,
            'basis_at' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $basisAt) ? $basisAt : null,
            'notes' => mb_substr($r->str('notes'), 0, 2000) ?: null,
        ];
        if ($data['nip'] && ($co = Companies::byNip($data['nip']))) {
            $data['company_id'] = (int) $co['id'];
        }
        $error = null;
        if ($data['company_name'] === '') {
            $error = 'Podaj nazwę firmy.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Podaj poprawny adres e-mail.';
        } elseif ($basis !== 'none' && !$data['basis_detail'] && !$data['basis_url']) {
            $error = 'Opisz podstawę kontaktu (np. treść zapytania, sposób wyrażenia zgody) albo podaj link - to Twój dowód w razie sporu.';
        }
        if ($error) {
            Session::flash('error', $error);
            return Response::html(View::render('outreach/contact', ['c' => $data + ['id' => $id, 'unsubscribed_at' => null], 'messages' => [], 'company' => null, 'title' => 'Kontakt']), 422);
        }
        if ($id) {
            $this->db()->update('contacts', $data, 'id = ? AND org_id = ?', [$id, $org]);
        } else {
            $id = $this->db()->insert('contacts', $data + ['org_id' => $org, 'created_at' => now_utc()]);
        }
        AuditLog::add('contact.save', 'contact', $id, ['basis' => $basis], $r->ip());
        return $this->redirect('/klienci/' . $id, 'Zapisano kontakt.');
    }

    public function unsubscribe(Request $r): Response
    {
        $c = Outreach::contact($this->orgId(), (int) $r->param('id'));
        if (!$c) {
            return $this->notFound();
        }
        $this->db()->update('contacts', ['unsubscribed_at' => $c['unsubscribed_at'] ? null : now_utc()], 'id = ?', [(int) $c['id']]);
        AuditLog::add($c['unsubscribed_at'] ? 'contact.resubscribe' : 'contact.unsubscribe', 'contact', (int) $c['id'], [], $r->ip());
        return $this->redirect('/klienci/' . $c['id'], $c['unsubscribed_at'] ? 'Usunięto oznaczenie sprzeciwu.' : 'Zapisano sprzeciw. Nie przygotujemy już dla tego kontaktu ofert ani próśb o zgodę.');
    }

    public function compose(Request $r): Response
    {
        $c = Outreach::contact($this->orgId(), (int) $r->param('id'));
        if (!$c) {
            return $this->notFound();
        }
        $kind = $this->kindFor($r, $c);
        $draft = Outreach::draft($kind, $c, Auth::org() ?? [], Auth::user());
        return $this->renderCompose($c, $kind, $draft['subject'], $draft['body']);
    }

    public function saveMessage(Request $r): Response
    {
        $c = Outreach::contact($this->orgId(), (int) $r->param('id'));
        if (!$c) {
            return $this->notFound();
        }
        $kind = $this->kindFor($r, $c);
        $subject = mb_substr($r->str('subject'), 0, 250);
        $body = mb_substr(str_replace("\r\n", "\n", (string) ($r->post['body'] ?? '')), 0, 20000);

        if ($r->str('action') === 'keep') {
            return $this->renderCompose($c, $kind, $subject, $body);
        }
        if ($r->str('action') !== 'switch' && $r->str('action') !== 'ai' && $r->str('action') !== 'save') {
            return $this->renderCompose($c, $kind, $subject, $body);
        }
        if ($r->str('action') === 'switch') {
            $draft = Outreach::draft($kind, $c, Auth::org() ?? [], Auth::user());
            return $this->renderCompose($c, $kind, $draft['subject'], $draft['body']);
        }
        if ($r->str('action') === 'ai') {
            if ($err = TenderController::aiGuard()) {
                Session::flash('error', $err);
                return $this->renderCompose($c, $kind, $subject, $body);
            }
            $res = Outreach::aiDraft($kind, $c, Auth::org() ?? [], Auth::user(), mb_substr($r->str('hint'), 0, 500));
            if (!$res['ok']) {
                Session::flash('error', $res['error']);
                return $this->renderCompose($c, $kind, $subject, $body);
            }
            Session::flash('info', 'Szkic AI gotowy. Przeczytaj go przed zapisaniem.');
            return $this->renderCompose($c, $kind, $res['subject'] ?: $subject, $res['body']);
        }

        $risk = RiskPolicy::assess($kind, $c);
        if ($risk['blocked']) {
            Session::flash('error', $risk['lead']);
            return $this->renderCompose($c, $kind, $subject, $body);
        }
        if ($subject === '' || trim($body) === '') {
            Session::flash('error', 'Uzupełnij temat i treść wiadomości.');
            return $this->renderCompose($c, $kind, $subject, $body);
        }
        if ($risk['needs_ack'] && $r->input('ack') !== '1') {
            Session::flash('error', 'Zaznacz oświadczenie o zapoznaniu się z ryzykiem albo wybierz inny rodzaj wiadomości.');
            return $this->renderCompose($c, $kind, $subject, $body);
        }
        $data = [
            'org_id' => $this->orgId(),
            'contact_id' => (int) $c['id'],
            'kind' => $kind,
            'risk' => $risk['level'],
            'subject' => $subject,
            'body' => $body,
            'status' => 'draft',
            'created_by' => Auth::id(),
            'created_at' => now_utc(),
        ];
        if ($risk['needs_ack']) {
            $data += ['ack_text' => RiskPolicy::ACK_TEXT, 'ack_by' => Auth::id(), 'ack_at' => now_utc()];
        }
        $id = $this->db()->insert('messages', $data);
        AuditLog::add('message.create', 'message', $id, [
            'kind' => $kind, 'risk' => $risk['level'], 'basis' => $c['basis'],
            'ack' => $risk['needs_ack'] ? RiskPolicy::ACK_TEXT : null,
            'forwarded_for' => (string) ($r->server['HTTP_X_FORWARDED_FOR'] ?? ''),
            'user_agent' => mb_substr((string) ($r->server['HTTP_USER_AGENT'] ?? ''), 0, 200),
        ], $r->ip());
        return $this->redirect('/wiadomosci/' . $id, 'Wiadomość gotowa do wysłania z Twojej skrzynki.');
    }

    public function message(Request $r): Response
    {
        $m = Outreach::message($this->orgId(), (int) $r->param('id'));
        if (!$m) {
            return $this->notFound();
        }
        $ackUser = $m['ack_by'] ? $this->db()->one('SELECT name, email FROM users WHERE id = ?', [(int) $m['ack_by']]) : null;
        return $this->view('outreach/message', ['m' => $m, 'ackUser' => $ackUser, 'mailto' => Outreach::mailto($m), 'title' => (string) $m['subject']]);
    }

    public function eml(Request $r): Response
    {
        $m = Outreach::message($this->orgId(), (int) $r->param('id'));
        if (!$m) {
            return $this->notFound();
        }
        $u = Auth::user();
        $eml = Outreach::eml($m, (string) $u['email'], (string) ($u['name'] ?? ''));
        return Response::download($eml, 'wiadomosc-' . $m['id'] . '.eml', 'message/rfc822');
    }

    public function markSent(Request $r): Response
    {
        $m = Outreach::message($this->orgId(), (int) $r->param('id'));
        if (!$m) {
            return $this->notFound();
        }
        $this->db()->update('messages', ['status' => 'sent', 'sent_at' => now_utc()], 'id = ? AND org_id = ?', [(int) $m['id'], $this->orgId()]);
        AuditLog::add('message.sent', 'message', (int) $m['id'], [], $r->ip());
        return $this->redirect('/wiadomosci/' . $m['id'], 'Oznaczono jako wysłaną.');
    }

    private function kindFor(Request $r, array $c): string
    {
        $kind = $r->str('kind');
        if (array_key_exists($kind, RiskPolicy::KINDS)) {
            return $kind;
        }
        return match ($c['basis']) {
            'rfq' => 'rfq_reply',
            'consent', 'client' => 'consented',
            default => 'consent_request',
        };
    }

    private function renderCompose(array $c, string $kind, string $subject, string $body): Response
    {
        $risks = [];
        foreach (array_keys(RiskPolicy::KINDS) as $k) {
            $risks[$k] = RiskPolicy::assess($k, $c);
        }
        return $this->view('outreach/compose', [
            'c' => $c, 'kind' => $kind, 'subject' => $subject, 'body' => $body, 'risks' => $risks, 'risk' => $risks[$kind],
            'aiEnabled' => Claude::enabled(), 'title' => 'Nowa wiadomość',
        ]);
    }
}
