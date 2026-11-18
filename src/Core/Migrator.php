<?php

declare(strict_types=1);

namespace App\Core;

/** Uruchamia pliki migrations/NNN_nazwa.php w kolejności, każdy raz. */
final class Migrator
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return string[] lista zastosowanych migracji */
    public function migrate(): array
    {
        $this->db->run('CREATE TABLE IF NOT EXISTS schema_migrations (name TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');
        $done = array_column($this->db->all('SELECT name FROM schema_migrations'), 'name');
        $files = glob(APP_ROOT . '/migrations/*.php') ?: [];
        sort($files);
        $applied = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (in_array($name, $done, true)) {
                continue;
            }
            $schema = new Schema($this->db->driver());
            (require $file)($schema);
            $this->db->transaction(function (Db $db) use ($schema, $name): void {
                foreach ($schema->statements() as $sql) {
                    $db->pdo()->exec($sql);
                }
                $db->run('INSERT INTO schema_migrations (name, applied_at) VALUES (?, ?)', [$name, now_utc()]);
            });
            $applied[] = $name;
        }
        return $applied;
    }
}
