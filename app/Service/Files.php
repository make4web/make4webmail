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
            'size' => (int) $f['size'], 'version' => (int) $f['version'], 'updated_at' => (int) $f['updated_at'],
            'created_at' => (int) $f['created_at'],
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
        if (!$f || self::permission($user, (int) $f['folder_id']) < $level) {
            $exists = $f && self::permission($user, (int) $f['folder_id']) >= self::READ;
            throw new \M4W\Core\HttpException($exists ? 403 : 404, t($exists ? 'files.forbidden' : 'files.not_found'));
        }
        return $f;
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
     * Add a file from a local path. With $replace, a file of the same name becomes a
     * new version of the existing one instead of a renamed copy.
     */
    public static function addFromPath(array $user, int $folderId, string $path, string $name, bool $replace = false, ?string $mime = null): array
    {
        self::require($user, $folderId, self::WRITE);
        $name = self::cleanName($name);
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
                'created_at' => $now, 'updated_at' => $now,
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
                throw new \InvalidArgumentException(t('compose.too_big', ['mb' => (int) Settings::get('security.max_attachment_mb', 25)]));
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
