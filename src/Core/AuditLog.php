<?php

declare(strict_types=1);

namespace App\Core;

/** Dziennik ważnych działań (decyzje o wysyłce, zmiany w ofertach, logowania). */
final class AuditLog
{
    public static function add(string $action, ?string $entity = null, string|int|null $entityId = null, array $data = [], string $ip = ''): void
    {
        Db::get()->insert('audit_log', [
            'org_id' => Auth::orgId() ?: null,
            'user_id' => Auth::id() ?: null,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId === null ? null : (string) $entityId,
            'data_json' => $data ? json_out($data) : null,
            'ip' => $ip ?: null,
            'created_at' => now_utc(),
        ]);
    }
}
