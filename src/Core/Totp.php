<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Logowanie dwuetapowe: kody z aplikacji (TOTP, RFC 6238) i jednorazowe kody zapasowe.
 *
 * Klucz aplikacji leży w bazie zaszyfrowany (Secret), kody zapasowe jako skróty. Kod działa 30 sekund,
 * przyjmujemy też sąsiedni przedział (zegar telefonu bywa przesunięty), a raz użyty kod nie zadziała drugi raz.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public const PERIOD = 30;

    public const RECOVERY_CODES = 8;

    public static function newSecret(): string
    {
        return self::base32(random_bytes(20));
    }

    /** Sześciocyfrowy kod dla chwili $time. */
    public static function code(string $secret, int $time): string
    {
        $key = self::unbase32($secret);
        $counter = pack('N*', 0, intdiv($time, self::PERIOD));
        $hash = hash_hmac('sha1', $counter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /** Sprawdza kod dla osoby. Zwraca numer przedziału czasu, w którym kod pasuje, albo null. */
    public static function match(string $secret, string $code, ?int $now = null): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== 6) {
            return null;
        }
        $now ??= time();
        foreach ([0, -1, 1] as $shift) {
            $t = $now + $shift * self::PERIOD;
            if (hash_equals(self::code($secret, $t), $code)) {
                return intdiv($t, self::PERIOD);
            }
        }
        return null;
    }

    /**
     * Kod z aplikacji albo kod zapasowy dla użytkownika. Kod zapasowy znika po użyciu.
     * @return bool czy kod jest poprawny
     */
    public static function check(array $user, string $input): bool
    {
        $secret = Secret::decrypt((string) ($user['totp_secret'] ?? ''));
        $uid = (int) $user['id'];
        if ($secret !== null && ($step = self::match($secret, $input)) !== null) {
            // Ten sam kod nie przejdzie drugi raz (podejrzany przez ramię albo przechwycony).
            if ((int) (Kv::get('totp-step:' . $uid) ?? 0) >= $step) {
                return false;
            }
            Kv::set('totp-step:' . $uid, $step, 600);
            return true;
        }
        $clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $input) ?? '');
        $codes = json_in((string) ($user['recovery_json'] ?? ''));
        if (strlen($clean) >= 10 && ($i = array_search(hash('sha256', $clean), $codes, true)) !== false) {
            unset($codes[$i]);
            Db::get()->update('users', ['recovery_json' => json_out(array_values($codes))], 'id = ?', [$uid]);
            return true;
        }
        return false;
    }

    /**
     * Nowe kody zapasowe. Zwraca kody do jednorazowego pokazania, w bazie zostają skróty.
     * @return string[]
     */
    public static function newRecoveryCodes(int $userId): array
    {
        $plain = [];
        $hashes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $c = substr(strtolower(self::base32(random_bytes(8))), 0, 10);
            $plain[] = substr($c, 0, 5) . '-' . substr($c, 5);
            $hashes[] = hash('sha256', $c);
        }
        Db::get()->update('users', ['recovery_json' => json_out($hashes)], 'id = ?', [$userId]);
        return $plain;
    }

    /** Adres do zeskanowania w aplikacji z kodami. */
    public static function uri(string $secret, string $email, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $email) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=' . self::PERIOD;
    }

    public static function base32(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $b) {
            $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function unbase32(string $text): string
    {
        $bits = '';
        foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $text) ?? '')) as $c) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
