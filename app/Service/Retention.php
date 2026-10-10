<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\App;
use M4W\Core\Audit;
use M4W\Core\Auth;
use M4W\Core\Database as DB;
use M4W\Core\Settings;
use M4W\Mail\MimeParser;
use M4W\Storage\Storage;

/**
 * "Second trash": a copy of every permanently deleted message is kept for a
 * configurable period, so administrators can audit deletions and restore mail
 * after a mistake or an incident. Users never see it and it does not count in
 * their quota. Contents go through the blob store (database by default).
 */
final class Retention
{
    public const REASONS = ['deleted', 'emptied', 'auto_purge', 'rule', 'account_deleted'];

    public static function enabled(): bool
    {
        return (int) Settings::get('retention.enabled', 1) === 1;
    }

    /**
     * Keep a copy of a message that is about to be purged.
     * @param array $row full messages row
     */
    public static function keep(array $row, string $reason, ?string $raw = null): ?int
    {
        if (!self::enabled()) {
            return null;
        }
        $folder = $row['folder_id'] ? DB::one('SELECT name, role FROM folders WHERE id = :id', ['id' => $row['folder_id']]) : null;
        // Spam removed by the 30-day clean-up is noise: kept only on request.
        if ($reason === 'auto_purge' && ($folder['role'] ?? '') === 'spam' && !(int) Settings::get('retention.keep_spam', 0)) {
            return null;
        }
        if ($raw === null) {
            $path = storage_path((string) $row['storage_path']);
            if ($row['storage_path'] === '' || !is_file($path)) {
                return null;
            }
            $raw = (string) file_get_contents($path);
        }
        return self::store($row, $raw, $reason, $folder);
    }

    /** Message refused by a "delete" rule at delivery: never stored, but retained. */
    public static function keepIncoming(array $user, string $raw, string $reason = 'rule'): ?int
    {
        if (!self::enabled()) {
            return null;
        }
        $p = MimeParser::parse($raw);
        $from = $p->from();
        return self::store([
            'user_id' => (int) $user['id'], 'folder_id' => null, 'message_id' => $p->messageId(), 'subject' => $p->subject(),
            'from_name' => $from['name'] ?? '', 'from_email' => $from['email'] ?? '', 'to_list' => json_encode($p->to()),
            'date_sent' => $p->date(), 'size' => strlen($raw), 'has_attachments' => $p->attachmentList() ? 1 : 0,
        ], $raw, $reason, ['name' => t('ret.not_stored'), 'role' => null]);
    }

    private static function store(array $row, string $raw, string $reason, ?array $folder): ?int
    {
        try {
            $blob = bin2hex(random_bytes(16));
            $packed = gzencode($raw, 6);
            $tmp = fopen('php://temp', 'w+b');
            fwrite($tmp, $packed === false ? $raw : $packed);
            rewind($tmp);
            Storage::store()->put($blob, $tmp);
            fclose($tmp);
            $user = DB::one('SELECT email FROM users WHERE id = :id', ['id' => (int) $row['user_id']]);
            $actor = Auth::user();
            $req = App::request();
            $origin = '';
            if (!empty($row['trashed_from_name'])) {
                $origin = (string) $row['trashed_from_name'];
            } elseif ($folder) {
                $origin = Folders::displayName(['name' => $folder['name'], 'role' => $folder['role']]);
            }
            return DB::insert('deleted_messages', [
                'user_id' => (int) $row['user_id'], 'user_email' => (string) ($user['email'] ?? ''),
                'folder_name' => mb_substr($origin, 0, 255),
                'original_folder_id' => !empty($row['trashed_from']) ? (int) $row['trashed_from'] : ($row['folder_id'] ?? null),
                'message_id' => mb_substr((string) ($row['message_id'] ?? ''), 0, 500),
                'subject' => mb_substr((string) ($row['subject'] ?? ''), 0, 500),
                'from_name' => mb_substr((string) ($row['from_name'] ?? ''), 0, 255),
                'from_email' => mb_substr((string) ($row['from_email'] ?? ''), 0, 255),
                'to_list' => (string) ($row['to_list'] ?? '[]'),
                'date_sent' => (int) ($row['date_sent'] ?? 0) ?: (int) ($row['date_received'] ?? 0),
                'size' => (int) ($row['size'] ?? strlen($raw)),
                'has_attachments' => (int) ($row['has_attachments'] ?? 0),
                'is_personal' => (int) ($row['is_personal'] ?? 0) ?: (Delegation::isPersonalSubject((string) ($row['subject'] ?? '')) ? 1 : 0),
                'blob_id' => $blob, 'reason' => in_array($reason, self::REASONS, true) ? $reason : 'deleted',
                'deleted_at' => time(), 'deleted_by' => $actor ? (int) $actor['id'] : null,
                'deleted_by_name' => $actor ? mb_substr($actor['name'] ?: $actor['email'], 0, 190) : '',
                'ip' => $req ? mb_substr($req->ip(), 0, 64) : '',
            ]);
        } catch (\Throwable $e) {
            // Never block a deletion because the copy failed, but leave a trace.
            error_log('[m4w] retention: ' . $e->getMessage());
            return null;
        }
    }

    public static function find(int $id): ?array
    {
        return DB::one('SELECT * FROM deleted_messages WHERE id = :id', ['id' => $id]);
    }

    public static function raw(array $row): string
    {
        $s = '';
        Storage::store()->read($row['blob_id'], static function (string $buf) use (&$s) {
            $s .= $buf;
        });
        $plain = @gzdecode($s);
        return $plain === false ? $s : $plain;
    }

    /** Administrators do not open what users marked personal (only restoring it to its owner). */
    public static function masked(array $row): bool
    {
        return (int) $row['is_personal'] === 1 && (int) Settings::get('delegation.hide_personal', 1) === 1;
    }

    /** @return array{items:array,total:int} */
    public static function search(array $f, int $limit = 50, int $offset = 0): array
    {
        $where = ['1 = 1'];
        $p = [];
        if (!empty($f['user'])) {
            $where[] = 'd.user_id = :u';
            $p['u'] = (int) $f['user'];
        }
        if (!empty($f['reason']) && in_array($f['reason'], self::REASONS, true)) {
            $where[] = 'd.reason = :r';
            $p['r'] = $f['reason'];
        }
        if (($f['state'] ?? '') === 'restored') {
            $where[] = 'd.restored_at > 0';
        } elseif (($f['state'] ?? '') === 'pending') {
            $where[] = 'd.restored_at = 0';
        }
        if (!empty($f['from'])) {
            $where[] = 'd.deleted_at >= :df';
            $p['df'] = (int) $f['from'];
        }
        if (!empty($f['to'])) {
            $where[] = 'd.deleted_at < :dt';
            $p['dt'] = (int) $f['to'] + 86400;
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . strtr(mb_strtolower($q), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            // Personal subjects are not searchable by content.
            $where[] = "((d.is_personal = 0 AND (LOWER(d.subject) LIKE :q ESCAPE '!' OR LOWER(d.to_list) LIKE :q ESCAPE '!'))"
                . " OR LOWER(d.from_email) LIKE :q ESCAPE '!' OR LOWER(d.from_name) LIKE :q ESCAPE '!' OR LOWER(d.user_email) LIKE :q ESCAPE '!')";
            $p['q'] = $like;
        }
        $w = implode(' AND ', $where);
        $total = (int) DB::value("SELECT COUNT(*) FROM deleted_messages d WHERE $w", $p);
        $items = DB::all("SELECT d.* FROM deleted_messages d WHERE $w ORDER BY d.deleted_at DESC, d.id DESC LIMIT " . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset), $p);
        return ['items' => $items, 'total' => $total];
    }

    public static function stats(): array
    {
        return [
            'count' => (int) DB::value('SELECT COUNT(*) FROM deleted_messages'),
            'size' => (int) (DB::value('SELECT SUM(size) FROM deleted_messages') ?? 0),
            'restored' => (int) DB::value('SELECT COUNT(*) FROM deleted_messages WHERE restored_at > 0'),
            'last30' => (int) DB::value('SELECT COUNT(*) FROM deleted_messages WHERE deleted_at > :t', ['t' => time() - 30 * 86400]),
        ];
    }

    /**
     * Put the message back into a mailbox: its original folder when it still exists
     * (not trash/spam), otherwise the inbox. Returns the new message id.
     */
    public static function restore(int $id, array $admin, ?int $targetUserId = null): int
    {
        $row = self::find($id);
        if (!$row) {
            throw new \InvalidArgumentException(t('ret.not_found'));
        }
        $targetUserId = $targetUserId ?: (int) $row['user_id'];
        $target = Users::find($targetUserId);
        if (!$target) {
            throw new \InvalidArgumentException(t('ret.no_target'));
        }
        if (self::masked($row) && $targetUserId !== (int) $row['user_id']) {
            throw new \InvalidArgumentException(t('ret.personal_owner_only'));
        }
        $folderId = null;
        if ($targetUserId === (int) $row['user_id'] && $row['original_folder_id']) {
            $f = DB::one('SELECT id, role FROM folders WHERE id = :id AND user_id = :u', ['id' => $row['original_folder_id'], 'u' => $targetUserId]);
            if ($f && !in_array($f['role'], ['trash', 'spam'], true)) {
                $folderId = (int) $f['id'];
            }
        }
        $folderId ??= (int) Folders::byRole($targetUserId, 'inbox')['id'];
        $raw = self::raw($row);
        if ($raw === '') {
            throw new \RuntimeException(t('ret.missing'));
        }
        $newId = Mailbox::store($targetUserId, $folderId, $raw, ['read' => true]);
        DB::update('deleted_messages', ['restored_at' => time(), 'restored_by' => (int) $admin['id'], 'restored_to' => $target['email']], 'id = :id', ['id' => $id]);
        Audit::log('retention.restored', $target['email'], ['id' => $id, 'subject' => self::masked($row) ? '' : mb_substr($row['subject'], 0, 120), 'owner' => $row['user_email']], (int) $admin['id']);
        return $newId;
    }

    public static function destroy(int $id, ?array $admin = null): void
    {
        $row = self::find($id);
        if (!$row) {
            return;
        }
        Storage::store()->delete($row['blob_id']);
        DB::delete('deleted_messages', 'id = :id', ['id' => $id]);
        if ($admin) {
            Audit::log('retention.purged', $row['user_email'], ['id' => $id], (int) $admin['id']);
        }
    }

    /** Drop copies older than the retention period (cron). 0 days = keep forever. */
    public static function expire(): int
    {
        $days = (int) Settings::get('retention.days', 365);
        if ($days <= 0) {
            return 0;
        }
        $n = 0;
        foreach (DB::all('SELECT id FROM deleted_messages WHERE deleted_at < :t LIMIT 5000', ['t' => time() - $days * 86400]) as $r) {
            self::destroy((int) $r['id']);
            $n++;
        }
        return $n;
    }
}
