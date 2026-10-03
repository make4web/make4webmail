<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Database as DB;
use M4W\Mail\MimeParser;
use M4W\Mail\ParsedMessage;

/**
 * Local message store: raw .eml on disk (outside web root) + indexed metadata in DB.
 */
final class Mailbox
{
    public static function store(int $userId, int $folderId, string $raw, array $flags = [], ?ParsedMessage $parsed = null): int
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $parsed ??= MimeParser::parse($raw);
        $rel = sprintf('mail/%d/%s/%s.eml', $userId, date('Y/m'), bin2hex(random_bytes(12)));
        $path = storage_path($rel);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0750, true);
        }
        file_put_contents($path, $raw, LOCK_EX);

        $from = $parsed->from();
        $msgId = $parsed->messageId();
        $refs = $parsed->references();
        $inReply = $parsed->inReplyTo();
        $threadKey = self::threadKey($userId, $msgId, $inReply, $refs, $parsed->subject());
        $attachments = array_values(array_filter($parsed->attachmentList(), static fn($a) => !$a['inline']));
        $text = $parsed->text();

        $id = DB::insert('messages', [
            'user_id'         => $userId,
            'folder_id'       => $folderId,
            'message_id'      => $msgId,
            'in_reply_to'     => $inReply,
            'thread_key'      => $threadKey,
            'subject'         => mb_substr($parsed->subject(), 0, 500),
            'from_name'       => mb_substr($from['name'], 0, 255),
            'from_email'      => mb_substr($from['email'], 0, 255),
            'to_list'         => json_encode($parsed->to(), JSON_UNESCAPED_UNICODE),
            'cc_list'         => json_encode($parsed->cc(), JSON_UNESCAPED_UNICODE),
            'bcc_list'        => json_encode($parsed->bcc(), JSON_UNESCAPED_UNICODE),
            'reply_to'        => mb_substr($parsed->replyTo()[0]['email'] ?? '', 0, 255),
            'date_sent'       => $parsed->date() ?: time(),
            'date_received'   => $flags['date_received'] ?? time(),
            'size'            => strlen($raw),
            'snippet'         => mb_substr($parsed->snippet(220), 0, 300),
            'body_text'       => mb_substr($text, 0, 200000),
            'attachments'     => json_encode($attachments, JSON_UNESCAPED_UNICODE),
            'has_attachments' => $attachments ? 1 : 0,
            'is_read'         => !empty($flags['read']) ? 1 : 0,
            'is_flagged'      => !empty($flags['flagged']) ? 1 : 0,
            'is_answered'     => 0,
            'is_forwarded'    => 0,
            'is_draft'        => !empty($flags['draft']) ? 1 : 0,
            'priority'        => $parsed->priority(),
            'storage_path'    => $rel,
            'draft_meta'      => isset($flags['draft_meta']) ? json_encode($flags['draft_meta']) : '',
            'created_at'      => time(),
        ]);
        DB::run('UPDATE users SET used_bytes = used_bytes + :s WHERE id = :u', ['s' => strlen($raw), 'u' => $userId]);
        return $id;
    }

    private static function threadKey(int $userId, string $msgId, string $inReplyTo, array $refs, string $subject): string
    {
        $parents = array_values(array_filter(array_merge($refs, [$inReplyTo])));
        if ($parents) {
            // Reuse the thread of any known parent message.
            [$in, $params] = DB::in('r', array_slice($parents, -20));
            $row = DB::one("SELECT thread_key FROM messages WHERE user_id = :u AND message_id IN $in ORDER BY id LIMIT 1", ['u' => $userId] + $params);
            if ($row && $row['thread_key'] !== '') {
                return $row['thread_key'];
            }
            return substr(sha1($parents[0]), 0, 32);
        }
        // Children that arrived earlier than their parent.
        if ($msgId !== '') {
            $row = DB::one('SELECT thread_key FROM messages WHERE user_id = :u AND in_reply_to = :m LIMIT 1', ['u' => $userId, 'm' => $msgId]);
            if ($row && $row['thread_key'] !== '') {
                return $row['thread_key'];
            }
        }
        return substr(sha1($msgId !== '' ? $msgId : uniqid('', true) . $subject), 0, 32);
    }

    public static function get(int $userId, int $id): ?array
    {
        return DB::one('SELECT * FROM messages WHERE id = :id AND user_id = :u', ['id' => $id, 'u' => $userId]);
    }

    public static function raw(array $msg): string
    {
        $path = storage_path($msg['storage_path']);
        $real = realpath($path);
        $base = realpath(storage_path('mail'));
        if ($real === false || $base === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            return '';
        }
        return (string) file_get_contents($real);
    }

    public static function parsed(array $msg): ParsedMessage
    {
        return MimeParser::parse(self::raw($msg));
    }

    /**
     * List messages of a folder (or search across folders).
     * @return array{items:array,total:int}
     */
    public static function list(int $userId, array $opts): array
    {
        $where = ['m.user_id = :u'];
        $params = ['u' => $userId];
        $folderId = (int) ($opts['folder'] ?? 0);
        $q = trim((string) ($opts['q'] ?? ''));

        if ($folderId > 0 && ($q === '' || !empty($opts['in_folder']))) {
            $where[] = 'm.folder_id = :f';
            $params['f'] = $folderId;
        } elseif ($q !== '' || !empty($opts['filter'])) {
            // Search everywhere but trash/spam.
            $excluded = DB::all("SELECT id FROM folders WHERE user_id = :u AND role IN ('trash','spam')", ['u' => $userId]);
            foreach ($excluded as $i => $ex) {
                $where[] = 'm.folder_id <> :ex' . $i;
                $params['ex' . $i] = (int) $ex['id'];
            }
        }
        $filter = (string) ($opts['filter'] ?? '');
        if ($filter === 'unread') {
            $where[] = 'm.is_read = 0';
        } elseif ($filter === 'flagged') {
            $where[] = 'm.is_flagged = 1';
        } elseif ($filter === 'attachments') {
            $where[] = 'm.has_attachments = 1';
        }

        if ($q !== '') {
            self::applySearch($q, $where, $params);
        }
        $whereSql = implode(' AND ', $where);
        $limit = max(10, min(200, (int) ($opts['limit'] ?? 50)));
        $offset = max(0, (int) ($opts['offset'] ?? 0));
        $sort = ($opts['sort'] ?? 'date') === 'from' ? 'm.from_name' : (($opts['sort'] ?? '') === 'subject' ? 'm.subject' : 'm.date_received');
        $dir = ($opts['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        $threads = !empty($opts['threads']) && $q === '';

        if ($threads) {
            $total = (int) DB::value("SELECT COUNT(DISTINCT m.thread_key) FROM messages m WHERE $whereSql", $params);
            $rows = DB::all(
                "SELECT m.*, t.cnt AS thread_count, t.unread_cnt AS thread_unread, t.flagged_cnt AS thread_flagged, t.att_cnt AS thread_att
                 FROM messages m
                 JOIN (SELECT thread_key, MAX(id) AS last_id, COUNT(*) AS cnt, SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread_cnt,
                              SUM(is_flagged) AS flagged_cnt, SUM(has_attachments) AS att_cnt
                       FROM messages m WHERE $whereSql GROUP BY thread_key) t ON t.last_id = m.id
                 ORDER BY $sort $dir, m.id $dir LIMIT $limit OFFSET $offset",
                $params
            );
        } else {
            $total = (int) DB::value("SELECT COUNT(*) FROM messages m WHERE $whereSql", $params);
            $rows = DB::all(
                "SELECT m.*, 1 AS thread_count, (1 - m.is_read) AS thread_unread, m.is_flagged AS thread_flagged, m.has_attachments AS thread_att
                 FROM messages m WHERE $whereSql ORDER BY $sort $dir, m.id $dir LIMIT $limit OFFSET $offset",
                $params
            );
        }
        return ['items' => array_map([self::class, 'summary'], $rows), 'total' => $total];
    }

    /** Gmail-like operators: from:, to:, subject:, has:attachment, is:unread, is:starred, before:, after:, larger: */
    private static function applySearch(string $q, array &$where, array &$params): void
    {
        $i = 0;
        preg_match_all('/(\w+):("[^"]*"|\S+)|"([^"]+)"|(\S+)/u', $q, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $i++;
            $key = strtolower($m[1] ?? '');
            $val = trim(($m[2] ?? '') !== '' ? $m[2] : (($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? '')), '"');
            if ($val === '') {
                continue;
            }
            $p = 's' . $i;
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($val)) . '%';
            switch ($key) {
                case 'from':
                case 'de':
                    $where[] = "(LOWER(m.from_email) LIKE :$p ESCAPE '\\' OR LOWER(m.from_name) LIKE :$p ESCAPE '\\')";
                    $params[$p] = $like;
                    break;
                case 'to':
                case 'a':
                    $where[] = "(LOWER(m.to_list) LIKE :$p ESCAPE '\\' OR LOWER(m.cc_list) LIKE :$p ESCAPE '\\')";
                    $params[$p] = $like;
                    break;
                case 'subject':
                case 'objet':
                    $where[] = "LOWER(m.subject) LIKE :$p ESCAPE '\\'";
                    $params[$p] = $like;
                    break;
                case 'has':
                    if (in_array($val, ['attachment', 'pj', 'piece-jointe'], true)) {
                        $where[] = 'm.has_attachments = 1';
                    }
                    break;
                case 'is':
                    if (in_array($val, ['unread', 'nonlu'], true)) {
                        $where[] = 'm.is_read = 0';
                    } elseif (in_array($val, ['starred', 'flagged', 'suivi'], true)) {
                        $where[] = 'm.is_flagged = 1';
                    } elseif (in_array($val, ['read', 'lu'], true)) {
                        $where[] = 'm.is_read = 1';
                    }
                    break;
                case 'before':
                case 'avant':
                case 'after':
                case 'apres':
                    $ts = strtotime(str_replace('/', '-', $val));
                    if ($ts) {
                        $op = in_array($key, ['before', 'avant'], true) ? '<' : '>=';
                        $where[] = "m.date_received $op :$p";
                        $params[$p] = $ts;
                    }
                    break;
                case 'larger':
                    $bytes = (int) $val * (str_ends_with(strtolower($val), 'k') ? 1024 : 1024 * 1024);
                    $where[] = "m.size > :$p";
                    $params[$p] = $bytes;
                    break;
                default:
                    $term = $key !== '' ? $m[0] : $val;
                    $params[$p] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)) . '%';
                    $where[] = "(LOWER(m.subject) LIKE :$p ESCAPE '\\' OR LOWER(m.from_name) LIKE :$p ESCAPE '\\' OR LOWER(m.from_email) LIKE :$p ESCAPE '\\'"
                        . " OR LOWER(m.to_list) LIKE :$p ESCAPE '\\' OR LOWER(m.body_text) LIKE :$p ESCAPE '\\' OR LOWER(m.attachments) LIKE :$p ESCAPE '\\')";
            }
        }
    }

    public static function summary(array $m): array
    {
        $to = json_decode((string) $m['to_list'], true) ?: [];
        return [
            'id'          => (int) $m['id'],
            'folder_id'   => (int) $m['folder_id'],
            'thread'      => $m['thread_key'],
            'thread_count'=> (int) ($m['thread_count'] ?? 1),
            'subject'     => $m['subject'],
            'from_name'   => $m['from_name'],
            'from_email'  => $m['from_email'],
            'to'          => $to,
            'date'        => (int) $m['date_received'],
            'snippet'     => $m['snippet'],
            'size'        => (int) $m['size'],
            'unread'      => isset($m['thread_unread']) ? (int) $m['thread_unread'] > 0 : !$m['is_read'],
            'flagged'     => isset($m['thread_flagged']) ? (int) $m['thread_flagged'] > 0 : (bool) $m['is_flagged'],
            'answered'    => (bool) $m['is_answered'],
            'forwarded'   => (bool) $m['is_forwarded'],
            'draft'       => (bool) $m['is_draft'],
            'attachments' => isset($m['thread_att']) ? (int) $m['thread_att'] > 0 : (bool) $m['has_attachments'],
            'priority'    => (int) $m['priority'],
        ];
    }

    /** @return int[] ids of messages in the same thread (and visible folders) */
    public static function threadIds(int $userId, string $threadKey): array
    {
        $rows = DB::all(
            "SELECT m.id FROM messages m JOIN folders f ON f.id = m.folder_id
             WHERE m.user_id = :u AND m.thread_key = :t AND (f.role IS NULL OR f.role NOT IN ('trash','spam'))
             ORDER BY m.date_received ASC, m.id ASC",
            ['u' => $userId, 't' => $threadKey]
        );
        return array_map('intval', array_column($rows, 'id'));
    }

    public static function setFlags(int $userId, array $ids, array $flags): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return 0;
        }
        $set = [];
        foreach (['is_read', 'is_flagged', 'is_answered', 'is_forwarded'] as $f) {
            if (array_key_exists($f, $flags)) {
                $set[$f] = $flags[$f] ? 1 : 0;
            }
        }
        if (!$set) {
            return 0;
        }
        [$in, $params] = DB::in('i', $ids);
        $sets = [];
        foreach ($set as $k => $v) {
            $sets[] = "$k = :v_$k";
            $params["v_$k"] = $v;
        }
        return DB::run('UPDATE messages SET ' . implode(', ', $sets) . " WHERE user_id = :u AND id IN $in", $params + ['u' => $userId])->rowCount();
    }

    public static function move(int $userId, array $ids, int $folderId): int
    {
        if (!Folders::find($userId, $folderId)) {
            throw new \InvalidArgumentException('Folder not found');
        }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        [$in, $params] = DB::in('i', $ids);
        return DB::run("UPDATE messages SET folder_id = :f WHERE user_id = :u AND id IN $in", $params + ['u' => $userId, 'f' => $folderId])->rowCount();
    }

    /** Move to trash, or delete permanently if already in trash/spam. */
    public static function delete(int $userId, array $ids, bool $permanent = false): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return 0;
        }
        $trash = Folders::byRole($userId, 'trash');
        $spam = Folders::byRole($userId, 'spam');
        [$in, $params] = DB::in('i', $ids);
        $rows = DB::all("SELECT id, folder_id, storage_path, size FROM messages WHERE user_id = :u AND id IN $in", $params + ['u' => $userId]);
        $n = 0;
        foreach ($rows as $r) {
            if ($permanent || (int) $r['folder_id'] === (int) $trash['id'] || (int) $r['folder_id'] === (int) $spam['id']) {
                self::purge($userId, $r);
            } else {
                DB::update('messages', ['folder_id' => $trash['id']], 'id = :id', ['id' => $r['id']]);
            }
            $n++;
        }
        return $n;
    }

    public static function purge(int $userId, array $row): void
    {
        DB::delete('messages', 'id = :id AND user_id = :u', ['id' => $row['id'], 'u' => $userId]);
        $path = storage_path($row['storage_path']);
        if ($row['storage_path'] !== '' && is_file($path)) {
            @unlink($path);
        }
        DB::run('UPDATE users SET used_bytes = CASE WHEN used_bytes > :s THEN used_bytes - :s2 ELSE 0 END WHERE id = :u',
            ['s' => (int) $row['size'], 's2' => (int) $row['size'], 'u' => $userId]);
    }

    public static function emptyFolder(int $userId, int $folderId): int
    {
        $rows = DB::all('SELECT id, storage_path, size FROM messages WHERE user_id = :u AND folder_id = :f', ['u' => $userId, 'f' => $folderId]);
        foreach ($rows as $r) {
            self::purge($userId, $r);
        }
        return count($rows);
    }

    /** Auto-purge trash/spam older than N days. */
    public static function autoPurge(int $days = 30): int
    {
        $limit = time() - $days * 86400;
        $rows = DB::all(
            "SELECT m.id, m.user_id, m.storage_path, m.size FROM messages m JOIN folders f ON f.id = m.folder_id
             WHERE f.role IN ('trash','spam') AND m.date_received < :l",
            ['l' => $limit]
        );
        foreach ($rows as $r) {
            self::purge((int) $r['user_id'], $r);
        }
        return count($rows);
    }

    public static function unreadCounts(int $userId): array
    {
        $rows = DB::all('SELECT folder_id, COUNT(*) AS c FROM messages WHERE user_id = :u AND is_read = 0 GROUP BY folder_id', ['u' => $userId]);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['folder_id']] = (int) $r['c'];
        }
        return $out;
    }
}
