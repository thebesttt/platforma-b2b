<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\AuditLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Kv;
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
                return $this->redirect('/pulpit', 'Konto gotowe. Zacznij od profilu przetargowego – na jego podstawie codziennie dobierzemy ogłoszenia.');
            }
            $error = $res['error'];
        }
        return Response::html(\App\Core\View::render('auth/register', ['title' => 'Załóż konto', 'v' => $v, 'error' => $error, 'kod' => $kod], 'public'), 422);
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
