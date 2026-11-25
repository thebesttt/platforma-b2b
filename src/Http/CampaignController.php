<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\AuditLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Mailer;
use App\Core\Plans;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Outreach\Campaigns;
use App\Modules\Outreach\Outreach;

/** Kampanie e-mail: szablon, odbiorcy z oceną ryzyka, wersja dla każdego odbiorcy, wysyłka partiami. */
final class CampaignController extends Controller
{
    public function index(Request $r): Response
    {
        return $this->view('outreach/campaigns', ['rows' => Campaigns::listing($this->orgId()), 'title' => 'Kampanie e-mail']);
    }

    public function create(Request $r): Response
    {
        $id = Campaigns::create($this->orgId(), (int) Auth::id(), $r->str('name'), $r->str('subject'), (string) $r->input('body', ''));
        AuditLog::add('campaign.create', 'campaign', $id, [], $r->ip());
        return $this->redirect('/klienci/kampanie/' . $id, 'Kampania zapisana. Teraz wybierz odbiorców.');
    }

    public function show(Request $r): Response
    {
        $c = $this->campaign($r);
        if (!$c) {
            return $this->notFound();
        }
        $org = Auth::org() ?? [];
        $rows = Campaigns::recipients($this->orgId(), (int) $c['id']);
        return $this->view('outreach/campaign', [
            'c' => $c, 'rows' => $rows, 'audience' => Campaigns::audience($this->orgId(), (int) $c['id']), 'stats' => Campaigns::stats($this->orgId(), (int) $c['id']),
            'room' => Campaigns::roomToday($org), 'limit' => Plans::limit($org, 'campaign_day'), 'mailOn' => Mailer::enabled(),
            'aiLeft' => Campaigns::aiLeft((int) $c['id']), 'aiRoom' => Plans::limit($org, 'ai_ops'), 'base' => $r->baseUrl(),
            'sending' => $r->str('wysylka') === '1', 'aiRun' => $r->str('ai') === '1', 'title' => (string) $c['name'],
        ]);
    }

    public function save(Request $r): Response
    {
        $c = $this->campaign($r);
        if (!$c) {
            return $this->notFound();
        }
        Campaigns::saveTemplate($this->orgId(), $c, $r->str('name'), $r->str('subject'), (string) $r->input('body', ''), Auth::org() ?? [], Auth::user());
        return $this->redirect('/klienci/kampanie/' . (int) $c['id'] . '#tresc', 'Zapisano treść. Wersje odbiorców złożone z faktów są zaktualizowane, poprawione ręcznie zostały bez zmian.');
    }

    public function recipients(Request $r): Response
    {
        $c = $this->campaign($r);
        if (!$c) {
            return $this->notFound();
        }
        $res = Campaigns::setRecipients($this->orgId(), $c, (array) $r->input('contacts', []), Auth::org() ?? [], Auth::user());
        $parts = ['Odbiorcy zapisani: dodano ' . $res['added'] . ', usunięto ' . $res['removed'] . '.'];
        if ($res['blocked']) {
            $parts[] = 'Pominięto ' . $res['blocked'] . ' ' . plural($res['blocked'], 'wypisaną firmę', 'wypisane firmy', 'wypisanych firm') . '.';
        }
        if ($res['paused']) {
            $parts[] = 'Pominięto ' . $res['paused'] . ' ' . plural($res['paused'], 'firmę', 'firmy', 'firm') . ', które dostały wiadomość z innej kampanii w ostatnich ' . Campaigns::PAUSE_DAYS . ' dniach.';
        }
        return $this->redirect('/klienci/kampanie/' . (int) $c['id'] . '#wersje', implode(' ', $parts));
    }

    /** Wersje przez AI partiami: strona sama wysyła kolejny krok, dopóki są odbiorcy z wersją z reguł. */
    public function ai(Request $r): Response
    {
        $c = $this->campaign($r);
        if (!$c) {
            return $this->notFound();
        }
        $res = Campaigns::aiBatch($this->orgId(), $c, Auth::org() ?? [], Auth::user(), 3);
        if (isset($res['error'])) {
            return $this->redirect('/klienci/kampanie/' . (int) $c['id'] . '#wersje', 'AI przygotowało ' . $res['done'] . ' ' . plural($res['done'], 'wersję', 'wersje', 'wersji') . ' i przerwało: ' . $res['error'], 'error');
        }
        if ($res['left'] > 0 && $res['done'] > 0) {
            return $this->redirect('/klienci/kampanie/' . (int) $c['id'] . '?ai=1#wersje');
        }
        return $this->redirect('/klienci/kampanie/' . (int) $c['id'] . '#wersje', 'AI dopasowało wersje do odbiorców. Przejrzyj je przed wysyłką.');
    }

    public function version(Request $r): Response
    {
        $c = $this->campaign($r);
        $rec = $c ? Campaigns::recipient($this->orgId(), (int) $r->param('rid')) : null;
        if (!$c || !$rec || (int) $rec['campaign_id'] !== (int) $c['id']) {
            return $this->notFound();
        }
        $action = $r->str('action');
        if ($action === 'exclude' || $action === 'include') {
            Campaigns::setExcluded($this->orgId(), (int) $rec['id'], $action === 'exclude');
            return $this->redirect('/klienci/kampanie/' . (int) $c['id'] . '#w' . (int) $rec['id'], $action === 'exclude' ? 'Pominięto odbiorcę w tej kampanii.' : 'Przywrócono odbiorcę.');
        }
        if ($action === 'rules') {
            $contact = Outreach::contact($this->orgId(), (int) $rec['contact_id']);
            if ($contact) {
                $v = Campaigns::build($c, $contact, Auth::org() ?? [], Auth::user());
                $this->db()->update('campaign_recipients', ['subject' => $v['subject'], 'body' => $v['body'], 'facts_json' => json_out($v['facts']), 'source' => 'rules', 'updated_at' => now_utc()],
                    "id = ? AND status = 'pending'", [(int) $rec['id']]);
            }
            return $this->redirect('/klienci/kampanie/' . (int) $c['id'] . '#w' . (int) $rec['id'], 'Przywrócono wersję z szablonu.');
        }
        $ok = Campaigns::updateVersion($this->orgId(), (int) $rec['id'], $r->str('subject'), (string) $r->input('body', ''));
        return $this->redirect('/klienci/kampanie/' . (int) $c['id'] . '#w' . (int) $rec['id'], $ok ? 'Zapisano wersję dla ' . $rec['company_name'] . '.' : 'Temat i treść nie mogą być puste.', $ok ? 'success' : 'error');
    }

    public function acknowledge(Request $r): Response
    {
        $c = $this->campaign($r);
        if (!$c) {
            return $this->notFound();
        }
        if ($r->str('ack') !== '1') {
            return $this->redirect('/klienci/kampanie/' . (int) $c['id'] . '#wysylka', 'Zaznacz oświadczenie o ryzyku, jeśli chcesz wysłać te wiadomości ze swojej skrzynki.', 'error');
        }
        $n = Campaigns::acknowledge($this->orgId(), (int) $c['id'], (int) Auth::id());
        AuditLog::add('campaign.ack', 'campaign', (int) $c['id'], ['count' => $n], $r->ip());
        return $this->redirect('/klienci/kampanie/' . (int) $c['id'] . '#wysylka', 'Zapisaliśmy potwierdzenie ryzyka dla ' . $n . ' ' . plural($n, 'wiadomości', 'wiadomości', 'wiadomości') . '. Możesz je pobrać.');
    }

    /** Wysyłka przez panel partiami: strona sama wysyła kolejny krok, aż skończy albo dojdzie do dziennego limitu. */
    public function send(Request $r): Response
    {
        $c = $this->campaign($r);
        if (!$c) {
            return $this->notFound();
        }
        $res = Campaigns::sendBatch($this->orgId(), $c, Auth::org() ?? [], Auth::user(), $r->baseUrl());
        if ($res['sent'] || $res['failed']) {
            AuditLog::add('campaign.send', 'campaign', (int) $c['id'], ['sent' => $res['sent'], 'failed' => $res['failed']], $r->ip());
        }
        if (isset($res['error'])) {
            return $this->redirect('/klienci/kampanie/' . (int) $c['id'] . '#wysylka', $res['error'], 'error');
        }
        if ($res['left'] > 0 && !$res['limit'] && ($res['sent'] + $res['failed']) > 0) {
            return $this->redirect('/klienci/kampanie/' . (int) $c['id'] . '?wysylka=1#wysylka');
        }
        $msg = 'Wysłano ' . $res['sent'] . ' ' . plural($res['sent'], 'wiadomość', 'wiadomości', 'wiadomości') . '.'
            . ($res['failed'] ? ' Błędy: ' . $res['failed'] . ' (szczegóły przy odbiorcach).' : '')
            . ($res['limit'] && $res['left'] ? ' Dzienny limit wysyłki z pakietu jest wyczerpany. Pozostałe ' . $res['left'] . ' wyślesz jutro jednym kliknięciem.' : '');
        return $this->redirect('/klienci/kampanie/' . (int) $c['id'] . '#wysylka', $msg, $res['failed'] ? 'error' : 'success');
    }

    public function download(Request $r): Response
    {
        $c = $this->campaign($r);
        if (!$c) {
            return $this->notFound();
        }
        $zip = Campaigns::downloadOwn($this->orgId(), $c, Auth::org() ?? [], Auth::user(), $r->baseUrl());
        if (!$zip) {
            return $this->redirect('/klienci/kampanie/' . (int) $c['id'] . '#wysylka', 'Nie ma wiadomości do pobrania. Najpierw potwierdź ryzyko dla wiadomości wysyłanych ze swojej skrzynki.', 'error');
        }
        AuditLog::add('campaign.download', 'campaign', (int) $c['id'], ['count' => $zip['count']], $r->ip());
        $body = (string) file_get_contents($zip['path']);
        @unlink($zip['path']);
        return Response::download($body, 'kampania-' . (int) $c['id'] . '.zip', 'application/zip');
    }

    public function delete(Request $r): Response
    {
        $c = $this->campaign($r);
        if (!$c) {
            return $this->notFound();
        }
        $sent = (int) $this->db()->val("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND status IN ('sent', 'downloaded')", [(int) $c['id']]);
        if ($sent > 0) {
            return $this->redirect('/klienci/kampanie/' . (int) $c['id'], 'Kampanii z wysłanymi wiadomościami nie usuwamy: zostaje w historii.', 'error');
        }
        $this->db()->run('DELETE FROM campaigns WHERE id = ? AND org_id = ?', [(int) $c['id'], $this->orgId()]);
        return $this->redirect('/klienci/kampanie', 'Usunięto kampanię.');
    }

    private function campaign(Request $r): ?array
    {
        return Campaigns::find($this->orgId(), (int) $r->param('id'));
    }
}
