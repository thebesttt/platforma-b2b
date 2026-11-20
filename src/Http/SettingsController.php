<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\AuditLog;
use App\Core\Auth;
use App\Core\Brand;
use App\Core\Mailer;
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
        $org = Auth::org();
        return $this->view('settings/index', [
            'org' => $org, 'user' => Auth::user(), 'team' => $team, 'aiEnabled' => Claude::enabled(), 'title' => 'Ustawienia',
            'theme' => Brand::theme($org), 'modules' => Brand::modules($org), 'mail' => Mailer::driver(), 'digest' => (Brand::settings($org)['digest'] ?? true) !== false,
            'outbox' => $this->db()->all('SELECT recipient, subject, status, error, created_at FROM outbox WHERE org_id = ? ORDER BY id DESC LIMIT 6', [$this->orgId()]),
        ]);
    }

    public function save(Request $r): Response
    {
        if (!Auth::isOwner()) {
            $this->db()->update('users', ['name' => mb_substr($r->str('user_name'), 0, 120)], 'id = ?', [Auth::id()]);
            return $this->redirect('/ustawienia', 'Zapisano Twoje imię i nazwisko. Dane firmy zmienia właściciel konta.', 'info');
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
        if (mb_strlen($new) < 10 || strlen($new) > Auth::MAX_PASSWORD_BYTES) {
            return $this->redirect('/ustawienia#haslo', 'Nowe hasło musi mieć co najmniej 10 znaków.', 'error');
        }
        $this->db()->update('users', ['password_hash' => Auth::hash($new)], 'id = ?', [Auth::id()]);
        AuditLog::add('auth.password_change', 'user', Auth::id(), [], $r->ip());
        return $this->redirect('/ustawienia', 'Hasło zmienione.');
    }

    /** Ręczne wgranie list decyzji UOKiK (gdy rejestr blokuje połączenia z serwera). Tylko administrator platformy. */
    public function uokik(Request $r): Response
    {
        if (!Auth::isAdmin()) {
            return $this->notFound();
        }
        $stats = $this->db()->one('SELECT COUNT(*) AS n, MAX(decided_at) AS latest FROM uokik_decisions') ?? ['n' => 0, 'latest' => null];
        return $this->view('settings/uokik', ['stats' => $stats, 'last' => \App\Core\Kv::get('uokik:last_sync'), 'title' => 'Decyzje UOKiK']);
    }

    public function uokikUpload(Request $r): Response
    {
        if (!Auth::isAdmin()) {
            return $this->notFound();
        }
        $saved = 0;
        $files = TenderController::normalizeFiles($r->files['pages'] ?? null);
        foreach ($files as $f) {
            if ($f['error'] !== UPLOAD_ERR_OK || (int) $f['size'] > 20 * 1024 * 1024 || (PHP_SAPI !== 'cli' && !is_uploaded_file((string) $f['tmp_name']))) {
                continue;
            }
            $html = (string) file_get_contents((string) $f['tmp_name']);
            if (!mb_check_encoding($html, 'UTF-8')) {
                $html = mb_convert_encoding($html, 'UTF-8', 'ISO-8859-2');
            }
            $saved += \App\Modules\Companies\Uokik::store(\App\Modules\Companies\Uokik::parseList($html));
        }
        if ($saved) {
            \App\Core\Kv::set('uokik:last_sync', now_utc());
        }
        AuditLog::add('uokik.upload', null, null, ['saved' => $saved], $r->ip());
        return $this->redirect('/ustawienia/uokik', $saved ? 'Zapisano ' . $saved . ' ' . plural($saved, 'decyzję', 'decyzje', 'decyzji') . '.' : 'Nie znaleziono decyzji w plikach. Zapisz stronę listy jako „HTML”.', $saved ? 'success' : 'error');
    }

    /** Krótkie zapytanie kontrolne do modelu AI - sprawdza klucz, nazwę modelu i połączenie. Tylko administrator platformy. */
    public function testAi(Request $r): Response
    {
        if (!Auth::isAdmin()) {
            return $this->notFound();
        }
        $res = Claude::complete('Odpowiadasz jednym słowem.', 'Napisz słowo: działa', 20);
        if (!$res['ok']) {
            return $this->redirect('/ustawienia', 'Test AI nieudany. ' . $res['error'], 'error');
        }
        $u = $res['usage'] ?? [];
        return $this->redirect('/ustawienia', 'AI działa. Model ' . config('AI_MODEL') . ' odpowiedział: „' . truncate((string) $res['text'], 40) . '” (tokeny: ' . (int) ($u['input_tokens'] ?? 0) . ' wejście, ' . (int) ($u['output_tokens'] ?? 0) . ' wyjście).');
    }

    /** Wygląd panelu i widoczne moduły. Ustawia właściciel konta. */
    public function appearance(Request $r): Response
    {
        if (!Auth::isOwner()) {
            return $this->redirect('/ustawienia', 'Wygląd i moduły ustawia właściciel konta.', 'error');
        }
        $theme = $r->str('theme');
        $picked = array_map('strval', (array) ($r->post['modules'] ?? []));
        Brand::save($this->orgId(), [
            'theme' => isset(Brand::THEMES[$theme]) ? $theme : 'default',
            'modules' => array_values(array_intersect(array_keys(Brand::MODULES), $picked)),
        ]);
        AuditLog::add('settings.appearance', 'organization', $this->orgId(), ['theme' => $theme, 'modules' => $picked], $r->ip());
        return $this->redirect('/ustawienia#wyglad', 'Zapisano wygląd i moduły.');
    }

    /** Włącza albo wyłącza poranny przegląd e-mailem dla całej firmy. */
    public function digest(Request $r): Response
    {
        if (!Auth::isOwner()) {
            return $this->redirect('/ustawienia#poczta', 'Poranny przegląd ustawia właściciel konta.', 'error');
        }
        $on = $r->str('digest') === '1';
        Brand::save($this->orgId(), ['digest' => $on]);
        return $this->redirect('/ustawienia#poczta', $on ? 'Poranny przegląd włączony. Wychodzi po codziennym imporcie, gdy jest coś nowego.' : 'Poranny przegląd wyłączony.');
    }

    /** Wiadomość kontrolna na adres zalogowanej osoby: sprawdza klucz usługi wysyłkowej i adres nadawcy. */
    public function testMail(Request $r): Response
    {
        if (!Auth::isAdmin()) {
            return $this->notFound();
        }
        $res = Mailer::send((string) Auth::user()['email'], 'Wiadomość kontrolna: ' . config('APP_NAME'),
            'To jest wiadomość kontrolna z panelu ' . config('APP_NAME') . '. Jeśli ją czytasz, wysyłka e-maili działa.', $this->orgId(), 'test');
        return $this->redirect('/ustawienia#poczta', $res['ok']
            ? ($res['status'] === 'sent' ? 'Wysłano wiadomość kontrolną na Twój adres.' : 'Tryb lokalny: wiadomość zapisana w dzienniku, nic nie zostało wysłane.')
            : 'Nie udało się wysłać. ' . ($res['error'] ?? ''), $res['ok'] ? 'success' : 'error');
    }

    /**
     * Właściciel dodaje osobę do zespołu. Gdy działa poczta i hasło jest puste, osoba dostaje e-mail z linkiem
     * do ustawienia własnego hasła (ważny 3 dni). W przeciwnym razie właściciel podaje hasło startowe.
     */
    public function invite(Request $r): Response
    {
        if (!Auth::isOwner()) {
            return $this->redirect('/ustawienia', 'Zespół zarządza właściciel konta.', 'error');
        }
        $email = mb_strtolower($r->str('email'));
        $password = (string) $r->input('password', '');
        $byMail = $password === '' && Mailer::enabled();
        if ($byMail) {
            $password = bin2hex(random_bytes(20)); // nikt go nie zna: osoba ustawi własne hasło z linku
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($password) < 10 || strlen($password) > Auth::MAX_PASSWORD_BYTES) {
            return $this->redirect('/ustawienia#zespol', Mailer::enabled() ? 'Podaj poprawny e-mail. Hasło startowe jest opcjonalne, ale musi mieć co najmniej 10 znaków.' : 'Podaj e-mail i hasło startowe (co najmniej 10 znaków).', 'error');
        }
        if ($this->db()->val('SELECT 1 FROM users WHERE email = ?', [$email])) {
            return $this->redirect('/ustawienia#zespol', 'Ten adres e-mail ma już konto.', 'error');
        }
        $id = $this->db()->insert('users', [
            'org_id' => $this->orgId(), 'email' => $email, 'name' => mb_substr($r->str('name'), 0, 120),
            'password_hash' => Auth::hash($password), 'role' => 'member', 'created_at' => now_utc(),
        ]);
        AuditLog::add('team.add', 'user', $id, ['email' => $email, 'invited_by_mail' => $byMail], $r->ip());
        if ($byMail) {
            $link = AuthController::passwordLink($id, 3 * 86400, $r->baseUrl());
            $sent = Mailer::send($email, 'Zaproszenie do panelu ' . config('APP_NAME'),
                (Auth::user()['name'] ?: Auth::user()['email']) . ' dodaje Cię do zespołu firmy ' . (Auth::org()['name'] ?? '') . ' w panelu ' . config('APP_NAME')
                . ".\n\nUstaw swoje hasło pod tym adresem (link działa 3 dni):\n" . $link . "\n\nLogin to ten adres e-mail.", $this->orgId(), 'invite');
            return $this->redirect('/ustawienia#zespol', $sent['ok'] ? 'Dodano osobę i wysłano jej link do ustawienia hasła.' : 'Dodano osobę, ale e-mail nie wyszedł. ' . ($sent['error'] ?? '') . ' Osoba może użyć „Nie pamiętam hasła” na stronie logowania.', $sent['ok'] ? 'success' : 'error');
        }
        return $this->redirect('/ustawienia#zespol', 'Dodano osobę do zespołu. Przekaż jej hasło startowe i poproś o zmianę po pierwszym logowaniu.');
    }
}
