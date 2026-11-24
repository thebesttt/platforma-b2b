<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\AuditLog;
use App\Core\Auth;
use App\Core\Brand;
use App\Core\Kv;
use App\Core\Mailer;
use App\Core\Plans;
use App\Core\Controller;
use App\Core\Nip;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Ai\Claude;

final class SettingsController extends Controller
{
    public function index(Request $r): Response
    {
        $team = $this->db()->all('SELECT id, name, email, role, last_login_at, totp_on FROM users WHERE org_id = ? ORDER BY id', [$this->orgId()]);
        $org = Auth::org();
        $usage = Plans::usage($this->orgId());
        $limits = [];
        foreach (Plans::LIMITS as $key => [$label, $hint]) {
            $limits[$key] = ['label' => $label, 'hint' => $hint, 'used' => $usage[$key], 'limit' => Plans::limit($org, $key)];
        }
        return $this->view('settings/index', [
            'plan' => ['key' => Plans::of($org), 'name' => Plans::name($org), 'limits' => $limits, 'renews' => gmdate('Y-m-01', strtotime('+1 month')), 'sales' => (string) config('SALES_EMAIL')],
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
        // Inne urządzenia tracą dostęp, ta sesja zostaje.
        Auth::revokeSessions(Auth::id(), true);
        AuditLog::add('auth.password_change', 'user', Auth::id(), [], $r->ip());
        return $this->redirect('/ustawienia', 'Hasło zmienione. Pozostałe urządzenia zostały wylogowane.');
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

    /** Zapytanie kontrolne do CEIDG: sprawdza token i połączenie. Tylko administrator platformy. */
    public function testCeidg(Request $r): Response
    {
        if (!Auth::isAdmin()) {
            return $this->notFound();
        }
        $res = \App\Modules\Companies\Ceidg::check();
        return $res['ok']
            ? $this->redirect('/ustawienia', 'CEIDG działa: token przyjęty. Wyszukiwanie firm jest w zakładce Klienci → Potencjalni klienci.')
            : $this->redirect('/ustawienia', 'Test CEIDG nieudany. ' . $res['error'], 'error');
    }

    /** Zapytanie kontrolne do Google: sprawdza klucz konta usługi i pokazuje, które witryny w Search Console konto widzi. Tylko administrator platformy. */
    public function testGoogle(Request $r): Response
    {
        if (!Auth::isAdmin()) {
            return $this->notFound();
        }
        $res = \App\Modules\Seo\Google::properties();
        if (!$res['ok']) {
            return $this->redirect('/ustawienia', 'Test Google nieudany. ' . $res['error'], 'error');
        }
        $n = count($res['sites']);
        $list = implode(', ', array_slice(array_column($res['sites'], 'url'), 0, 5));
        return $this->redirect('/ustawienia', 'Google działa: klucz przyjęty. Konto usługi ' . \App\Modules\Seo\Google::email() . ' widzi w Search Console '
            . $n . ' ' . plural($n, 'witrynę', 'witryny', 'witryn') . ($n ? ' (' . $list . ($n > 5 ? ' i kolejne' : '') . ')' : '. Dodaj ten adres jako użytkownika w Search Console witryny') . '.');
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
        if (!Plans::room(Auth::org(), 'team')) {
            return $this->redirect('/ustawienia#zespol', 'Pakiet ' . Plans::name(Auth::org()) . ' obejmuje ' . (int) Plans::limit(Auth::org(), 'team') . ' osoby w zespole. Usuń kogoś albo zmień pakiet.', 'error');
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

    /** Usunięcie osoby z zespołu. Właściciel nie usuwa siebie ani innego właściciela. */
    public function removeMember(Request $r): Response
    {
        if (!Auth::isOwner()) {
            return $this->redirect('/ustawienia', 'Zespół zarządza właściciel konta.', 'error');
        }
        $u = $this->db()->one('SELECT id, email, role FROM users WHERE id = ? AND org_id = ?', [(int) $r->param('id'), $this->orgId()]);
        if (!$u) {
            return $this->notFound('Nie ma takiej osoby');
        }
        if ((int) $u['id'] === Auth::id() || $u['role'] === 'owner') {
            return $this->redirect('/ustawienia#zespol', 'Konta właściciela nie da się usunąć z zespołu.', 'error');
        }
        $this->db()->run('DELETE FROM users WHERE id = ? AND org_id = ?', [(int) $u['id'], $this->orgId()]);
        AuditLog::add('team.remove', 'user', (int) $u['id'], ['email' => (string) $u['email']], $r->ip());
        return $this->redirect('/ustawienia#zespol', 'Osoba usunięta z zespołu. Jej sesje przestały działać.');
    }

    /** Właściciel wyłącza logowanie dwuetapowe osobie, która straciła telefon i kody zapasowe. */
    public function resetMemberTwoFactor(Request $r): Response
    {
        if (!Auth::isOwner()) {
            return $this->redirect('/ustawienia', 'Zespół zarządza właściciel konta.', 'error');
        }
        $u = $this->db()->one('SELECT id, email FROM users WHERE id = ? AND org_id = ?', [(int) $r->param('id'), $this->orgId()]);
        if (!$u || (int) $u['id'] === Auth::id()) {
            return $this->notFound('Nie ma takiej osoby');
        }
        $this->db()->update('users', ['totp_on' => 0, 'totp_secret' => null, 'recovery_json' => null], 'id = ?', [(int) $u['id']]);
        Auth::revokeSessions((int) $u['id']);
        AuditLog::add('auth.2fa_reset', 'user', (int) $u['id'], ['by' => Auth::id()], $r->ip());
        return $this->redirect('/ustawienia#zespol', 'Logowanie dwuetapowe wyłączone dla ' . $u['email'] . '. Osoba zaloguje się samym hasłem i może włączyć je od nowa.');
    }

    public function logoutEverywhere(Request $r): Response
    {
        Auth::revokeSessions(Auth::id(), true);
        AuditLog::add('auth.logout_all', 'user', Auth::id(), [], $r->ip());
        return $this->redirect('/ustawienia#bezpieczenstwo', 'Pozostałe urządzenia zostały wylogowane. Ta sesja działa dalej.');
    }

    // --- Logowanie dwuetapowe -----------------------------------------------------------------------------------------

    public function twoFactor(Request $r): Response
    {
        $u = Auth::user();
        $secret = (int) $u['totp_on'] === 0 ? \App\Core\Secret::decrypt((string) $u['totp_secret']) : null;
        $codes = $_SESSION['recovery_once'] ?? null;
        unset($_SESSION['recovery_once']);
        $uri = $secret ? \App\Core\Totp::uri($secret, (string) $u['email'], (string) config('APP_NAME')) : '';
        return $this->view('settings/twofa', [
            'on' => (int) $u['totp_on'] === 1, 'secret' => $secret, 'qr' => $uri !== '' ? \App\Core\Qr::png($uri, 5) : null,
            'codes' => is_array($codes) ? $codes : null, 'left' => count(json_in((string) $u['recovery_json'])), 'title' => 'Logowanie dwuetapowe',
        ]);
    }

    /** Krok 1: po podaniu hasła powstaje klucz do zeskanowania. Logowanie dwuetapowe włączy dopiero poprawny kod. */
    public function twoFactorStart(Request $r): Response
    {
        $u = Auth::user();
        if (!\App\Core\Secret::ready()) {
            return $this->redirect('/ustawienia/logowanie-dwuetapowe', 'Administrator platformy musi ustawić zmienną APP_SECRET (co najmniej 16 znaków). Bez niej klucza nie da się bezpiecznie zapisać.', 'error');
        }
        if (!password_verify((string) $r->input('current', ''), (string) $u['password_hash'])) {
            return $this->redirect('/ustawienia/logowanie-dwuetapowe', 'Hasło jest nieprawidłowe.', 'error');
        }
        if ((int) $u['totp_on'] === 0) {
            $this->db()->update('users', ['totp_secret' => \App\Core\Secret::encrypt(\App\Core\Totp::newSecret())], 'id = ?', [Auth::id()]);
        }
        return $this->redirect('/ustawienia/logowanie-dwuetapowe');
    }

    /** Krok 2: kod z aplikacji potwierdza, że klucz trafił na telefon. Kody zapasowe pokazujemy raz. */
    public function twoFactorEnable(Request $r): Response
    {
        $u = Auth::user();
        $secret = \App\Core\Secret::decrypt((string) $u['totp_secret']);
        if ((int) $u['totp_on'] === 1 || $secret === null) {
            return $this->redirect('/ustawienia/logowanie-dwuetapowe');
        }
        if (Kv::hit('2fa-setup:' . Auth::id(), 900) > 10 || \App\Core\Totp::match($secret, $r->str('code')) === null) {
            return $this->redirect('/ustawienia/logowanie-dwuetapowe', 'Kod jest nieprawidłowy. Wpisz aktualny kod z aplikacji.', 'error');
        }
        $this->db()->update('users', ['totp_on' => 1], 'id = ?', [Auth::id()]);
        $_SESSION['recovery_once'] = \App\Core\Totp::newRecoveryCodes(Auth::id());
        Auth::revokeSessions(Auth::id(), true);
        AuditLog::add('auth.2fa_on', 'user', Auth::id(), [], $r->ip());
        return $this->redirect('/ustawienia/logowanie-dwuetapowe', 'Logowanie dwuetapowe jest włączone. Zapisz kody zapasowe: pokazujemy je tylko teraz.');
    }

    public function twoFactorDisable(Request $r): Response
    {
        $u = Auth::user();
        if ((int) $u['totp_on'] !== 1) {
            return $this->redirect('/ustawienia/logowanie-dwuetapowe');
        }
        if (!password_verify((string) $r->input('current', ''), (string) $u['password_hash']) || !\App\Core\Totp::check($u, $r->str('code'))) {
            return $this->redirect('/ustawienia/logowanie-dwuetapowe', 'Podaj hasło i aktualny kod z aplikacji albo kod zapasowy.', 'error');
        }
        $this->db()->update('users', ['totp_on' => 0, 'totp_secret' => null, 'recovery_json' => null], 'id = ?', [Auth::id()]);
        AuditLog::add('auth.2fa_off', 'user', Auth::id(), [], $r->ip());
        return $this->redirect('/ustawienia/logowanie-dwuetapowe', 'Logowanie dwuetapowe wyłączone.');
    }

    // --- Dane firmy ---------------------------------------------------------------------------------------------------

    /** Komplet danych firmy do pobrania. Tylko właściciel. */
    public function export(Request $r): Response
    {
        if (!Auth::isOwner()) {
            return $this->redirect('/ustawienia', 'Dane firmy pobiera właściciel konta.', 'error');
        }
        if (Kv::hit('export:' . $this->orgId(), 3600) > 5) {
            return $this->redirect('/ustawienia#dane', 'Eksport można pobrać 5 razy na godzinę. Spróbuj później.', 'error');
        }
        $res = \App\Core\OrgData::export($this->orgId());
        AuditLog::add('org.export', 'organization', $this->orgId(), ['tables' => $res['tables'], 'rows' => $res['rows']], $r->ip());
        return Response::download($res['zip'], 'dane-firmy-' . gmdate('Y-m-d') . '.zip', 'application/zip');
    }

    /** Trwałe usunięcie konta firmy ze wszystkimi danymi. Wymaga hasła właściciela i przepisania nazwy firmy. */
    public function deleteOrg(Request $r): Response
    {
        $org = Auth::org();
        if (!Auth::isOwner()) {
            return $this->redirect('/ustawienia', 'Konto firmy usuwa właściciel.', 'error');
        }
        if (Plans::isOperator($this->orgId())) {
            return $this->redirect('/ustawienia#dane', 'To firma administratora platformy. Jej konta nie da się usunąć z panelu.', 'error');
        }
        if (!password_verify((string) $r->input('current', ''), (string) Auth::user()['password_hash']) || mb_strtolower(trim($r->str('confirm'))) !== mb_strtolower(trim((string) $org['name']))) {
            return $this->redirect('/ustawienia#dane', 'Podaj hasło i przepisz dokładnie nazwę firmy. Nic nie zostało usunięte.', 'error');
        }
        $orgId = $this->orgId();
        $name = (string) $org['name'];
        Auth::logout();
        \App\Core\OrgData::delete($orgId);
        AuditLog::add('org.deleted', 'organization', $orgId, ['name' => $name], $r->ip());
        return $this->redirect('/logowanie', 'Konto firmy ' . $name . ' i wszystkie jej dane zostały usunięte.');
    }
}
