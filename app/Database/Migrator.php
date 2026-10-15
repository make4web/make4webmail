<?php
declare(strict_types=1);

namespace M4W\Database;

use M4W\Core\Database;

final class Migrator
{
    public static function migrate(): int
    {
        // One process at a time: two requests arriving together after an update must not both
        // apply the same step (MySQL commits each ALTER TABLE at once: "Duplicate column").
        $dir = \M4W\Core\Config::storagePath('tmp');
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $lock = @fopen($dir . '/migrate.lock', 'c');
        if ($lock) {
            flock($lock, LOCK_EX);
        }
        try {
            return self::apply();
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private static function apply(): int
    {
        $driver = Database::driver();
        $pdo = Database::pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_version (version INTEGER NOT NULL)');
        $current = (int) (Database::value('SELECT MAX(version) FROM schema_version') ?? 0);
        $schema = require __DIR__ . '/schema.php';
        $applied = 0;
        foreach ($schema as $version => $statements) {
            if ($version <= $current) {
                continue;
            }
            foreach ($statements as $sql) {
                if ($sql instanceof \Closure) {
                    $sql(); // data migration step
                    continue;
                }
                $pdo->exec(self::expand($sql, $driver));
            }
            Database::run('INSERT INTO schema_version (version) VALUES (:v)', ['v' => $version]);
            $applied++;
        }
        return $applied;
    }

    public static function expand(string $sql, string $driver): string
    {
        if ($driver === 'mysql') {
            $sql = strtr($sql, [
                '{PK}'   => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
                '{TEXT}' => 'MEDIUMTEXT NULL',
                '{OPT}'  => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
                '{BLOB}' => 'LONGBLOB NULL',
            ]);
            // MySQL cannot index long VARCHARs in utf8mb4 beyond 3072 bytes; ours are <=190 chars where indexed.
            return $sql;
        }
        return strtr($sql, [
            '{PK}'   => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            '{TEXT}' => 'TEXT NULL',
            '{OPT}'  => '',
            '{BLOB}' => 'BLOB NULL',
        ]);
    }
}
