<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Tenders\PlanMatcher;
use App\Modules\Tenders\Plans;
use App\Modules\Tenders\Regions;
use App\Modules\Tenders\Tenders;

/** Ekran „Plany zamówień”: zapowiedziane zamówienia dopasowane do profili firmy i wyszukiwarka we wszystkich planach. */
final class PlanController extends Controller
{
    public function index(Request $r): Response
    {
        $org = $this->orgId();
        $profiles = Tenders::profiles($org);
        $mode = $r->str('widok') === 'wszystkie' || !$profiles ? 'all' : 'matches';
        $when = $r->str('kiedy') === 'caly-rok' ? 'all' : 'upcoming';
        $q = mb_substr($r->str('q'), 0, 120);
        $page = max(1, $r->int('strona', 1));
        if ($mode === 'matches') {
            // Po zapisaniu profilu dopasowanie mogło nie zdążyć z całym rokiem: dokańczamy w granicach kilku sekund.
            if (PlanMatcher::pending($org)) {
                PlanMatcher::run($org, null, 4.0);
            }
            $status = in_array($r->str('status'), ['new', 'saved', 'dismissed'], true) ? $r->str('status') : 'active';
            $profileId = $r->int('profil');
            $list = Plans::matches($org, ['profile' => $profileId, 'status' => $status, 'q' => $q, 'when' => $when], $page);
            $filters = ['status' => $status, 'profileId' => $profileId, 'region' => '', 'cat' => ''];
        } else {
            $region = array_key_exists($r->str('woj'), Regions::ALL) ? $r->str('woj') : '';
            $cat = isset(Plans::CATEGORIES[$r->str('rodzaj')]) ? $r->str('rodzaj') : '';
            $list = Plans::search(['q' => $q, 'woj' => $region, 'cat' => $cat, 'when' => $when], $page);
            $filters = ['status' => 'active', 'profileId' => 0, 'region' => $region, 'cat' => $cat];
        }
        return $this->view('tenders/plans', $filters + [
            'rows' => $list['rows'], 'total' => $list['total'], 'page' => $page, 'mode' => $mode, 'when' => $when, 'q' => $q,
            'profiles' => $profiles, 'coverage' => Plans::coverage(), 'title' => 'Plany zamówień',
        ]);
    }

    /** Zapisanie, ukrycie albo przywrócenie pozycji planu (dla wszystkich profili firmy naraz). */
    public function status(Request $r): Response
    {
        $item = (int) $r->param('id');
        $status = $r->str('status');
        if (in_array($status, ['new', 'saved', 'dismissed'], true)) {
            $this->db()->update('plan_matches', ['status' => $status], 'org_id = ? AND item_id = ?', [$this->orgId(), $item]);
        }
        $msg = [
            'saved' => 'Zapisano pozycję planu. Znajdziesz ją w zakładce „Zapisane”.',
            'dismissed' => 'Ukryto pozycję planu.',
            'new' => 'Przywrócono pozycję planu.',
        ][$status] ?? null;
        return $this->back($r, '/przetargi/plany', $msg);
    }
}
