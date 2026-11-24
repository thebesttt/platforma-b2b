<?php

declare(strict_types=1);

namespace App\Modules\Seo;

use App\Core\Config;
use App\Core\Http;
use App\Core\Kv;
use App\Core\Secret;

/**
 * Połączenie własnego konta Google firmy (OAuth 2.0), żeby panel czytał jej Search Console i Google Analytics.
 *
 * Każda firma łączy swoje konto, więc widzi tylko to, do czego to konto ma dostęp. Dlatego to bezpieczna droga dla wielu firm:
 * nie da się dopisać cudzej domeny i przeczytać jej danych. Panel prosi wyłącznie o odczyt (webmasters.readonly, analytics.readonly).
 * Stały dostęp (refresh token) leży w bazie zaszyfrowany kluczem z APP_SECRET, a firma cofa go jednym kliknięciem.
 *
 * Administrator panelu zakłada raz klienta OAuth w Google Cloud i wpisuje go w GOOGLE_OAUTH_CLIENT_ID i GOOGLE_OAUTH_CLIENT_SECRET.
 * Wywołania są napisane według dokumentacji Google (OAuth 2.0 dla aplikacji serwerowych) i sprawdzone na odpowiedziach w jej formacie.
 */
final class GoogleOAuth
{
    public const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public const CALLBACK = '/audyt/widocznosc/google/powrot';

    private const SECRET = 'google';

    /** Ile sekund ma użytkownik na przejście przez ekran zgody Google. */
    private const STATE_TTL = 900;

    public static function enabled(): bool
    {
        return trim((string) Config::get('GOOGLE_OAUTH_CLIENT_ID')) !== '' && trim((string) Config::get('GOOGLE_OAUTH_CLIENT_SECRET')) !== '' && Secret::ready();
    }

    public static function connected(int $orgId): bool
    {
        return $orgId > 0 && Secret::meta($orgId, self::SECRET) !== null;
    }

    /** Opis połączenia bez tokenu: kiedy, kto i do czego dał dostęp. */
    public static function info(int $orgId): ?array
    {
        return $orgId > 0 ? Secret::meta($orgId, self::SECRET) : null;
    }

    public static function redirectUri(string $base): string
    {
        return rtrim($base, '/') . self::CALLBACK;
    }

    /**
     * Początek łączenia: zapisuje w sesji jednorazowy znacznik i zwraca adres ekranu zgody Google.
     * Znacznik wiąże powrót z Google z tą samą przeglądarką i firmą, więc cudzego konta nie da się podłożyć linkiem.
     */
    public static function start(string $base, int $orgId, int $siteId): string
    {
        $state = bin2hex(random_bytes(24));
        $_SESSION['google_oauth'] = ['state' => $state, 'org' => $orgId, 'site' => $siteId, 'at' => time()];
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => trim((string) Config::get('GOOGLE_OAUTH_CLIENT_ID')),
            'redirect_uri' => self::redirectUri($base),
            'response_type' => 'code',
            'scope' => Google::SCOPES,
            // „offline” i „consent” razem dają stały dostęp, którego panel potrzebuje do codziennego pobierania.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    /**
     * Powrót z Google: sprawdza znacznik, wymienia kod na tokeny i zapisuje stały dostęp firmy.
     * @return array{ok: bool, site: int, error?: string, analytics?: bool}
     */
    public static function finish(int $orgId, int $userId, string $userName, string $state, string $code, string $base): array
    {
        $pending = $_SESSION['google_oauth'] ?? null;
        unset($_SESSION['google_oauth']);
        $site = is_array($pending) ? (int) ($pending['site'] ?? 0) : 0;
        if (!is_array($pending) || $state === '' || !hash_equals((string) ($pending['state'] ?? ''), $state)
            || (int) ($pending['org'] ?? 0) !== $orgId || time() - (int) ($pending['at'] ?? 0) > self::STATE_TTL) {
            return ['ok' => false, 'site' => $site, 'error' => 'Łączenie z Google wygasło albo zaczęło się w innym oknie. Kliknij „Połącz z Google” jeszcze raz.'];
        }
        if ($code === '') {
            return ['ok' => false, 'site' => $site, 'error' => 'Google nie przekazał zgody. Kliknij „Połącz z Google” i zaakceptuj dostęp do odczytu.'];
        }
        $r = Http::request('POST', Google::TOKEN_URL, http_build_query([
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::redirectUri($base),
            'client_id' => trim((string) Config::get('GOOGLE_OAUTH_CLIENT_ID')), 'client_secret' => trim((string) Config::get('GOOGLE_OAUTH_CLIENT_SECRET')),
        ]), ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json']);
        $j = json_decode((string) $r['body'], true);
        if ($r['status'] !== 200 || !is_array($j) || empty($j['access_token'])) {
            $why = is_array($j) ? (string) ($j['error'] ?? '') : '';
            return ['ok' => false, 'site' => $site, 'error' => match (true) {
                $r['status'] === 0 => 'Brak połączenia z Google. Spróbuj ponownie za chwilę.',
                in_array($why, ['invalid_client', 'unauthorized_client', 'redirect_uri_mismatch'], true)
                    => 'Google odrzucił ustawienia połączenia tego panelu (' . $why . '). To poprawia administrator panelu w Google Cloud.',
                default => 'Google nie potwierdził połączenia. Kliknij „Połącz z Google” jeszcze raz.',
            }];
        }
        $scope = (string) ($j['scope'] ?? '');
        if (!str_contains($scope, 'webmasters.readonly')) {
            return ['ok' => false, 'site' => $site, 'error' => 'Na ekranie Google nie zaznaczono dostępu do Search Console. Połącz jeszcze raz i zaznacz zgodę na odczyt Search Console.'];
        }
        if (empty($j['refresh_token'])) {
            return ['ok' => false, 'site' => $site, 'error' => 'Google nie przekazał stałego dostępu. Kliknij „Połącz z Google” jeszcze raz.'];
        }
        $analytics = str_contains($scope, 'analytics.readonly');
        Secret::put($orgId, self::SECRET, (string) $j['refresh_token'], ['by' => mb_substr($userName, 0, 120), 'analytics' => $analytics], $userId);
        self::cache($orgId, (string) $j['access_token'], (int) ($j['expires_in'] ?? 3600));
        return ['ok' => true, 'site' => $site, 'analytics' => $analytics];
    }

    /**
     * Token dostępu firmy: z pamięci podręcznej albo odświeżony stałym dostępem.
     * @return array{ok: bool, token?: string, error?: string}
     */
    public static function token(int $orgId): array
    {
        $cached = Secret::decrypt(is_string($c = Kv::get(self::cacheKey($orgId))) ? $c : null);
        if ($cached !== null && $cached !== '') {
            return ['ok' => true, 'token' => $cached];
        }
        $refresh = Secret::get($orgId, self::SECRET);
        if ($refresh === null || $refresh === '') {
            return ['ok' => false, 'error' => 'Konto Google nie jest połączone. Połącz je w zakładce „Dane”.'];
        }
        $r = Http::request('POST', Google::TOKEN_URL, http_build_query([
            'grant_type' => 'refresh_token', 'refresh_token' => $refresh,
            'client_id' => trim((string) Config::get('GOOGLE_OAUTH_CLIENT_ID')), 'client_secret' => trim((string) Config::get('GOOGLE_OAUTH_CLIENT_SECRET')),
        ]), ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json']);
        $j = json_decode((string) $r['body'], true);
        if ($r['status'] === 200 && is_array($j) && !empty($j['access_token'])) {
            self::cache($orgId, (string) $j['access_token'], (int) ($j['expires_in'] ?? 3600));
            return ['ok' => true, 'token' => (string) $j['access_token']];
        }
        if (is_array($j) && ($j['error'] ?? '') === 'invalid_grant') {
            // Dostęp cofnięty w koncie Google albo wygasły: nieważnego tokenu nie trzymamy.
            self::forget($orgId);
            return ['ok' => false, 'error' => 'Połączenie z Google wygasło albo zostało cofnięte w koncie Google. Połącz konto ponownie w zakładce „Dane”.'];
        }
        return ['ok' => false, 'error' => $r['status'] === 0 ? 'Brak połączenia z Google. Spróbuj ponownie za chwilę.' : 'Google nie odnowił dostępu (kod ' . (int) $r['status'] . '). Spróbuj ponownie za chwilę.'];
    }

    /** Odłączenie: cofamy dostęp po stronie Google (jeśli się uda) i usuwamy go z panelu. */
    public static function disconnect(int $orgId): void
    {
        $refresh = Secret::get($orgId, self::SECRET);
        if ($refresh !== null && $refresh !== '') {
            try {
                Http::request('POST', self::REVOKE_URL, http_build_query(['token' => $refresh]), ['Content-Type' => 'application/x-www-form-urlencoded'], 10);
            } catch (\Throwable) {
                // Brak odpowiedzi Google nie blokuje odłączenia w panelu.
            }
        }
        self::forget($orgId);
    }

    /** Po odrzuceniu tokenu przez Google: kolejne pobranie odświeży dostęp. */
    public static function dropCached(int $orgId): void
    {
        Kv::forget(self::cacheKey($orgId));
    }

    private static function forget(int $orgId): void
    {
        Secret::put($orgId, self::SECRET, '');
        Kv::forget(self::cacheKey($orgId));
    }

    private static function cache(int $orgId, string $access, int $expires): void
    {
        // Token dostępu żyje godzinę, ale i tak leży w bazie zaszyfrowany.
        Kv::set(self::cacheKey($orgId), Secret::encrypt($access), max(60, $expires - 120));
    }

    private static function cacheKey(int $orgId): string
    {
        return 'google:oauth:' . $orgId;
    }
}
