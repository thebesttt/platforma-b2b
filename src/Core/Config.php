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
        'APP_THEME' => '',             // perfectdata = wygląd PerfectData; puste = standardowy. Firma może to zmienić w Ustawieniach.
        'APP_URL' => '',
        'APP_SECRET' => '',
        'DATABASE_URL' => '',
        'ANTHROPIC_API_KEY' => '',
        'AI_MODEL' => 'claude-sonnet-5-5',
        'AI_MAX_INPUT_CHARS' => '300000', // ok. 100 tys. tokenów dokumentacji na jedno zapytanie
        'AI_MODEL_BASIC' => '',        // tańszy model dla pakietu Podstawowego. Puste: ten sam co AI_MODEL
        'GOOGLE_OAUTH_CLIENT_ID' => '',     // logowanie Google dla firm (zakładka Widoczność): identyfikator klienta OAuth
        'GOOGLE_OAUTH_CLIENT_SECRET' => '', // tajny klucz klienta OAuth
        'SALES_EMAIL' => '',           // adres, na który firmy piszą w sprawie zmiany pakietu
        'CRON_TOKEN' => '',
        'PSI_KEY' => '',
        'CEIDG_TOKEN' => '',          // hurtownia danych CEIDG (biznes.gov.pl): wyszukiwanie jednoosobowych działalności po PKD i regionie
        'CF_ACCOUNT_ID' => '',        // Cloudflare Browser Run: strona po uruchomieniu skryptów (wskaźniki A6 i E2 audytu treści)
        'CF_BROWSER_TOKEN' => '',     // token API z uprawnieniem „Browser Rendering - Edit”
        'CF_ANALYTICS_TOKEN' => '',   // token API z uprawnieniem „Zone - Analytics - Read”: boty AI na witrynie. Bez niego używamy CF_BROWSER_TOKEN
        'GOOGLE_SERVICE_ACCOUNT' => '', // klucz konta usługi Google (JSON albo base64): Search Console API i Google Analytics Data API, tylko odczyt
        'RESEND_API_KEY' => '',
        'MAIL_FROM' => '',
        'CAMPAIGN_FROM' => '',
        'HTTP_FIXTURES' => '',
        'UPLOAD_MAX_MB' => '15',
        'SIGNUP_OPEN' => '0',          // 1 = każdy może założyć konto; 0 = tylko z kodem zaproszenia (SIGNUP_CODE)
        'SIGNUP_CODE' => '',           // kod zaproszenia: /rejestracja?kod=…
        'TERMS_URL' => '',             // adres regulaminu (link przy rejestracji)
        'PRIVACY_URL' => '',           // adres polityki prywatności
        'ADMIN_EMAILS' => '',          // e-maile administratorów platformy (po przecinku)
        'CLIENT_IP_HEADER' => '',      // nagłówek z adresem odwiedzającego. Puste: X-Forwarded-For (Render). REMOTE_ADDR: bez proxy
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
