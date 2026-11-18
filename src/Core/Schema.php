<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Prosty opis tabel zamieniany na DDL dla PostgreSQL albo SQLite.
 * Typy: id, int, bigint, text, str, money, num, bool, json, ts, date, blob, ref:<tabela>.
 */
final class Schema
{
    /** @var string[] */
    private array $sql = [];

    public function __construct(private readonly string $driver)
    {
    }

    public function table(string $name, array $columns, array $options = []): void
    {
        $defs = [];
        foreach ($columns as $col => $spec) {
            $defs[] = $col . ' ' . $this->type($spec);
        }
        foreach ($options['unique'] ?? [] as $u) {
            $defs[] = 'UNIQUE (' . implode(', ', (array) $u) . ')';
        }
        $this->sql[] = "CREATE TABLE IF NOT EXISTS $name (\n  " . implode(",\n  ", $defs) . "\n)";
        foreach ($options['index'] ?? [] as $idx) {
            $cols = (array) $idx;
            $this->index($name, $cols);
        }
    }

    public function index(string $table, array $cols, bool $unique = false): void
    {
        $iname = 'ix_' . $table . '_' . implode('_', $cols);
        $this->sql[] = sprintf(
            'CREATE %sINDEX IF NOT EXISTS %s ON %s (%s)',
            $unique ? 'UNIQUE ' : '',
            $iname,
            $table,
            implode(', ', $cols)
        );
    }

    public function addColumn(string $table, string $col, string $spec): void
    {
        $this->sql[] = "ALTER TABLE $table ADD COLUMN $col " . $this->type($spec);
    }

    public function raw(string $pg, ?string $sqlite = null): void
    {
        $this->sql[] = $this->driver === 'pgsql' ? $pg : ($sqlite ?? $pg);
    }

    /** @return string[] */
    public function statements(): array
    {
        return $this->sql;
    }

    private function type(string $spec): string
    {
        $pg = $this->driver === 'pgsql';
        $parts = explode(' ', $spec, 2);
        $t = $parts[0];
        $rest = isset($parts[1]) ? ' ' . $parts[1] : '';
        if (str_starts_with($t, 'ref:')) {
            $target = substr($t, 4);
            return ($pg ? 'BIGINT' : 'INTEGER') . " REFERENCES $target(id) ON DELETE CASCADE" . $rest;
        }
        $base = match ($t) {
            'id' => $pg ? 'BIGSERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'int' => 'INTEGER',
            'bigint' => $pg ? 'BIGINT' : 'INTEGER',
            'text', 'str', 'json', 'ts', 'date' => 'TEXT',
            'money', 'num' => $pg ? 'NUMERIC(18,2)' : 'REAL',
            'bool' => $pg ? 'SMALLINT' : 'INTEGER',
            'blob' => $pg ? 'BYTEA' : 'BLOB',
            default => throw new \InvalidArgumentException("Nieznany typ $t"),
        };
        return $base . $rest;
    }
}
