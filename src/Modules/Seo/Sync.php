<?php

declare(strict_types=1);

namespace App\Modules\Seo;

use App\Core\Db;

/**
 * Pobieranie danych o widoczności: na żądanie (przycisk w panelu) i raz dziennie dla witryn, które są już połączone.
 * Każde źródło zapisuje, kiedy i z jakim skutkiem pobrało dane, żeby ekran „Dane” mówił wprost, co działa.
 */
final class Sync
{
    /** Witryn na jedno uruchomienie zadania dziennego. */
    private const DAILY_SITES = 40;

    /**
     * Czy firma może korzystać z kluczy platformy (konto usługi Google, token Cloudflare).
     *
     * Klucz jest jeden na cały panel i widzi wszystko, do czego ktokolwiek dał mu dostęp. Gdyby korzystała z niego każda firma,
     * jedna mogłaby dopisać cudzą domenę i przeczytać jej dane. Dlatego klucze platformy działają tylko w firmie administratora.
     * Pozostałe firmy łączą własne konto Google i własny token Cloudflare albo wgrywają pliki z eksportu.
     */
    public static function allowed(int $orgId): bool
    {
        return \App\Core\Plans::isOperator($orgId);
    }

    /** Czy panel może pobierać dane z Google dla tej firmy: z jej połączonego konta albo, w firmie prowadzącej panel, kluczem platformy. */
    public static function googleOn(int $orgId): bool
    {
        return Google::mode($orgId) !== null;
    }

    /** Czy panel może pobierać dane z Cloudflare dla tej firmy: jej własnym tokenem albo, w firmie prowadzącej panel, tokenem platformy. */
    public static function cloudflareOn(int $orgId): bool
    {
        return CloudflareBots::tokenFor($orgId) !== '';
    }

    /**
     * Google: Search Console, a potem Google Analytics, jeśli konto usługi ma do niego dostęp.
     * Brak dostępu do Analytics nie jest błędem połączenia: to dane dodatkowe.
     * @return array{ok: bool, messages: string[], errors: string[]}
     */
    public static function google(array $site): array
    {
        $messages = [];
        $errors = [];
        $gsc = Google::syncGsc($site);
        Sites::markSync($site, 'google', $gsc['ok'], $gsc['ok'] ? $gsc['queries'] . ' zapytań, ' . $gsc['pages'] . ' stron' : (string) $gsc['error']);
        if ($gsc['ok']) {
            $messages[] = 'Search Console: pobrane dane z okresu ' . self::range($gsc['start'], $gsc['end']) . ' (' . $gsc['queries'] . ' zapytań, ' . $gsc['pages'] . ' stron) i 28 dni wcześniej.';
        } else {
            $errors[] = (string) $gsc['error'];
        }
        // Analytics pobieramy także wtedy, gdy Search Console się nie udał: to osobne uprawnienie.
        if (Google::tokenFor((int) $site['org_id'])['ok']) {
            $fresh = Sites::find((int) $site['org_id'], (int) $site['id']) ?? $site;
            $ga = Google::syncGa4($fresh);
            Sites::markSync($site, 'ga4', $ga['ok'], $ga['ok'] ? $ga['sessions'] . ' sesji z asystentów AI' : (string) $ga['error']);
            if ($ga['ok']) {
                $messages[] = 'Google Analytics: ' . $ga['sessions'] . ' ' . plural((int) $ga['sessions'], 'sesja', 'sesje', 'sesji') . ' z asystentów AI w okresie ' . self::range($ga['start'], $ga['end']) . '.';
            }
        }
        return ['ok' => $gsc['ok'], 'messages' => $messages, 'errors' => $errors];
    }

    /** @return array{ok: bool, messages: string[], errors: string[]} */
    public static function cloudflare(array $site): array
    {
        $res = CloudflareBots::sync($site);
        Sites::markSync($site, 'cloudflare', $res['ok'], $res['ok'] ? $res['requests'] . ' pobrań, ' . $res['bots'] . ' botów' : (string) $res['error']);
        return $res['ok']
            ? ['ok' => true, 'messages' => ['Cloudflare: ' . $res['requests'] . ' pobrań stron przez ' . $res['bots'] . ' ' . plural((int) $res['bots'], 'bota AI', 'boty AI', 'botów AI') . ' w ostatnich 28 dniach.'], 'errors' => []]
            : ['ok' => false, 'messages' => [], 'errors' => [(string) $res['error']]];
    }

    /**
     * Zadanie dzienne: witryny, które użytkownik już połączył (poprzednie pobranie się udało), i mapy witryn konkurentów.
     * @return array{sites: int, google: int, cloudflare: int, maps: int, errors: int}
     */
    public static function daily(): array
    {
        $out = ['sites' => 0, 'google' => 0, 'cloudflare' => 0, 'maps' => 0, 'errors' => 0];
        $on = [];
        foreach (Db::get()->all('SELECT * FROM seo_sites ORDER BY id LIMIT ' . self::DAILY_SITES) as $site) {
            $out['sites']++;
            $org = (int) $site['org_id'];
            // Każda firma ma własne połączenia, więc sprawdzamy je raz na firmę.
            $on[$org] ??= ['google' => self::googleOn($org), 'cloudflare' => self::cloudflareOn($org)];
            try {
                if ($on[$org]['google'] && (Sites::lastSync($site, 'google')['ok'] ?? false)) {
                    $res = self::google($site);
                    $out[$res['ok'] ? 'google' : 'errors']++;
                }
                if ($on[$org]['cloudflare'] && (Sites::lastSync($site, 'cloudflare')['ok'] ?? false)) {
                    $res = self::cloudflare($site);
                    $out[$res['ok'] ? 'cloudflare' : 'errors']++;
                }
                if (Sites::rivals($site)) {
                    foreach (Rivals::refreshAll($site) as $res) {
                        $out[$res['ok'] ? 'maps' : 'errors']++;
                    }
                }
            } catch (\Throwable $e) {
                $out['errors']++;
            }
        }
        return $out;
    }

    public static function range(string $from, string $to): string
    {
        return date('d.m', strtotime($from)) . '-' . date('d.m.Y', strtotime($to));
    }
}
