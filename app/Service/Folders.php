<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Database as DB;

final class Folders
{
    public const ROLES = ['inbox', 'drafts', 'sent', 'archive', 'spam', 'trash'];
    public const ICONS = [
        'inbox' => 'inbox', 'drafts' => 'file-earmark-text', 'sent' => 'send', 'archive' => 'archive',
        'spam' => 'exclamation-octagon', 'trash' => 'trash3', '' => 'folder2',
    ];

    public static function ensureDefaults(int $userId): void
    {
        $existing = array_column(DB::all('SELECT role FROM folders WHERE user_id = :u AND role IS NOT NULL', ['u' => $userId]), 'role');
        $sort = 0;
        foreach (self::ROLES as $role) {
            $sort += 10;
            if (!in_array($role, $existing, true)) {
                DB::insert('folders', [
                    'user_id' => $userId, 'name' => ucfirst($role), 'role' => $role,
                    'sort' => $sort, 'created_at' => time(),
                ]);
            }
        }
    }

    public static function byRole(int $userId, string $role): array
    {
        $f = DB::one('SELECT * FROM folders WHERE user_id = :u AND role = :r', ['u' => $userId, 'r' => $role]);
        if (!$f) {
            self::ensureDefaults($userId);
            $f = DB::one('SELECT * FROM folders WHERE user_id = :u AND role = :r', ['u' => $userId, 'r' => $role]);
        }
        return $f;
    }

    public static function find(int $userId, int $id): ?array
    {
        if (Mailbox::$privacy && in_array($id, self::personalIds($userId), true)) {
            return null;
        }
        return DB::one('SELECT * FROM folders WHERE user_id = :u AND id = :id', ['u' => $userId, 'id' => $id]);
    }

    /** User folders named as personal ("Perso", "Personnel", "Privé"…) and their sub-folders. */
    public static function personalIds(int $userId): array
    {
        $rows = DB::all('SELECT id, parent_id, name, role FROM folders WHERE user_id = :u', ['u' => $userId]);
        $byId = array_column($rows, null, 'id');
        $out = [];
        foreach ($rows as $r) {
            $n = $r;
            for ($depth = 0; $n && $depth < 20; $depth++) {
                if ($n['role'] === null && Delegation::isPersonalName((string) $n['name'])) {
                    $out[] = (int) $r['id'];
                    break;
                }
                $n = $n['parent_id'] !== null ? ($byId[$n['parent_id']] ?? null) : null;
            }
        }
        return $out;
    }

    public static function displayName(array $f): string
    {
        return $f['role'] ? t('folder.' . $f['role']) : (string) $f['name'];
    }

    /** Folder tree with counts. */
    public static function listWithCounts(int $userId): array
    {
        $rows = DB::all(
            'SELECT f.*,
                (SELECT COUNT(*) FROM messages m WHERE m.folder_id = f.id) AS total,
                (SELECT COUNT(*) FROM messages m WHERE m.folder_id = f.id AND m.is_read = 0) AS unread
             FROM folders f WHERE f.user_id = :u ORDER BY CASE WHEN f.role IS NULL THEN 1 ELSE 0 END, f.sort, f.name',
            ['u' => $userId]
        );
        $out = [];
        $hidden = Mailbox::$privacy ? self::personalIds($userId) : [];
        foreach ($rows as $r) {
            if (in_array((int) $r['id'], $hidden, true)) {
                continue;
            }
            $out[] = [
                'id'        => (int) $r['id'],
                'parent_id' => $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
                'name'      => self::displayName($r),
                'raw_name'  => $r['name'],
                'role'      => $r['role'],
                'color'     => $r['color'],
                'icon'      => self::ICONS[$r['role'] ?? ''] ?? 'folder2',
                'total'     => (int) $r['total'],
                'unread'    => (int) $r['unread'],
            ];
        }
        return $out;
    }

    public static function create(int $userId, string $name, ?int $parentId = null, string $color = ''): int
    {
        $name = trim(mb_substr($name, 0, 100));
        if ($name === '') {
            throw new \InvalidArgumentException(t('folder.name_required'));
        }
        if ($parentId && !self::find($userId, $parentId)) {
            $parentId = null;
        }
        $dupe = DB::value(
            'SELECT COUNT(*) FROM folders WHERE user_id = :u AND LOWER(name) = :n AND ' . ($parentId ? 'parent_id = :p' : 'parent_id IS NULL'),
            ['u' => $userId, 'n' => mb_strtolower($name)] + ($parentId ? ['p' => $parentId] : [])
        );
        if ($dupe) {
            throw new \InvalidArgumentException(t('folder.exists'));
        }
        return DB::insert('folders', [
            'user_id' => $userId, 'name' => $name, 'parent_id' => $parentId, 'role' => null,
            'color' => preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : '', 'sort' => 100, 'created_at' => time(),
        ]);
    }

    public static function rename(int $userId, int $id, string $name, ?string $color = null): void
    {
        $f = self::find($userId, $id);
        if (!$f || $f['role']) {
            throw new \InvalidArgumentException(t('folder.system_locked'));
        }
        $set = ['name' => trim(mb_substr($name, 0, 100)) ?: $f['name']];
        if ($color !== null) {
            $set['color'] = preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : '';
        }
        DB::update('folders', $set, 'id = :id', ['id' => $id]);
    }

    public static function delete(int $userId, int $id): void
    {
        $f = self::find($userId, $id);
        if (!$f || $f['role']) {
            throw new \InvalidArgumentException(t('folder.system_locked'));
        }
        $trash = self::byRole($userId, 'trash');
        DB::transaction(function () use ($userId, $id, $trash) {
            // Move messages of the folder and its sub-folders to trash.
            $ids = [$id];
            $queue = [$id];
            while ($queue) {
                $p = array_shift($queue);
                foreach (DB::all('SELECT id FROM folders WHERE user_id = :u AND parent_id = :p', ['u' => $userId, 'p' => $p]) as $c) {
                    $ids[] = (int) $c['id'];
                    $queue[] = (int) $c['id'];
                }
            }
            [$in, $params] = DB::in('f', $ids);
            DB::run("UPDATE messages SET trashed_from = NULL, trashed_from_name = COALESCE((SELECT f.name FROM folders f WHERE f.id = messages.folder_id), ''), folder_id = :t WHERE user_id = :u AND folder_id IN $in", ['t' => $trash['id'], 'u' => $userId] + $params);
            DB::run("DELETE FROM folders WHERE user_id = :u AND id IN $in", ['u' => $userId] + $params);
            foreach (DB::all('SELECT id, actions FROM rules WHERE user_id = :u', ['u' => $userId]) as $rule) {
                foreach (json_decode((string) $rule['actions'], true) ?: [] as $a) {
                    if (isset($a['folder']) && in_array((int) $a['folder'], $ids, true)) {
                        DB::update('rules', ['enabled' => 0], 'id = :id', ['id' => $rule['id']]);
                        break;
                    }
                }
            }
        });
    }
}
