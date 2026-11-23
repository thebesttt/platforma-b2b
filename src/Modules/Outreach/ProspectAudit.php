<?php

declare(strict_types=1);

namespace App\Modules\Outreach;

use App\Core\Db;
use App\Modules\Audit\Audit;

/**
 * Audyt strony firmy z listy potencjalnych klientów.
 *
 * Sam adres e-mail firma zostawia chętniej, gdy dostaje za niego coś konkretnego. Dlatego list może obiecać raport z audytu
 * jej strony: wynik na 100 punktów i lista rzeczy do poprawy. Raport ma publiczny adres z losowym tokenem. Firma dostaje go
 * na stronie zgody i w potwierdzeniu, gdy sama o niego poprosi.
 */
final class ProspectAudit
{
    /** Ile rzeczy do poprawy wymieniamy w liście i w treści oferty. */
    public const TOP = 3;

    /** Uruchamia audyt techniczny strony firmy i przypina go do firmy na liście. @return array{ok: bool, error?: string, id?: int} */
    public static function run(int $orgId, int $userId, array $p): array
    {
        if (empty($p['website'])) {
            return ['ok' => false, 'error' => 'Najpierw wpisz adres strony firmy.'];
        }
        $res = Audit::run($orgId, $userId, (string) $p['website'], false, 'Potencjalny klient: ' . truncate((string) $p['name'], 80));
        if (!$res['ok']) {
            return ['ok' => false, 'error' => (string) ($res['error'] ?? 'Nie udało się sprawdzić strony.')];
        }
        self::attach($orgId, $p, (int) $res['id']);
        return ['ok' => true, 'id' => (int) $res['id']];
    }

    /** Przypina gotowy audyt do firmy z listy i nadaje mu token publicznego raportu. */
    public static function attach(int $orgId, array $p, int $auditId): bool
    {
        $db = Db::get();
        $row = Audit::find($orgId, $auditId);
        if (!$row) {
            return false;
        }
        if (empty($row['share_token'])) {
            $db->update('site_audits', ['share_token' => bin2hex(random_bytes(20))], 'id = ?', [$auditId]);
        }
        $db->update('prospects', ['audit_id' => $auditId, 'updated_at' => now_utc()], 'id = ? AND org_id = ?', [(int) $p['id'], $orgId]);
        Prospects::rescore($db->one('SELECT * FROM prospects WHERE id = ?', [(int) $p['id']]) ?? []);
        return true;
    }

    /** Odpina audyt: list i strona zgody przestają obiecywać raport, a publiczny adres przestaje działać. */
    public static function detach(int $orgId, array $p): void
    {
        $db = Db::get();
        if (!empty($p['audit_id'])) {
            $db->update('site_audits', ['share_token' => null], 'id = ? AND org_id = ?', [(int) $p['audit_id'], $orgId]);
        }
        $db->update('prospects', ['audit_id' => null, 'updated_at' => now_utc()], 'id = ? AND org_id = ?', [(int) $p['id'], $orgId]);
        Prospects::rescore($db->one('SELECT * FROM prospects WHERE id = ?', [(int) $p['id']]) ?? []);
    }

    /**
     * Raport przypięty do firmy: wynik, data, najważniejsze rzeczy do poprawy i publiczny adres.
     * W liście nie wymieniamy ustaleń o bezpieczeństwie. Te firma zobaczy dopiero w raporcie.
     * @return ?array{id: int, host: string, score: int, at: string, issues: int, top: string[], token: string, url: string}
     */
    public static function report(array $p, string $base = ''): ?array
    {
        if (empty($p['audit_id'])) {
            return null;
        }
        $row = Db::get()->one('SELECT * FROM site_audits WHERE id = ? AND org_id = ?', [(int) $p['audit_id'], (int) $p['org_id']]);
        if (!$row || empty($row['share_token'])) {
            return null;
        }
        $v = Audit::view($row);
        $top = [];
        foreach ((array) ($v['issues'] ?? []) as $f) {
            if (empty($f['sensitive']) && trim((string) ($f['title'] ?? '')) !== '' && count($top) < self::TOP) {
                $top[] = rtrim(trim((string) $f['title']), '.');
            }
        }
        return [
            'id' => (int) $row['id'], 'host' => (string) $row['host'], 'score' => (int) $row['score'], 'at' => (string) $row['created_at'],
            'issues' => count((array) ($v['issues'] ?? [])), 'top' => $top, 'token' => (string) $row['share_token'],
            'url' => $base !== '' ? rtrim($base, '/') . '/raport/' . $row['share_token'] : '',
        ];
    }

    /** Zdanie do treści oferty i powodu kontaktu: wynik i najważniejsze rzeczy do poprawy. */
    public static function sentence(array $report): string
    {
        return 'W naszym audycie technicznym uzyskała ' . $report['score'] . ' na 100 punktów.'
            . ($report['top'] ? ' Do poprawy są między innymi: ' . implode(', ', array_map(static fn (string $t): string => '„' . $t . '”', $report['top'])) . '.' : '');
    }

    /** Audyt po tokenie z publicznego adresu, razem z nazwą firmy, która go przygotowała, i firmą z listy. */
    public static function byToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
            return null;
        }
        $db = Db::get();
        $row = $db->one('SELECT a.*, o.name AS org_name FROM site_audits a JOIN organizations o ON o.id = a.org_id WHERE a.share_token = ?', [$token]);
        if (!$row) {
            return null;
        }
        $row['prospect'] = $db->one('SELECT * FROM prospects WHERE org_id = ? AND audit_id = ? ORDER BY id DESC LIMIT 1', [(int) $row['org_id'], (int) $row['id']]);
        return $row;
    }
}
