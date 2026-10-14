<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Audit;
use M4W\Core\Database as DB;
use M4W\Core\Settings;
use M4W\Storage\Storage;

/**
 * Shared file space.
 *
 * Tree: every user has a private root ("personal"); team spaces ("space") are
 * top-level folders shared through access entries. Rights are granted on folders
 * and inherited by everything below; the effective level is the highest grant
 * found on the folder or any ancestor, for the user, their department or everyone.
 *
 *   READ   view, download, copy out
 *   WRITE  + upload, create, rename, move, delete, restore
 *   MANAGE + change access, rename/delete the space itself
 *
 * The owner of a personal tree and administrators (on team spaces) always manage.
 *
 * A single file can also be shared on its own (fs_file_acl): the people it is shared
 * with may read it — view, download, copy, attach to a message — without any access
 * to its folder. They find it under "Shared with me".
 *
 * Every document has an expiry date (one year by default, three at most). Its creator
 * is reminded one month, one week and two days before; past the date, the document
 * leaves the file space for an archive only administrators reach, its shares end, and
 * the creator (or an administrator) receives it by e-mail.
 */
final class Files
{
    public const NONE = 0;
    public const READ = 1;
    public const WRITE = 2;
    public const MANAGE = 3;

    private const LEVELS = ['read' => self::READ, 'write' => self::WRITE, 'manage' => self::MANAGE];

    /** @var array<int, array|null> */
    private static array $folderCache = [];
    /** @var array<string, int> */
    private static array $permCache = [];

    public static function levelName(int $level): string
    {
        return array_search($level, self::LEVELS, true) ?: 'none';
    }

    public static function resetCache(): void
    {
        self::$folderCache = [];
        self::$permCache = [];
    }

    // ------------------------------------------------------------------ names

    public static function cleanName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1f\x7f\/\\\\]+/u', ' ', $name) ?? '';
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $name = rtrim($name, '. ');
        if ($name === '' || $name === '.' || $name === '..') {
            throw new \InvalidArgumentException(t('files.invalid_name'));
        }
        return mb_substr($name, 0, 200);
    }

    /** "rapport.pdf" -> "rapport (2).pdf" until free in the folder. */
    private static function freeName(int $folderId, string $name, string $kind, ?int $exceptId = null): string
    {
        $taken = static function (string $n) use ($folderId, $kind, $exceptId): bool {
            $sql = $kind === 'file'
                ? 'SELECT id FROM fs_files WHERE folder_id = :f AND deleted_at = 0 AND LOWER(name) = LOWER(:n)'
                : 'SELECT id FROM fs_folders WHERE parent_id = :f AND deleted_at = 0 AND LOWER(name) = LOWER(:n)';
            $id = DB::value($sql, ['f' => $folderId, 'n' => $n]);
            return $id !== null && (int) $id !== $exceptId;
        };
        if (!$taken($name)) {
            return $name;
        }
        $dot = $kind === 'file' ? strrpos($name, '.') : false;
        $base = $dot > 0 ? substr($name, 0, $dot) : $name;
        $ext = $dot > 0 ? substr($name, $dot) : '';
        for ($i = 2; $i < 1000; $i++) {
            $candidate = $base . ' (' . $i . ')' . $ext;
            if (!$taken($candidate)) {
                return $candidate;
            }
        }
        return $base . ' (' . bin2hex(random_bytes(3)) . ')' . $ext;
    }

    // ---------------------------------------------------------------- folders

    public static function folder(int $id): ?array
    {
        if (!array_key_exists($id, self::$folderCache)) {
            self::$folderCache[$id] = DB::one('SELECT * FROM fs_folders WHERE id = :id', ['id' => $id]);
        }
        return self::$folderCache[$id];
    }

    /** Root first, $id last. */
    public static function chain(int $id): array
    {
        $chain = [];
        $seen = [];
        while ($id && !isset($seen[$id]) && ($f = self::folder($id))) {
            $seen[$id] = true;
            array_unshift($chain, $f);
            $id = (int) ($f['parent_id'] ?? 0);
        }
        return $chain;
    }

    public static function personalRoot(int $userId): array
    {
        $row = DB::one("SELECT * FROM fs_folders WHERE kind = 'personal' AND owner_id = :u AND parent_id IS NULL", ['u' => $userId]);
        if ($row) {
            return $row;
        }
        $id = DB::insert('fs_folders', [
            'parent_id' => null, 'kind' => 'personal', 'owner_id' => $userId, 'name' => 'personal',
            'created_by' => $userId, 'created_at' => time(), 'updated_at' => time(),
        ]);
        unset(self::$folderCache[$id]);
        return self::folder($id);
    }

    // ---------------------------------------------------------------- expiry

    public static function defaultExpiryDays(): int
    {
        return max(1, min(self::maxExpiryDays(), (int) Settings::get('files.expiry_default_days', 365)));
    }

    public static function maxExpiryDays(): int
    {
        return max(1, (int) Settings::get('files.expiry_max_days', 1095));
    }

    /** Who may change a document's expiry: its creator, or anyone who can modify its folder. */
    public static function canSetExpiry(array $user, array $file): bool
    {
        return (int) $file['created_by'] === (int) $user['id'] && self::filePermission($user, $file) >= self::READ
            || self::permission($user, (int) $file['folder_id']) >= self::WRITE;
    }

    /** New expiry date (timestamp), between tomorrow and the maximum period from today. */
    public static function setExpiry(array $user, int $fileId, int $at): array
    {
        $f = self::requireFile($user, $fileId, self::READ);
        if (!self::canSetExpiry($user, $f)) {
            throw new \M4W\Core\HttpException(403, t('files.forbidden'));
        }
        $min = strtotime('tomorrow');
        $max = strtotime('today') + self::maxExpiryDays() * 86400 + 86399;
        if ($at < $min || $at > $max) {
            throw new \InvalidArgumentException(t('files.expiry_range', ['max' => format_datetime($max, false)]));
        }
        $at = strtotime(date('Y-m-d 23:59:00', $at)) ?: $at;
        DB::update('fs_files', ['expires_at' => $at, 'expiry_stage' => 0], 'id = :id', ['id' => $fileId]);
        Audit::log('files.expiry_changed', $f['name'], ['until' => date('Y-m-d', $at)], (int) $user['id']);
        return self::fileOut(self::file($fileId));
    }

    /** Hidden folder holding expired documents (no one has rights on it; administrators use dedicated screens). */
    public static function archiveRoot(): array
    {
        $row = DB::one("SELECT * FROM fs_folders WHERE kind = 'archive' AND parent_id IS NULL ORDER BY id LIMIT 1");
        if ($row) {
            return $row;
        }
        $id = DB::insert('fs_folders', ['parent_id' => null, 'kind' => 'archive', 'owner_id' => null, 'name' => 'archive', 'created_at' => time(), 'updated_at' => time()]);
        return self::folder($id);
    }

    /** Scheduler: reminders before expiry, archiving after. Returns [reminders, archived]. */
    public static function processExpiry(?int $now = null): array
    {
        $now ??= time();
        $archive = (int) self::archiveRoot()['id'];
        $sent = 0;
        // Thresholds in days with the stage they set: the most urgent reminder due is the one sent.
        $stages = [3 => 2, 2 => 7, 1 => 30];
        foreach (DB::all('SELECT * FROM fs_files WHERE deleted_at = 0 AND archived_at = 0 AND folder_id <> :a AND expires_at > :n AND expires_at <= :lim AND expiry_stage < 3',
            ['a' => $archive, 'n' => $now, 'lim' => $now + 30 * 86400]) as $f) {
            if (self::isDeleted((int) $f['folder_id'])) {
                continue;
            }
            $left = (int) $f['expires_at'] - $now;
            foreach ($stages as $stage => $days) {
                if ($left <= $days * 86400) {
                    if ((int) $f['expiry_stage'] < $stage) {
                        DB::update('fs_files', ['expiry_stage' => $stage], 'id = :id', ['id' => $f['id']]);
                        self::expiryMail($f, 'reminder', $left);
                        $sent++;
                    }
                    break;
                }
            }
        }
        $archived = 0;
        foreach (DB::all('SELECT * FROM fs_files WHERE deleted_at = 0 AND archived_at = 0 AND folder_id <> :a AND expires_at > 0 AND expires_at <= :n LIMIT 500',
            ['a' => $archive, 'n' => $now]) as $f) {
            if (self::isDeleted((int) $f['folder_id'])) {
                continue; // in the trash: the trash rules apply
            }
            DB::transaction(function () use ($f, $archive, $now) {
                DB::update('fs_files', ['archived_at' => $now, 'archived_from' => (int) $f['folder_id'], 'folder_id' => $archive], 'id = :id', ['id' => $f['id']]);
                DB::delete('fs_file_acl', 'file_id = :f', ['f' => $f['id']]);
            });
            self::touch((int) $f['folder_id']);
            self::expiryMail($f, 'archived', 0);
            Audit::log('files.archived', $f['name'], ['folder' => (int) $f['folder_id']], null);
            $archived++;
        }
        self::resetCache();
        return [$sent, $archived];
    }

    /** Archived documents (administration). */
    public static function archived(): array
    {
        $rows = DB::all('SELECT f.*, u.email AS creator_email FROM fs_files f LEFT JOIN users u ON u.id = f.created_by WHERE f.archived_at > 0 AND f.deleted_at = 0 ORDER BY f.archived_at DESC LIMIT 500');
        foreach ($rows as &$r) {
            $from = (int) ($r['archived_from'] ?? 0);
            $r['origin'] = $from && self::folder($from) ? self::originName($from) : '—';
        }
        return $rows;
    }

    private static function originName(int $folderId): string
    {
        $names = [];
        foreach (self::chain($folderId) as $f) {
            $names[] = $f['kind'] === 'personal' ? t('files.my_files') . ' (' . self::userName((int) $f['owner_id']) . ')' : $f['name'];
        }
        return implode(' / ', $names);
    }

    /** Administration: put an archived document back where it was (or in its creator's files), with a new period. */
    public static function restoreArchived(int $fileId, int $by): array
    {
        $f = DB::one('SELECT * FROM fs_files WHERE id = :id AND archived_at > 0', ['id' => $fileId]);
        if (!$f) {
            throw new \M4W\Core\HttpException(404, t('files.not_found'));
        }
        $target = (int) ($f['archived_from'] ?? 0);
        if (!$target || !self::folder($target) || self::isDeleted($target)) {
            $creator = DB::value("SELECT id FROM users WHERE id = :u AND status = 'active'", ['u' => (int) $f['created_by']]);
            $target = (int) self::personalRoot((int) ($creator ?: $by))['id'];
        }
        DB::update('fs_files', [
            'folder_id' => $target, 'name' => self::freeName($target, $f['name'], 'file'), 'archived_at' => 0, 'archived_from' => null,
            'expires_at' => time() + self::defaultExpiryDays() * 86400, 'expiry_stage' => 0,
        ], 'id = :id', ['id' => $fileId]);
        self::touch($target);
        self::resetCache();
        Audit::log('files.unarchived', $f['name'], ['folder' => $target], $by);
        return self::fileOut(self::file($fileId));
    }

    /** Reminder or archiving notice, delivered to the creator's inbox (an administrator's if the creator is gone). */
    private static function expiryMail(array $f, string $kind, int $left): void
    {
        try {
            $to = DB::one("SELECT * FROM users WHERE id = :u AND status = 'active'", ['u' => (int) $f['created_by']]);
            $orphan = !$to;
            $to ??= DB::one("SELECT * FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id LIMIT 1");
            if (!$to) {
                return;
            }
            $to = Users::find((int) $to['id']);
            $domain = \M4W\Mail\Address::domain($to['email']) ?: 'localhost';
            $where = self::originName($kind === 'archived' ? (int) $f['folder_id'] : (int) $f['folder_id']);
            $b = new \M4W\Mail\MimeBuilder();
            $b->from = ['email' => 'no-reply@' . $domain, 'name' => (string) Settings::get('brand.name', 'Make4WebMail')];
            $b->to = [['email' => $to['email'], 'name' => $to['name']]];
            $b->messageId = \M4W\Mail\MimeBuilder::newMessageId($domain);
            $b->extraHeaders['Auto-Submitted'] = 'auto-generated';
            $b->extraHeaders['X-M4W-Files'] = 'expiry-' . $kind;
            $box = 'font-family:Arial,sans-serif;font-size:14px;color:#1f2937';
            $intro = $orphan ? '<p style="color:#64748b">' . e(t('files.expiry_orphan', ['name' => self::userName((int) $f['created_by'])])) . '</p>' : '';
            if ($kind === 'reminder') {
                $days = max(1, (int) ceil($left / 86400));
                $b->subject = t('files.expiry_reminder_subject', ['name' => $f['name'], 'days' => $days]);
                $link = public_url('files#e/' . (int) $f['id']);
                $b->html = '<div style="' . $box . '">' . $intro
                    . '<p>' . e(t('files.expiry_reminder_body', ['name' => $f['name'], 'date' => format_datetime((int) $f['expires_at'], false), 'days' => $days])) . '</p>'
                    . '<p style="color:#64748b">' . e($where) . '</p>'
                    . '<p><a href="' . e($link) . '" style="display:inline-block;background:#2563eb;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none">' . e(t('files.expiry_extend')) . '</a></p>'
                    . '<p style="color:#64748b">' . e(t('files.expiry_reminder_footer')) . '</p></div>';
            } else {
                $b->subject = t('files.expiry_archived_subject', ['name' => $f['name']]);
                $max = max(1, (int) Settings::get('security.max_attachment_mb', 25)) * 1048576;
                $attached = (int) $f['size'] <= $max;
                $b->html = '<div style="' . $box . '">' . $intro
                    . '<p>' . e(t('files.expiry_archived_body', ['name' => $f['name'], 'date' => format_datetime((int) $f['expires_at'], false)])) . '</p>'
                    . '<p style="color:#64748b">' . e($where) . '</p>'
                    . '<p>' . e(t($attached ? 'files.expiry_archived_attached' : 'files.expiry_archived_too_big')) . '</p></div>';
                if ($attached) {
                    $b->attachments[] = ['name' => $f['name'], 'mime' => $f['mime'] ?: 'application/octet-stream', 'content' => self::contents($f['blob_id'])];
                }
            }
            $inbox = Folders::byRole((int) $to['id'], 'inbox');
            Mailbox::store((int) $to['id'], (int) $inbox['id'], $b->build());
        } catch (\Throwable $e) {
            error_log('[m4w] files expiry mail: ' . $e->getMessage());
        }
    }

    /** Principals a user matches in access entries. */
    private static function principals(array $user): array
    {
        $p = [['user', (string) $user['id']], ['all', '*']];
        $dept = mb_strtolower(trim((string) ($user['department'] ?? '')));
        if ($dept !== '') {
            $p[] = ['department', $dept];
        }
        return $p;
    }

    /**
     * Effective level on a folder. Deleted folders (or folders below a deleted one)
     * give NONE unless $includeDeleted, used by the trash.
     */
    public static function permission(array $user, int $folderId, bool $includeDeleted = false): int
    {
        $key = $user['id'] . ':' . $folderId . ':' . (int) $includeDeleted;
        if (isset(self::$permCache[$key])) {
            return self::$permCache[$key];
        }
        $chain = self::chain($folderId);
        $level = self::NONE;
        if ($chain && (int) end($chain)['id'] === $folderId) {
            $deleted = false;
            foreach ($chain as $f) {
                $deleted = $deleted || (int) $f['deleted_at'] > 0;
            }
            $root = $chain[0];
            if ($deleted && !$includeDeleted) {
                $level = self::NONE;
            } elseif ($root['kind'] === 'personal' && (int) $root['owner_id'] === (int) $user['id']) {
                $level = self::MANAGE;
            } elseif ($root['kind'] === 'space' && ($user['role'] ?? '') === 'admin') {
                $level = self::MANAGE;
            } else {
                $ids = array_map(static fn($f) => (int) $f['id'], $chain);
                [$in, $params] = DB::in('f', $ids);
                $or = [];
                foreach (self::principals($user) as $i => [$type, $value]) {
                    $or[] = "(principal_type = :t$i AND principal = :v$i)";
                    $params["t$i"] = $type;
                    $params["v$i"] = $value;
                }
                $level = (int) (DB::value("SELECT MAX(level) FROM fs_acl WHERE folder_id IN $in AND (" . implode(' OR ', $or) . ')', $params) ?? 0);
            }
        }
        return self::$permCache[$key] = $level;
    }

    /** @throws \M4W\Core\HttpException */
    public static function require(array $user, int $folderId, int $level): array
    {
        $f = self::folder($folderId);
        $have = $f ? self::permission($user, $folderId) : self::NONE;
        if ($have < $level) {
            throw new \M4W\Core\HttpException($have === self::NONE ? 404 : 403, t($have === self::NONE ? 'files.not_found' : 'files.forbidden'));
        }
        return $f;
    }

    public static function canCreateSpaces(array $user): bool
    {
        return ($user['role'] ?? '') === 'admin' || (int) Settings::get('files.users_create_spaces', 0) === 1;
    }

    public static function createSpace(array $user, string $name, string $description = ''): int
    {
        if (!self::canCreateSpaces($user)) {
            throw new \M4W\Core\HttpException(403, t('files.forbidden'));
        }
        $name = self::cleanName($name);
        $id = DB::insert('fs_folders', [
            'parent_id' => null, 'kind' => 'space', 'owner_id' => (int) $user['id'], 'name' => $name,
            'description' => mb_substr(trim($description), 0, 500), 'created_by' => (int) $user['id'],
            'created_at' => time(), 'updated_at' => time(),
        ]);
        // The creator manages the space they created, even without being an administrator.
        DB::insert('fs_acl', ['folder_id' => $id, 'principal_type' => 'user', 'principal' => (string) $user['id'], 'level' => self::MANAGE, 'created_by' => (int) $user['id'], 'created_at' => time()]);
        Audit::log('files.space_created', $name, [], (int) $user['id']);
        return $id;
    }

    public static function createFolder(array $user, int $parentId, string $name): int
    {
        self::require($user, $parentId, self::WRITE);
        $name = self::freeName($parentId, self::cleanName($name), 'folder');
        $id = DB::insert('fs_folders', [
            'parent_id' => $parentId, 'kind' => 'folder', 'owner_id' => null, 'name' => $name,
            'created_by' => (int) $user['id'], 'created_at' => time(), 'updated_at' => time(),
        ]);
        self::touch($parentId);
        return $id;
    }

    /** Find or create the sub-folders of a relative path ("a/b/c") under $parentId (folder uploads). */
    public static function ensurePath(array $user, int $parentId, string $relDir): int
    {
        $id = $parentId;
        foreach (array_slice(array_filter(explode('/', str_replace('\\', '/', $relDir)), static fn($p) => trim($p) !== ''), 0, 20) as $part) {
            $name = self::cleanName($part);
            $existing = DB::value('SELECT id FROM fs_folders WHERE parent_id = :p AND deleted_at = 0 AND LOWER(name) = LOWER(:n)', ['p' => $id, 'n' => $name]);
            $id = $existing !== null ? (int) $existing : self::createFolder($user, $id, $name);
        }
        return $id;
    }

    private static function touch(int $folderId): void
    {
        DB::update('fs_folders', ['updated_at' => time()], 'id = :id', ['id' => $folderId]);
    }

    /** Spaces, folders and files visible to the user, for the sidebar. */
    public static function roots(array $user): array
    {
        $personal = self::personalRoot((int) $user['id']);
        $spaces = [];
        foreach (DB::all("SELECT * FROM fs_folders WHERE kind = 'space' AND deleted_at = 0 ORDER BY name") as $s) {
            $lvl = self::permission($user, (int) $s['id']);
            if ($lvl >= self::READ) {
                $spaces[] = self::folderOut($s, $lvl);
            }
        }
        return [
            'personal' => (int) $personal['id'],
            'spaces' => $spaces,
            'shared' => self::sharedWithMe($user),
            'shared_files' => self::sharedFilesWithMe($user),
            'can_create_space' => self::canCreateSpaces($user),
            'usage' => self::usage(),
            'trash' => count(self::trash($user)),
            'trash_days' => max(1, (int) Settings::get('files.trash_days', 30)),
        ];
    }

    /**
     * Folders shared with the user that they cannot reach from a space they see
     * or from their own tree: the top-most granted folder of each branch.
     */
    public static function sharedWithMe(array $user): array
    {
        $params = [];
        $or = [];
        foreach (self::principals($user) as $i => [$type, $value]) {
            $or[] = "(a.principal_type = :t$i AND a.principal = :v$i)";
            $params["t$i"] = $type;
            $params["v$i"] = $value;
        }
        $rows = DB::all('SELECT DISTINCT f.* FROM fs_acl a JOIN fs_folders f ON f.id = a.folder_id WHERE f.deleted_at = 0 AND (' . implode(' OR ', $or) . ') ORDER BY f.name', $params);
        $out = [];
        foreach ($rows as $f) {
            $chain = self::chain((int) $f['id']);
            if (!$chain || $chain[0]['kind'] === 'space') {
                continue; // spaces are listed on their own
            }
            if ($chain[0]['kind'] === 'personal' && (int) $chain[0]['owner_id'] === (int) $user['id']) {
                continue;
            }
            $lvl = self::permission($user, (int) $f['id']);
            if ($lvl < self::READ) {
                continue;
            }
            $parent = (int) ($f['parent_id'] ?? 0);
            if ($parent && self::permission($user, $parent) >= self::READ) {
                continue; // reachable through an upper shared folder
            }
            $o = self::folderOut($f, $lvl);
            $o['owner'] = self::userName((int) $chain[0]['owner_id']);
            $out[] = $o;
        }
        return $out;
    }

    private static function userName(int $id): string
    {
        static $cache = [];
        if (!isset($cache[$id])) {
            $u = DB::one('SELECT display_name, email FROM users WHERE id = :id', ['id' => $id]);
            $cache[$id] = $u ? ($u['display_name'] ?: $u['email']) : '—';
        }
        return $cache[$id];
    }

    public static function folderOut(array $f, int $level): array
    {
        return [
            'id' => (int) $f['id'], 'parent_id' => $f['parent_id'] !== null ? (int) $f['parent_id'] : null,
            'kind' => $f['kind'], 'name' => $f['kind'] === 'personal' ? t('files.my_files') : $f['name'],
            'description' => (string) $f['description'], 'updated_at' => (int) $f['updated_at'],
            'created_by' => $f['created_by'] ? self::userName((int) $f['created_by']) : '',
            'level' => self::levelName($level), 'shared' => (bool) DB::value('SELECT 1 FROM fs_acl WHERE folder_id = :f LIMIT 1', ['f' => $f['id']]),
        ];
    }

    public static function fileOut(array $f): array
    {
        return [
            'id' => (int) $f['id'], 'folder_id' => (int) $f['folder_id'], 'name' => $f['name'], 'mime' => $f['mime'],
            'shared' => (bool) DB::value('SELECT 1 FROM fs_file_acl WHERE file_id = :f LIMIT 1', ['f' => $f['id']]),
            'size' => (int) $f['size'], 'version' => (int) $f['version'], 'updated_at' => (int) $f['updated_at'],
            'created_at' => (int) $f['created_at'], 'expires_at' => (int) ($f['expires_at'] ?? 0),
            'updated_by' => $f['updated_by'] ? self::userName((int) $f['updated_by']) : '',
        ];
    }

    public static function listing(array $user, int $folderId): array
    {
        $f = self::require($user, $folderId, self::READ);
        $level = self::permission($user, $folderId);
        $folders = [];
        foreach (DB::all('SELECT * FROM fs_folders WHERE parent_id = :p AND deleted_at = 0 ORDER BY name', ['p' => $folderId]) as $sub) {
            $folders[] = self::folderOut($sub, self::permission($user, (int) $sub['id']));
        }
        $files = array_map([self::class, 'fileOut'], DB::all('SELECT * FROM fs_files WHERE folder_id = :p AND deleted_at = 0 ORDER BY name', ['p' => $folderId]));
        return [
            'folder' => self::folderOut($f, $level),
            'path' => self::breadcrumb($user, $folderId),
            'folders' => $folders,
            'files' => $files,
        ];
    }

    /** Breadcrumb limited to the part of the tree the user may see. */
    public static function breadcrumb(array $user, int $folderId): array
    {
        $out = [];
        foreach (self::chain($folderId) as $f) {
            $lvl = self::permission($user, (int) $f['id']);
            if ($lvl < self::READ) {
                $out = [];
                continue;
            }
            $out[] = ['id' => (int) $f['id'], 'name' => $f['kind'] === 'personal' ? t('files.my_files') : $f['name'], 'kind' => $f['kind']];
        }
        return $out;
    }

    public static function search(array $user, string $q, int $limit = 100): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return ['folders' => [], 'files' => []];
        }
        $like = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $files = [];
        foreach (DB::all("SELECT * FROM fs_files WHERE deleted_at = 0 AND name LIKE :q ESCAPE '!' ORDER BY updated_at DESC LIMIT 1000", ['q' => $like]) as $f) {
            if (self::permission($user, (int) $f['folder_id']) >= self::READ) {
                $o = self::fileOut($f);
                $o['path'] = implode(' / ', array_column(self::breadcrumb($user, (int) $f['folder_id']), 'name'));
                $files[] = $o;
                if (count($files) >= $limit) {
                    break;
                }
            }
        }
        $folders = [];
        foreach (DB::all("SELECT * FROM fs_folders WHERE deleted_at = 0 AND kind = 'folder' AND name LIKE :q ESCAPE '!' ORDER BY name LIMIT 500", ['q' => $like]) as $f) {
            $lvl = self::permission($user, (int) $f['id']);
            if ($lvl >= self::READ) {
                $o = self::folderOut($f, $lvl);
                $o['path'] = implode(' / ', array_column(self::breadcrumb($user, (int) $f['parent_id']), 'name'));
                $folders[] = $o;
            }
        }
        return ['folders' => array_slice($folders, 0, 50), 'files' => $files];
    }

    // ------------------------------------------------------------------ files

    public static function file(int $id, bool $includeDeleted = false): ?array
    {
        return DB::one('SELECT * FROM fs_files WHERE id = :id' . ($includeDeleted ? '' : ' AND deleted_at = 0'), ['id' => $id]);
    }

    public static function requireFile(array $user, int $id, int $level): array
    {
        $f = self::file($id);
        $have = $f ? self::filePermission($user, $f) : self::NONE;
        if ($have < $level) {
            throw new \M4W\Core\HttpException($have >= self::READ ? 403 : 404, t($have >= self::READ ? 'files.forbidden' : 'files.not_found'));
        }
        return $f;
    }

    /** Effective level on a file: its folder's, or read access when the file itself is shared. */
    public static function filePermission(array $user, array $file): int
    {
        $level = self::permission($user, (int) $file['folder_id']);
        if ($level >= self::READ || (int) $file['deleted_at'] > 0 || self::isDeleted((int) $file['folder_id'])) {
            return $level;
        }
        [$where, $params] = self::principalWhere($user);
        $params['f'] = (int) $file['id'];
        $grant = (int) (DB::value("SELECT MAX(level) FROM fs_file_acl WHERE file_id = :f AND ($where)", $params) ?? 0);
        return min($grant, self::READ);
    }

    /** SQL condition matching the user, their department or everyone. @return array{string, array} */
    private static function principalWhere(array $user, string $alias = ''): array
    {
        $or = [];
        $params = [];
        foreach (self::principals($user) as $i => [$type, $value]) {
            $or[] = "({$alias}principal_type = :pt$i AND {$alias}principal = :pv$i)";
            $params["pt$i"] = $type;
            $params["pv$i"] = $value;
        }
        return [implode(' OR ', $or), $params];
    }

    /** Files shared one by one with the user, that their folder rights do not already show. */
    public static function sharedFilesWithMe(array $user): array
    {
        [$where, $params] = self::principalWhere($user, 'a.');
        $rows = DB::all("SELECT DISTINCT f.* FROM fs_file_acl a JOIN fs_files f ON f.id = a.file_id WHERE f.deleted_at = 0 AND ($where) ORDER BY f.name", $params);
        $out = [];
        foreach ($rows as $f) {
            if (self::isDeleted((int) $f['folder_id']) || self::permission($user, (int) $f['folder_id']) >= self::READ) {
                continue;
            }
            $o = self::fileOut($f);
            $root = self::chain((int) $f['folder_id'])[0] ?? null;
            $o['owner'] = $root && $root['kind'] === 'personal' ? self::userName((int) $root['owner_id']) : ($root['name'] ?? '');
            $o['level'] = 'read';
            $o['shared_file'] = true;
            $out[] = $o;
        }
        return $out;
    }

    /** People a file is shared with (managers of its folder only). */
    public static function fileAcl(array $user, int $fileId): array
    {
        $f = self::requireFile($user, $fileId, self::READ);
        self::require($user, (int) $f['folder_id'], self::MANAGE);
        $entries = [];
        foreach (DB::all('SELECT * FROM fs_file_acl WHERE file_id = :f ORDER BY principal_type, principal', ['f' => $fileId]) as $a) {
            $entries[] = self::aclOut($a);
        }
        // Who already sees it through its folder, shown read-only.
        $inherited = [];
        foreach (self::chain((int) $f['folder_id']) as $anc) {
            foreach (DB::all('SELECT * FROM fs_acl WHERE folder_id = :f', ['f' => $anc['id']]) as $a) {
                $o = self::aclOut($a);
                $o['from'] = $anc['kind'] === 'personal' ? t('files.my_files') : $anc['name'];
                $inherited[] = $o;
            }
        }
        $root = self::chain((int) $f['folder_id'])[0];
        return [
            'file' => self::fileOut($f), 'entries' => $entries, 'inherited' => $inherited,
            'owner' => $root['kind'] === 'personal' ? self::userName((int) $root['owner_id']) : '',
        ];
    }

    /** Replace the people a file is shared with (read access). */
    public static function setFileAcl(array $user, int $fileId, array $entries): void
    {
        $f = self::requireFile($user, $fileId, self::READ);
        self::require($user, (int) $f['folder_id'], self::MANAGE);
        $clean = [];
        foreach (array_slice($entries, 0, 500) as $e) {
            if (!is_array($e)) {
                continue;
            }
            $type = (string) ($e['type'] ?? '');
            $principal = trim((string) ($e['principal'] ?? ''));
            if ($type === 'user' && DB::value('SELECT 1 FROM users WHERE id = :id', ['id' => (int) $principal])) {
                $principal = (string) (int) $principal;
            } elseif ($type === 'department' && $principal !== '') {
                $principal = mb_substr(mb_strtolower($principal), 0, 190);
            } elseif ($type === 'all') {
                $principal = '*';
            } else {
                continue;
            }
            $clean[$type . ':' . $principal] = ['type' => $type, 'principal' => $principal];
        }
        DB::transaction(function () use ($fileId, $clean, $user) {
            DB::delete('fs_file_acl', 'file_id = :f', ['f' => $fileId]);
            foreach ($clean as $c) {
                DB::insert('fs_file_acl', [
                    'file_id' => $fileId, 'principal_type' => $c['type'], 'principal' => $c['principal'], 'level' => self::READ,
                    'created_by' => (int) $user['id'], 'created_at' => time(),
                ]);
            }
        });
        self::resetCache();
        Audit::log('files.file_shared', $f['name'], ['entries' => count($clean)], (int) $user['id']);
    }

    public static function maxFileBytes(): int
    {
        return max(1, (int) Settings::get('files.max_file_mb', 200)) * 1024 * 1024;
    }

    /** Bytes used by all file contents (current versions + history), and the instance quota (0 = none). */
    public static function usage(): array
    {
        $used = (int) (DB::value('SELECT SUM(size) FROM fs_blobs') ?? 0);
        return ['used' => $used, 'quota' => max(0, (int) Settings::get('files.quota_gb', 0)) * 1024 * 1024 * 1024];
    }

    private static function checkQuota(int $adding): void
    {
        $u = self::usage();
        if ($u['quota'] > 0 && $u['used'] + $adding > $u['quota']) {
            throw new \InvalidArgumentException(t('files.quota_full'));
        }
    }

    /** Store a local file as a new blob; returns [id, size, sha256]. */
    private static function storeBlob(string $path): array
    {
        $size = (int) filesize($path);
        self::checkQuota($size);
        $id = bin2hex(random_bytes(16));
        $sha = (string) hash_file('sha256', $path);
        $in = fopen($path, 'rb');
        if (!$in) {
            throw new \RuntimeException('Cannot read file');
        }
        try {
            $size = Storage::store()->put($id, $in);
        } catch (\Throwable $e) {
            Storage::store()->delete($id);
            throw $e;
        } finally {
            fclose($in);
        }
        DB::insert('fs_blobs', ['id' => $id, 'size' => $size, 'sha256' => $sha, 'created_at' => time()]);
        return [$id, $size, $sha];
    }

    private static function deleteBlob(string $id): void
    {
        Storage::store()->delete($id);
        DB::delete('fs_blobs', 'id = :id', ['id' => $id]);
    }

    public static function sniffMime(string $path, string $name): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        // finfo reports Office files as zip archives: trust the extension for well-known containers.
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $office = [
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
            'odp' => 'application/vnd.oasis.opendocument.presentation',
        ];
        if (isset($office[$ext]) && in_array($mime, ['application/zip', 'application/octet-stream'], true)) {
            return $office[$ext];
        }
        return mb_substr($mime, 0, 190);
    }

    /**
     * New content for an existing document (online editing): the previous content
     * becomes a version, as with an upload of the same name. Needs the right to modify.
     */
    public static function putContent(array $user, int $fileId, string $path): array
    {
        $f = self::requireFile($user, $fileId, self::WRITE);
        $size = (int) filesize($path);
        if ($size > self::maxFileBytes()) {
            throw new \InvalidArgumentException(t('files.too_big', ['mb' => (int) Settings::get('files.max_file_mb', 200)]));
        }
        if (hash_file('sha256', $path) === $f['sha256']) {
            return self::fileOut($f); // unchanged: no new version
        }
        [$blob, $size, $sha] = self::storeBlob($path);
        DB::transaction(function () use ($f, $user, $blob, $size, $sha) {
            self::archiveVersion($f);
            DB::update('fs_files', [
                'blob_id' => $blob, 'size' => $size, 'sha256' => $sha, 'version' => (int) $f['version'] + 1,
                'updated_by' => (int) $user['id'], 'updated_at' => time(),
            ], 'id = :id', ['id' => $f['id']]);
        });
        self::pruneVersions((int) $f['id']);
        self::touch((int) $f['folder_id']);
        Audit::log('files.edited', $f['name'], ['version' => (int) $f['version'] + 1], (int) $user['id']);
        return self::fileOut(self::file($fileId));
    }

    /**
     * Add a file from a local path. With $replace, a file of the same name becomes a
     * new version of the existing one instead of a renamed copy.
     */
    public static function addFromPath(array $user, int $folderId, string $path, string $name, bool $replace = false, ?string $mime = null): array
    {
        self::require($user, $folderId, self::WRITE);
        $name = self::cleanName($name);
        $av = Antivirus::scanFile($path);
        if ($av['status'] === 'infected') {
            Audit::log('files.virus_refused', $name, ['signature' => $av['signature']], (int) $user['id']);
            throw new \InvalidArgumentException(t('av.upload_infected', ['name' => $name, 'signature' => $av['signature']]));
        }
        $size = (int) filesize($path);
        if ($size > self::maxFileBytes()) {
            throw new \InvalidArgumentException(t('files.too_big', ['mb' => (int) Settings::get('files.max_file_mb', 200)]));
        }
        $mime = $mime ?: self::sniffMime($path, $name);
        [$blob, $size, $sha] = self::storeBlob($path);
        $existing = $replace ? DB::one('SELECT * FROM fs_files WHERE folder_id = :f AND deleted_at = 0 AND LOWER(name) = LOWER(:n)', ['f' => $folderId, 'n' => $name]) : null;
        $now = time();
        if ($existing) {
            DB::transaction(function () use ($existing, $user, $blob, $size, $sha, $mime, $now) {
                self::archiveVersion($existing);
                DB::update('fs_files', [
                    'blob_id' => $blob, 'size' => $size, 'sha256' => $sha, 'mime' => $mime, 'version' => (int) $existing['version'] + 1,
                    'updated_by' => (int) $user['id'], 'updated_at' => $now,
                ], 'id = :id', ['id' => $existing['id']]);
            });
            self::pruneVersions((int) $existing['id']);
            $id = (int) $existing['id'];
            Audit::log('files.updated', $name, ['version' => (int) $existing['version'] + 1], (int) $user['id']);
        } else {
            $id = DB::insert('fs_files', [
                'folder_id' => $folderId, 'name' => self::freeName($folderId, $name, 'file'), 'mime' => $mime, 'size' => $size,
                'blob_id' => $blob, 'sha256' => $sha, 'version' => 1, 'created_by' => (int) $user['id'], 'updated_by' => (int) $user['id'],
                'created_at' => $now, 'updated_at' => $now, 'expires_at' => $now + self::defaultExpiryDays() * 86400,
            ]);
            Audit::log('files.uploaded', $name, ['folder' => $folderId], (int) $user['id']);
        }
        self::touch($folderId);
        return self::fileOut(self::file($id));
    }

    /** Same as addFromPath for in-memory content (e.g. a mail attachment). */
    public static function addFromString(array $user, int $folderId, string $content, string $name, ?string $mime = null): array
    {
        $tmp = tempnam(storage_path('tmp'), 'fs');
        file_put_contents($tmp, $content);
        try {
            return self::addFromPath($user, $folderId, $tmp, $name, false, $mime && $mime !== 'application/octet-stream' ? $mime : null);
        } finally {
            @unlink($tmp);
        }
    }

    private static function archiveVersion(array $file): void
    {
        DB::insert('fs_versions', [
            'file_id' => (int) $file['id'], 'version' => (int) $file['version'], 'name' => $file['name'], 'mime' => $file['mime'],
            'size' => (int) $file['size'], 'blob_id' => $file['blob_id'], 'sha256' => $file['sha256'],
            'created_by' => $file['updated_by'], 'created_at' => (int) $file['updated_at'],
        ]);
    }

    private static function pruneVersions(int $fileId): void
    {
        $keep = max(0, (int) Settings::get('files.versions', 10));
        $old = DB::all('SELECT id, blob_id FROM fs_versions WHERE file_id = :f ORDER BY version DESC', ['f' => $fileId]);
        foreach (array_slice($old, $keep) as $v) {
            self::deleteBlob($v['blob_id']);
            DB::delete('fs_versions', 'id = :id', ['id' => $v['id']]);
        }
    }

    public static function versions(array $user, int $fileId): array
    {
        $f = self::requireFile($user, $fileId, self::READ);
        $out = [array_merge(self::fileOut($f), ['current' => true, 'version_id' => 0])];
        foreach (DB::all('SELECT * FROM fs_versions WHERE file_id = :f ORDER BY version DESC', ['f' => $fileId]) as $v) {
            $out[] = [
                'version_id' => (int) $v['id'], 'version' => (int) $v['version'], 'name' => $v['name'], 'size' => (int) $v['size'],
                'updated_at' => (int) $v['created_at'], 'updated_by' => $v['created_by'] ? self::userName((int) $v['created_by']) : '', 'current' => false,
            ];
        }
        return $out;
    }

    public static function restoreVersion(array $user, int $fileId, int $versionId): void
    {
        $f = self::requireFile($user, $fileId, self::WRITE);
        $v = DB::one('SELECT * FROM fs_versions WHERE id = :id AND file_id = :f', ['id' => $versionId, 'f' => $fileId]);
        if (!$v) {
            throw new \M4W\Core\HttpException(404, t('files.not_found'));
        }
        // Copy the old content into a new blob: history stays intact and append-only.
        $tmp = self::toTempFile($v['blob_id']);
        try {
            [$blob, $size, $sha] = self::storeBlob($tmp);
        } finally {
            @unlink($tmp);
        }
        DB::transaction(function () use ($f, $user, $v, $blob, $size, $sha) {
            self::archiveVersion($f);
            DB::update('fs_files', [
                'blob_id' => $blob, 'size' => $size, 'sha256' => $sha, 'mime' => $v['mime'], 'version' => (int) $f['version'] + 1,
                'updated_by' => (int) $user['id'], 'updated_at' => time(),
            ], 'id = :id', ['id' => $f['id']]);
        });
        self::pruneVersions($fileId);
        Audit::log('files.version_restored', $f['name'], ['version' => (int) $v['version']], (int) $user['id']);
    }

    /** Stream content to the output (or any sink). */
    public static function stream(string $blobId, callable $sink): void
    {
        Storage::store()->read($blobId, $sink);
    }

    public static function toTempFile(string $blobId): string
    {
        $tmp = tempnam(storage_path('tmp'), 'fs');
        $out = fopen($tmp, 'wb');
        self::stream($blobId, static function (string $buf) use ($out) {
            fwrite($out, $buf);
        });
        fclose($out);
        return $tmp;
    }

    public static function contents(string $blobId): string
    {
        $s = '';
        self::stream($blobId, static function (string $buf) use (&$s) {
            $s .= $buf;
        });
        return $s;
    }

    // ------------------------------------------------------- item operations

    /** @param array<int, array{type:string,id:int}> $items */
    public static function normalizeItems(array $items): array
    {
        $out = [];
        foreach ($items as $it) {
            if (is_array($it) && in_array($it['type'] ?? '', ['file', 'folder'], true) && (int) ($it['id'] ?? 0) > 0) {
                $out[$it['type'] . ':' . (int) $it['id']] = ['type' => $it['type'], 'id' => (int) $it['id']];
            }
        }
        return array_values(array_slice($out, 0, 500));
    }

    public static function rename(array $user, string $type, int $id, string $name): string
    {
        $name = self::cleanName($name);
        if ($type === 'file') {
            $f = self::requireFile($user, $id, self::WRITE);
            $name = self::freeName((int) $f['folder_id'], $name, 'file', $id);
            DB::update('fs_files', ['name' => $name, 'updated_at' => time(), 'updated_by' => (int) $user['id']], 'id = :id', ['id' => $id]);
            return $name;
        }
        $f = self::folder($id);
        if (!$f || (int) $f['deleted_at'] > 0) {
            throw new \M4W\Core\HttpException(404, t('files.not_found'));
        }
        if ($f['kind'] === 'personal') {
            throw new \M4W\Core\HttpException(403, t('files.forbidden'));
        }
        // A space is renamed by its managers; a sub-folder by whoever may write in its parent.
        $f['kind'] === 'space' ? self::require($user, $id, self::MANAGE) : self::require($user, (int) $f['parent_id'], self::WRITE);
        if ($f['kind'] === 'folder') {
            $name = self::freeName((int) $f['parent_id'], $name, 'folder', $id);
        }
        DB::update('fs_folders', ['name' => $name, 'updated_at' => time()], 'id = :id', ['id' => $id]);
        unset(self::$folderCache[$id]);
        return $name;
    }

    public static function updateSpace(array $user, int $id, string $name, string $description): void
    {
        $f = self::require($user, $id, self::MANAGE);
        if ($f['kind'] !== 'space') {
            throw new \M4W\Core\HttpException(400, t('files.forbidden'));
        }
        DB::update('fs_folders', ['name' => self::cleanName($name), 'description' => mb_substr(trim($description), 0, 500), 'updated_at' => time()], 'id = :id', ['id' => $id]);
        unset(self::$folderCache[$id]);
    }

    public static function move(array $user, array $items, int $target): int
    {
        self::require($user, $target, self::WRITE);
        $targetChain = array_map(static fn($f) => (int) $f['id'], self::chain($target));
        $n = 0;
        foreach (self::normalizeItems($items) as $it) {
            if ($it['type'] === 'file') {
                $f = self::requireFile($user, $it['id'], self::WRITE);
                if ((int) $f['folder_id'] === $target) {
                    continue;
                }
                DB::update('fs_files', ['folder_id' => $target, 'name' => self::freeName($target, $f['name'], 'file'), 'updated_at' => time()], 'id = :id', ['id' => $f['id']]);
                self::touch((int) $f['folder_id']);
            } else {
                $f = self::folder($it['id']);
                if (!$f || $f['kind'] !== 'folder' || (int) $f['deleted_at'] > 0) {
                    throw new \M4W\Core\HttpException(400, t('files.cannot_move'));
                }
                self::require($user, (int) $f['parent_id'], self::WRITE);
                if (in_array((int) $f['id'], $targetChain, true)) {
                    throw new \InvalidArgumentException(t('files.move_into_self'));
                }
                if ((int) $f['parent_id'] === $target) {
                    continue;
                }
                DB::update('fs_folders', ['parent_id' => $target, 'name' => self::freeName($target, $f['name'], 'folder'), 'updated_at' => time()], 'id = :id', ['id' => $f['id']]);
                self::touch((int) $f['parent_id']);
            }
            $n++;
        }
        self::touch($target);
        self::resetCache();
        if ($n) {
            Audit::log('files.moved', (string) $n, ['target' => $target], (int) $user['id']);
        }
        return $n;
    }

    /**
     * "Send to compressed folder": a ZIP of the selected files and folders, created in
     * $target (usually the folder they are in). Sub-folders the user cannot read are left out.
     */
    public static function compress(array $user, array $items, int $target, string $name = ''): array
    {
        self::require($user, $target, self::WRITE);
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZIP extension missing');
        }
        $items = self::normalizeItems($items);
        if (!$items) {
            throw new \InvalidArgumentException(t('files.nothing_selected'));
        }
        $max = self::maxFileBytes();
        $tmp = (string) tempnam(storage_path('tmp'), 'czip');
        $temps = [];
        $total = 0;
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $used = [];
        $entry = static function (string $path) use (&$used): string {
            $base = $path;
            for ($i = 2; isset($used[mb_strtolower($path)]); $i++) {
                $dot = strrpos($base, '.');
                $path = $dot > 0 && !str_ends_with($base, '/') ? substr($base, 0, $dot) . " ($i)" . substr($base, $dot) : rtrim($base, '/') . " ($i)";
            }
            $used[mb_strtolower($path)] = true;
            return $path;
        };
        $addFile = function (array $f, string $prefix) use ($zip, &$temps, &$total, $max, $entry) {
            $total += (int) $f['size'];
            if ($total > $max) {
                throw new \InvalidArgumentException(t('files.too_big', ['mb' => (int) ($max / 1048576)]));
            }
            $temps[] = $p = self::toTempFile($f['blob_id']);
            $zip->addFile($p, $entry($prefix . $f['name']));
        };
        $walk = function (int $folderId, string $prefix, int $depth) use (&$walk, $zip, $addFile, $user, $entry) {
            if ($depth > 30) {
                return;
            }
            $zip->addEmptyDir($entry(rtrim($prefix, '/')));
            foreach (DB::all('SELECT * FROM fs_files WHERE folder_id = :f AND deleted_at = 0 ORDER BY name', ['f' => $folderId]) as $f) {
                $addFile($f, $prefix);
            }
            foreach (DB::all('SELECT * FROM fs_folders WHERE parent_id = :f AND deleted_at = 0 ORDER BY name', ['f' => $folderId]) as $sub) {
                if (self::permission($user, (int) $sub['id']) >= self::READ) {
                    $walk((int) $sub['id'], $prefix . $sub['name'] . '/', $depth + 1);
                }
            }
        };
        $first = '';
        try {
            foreach ($items as $it) {
                if ($it['type'] === 'file') {
                    $f = self::requireFile($user, $it['id'], self::READ);
                    $first = $first ?: $f['name'];
                    $addFile($f, '');
                } else {
                    $f = self::require($user, $it['id'], self::READ);
                    if ($f['kind'] !== 'folder') {
                        continue; // never a whole personal tree or team space by mistake
                    }
                    $first = $first ?: $f['name'] . '/';
                    $walk((int) $f['id'], $f['name'] . '/', 0);
                }
            }
            $zip->close();
            if ($name === '') {
                $name = count($items) === 1 && $first !== '' ? preg_replace('/\.[^.\/]+$/', '', rtrim($first, '/')) . '.zip' : t('files.archive_name') . '.zip';
            } elseif (!str_ends_with(mb_strtolower($name), '.zip')) {
                $name .= '.zip';
            }
            $out = self::addFromPath($user, $target, $tmp, $name, false, 'application/zip');
        } finally {
            foreach ($temps as $t) {
                @unlink($t);
            }
            @unlink($tmp);
        }
        Audit::log('files.compressed', $out['name'], ['items' => count($items)], (int) $user['id']);
        return $out;
    }

    public static function copy(array $user, array $items, int $target): int
    {
        self::require($user, $target, self::WRITE);
        $n = 0;
        foreach (self::normalizeItems($items) as $it) {
            if ($it['type'] === 'file') {
                $f = self::requireFile($user, $it['id'], self::READ);
                self::copyFile($user, $f, $target);
            } else {
                self::require($user, $it['id'], self::READ);
                $targetChain = array_map(static fn($x) => (int) $x['id'], self::chain($target));
                if (in_array($it['id'], $targetChain, true)) {
                    throw new \InvalidArgumentException(t('files.move_into_self'));
                }
                self::copyFolder($user, $it['id'], $target, 0);
            }
            $n++;
        }
        return $n;
    }

    private static function copyFile(array $user, array $f, int $target): void
    {
        $tmp = self::toTempFile($f['blob_id']);
        try {
            self::addFromPath($user, $target, $tmp, $f['name'], false, $f['mime']);
        } finally {
            @unlink($tmp);
        }
    }

    private static function copyFolder(array $user, int $sourceId, int $target, int $depth): void
    {
        if ($depth > 30) {
            return;
        }
        $src = self::folder($sourceId);
        $newId = self::createFolder($user, $target, $src['kind'] === 'personal' ? t('files.my_files') : $src['name']);
        foreach (DB::all('SELECT * FROM fs_files WHERE folder_id = :f AND deleted_at = 0', ['f' => $sourceId]) as $file) {
            self::copyFile($user, $file, $newId);
        }
        foreach (DB::all('SELECT id FROM fs_folders WHERE parent_id = :f AND deleted_at = 0', ['f' => $sourceId]) as $sub) {
            self::copyFolder($user, (int) $sub['id'], $newId, $depth + 1);
        }
    }

    /** Soft delete (trash). */
    public static function delete(array $user, array $items): int
    {
        $n = 0;
        $now = time();
        foreach (self::normalizeItems($items) as $it) {
            if ($it['type'] === 'file') {
                $f = self::requireFile($user, $it['id'], self::WRITE);
                DB::update('fs_files', ['deleted_at' => $now, 'deleted_by' => (int) $user['id']], 'id = :id', ['id' => $f['id']]);
                self::touch((int) $f['folder_id']);
            } else {
                $f = self::folder($it['id']);
                if (!$f || (int) $f['deleted_at'] > 0 || $f['kind'] === 'personal') {
                    throw new \M4W\Core\HttpException(400, t('files.forbidden'));
                }
                $f['kind'] === 'space' ? self::require($user, $f['id'], self::MANAGE) : self::require($user, (int) $f['parent_id'], self::WRITE);
                DB::update('fs_folders', ['deleted_at' => $now, 'deleted_by' => (int) $user['id']], 'id = :id', ['id' => $f['id']]);
            }
            $n++;
        }
        self::resetCache();
        if ($n) {
            Audit::log('files.deleted', (string) $n, [], (int) $user['id']);
        }
        return $n;
    }

    /**
     * Trash: deleted items whose container the user may write to
     * (for a space, the space itself must be managed).
     */
    public static function trash(array $user): array
    {
        $out = [];
        foreach (DB::all('SELECT * FROM fs_folders WHERE deleted_at > 0 ORDER BY deleted_at DESC LIMIT 1000') as $f) {
            $parent = (int) ($f['parent_id'] ?? 0);
            if ($parent && self::isDeleted($parent)) {
                continue; // shown through its deleted ancestor
            }
            $ok = $f['kind'] === 'space'
                ? self::permission($user, (int) $f['id'], true) >= self::MANAGE
                : $parent && self::permission($user, $parent) >= self::WRITE;
            if ($ok) {
                $o = self::folderOut($f, self::permission($user, (int) $f['id'], true));
                $o['type'] = 'folder';
                $o['deleted_at'] = (int) $f['deleted_at'];
                $o['deleted_by'] = $f['deleted_by'] ? self::userName((int) $f['deleted_by']) : '';
                $o['origin'] = $parent ? implode(' / ', array_column(self::breadcrumb($user, $parent), 'name')) : '';
                $out[] = $o;
            }
        }
        foreach (DB::all('SELECT * FROM fs_files WHERE deleted_at > 0 ORDER BY deleted_at DESC LIMIT 2000') as $f) {
            if (self::isDeleted((int) $f['folder_id']) || self::permission($user, (int) $f['folder_id']) < self::WRITE) {
                continue;
            }
            $o = self::fileOut($f);
            $o['type'] = 'file';
            $o['deleted_at'] = (int) $f['deleted_at'];
            $o['deleted_by'] = $f['deleted_by'] ? self::userName((int) $f['deleted_by']) : '';
            $o['origin'] = implode(' / ', array_column(self::breadcrumb($user, (int) $f['folder_id']), 'name'));
            $out[] = $o;
        }
        usort($out, static fn($a, $b) => $b['deleted_at'] <=> $a['deleted_at']);
        return $out;
    }

    private static function isDeleted(int $folderId): bool
    {
        foreach (self::chain($folderId) as $f) {
            if ((int) $f['deleted_at'] > 0) {
                return true;
            }
        }
        return false;
    }

    private static function trashAllowed(array $user, array $it): ?array
    {
        if ($it['type'] === 'file') {
            $f = self::file($it['id'], true);
            if ($f && (int) $f['deleted_at'] > 0 && !self::isDeleted((int) $f['folder_id']) && self::permission($user, (int) $f['folder_id']) >= self::WRITE) {
                return $f;
            }
            return null;
        }
        $f = self::folder($it['id']);
        if (!$f || (int) $f['deleted_at'] === 0) {
            return null;
        }
        $parent = (int) ($f['parent_id'] ?? 0);
        $ok = $f['kind'] === 'space'
            ? self::permission($user, (int) $f['id'], true) >= self::MANAGE
            : $parent && !self::isDeleted($parent) && self::permission($user, $parent) >= self::WRITE;
        return $ok ? $f : null;
    }

    public static function restore(array $user, array $items): int
    {
        $n = 0;
        foreach (self::normalizeItems($items) as $it) {
            $f = self::trashAllowed($user, $it);
            if (!$f) {
                continue;
            }
            if ($it['type'] === 'file') {
                DB::update('fs_files', ['deleted_at' => 0, 'deleted_by' => null, 'name' => self::freeName((int) $f['folder_id'], $f['name'], 'file')], 'id = :id', ['id' => $f['id']]);
            } else {
                $name = $f['parent_id'] ? self::freeName((int) $f['parent_id'], $f['name'], 'folder') : $f['name'];
                DB::update('fs_folders', ['deleted_at' => 0, 'deleted_by' => null, 'name' => $name], 'id = :id', ['id' => $f['id']]);
            }
            $n++;
        }
        self::resetCache();
        return $n;
    }

    public static function purge(array $user, array $items): int
    {
        $n = 0;
        foreach (self::normalizeItems($items) as $it) {
            $f = self::trashAllowed($user, $it);
            if (!$f) {
                continue;
            }
            $it['type'] === 'file' ? self::destroyFile((int) $f['id']) : self::destroyFolder((int) $f['id']);
            $n++;
        }
        self::resetCache();
        if ($n) {
            Audit::log('files.purged', (string) $n, [], (int) $user['id']);
        }
        return $n;
    }

    public static function destroyFile(int $id): void
    {
        $f = DB::one('SELECT * FROM fs_files WHERE id = :id', ['id' => $id]);
        if (!$f) {
            return;
        }
        foreach (DB::all('SELECT blob_id FROM fs_versions WHERE file_id = :f', ['f' => $id]) as $v) {
            self::deleteBlob($v['blob_id']);
        }
        DB::delete('fs_versions', 'file_id = :f', ['f' => $id]);
        DB::delete('fs_file_acl', 'file_id = :f', ['f' => $id]);
        self::deleteBlob($f['blob_id']);
        DB::delete('fs_files', 'id = :id', ['id' => $id]);
    }

    public static function destroyFolder(int $id, int $depth = 0): void
    {
        if ($depth > 64) {
            return;
        }
        foreach (DB::all('SELECT id FROM fs_folders WHERE parent_id = :p', ['p' => $id]) as $sub) {
            self::destroyFolder((int) $sub['id'], $depth + 1);
        }
        foreach (DB::all('SELECT id FROM fs_files WHERE folder_id = :p', ['p' => $id]) as $f) {
            self::destroyFile((int) $f['id']);
        }
        DB::delete('fs_acl', 'folder_id = :f', ['f' => $id]);
        DB::delete('fs_folders', 'id = :id', ['id' => $id]);
        unset(self::$folderCache[$id]);
    }

    /** Empty items trashed more than $days days ago (cron). */
    public static function autoPurge(int $days = 30): int
    {
        $limit = time() - $days * 86400;
        $n = 0;
        foreach (DB::all('SELECT id FROM fs_files WHERE deleted_at > 0 AND deleted_at < :t', ['t' => $limit]) as $f) {
            self::destroyFile((int) $f['id']);
            $n++;
        }
        foreach (DB::all('SELECT id FROM fs_folders WHERE deleted_at > 0 AND deleted_at < :t', ['t' => $limit]) as $f) {
            self::destroyFolder((int) $f['id']);
            $n++;
        }
        self::resetCache();
        return $n;
    }

    /** Remove a deleted user's personal tree and access entries. */
    public static function deleteUser(int $userId): void
    {
        foreach (DB::all("SELECT id FROM fs_folders WHERE kind = 'personal' AND owner_id = :u", ['u' => $userId]) as $f) {
            self::destroyFolder((int) $f['id']);
        }
        DB::delete('fs_acl', "principal_type = 'user' AND principal = :u", ['u' => (string) $userId]);
        DB::delete('fs_file_acl', "principal_type = 'user' AND principal = :u", ['u' => (string) $userId]);
        self::resetCache();
    }

    // ----------------------------------------------------------------- access

    public static function acl(array $user, int $folderId): array
    {
        $f = self::require($user, $folderId, self::MANAGE);
        $entries = [];
        foreach (DB::all('SELECT * FROM fs_acl WHERE folder_id = :f ORDER BY principal_type, principal', ['f' => $folderId]) as $a) {
            $entries[] = self::aclOut($a);
        }
        // Grants coming from parents, shown read-only.
        $inherited = [];
        foreach (self::chain($folderId) as $anc) {
            if ((int) $anc['id'] === $folderId) {
                break;
            }
            foreach (DB::all('SELECT * FROM fs_acl WHERE folder_id = :f', ['f' => $anc['id']]) as $a) {
                $o = self::aclOut($a);
                $o['from'] = $anc['kind'] === 'personal' ? t('files.my_files') : $anc['name'];
                $inherited[] = $o;
            }
        }
        $root = self::chain($folderId)[0];
        return [
            'folder' => self::folderOut($f, self::MANAGE),
            'entries' => $entries,
            'inherited' => $inherited,
            'owner' => $root['kind'] === 'personal' ? self::userName((int) $root['owner_id']) : '',
        ];
    }

    private static function aclOut(array $a): array
    {
        $label = match ($a['principal_type']) {
            'all' => t('files.everyone'),
            'department' => t('files.department', ['name' => $a['principal']]),
            default => self::userName((int) $a['principal']),
        };
        $email = '';
        if ($a['principal_type'] === 'user') {
            $email = (string) DB::value('SELECT email FROM users WHERE id = :id', ['id' => (int) $a['principal']]);
        }
        return ['type' => $a['principal_type'], 'principal' => $a['principal'], 'label' => $label, 'email' => $email, 'level' => self::levelName((int) $a['level'])];
    }

    /** Replace the folder's own entries. Each entry: {type: user|department|all, principal, level}. */
    public static function setAcl(array $user, int $folderId, array $entries): void
    {
        $f = self::require($user, $folderId, self::MANAGE);
        $clean = [];
        foreach (array_slice($entries, 0, 500) as $e) {
            if (!is_array($e)) {
                continue;
            }
            $type = (string) ($e['type'] ?? '');
            $level = self::LEVELS[(string) ($e['level'] ?? '')] ?? 0;
            if (!$level) {
                continue;
            }
            $principal = trim((string) ($e['principal'] ?? ''));
            if ($type === 'user') {
                if (!DB::value("SELECT 1 FROM users WHERE id = :id", ['id' => (int) $principal])) {
                    continue;
                }
                $principal = (string) (int) $principal;
            } elseif ($type === 'department') {
                $principal = mb_substr(mb_strtolower($principal), 0, 190);
                if ($principal === '') {
                    continue;
                }
            } elseif ($type === 'all') {
                $principal = '*';
            } else {
                continue;
            }
            $clean[$type . ':' . $principal] = ['type' => $type, 'principal' => $principal, 'level' => $level];
        }
        // A non-admin manager keeps their own grant: nobody locks themselves out by mistake.
        if (($user['role'] ?? '') !== 'admin') {
            $own = (int) DB::value("SELECT level FROM fs_acl WHERE folder_id = :f AND principal_type = 'user' AND principal = :p", ['f' => $folderId, 'p' => (string) $user['id']]);
            $key = 'user:' . $user['id'];
            if ($own === self::MANAGE && ($clean[$key]['level'] ?? 0) < self::MANAGE) {
                $clean[$key] = ['type' => 'user', 'principal' => (string) $user['id'], 'level' => self::MANAGE];
            }
        }
        DB::transaction(function () use ($folderId, $clean, $user) {
            DB::delete('fs_acl', 'folder_id = :f', ['f' => $folderId]);
            foreach ($clean as $c) {
                DB::insert('fs_acl', [
                    'folder_id' => $folderId, 'principal_type' => $c['type'], 'principal' => $c['principal'], 'level' => $c['level'],
                    'created_by' => (int) $user['id'], 'created_at' => time(),
                ]);
            }
        });
        self::resetCache();
        Audit::log('files.access_changed', $f['kind'] === 'personal' ? t('files.my_files') : $f['name'], ['entries' => count($clean)], (int) $user['id']);
    }

    /** Users and departments matching a query, for the share dialog. */
    public static function principalsSearch(string $q): array
    {
        $q = trim($q);
        $like = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $users = DB::all("SELECT id, email, display_name, department FROM users WHERE status = 'active' AND (email LIKE :q ESCAPE '!' OR display_name LIKE :q ESCAPE '!') ORDER BY display_name, email LIMIT 15", ['q' => $like]);
        $depts = DB::all("SELECT DISTINCT department FROM users WHERE department <> '' AND department LIKE :q ESCAPE '!' ORDER BY department LIMIT 10", ['q' => $like]);
        $out = [];
        foreach ($users as $u) {
            $out[] = ['type' => 'user', 'principal' => (string) $u['id'], 'label' => $u['display_name'] ?: $u['email'], 'email' => $u['email']];
        }
        foreach ($depts as $d) {
            $out[] = ['type' => 'department', 'principal' => mb_strtolower($d['department']), 'label' => t('files.department', ['name' => $d['department']]), 'email' => ''];
        }
        return $out;
    }

    // ------------------------------------------------------------ mail links

    /** Copy files into compose uploads; returns upload descriptors like Composer::storeUpload. */
    public static function toUploads(array $user, array $fileIds, ?int $uploadOwner = null): array
    {
        // Rights are the reader's; the compose uploads belong to the mailbox being written from
        // (a delegated mailbox when one is open).
        $uploadOwner ??= (int) $user['id'];
        $out = [];
        $max = max(1, (int) Settings::get('security.max_attachment_mb', 25)) * 1024 * 1024;
        foreach (array_slice(array_unique(array_map('intval', $fileIds)), 0, 50) as $id) {
            $f = self::requireFile($user, $id, self::READ);
            if ((int) $f['size'] > $max) {
                throw new TooBigForMail($f['name'], (int) $f['id'], (int) $f['size']);
            }
            $token = bin2hex(random_bytes(16));
            $rel = 'tmp/up-' . $uploadOwner . '-' . $token;
            $dest = storage_path($rel);
            if (!is_dir(dirname($dest))) {
                mkdir(dirname($dest), 0750, true);
            }
            $fh = fopen($dest, 'wb');
            self::stream($f['blob_id'], static function (string $buf) use ($fh) {
                fwrite($fh, $buf);
            });
            fclose($fh);
            $name = str_replace(['"', "\r", "\n"], '_', $f['name']);
            DB::insert('uploads', [
                'user_id' => $uploadOwner, 'token' => $token, 'filename' => $name, 'mime' => $f['mime'],
                'size' => (int) $f['size'], 'path' => $rel, 'created_at' => time(),
            ]);
            $out[] = ['token' => $token, 'name' => $name, 'size' => (int) $f['size'], 'mime' => $f['mime']];
        }
        return $out;
    }

    /** Folders the user can write to (for "save to Files" pickers), as a flat indented list. */
    public static function writableTree(array $user): array
    {
        $out = [];
        $walk = function (int $id, int $depth) use (&$walk, &$out, $user) {
            if ($depth > 12 || count($out) > 2000) {
                return;
            }
            foreach (DB::all('SELECT * FROM fs_folders WHERE parent_id = :p AND deleted_at = 0 ORDER BY name', ['p' => $id]) as $sub) {
                $lvl = self::permission($user, (int) $sub['id']);
                if ($lvl >= self::READ) {
                    $out[] = ['id' => (int) $sub['id'], 'name' => $sub['name'], 'depth' => $depth, 'writable' => $lvl >= self::WRITE];
                    $walk((int) $sub['id'], $depth + 1);
                }
            }
        };
        $roots = self::roots($user);
        $personal = self::personalRoot((int) $user['id']);
        $out[] = ['id' => (int) $personal['id'], 'name' => t('files.my_files'), 'depth' => 0, 'writable' => true, 'root' => 'personal'];
        $walk((int) $personal['id'], 1);
        foreach ($roots['spaces'] as $s) {
            $out[] = ['id' => $s['id'], 'name' => $s['name'], 'depth' => 0, 'writable' => in_array($s['level'], ['write', 'manage'], true), 'root' => 'space'];
            $walk($s['id'], 1);
        }
        foreach ($roots['shared'] as $s) {
            $out[] = ['id' => $s['id'], 'name' => $s['name'], 'depth' => 0, 'writable' => in_array($s['level'], ['write', 'manage'], true), 'root' => 'shared'];
            $walk($s['id'], 1);
        }
        return $out;
    }
}
