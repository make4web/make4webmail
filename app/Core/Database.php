<?php
declare(strict_types=1);

namespace M4W\Core;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper (SQLite by default, MySQL/MariaDB supported).
 * Every query goes through prepared statements.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static string $driver = 'sqlite';

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::connect((array) Config::get('db', []));
        }
        return self::$pdo;
    }

    public static function connect(array $db): void
    {
        $driver = $db['driver'] ?? 'sqlite';
        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        if ($driver === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $db['host'] ?? '127.0.0.1',
                (int) ($db['port'] ?? 3306),
                $db['name'] ?? 'm4w'
            );
            $pdo = new PDO($dsn, $db['user'] ?? '', $db['pass'] ?? '', $opts);
        } else {
            $path = $db['path'] ?? Config::storagePath('data/m4w.sqlite');
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0750, true);
            }
            $pdo = new PDO('sqlite:' . $path, null, null, $opts);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $driver = 'sqlite';
        }
        self::$pdo = $pdo;
        self::$driver = $driver;
    }

    public static function driver(): string
    {
        self::pdo();
        return self::$driver;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        // Native prepares (MySQL) forbid re-using a named placeholder: duplicate them transparently.
        $seen = [];
        $sql = preg_replace_callback('/(?<![:\w]):([a-zA-Z_]\w*)/', static function ($m) use (&$seen, &$params) {
            $name = $m[1];
            $seen[$name] = ($seen[$name] ?? 0) + 1;
            if ($seen[$name] === 1) {
                return $m[0];
            }
            $alias = $name . '__' . $seen[$name];
            if (array_key_exists($name, $params)) {
                $params[$alias] = $params[$name];
            } elseif (array_key_exists(':' . $name, $params)) {
                $params[$alias] = $params[':' . $name];
            }
            return ':' . $alias;
        }, $sql) ?? $sql;
        $st = self::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $key = is_int($k) ? $k + 1 : (str_starts_with($k, ':') ? $k : ':' . $k);
            $type = match (true) {
                is_int($v)  => PDO::PARAM_INT,
                is_bool($v) => PDO::PARAM_INT,
                $v === null => PDO::PARAM_NULL,
                default     => PDO::PARAM_STR,
            };
            $st->bindValue($key, is_bool($v) ? (int) $v : $v, $type);
        }
        $st->execute();
        return $st;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::ident($table),
            implode(', ', array_map([self::class, 'ident'], $cols)),
            implode(', ', array_map(static fn($c) => ':' . $c, $cols))
        );
        self::run($sql, $data);
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        $sets = [];
        $bind = [];
        foreach ($data as $col => $val) {
            $sets[] = self::ident($col) . ' = :set_' . $col;
            $bind['set_' . $col] = $val;
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s', self::ident($table), implode(', ', $sets), $where);
        return self::run($sql, $bind + $params)->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int
    {
        return self::run(sprintf('DELETE FROM %s WHERE %s', self::ident($table), $where), $params)->rowCount();
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Build "IN (:p0, :p1...)" placeholders. Returns [sql, params]. */
    public static function in(string $prefix, array $values): array
    {
        $values = array_values($values);
        if (!$values) {
            return ['(NULL)', []];
        }
        $ph = [];
        $params = [];
        foreach ($values as $i => $v) {
            $ph[] = ':' . $prefix . $i;
            $params[$prefix . $i] = $v;
        }
        return ['(' . implode(', ', $ph) . ')', $params];
    }

    public static function ident(string $name): string
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $name)) {
            throw new \InvalidArgumentException('Invalid identifier');
        }
        return self::driver() === 'mysql' ? "`$name`" : "\"$name\"";
    }

    /** Case-insensitive LIKE operator for the current driver. */
    public static function like(): string
    {
        return 'LIKE';
    }
}
