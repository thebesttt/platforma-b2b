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
use App\Modules\Outreach\Icp;
use App\Modules\Outreach\Outreach;
use App\Modules\Outreach\ProspectEvents;
use App\Modules\Outreach\Prospects;
use App\Modules\Outreach\Rfqs;
use App\Modules\Outreach\RiskPolicy;
use App\Modules\Outreach\Sequences;

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
        $due = Sequences::dueCount($org);
        $rfqOpen = Rfqs::openCount($org);
        $prospects = Prospects::counts($org);
        $rfqSoon = Rfqs::dueSoon($org);
        $voice = \App\Modules\Outreach\ClientVoice::forContacts($org, array_column($contacts, 'id'));
        return $this->view('outreach/index', compact('contacts', 'messages', 'q', 'due', 'rfqOpen', 'rfqSoon', 'prospects', 'voice') + ['title' => 'Klienci i wiadomości']);
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
        $sequence = $id ? Sequences::activeFor($this->orgId(), $id) : null;
        $voice = $id ? (\App\Modules\Outreach\ClientVoice::forContacts($this->orgId(), [$id])[$id] ?? []) : [];
        return $this->view('outreach/contact', compact('c', 'messages', 'company', 'sequence', 'voice') + ['title' => $id ? (string) $c['company_name'] : 'Nowy kontakt']);
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
        if ($c['unsubscribed_at']) {
            $this->db()->update('contacts', ['unsubscribed_at' => null], 'id = ?', [(int) $c['id']]);
        } else {
            Outreach::unsubscribe($c);
        }
        AuditLog::add($c['unsubscribed_at'] ? 'contact.resubscribe' : 'contact.unsubscribe', 'contact', (int) $c['id'], ['via' => 'panel'], $r->ip());
        return $this->redirect('/klienci/' . $c['id'], $c['unsubscribed_at'] ? 'Usunięto oznaczenie sprzeciwu.' : 'Zapisano sprzeciw. Nie przygotujemy już dla tego kontaktu ofert ani próśb o zgodę.');
    }

    /** Sekwencja, której kolejny krok właśnie przygotowujemy (null przy zwykłej wiadomości). */
    private ?array $seq = null;

    /** Odbiorca odpisał: przypomnienia się zatrzymują. Drugie kliknięcie cofa oznaczenie. */
    public function replied(Request $r): Response
    {
        $c = Outreach::contact($this->orgId(), (int) $r->param('id'));
        if (!$c) {
            return $this->notFound();
        }
        $back = $r->str('back') === 'sekwencje' ? '/klienci/przypomnienia' : '/klienci/' . $c['id'];
        // Usunięcie jednej wklejonej odpowiedzi.
        if ($r->int('usun_odpowiedz')) {
            \App\Modules\Outreach\ClientVoice::deleteReply($this->orgId(), (int) $c['id'], $r->int('usun_odpowiedz'));
            return $this->redirect('/klienci/' . $c['id'] . '#glos', 'Usunięto wklejoną odpowiedź.');
        }
        // Wklejona treść odpowiedzi: zapisujemy ją i oznaczamy, że firma odpisała (jeśli jeszcze nie jest oznaczona).
        $pasted = (string) $r->input('tresc', '');
        if ($r->str('cofnij') !== '1' && is_string($pasted) && trim($pasted) !== '') {
            $saved = \App\Modules\Outreach\ClientVoice::addReply($this->orgId(), (int) $c['id'], (int) Auth::id(), $pasted, $r->str('data'));
            if (!$saved['ok']) {
                return $this->redirect('/klienci/' . $c['id'] . '#glos', $saved['error'], 'error');
            }
            $stopped = 0;
            if (empty($c['replied_at'])) {
                $this->db()->update('contacts', ['replied_at' => now_utc()], 'id = ?', [(int) $c['id']]);
                $stopped = Sequences::stopForContact($this->orgId(), (int) $c['id'], 'replied');
                ProspectEvents::forContact($this->orgId(), (int) $c['id'], 'reply', (int) Auth::id());
            }
            AuditLog::add('contact.reply_saved', 'contact', (int) $c['id'], [], $r->ip());
            return $this->redirect('/klienci/' . $c['id'] . '#glos', 'Zapisano odpowiedź klienta' . ($stopped ? '. Przypomnienia dla tej firmy są zatrzymane.' : '.'));
        }
        // Karta kontaktu ma dwa przyciski (zapisz i cofnij). Lista przypomnień wysyła samo przełączenie.
        if ($r->str('zapisz') === '1' && !empty($c['replied_at'])) {
            return $this->redirect('/klienci/' . $c['id'] . '#glos', 'Wklej treść odpowiedzi klienta, żeby ją dodać.', 'error');
        }
        $on = $r->str('cofnij') === '1' ? false : ($r->str('zapisz') === '1' ? true : empty($c['replied_at']));
        $this->db()->update('contacts', ['replied_at' => $on ? now_utc() : null], 'id = ?', [(int) $c['id']]);
        $stopped = $on ? Sequences::stopForContact($this->orgId(), (int) $c['id'], 'replied') : 0;
        // Firma z listy potencjalnych klientów: odpowiedź trafia do lejka listów.
        $on ? ProspectEvents::forContact($this->orgId(), (int) $c['id'], 'reply', (int) Auth::id()) : ProspectEvents::dropForContact($this->orgId(), (int) $c['id'], 'reply');
        AuditLog::add($on ? 'contact.replied' : 'contact.replied_undo', 'contact', (int) $c['id'], [], $r->ip());
        return $this->redirect($back, $on ? 'Zapisano odpowiedź' . ($stopped ? '. Przypomnienia dla tej firmy są zatrzymane.' : '.') : 'Usunięto oznaczenie odpowiedzi.');
    }

    /** Idealny klient: opis ze strony firmy (AI z cytatami) albo wpisany ręcznie. */
    public function icp(Request $r): Response
    {
        $org = Auth::org();
        return $this->view('outreach/icp', [
            'icp' => Icp::get($org), 'website' => (string) ($org['website'] ?? ''), 'aiEnabled' => Claude::enabled(), 'title' => 'Idealny klient',
        ]);
    }

    public function icpSave(Request $r): Response
    {
        if ($r->str('action') === 'ai') {
            if ($err = TenderController::aiGuard()) {
                return $this->redirect('/klienci/idealny-klient', $err, 'error');
            }
            $res = Icp::generate($this->orgId());
            if (!$res['ok']) {
                return $this->redirect('/klienci/idealny-klient', $res['error'], 'error');
            }
            AuditLog::add('icp.generate', 'organization', $this->orgId(), [], $r->ip());
            return $this->redirect('/klienci/idealny-klient', 'Opis gotowy. Przeczytaj go i popraw, jeśli trzeba.');
        }
        $text = trim($r->str('summary'));
        if ($text === '') {
            return $this->redirect('/klienci/idealny-klient', 'Wpisz opis idealnego klienta albo przygotuj go ze strony firmy.', 'error');
        }
        Icp::saveText($this->orgId(), Auth::org(), $text);
        return $this->redirect('/klienci/idealny-klient', 'Zapisano opis idealnego klienta.');
    }

    /** Przypomnienia: co wysłać dziś, co czeka na termin, co się zakończyło. */
    public function sequences(Request $r): Response
    {
        $org = $this->orgId();
        $limit = Sequences::limit(Auth::org());
        $sent = Sequences::sentToday($org);
        $due = Sequences::due($org);
        return $this->view('outreach/sequences', [
            'due' => $due, 'room' => max(0, $limit - $sent), 'limit' => $limit, 'sent' => $sent,
            'waiting' => Sequences::waiting($org), 'closed' => Sequences::closed($org), 'title' => 'Przypomnienia',
        ]);
    }

    public function stopSequence(Request $r): Response
    {
        $ok = Sequences::stop($this->orgId(), (int) $r->param('id'), 'manual');
        if ($ok) {
            AuditLog::add('sequence.stop', 'sequence', (int) $r->param('id'), [], $r->ip());
        }
        return $ok ? $this->redirect('/klienci/przypomnienia', 'Zatrzymano przypomnienia dla tej firmy.') : $this->notFound();
    }

    /** Dzienny limit wiadomości z jednej skrzynki. */
    public function saveLimit(Request $r): Response
    {
        if (!Auth::isOwner()) {
            return $this->redirect('/klienci/przypomnienia', 'Limit ustawia właściciel konta.', 'error');
        }
        $n = $r->int('limit');
        if ($n < 1 || $n > 200) {
            return $this->redirect('/klienci/przypomnienia', 'Podaj limit od 1 do 200 wiadomości dziennie.', 'error');
        }
        \App\Core\Brand::save($this->orgId(), ['outreach_limit' => $n]);
        return $this->redirect('/klienci/przypomnienia', 'Zapisano limit: ' . $n . ' ' . plural($n, 'wiadomość', 'wiadomości', 'wiadomości') . ' dziennie.');
    }

    /** Strona wypisania z linku w wiadomości. Bez logowania. Samo otwarcie niczego nie zmienia: wypisuje dopiero przycisk. */
    public function unsubForm(Request $r): Response
    {
        $headers = ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'private, no-store', 'Content-Type' => 'text/html; charset=utf-8'];
        if (\App\Core\Kv::hit('unsub:' . hash('sha256', $r->ip()) . ':' . gmdate('YmdH'), 3600) > 60) {
            return new Response(View::render('errors/message', ['title' => 'Za dużo prób', 'message' => 'Spróbuj ponownie za godzinę.', 'bare' => true, 'footNote' => ''], 'public'), 429, $headers);
        }
        $c = Outreach::byUnsubToken((string) $r->param('token'));
        if (!$c) {
            return new Response(View::render('errors/message', ['title' => 'Link nie działa', 'message' => 'Ten link wypisania jest nieprawidłowy. Możesz też odpisać nadawcy „rezygnuję”.', 'bare' => true, 'footNote' => ''], 'public'), 404, $headers);
        }
        $done = false;
        if ($r->method === 'POST') {
            if (Outreach::unsubscribe($c)) {
                // Wpis w dzienniku firmy, do której należy kontakt (odbiorca nie jest zalogowany).
                $this->db()->insert('audit_log', ['org_id' => (int) $c['org_id'], 'user_id' => null, 'action' => 'contact.unsubscribe', 'entity' => 'contact',
                    'entity_id' => (string) $c['id'], 'data_json' => json_out(['via' => 'link']), 'ip' => $r->ip() ?: null, 'created_at' => now_utc()]);
            }
            $done = true;
        }
        return new Response(View::render('outreach/unsubscribe', [
            'c' => $c, 'done' => $done || !empty($c['unsubscribed_at']), 'token' => (string) $r->param('token'),
            'title' => 'Rezygnacja z wiadomości', 'bare' => true, 'footNote' => '',
        ], 'public'), 200, $headers);
    }

    public function compose(Request $r): Response
    {
        $c = Outreach::contact($this->orgId(), (int) $r->param('id'));
        if (!$c) {
            return $this->notFound();
        }
        $unsub = Outreach::unsubUrl($c, $r->baseUrl());
        $this->seq = $this->sequenceFor($r->int('sekwencja'), $c);
        if ($this->seq) {
            $draft = Sequences::followUp($this->seq, $c, Auth::org() ?? [], Auth::user(), $unsub);
            return $this->renderCompose($c, (string) $this->seq['kind'], $draft['subject'], $draft['body']);
        }
        $kind = $this->kindFor($r, $c);
        // Firma z listy potencjalnych klientów ma już treść oferty na swojej karcie: ta sama trafia do wiadomości.
        $prospect = in_array($kind, ['cold_offer', 'consented'], true) ? Prospects::forContact($this->orgId(), (int) $c['id']) : null;
        $draft = $prospect
            ? Prospects::emailDraft($prospect, Prospects::company($prospect), Auth::org() ?? [], Auth::user(), $c, $unsub, $r->baseUrl())
            : Outreach::draft($kind, $c, Auth::org() ?? [], Auth::user(), $unsub);
        return $this->renderCompose($c, $kind, $draft['subject'], $draft['body']);
    }

    /** Aktywna sekwencja tego kontaktu o podanym numerze albo null. */
    private function sequenceFor(int $id, array $contact): ?array
    {
        $seq = $id ? Sequences::find($this->orgId(), $id) : null;
        return $seq && (int) $seq['contact_id'] === (int) $contact['id'] && $seq['status'] === 'active' ? $seq : null;
    }

    public function saveMessage(Request $r): Response
    {
        $c = Outreach::contact($this->orgId(), (int) $r->param('id'));
        if (!$c) {
            return $this->notFound();
        }
        $this->seq = $this->sequenceFor($r->int('sequence_id'), $c);
        $unsub = Outreach::unsubUrl($c, $r->baseUrl());
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
            $draft = Outreach::draft($kind, $c, Auth::org() ?? [], Auth::user(), $unsub);
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
            // Ofertę zawsze kończy możliwość wypisania, także gdy model jej nie dopisał.
            if (in_array($kind, ['consented', 'cold_offer'], true) && $unsub !== '' && !str_contains($res['body'], $unsub)) {
                $res['body'] = rtrim($res['body']) . "\n\nRezygnacja z wiadomości: " . $unsub;
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
        if ($this->seq) {
            $data += ['sequence_id' => (int) $this->seq['id'], 'step' => (int) $this->seq['step'] + 1];
        }
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
        $limit = Sequences::limit(Auth::org());
        return $this->view('outreach/message', [
            'm' => $m, 'ackUser' => $ackUser, 'mailto' => Outreach::mailto($m), 'title' => (string) $m['subject'],
            'canSequence' => Sequences::canStart($m), 'sequence' => $m['sequence_id'] ? Sequences::find($this->orgId(), (int) $m['sequence_id']) : null,
            'limit' => $limit, 'sentToday' => Sequences::sentToday($this->orgId()),
        ]);
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
        if ($m['status'] === 'sent') {
            return $this->redirect('/wiadomosci/' . $m['id']);
        }
        $this->db()->update('messages', ['status' => 'sent', 'sent_at' => now_utc()], 'id = ? AND org_id = ?', [(int) $m['id'], $this->orgId()]);
        AuditLog::add('message.sent', 'message', (int) $m['id'], [], $r->ip());
        $note = '';
        Prospects::markContactedByContact($this->orgId(), (int) $m['contact_id']);
        if ($m['kind'] === 'rfq_reply' && Rfqs::markReplied($this->orgId(), (int) $m['contact_id'])) {
            $note = ' Zapytanie w rejestrze ma stan „oferta złożona”.';
        }
        if ($m['sequence_id']) {
            Sequences::advance($m);
            $seq = Sequences::find($this->orgId(), (int) $m['sequence_id']);
            $note .= $seq && $seq['next_at'] ? ' Kolejny krok przypomnimy ' . local_dt($seq['next_at'], 'd.m.Y') . '.' : ' To był ostatni krok przypomnień.';
        } elseif ($r->input('sequence') === '1' && ($sid = Sequences::start($m, Auth::id()))) {
            $note .= ' Przypomnimy o kolejnej wiadomości ' . local_dt((string) Sequences::find($this->orgId(), $sid)['next_at'], 'd.m.Y') . '.';
        }
        return $this->redirect('/wiadomosci/' . $m['id'], 'Oznaczono jako wysłaną.' . $note);
    }

    /**
     * Rodzaj wiadomości dopasowany do podstawy kontaktu, gdy użytkownik sam go nie wybrał: odpowiedź na zapytanie,
     * oferta za zgodą albo oferta bez zgody. Prośba o zgodę nie jest domyślna, bo sama wymaga zgody.
     */
    private function kindFor(Request $r, array $c): string
    {
        $kind = $r->str('kind');
        if (array_key_exists($kind, RiskPolicy::KINDS)) {
            return $kind;
        }
        return match (true) {
            $c['basis'] === 'rfq' => 'rfq_reply',
            in_array($c['basis'], ['consent', 'client', 'invite'], true), !empty($c['marketing_consent_at']) => 'consented',
            default => 'cold_offer',
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
            'aiEnabled' => Claude::enabled(), 'title' => 'Nowa wiadomość', 'seq' => $this->seq,
        ]);
    }
}
