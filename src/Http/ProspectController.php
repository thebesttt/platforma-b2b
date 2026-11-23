<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\AuditLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Offers\Docx;
use App\Modules\Outreach\Outreach;
use App\Modules\Outreach\Prospects;
use App\Modules\Tenders\Regions;
use App\Modules\Tenders\Results;

/**
 * Lista potencjalnych klientów. Panel proponuje firmy, podaje powód kontaktu i przygotowuje ofertę.
 * O wysyłce decyduje użytkownik: karta firmy pokazuje drogi kontaktu z oceną ryzyka prawnego.
 */
final class ProspectController extends Controller
{
    public function index(Request $r): Response
    {
        $org = $this->orgId();
        $status = isset(Prospects::STATUSES[$r->str('stan')]) && $r->str('stan') !== 'objected' ? $r->str('stan') : 'new';
        $filters = ['q' => mb_substr($r->str('q'), 0, 120), 'cpv' => mb_substr($r->str('cpv'), 0, 40), 'woj' => isset(Regions::ALL[$r->str('woj')]) ? $r->str('woj') : '',
            'dni' => in_array($r->int('dni'), [30, 90, 180, 365], true) ? $r->int('dni') : 180];
        $searched = $r->str('szukaj') === '1';
        return $this->view('outreach/prospects', [
            'rows' => Prospects::listing($org, $status), 'counts' => Prospects::counts($org), 'status' => $status,
            'criteria' => Prospects::criteria(Auth::org()), 'pending' => Prospects::pendingCount($org),
            'filters' => $filters, 'searched' => $searched, 'candidates' => $searched ? Prospects::candidates($org, $filters) : [],
            'coverage' => Results::coverage(), 'title' => 'Potencjalni klienci',
        ]);
    }

    public function saveCriteria(Request $r): Response
    {
        Prospects::saveCriteria($this->orgId(), ['keywords' => $r->str('keywords'), 'regions' => (array) $r->input('regions', []), 'min_years' => $r->int('min_years', 2)]);
        AuditLog::add('prospect.criteria', 'organization', $this->orgId(), [], $r->ip());
        return $this->redirect('/klienci/potencjalni', 'Zapisano. Punkty dopasowania na liście są przeliczone.');
    }

    public function addFromResults(Request $r): Response
    {
        $n = Prospects::addFromResults($this->orgId(), (int) Auth::id(), array_map('strval', (array) $r->input('nips', [])));
        if ($n === 0) {
            return $this->redirect('/klienci/potencjalni', 'Zaznacz firmy, które chcesz dodać do listy.', 'error');
        }
        AuditLog::add('prospect.add', 'prospect', 0, ['source' => 'results', 'count' => $n], $r->ip());
        return $this->redirect('/klienci/potencjalni', 'Dodano ' . $n . ' ' . plural($n, 'firmę', 'firmy', 'firm') . '. Panel dopisuje dane z rejestrów.');
    }

    public function addList(Request $r): Response
    {
        $res = Prospects::addList($this->orgId(), (int) Auth::id(), (string) $r->input('lines', ''));
        $parts = [];
        if ($res['added']) {
            $parts[] = 'Dodano ' . $res['added'] . ' ' . plural($res['added'], 'firmę', 'firmy', 'firm') . '.';
        }
        if ($res['duplicates']) {
            $parts[] = $res['duplicates'] . ' ' . plural($res['duplicates'], 'firma była', 'firmy były', 'firm było') . ' już na liście.';
        }
        if ($res['rejected']) {
            $parts[] = 'Nie rozpoznaliśmy NIP-u ani adresu strony w ' . count($res['rejected']) . ' ' . plural(count($res['rejected']), 'wierszu', 'wierszach', 'wierszach') . ': ' . implode('; ', array_slice($res['rejected'], 0, 5)) . '.';
        }
        if ($res['over']) {
            $parts[] = 'Jedno wklejenie to najwyżej ' . Prospects::PASTE_LIMIT . ' wierszy. Pozostałe ' . $res['over'] . ' wklej osobno.';
        }
        if (!$parts) {
            $parts[] = 'Wklej listę: w każdym wierszu NIP albo adres strony firmy.';
        }
        if ($res['added']) {
            AuditLog::add('prospect.add', 'prospect', 0, ['source' => 'list', 'count' => $res['added']], $r->ip());
        }
        return $this->redirect('/klienci/potencjalni', implode(' ', $parts), $res['added'] ? 'ok' : 'error');
    }

    /** Krok kolejki: dane z rejestrów dla kolejnych firm. Wywoływany przez przeglądarkę, a w tle przez zadanie dzienne. */
    public function step(Request $r): Response
    {
        return Response::json(Prospects::enrichPending($this->orgId(), 10));
    }

    public function show(Request $r): Response
    {
        $p = Prospects::find($this->orgId(), (int) $r->param('id'));
        if (!$p) {
            return $this->notFound();
        }
        return $this->card($p);
    }

    /** Adres strony, NIP i notatka. */
    public function save(Request $r): Response
    {
        $org = $this->orgId();
        $p = Prospects::find($org, (int) $r->param('id'));
        if (!$p) {
            return $this->notFound();
        }
        $error = Prospects::saveIdentity($org, $p, $r->str('website'), $r->str('nip'));
        if ($error !== null) {
            return $this->redirect('/klienci/potencjalni/' . $p['id'], $error, 'error');
        }
        $this->db()->update('prospects', ['notes' => mb_substr(trim((string) $r->input('notes', '')), 0, 2000) ?: null], 'id = ? AND org_id = ?', [(int) $p['id'], $org]);
        $fresh = Prospects::find($org, (int) $p['id']);
        if ($fresh && $fresh['enriched_at'] === null && $fresh['nip']) {
            Prospects::enrichPending($org, 10);
        }
        return $this->redirect('/klienci/potencjalni/' . $p['id'], 'Zapisano.');
    }

    public function state(Request $r): Response
    {
        $org = $this->orgId();
        $p = Prospects::find($org, (int) $r->param('id'));
        if (!$p) {
            return $this->notFound();
        }
        $back = $r->str('back') === 'list' ? '/klienci/potencjalni' . ($r->str('stan') !== '' ? '?stan=' . rawurlencode($r->str('stan')) : '') : '/klienci/potencjalni/' . $p['id'];
        if ($r->str('delete') === '1') {
            Prospects::delete($org, (int) $p['id']);
            AuditLog::add('prospect.delete', 'prospect', (int) $p['id'], [], $r->ip());
            return $this->redirect('/klienci/potencjalni', 'Usunięto firmę z listy.');
        }
        if ($r->str('status') === 'objected') {
            Prospects::object($org, $p);
            AuditLog::add('prospect.objection', 'prospect', (int) $p['id'], [], $r->ip());
            return $this->redirect($back, 'Zapisano sprzeciw. Panel nie przygotuje już dla tej firmy listu ani oferty.');
        }
        if (!in_array($r->str('status'), ['new', 'picked', 'skipped', 'contacted'], true) || !Prospects::setStatus($org, (int) $p['id'], $r->str('status'))) {
            return $this->redirect($back, 'Nieznany stan.', 'error');
        }
        return $this->redirect($back, match ($r->str('status')) {
            'picked' => 'Firma jest na liście wybranych.',
            'skipped' => 'Firma pominięta. Nie wróci w wyszukiwaniu.',
            'contacted' => 'Oznaczono: po kontakcie.',
            default => 'Firma wróciła do oceny.',
        });
    }

    public function checkSite(Request $r): Response
    {
        $org = $this->orgId();
        $p = Prospects::find($org, (int) $r->param('id'));
        if (!$p) {
            return $this->notFound();
        }
        if ($r->str('website') !== '' && ($error = Prospects::saveIdentity($org, $p, $r->str('website'), '')) !== null) {
            return $this->redirect('/klienci/potencjalni/' . $p['id'], $error, 'error');
        }
        $res = Prospects::checkSite(Prospects::find($org, (int) $p['id']) ?? $p);
        if (!$res['ok']) {
            return $this->redirect('/klienci/potencjalni/' . $p['id'] . '#strona', (string) $res['error'], 'error');
        }
        return $this->redirect('/klienci/potencjalni/' . $p['id'] . '#strona', 'Strona sprawdzona.' . (!empty($res['invite']) ? ' Jest na niej zdanie, które wygląda na zaproszenie do ofert: przeczytaj je poniżej.' : ''));
    }

    /** Potwierdzenie znalezionego zaproszenia, wpisanie własnego albo jego usunięcie. */
    public function invite(Request $r): Response
    {
        $org = $this->orgId();
        $p = Prospects::find($org, (int) $r->param('id'));
        if (!$p) {
            return $this->notFound();
        }
        $url = '/klienci/potencjalni/' . $p['id'] . '#kontakt';
        if ($r->str('drop') === '1') {
            Prospects::dropInvite($p);
            return $this->redirect($url, 'Usunięto zaproszenie z karty firmy.');
        }
        if ($r->str('confirm') !== '1') {
            return $this->redirect($url, 'Zaznacz, że przeczytałeś zaproszenie i że dotyczy ofert takich jak Twoja.', 'error');
        }
        $manual = $r->str('manual') === '1' ? ['quote' => $r->str('quote'), 'email' => $r->str('email'), 'url' => $r->str('url')] : null;
        $error = Prospects::confirmInvite($p, (int) Auth::id(), $manual);
        if ($error !== null) {
            return $this->redirect($url, $error, 'error');
        }
        AuditLog::add('prospect.invite', 'prospect', (int) $p['id'], ['manual' => $manual !== null], $r->ip());
        return $this->redirect($url, 'Zapisano zaproszenie jako podstawę kontaktu. Cytat i link zostają jako dowód.');
    }

    /** Zakłada kontakt i otwiera ekran wiadomości z oceną ryzyka. */
    public function contact(Request $r): Response
    {
        $org = $this->orgId();
        $p = Prospects::find($org, (int) $r->param('id'));
        if (!$p) {
            return $this->notFound();
        }
        if ($p['status'] === 'objected') {
            return $this->redirect('/klienci/potencjalni/' . $p['id'], 'Firma zgłosiła sprzeciw. Nie przygotujemy dla niej oferty.', 'error');
        }
        $res = Prospects::toContact($org, $p, Prospects::company($p), $r->str('email'), $r->str('person'));
        if (!$res['ok']) {
            return $this->redirect('/klienci/potencjalni/' . $p['id'] . '#kontakt', (string) $res['error'], 'error');
        }
        AuditLog::add('prospect.contact', 'prospect', (int) $p['id'], ['contact' => $res['id'], 'kind' => $res['kind']], $r->ip());
        return $this->redirect('/klienci/' . $res['id'] . '/wiadomosc?kind=' . $res['kind']);
    }

    /** List z ofertą do druku: podgląd z edycją, pobranie .docx, oznaczenie wysyłki. */
    public function letter(Request $r): Response
    {
        $org = $this->orgId();
        $p = Prospects::find($org, (int) $r->param('id'));
        if (!$p) {
            return $this->notFound();
        }
        if ($p['status'] === 'objected') {
            return $this->redirect('/klienci/potencjalni/' . $p['id'], 'Firma zgłosiła sprzeciw. Nie przygotujemy dla niej listu.', 'error');
        }
        $company = Prospects::company($p);
        $letter = Prospects::letter($p, $company, Auth::org() ?? [], Auth::user());
        if ($r->method === 'POST') {
            $body = mb_substr(str_replace("\r\n", "\n", (string) $r->input('body', '')), 0, 12000);
            if (trim($body) === '') {
                return $this->redirect('/klienci/potencjalni/' . $p['id'] . '/list', 'List jest pusty.', 'error');
            }
            if ($r->str('action') === 'sent') {
                Prospects::markContacted($org, (int) $p['id'], 'letter');
                AuditLog::add('prospect.letter_sent', 'prospect', (int) $p['id'], [], $r->ip());
                return $this->redirect('/klienci/potencjalni/' . $p['id'], 'Oznaczono: list wysłany.');
            }
            AuditLog::add('prospect.letter', 'prospect', (int) $p['id'], [], $r->ip());
            $bin = Docx::build($letter['title'], [['title' => '', 'body' => $body]], false);
            return Response::download($bin, 'list-' . (preg_replace('/[^a-z0-9]+/', '-', search_text((string) $p['name'])) ?: 'firma') . '.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        }
        return $this->view('outreach/prospect_letter', ['p' => $p, 'company' => $company, 'letter' => $letter, 'person' => Prospects::isPerson($company), 'title' => 'List do ' . $p['name']]);
    }

    private function card(array $p): Response
    {
        $org = $this->orgId();
        $company = Prospects::company($p);
        $contact = !empty($p['contact_id']) ? Outreach::contact($org, (int) $p['contact_id']) : null;
        return $this->view('outreach/prospect', [
            'p' => $p, 'company' => $company, 'contact' => $contact, 'facts' => Prospects::facts($p), 'score' => json_in($p['score_json'] ?? null),
            'reasons' => Prospects::reasons($p, $company), 'paths' => Prospects::paths($org, $p, $company, $contact),
            'infoDays' => Prospects::infoDaysLeft($p, $company), 'criteria' => Prospects::criteria(Auth::org()), 'title' => (string) $p['name'],
        ]);
    }
}
