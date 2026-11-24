<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Pakiety platformy i ich limity.
 *
 * Podstawowy ma mały miesięczny przydział AI, Rozszerzony większy. Funkcje bez AI (rejestry, audyty techniczne, listy,
 * szkice regułowe, import z Search Console) działają w obu bez limitu użyć. Po wyczerpaniu przydziału AI panel wraca do
 * wersji regułowych, niczego nie blokuje.
 * Liczby są wartościami startowymi: administrator platformy zmienia je na ekranie Administracja.
 */
final class Plans
{
    public const PLANS = ['basic' => 'Podstawowy', 'extended' => 'Rozszerzony', 'internal' => 'Bez limitów'];

    /** Limit => [nazwa, opis]. */
    public const LIMITS = [
        'ai_ops' => ['Użycia AI w miesiącu', 'Analiza dokumentów przetargu, szkice ofert i wiadomości od AI, ocena treści w audycie, opis idealnego klienta. Jedno zapytanie do modelu to jedno użycie.'],
        'ai_chat' => ['Odpowiedzi czatu na stronie w miesiącu', 'Odpowiedzi asystenta w widżecie na stronie firmy.'],
        'team' => ['Osoby w zespole', 'Konta z dostępem do panelu firmy.'],
        'sites' => ['Witryny w zakładce Widoczność', 'Strony z danymi z Search Console i konkurentami.'],
    ];

    public const DEFAULTS = [
        'basic' => ['ai_ops' => 30, 'ai_chat' => 100, 'team' => 3, 'sites' => 2],
        'extended' => ['ai_ops' => 400, 'ai_chat' => 1500, 'team' => 15, 'sites' => 20],
    ];

    private const MAX = 1_000_000;

    /**
     * Pakiet firmy. Firma administratora platformy nie ma limitów niezależnie od zapisu w bazie, żeby właściciel panelu
     * nie zablokował sam siebie. Nieznana wartość (np. dawne „trial”) to Podstawowy.
     */
    public static function of(?array $org): string
    {
        if ($org && self::isOperator((int) $org['id'])) {
            return 'internal';
        }
        $plan = (string) ($org['plan'] ?? '');
        return isset(self::PLANS[$plan]) ? $plan : 'basic';
    }

    public static function name(?array $org): string
    {
        return self::PLANS[self::of($org)];
    }

    /** Czy w firmie jest konto administratora platformy (adresy z ADMIN_EMAILS). */
    public static function isOperator(int $orgId): bool
    {
        $admins = array_values(array_filter(array_map(static fn ($e) => mb_strtolower(trim($e)), explode(',', (string) Config::get('ADMIN_EMAILS')))));
        if (!$admins || $orgId <= 0) {
            return false;
        }
        return (bool) Db::get()->val(
            'SELECT 1 FROM users WHERE org_id = ? AND LOWER(email) IN (' . implode(', ', array_fill(0, count($admins), '?')) . ') LIMIT 1',
            [$orgId, ...$admins]
        );
    }

    /** @return array<string, array<string, int>> limity pakietów: wartości startowe z poprawkami administratora */
    public static function limits(): array
    {
        $saved = Kv::get('plans:limits');
        $out = self::DEFAULTS;
        foreach (self::DEFAULTS as $plan => $defaults) {
            foreach ($defaults as $key => $_) {
                if (is_array($saved) && isset($saved[$plan][$key]) && is_numeric($saved[$plan][$key])) {
                    $out[$plan][$key] = max(0, min(self::MAX, (int) $saved[$plan][$key]));
                }
            }
        }
        return $out;
    }

    /**
     * Zapis limitów z formularza administratora ([pakiet][limit] => liczba).
     * @return string[] błędy
     */
    public static function saveLimits(array $in): array
    {
        $errors = [];
        $new = self::limits();
        foreach (self::DEFAULTS as $plan => $defaults) {
            foreach ($defaults as $key => $_) {
                $raw = trim((string) ($in[$plan][$key] ?? ''));
                if ($raw === '') {
                    continue;
                }
                if (!preg_match('/^\d{1,7}$/', $raw) || (int) $raw > self::MAX) {
                    $errors[] = self::PLANS[$plan] . ', ' . mb_strtolower(self::LIMITS[$key][0]) . ': podaj liczbę od 0 do ' . num_pl(self::MAX) . '.';
                    continue;
                }
                $new[$plan][$key] = (int) $raw;
            }
        }
        if (!$errors) {
            Kv::set('plans:limits', $new);
        }
        return $errors;
    }

    /** Limit dla firmy. Null oznacza brak limitu. */
    public static function limit(?array $org, string $key): ?int
    {
        $plan = self::of($org);
        return $plan === 'internal' ? null : (self::limits()[$plan][$key] ?? null);
    }

    public static function month(): string
    {
        return gmdate('Y-m');
    }

    /** @return array<string, int> bieżące zużycie firmy */
    public static function usage(int $orgId): array
    {
        $db = Db::get();
        $ai = ['ops' => 0, 'chat' => 0];
        foreach ($db->all('SELECT kind, calls FROM ai_usage WHERE org_id = ? AND month = ?', [$orgId, self::month()]) as $r) {
            $ai[(string) $r['kind']] = (int) $r['calls'];
        }
        return [
            'ai_ops' => $ai['ops'], 'ai_chat' => $ai['chat'],
            'team' => (int) $db->val('SELECT COUNT(*) FROM users WHERE org_id = ?', [$orgId]),
            'sites' => (int) $db->val('SELECT COUNT(*) FROM seo_sites WHERE org_id = ?', [$orgId]),
        ];
    }

    /** Czy firma może dodać kolejną pozycję liczoną limitem (osobę, witrynę). */
    public static function room(?array $org, string $key): bool
    {
        $limit = self::limit($org, $key);
        return $limit === null || !$org || self::usage((int) $org['id'])[$key] < $limit;
    }

    /** Czy firma ma jeszcze przydział AI w tym miesiącu. $kind: ops albo chat. */
    public static function aiAllowed(int $orgId, string $kind): bool
    {
        $org = Db::get()->one('SELECT id, plan FROM organizations WHERE id = ?', [$orgId]);
        $limit = self::limit($org, $kind === 'chat' ? 'ai_chat' : 'ai_ops');
        if ($limit === null) {
            return true;
        }
        return (int) Db::get()->val('SELECT calls FROM ai_usage WHERE org_id = ? AND month = ? AND kind = ?', [$orgId, self::month(), $kind === 'chat' ? 'chat' : 'ops']) < $limit;
    }

    /** Dopisuje jedno użycie AI. Jedno zapytanie w bazie, żeby dwa równoczesne użycia się nie zgubiły. */
    public static function aiRecord(int $orgId, string $kind, array $usage): void
    {
        $kind = $kind === 'chat' ? 'chat' : 'ops';
        $in = (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['cache_creation_input_tokens'] ?? 0) + (int) ($usage['cache_read_input_tokens'] ?? 0);
        $out = (int) ($usage['output_tokens'] ?? 0);
        Db::get()->run(
            'INSERT INTO ai_usage (org_id, month, kind, calls, input_tokens, output_tokens, updated_at) VALUES (?, ?, ?, 1, ?, ?, ?)
             ON CONFLICT (org_id, month, kind) DO UPDATE SET calls = ai_usage.calls + 1, input_tokens = ai_usage.input_tokens + excluded.input_tokens,
                output_tokens = ai_usage.output_tokens + excluded.output_tokens, updated_at = excluded.updated_at',
            [$orgId, self::month(), $kind, $in, $out, now_utc()]
        );
    }

    /** Komunikat po wyczerpaniu przydziału: co się stało i co dalej. */
    public static function aiLimitMessage(int $orgId): string
    {
        $org = Db::get()->one('SELECT id, plan FROM organizations WHERE id = ?', [$orgId]);
        $next = date('d.m', strtotime(gmdate('Y-m-01', strtotime('+1 month'))));
        return 'Przydział AI w pakiecie ' . self::name($org) . ' na ten miesiąc jest wyczerpany (' . (int) self::limit($org, 'ai_ops') . ' użyć). Wersje bez AI działają dalej. '
            . 'Przydział odnowi się ' . $next . ', a większy jest w pakiecie Rozszerzonym.';
    }

    /** Model AI dla firmy: w pakiecie Podstawowym można wskazać tańszy model zmienną AI_MODEL_BASIC. */
    public static function model(int $orgId): string
    {
        $basic = trim((string) Config::get('AI_MODEL_BASIC'));
        if ($basic !== '' && $orgId > 0 && self::of(Db::get()->one('SELECT id, plan FROM organizations WHERE id = ?', [$orgId])) === 'basic') {
            return $basic;
        }
        return (string) Config::get('AI_MODEL');
    }
}
