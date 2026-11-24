<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\AuditLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Modules\Ai\Claude;
use App\Modules\Audit\Audit;
use App\Modules\Companies\Companies;
use App\Modules\Inbound\Inbound;
use App\Modules\Inbound\Rules;
use App\Modules\Outreach\Outreach;
use App\Modules\Outreach\RiskPolicy;

/**
 * Zapytania ze strony w panelu: lista, karta zapytania z rozpisaną oceną, ustawienia widżetu, wyniki i baza wiedzy czatu.
 * Zasady oceny i ustawienia zmienia właściciel konta. Pozostali widzą zapytania i zmieniają ich status.
 */
final class InboundController extends Controller
{
    public function index(Request $r): Response
    {
        $org = $this->orgId();
        $status = $r->str('status');
        $level = $r->str('poziom');
        $leads = Inbound::leads($org, $status, $level);
        $counts = ['all' => 0] + array_fill_keys(array_keys(Inbound::STATUSES), 0);
        foreach ($this->db()->all('SELECT status, COUNT(*) AS n FROM inbound_leads WHERE org_id = ? GROUP BY status', [$org]) as $row) {
            $counts[$row['status']] = (int) $row['n'];
            $counts['all'] += (int) $row['n'];
        }
        $widget = Inbound::widget($org);
        return $this->view('inbound/index', compact('leads', 'counts', 'status', 'level', 'widget') + [
            'installed' => Inbound::origins($widget) !== [], 'title' => 'Zapytania ze strony',
        ]);
    }

    public function show(Request $r): Response
    {
        $org = $this->orgId();
        $lead = Inbound::find($org, (int) $r->param('id'));
        if (!$lead) {
            return $this->notFound();
        }
        if (empty($lead['enriched_at']) && (!empty($lead['nip']) || !empty($lead['site_url']))) {
            try {
                $lead = Inbound::enrich($lead, Auth::id());
            } catch (\Throwable $e) {
                error_log('[inbound] uzupełnianie zapytania ' . $lead['id'] . ': ' . $e->getMessage());
            }
        }
        $contact = !empty($lead['contact_id']) ? Outreach::contact($org, (int) $lead['contact_id']) : null;
        $audit = !empty($lead['site_audit_id']) ? Audit::find($org, (int) $lead['site_audit_id']) : null;
        $widget = Inbound::widget($org);
        return $this->view('inbound/show', [
            'lead' => $lead,
            'rows' => json_in($lead['points_json']),
            'contact' => $contact,
            'risk' => $contact ? RiskPolicy::assess('rfq_reply', $contact) : null,
            'company' => !empty($lead['company_id']) ? Companies::find((int) $lead['company_id']) : null,
            'audit' => $audit,
            'chat' => !empty($lead['conversation_id'])
                ? $this->db()->all('SELECT role, text, created_at FROM inbound_messages WHERE org_id = ? AND conversation_id = ? ORDER BY id', [$org, (int) $lead['conversation_id']])
                : [],
            'rulesVersion' => (int) $widget['rules_version'],
            'levels' => Inbound::rules($widget)['levels'],
            'title' => 'Zapytanie: ' . ($lead['name'] ?: $lead['email']),
        ]);
    }

    public function status(Request $r): Response
    {
        $lead = Inbound::find($this->orgId(), (int) $r->param('id'));
        $status = $r->str('status');
        if (!$lead) {
            return $this->notFound();
        }
        if (!isset(Inbound::STATUSES[$status])) {
            return $this->redirect('/zapytania/' . $lead['id'], 'Nie ma takiego statusu.', 'error');
        }
        $this->db()->update('inbound_leads', ['status' => $status], 'id = ? AND org_id = ?', [(int) $lead['id'], $this->orgId()]);
        Nav::reset();
        return $this->redirect('/zapytania/' . $lead['id'], 'Status: ' . mb_strtolower(Inbound::STATUSES[$status]) . '.');
    }

    public function delete(Request $r): Response
    {
        $lead = Inbound::find($this->orgId(), (int) $r->param('id'));
        if (!$lead) {
            return $this->notFound();
        }
        $res = Inbound::delete($this->orgId(), (int) $lead['id']);
        AuditLog::add('inbound.lead_delete', 'inbound_lead', (int) $lead['id'], ['contact_removed' => $res['contact_removed']], $r->ip());
        Nav::reset();
        return $this->redirect('/zapytania', $res['contact_removed']
            ? 'Usunięto zapytanie, rozmowę i kontakt.'
            : 'Usunięto zapytanie i rozmowę.' . (!empty($lead['contact_id']) ? ' Kontakt został w zakładce „Klienci i wiadomości”, bo ma własną historię.' : ''));
    }

    public function settings(Request $r): Response
    {
        return $this->settingsView(Inbound::widget($this->orgId()), [], []);
    }

    public function saveSettings(Request $r): Response
    {
        $org = $this->orgId();
        $w = Inbound::widget($org);
        if (!Auth::isOwner()) {
            return $this->redirect('/zapytania/ustawienia', 'Ustawienia widżetu zmienia właściciel konta.', 'error');
        }
        $parsed = Rules::parse((string) $r->input('rules', ''), $r->int('phone_points', -1), $r->int('hot', -1), $r->int('talk', -1));
        $errors = $parsed['errors'];

        $origins = [];
        foreach (preg_split('/[\s,;]+/u', $r->str('origins')) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $h = Inbound::host($line);
            if ($h === '') {
                $errors[] = 'Adres „' . mb_substr($line, 0, 80) . '” nie wygląda na adres strony. Wpisz samą domenę, np. twojafirma.pl.';
            } else {
                $origins[] = $h;
            }
        }
        $origins = array_values(array_unique($origins));
        if (count($origins) > 10) {
            $errors[] = 'Najwyżej 10 stron dla jednego widżetu.';
        }
        $privacy = $r->str('privacy_url');
        if ($privacy !== '' && !preg_match('#^https://[^\s<>"\']{4,300}$#i', $privacy)) {
            $errors[] = 'Adres polityki prywatności musi zaczynać się od https://.';
        }
        $limits = [
            'messages' => $r->int('limit_messages', Inbound::LIMITS['messages']),
            'daily' => $r->int('limit_daily', Inbound::LIMITS['daily']),
            'ai_month' => $r->int('limit_ai_month', Inbound::LIMITS['ai_month']),
        ];
        // Górną granicę odpowiedzi czatu wyznacza pakiet firmy: wyższą wartość obniżamy do tej granicy, bez błędu w formularzu.
        $chatCap = Inbound::planCap($org);
        if ($chatCap !== null) {
            $limits['ai_month'] = min($limits['ai_month'], $chatCap);
        }
        foreach (['messages' => [1, 50, 'Pytań do czatu w jednej rozmowie'], 'daily' => [10, 100000, 'Rozmów dziennie'], 'ai_month' => [0, 1000000, 'Odpowiedzi czatu w miesiącu']] as $k => [$min, $max, $label]) {
            if ($limits[$k] < $min || $limits[$k] > $max) {
                $errors[] = $label . ': od ' . $min . ' do ' . $max . '.';
            }
        }
        $defaults = Inbound::texts();
        $texts = [];
        foreach (['company' => 120, 'button' => 40, 'greeting' => 300, 'url_prompt' => 200, 'thanks' => 300] as $k => $max) {
            $v = mb_substr($r->str($k), 0, $max);
            $texts[$k] = $v !== '' ? $v : $defaults[$k];
        }
        $texts['privacy_url'] = $privacy;

        $form = [
            'origins' => (string) $r->input('origins', ''),
            'rules_text' => (string) $r->input('rules', ''),
            'phone_points' => $r->int('phone_points'), 'hot' => $r->int('hot'), 'talk' => $r->int('talk'),
            'texts' => $texts, 'limits' => $limits,
            'active' => $r->str('active') === '1', 'chat' => $r->str('chat') === '1', 'ab_test' => $r->str('ab_test') === '1',
        ];
        if ($errors) {
            return new Response($this->settingsView($w, $form, $errors)->body, 422);
        }
        $rulesChanged = json_out($parsed['rules']) !== json_out(Inbound::rules($w));
        $this->db()->update('inbound_widgets', [
            'origins' => implode("\n", $origins),
            'rules_json' => json_out($parsed['rules']),
            'rules_version' => (int) $w['rules_version'] + ($rulesChanged ? 1 : 0),
            'texts_json' => json_out($texts),
            'limits_json' => json_out($limits),
            'active' => $form['active'] ? 1 : 0,
            'chat' => $form['chat'] ? 1 : 0,
            'ab_test' => $form['ab_test'] ? 1 : 0,
            'updated_at' => now_utc(),
        ], 'id = ? AND org_id = ?', [(int) $w['id'], $org]);
        AuditLog::add('inbound.settings', 'inbound_widget', (int) $w['id'], ['rules_changed' => $rulesChanged, 'origins' => $origins], $r->ip());
        return $this->redirect('/zapytania/ustawienia', $rulesChanged
            ? 'Zapisano. Nowe zasady oceny (wersja ' . ((int) $w['rules_version'] + 1) . ') działają dla kolejnych zapytań. Starsze zachowują swoją ocenę.'
            : 'Zapisano ustawienia widżetu.');
    }

    private function settingsView(array $w, array $form, array $errors): Response
    {
        $rules = Inbound::rules($w);
        $form += [
            'origins' => (string) $w['origins'], 'rules_text' => Rules::format($rules), 'phone_points' => (int) $rules['phone_points'],
            'hot' => (int) $rules['levels']['hot'], 'talk' => (int) $rules['levels']['talk'],
            'texts' => Inbound::texts($w), 'limits' => Inbound::limits($w),
            'active' => (int) $w['active'] === 1, 'chat' => (int) $w['chat'] === 1, 'ab_test' => (int) $w['ab_test'] === 1,
        ];
        $base = \App\Core\App::$request?->baseUrl() ?? '';
        return $this->view('inbound/settings', [
            'w' => $w, 'f' => $form, 'errors' => $errors, 'canEdit' => Auth::isOwner(), 'aiEnabled' => Claude::enabled(),
            'scriptUrl' => $base . '/w/' . $w['public_id'] . '.js', 'title' => 'Ustawienia widżetu',
            'chatCap' => Inbound::planCap((int) $w['org_id']), 'planName' => \App\Core\Plans::name(Auth::org()),
        ]);
    }

    public function preview(Request $r): Response
    {
        $w = Inbound::widget($this->orgId());
        return $this->view('inbound/preview', ['w' => $w, 'inline' => $r->str('tryb') === 'w-tresci', 'title' => 'Podgląd widżetu']);
    }

    public function results(Request $r): Response
    {
        $org = $this->orgId();
        $w = Inbound::widget($org);
        $days = in_array($r->int('dni', 30), [7, 30, 90], true) ? $r->int('dni', 30) : 30;
        $stats = Inbound::stats($w, $days);
        $since = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $levels = array_fill_keys(array_keys(Rules::LEVELS), 0);
        foreach ($this->db()->all('SELECT level, COUNT(*) AS n FROM inbound_leads WHERE org_id = ? AND created_at >= ? GROUP BY level', [$org, $since]) as $row) {
            if (isset($levels[$row['level']])) {
                $levels[$row['level']] = (int) $row['n'];
            }
        }
        $won = (int) $this->db()->val("SELECT COUNT(*) FROM inbound_leads WHERE org_id = ? AND created_at >= ? AND status = 'won'", [$org, $since]);
        return $this->view('inbound/results', [
            'w' => $w, 'days' => $days, 'stats' => $stats, 'levels' => $levels, 'won' => $won,
            'z' => Rules::ztest($stats['a']['views'], $stats['a']['leads'], $stats['b']['views'], $stats['b']['leads']),
            'todayViews' => Inbound::todayViews($w), 'limits' => Inbound::limits($w), 'aiUsed' => Inbound::aiUsed($w),
            'title' => 'Wyniki widżetu',
        ]);
    }

    public function knowledge(Request $r): Response
    {
        $org = $this->orgId();
        $w = Inbound::widget($org);
        return $this->view('inbound/knowledge', [
            'w' => $w,
            'pages' => $this->db()->all('SELECT id, url, title, chars, fetched_at FROM inbound_knowledge WHERE org_id = ? ORDER BY id', [$org]),
            'texts' => $this->db()->all("SELECT id, title, LENGTH(body) AS chars FROM library_items WHERE org_id = ? AND kind = 'text' ORDER BY id", [$org]),
            'total' => mb_strlen(Inbound::knowledge($org, 10_000_000)), 'cap' => 60000,
            'aiEnabled' => Claude::enabled(), 'chatOn' => Inbound::chatOn($w), 'aiUsed' => Inbound::aiUsed($w), 'limits' => Inbound::limits($w),
            'title' => 'Wiedza czatu',
        ]);
    }

    public function knowledgeAdd(Request $r): Response
    {
        $res = Inbound::fetchPage($this->orgId(), $r->str('url'));
        return $res['ok']
            ? $this->redirect('/zapytania/wiedza', 'Dodano stronę do bazy wiedzy: ' . number_format((int) $res['chars'], 0, ',', ' ') . ' znaków tekstu.')
            : $this->redirect('/zapytania/wiedza', (string) $res['error'], 'error');
    }

    public function knowledgeDelete(Request $r): Response
    {
        $this->db()->run('DELETE FROM inbound_knowledge WHERE org_id = ? AND id = ?', [$this->orgId(), (int) $r->param('id')]);
        return $this->redirect('/zapytania/wiedza', 'Usunięto stronę z bazy wiedzy.');
    }
}
