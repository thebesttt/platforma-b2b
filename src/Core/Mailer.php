<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Poczta wychodząca platformy: powiadomienia o zapytaniach, reset hasła, poranny przegląd.
 * Wysyłka przez API Resend (zmienne RESEND_API_KEY i MAIL_FROM). Każda wiadomość trafia do tabeli outbox,
 * także ta, której nie udało się wysłać, żeby w Ustawieniach było widać, co się stało.
 *
 * To nie jest kanał do ofert: wiadomości do klientów wychodzą ze skrzynki użytkownika (moduł „Klienci i wiadomości”).
 */
final class Mailer
{
    public const API = 'https://api.resend.com/emails';

    /** resend = prawdziwa wysyłka, log = tylko zapis (praca lokalna i testy), none = brak konfiguracji. */
    public static function driver(): string
    {
        if ((string) Config::get('RESEND_API_KEY') !== '' && (string) Config::get('MAIL_FROM') !== '') {
            return 'resend';
        }
        return Config::isDev() ? 'log' : 'none';
    }

    public static function enabled(): bool
    {
        return self::driver() !== 'none';
    }

    /** @return array{ok: bool, status: string, error?: string} */
    public static function send(string $to, string $subject, string $text, ?int $orgId = null, string $kind = ''): array
    {
        $to = mb_strtolower(trim($to));
        // Nagłówki nie mogą zawierać znaków nowej linii (wstrzyknięcie nagłówków).
        $subject = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $subject)), 0, 200);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || $subject === '') {
            return ['ok' => false, 'status' => 'failed', 'error' => 'Nieprawidłowy adres albo pusty temat.'];
        }
        $driver = self::driver();
        $status = 'skipped';
        $error = null;
        if ($driver === 'resend') {
            $r = Http::postJson(self::API, [
                'from' => (string) Config::get('MAIL_FROM'),
                'to' => [$to],
                'subject' => $subject,
                'text' => $text,
            ], ['Authorization' => 'Bearer ' . Config::get('RESEND_API_KEY')], 10);
            if ($r['status'] >= 200 && $r['status'] < 300) {
                $status = 'sent';
            } else {
                $status = 'failed';
                $j = json_decode($r['body'], true);
                $error = truncate((string) (is_array($j) ? ($j['message'] ?? $j['error'] ?? '') : ($r['error'] ?? '')), 300) ?: 'Usługa wysyłkowa zwróciła kod ' . $r['status'] . '.';
            }
        } elseif ($driver === 'log') {
            $status = 'logged';
        } else {
            $error = 'Wysyłka e-maili nie jest skonfigurowana (zmienne RESEND_API_KEY i MAIL_FROM).';
        }
        try {
            Db::get()->insert('outbox', [
                'org_id' => $orgId, 'recipient' => $to, 'subject' => $subject, 'body' => $text,
                'kind' => $kind, 'status' => $status, 'error' => $error, 'created_at' => now_utc(),
            ]);
        } catch (\Throwable $e) {
            error_log('[mail] zapis do outbox: ' . $e->getMessage());
        }
        return ['ok' => in_array($status, ['sent', 'logged'], true), 'status' => $status] + ($error ? ['error' => $error] : []);
    }

    /** Powiadomienie dla wszystkich osób z firmy. @return int liczba wysłanych */
    public static function notifyOrg(int $orgId, string $subject, string $text, string $kind): int
    {
        $n = 0;
        foreach (Db::get()->all('SELECT email FROM users WHERE org_id = ? ORDER BY id', [$orgId]) as $u) {
            if (self::send((string) $u['email'], $subject, $text, $orgId, $kind)['ok']) {
                $n++;
            }
        }
        return $n;
    }
}
