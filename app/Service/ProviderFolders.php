<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Audit;
use M4W\Core\Database as DB;
use M4W\Core\Settings;
use M4W\Mail\ImapClient;

/**
 * One's own folders, the same here and in one's own mailbox at the provider (own mailbox, or
 * webmail mode; never the collector, which holds everybody's mail): Roundcube, the phone and
 * this webmail show the same tree, and a message filed in one is filed in the others.
 *
 *  - a folder created, renamed or deleted on one side is on the other at the next pass (renaming
 *    elsewhere is seen as its messages arriving in the new folder);
 *  - each folder is followed like the inbox (RemoteMailbox::reconcile): moved, deleted, read or
 *    flagged on either side, the other follows; mail filed by the provider's own filters is
 *    brought here;
 *  - a mapping (remote_folders) ties a local folder to its provider path; its messages are
 *    followed in remote_uids under the key "f<folder id>".
 */
final class ProviderFolders
{
    /** Whether this account's folders follow (each person's own mailbox only). */
    public static function enabled(array $acc): bool
    {
        return (int) $acc['user_id'] !== Collector::ACCOUNT && (int) ($acc['two_way'] ?? 1) === 1 && (int) ($acc['sync_folders'] ?? 1) === 1
            && (RemoteMailbox::isOwn($acc) || RemoteMailbox::active());
    }

    public static function key(int $folderId): string
    {
        return 'f' . $folderId;
    }

    /** Provider folders that are not "one's own": the inbox and the special ones. */
    private static function special(ImapClient $client, array $acc): array
    {
        $out = ['inbox' => true];
        foreach ($client->folders() as $name => $attrs) {
            if (preg_match('/\\\\(Sent|Trash|Junk|Drafts|Archive|All|Flagged|Important|Noselect|NonExistent)\b/i', $attrs)) {
                $out[mb_strtolower((string) $name)] = true;
            }
        }
        $names = [$client->sentFolder(), $client->trashFolder(), $client->archiveFolder(), (string) ($acc['trash_folder'] ?? ''), (string) ($acc['archive_folder'] ?? ''),
            trim((string) Settings::get('webmail.spam_folder', '')), 'Drafts', 'INBOX.Drafts', 'Brouillons', 'INBOX.Brouillons', 'Junk', 'Spam', 'INBOX.Junk', 'INBOX.Spam',
            'Templates', 'Outbox', 'Notes'];
        foreach ($names as $n) {
            if ($n !== '' && $n !== '-') {
                $out[mb_strtolower($n)] = true;
            }
        }
        return $out;
    }

    /** "INBOX." where the server keeps every folder under the inbox (Courier, some Dovecot). */
    private static function prefix(ImapClient $client): string
    {
        $d = $client->delimiter();
        $names = array_filter(array_keys($client->folders()), static fn($n) => strcasecmp((string) $n, 'INBOX') !== 0);
        if ($names && !array_filter($names, static fn($n) => stripos((string) $n, 'INBOX' . $d) !== 0)) {
            return 'INBOX' . $d;
        }
        return '';
    }

    /** Where a local folder lives at the provider (its parents included). */
    private static function pathOf(int $folderId, array $folders, string $prefix, string $d): string
    {
        $parts = [];
        $seen = [];
        for ($id = $folderId; $id && isset($folders[$id]) && !isset($seen[$id]); $id = (int) $folders[$id]['parent_id']) {
            $seen[$id] = true;
            // The separator cannot be part of a name at the provider.
            $parts[] = ImapClient::encodeName(str_replace($d, '-', (string) $folders[$id]['name']));
            if ($folders[$id]['role'] !== null) {
                return ''; // under a system folder: not followed
            }
        }
        return $prefix . implode($d, array_reverse($parts));
    }

    /**
     * Before the messages: both trees made the same. Returns the folders followed, as
     * [key => ['path', 'folder_id', 'map_id']] for the pull and reconcile that come next.
     */
    public static function prepare(ImapClient $client, array $acc, array $user): array
    {
        $uid = (int) $user['id'];
        $aid = (int) $acc['id'];
        $remote = $client->folders();
        $byLower = [];
        foreach (array_keys($remote) as $n) {
            $byLower[mb_strtolower((string) $n)] = (string) $n;
        }
        $special = self::special($client, $acc);
        $prefix = self::prefix($client);
        $d = $client->delimiter();
        $local = [];
        foreach (DB::all('SELECT id, parent_id, name, role FROM folders WHERE user_id = :u', ['u' => $uid]) as $f) {
            $local[(int) $f['id']] = $f;
        }
        $maps = [];
        foreach (DB::all('SELECT * FROM remote_folders WHERE account_id = :a', ['a' => $aid]) as $m) {
            $maps[(int) $m['folder_id']] = $m;
        }
        $exists = static fn(string $p) => isset($byLower[mb_strtolower($p)]);

        // 1. Renamed or moved here: renamed there (the folders under it follow on the server).
        foreach ($maps as $fid => $m) {
            if (!isset($local[$fid]) || $local[$fid]['role'] !== null) {
                continue;
            }
            $want = self::pathOf($fid, $local, $prefix, $d);
            if ($want === '' || $want === $m['path'] || !$exists($m['path']) || $exists($want)) {
                continue;
            }
            try {
                $client->renameFolder($m['path'], $want);
                foreach ($maps as $cid => $cm) {
                    if ($cm['path'] === $m['path'] || str_starts_with($cm['path'], $m['path'] . $d)) {
                        $maps[$cid]['path'] = $want . substr($cm['path'], strlen($m['path']));
                        DB::update('remote_folders', ['path' => $maps[$cid]['path']], 'id = :id', ['id' => $cm['id']]);
                    }
                }
                Audit::log('sync.folder_renamed_there', ImapClient::decodeName($want), ['box' => (string) $acc['login'], 'from' => ImapClient::decodeName($m['path'])], $uid);
            } catch (\RuntimeException $e) {
                error_log('[m4w] folder rename: ' . $e->getMessage());
            }
        }
        $remote = $client->folders();
        $byLower = [];
        foreach (array_keys($remote) as $n) {
            $byLower[mb_strtolower((string) $n)] = (string) $n;
        }

        // 2. Created here: created there (parents first), or tied to the one of the same name.
        $todo = array_filter($local, static fn($f) => $f['role'] === null);
        uasort($todo, static fn($a, $b) => substr_count(self::pathOf((int) $a['id'], $local, $prefix, $d), $d) <=> substr_count(self::pathOf((int) $b['id'], $local, $prefix, $d), $d));
        foreach ($todo as $fid => $f) {
            if (isset($maps[$fid])) {
                continue;
            }
            $path = self::pathOf($fid, $local, $prefix, $d);
            if ($path === '' || isset($special[mb_strtolower($path)])) {
                continue;
            }
            try {
                if (!$exists($path)) {
                    $client->createFolder($path);
                    $byLower[mb_strtolower($path)] = $path;
                    Audit::log('sync.folder_created_there', (string) $f['name'], ['box' => (string) $acc['login']], $uid);
                } else {
                    $path = $byLower[mb_strtolower($path)];
                }
                DB::insert('remote_folders', ['account_id' => $aid, 'folder_id' => $fid, 'path' => mb_substr($path, 0, 255), 'created_at' => time()]);
                $maps[$fid] = DB::one('SELECT * FROM remote_folders WHERE account_id = :a AND folder_id = :f', ['a' => $aid, 'f' => $fid]);
            } catch (\RuntimeException $e) {
                error_log('[m4w] folder create: ' . $e->getMessage());
            }
        }

        // 3. Created at the provider (phone, Roundcube, its filters): created here.
        $mapped = [];
        foreach ($maps as $m) {
            $mapped[mb_strtolower((string) $m['path'])] = true;
        }
        $names = array_keys($remote);
        usort($names, static fn($a, $b) => substr_count((string) $a, $d) <=> substr_count((string) $b, $d));
        foreach ($names as $name) {
            $name = (string) $name;
            $lower = mb_strtolower($name);
            if (isset($mapped[$lower]) || isset($special[$lower]) || ($prefix !== '' && stripos($name, $prefix) !== 0)
                || preg_match('/\\\\(Noselect|NonExistent)\b/i', (string) $remote[$name])) {
                continue;
            }
            $rel = substr($name, strlen($prefix));
            $parts = explode($d, $rel);
            $leaf = ImapClient::decodeName((string) array_pop($parts));
            // Its parent: the folder already tied to the path above, if any.
            $parentId = null;
            if ($parts) {
                $parentPath = $prefix . implode($d, $parts);
                foreach ($maps as $pfid => $pm) {
                    if (strcasecmp((string) $pm['path'], $parentPath) === 0) {
                        $parentId = $pfid;
                    }
                }
                if ($parentId === null && isset($special[mb_strtolower($parentPath)])) {
                    continue; // under the Trash, the Sent folder…: not one's own
                }
            }
            $existing = DB::one('SELECT id FROM folders WHERE user_id = :u AND role IS NULL AND LOWER(name) = :n AND ' . ($parentId ? 'parent_id = :p' : 'parent_id IS NULL'),
                ['u' => $uid, 'n' => mb_strtolower($leaf)] + ($parentId ? ['p' => $parentId] : []));
            try {
                $fid = $existing ? (int) $existing['id'] : Folders::create($uid, $leaf !== '' ? $leaf : $name, $parentId);
            } catch (\InvalidArgumentException $e) {
                error_log('[m4w] folder here: ' . $e->getMessage());
                continue;
            }
            $local[$fid] ??= ['id' => $fid, 'parent_id' => $parentId, 'name' => $leaf, 'role' => null];
            if (isset($maps[$fid])) {
                continue;
            }
            DB::insert('remote_folders', ['account_id' => $aid, 'folder_id' => $fid, 'path' => mb_substr($name, 0, 255), 'created_at' => time()]);
            $maps[$fid] = DB::one('SELECT * FROM remote_folders WHERE account_id = :a AND folder_id = :f', ['a' => $aid, 'f' => $fid]);
            $mapped[$lower] = true;
            if (!$existing) {
                Audit::log('sync.folder_created_here', $leaf, ['box' => (string) $acc['login']], $uid);
            }
        }

        $out = [];
        foreach ($maps as $fid => $m) {
            // Missing from the provider's list: believed only when seen twice in a row.
            $missing = isset($byLower[mb_strtolower((string) $m['path'])]) ? 0 : (int) $m['missing'] + 1;
            if ($missing !== (int) $m['missing']) {
                DB::update('remote_folders', ['missing' => $missing], 'id = :id', ['id' => $m['id']]);
            }
            if ($missing === 1) {
                continue;
            }
            $out[self::key($fid)] = ['path' => (string) $m['path'], 'folder_id' => $fid, 'map_id' => (int) $m['id'], 'gone_here' => !isset($local[$fid]), 'gone_there' => $missing > 1];
        }
        return $out;
    }

    /**
     * New messages of the followed folders: brought here (filed by the provider's filters, or
     * there before the folder was followed), or, already here elsewhere, moved here likewise
     * (filed on the phone).
     */
    public static function pull(ImapClient $client, array $acc, array $user, array $folders, int $max = 200): int
    {
        $uid = (int) $user['id'];
        $n = 0;
        foreach ($folders as $key => $f) {
            if ($f['gone_here'] || $f['gone_there']) {
                continue;
            }
            try {
                $info = $client->select($f['path']);
            } catch (\RuntimeException) {
                continue;
            }
            $map = DB::one('SELECT * FROM remote_folders WHERE id = :id', ['id' => $f['map_id']]);
            if ((int) $map['validity'] !== 0 && (int) $map['validity'] !== $info['uidvalidity']) {
                // Renumbered: what is there is followed again from the start, nothing imported twice.
                DB::delete('remote_uids', 'account_id = :a AND folder = :f', ['a' => $acc['id'], 'f' => $key]);
                DB::update('remote_folders', ['last_uid' => 0, 'validity' => $info['uidvalidity']], 'id = :id', ['id' => $map['id']]);
                $map['last_uid'] = 0;
            }
            $first = (int) $map['validity'] === 0;
            $boundary = $first ? PHP_INT_MAX : (int) $map['history_uid'];
            $uids = $client->searchUidsAbove((int) $map['last_uid']);
            if ($first) {
                DB::update('remote_folders', ['validity' => $info['uidvalidity'], 'history_uid' => $uids ? max($uids) : 0], 'id = :id', ['id' => $map['id']]);
                $boundary = $uids ? max($uids) : 0;
            }
            foreach (array_slice($uids, 0, $max) as $ruid) {
                if (DB::value('SELECT COUNT(*) FROM remote_uids WHERE account_id = :a AND folder = :f AND validity = :v AND uid = :u', ['a' => $acc['id'], 'f' => $key, 'v' => $info['uidvalidity'], 'u' => $ruid])) {
                    DB::update('remote_folders', ['last_uid' => $ruid], 'id = :id', ['id' => $map['id']]);
                    continue; // moved here by this webmail itself
                }
                $msg = $client->fetchMessage($ruid);
                if ($msg === null) {
                    continue;
                }
                $head = substr($msg['raw'], 0, (int) (strpos($msg['raw'], "\r\n\r\n") ?: 65536));
                $mid = preg_match('/^Message-ID:\s*<([^>\r\n]+)>/mi', $head, $mm) ? $mm[1] : '';
                $here = $mid !== '' ? DB::one('SELECT m.id, m.folder_id, f.role FROM messages m JOIN folders f ON f.id = m.folder_id
                    WHERE m.user_id = :u AND m.message_id = :m AND m.is_draft = 0 AND (f.role IS NULL OR f.role <> :s) ORDER BY m.folder_id = :fid DESC LIMIT 1',
                    ['u' => $uid, 'm' => $mid, 's' => 'sent', 'fid' => $f['folder_id']]) : null;
                if ($here) {
                    if ((int) $here['folder_id'] !== (int) $f['folder_id']) {
                        // Filed there on the phone (or brought back from its Trash): filed here too.
                        Mailbox::move($uid, [(int) $here['id']], (int) $f['folder_id']);
                        Audit::log('sync.filed_here', mb_substr((string) DB::value('SELECT subject FROM messages WHERE id = :id', ['id' => $here['id']]), 0, 150),
                            ['box' => (string) $acc['login'], 'folder' => ImapClient::decodeName($f['path'])], $uid);
                    }
                } else {
                    $flags = (array) $msg['flags'];
                    $raw = Delivery::sanitizeIncoming(str_replace(["\r\n", "\r"], "\n", $msg['raw']));
                    $p = \M4W\Mail\MimeParser::parse($raw);
                    $date = (int) ($msg['date'] ?? 0) ?: ($p->date() ?: time());
                    $id = Mailbox::store($uid, (int) $f['folder_id'], 'Delivered-To: ' . $user['email'] . "\n" . $raw,
                        ['read' => in_array('\\seen', $flags, true), 'flagged' => in_array('\\flagged', $flags, true), 'date_received' => min($date, time())], $p);
                    // Arrived since the folder is followed (filed by the provider's filters): announced.
                    if ($ruid > $boundary && !in_array('\\seen', $flags, true) && $id) {
                        WebPush::newMail($user, (int) $id, $p->from(), $p->subject(), (int) $f['folder_id']);
                    }
                    $n++;
                }
                DB::insert('remote_uids', ['account_id' => $acc['id'], 'folder' => $key, 'validity' => $info['uidvalidity'], 'uid' => $ruid, 'imported_at' => time(),
                    'msgid' => mb_substr($mid, 0, 255), 'seen' => (int) in_array('\\seen', $msg['flags'], true), 'flagged' => (int) in_array('\\flagged', $msg['flags'], true)]);
                DB::update('remote_folders', ['last_uid' => $ruid], 'id = :id', ['id' => $map['id']]);
            }
        }
        return $n;
    }

    /**
     * After the messages: a folder deleted on one side is deleted on the other once empty
     * (its messages have followed: to the Trash, or wherever they were moved).
     */
    public static function finish(ImapClient $client, array $acc, array $user, array $folders): void
    {
        $uid = (int) $user['id'];
        // Deepest first: a folder holding others is removed after them.
        uasort($folders, static fn($a, $b) => substr_count($b['path'], $client->delimiter()) <=> substr_count($a['path'], $client->delimiter()));
        foreach ($folders as $key => $f) {
            $left = (int) DB::value('SELECT COUNT(*) FROM remote_uids WHERE account_id = :a AND folder = :f', ['a' => $acc['id'], 'f' => $key]);
            if ($f['gone_here'] && $left === 0) {
                try {
                    if (!$f['gone_there']) {
                        $info = $client->select($f['path']);
                        if ($info['exists'] > 0) {
                            continue; // something new arrived there meanwhile: next time
                        }
                        $client->select('INBOX');
                        $client->deleteFolder($f['path']);
                        Audit::log('sync.folder_deleted_there', ImapClient::decodeName($f['path']), ['box' => (string) $acc['login']], $uid);
                    }
                    DB::delete('remote_folders', 'id = :id', ['id' => $f['map_id']]);
                } catch (\RuntimeException $e) {
                    error_log('[m4w] folder delete: ' . $e->getMessage());
                }
            } elseif ($f['gone_there'] && !$f['gone_here'] && $left === 0) {
                // Deleted (or renamed) at the provider: gone here too once nothing is left in it.
                DB::delete('remote_folders', 'id = :id', ['id' => $f['map_id']]);
                $busy = DB::value('SELECT COUNT(*) FROM messages WHERE user_id = :u AND folder_id = :f', ['u' => $uid, 'f' => $f['folder_id']])
                    || DB::value('SELECT COUNT(*) FROM folders WHERE user_id = :u AND parent_id = :f', ['u' => $uid, 'f' => $f['folder_id']]);
                if (!$busy) {
                    try {
                        $name = (string) DB::value('SELECT name FROM folders WHERE id = :f', ['f' => $f['folder_id']]);
                        Folders::delete($uid, (int) $f['folder_id']);
                        Audit::log('sync.folder_deleted_here', $name, ['box' => (string) $acc['login']], $uid);
                    } catch (\InvalidArgumentException $e) {
                        error_log('[m4w] folder delete here: ' . $e->getMessage());
                    }
                }
            }
        }
    }

    /**
     * Messages here in a followed folder that the provider does not have at all (brought by
     * another way: the collector, a colleague of the server…): put into that folder there, so
     * that the other mail apps see the same folder. Never twice (RemoteMailbox::appendOnce).
     */
    public static function push(ImapClient $client, array $acc, array $user, array $folders, int $max = 50): int
    {
        $ids = [];
        foreach ($folders as $key => $f) {
            if (!$f['gone_here'] && !$f['gone_there']) {
                $ids[(int) $f['folder_id']] = $f['path'];
            }
        }
        if (!$ids) {
            return 0;
        }
        [$in, $p] = DB::in('f', array_keys($ids));
        $rows = DB::all("SELECT m.* FROM messages m WHERE m.user_id = :u AND m.is_draft = 0 AND m.folder_id IN $in
            AND NOT EXISTS (SELECT 1 FROM remote_uids r WHERE r.account_id = :a AND r.msgid = m.message_id AND m.message_id <> '')
            AND NOT EXISTS (SELECT 1 FROM provider_pushed x WHERE x.account_id = :a2 AND x.local_id = m.id)
            ORDER BY m.id LIMIT $max", $p + ['u' => $user['id'], 'a' => $acc['id'], 'a2' => $acc['id']]);
        if (!$rows) {
            return 0;
        }
        $holds = RemoteMailbox::providerHolds($client, $acc);
        $n = 0;
        foreach ($rows as $m) {
            if (RemoteMailbox::heldThere($holds, $m) || $m['storage_path'] === '' || !is_file(storage_path((string) $m['storage_path']))) {
                // Already there somewhere (followed another way), or nothing to send: written down, not tried again.
                DB::insert('provider_pushed', ['account_id' => $acc['id'], 'local_id' => $m['id'], 'msgid' => '', 'fkey' => '', 'created_at' => time()]);
                continue;
            }
            try {
                $n += RemoteMailbox::appendOnce($client, $acc, $m, $ids[(int) $m['folder_id']], 'folder') ? 1 : 0;
            } catch (\RuntimeException $e) {
                error_log('[m4w] folder copy: ' . $e->getMessage());
            }
        }
        return $n;
    }
}
