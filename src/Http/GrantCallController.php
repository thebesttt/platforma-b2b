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
use App\Modules\Offers\GrantCalls;

/**
 * Nabory dotacji wpisywane ręcznie. Serwisy z naborami nie udostępniają danych programom, więc panel pokazuje, gdzie szukać,
 * a po wpisaniu naboru pilnuje terminu i zakłada szkic wniosku.
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
        ]), $status);
    }
}
