<?php

declare(strict_types=1);

namespace App\Core;

use SessionHandlerInterface;

/** Sesje w bazie, żeby przetrwały restart i wdrożenie serwera. */
final class Session implements SessionHandlerInterface
{
    private const TTL = 60 * 60 * 24 * 14;

    public static function start(Request $request): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
            return;
        }
        session_set_save_handler(new self(), true);
        session_name('sid');
        session_set_cookie_params([
            'lifetime' => self::TTL,
            'path' => '/',
            'secure' => $request->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', (string) self::TTL);
        session_start();
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $row = Db::get()->one('SELECT data, expires_at FROM sessions WHERE id = ?', [hash('sha256', $id)]);
        if (!$row || (int) $row['expires_at'] < time()) {
            return '';
        }
        return (string) $row['data'];
    }

    public function write(string $id, string $data): bool
    {
        Db::get()->upsert('sessions', [
            'id' => hash('sha256', $id),
            'data' => $data,
            'expires_at' => time() + self::TTL,
        ], ['id'], null, false);
        return true;
    }

    public function destroy(string $id): bool
    {
        Db::get()->run('DELETE FROM sessions WHERE id = ?', [hash('sha256', $id)]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return Db::get()->run('DELETE FROM sessions WHERE expires_at < ?', [time()])->rowCount();
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return array<int, array{type: string, message: string}> */
    public static function takeFlash(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $f;
    }

    public static function old(string $key, string $default = ''): string
    {
        return (string) ($_SESSION['_old'][$key] ?? $default);
    }
}
