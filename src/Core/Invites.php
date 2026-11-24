<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Zaproszenia firm na platformę. Administrator wskazuje adres e-mail i pakiet, a panel tworzy jednorazowy link.
 * Konto założone z linku dostaje ten adres (nie da się go podmienić) i ten pakiet. W bazie zostaje tylko skrót tokenu.
 */
final class Invites
{
    public const DAYS = 14;

    /** @return array{ok: bool, error?: string, link?: string, id?: int} */
    public static function create(string $email, string $company, string $plan, int $adminId, string $baseUrl): array
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 160) {
            return ['ok' => false, 'error' => 'Podaj poprawny adres e-mail osoby, która założy konto firmy.'];
        }
        if (!isset(Plans::PLANS[$plan])) {
            return ['ok' => false, 'error' => 'Wybierz pakiet.'];
        }
        $db = Db::get();
        if ($db->val('SELECT 1 FROM users WHERE email = ?', [$email])) {
            return ['ok' => false, 'error' => 'Ten adres e-mail ma już konto na platformie.'];
        }
        // Nowe zaproszenie na ten sam adres zastępuje poprzednie, żeby w obiegu był jeden ważny link.
        $db->run('DELETE FROM invites WHERE email = ? AND used_at IS NULL', [$email]);
        $token = bin2hex(random_bytes(24));
        $id = $db->insert('invites', [
            'token_hash' => hash('sha256', $token), 'email' => $email, 'company' => mb_substr(trim($company), 0, 160) ?: null, 'plan' => $plan,
            'created_by' => $adminId, 'created_at' => now_utc(), 'expires_at' => gmdate('Y-m-d H:i:s', time() + self::DAYS * 86400),
        ]);
        return ['ok' => true, 'id' => $id, 'link' => rtrim($baseUrl, '/') . '/rejestracja?zaproszenie=' . $token];
    }

    /** Ważne, niewykorzystane zaproszenie dla tokenu z linku. */
    public static function find(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }
        return Db::get()->one('SELECT * FROM invites WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?', [hash('sha256', $token), now_utc()]);
    }

    public static function consume(int $id, int $orgId): void
    {
        Db::get()->update('invites', ['used_at' => now_utc(), 'org_id' => $orgId], 'id = ? AND used_at IS NULL', [$id]);
    }

    /** @return array<int, array<string, mixed>> zaproszenia czekające na użycie */
    public static function pending(): array
    {
        return Db::get()->all('SELECT id, email, company, plan, created_at, expires_at FROM invites WHERE used_at IS NULL AND expires_at > ? ORDER BY id DESC LIMIT 100', [now_utc()]);
    }

    public static function revoke(int $id): void
    {
        Db::get()->run('DELETE FROM invites WHERE id = ? AND used_at IS NULL', [$id]);
    }
}
