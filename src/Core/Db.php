<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Cienka warstwa nad PDO. PostgreSQL na produkcji, SQLite lokalnie i w testach.
 * Zapytania piszemy w SQL wspólnym dla obu baz (ON CONFLICT, LIMIT/OFFSET, daty jako tekst ISO w UTC).
 */
final class Db
{
    private static ?Db $instance = null;

    private PDO $pdo;
    private string $driver;

    private function __construct(string $url)
    {
        if ($url === '') {
            // Na serwerze brak adresu bazy oznaczałby zapis do pliku, który znika przy każdym wdrożeniu – wolimy głośny błąd.
            if (!Config::isDev()) {
                throw new \RuntimeException('Brak zmiennej DATABASE_URL. Ustaw adres bazy PostgreSQL (albo APP_ENV=dev do pracy lokalnej na SQLite).');
            }
            $url = 'sqlite:' . APP_ROOT . '/storage/app.sqlite';
        }
        if (str_starts_with($url, 'sqlite:')) {
            $path = substr($url, 7);
            if ($path !== ':memory:') {
                @mkdir(dirname($path), 0770, true);
            }
            $this->pdo = new PDO('sqlite:' . $path);
            $this->driver = 'sqlite';
            $this->pdo->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
            if ($path !== ':memory:') {
                $this->pdo->exec('PRAGMA journal_mode = WAL;');
            }
        } else {
            $p = parse_url($url);
            if (!$p || !isset($p['host'])) {
                throw new \RuntimeException('Nieprawidłowy DATABASE_URL.');
            }
            parse_str($p['query'] ?? '', $q);
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $p['host'],
                $p['port'] ?? 5432,
                ltrim($p['path'] ?? '/postgres', '/')
            );
            if (!empty($q['sslmode'])) {
                $dsn .= ';sslmode=' . $q['sslmode'];
            }
            $this->pdo = new PDO($dsn, urldecode($p['user'] ?? ''), urldecode($p['pass'] ?? ''));
            $this->driver = 'pgsql';
            $this->pdo->exec("SET TIME ZONE 'UTC'");
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public static function get(): Db
    {
        return self::$instance ??= new Db((string) Config::get('DATABASE_URL'));
    }

    /** Do testów: nowa baza w pamięci. */
    public static function useUrl(string $url): Db
    {
        return self::$instance = new Db($url);
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function run(string $sql, array $params = []): PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        foreach (array_values($params) as $i => $v) {
            $type = match (true) {
                is_int($v) => PDO::PARAM_INT,
                is_bool($v) => PDO::PARAM_INT,
                $v === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            if (is_string($v) && $v !== '' && (!mb_check_encoding($v, 'UTF-8') || str_contains($v, "\0"))) {
                // PostgreSQL odrzuca błędne sekwencje UTF-8 i znak NUL – jeden zły znak nie może przerwać zapisu.
                $v = str_replace("\0", '', mb_scrub($v, 'UTF-8'));
            }
            $st->bindValue($i + 1, is_bool($v) ? (int) $v : $v, $type);
        }
        $st->execute();
        return $st;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function val(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', array_fill(0, count($cols), '?'))
        );
        if ($this->driver === 'pgsql') {
            return (int) $this->val($sql . ' RETURNING id', array_values($data));
        }
        $this->run($sql, array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    /** INSERT … ON CONFLICT (klucz) DO UPDATE – działa w PostgreSQL i SQLite ≥ 3.24. Zwraca id. */
    public function upsert(string $table, array $data, array $conflictCols, ?array $updateCols = null, bool $returnId = true): int
    {
        $cols = array_keys($data);
        $updateCols ??= array_values(array_diff($cols, $conflictCols));
        $set = $updateCols
            ? implode(', ', array_map(static fn ($c) => "$c = excluded.$c", $updateCols))
            : $conflictCols[0] . ' = excluded.' . $conflictCols[0];
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO UPDATE SET %s',
            $table,
            implode(', ', $cols),
            implode(', ', array_fill(0, count($cols), '?')),
            implode(', ', $conflictCols),
            $set
        );
        $this->run($sql, array_values($data));
        if (!$returnId) {
            return 0;
        }
        $where = implode(' AND ', array_map(static fn ($c) => "$c = ?", $conflictCols));
        $vals = array_map(static fn ($c) => $data[$c], $conflictCols);
        return (int) $this->val("SELECT id FROM $table WHERE $where", $vals);
    }

    public function update(string $table, array $data, string $where, array $params = []): int
    {
        $set = implode(', ', array_map(static fn ($c) => "$c = ?", array_keys($data)));
        return $this->run("UPDATE $table SET $set WHERE $where", [...array_values($data), ...$params])->rowCount();
    }

    public function transaction(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn($this);
        }
        $this->pdo->beginTransaction();
        try {
            $r = $fn($this);
            $this->pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Wyrażenie SQL „dopasowanie bez wielkości liter” na kolumnie z tekstem znormalizowanym przez search_text(). */
    public function likeParam(string $s): string
    {
        return '%' . str_replace(['%', '_'], ['', ''], search_text($s)) . '%';
    }
}
