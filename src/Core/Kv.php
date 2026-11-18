<?php

declare(strict_types=1);

namespace App\Core;

/** Pamięć podręczna i liczniki (limity zapytań) w bazie. */
final class Kv
{
    public static function get(string $key): mixed
    {
        $row = Db::get()->one('SELECT v, expires_at FROM kv WHERE k = ?', [$key]);
        if (!$row || ($row['expires_at'] !== null && (int) $row['expires_at'] < time())) {
            return null;
        }
        return json_decode((string) $row['v'], true);
    }

    public static function set(string $key, mixed $value, ?int $ttl = null): void
    {
        Db::get()->upsert('kv', [
            'k' => $key,
            'v' => json_out($value),
            'expires_at' => $ttl ? time() + $ttl : null,
        ], ['k'], null, false);
    }

    public static function forget(string $key): void
    {
        Db::get()->run('DELETE FROM kv WHERE k = ?', [$key]);
    }

    /** Zwiększa licznik w oknie czasowym; zwraca nową wartość. */
    public static function hit(string $key, int $window): int
    {
        $n = (int) (self::get($key) ?? 0) + 1;
        $row = Db::get()->one('SELECT expires_at FROM kv WHERE k = ?', [$key]);
        $ttl = ($row && $row['expires_at'] && (int) $row['expires_at'] > time()) ? (int) $row['expires_at'] - time() : $window;
        self::set($key, $n, max(1, $ttl));
        return $n;
    }

    public static function remember(string $key, int $ttl, callable $fn): mixed
    {
        $v = self::get($key);
        if ($v !== null) {
            return $v;
        }
        $v = $fn();
        if ($v !== null) {
            self::set($key, $v, $ttl);
        }
        return $v;
    }
}
