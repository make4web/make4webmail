<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Database as DB;
use M4W\Core\Settings;

/**
 * Full-text index of the messages (subject, sender, recipients, text, attachment names):
 * FTS5 with SQLite, a FULLTEXT index with MySQL / MariaDB. Accents and case do not matter,
 * words are found from their beginning ("factur" finds "facture", "facturation").
 *
 * Built by the scheduled task, never during a web request (it can take a while on a large
 * mailbox); until it is ready, and for what it cannot answer (MySQL leaves out words shorter
 * than its minimum and its stopwords), the search works as before with LIKE.
 */
final class Search
{
    private const COLUMNS = ['subject', 'from_name', 'from_email', 'to_list', 'body_text', 'attachments'];

    private static ?array $stop = null;
    private static ?int $minToken = null;

    /** Per request: whether the index was found. */
    private static ?bool $ready = null;

    /**
     * The index was built and is really there (a restored backup may bring the setting back
     * without the index: then it is simply rebuilt by the next scheduled run).
     */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            self::$ready = false;
            if (Settings::get('search.fts', '') === DB::driver()) {
                try {
                    self::$ready = DB::driver() === 'mysql'
                        ? (bool) DB::value("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND INDEX_NAME = 'messages_ft'")
                        : (int) DB::value("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('messages_fts', 'messages_fts_ai', 'messages_fts_ad', 'messages_fts_au')") === 4;
                } catch (\Throwable) {
                    self::$ready = false;
                }
                if (!self::$ready) {
                    Settings::set('search.fts', ''); // rebuilt at the next scheduled run
                }
            }
        }
        return self::$ready;
    }

    /**
     * Create the index if it does not exist yet (scheduled task, installation, tests).
     * @return bool whether the index is ready
     */
    public static function ensureIndex(): bool
    {
        self::$ready = null;
        if (self::ready()) {
            return true;
        }
        try {
            if (DB::driver() === 'mysql') {
                $has = DB::value("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND INDEX_NAME = 'messages_ft'");
                if (!(int) $has) {
                    DB::pdo()->exec('ALTER TABLE messages ADD FULLTEXT INDEX messages_ft (' . implode(', ', self::COLUMNS) . ')');
                }
            } else {
                $pdo = DB::pdo();
                $cols = implode(', ', self::COLUMNS);
                $pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS messages_fts USING fts5($cols, content='messages', content_rowid='id', tokenize='unicode61 remove_diacritics 2')");
                $new = implode(', ', array_map(static fn($c) => 'new.' . $c, self::COLUMNS));
                $old = implode(', ', array_map(static fn($c) => 'old.' . $c, self::COLUMNS));
                // Kept in step with every change of the messages, whatever code makes it.
                $pdo->exec("CREATE TRIGGER IF NOT EXISTS messages_fts_ai AFTER INSERT ON messages BEGIN INSERT INTO messages_fts(rowid, $cols) VALUES (new.id, $new); END");
                $pdo->exec("CREATE TRIGGER IF NOT EXISTS messages_fts_ad AFTER DELETE ON messages BEGIN INSERT INTO messages_fts(messages_fts, rowid, $cols) VALUES ('delete', old.id, $old); END");
                $pdo->exec("CREATE TRIGGER IF NOT EXISTS messages_fts_au AFTER UPDATE OF $cols ON messages BEGIN INSERT INTO messages_fts(messages_fts, rowid, $cols) VALUES ('delete', old.id, $old); INSERT INTO messages_fts(rowid, $cols) VALUES (new.id, $new); END");
                $pdo->exec("INSERT INTO messages_fts(messages_fts) VALUES ('rebuild')");
            }
            Settings::set('search.fts', DB::driver());
            self::$ready = null;
            return self::ready();
        } catch (\Throwable $e) {
            // SQLite built without FTS5, no right to alter the table…: the search stays as it was.
            error_log('[m4w] search index: ' . $e->getMessage());
            return false;
        }
    }

    /** Remove the index (tests, or an administrator going back to the plain search). */
    public static function dropIndex(): void
    {
        if (DB::driver() === 'mysql') {
            if ((int) DB::value("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND INDEX_NAME = 'messages_ft'")) {
                DB::pdo()->exec('ALTER TABLE messages DROP INDEX messages_ft');
            }
        } else {
            foreach (['messages_fts_ai', 'messages_fts_ad', 'messages_fts_au'] as $t) {
                DB::pdo()->exec("DROP TRIGGER IF EXISTS $t");
            }
            DB::pdo()->exec('DROP TABLE IF EXISTS messages_fts');
        }
        Settings::set('search.fts', '');
        self::$ready = null;
    }

    /** Words of a search term, as the index cuts them (letters and digits). @return string[] */
    public static function tokens(string $term): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($term), $m);
        return array_values(array_filter($m[0], static fn($t) => $t !== ''));
    }

    /**
     * SQL condition for one free-text term through the index, or null when the index cannot
     * answer it exactly (then the caller uses LIKE).
     */
    public static function condition(string $term, string $param, array &$params): ?string
    {
        if (!self::ready()) {
            return null;
        }
        $tokens = self::tokens($term);
        if (!$tokens || count($tokens) > 12) {
            return null;
        }
        if (DB::driver() === 'mysql') {
            foreach ($tokens as $t) {
                if (mb_strlen($t) < self::minToken() || in_array($t, self::stopwords(), true)) {
                    return null; // not in a MySQL index: it would find nothing
                }
            }
            // Only letters and digits reach the query: no operator can be slipped in.
            $params[$param] = count($tokens) === 1 ? '+' . $tokens[0] . '*' : '+"' . implode(' ', $tokens) . '"';
            return 'MATCH(m.' . implode(', m.', self::COLUMNS) . ") AGAINST(:$param IN BOOLEAN MODE)";
        }
        // FTS5: a quoted phrase, the last word as a prefix ("make4web com" for make4web.com).
        $params[$param] = '"' . implode(' ', $tokens) . '"*';
        return "m.id IN (SELECT rowid FROM messages_fts WHERE messages_fts MATCH :$param)";
    }

    private static function minToken(): int
    {
        if (self::$minToken === null) {
            try {
                self::$minToken = max(1, (int) DB::value('SELECT @@innodb_ft_min_token_size'));
            } catch (\Throwable) {
                self::$minToken = 3;
            }
        }
        return self::$minToken;
    }

    /** @return string[] words InnoDB leaves out of its full-text indexes */
    private static function stopwords(): array
    {
        if (self::$stop === null) {
            try {
                self::$stop = array_map('strval', array_column(DB::all('SELECT value FROM information_schema.INNODB_FT_DEFAULT_STOPWORD'), 'value'));
            } catch (\Throwable) {
                self::$stop = [];
            }
            if (!self::$stop) {
                self::$stop = ['a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from', 'how', 'i', 'in', 'is', 'it', 'la', 'of', 'on',
                    'or', 'that', 'the', 'this', 'to', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'und', 'the', 'www'];
            }
        }
        return self::$stop;
    }
}
