<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\AuditLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Nip;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Ai\Claude;

final class SettingsController extends Controller
{
    public function index(Request $r): Response
    {
        $team = $this->db()->all('SELECT id, name, email, role, last_login_at FROM users WHERE org_id = ? ORDER BY id', [$this->orgId()]);
        return $this->view('settings/index', [
            'org' => Auth::org(), 'user' => Auth::user(), 'team' => $team, 'aiEnabled' => Claude::enabled(), 'title' => 'Ustawienia',
        ]);
    }

    public function save(Request $r): Response
    {
        if (!Auth::isOwner()) {
            return $this->redirect('/ustawienia', 'Dane firmy zmienia właściciel konta.', 'error');
        }
        $nip = Nip::clean($r->str('nip'));
        if ($nip !== '' && !Nip::valid($nip)) {
            return $this->redirect('/ustawienia', 'Numer NIP jest nieprawidłowy.', 'error');
        }
        $website = $r->str('website');
        if ($website !== '' && !preg_match('#^https?://#i', $website)) {
            $website = 'https://' . $website;
        }
        $this->db()->update('organizations', [
            'name' => mb_substr($r->str('name'), 0, 200) ?: (Auth::org()['name'] ?? ''),
            'nip' => $nip ?: null,
            'address' => mb_substr($r->str('address'), 0, 300) ?: null,
            'phone' => mb_substr($r->str('phone'), 0, 40) ?: null,
            'website' => filter_var($website, FILTER_VALIDATE_URL) ? $website : null,
        ], 'id = ?', [$this->orgId()]);
        $this->db()->update('users', ['name' => mb_substr($r->str('user_name'), 0, 120)], 'id = ?', [Auth::id()]);
        return $this->redirect('/ustawienia', 'Zapisano. Dane firmy trafią do formularzy ofert i wezwań do zapłaty.');
    }

    public function password(Request $r): Response
    {
        $u = Auth::user();
        if (!password_verify((string) $r->input('current', ''), (string) $u['password_hash'])) {
            return $this->redirect('/ustawienia#haslo', 'Obecne hasło jest nieprawidłowe.', 'error');
        }
        $new = (string) $r->input('new', '');
        if (mb_strlen($new) < 10) {
            return $this->redirect('/ustawienia#haslo', 'Nowe hasło musi mieć co najmniej 10 znaków.', 'error');
        }
        $this->db()->update('users', ['password_hash' => Auth::hash($new)], 'id = ?', [Auth::id()]);
        AuditLog::add('auth.password_change', 'user', Auth::id(), [], $r->ip());
        return $this->redirect('/ustawienia', 'Hasło zmienione.');
    }

    /** Właściciel dodaje członka zespołu z hasłem startowym, które przekazuje mu osobiście. */
    public function invite(Request $r): Response
    {
        if (!Auth::isOwner()) {
            return $this->redirect('/ustawienia', 'Zespół zarządza właściciel konta.', 'error');
        }
        $email = mb_strtolower($r->str('email'));
        $password = (string) $r->input('password', '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($password) < 10) {
            return $this->redirect('/ustawienia#zespol', 'Podaj e-mail i hasło startowe (co najmniej 10 znaków).', 'error');
        }
        if ($this->db()->val('SELECT 1 FROM users WHERE email = ?', [$email])) {
            return $this->redirect('/ustawienia#zespol', 'Ten adres e-mail ma już konto.', 'error');
        }
        $id = $this->db()->insert('users', [
            'org_id' => $this->orgId(), 'email' => $email, 'name' => mb_substr($r->str('name'), 0, 120),
            'password_hash' => Auth::hash($password), 'role' => 'member', 'created_at' => now_utc(),
        ]);
        AuditLog::add('team.add', 'user', $id, ['email' => $email], $r->ip());
        return $this->redirect('/ustawienia#zespol', 'Dodano osobę do zespołu. Przekaż jej hasło startowe i poproś o zmianę po pierwszym logowaniu.');
    }
}
