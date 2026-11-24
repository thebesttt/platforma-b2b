<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Szyfrowanie sekretów zapisywanych w bazie (tokeny firm, klucze logowania dwuetapowego).
 *
 * Klucz szyfrujący powstaje z APP_SECRET, więc sama kopia bazy nie wystarcza do odczytania sekretów.
 * Algorytm: XSalsa20-Poly1305 (libsodium, secretbox) z losowym numerem jednorazowym przy każdym zapisie.
 */
final class Secret
{
    private const PREFIX = 'v1:';

    public static function ready(): bool
    {
        return strlen((string) Config::get('APP_SECRET')) >= 16 && function_exists('sodium_crypto_secretbox');
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    /** Null, gdy wartość jest uszkodzona albo zaszyfrowana innym kluczem. */
    public static function decrypt(?string $stored): ?string
    {
        if (!is_string($stored) || !str_starts_with($stored, self::PREFIX)) {
            return null;
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key());
        return $plain === false ? null : $plain;
    }

    /** Zapis sekretu firmy. Pusta wartość kasuje sekret. */
    public static function put(int $orgId, string $name, string $value, array $meta = [], ?int $userId = null): void
    {
        $db = Db::get();
        $db->run('DELETE FROM org_secrets WHERE org_id = ? AND name = ?', [$orgId, $name]);
        if ($value !== '') {
            $db->insert('org_secrets', ['org_id' => $orgId, 'name' => $name, 'value_enc' => self::encrypt($value), 'meta_json' => json_out($meta),
                'created_by' => $userId, 'created_at' => now_utc()]);
        }
    }

    public static function get(int $orgId, string $name): ?string
    {
        return self::decrypt(Db::get()->val('SELECT value_enc FROM org_secrets WHERE org_id = ? AND name = ?', [$orgId, $name]) ?: null);
    }

    /** Opis sekretu bez wartości (kiedy dodany, podpis), do pokazania w panelu. */
    public static function meta(int $orgId, string $name): ?array
    {
        $row = Db::get()->one('SELECT meta_json, created_at FROM org_secrets WHERE org_id = ? AND name = ?', [$orgId, $name]);
        return $row ? json_in((string) $row['meta_json']) + ['created_at' => (string) $row['created_at']] : null;
    }

    private static function key(): string
    {
        return sodium_crypto_generichash('platforma-b2b:sekrety:' . (string) Config::get('APP_SECRET'), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
