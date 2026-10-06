<?php
declare(strict_types=1);

namespace M4W\Database;

use M4W\Core\Database;

final class Migrator
{
    public static function migrate(): int
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
