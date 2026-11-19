<?php

declare(strict_types=1);

namespace App\Core;

/** Konta: rejestracja firmy z właścicielem, logowanie, bieżący użytkownik i firma. */
final class Auth
{
    /** Bardzo długie hasło to tani sposób na obciążenie serwera liczeniem skrótu. */
    public const MAX_PASSWORD_BYTES = 800;

    private static ?array $user = null;
    private static ?array $org = null;

    public static function user(): ?array
    {
        if (self::$user === null && !empty($_SESSION['uid'])) {
            self::$user = Db::get()->one('SELECT * FROM users WHERE id = ?', [(int) $_SESSION['uid']]);
            if (!self::$user) {
                unset($_SESSION['uid']);
            }
        }
        return self::$user;
    }

    public static function org(): ?array
    {
        $u = self::user();
        if ($u && self::$org === null) {
            self::$org = Db::get()->one('SELECT * FROM organizations WHERE id = ?', [(int) $u['org_id']]);
        }
        return self::$org;
    }

    public static function orgId(): int
    {
        return (int) (self::user()['org_id'] ?? 0);
    }

    public static function id(): int
    {
        return (int) (self::user()['id'] ?? 0);
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** Administrator platformy (zmienna ADMIN_EMAILS) – np. ręczne wgrywanie decyzji UOKiK. */
    public static function isAdmin(): bool
    {
        $email = mb_strtolower((string) (self::user()['email'] ?? ''));
        $list = array_filter(array_map(static fn ($e) => mb_strtolower(trim($e)), explode(',', (string) Config::get('ADMIN_EMAILS'))));
        return $email !== '' && in_array($email, $list, true);
    }

    public static function isOwner(): bool
    {
        return (self::user()['role'] ?? '') === 'owner';
    }

    /** Tylko testy i CLI. */
    public static function actAs(int $userId): void
    {
        self::$user = null;
        self::$org = null;
        $_SESSION['uid'] = $userId;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    }

    /**
     * @return array{ok: bool, error?: string, user_id?: int}
     */
    public static function register(string $company, string $nip, string $name, string $email, string $password): array
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Podaj poprawny adres e-mail.'];
        }
        if (mb_strlen($password) < 10) {
            return ['ok' => false, 'error' => 'Hasło musi mieć co najmniej 10 znaków.'];
        }
        if (strlen($password) > self::MAX_PASSWORD_BYTES) {
            return ['ok' => false, 'error' => 'Hasło jest za długie (najwyżej 200 znaków).'];
        }
        if (trim($company) === '') {
            return ['ok' => false, 'error' => 'Podaj nazwę firmy.'];
        }
        $nip = preg_replace('/\D/', '', $nip) ?? '';
        if ($nip !== '' && !Nip::valid($nip)) {
            return ['ok' => false, 'error' => 'Numer NIP jest nieprawidłowy.'];
        }
        $db = Db::get();
        if ($db->val('SELECT 1 FROM users WHERE email = ?', [$email])) {
            return ['ok' => false, 'error' => 'Konto z tym adresem e-mail już istnieje. Zaloguj się.'];
        }
        $uid = $db->transaction(static function (Db $db) use ($company, $nip, $name, $email, $password): int {
            $orgId = $db->insert('organizations', [
                'name' => trim($company),
                'nip' => $nip ?: null,
                'plan' => 'trial',
                'created_at' => now_utc(),
            ]);
            return $db->insert('users', [
                'org_id' => $orgId,
                'email' => $email,
                'name' => trim($name),
                'password_hash' => self::hash($password),
                'role' => 'owner',
                'created_at' => now_utc(),
            ]);
        });
        return ['ok' => true, 'user_id' => $uid];
    }

    /** @return array{ok: bool, error?: string} */
    public static function attempt(string $email, string $password, string $ip): array
    {
        $email = mb_strtolower(trim($email));
        if (strlen($password) > self::MAX_PASSWORD_BYTES) {
            return ['ok' => false, 'error' => 'Nieprawidłowy e-mail lub hasło.'];
        }
        $key = 'login:' . hash('sha256', $ip . '|' . $email);
        // Drugi licznik tylko na e-mail: adres IP za proxy da się podrobić nagłówkiem, więc nie może być jedyną ochroną.
        $emailKey = 'login-email:' . hash('sha256', $email);
        if ((int) (Kv::get($key) ?? 0) >= 8 || (int) (Kv::get($emailKey) ?? 0) >= 20) {
            return ['ok' => false, 'error' => 'Zbyt wiele nieudanych prób. Spróbuj ponownie za 15 minut.'];
        }
        $u = Db::get()->one('SELECT * FROM users WHERE email = ?', [$email]);
        if (!$u || !password_verify($password, (string) $u['password_hash'])) {
            Kv::hit($key, 900);
            Kv::hit($emailKey, 900);
            usleep(250000);
            return ['ok' => false, 'error' => 'Nieprawidłowy e-mail lub hasło.'];
        }
        if (password_needs_rehash((string) $u['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            Db::get()->update('users', ['password_hash' => self::hash($password)], 'id = ?', [$u['id']]);
        }
        Kv::forget($key);
        Kv::forget($emailKey);
        self::login((int) $u['id']);
        return ['ok' => true];
    }

    public static function login(int $userId): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['uid'] = $userId;
        self::$user = null;
        self::$org = null;
        Db::get()->update('users', ['last_login_at' => now_utc()], 'id = ?', [$userId]);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        self::$user = null;
        self::$org = null;
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}
