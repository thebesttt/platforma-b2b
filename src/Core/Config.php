<?php

declare(strict_types=1);

namespace App\Core;

/** Konfiguracja wyłącznie ze zmiennych środowiskowych (12-factor). */
final class Config
{
    private static array $overrides = [];

    private const DEFAULTS = [
        'APP_ENV' => 'production',
        'APP_NAME' => '[Nazwa]',
        'APP_URL' => '',
        'APP_SECRET' => '',
        'DATABASE_URL' => '',
        'ANTHROPIC_API_KEY' => '',
        'AI_MODEL' => 'claude-sonnet-5-5',
        'AI_MAX_INPUT_CHARS' => '400000',
        'CRON_TOKEN' => '',
        'PSI_KEY' => '',
        'RESEND_API_KEY' => '',
        'MAIL_FROM' => '',
        'HTTP_FIXTURES' => '',
        'UPLOAD_MAX_MB' => '15',
        'SIGNUP_OPEN' => '1',
        'AUDIT_ALLOW_PRIVATE' => '0', // tylko testy lokalne (działa wyłącznie przy APP_ENV=dev|test)
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$overrides)) {
            return self::$overrides[$key];
        }
        $v = getenv($key);
        if ($v !== false && $v !== '') {
            return $v;
        }
        return self::DEFAULTS[$key] ?? $default;
    }

    /** Tylko do testów. */
    public static function set(string $key, mixed $value): void
    {
        self::$overrides[$key] = $value;
    }

    public static function isDev(): bool
    {
        return in_array(self::get('APP_ENV'), ['dev', 'local', 'test'], true);
    }

    public static function secret(): string
    {
        $s = (string) self::get('APP_SECRET');
        if ($s === '') {
            // Bez sekretu: stały, ale lokalny dla instalacji (tylko do pracy lokalnej).
            $file = APP_ROOT . '/storage/.secret';
            if (!is_file($file)) {
                @mkdir(dirname($file), 0770, true);
                file_put_contents($file, bin2hex(random_bytes(32)));
            }
            $s = trim((string) file_get_contents($file));
        }
        return $s;
    }
}
