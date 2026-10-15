<?php
declare(strict_types=1);

namespace M4W\Core;

/**
 * Sessions kept in the database, so that several web servers behind a load balancer share them.
 *  - The table holds the SHA-256 of the session id, never the id itself: reading the database
 *    or a backup does not give anyone a session to reuse.
 *  - Unchanged sessions are not rewritten (only their time, at most once a minute), so parallel
 *    requests of one person do not overwrite each other's changes for nothing.
 *  - Expired rows are removed by the scheduled task (Debian turns PHP's own clean-up off).
 */
final class DbSessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    /** @var array<string,string> data as read, per hashed id */
    private array $read = [];

    private static function key(string $id): string
    {
        return hash('sha256', $id);
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $k = self::key($id);
        $row = Database::one('SELECT data, updated_at FROM php_sessions WHERE id = :k', ['k' => $k]);
        $data = '';
        if ($row && (int) $row['updated_at'] >= time() - self::lifetime()) {
            $data = (string) base64_decode((string) $row['data'], true);
        }
        $this->read[$k] = $data;
        return $data;
    }

    public function write(string $id, string $data): bool
    {
        $k = self::key($id);
        $now = time();
        $enc = base64_encode($data);
        if (Database::run('UPDATE php_sessions SET data = :d, updated_at = :t WHERE id = :k', ['d' => $enc, 't' => $now, 'k' => $k])->rowCount() === 0) {
            try {
                Database::insert('php_sessions', ['id' => $k, 'data' => $enc, 'updated_at' => $now]);
            } catch (\PDOException) {
                // Created meanwhile by a parallel request (MySQL reports 0 rows when nothing changed).
                Database::run('UPDATE php_sessions SET data = :d, updated_at = :t WHERE id = :k', ['d' => $enc, 't' => $now, 'k' => $k]);
            }
        }
        $this->read[$k] = $data;
        return true;
    }

    public function destroy(string $id): bool
    {
        Database::run('DELETE FROM php_sessions WHERE id = :k', ['k' => self::key($id)]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return self::purge($max_lifetime);
    }

    /** Strict mode: an id the server never gave out is refused (a new one is made). */
    public function validateId(string $id): bool
    {
        return (bool) Database::value('SELECT 1 FROM php_sessions WHERE id = :k AND updated_at >= :t', ['k' => self::key($id), 't' => time() - self::lifetime()]);
    }

    /** Data unchanged: only keep the session alive, at most one write a minute. */
    public function updateTimestamp(string $id, string $data): bool
    {
        Database::run('UPDATE php_sessions SET updated_at = :t WHERE id = :k AND updated_at < :old', ['t' => time(), 'k' => self::key($id), 'old' => time() - 60]);
        return true;
    }

    public static function lifetime(): int
    {
        return max(60, (int) ini_get('session.gc_maxlifetime') ?: 1440);
    }

    /** Remove expired sessions (scheduled task). */
    public static function purge(?int $maxLifetime = null): int
    {
        $max = $maxLifetime ?? (max(60, (int) Settings::get('security.session_absolute', 720)) * 60);
        return Database::run('DELETE FROM php_sessions WHERE updated_at < :t', ['t' => time() - $max])->rowCount();
    }
}
