<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\AuditLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Invites;
use App\Core\Mailer;
use App\Core\Plans;
use App\Core\Request;
use App\Core\Response;

/**
 * Administracja platformy: firmy, pakiety, zaproszenia. Dostęp mają tylko adresy z ADMIN_EMAILS,
 * dla pozostałych te adresy nie istnieją (404). Administrator widzi liczby o firmach, nie ich dane.
 */
final class AdminController extends Controller
{
    public function index(Request $r): Response
    {
        if (!Auth::isAdmin()) {
            return $this->notFound();
        }
        $db = $this->db();
        $usage = [];
        foreach ($db->all('SELECT org_id, kind, calls, input_tokens, output_tokens FROM ai_usage WHERE month = ?', [Plans::month()]) as $u) {
            $usage[(int) $u['org_id']][(string) $u['kind']] = $u;
        }
        $firms = [];
        foreach ($db->all(
            'SELECT o.id, o.name, o.nip, o.plan, o.status, o.created_at,
                    (SELECT COUNT(*) FROM users u WHERE u.org_id = o.id) AS people,
                    (SELECT MAX(u.last_login_at) FROM users u WHERE u.org_id = o.id) AS last_login
             FROM organizations o ORDER BY o.id DESC LIMIT 500'
        ) as $o) {
            $id = (int) $o['id'];
            $firms[] = $o + [
                'operator' => Plans::isOperator($id), 'plan_key' => Plans::of($o),
                'ops' => (int) ($usage[$id]['ops']['calls'] ?? 0), 'chat' => (int) ($usage[$id]['chat']['calls'] ?? 0),
                'tokens' => (int) ($usage[$id]['ops']['input_tokens'] ?? 0) + (int) ($usage[$id]['ops']['output_tokens'] ?? 0)
                    + (int) ($usage[$id]['chat']['input_tokens'] ?? 0) + (int) ($usage[$id]['chat']['output_tokens'] ?? 0),
                'ops_limit' => Plans::limit($o, 'ai_ops'), 'chat_limit' => Plans::limit($o, 'ai_chat'),
            ];
        }
        $link = $_SESSION['invite_link'] ?? null;
        unset($_SESSION['invite_link']);
        return $this->view('admin/index', [
            'firms' => $firms, 'invites' => Invites::pending(), 'limits' => Plans::limits(), 'link' => is_string($link) ? $link : null,
            'totals' => ['calls' => array_sum(array_column($firms, 'ops')) + array_sum(array_column($firms, 'chat')), 'tokens' => array_sum(array_column($firms, 'tokens'))],
            'mailOn' => Mailer::enabled(), 'title' => 'Administracja platformy',
        ]);
    }

    /** Pakiet i stan firmy. */
    public function firm(Request $r): Response
    {
        if (!Auth::isAdmin()) {
            return $this->notFound();
        }
        $org = $this->db()->one('SELECT * FROM organizations WHERE id = ?', [(int) $r->param('id')]);
        if (!$org) {
            return $this->notFound('Nie ma takiej firmy');
        }
        $plan = $r->str('plan');
        $status = $r->str('status') === 'blocked' ? 'blocked' : 'active';
        if (!in_array($plan, ['basic', 'extended'], true)) {
            return $this->redirect('/administracja#firmy', 'Wybierz pakiet Podstawowy albo Rozszerzony.', 'error');
        }
        if ($status === 'blocked' && Plans::isOperator((int) $org['id'])) {
            return $this->redirect('/administracja#firmy', 'To firma administratora platformy. Nie da się jej zablokować.', 'error');
        }
        $this->db()->update('organizations', ['plan' => $plan, 'status' => $status], 'id = ?', [(int) $org['id']]);
        AuditLog::add('admin.firm', 'organization', (int) $org['id'], ['plan' => $plan, 'status' => $status, 'was' => [$org['plan'], $org['status'] ?? 'active']], $r->ip());
        return $this->redirect('/administracja#firmy', $org['name'] . ': pakiet ' . Plans::PLANS[$plan] . ($status === 'blocked' ? ', firma zablokowana. Jej osoby stracą dostęp przy najbliższym kliknięciu.' : ', firma aktywna.'));
    }

    /** Zaproszenie firmy: jednorazowy link dla adresu e-mail, z pakietem. */
    public function invite(Request $r): Response
    {
        if (!Auth::isAdmin()) {
            return $this->notFound();
        }
        $res = Invites::create($r->str('email'), $r->str('company'), $r->str('plan'), Auth::id(), $r->baseUrl());
        if (!$res['ok']) {
            return $this->redirect('/administracja#zaproszenia', $res['error'], 'error');
        }
        AuditLog::add('admin.invite', 'invite', $res['id'], ['email' => mb_strtolower(trim($r->str('email'))), 'plan' => $r->str('plan')], $r->ip());
        // Link pokazujemy raz, zaraz po utworzeniu. W bazie jest tylko skrót tokenu, więc później nie da się go odtworzyć.
        $_SESSION['invite_link'] = $res['link'];
        $sent = null;
        if (Mailer::enabled()) {
            $sent = Mailer::send(mb_strtolower(trim($r->str('email'))), 'Zaproszenie do panelu ' . config('APP_NAME'),
                'Zapraszamy do panelu ' . config('APP_NAME') . ".\n\nKonto firmy założysz pod tym adresem (link działa " . Invites::DAYS . " dni i tylko raz):\n" . $res['link']
                . "\n\nPakiet: " . Plans::PLANS[$r->str('plan')] . ".\nLoginem będzie ten adres e-mail.", null, 'platform_invite');
        }
        return $this->redirect('/administracja#zaproszenia', 'Zaproszenie gotowe.' . ($sent === null ? ' Skopiuj link i wyślij go samodzielnie.' : ($sent['ok'] ? ' Link poszedł też e-mailem.' : ' E-mail nie wyszedł, więc wyślij link samodzielnie.')));
    }

    public function revokeInvite(Request $r): Response
    {
        if (!Auth::isAdmin()) {
            return $this->notFound();
        }
        Invites::revoke((int) $r->param('id'));
        AuditLog::add('admin.invite_revoked', 'invite', (int) $r->param('id'), [], $r->ip());
        return $this->redirect('/administracja#zaproszenia', 'Zaproszenie wycofane. Link przestał działać.');
    }

    /** Limity pakietów. */
    public function limits(Request $r): Response
    {
        if (!Auth::isAdmin()) {
            return $this->notFound();
        }
        $errors = Plans::saveLimits((array) ($r->post['limit'] ?? []));
        if ($errors) {
            return $this->redirect('/administracja#pakiety', implode(' ', $errors), 'error');
        }
        AuditLog::add('admin.limits', null, null, Plans::limits(), $r->ip());
        return $this->redirect('/administracja#pakiety', 'Limity zapisane. Obowiązują od razu, także w bieżącym miesiącu.');
    }

    /** Awaryjne wyłączenie logowania dwuetapowego osobie, która straciła telefon i kody (np. właścicielowi firmy). */
    public function resetTwoFactor(Request $r): Response
    {
        if (!Auth::isAdmin()) {
            return $this->notFound();
        }
        $u = $this->db()->one('SELECT id, email, totp_on FROM users WHERE email = ?', [mb_strtolower(trim($r->str('email')))]);
        if (!$u || (int) $u['totp_on'] !== 1) {
            return $this->redirect('/administracja#awaryjne', 'Nie ma konta z tym adresem i włączonym logowaniem dwuetapowym.', 'error');
        }
        $this->db()->update('users', ['totp_on' => 0, 'totp_secret' => null, 'recovery_json' => null], 'id = ?', [(int) $u['id']]);
        Auth::revokeSessions((int) $u['id']);
        AuditLog::add('admin.2fa_reset', 'user', (int) $u['id'], [], $r->ip());
        return $this->redirect('/administracja#awaryjne', 'Logowanie dwuetapowe wyłączone dla ' . $u['email'] . '. Upewnij się inną drogą, że prosi o to właściciel konta.');
    }
}
