<?php

declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['_csrf'];
    }

    public static function check(Request $r): bool
    {
        $sent = (string) ($r->post['_csrf'] ?? $r->server['HTTP_X_CSRF_TOKEN'] ?? '');
        return $sent !== '' && !empty($_SESSION['_csrf']) && hash_equals((string) $_SESSION['_csrf'], $sent);
    }
}
