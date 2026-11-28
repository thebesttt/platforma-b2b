<?php

declare(strict_types=1);

namespace App\Modules\Notify;

use App\Core\Db;

/**
 * Powiadomienia z alarmu pomiaru, monitorów i raportów miesięcznych. Trafiają na pulpit i do porannego przeglądu.
 * Alerty o kontrahentach i przetargach mają własną tabelę i zostają bez zmian.
 */
final class Notices
{
    public const KINDS = ['tracking' => 'Pomiar na stronie', 'monitor' => 'Monitor', 'report' => 'Raport miesięczny'];

    public static function add(int $orgId, string $kind, string $level, string $title, string $detail = '', string $url = '', ?int $siteId = null, ?int $monitorId = null): int
    {
        return Db::get()->insert('notices', [
            'org_id' => $orgId,
            'kind' => isset(self::KINDS[$kind]) ? $kind : 'monitor',
            'site_id' => $siteId,
            'monitor_id' => $monitorId,
            'level' => in_array($level, ['info', 'mid', 'high'], true) ? $level : 'info',
            'title' => mb_substr($title, 0, 300),
            'detail' => mb_substr($detail, 0, 2000),
            'url' => $url !== '' ? mb_substr($url, 0, 500) : null,
            'created_at' => now_utc(),
        ]);
    }

    /** @return array<int, array<string, mixed>> najnowsze: nieprzeczytane najpierw */
    public static function recent(int $orgId, int $limit = 6): array
    {
        return Db::get()->all('SELECT * FROM notices WHERE org_id = ? ORDER BY read_at IS NOT NULL, id DESC LIMIT ' . max(1, $limit), [$orgId]);
    }

    public static function unread(int $orgId): int
    {
        return (int) Db::get()->val('SELECT COUNT(*) FROM notices WHERE org_id = ? AND read_at IS NULL', [$orgId]);
    }

    /** @return array<int, array<string, mixed>> od podanego czasu, do porannego przeglądu */
    public static function since(int $orgId, string $since, int $limit = 10): array
    {
        return Db::get()->all('SELECT * FROM notices WHERE org_id = ? AND created_at > ? ORDER BY id DESC LIMIT ' . max(1, $limit), [$orgId, $since]);
    }

    public static function markRead(int $orgId, ?int $id = null): void
    {
        $id === null
            ? Db::get()->run('UPDATE notices SET read_at = ? WHERE org_id = ? AND read_at IS NULL', [now_utc(), $orgId])
            : Db::get()->run('UPDATE notices SET read_at = ? WHERE org_id = ? AND id = ? AND read_at IS NULL', [now_utc(), $orgId, $id]);
    }
}
