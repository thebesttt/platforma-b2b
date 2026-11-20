<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\AuditLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Kv;
use App\Core\Mailer;
use App\Core\Request;
use App\Core\Response;

final class AuthController extends Controller
{
    public function home(Request $r): Response
    {
        if (Auth::check()) {
            return $this->redirect('/pulpit');
        }
        return $this->view('public/home', ['wrap' => 'pub-wide'], 'public');
    }

    public function loginForm(Request $r): Response
    {
        if (Auth::check()) {
            return $this->redirect('/pulpit');
        }
        return $this->view('auth/login', ['title' => 'Logowanie', 'email' => '', 'next' => $this->safeNext($r->str('next'))], 'public');
    }

    public function login(Request $r): Response
    {
        $email = $r->str('email');
        $res = Auth::attempt($email, (string) $r->input('password', ''), $r->ip());
        if (!$res['ok']) {
            return Response::html(\App\Core\View::render('auth/login', [
                'title' => 'Logowanie', 'email' => $email, 'error' => $res['error'], 'next' => $this->safeNext($r->str('next')),
            ], 'public'), 422);
        }
        AuditLog::add('auth.login', 'user', Auth::id(), [], $r->ip());
        return $this->redirect($this->safeNext($r->str('next')) ?: '/pulpit');
    }

    /** Rejestracja otwarta dla wszystkich (SIGNUP_OPEN=1) albo tylko z kodem zaproszenia (SIGNUP_CODE). */
    public static function signupAllowed(string $code): bool
    {
        if ((string) config('SIGNUP_OPEN') === '1') {
            return true;
        }
        $expected = (string) config('SIGNUP_CODE');
        return $expected !== '' && $code !== '' && hash_equals($expected, $code);
    }

    public function registerForm(Request $r): Response
    {
        if (!self::signupAllowed($r->str('kod'))) {
            return Response::html(\App\Core\View::render('errors/message', ['title' => 'Rejestracja na zaproszenie', 'message' => 'Konto założysz z linku z zaproszeniem. Jeśli go nie masz, poproś o niego administratora platformy.'], 'public'), 403);
        }
        return $this->view('auth/register', ['title' => 'Załóż konto', 'v' => [], 'kod' => $r->str('kod')], 'public');
    }

    public function register(Request $r): Response
    {
        $kod = $r->str('kod');
        if (!self::signupAllowed($kod)) {
            return $this->redirect('/rejestracja');
        }
        $v = ['company' => $r->str('company'), 'nip' => $r->str('nip'), 'name' => $r->str('name'), 'email' => $r->str('email')];
        if (Kv::hit('signup:' . hash('sha256', $r->ip()), 3600) > 10) {
            $error = 'Zbyt wiele prób rejestracji. Spróbuj za godzinę.';
        } elseif ($r->input('terms') !== '1') {
            $error = 'Zaakceptuj regulamin, aby założyć konto.';
        } else {
            $res = Auth::register($v['company'], $v['nip'], $v['name'], $v['email'], (string) $r->input('password', ''));
            if ($res['ok']) {
                Auth::login((int) $res['user_id']);
                AuditLog::add('auth.register', 'user', (int) $res['user_id'], ['terms_accepted_at' => now_utc(), 'invited' => (string) config('SIGNUP_OPEN') !== '1'], $r->ip());
                return $this->redirect('/pulpit', 'Konto gotowe. Zacznij od profilu przetargowego - na jego podstawie codziennie dobierzemy ogłoszenia.');
            }
            $error = $res['error'];
        }
        return Response::html(\App\Core\View::render('auth/register', ['title' => 'Załóż konto', 'v' => $v, 'error' => $error, 'kod' => $kod], 'public'), 422);
    }

    /** Jednorazowy link do ustawienia hasła. W bazie zostaje tylko skrót tokenu. */
    public static function passwordLink(int $userId, int $ttl, string $baseUrl): string
    {
        $token = bin2hex(random_bytes(24));
        Kv::set('pwreset:' . hash('sha256', $token), ['user' => $userId], $ttl);
        return $baseUrl . '/haslo/' . $token;
    }

    public function forgotForm(Request $r): Response
    {
        return $this->view('auth/forgot', ['title' => 'Nowe hasło', 'mail' => Mailer::enabled()], 'public');
    }

    public function forgot(Request $r): Response
    {
        if (Kv::hit('pwreset-ip:' . hash('sha256', $r->ip()), 3600) > 8) {
            return $this->redirect('/haslo', 'Zbyt wiele prób. Spróbuj za godzinę.', 'error');
        }
        if (!Mailer::enabled()) {
            return $this->redirect('/haslo', 'Wysyłka e-maili nie jest jeszcze skonfigurowana. Poproś właściciela konta o nowe hasło.', 'error');
        }
        $email = mb_strtolower($r->str('email'));
        $u = filter_var($email, FILTER_VALIDATE_EMAIL) ? $this->db()->one('SELECT id, org_id, email FROM users WHERE email = ?', [$email]) : null;
        if ($u) {
            $link = self::passwordLink((int) $u['id'], 3600, $r->baseUrl());
            Mailer::send((string) $u['email'], 'Nowe hasło: ' . config('APP_NAME'),
                "Ktoś poprosił o nowe hasło do konta w panelu " . config('APP_NAME') . ".\n\nUstaw hasło pod tym adresem (link działa godzinę):\n" . $link
                . "\n\nJeśli to nie Ty, zignoruj tę wiadomość. Hasło się nie zmieni.", (int) $u['org_id'], 'password_reset');
            AuditLog::add('auth.reset_request', 'user', (int) $u['id'], [], $r->ip());
        }
        // Odpowiedź jest taka sama bez względu na to, czy konto istnieje.
        return $this->redirect('/logowanie', 'Jeśli to konto istnieje, wysłaliśmy link do ustawienia hasła. Link działa godzinę.', 'info');
    }

    private function resetUser(string $token): ?array
    {
        $row = preg_match('/^[a-f0-9]{48}$/', $token) ? Kv::get('pwreset:' . hash('sha256', $token)) : null;
        return is_array($row) ? $this->db()->one('SELECT id, org_id, email FROM users WHERE id = ?', [(int) ($row['user'] ?? 0)]) : null;
    }

    public function resetForm(Request $r): Response
    {
        if (!$this->resetUser($r->param('token'))) {
            return Response::html(\App\Core\View::render('errors/message', ['title' => 'Link wygasł', 'message' => 'Ten link do ustawienia hasła jest nieważny albo został już użyty. Poproś o nowy na stronie logowania.'], 'public'), 404);
        }
        return $this->view('auth/reset', ['title' => 'Ustaw hasło', 'token' => $r->param('token')], 'public');
    }

    public function reset(Request $r): Response
    {
        $token = $r->param('token');
        $u = $this->resetUser($token);
        if (!$u) {
            return $this->redirect('/haslo', 'Link wygasł. Poproś o nowy.', 'error');
        }
        $new = (string) $r->input('password', '');
        if (mb_strlen($new) < 10 || strlen($new) > Auth::MAX_PASSWORD_BYTES) {
            return Response::html(\App\Core\View::render('auth/reset', ['title' => 'Ustaw hasło', 'token' => $token, 'error' => 'Hasło musi mieć co najmniej 10 znaków.'], 'public'), 422);
        }
        $this->db()->update('users', ['password_hash' => Auth::hash($new)], 'id = ?', [(int) $u['id']]);
        Kv::forget('pwreset:' . hash('sha256', $token));
        AuditLog::add('auth.password_reset', 'user', (int) $u['id'], [], $r->ip());
        return $this->redirect('/logowanie', 'Hasło ustawione. Zaloguj się.');
    }

    public function logout(Request $r): Response
    {
        Auth::logout();
        return $this->redirect('/logowanie', 'Wylogowano.', 'info');
    }

    private function safeNext(string $next): string
    {
        return (str_starts_with($next, '/') && !str_starts_with($next, '//') && !str_contains($next, '\\')) ? $next : '';
    }
}
