<?php

declare(strict_types=1);

namespace App\Modules\Content;

use App\Core\Db;
use App\Modules\Ai\Claude;

/**
 * Audyt treści w bazie: strony z frazą, wykonania audytu krok po kroku, wyniki wskaźników, porównanie i link dla klienta.
 * Pomiar i punktacja są w klasach Measure i Checks. Tu jest tylko zapis, odczyt i izolacja danych między firmami.
 */
final class ContentAudit
{
    public const LINK_DAYS = 90;

    // --- Strony ------------------------------------------------------------------------------------------------------

    /** @return array{ok: bool, id?: int, error?: string} */
    public static function addTarget(int $orgId, int $userId, string $url, string $phrase, string $pageType, ?int $contactId = null): array
    {
        $url = trim($url);
        // Wyszukiwarki nie rozróżniają wielkości liter we frazie, więc zapisujemy ją małymi literami.
        $phrase = mb_strtolower(Text::normalize($phrase));
        if ($url === '' || mb_strlen($url) > 500) {
            return ['ok' => false, 'error' => 'Podaj adres strony, np. twojafirma.pl/oferta.'];
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        $host = Fetcher::host($url);
        if ($host === '' || !preg_match('/^[a-z0-9.\-]+\.[a-z]{2,}$/', $host) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'error' => 'To nie wygląda na adres strony. Wpisz go tak, jak w przeglądarce.'];
        }
        if (mb_strlen($phrase) < 3 || mb_strlen($phrase) > 120 || !Text::content($phrase)) {
            return ['ok' => false, 'error' => 'Podaj frazę, na którą strona ma odpowiadać, np. „jaki ekspres ciśnieniowy do domu”. Od 3 do 120 znaków.'];
        }
        if (!isset(Ruleset::pageTypes()[$pageType])) {
            return ['ok' => false, 'error' => 'Wybierz rodzaj strony.'];
        }
        $db = Db::get();
        if ($contactId && !$db->val('SELECT 1 FROM contacts WHERE org_id = ? AND id = ?', [$orgId, $contactId])) {
            $contactId = null;
        }
        $existing = $db->val('SELECT id FROM content_targets WHERE org_id = ? AND url = ? AND phrase = ?', [$orgId, $url, $phrase]);
        if ($existing) {
            return ['ok' => true, 'id' => (int) $existing, 'existing' => true];
        }
        if ((int) $db->val('SELECT COUNT(*) FROM content_targets WHERE org_id = ?', [$orgId]) >= 500) {
            return ['ok' => false, 'error' => 'Osiągnięto limit 500 stron. Usuń strony, których już nie sprawdzasz.'];
        }
        $id = $db->insert('content_targets', [
            'org_id' => $orgId, 'contact_id' => $contactId, 'url' => $url, 'host' => $host, 'phrase' => $phrase, 'page_type' => $pageType,
            'created_by' => $userId ?: null, 'created_at' => now_utc(),
        ]);
        return ['ok' => true, 'id' => $id];
    }

    public static function target(int $orgId, int $id): ?array
    {
        return Db::get()->one('SELECT * FROM content_targets WHERE org_id = ? AND id = ?', [$orgId, $id]);
    }

    /** Strony firmy z ostatnim zakończonym audytem. */
    public static function targets(int $orgId, ?int $contactId = null): array
    {
        $sql = 'SELECT t.*, a.id AS audit_id, a.score, a.blocked, a.finished_at, a.title, a.ruleset,
                    (SELECT COUNT(*) FROM content_audits c WHERE c.target_id = t.id AND c.status = \'done\') AS audits,
                    (SELECT MIN(c.id) FROM content_audits c WHERE c.target_id = t.id AND c.status IN (\'queued\', \'running\')) AS open_audit
                FROM content_targets t
                LEFT JOIN content_audits a ON a.id = (SELECT MAX(x.id) FROM content_audits x WHERE x.target_id = t.id AND x.status = \'done\')
                WHERE t.org_id = ?';
        $params = [$orgId];
        if ($contactId) {
            $sql .= ' AND t.contact_id = ?';
            $params[] = $contactId;
        }
        return Db::get()->all($sql . ' ORDER BY t.host, t.id LIMIT 500', $params);
    }

    public static function deleteTarget(int $orgId, int $id): void
    {
        $db = Db::get();
        $db->transaction(static function (Db $db) use ($orgId, $id): void {
            $db->run('DELETE FROM content_audit_checks WHERE org_id = ? AND audit_id IN (SELECT id FROM content_audits WHERE org_id = ? AND target_id = ?)', [$orgId, $orgId, $id]);
            $db->run('DELETE FROM content_audits WHERE org_id = ? AND target_id = ?', [$orgId, $id]);
            $db->run('DELETE FROM content_audit_shares WHERE org_id = ? AND target_id = ?', [$orgId, $id]);
            $db->run('DELETE FROM content_targets WHERE org_id = ? AND id = ?', [$orgId, $id]);
        });
    }

    // --- Pytania poboczne (wskaźnik D2) ------------------------------------------------------------------------------

    /** @return string[] */
    public static function questions(array $target): array
    {
        return array_values(array_filter(json_in($target['questions_json'] ?? null), 'is_string'));
    }

    /** Zapis listy pytań. Zatwierdzonej listy nie zmieniamy, bo wyniki kolejnych audytów przestałyby być porównywalne. */
    public static function saveQuestions(array $target, array $lines, bool $lock): array
    {
        if (!empty($target['questions_locked_at'])) {
            return ['ok' => false, 'error' => 'Lista pytań jest już zatwierdzona i zostaje bez zmian, żeby audyty dało się porównać.'];
        }
        $qs = AiChecks::cleanQuestions($lines);
        if ($lock && count($qs) < 5) {
            return ['ok' => false, 'error' => 'Do zatwierdzenia potrzeba co najmniej 5 pytań (najlepiej 10), każde w osobnym wierszu.'];
        }
        Db::get()->update('content_targets', ['questions_json' => json_out($qs), 'questions_locked_at' => $lock ? now_utc() : null],
            'id = ? AND org_id = ?', [(int) $target['id'], (int) $target['org_id']]);
        return ['ok' => true, 'count' => count($qs)];
    }

    /** Propozycja pytań od modelu AI. Zapisuje ją jako wersję roboczą do poprawienia i zatwierdzenia. */
    public static function proposeQuestions(array $target): array
    {
        if (!Claude::enabled()) {
            return ['ok' => false, 'error' => 'Propozycja pytań wymaga klucza ANTHROPIC_API_KEY. Możesz wpisać pytania ręcznie.'];
        }
        $title = (string) Db::get()->val("SELECT title FROM content_audits WHERE target_id = ? AND status = 'done' ORDER BY id DESC LIMIT 1", [(int) $target['id']]);
        $res = AiChecks::propose((string) $target['phrase'], $title);
        return $res['ok'] ? self::saveQuestions($target, $res['questions'], false) : $res;
    }

    // --- Audyty ------------------------------------------------------------------------------------------------------

    public static function audit(int $orgId, int $id): ?array
    {
        return Db::get()->one('SELECT * FROM content_audits WHERE org_id = ? AND id = ?', [$orgId, $id]);
    }

    /** Zakończone audyty strony, od najnowszego. */
    public static function audits(int $orgId, int $targetId): array
    {
        return Db::get()->all("SELECT id, status, score, points, max_points, blocked, label, ruleset, title, words, created_at, finished_at
            FROM content_audits WHERE org_id = ? AND target_id = ? AND status = 'done' ORDER BY id DESC LIMIT 60", [$orgId, $targetId]);
    }

    public static function openAudit(int $orgId, int $targetId): ?array
    {
        return Db::get()->one("SELECT * FROM content_audits WHERE org_id = ? AND target_id = ? AND status IN ('queued', 'running') ORDER BY id LIMIT 1", [$orgId, $targetId]);
    }

    /** Nowy audyt w kolejce. Jeśli jeden już trwa, zwracamy ten sam. */
    public static function start(array $target, int $userId, string $label = ''): int
    {
        $db = Db::get();
        $org = (int) $target['org_id'];
        if ($open = self::openAudit($org, (int) $target['id'])) {
            return (int) $open['id'];
        }
        $questions = !empty($target['questions_locked_at']) ? self::questions($target) : [];
        $state = Measure::start((string) $target['url'], (string) $target['phrase'], (string) $target['page_type'], [
            'questions' => $questions, 'siblings' => self::siblings($target), 'previous' => self::previous($org, (int) $target['id']),
        ]);
        return $db->insert('content_audits', [
            'org_id' => $org, 'target_id' => (int) $target['id'], 'status' => 'queued',
            'steps_json' => json_out(array_fill_keys(array_keys(Measure::STEPS), 'pending')), 'ruleset' => Ruleset::version(),
            'label' => mb_substr(Text::normalize($label), 0, 60) ?: null, 'state_json' => json_out($state),
            'created_by' => $userId ?: null, 'created_at' => now_utc(),
        ]);
    }

    /** Inne sprawdzone strony tej samej witryny: do wykrywania powtórzonych tytułów i opisów. */
    private static function siblings(array $target): array
    {
        $rows = Db::get()->all("SELECT t.url, a.title, a.state_json FROM content_targets t
            JOIN content_audits a ON a.id = (SELECT MAX(x.id) FROM content_audits x WHERE x.target_id = t.id AND x.status = 'done')
            WHERE t.org_id = ? AND t.host = ? AND t.url <> ? LIMIT 100", [(int) $target['org_id'], (string) $target['host'], (string) $target['url']]);
        return array_map(static fn ($r) => ['url' => $r['url'], 'title' => (string) $r['title'], 'description' => (string) (json_in($r['state_json'])['description'] ?? '')], $rows);
    }

    /** Dane poprzedniego audytu potrzebne w kolejnym: skróty treści, widoczne daty i oceny AI. */
    private static function previous(int $orgId, int $targetId): ?array
    {
        $row = Db::get()->one("SELECT state_json, text_hash FROM content_audits WHERE org_id = ? AND target_id = ? AND status = 'done' ORDER BY id DESC LIMIT 1", [$orgId, $targetId]);
        if (!$row) {
            return null;
        }
        $st = json_in($row['state_json']);
        return ['text_hash' => $row['text_hash'], 'lead_hash' => $st['lead_hash'] ?? null, 'questions_hash' => $st['questions_hash'] ?? null, 'dates' => $st['dates'] ?? [], 'ai' => $st['ai'] ?? []];
    }

    /**
     * Wykonuje następny krok audytu.
     * @return array{done: bool, failed: bool, step: ?string, steps: array<string, string>, error?: string}
     */
    public static function advance(int $orgId, int $auditId): array
    {
        $db = Db::get();
        $a = self::audit($orgId, $auditId);
        if (!$a) {
            return ['done' => true, 'failed' => true, 'step' => null, 'steps' => [], 'error' => 'Nie ma takiego audytu.'];
        }
        $steps = json_in($a['steps_json']);
        if (in_array($a['status'], ['done', 'failed'], true)) {
            return ['done' => true, 'failed' => $a['status'] === 'failed', 'step' => null, 'steps' => $steps] + ($a['error'] ? ['error' => (string) $a['error']] : []);
        }
        $name = array_search('pending', $steps, true);
        if ($name === false) {
            $name = 'score';
        }
        $state = json_in($a['state_json']);
        try {
            // Ocena AI (D1, D2) liczy się do przydziału firmy także wtedy, gdy audyt kończy zadanie dzienne, bez zalogowanej osoby.
            $state = \App\Modules\Ai\Claude::as($orgId, 'ops', static fn () => Measure::step((string) $name, $state));
            $steps[$name] = 'done';
        } catch (\Throwable $e) {
            error_log('[content] audyt ' . $auditId . ', krok ' . $name . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            if ($name === 'fetch' || $name === 'score') {
                $db->update('content_audits', ['status' => 'failed', 'steps_json' => json_out([$name => 'failed'] + $steps), 'error' => 'Audyt przerwany na kroku „' . Measure::STEPS[$name] . '”. Spróbuj ponownie.', 'finished_at' => now_utc()], 'id = ?', [$auditId]);
                return ['done' => true, 'failed' => true, 'step' => (string) $name, 'steps' => [$name => 'failed'] + $steps, 'error' => 'Audyt przerwany. Spróbuj ponownie.'];
            }
            // Krok pomocniczy (boty, render, szybkość, AI) może zawieść: wskaźnik dostanie „brak pomiaru”, a audyt idzie dalej.
            $steps[$name] = 'failed';
        }
        if ($name !== 'score') {
            $db->update('content_audits', ['status' => 'running', 'steps_json' => json_out($steps), 'state_json' => json_out($state)], 'id = ?', [$auditId]);
            return ['done' => false, 'failed' => false, 'step' => (string) $name, 'steps' => $steps];
        }
        self::finish($a, $state, $steps);
        return ['done' => true, 'failed' => false, 'step' => 'score', 'steps' => $steps];
    }

    /** Wszystkie pozostałe kroki naraz (zadanie cykliczne, konsola, testy, przeglądarka bez JavaScript). */
    public static function runAll(int $orgId, int $auditId): array
    {
        do {
            $r = self::advance($orgId, $auditId);
        } while (!$r['done']);
        return $r;
    }

    private static function finish(array $a, array $state, array $steps): void
    {
        $db = Db::get();
        $t = $state['totals'];
        $facts = $state['facts'] ?? [];
        // Po zakończeniu zostawiamy tylko to, czego potrzebują porównanie i następny audyt. Treści strony nie przechowujemy.
        $keep = [
            'final_url' => $state['final_url'] ?? null, 'description' => $facts['description'] ?? null, 'dates' => $facts['dates'] ?? [],
            'lead_hash' => $state['lead_hash'] ?? null, 'questions_hash' => $state['questions_hash'] ?? null,
            'ai' => array_map(static fn ($r) => array_diff_key($r, ['reused' => 1]), $state['ai'] ?? []),
            'questions' => $state['questions'] ?? [], 'page_type' => $state['page_type'] ?? 'other', 'phrase' => $state['phrase'] ?? '',
            'groups' => $t['groups'], 'unmeasured' => $t['unmeasured'], 'blocked' => $t['blocked'],
        ];
        $db->transaction(static function (Db $db) use ($a, $state, $steps, $t, $facts, $keep): void {
            $db->run('DELETE FROM content_audit_checks WHERE audit_id = ?', [(int) $a['id']]);
            foreach ($state['results'] as $code => $r) {
                $db->insert('content_audit_checks', [
                    'org_id' => (int) $a['org_id'], 'audit_id' => (int) $a['id'], 'code' => $code, 'status' => $r['status'], 'points' => (int) $r['points'], 'max_points' => (int) $r['max'],
                    'value' => (string) $r['value'], 'evidence_json' => json_out(['text' => (string) ($r['evidence'] ?? '')] + (isset($r['answers']) ? ['answers' => $r['answers']] : []) + (!empty($r['reused']) ? ['reused' => true] : [])),
                ]);
            }
            $db->update('content_audits', [
                'status' => 'done', 'steps_json' => json_out($steps), 'score' => $t['score'], 'points' => $t['points'], 'max_points' => $t['max'], 'blocked' => $t['blocked'] ? 1 : 0,
                'title' => mb_substr((string) ($facts['title'] ?? ''), 0, 300) ?: null, 'words' => (int) ($facts['words'] ?? 0), 'text_hash' => $state['text_hash'] ?? null,
                'state_json' => json_out($keep), 'finished_at' => now_utc(),
            ], 'id = ?', [(int) $a['id']]);
        });
    }

    /** @return array<string, array{status: string, points: int, max: int, value: string, evidence: string, answers?: array, reused?: bool}> */
    public static function checks(int $orgId, int $auditId): array
    {
        $out = [];
        foreach (Db::get()->all('SELECT * FROM content_audit_checks WHERE org_id = ? AND audit_id = ?', [$orgId, $auditId]) as $c) {
            $ev = json_in($c['evidence_json']);
            $out[$c['code']] = ['status' => $c['status'], 'points' => (int) $c['points'], 'max' => (int) $c['max_points'], 'value' => (string) $c['value'], 'evidence' => (string) ($ev['text'] ?? '')]
                + (isset($ev['answers']) ? ['answers' => $ev['answers']] : []) + (!empty($ev['reused']) ? ['reused' => true] : []);
        }
        $ordered = [];
        foreach (array_keys(Ruleset::rules()) as $code) {
            if (isset($out[$code])) {
                $ordered[$code] = $out[$code];
            }
        }
        return $ordered;
    }

    /** Audyty, które ktoś zaczął i nie dokończył (zamknięta karta przeglądarki): dokańcza je zadanie cykliczne. */
    public static function runPending(int $limit = 10): int
    {
        $n = 0;
        $old = gmdate('Y-m-d H:i:s', time() - 600);
        foreach (Db::get()->all("SELECT id, org_id FROM content_audits WHERE status IN ('queued', 'running') AND created_at < ? ORDER BY id LIMIT " . max(1, $limit), [$old]) as $a) {
            self::runAll((int) $a['org_id'], (int) $a['id']);
            $n++;
        }
        return $n;
    }

    // --- Link dla klienta --------------------------------------------------------------------------------------------

    /** Tworzy link do raportu. Token widać tylko raz, w bazie zostaje jego skrót. */
    public static function share(array $target, int $userId): string
    {
        $token = bin2hex(random_bytes(20));
        Db::get()->insert('content_audit_shares', [
            'org_id' => (int) $target['org_id'], 'target_id' => (int) $target['id'], 'token_hash' => hash('sha256', $token), 'created_by' => $userId ?: null,
            'created_at' => now_utc(), 'expires_at' => gmdate('Y-m-d H:i:s', time() + self::LINK_DAYS * 86400),
        ]);
        return $token;
    }

    public static function shares(int $orgId, int $targetId): array
    {
        return Db::get()->all('SELECT id, created_at, expires_at, revoked_at FROM content_audit_shares WHERE org_id = ? AND target_id = ? AND revoked_at IS NULL AND expires_at > ? ORDER BY id DESC', [$orgId, $targetId, now_utc()]);
    }

    public static function revokeShares(int $orgId, int $targetId): int
    {
        return Db::get()->update('content_audit_shares', ['revoked_at' => now_utc()], 'org_id = ? AND target_id = ? AND revoked_at IS NULL', [$orgId, $targetId]);
    }

    /** Strona, do której prowadzi link dla klienta. Null, gdy link jest błędny, wygasł albo został wyłączony. */
    public static function byToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
            return null;
        }
        $s = Db::get()->one('SELECT * FROM content_audit_shares WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > ?', [hash('sha256', $token), now_utc()]);
        return $s ? self::target((int) $s['org_id'], (int) $s['target_id']) : null;
    }
}
