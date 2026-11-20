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
use App\Modules\Outreach\Rfqs;

/**
 * Rejestr zapytań ofertowych znalezionych na rynku. Panel nie przeszukuje tych źródeł sam (nie udostępniają danych programom):
 * pokazuje, gdzie szukać, a po wpisaniu zapytania pilnuje terminu i przygotowuje odpowiedź.
 */
final class RfqController extends Controller
{
    public function index(Request $r): Response
    {
        $edit = $r->int('edytuj') ? Rfqs::find($this->orgId(), $r->int('edytuj')) : null;
        if ($r->int('edytuj') && !$edit) {
            return $this->notFound();
        }
        return $this->page($edit ?? [], $edit ? (int) $edit['id'] : 0);
    }

    public function save(Request $r): Response
    {
        $id = (int) $r->param('id');
        $res = Rfqs::save($this->orgId(), (int) Auth::id(), $r->post, $id);
        if (!$res['ok']) {
            if ($id && !Rfqs::find($this->orgId(), $id)) {
                return $this->notFound();
            }
            Session::flash('error', $res['error']);
            return $this->page(Rfqs::clean($r->post), $id, 422);
        }
        AuditLog::add($id ? 'rfq.update' : 'rfq.create', 'rfq', (int) $res['id'], ['contact' => $res['contact']], $r->ip());
        $note = match ($res['contact']) {
            'created' => ' Dodaliśmy kontakt z podstawą „zapytanie ofertowe”, więc odpowiedź możesz przygotować od razu.',
            'linked' => ' Zapytanie jest powiązane z istniejącym kontaktem.',
            default => ' Nie ma adresu e-mail, więc ofertę złożysz na stronie zapytania. Panel przypomni o terminie.',
        };
        return $this->redirect('/klienci/zapytania#z' . (int) $res['id'], ($id ? 'Zapisano zmiany.' : 'Zapisano zapytanie.') . $note);
    }

    /** Zmiana stanu (oferta złożona, pominięte, z powrotem do odpowiedzi) albo usunięcie. */
    public function state(Request $r): Response
    {
        $id = (int) $r->param('id');
        $org = $this->orgId();
        if (!Rfqs::find($org, $id)) {
            return $this->notFound();
        }
        if ($r->str('delete') === '1') {
            Rfqs::delete($org, $id);
            AuditLog::add('rfq.delete', 'rfq', $id, [], $r->ip());
            return $this->redirect('/klienci/zapytania', 'Usunięto zapytanie. Kontakt, jeśli był, zostaje w Klientach.');
        }
        $status = $r->str('status');
        if (!Rfqs::setStatus($org, $id, $status)) {
            return $this->redirect('/klienci/zapytania', 'Nieznany stan zapytania.', 'error');
        }
        AuditLog::add('rfq.status', 'rfq', $id, ['status' => $status], $r->ip());
        return $this->redirect('/klienci/zapytania', match ($status) {
            'replied' => 'Oznaczono: oferta złożona.',
            'skipped' => 'Zapytanie pominięte. Znajdziesz je w zamkniętych.',
            default => 'Zapytanie wróciło na listę do odpowiedzi.',
        });
    }

    private function page(array $form, int $editId, int $status = 200): Response
    {
        $org = $this->orgId();
        return Response::html(View::render('outreach/rfqs', [
            'open' => Rfqs::open($org), 'closed' => Rfqs::closed($org), 'form' => $form, 'editId' => $editId,
            'keywords' => Rfqs::keywords($org), 'title' => 'Zapytania ofertowe z rynku',
        ]), $status);
    }
}
