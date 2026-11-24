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
use App\Modules\Ai\Claude;
use App\Modules\Offers\GrantCalls;
use App\Modules\Offers\GrantFinder;

/**
 * Nabory dotacji: wyszukiwarka (AI szuka w internecie naborów dla opisanej firmy i podaje źródła) oraz lista obserwowanych.
 * Oficjalne listy naborów są zabezpieczone przed automatami, więc panel ich nie pobiera. Nabór można też wpisać ręcznie.
 * Dla obserwowanych panel pilnuje terminu i zakłada szkic wniosku.
 */
final class GrantCallController extends Controller
{
    public function index(Request $r): Response
    {
        $edit = $r->int('edytuj') ? GrantCalls::find($this->orgId(), $r->int('edytuj')) : null;
        if ($r->int('edytuj') && !$edit) {
            return $this->notFound();
        }
        return $this->page($edit ?? [], $edit ? (int) $edit['id'] : 0);
    }

    public function save(Request $r): Response
    {
        $id = (int) $r->param('id');
        if ($id && !GrantCalls::find($this->orgId(), $id)) {
            return $this->notFound();
        }
        $res = GrantCalls::save($this->orgId(), (int) Auth::id(), $r->post, $id);
        if (!$res['ok']) {
            Session::flash('error', $res['error']);
            return $this->page(GrantCalls::clean($r->post), $id, 422);
        }
        AuditLog::add($id ? 'grant_call.update' : 'grant_call.create', 'grant_call', (int) $res['id'], [], $r->ip());
        return $this->redirect('/oferty/nabory#n' . (int) $res['id'], $id ? 'Zapisano zmiany.' : 'Zapisano nabór. Panel przypomni o końcu naboru 14, 7 i 3 dni wcześniej.');
    }

    /** Wyszukiwanie naborów dla firmy. Jedno wyszukiwanie to jedno użycie AI z pakietu. */
    public function search(Request $r): Response
    {
        if ($err = TenderController::aiGuard()) {
            return $this->redirect('/oferty/nabory#szukaj', $err, 'error');
        }
        $res = GrantFinder::search($this->orgId(), (int) Auth::id(), $r->post);
        AuditLog::add('grant_search', null, null, ['ok' => $res['ok'], 'found' => $res['found'] ?? 0], $r->ip());
        if (!$res['ok']) {
            return $this->redirect('/oferty/nabory#szukaj', $res['error'], 'error');
        }
        $n = (int) $res['found'];
        return $this->redirect('/oferty/nabory#znalezione', $n
            ? 'Wyszukiwarka znalazła ' . $n . ' ' . plural($n, 'nabór', 'nabory', 'naborów') . '. Sprawdź termin i warunki na stronie instytucji, zanim zaczniesz pisać.'
            : ((int) $res['known'] ? 'Bez nowych naborów: wyszukiwarka trafiła tylko na te, które już są na liście.' : 'Wyszukiwarka nie znalazła naborów pasujących do opisu. Spróbuj opisać potrzebę szerzej albo innymi słowami.'));
    }

    /** Znaleziony nabór: obserwuj, zacznij wniosek albo odrzuć. */
    public function found(Request $r): Response
    {
        $org = $this->orgId();
        $find = GrantFinder::find($org, (int) $r->param('id'));
        if (!$find) {
            return $this->notFound();
        }
        $action = $r->str('akcja');
        if ($action === 'odrzuc') {
            GrantFinder::dismiss($org, (int) $find['id']);
            return $this->redirect('/oferty/nabory#znalezione', 'Nabór zdjęty z listy znalezionych.');
        }
        if (!in_array($action, ['obserwuj', 'wniosek'], true)) {
            return $this->redirect('/oferty/nabory#znalezione');
        }
        $res = GrantFinder::toCall($org, (int) Auth::id(), $find);
        if (!$res['ok']) {
            return $this->redirect('/oferty/nabory#znalezione', $res['error'], 'error');
        }
        AuditLog::add('grant_call.create', 'grant_call', (int) $res['id'], ['from_find' => (int) $find['id']], $r->ip());
        if ($action === 'obserwuj') {
            return $this->redirect('/oferty/nabory#n' . (int) $res['id'], 'Nabór jest na liście obserwowanych. Panel przypomni o końcu naboru 14, 7 i 3 dni wcześniej.');
        }
        $call = GrantCalls::find($org, (int) $res['id']);
        $offer = GrantCalls::startOffer($org, (int) Auth::id(), $call);
        if ($offer['created']) {
            AuditLog::add('offer.create', 'offer', $offer['offer_id'], ['kind' => 'grant', 'grant_call_id' => (int) $res['id']], $r->ip());
        }
        return $this->redirect('/oferty/' . $offer['offer_id'], $offer['created']
            ? 'Utworzono szkic wniosku. Wgraj regulamin i kryteria oceny ze strony naboru, a potem kliknij „Napisz cały wniosek”.'
            : null);
    }

    /** Zmiana stanu, usunięcie albo start wniosku z naboru. */
    public function state(Request $r): Response
    {
        $id = (int) $r->param('id');
        $org = $this->orgId();
        $call = GrantCalls::find($org, $id);
        if (!$call) {
            return $this->notFound();
        }
        if ($r->str('start') === '1') {
            $res = GrantCalls::startOffer($org, (int) Auth::id(), $call);
            if ($res['created']) {
                AuditLog::add('offer.create', 'offer', $res['offer_id'], ['kind' => 'grant', 'grant_call_id' => $id], $r->ip());
            }
            return $this->redirect('/oferty/' . $res['offer_id'], $res['created']
                ? 'Utworzono szkic wniosku z naboru. Wgraj regulamin i kryteria oceny, a panel sprawdzi warunki udziału i pomoże napisać sekcje.'
                : null);
        }
        if ($r->str('delete') === '1') {
            GrantCalls::delete($org, $id);
            AuditLog::add('grant_call.delete', 'grant_call', $id, [], $r->ip());
            return $this->redirect('/oferty/nabory', 'Usunięto nabór z listy.' . ($call['offer_id'] ? ' Szkic wniosku zostaje w Ofertach.' : ''));
        }
        $status = $r->str('status');
        if (!GrantCalls::setStatus($org, $id, $status)) {
            return $this->redirect('/oferty/nabory', 'Nieznany stan naboru.', 'error');
        }
        AuditLog::add('grant_call.status', 'grant_call', $id, ['status' => $status], $r->ip());
        return $this->redirect('/oferty/nabory', $status === 'skip' ? 'Nabór przeniesiony do odrzuconych. Nie będziemy o nim przypominać.' : 'Nabór wrócił na listę obserwowanych.');
    }

    private function page(array $form, int $editId, int $status = 200): Response
    {
        $org = $this->orgId();
        return Response::html(View::render('offers/calls', [
            'current' => GrantCalls::current($org), 'past' => GrantCalls::past($org), 'form' => $form, 'editId' => $editId, 'title' => 'Nabory dotacji',
            'finds' => GrantFinder::fresh($org), 'query' => GrantFinder::lastQuery($org), 'lastSearch' => GrantFinder::lastSearch($org), 'aiEnabled' => Claude::enabled(),
            'plan' => ['name' => \App\Core\Plans::name(Auth::org()), 'limit' => \App\Core\Plans::limit(Auth::org(), 'ai_ops'), 'used' => \App\Core\Plans::usage($org)['ai_ops'],
                'auto' => in_array(\App\Core\Plans::of(Auth::org()), GrantFinder::AUTO_PLANS, true)],
        ]), $status);
    }
}
