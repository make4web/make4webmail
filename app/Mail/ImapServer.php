<?php
declare(strict_types=1);

namespace M4W\Mail;

use M4W\Core\Database as DB;
use M4W\Service\Folders;
use M4W\Service\Mailbox;

/**
 * IMAP4rev1 for mail apps (phones, Thunderbird, Outlook), on the webmail's own mailboxes: the same
 * folders, the messages already sorted by the rules and the antispam, the same read / flagged /
 * answered marks, both ways. Started by bin/maild.php once the person is identified (an app
 * password), in a process of its own for this instance.
 *
 * Folders: INBOX, Sent, Drafts, Trash, Junk, Archive (special use) and the person's own, "/"
 * between levels. UIDs are given per folder on first sight (messages.imap_uid / imap_folder):
 * a message moved in the webmail gets a new UID in its new folder, as IMAP requires.
 */
final class ImapServer
{
    private const ROLE_NAMES = ['inbox' => 'INBOX', 'sent' => 'Sent', 'drafts' => 'Drafts', 'trash' => 'Trash', 'spam' => 'Junk', 'archive' => 'Archive'];
    private const SPECIAL = ['sent' => '\\Sent', 'drafts' => '\\Drafts', 'trash' => '\\Trash', 'spam' => '\\Junk', 'archive' => '\\Archive'];
    public const CAPS = 'IMAP4rev1 LITERAL+ SASL-IR ID IDLE NAMESPACE SPECIAL-USE MOVE UNSELECT CHILDREN';
    private const IDLE_MAX = 1740;
    private const MAX_LITERAL = 50 * 1024 * 1024;

    /** @var resource */
    private $c;
    private int $uid;
    /** @var array<string, array> name => folder row */
    private array $folders = [];
    private ?array $sel = null;
    private bool $readOnly = false;
    /** @var int[] UIDs of the selected folder, in order (sequence number = index + 1) */
    private array $uids = [];
    /** @var array<int, array> uid => row */
    private array $rows = [];
    private string $stamp = '';
    private array $raws = [];

    /** @param resource $conn */
    public function __construct($conn, private array $user, private string $ip)
    {
        $this->c = $conn;
        $this->uid = (int) $user['id'];
    }

    /**
     * The person's app password (never the account password), checked by this instance; the
     * new place is told like a sign-in to the webmail.
     */
    public static function authenticate(string $email, string $password, string $ip, string $proto = 'imap'): ?array
    {
        $user = \M4W\Dav\AppPasswords::check($email, $password, $ip);
        if ($user) {
            try {
                \M4W\Service\AccountGuard::onLogin($user, $ip, strtoupper($proto) . ' (mail app)');
            } catch (\Throwable) {
            }
            \M4W\Core\Audit::log($proto . '.login', (string) $user['email'], ['ip' => $ip], (int) $user['id']);
        }
        return $user;
    }

    // ------------------------------------------------------------------ connection

    private function out(string $line): void
    {
        $this->write($line . "\r\n");
    }

    private function write(string $s): void
    {
        while ($s !== '') {
            $n = @fwrite($this->c, $s);
            if ($n === false || $n === 0) {
                throw new \RuntimeException('closed');
            }
            $s = substr($s, $n);
        }
    }

    private function readLine(int $timeout): ?string
    {
        stream_set_timeout($this->c, $timeout);
        $line = fgets($this->c, 65536);
        if ($line === false) {
            return null;
        }
        return rtrim($line, "\r\n");
    }

    /** A whole command, its literals read ({n} asked with "+", {n+} not): [line with markers, literals]. */
    private function readCommand(): ?array
    {
        $line = $this->readLine(1800);
        if ($line === null) {
            return null;
        }
        $lits = [];
        while (preg_match('/\{(\d+)(\+?)\}$/', $line, $m)) {
            $n = (int) $m[1];
            if ($n > self::MAX_LITERAL) {
                throw new \RuntimeException('literal too large');
            }
            if ($m[2] === '') {
                $this->out('+ Ready');
            }
            $data = '';
            while (strlen($data) < $n) {
                $chunk = fread($this->c, min(65536, $n - strlen($data)));
                if ($chunk === false || $chunk === '') {
                    return null;
                }
                $data .= $chunk;
            }
            $lits[] = $data;
            $line = substr($line, 0, -strlen($m[0])) . "\x00" . (count($lits) - 1) . "\x00";
            $rest = $this->readLine(60);
            if ($rest === null) {
                return null;
            }
            $line .= $rest;
        }
        return [$line, $lits];
    }

    /** Words, quoted strings, literals and ( ) lists; "[...]" kept with the word it follows. */
    private static function tokens(string $s, array $lits, int &$i = 0): array
    {
        $out = [];
        $len = strlen($s);
        while ($i < $len) {
            $ch = $s[$i];
            if ($ch === ' ') {
                $i++;
            } elseif ($ch === '(') {
                $i++;
                $out[] = self::tokens($s, $lits, $i);
            } elseif ($ch === ')') {
                $i++;
                return $out;
            } elseif ($ch === '"') {
                $v = '';
                for ($i++; $i < $len && $s[$i] !== '"'; $i++) {
                    if ($s[$i] === '\\' && $i + 1 < $len) {
                        $i++;
                    }
                    $v .= $s[$i];
                }
                $i++;
                $out[] = ['q' => $v];
            } elseif ($ch === "\x00") {
                $j = strpos($s, "\x00", $i + 1);
                $out[] = ['q' => $lits[(int) substr($s, $i + 1, $j - $i - 1)] ?? ''];
                $i = $j + 1;
            } else {
                $v = '';
                $depth = 0;
                while ($i < $len && ($depth > 0 || !in_array($s[$i], [' ', '(', ')'], true))) {
                    if ($s[$i] === '[') {
                        $depth++;
                    } elseif ($s[$i] === ']') {
                        $depth--;
                    }
                    $v .= $s[$i++];
                }
                $out[] = $v;
            }
        }
        return $out;
    }

    private static function str(mixed $t): string
    {
        return is_array($t) ? (string) ($t['q'] ?? '') : (string) $t;
    }

    private static function quote(?string $s): string
    {
        if ($s === null) {
            return 'NIL';
        }
        if (preg_match('/[\r\n\x00\x80-\xff"\\\\]/', $s)) {
            return '{' . strlen($s) . "}\r\n" . $s;
        }
        return '"' . $s . '"';
    }

    // ------------------------------------------------------------------ loop

    /** Serves the commands after the login (answered OK here with $tag). */
    public function run(string $tag): void
    {
        $this->out($tag . ' OK [CAPABILITY ' . self::CAPS . '] Logged in');
        while (true) {
            $cmd = $this->readCommand();
            if ($cmd === null) {
                return;
            }
            [$line, $lits] = $cmd;
            $i = 0;
            $t = self::tokens($line, $lits, $i);
            $tag = (string) ($t[0] ?? '*');
            $name = strtoupper(self::str($t[1] ?? ''));
            $args = array_slice($t, 2);
            $uidMode = false;
            if ($name === 'UID') {
                $uidMode = true;
                $name = strtoupper(self::str($args[0] ?? ''));
                $args = array_slice($args, 1);
            }
            try {
                if ($this->command($tag, $name, $args, $uidMode) === false) {
                    return;
                }
            } catch (\InvalidArgumentException $e) {
                $this->out($tag . ' BAD ' . $e->getMessage());
            } catch (\RuntimeException $e) {
                throw $e;
            } catch (\Throwable $e) {
                error_log('[m4w] imapd: ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
                $this->out($tag . ' NO Server error');
            }
        }
    }

    private function command(string $tag, string $name, array $a, bool $uidMode): ?bool
    {
        switch ($name) {
            case 'CAPABILITY':
                $this->out('* CAPABILITY ' . self::CAPS);
                break;
            case 'NOOP':
            case 'CHECK':
                $this->refresh(true);
                break;
            case 'LOGOUT':
                $this->out('* BYE Logging out');
                $this->out($tag . ' OK Logout completed');
                return false;
            case 'ID':
                $this->out('* ID NIL');
                break;
            case 'ENABLE':
                $this->out('* ENABLED');
                break;
            case 'NAMESPACE':
                $this->out('* NAMESPACE (("" "/")) NIL NIL');
                break;
            case 'LIST':
            case 'LSUB':
            case 'XLIST':
                $this->list($name === 'XLIST' ? 'LIST' : $name, self::str($a[0] ?? ''), self::str($a[1] ?? ''));
                break;
            case 'STATUS':
                $this->status(self::str($a[0] ?? ''), (array) ($a[1] ?? []));
                break;
            case 'SELECT':
            case 'EXAMINE':
                if (!$this->select(self::str($a[0] ?? ''), $name === 'EXAMINE')) {
                    $this->out($tag . ' NO No such mailbox');
                    return null;
                }
                $this->out($tag . ' OK [' . ($this->readOnly ? 'READ-ONLY' : 'READ-WRITE') . '] ' . $name . ' completed');
                return null;
            case 'CLOSE':
            case 'UNSELECT':
                if ($this->sel && $name === 'CLOSE' && !$this->readOnly) {
                    $this->expunge(null, false);
                }
                $this->sel = null;
                break;
            case 'CREATE':
                $this->create(self::str($a[0] ?? ''));
                break;
            case 'DELETE':
                if (!$this->deleteFolder(self::str($a[0] ?? ''))) {
                    $this->out($tag . ' NO Cannot delete this mailbox');
                    return null;
                }
                break;
            case 'RENAME':
                if (!$this->renameFolder(self::str($a[0] ?? ''), self::str($a[1] ?? ''))) {
                    $this->out($tag . ' NO Cannot rename this mailbox');
                    return null;
                }
                break;
            case 'SUBSCRIBE':
            case 'UNSUBSCRIBE':
                break;
            case 'APPEND':
                $this->append($a);
                break;
            case 'IDLE':
                $this->idle($tag);
                return null;
            case 'FETCH':
            case 'STORE':
            case 'SEARCH':
            case 'COPY':
            case 'MOVE':
            case 'EXPUNGE':
                if (!$this->sel) {
                    $this->out($tag . ' NO No mailbox selected');
                    return null;
                }
                match ($name) {
                    'FETCH' => $this->fetch(self::str($a[0] ?? ''), $a[1] ?? [], $uidMode),
                    'STORE' => $this->store(self::str($a[0] ?? ''), strtoupper(self::str($a[1] ?? '')), $a[2] ?? [], $uidMode),
                    'SEARCH' => $this->search($a, $uidMode),
                    'COPY', 'MOVE' => $this->copy(self::str($a[0] ?? ''), self::str($a[1] ?? ''), $uidMode, $name === 'MOVE'),
                    'EXPUNGE' => $this->expunge($uidMode ? self::str($a[0] ?? '') : null, true),
                };
                if (in_array($name, ['COPY', 'MOVE', 'EXPUNGE'], true)) {
                    $this->refresh(true);
                }
                break;
            default:
                $this->out($tag . ' BAD Unknown command');
                return null;
        }
        $this->out($tag . ' OK ' . ($uidMode ? 'UID ' : '') . $name . ' completed');
        return null;
    }

    // ------------------------------------------------------------------ folders

    private function loadFolders(): void
    {
        $byId = [];
        foreach (DB::all('SELECT * FROM folders WHERE user_id = :u ORDER BY id', ['u' => $this->uid]) as $f) {
            $byId[(int) $f['id']] = $f;
        }
        $hidden = Mailbox::$privacy ? Folders::personalIds($this->uid) : [];
        $this->folders = [];
        foreach ($byId as $id => $f) {
            if (in_array($id, $hidden, true)) {
                continue;
            }
            $parts = [];
            for ($x = $f, $n = 0; $x && $n < 20; $x = $x['parent_id'] !== null ? ($byId[(int) $x['parent_id']] ?? null) : null, $n++) {
                array_unshift($parts, $x['role'] ? (self::ROLE_NAMES[$x['role']] ?? (string) $x['name']) : str_replace('/', '-', (string) $x['name']));
            }
            $this->folders[implode('/', $parts)] = $f;
        }
    }

    private function folder(string $name): ?array
    {
        $this->loadFolders();
        $name = ImapClient::decodeName(trim($name, '/'));
        if (strcasecmp($name, 'INBOX') === 0) {
            $name = 'INBOX';
        }
        return $this->folders[$name] ?? null;
    }

    private function list(string $cmd, string $ref, string $pattern): void
    {
        if ($pattern === '') {
            $this->out('* ' . $cmd . ' (\\Noselect) "/" ""');
            return;
        }
        $this->loadFolders();
        $re = '/^' . str_replace(['\\*', '%'], ['.*', '[^\\/]*'], preg_quote(ImapClient::decodeName($ref . $pattern), '/')) . '$/i';
        $names = array_keys($this->folders);
        foreach ($names as $name) {
            if (!preg_match($re, $name)) {
                continue;
            }
            $f = $this->folders[$name];
            $attrs = [];
            $hasKids = (bool) array_filter($names, static fn($n) => str_starts_with($n, $name . '/'));
            $attrs[] = $hasKids ? '\\HasChildren' : '\\HasNoChildren';
            if (isset(self::SPECIAL[$f['role'] ?? ''])) {
                $attrs[] = self::SPECIAL[$f['role']];
            }
            $this->out('* ' . $cmd . ' (' . implode(' ', $attrs) . ') "/" ' . self::quote(ImapClient::encodeName($name)));
        }
    }

    private function status(string $name, array $items): void
    {
        $f = $this->folder($name);
        if (!$f) {
            throw new \InvalidArgumentException('No such mailbox');
        }
        $this->assign($f);
        $f = DB::one('SELECT * FROM folders WHERE id = :id', ['id' => $f['id']]);
        $c = DB::one('SELECT COUNT(*) AS n, SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS u FROM messages WHERE user_id = :u AND folder_id = :f', ['u' => $this->uid, 'f' => $f['id']]);
        $out = [];
        foreach ($items as $it) {
            $k = strtoupper(self::str($it));
            $v = match ($k) {
                'MESSAGES' => (int) $c['n'], 'UNSEEN' => (int) $c['u'], 'RECENT' => 0,
                'UIDNEXT' => (int) $f['uid_next'], 'UIDVALIDITY' => (int) $f['uid_validity'], default => null,
            };
            if ($v !== null) {
                $out[] = $k . ' ' . $v;
            }
        }
        $this->out('* STATUS ' . self::quote(ImapClient::encodeName($name)) . ' (' . implode(' ', $out) . ')');
    }

    private function create(string $name): void
    {
        $name = trim(ImapClient::decodeName($name), '/');
        $this->loadFolders();
        if ($name === '' || isset($this->folders[$name]) || strcasecmp($name, 'INBOX') === 0) {
            throw new \InvalidArgumentException('Mailbox exists');
        }
        $parent = null;
        $path = '';
        foreach (explode('/', $name) as $part) {
            $path = $path === '' ? $part : $path . '/' . $part;
            if (!isset($this->folders[$path])) {
                $parent = Folders::create($this->uid, $part, $parent);
                $this->loadFolders();
            } else {
                $parent = (int) $this->folders[$path]['id'];
            }
        }
    }

    private function deleteFolder(string $name): bool
    {
        $f = $this->folder($name);
        if (!$f || $f['role']) {
            return false;
        }
        Folders::delete($this->uid, (int) $f['id']);
        return true;
    }

    private function renameFolder(string $from, string $to): bool
    {
        $f = $this->folder($from);
        $to = trim(ImapClient::decodeName($to), '/');
        if (!$f || $f['role'] || $to === '' || $this->folder($to)) {
            return false;
        }
        $parts = explode('/', $to);
        $leaf = array_pop($parts);
        $parentId = null;
        if ($parts) {
            $p = $this->folder(implode('/', $parts));
            if (!$p) {
                return false;
            }
            $parentId = (int) $p['id'];
        }
        Folders::rename($this->uid, (int) $f['id'], $leaf);
        DB::update('folders', ['parent_id' => $parentId], 'id = :id AND user_id = :u', ['id' => $f['id'], 'u' => $this->uid]);
        return true;
    }

    // ------------------------------------------------------------------ UIDs and state

    /** UIDs for the messages of a folder that have none there yet (new, or moved in). */
    private function assign(array $f): void
    {
        $fid = (int) $f['id'];
        if (!(int) $f['uid_validity']) {
            DB::run('UPDATE folders SET uid_validity = :v WHERE id = :f AND uid_validity = 0', ['v' => max(1, time() - 1700000000 + $fid), 'f' => $fid]);
        }
        $todo = DB::all('SELECT id, storage_path, raw_prefix FROM messages WHERE user_id = :u AND folder_id = :f AND (imap_folder <> :f2 OR imap_uid = 0) ORDER BY date_received, id', ['u' => $this->uid, 'f' => $fid, 'f2' => $fid]);
        if (!$todo) {
            return;
        }
        $n = count($todo);
        $first = (int) DB::transaction(static function () use ($fid, $n) {
            DB::run('UPDATE folders SET uid_next = uid_next + :n WHERE id = :f', ['n' => $n, 'f' => $fid]);
            return (int) DB::value('SELECT uid_next FROM folders WHERE id = :f', ['f' => $fid]) - $n;
        });
        foreach ($todo as $k => $m) {
            $size = strlen(self::crlf(Mailbox::raw($m)));
            DB::update('messages', ['imap_uid' => $first + $k, 'imap_folder' => $fid, 'imap_size' => $size, 'imap_deleted' => 0], 'id = :id', ['id' => $m['id']]);
        }
    }

    private function select(string $name, bool $readOnly): bool
    {
        $f = $this->folder($name);
        if (!$f) {
            $this->sel = null;
            return false;
        }
        $this->sel = $f;
        $this->readOnly = $readOnly;
        $this->uids = [];
        $this->rows = [];
        $this->raws = [];
        $this->load();
        $f = DB::one('SELECT * FROM folders WHERE id = :id', ['id' => $f['id']]);
        $this->sel = $f;
        $unseen = 0;
        foreach ($this->uids as $i => $u) {
            if (!(int) $this->rows[$u]['is_read']) {
                $unseen = $i + 1;
                break;
            }
        }
        $this->out('* FLAGS (\\Answered \\Flagged \\Deleted \\Seen \\Draft $Forwarded)');
        $this->out('* OK [PERMANENTFLAGS (\\Answered \\Flagged \\Deleted \\Seen \\Draft $Forwarded)] Flags permitted');
        $this->out('* ' . count($this->uids) . ' EXISTS');
        $this->out('* 0 RECENT');
        if ($unseen) {
            $this->out('* OK [UNSEEN ' . $unseen . '] First unseen');
        }
        $this->out('* OK [UIDVALIDITY ' . (int) $f['uid_validity'] . '] UIDs valid');
        $this->out('* OK [UIDNEXT ' . (int) $f['uid_next'] . '] Predicted next UID');
        return true;
    }

    /** @return array{0:int[],1:array<int,array>} the folder's messages by UID */
    private function current(): array
    {
        $this->assign($this->sel);
        $rows = [];
        foreach (DB::all('SELECT id, imap_uid, imap_size, imap_deleted, is_read, is_flagged, is_answered, is_forwarded, is_draft, date_received, size FROM messages WHERE user_id = :u AND folder_id = :f AND imap_folder = :f2 ORDER BY imap_uid',
            ['u' => $this->uid, 'f' => $this->sel['id'], 'f2' => $this->sel['id']]) as $r) {
            $rows[(int) $r['imap_uid']] = $r;
        }
        return [array_keys($rows), $rows];
    }

    private function folderStamp(): string
    {
        $s = DB::one('SELECT COUNT(*) AS n, COALESCE(SUM(id), 0) AS s, COALESCE(SUM(is_read + 2 * is_flagged + 4 * is_answered + 8 * imap_deleted + 16 * is_forwarded), 0) AS fl, COALESCE(MAX(imap_uid), 0) AS mu
            FROM messages WHERE user_id = :u AND folder_id = :f', ['u' => $this->uid, 'f' => $this->sel['id']]);
        return implode('|', array_map('strval', (array) $s));
    }

    private function load(): void
    {
        [$this->uids, $this->rows] = $this->current();
        $this->stamp = $this->folderStamp();
    }

    /** What changed in the selected folder meanwhile (other apps, the webmail, new mail). */
    private function refresh(bool $expunges): void
    {
        if (!$this->sel || $this->folderStamp() === $this->stamp) {
            return;
        }
        [$uids, $rows] = $this->current();
        if ($expunges) {
            $gone = array_diff($this->uids, $uids);
            if ($gone) {
                // Highest sequence numbers first: the others keep theirs.
                $seq = array_flip($this->uids);
                $nums = array_map(static fn($u) => $seq[$u] + 1, $gone);
                rsort($nums);
                foreach ($nums as $n) {
                    $this->out('* ' . $n . ' EXPUNGE');
                }
                $this->uids = array_values(array_diff($this->uids, $gone));
            }
        }
        $new = array_values(array_diff($uids, $this->uids));
        $kept = array_flip($this->uids);
        foreach ($rows as $u => $r) {
            if (isset($kept[$u], $this->rows[$u]) && $this->flags($this->rows[$u]) !== $this->flags($r)) {
                $this->out('* ' . ($kept[$u] + 1) . ' FETCH (UID ' . $u . ' FLAGS (' . $this->flags($r) . '))');
            }
        }
        if ($new) {
            $this->uids = array_merge($this->uids, $new);
            $this->out('* ' . count($this->uids) . ' EXISTS');
        }
        foreach ($rows as $u => $r) {
            $this->rows[$u] = $r;
        }
        if ($expunges) {
            $this->rows = array_intersect_key($this->rows, array_flip($this->uids));
            $this->stamp = $this->folderStamp();
        }
    }

    private function flags(array $r): string
    {
        $f = [];
        foreach (['is_read' => '\\Seen', 'is_flagged' => '\\Flagged', 'is_answered' => '\\Answered', 'is_draft' => '\\Draft', 'imap_deleted' => '\\Deleted', 'is_forwarded' => '$Forwarded'] as $k => $v) {
            if ((int) ($r[$k] ?? 0)) {
                $f[] = $v;
            }
        }
        return implode(' ', $f);
    }

    /** Sequence or UID set → list of UIDs present. */
    private function set(string $set, bool $uidMode): array
    {
        $out = [];
        $max = $uidMode ? (end($this->uids) ?: 0) : count($this->uids);
        foreach (explode(',', $set) as $part) {
            if ($part === '$' || $part === '') {
                continue;
            }
            [$a, $b] = array_pad(explode(':', $part, 2), 2, null);
            $a = $a === '*' ? $max : (int) $a;
            $b = $b === null ? $a : ($b === '*' ? $max : (int) $b);
            if ($a > $b) {
                [$a, $b] = [$b, $a];
            }
            if ($uidMode) {
                foreach ($this->uids as $u) {
                    if ($u >= $a && $u <= $b) {
                        $out[$u] = true;
                    }
                }
            } else {
                for ($n = max(1, $a); $n <= min($b, count($this->uids)); $n++) {
                    $out[$this->uids[$n - 1]] = true;
                }
            }
        }
        $list = array_keys($out);
        sort($list);
        return $list;
    }

    // ------------------------------------------------------------------ FETCH

    private function raw(int $uid): string
    {
        if (!isset($this->raws[$uid])) {
            if (count($this->raws) > 20) {
                $this->raws = [];
            }
            $m = DB::one('SELECT id, storage_path, raw_prefix FROM messages WHERE id = :id', ['id' => $this->rows[$uid]['id']]);
            $this->raws[$uid] = self::crlf($m ? Mailbox::raw($m) : '');
        }
        return $this->raws[$uid];
    }

    private static function crlf(string $s): string
    {
        return (string) preg_replace("/\r?\n/", "\r\n", $s);
    }

    private function fetch(string $set, mixed $items, bool $uidMode): void
    {
        $items = is_array($items) && !isset($items['q']) ? $items : [$items];
        $want = [];
        foreach ($items as $it) {
            $w = strtoupper(self::str($it));
            $want = array_merge($want, match ($w) {
                'ALL' => ['FLAGS', 'INTERNALDATE', 'RFC822.SIZE', 'ENVELOPE'],
                'FAST' => ['FLAGS', 'INTERNALDATE', 'RFC822.SIZE'],
                'FULL' => ['FLAGS', 'INTERNALDATE', 'RFC822.SIZE', 'ENVELOPE', 'BODY'],
                default => [self::str($it)],
            });
        }
        if ($uidMode && !in_array('UID', array_map('strtoupper', $want), true)) {
            array_unshift($want, 'UID');
        }
        $seq = array_flip($this->uids);
        $seen = [];
        foreach ($this->set($set, $uidMode) as $u) {
            $r = $this->rows[$u];
            $parts = [];
            $setSeen = false;
            foreach ($want as $w) {
                $W = strtoupper($w);
                if ($W === 'UID') {
                    $parts[] = 'UID ' . $u;
                } elseif ($W === 'FLAGS') {
                    $parts[] = 'FLAGS (' . $this->flags($r) . ')';
                } elseif ($W === 'INTERNALDATE') {
                    $parts[] = 'INTERNALDATE "' . date('d-M-Y H:i:s O', (int) $r['date_received']) . '"';
                } elseif ($W === 'RFC822.SIZE') {
                    $parts[] = 'RFC822.SIZE ' . (int) $r['imap_size'];
                } elseif ($W === 'ENVELOPE') {
                    $parts[] = 'ENVELOPE ' . self::envelope(self::split($this->raw($u))[0]);
                } elseif ($W === 'BODYSTRUCTURE' || $W === 'BODY') {
                    $parts[] = $W . ' ' . self::structure($this->raw($u), $W === 'BODYSTRUCTURE');
                } elseif ($W === 'RFC822' || $W === 'RFC822.HEADER' || $W === 'RFC822.TEXT') {
                    [$h, $b] = self::split($this->raw($u));
                    $data = $W === 'RFC822' ? $this->raw($u) : ($W === 'RFC822.HEADER' ? $h : $b);
                    $parts[] = $W . ' {' . strlen($data) . "}\r\n" . $data;
                    $setSeen = $setSeen || $W !== 'RFC822.HEADER';
                } elseif (preg_match('/^(BODY(?:\.PEEK)?)\[([^\]]*)\](?:<(\d+)(?:\.(\d+))?>)?$/i', $w, $m)) {
                    $data = $this->section($this->raw($u), $m[2]);
                    $origin = '';
                    if (isset($m[3]) && $m[3] !== '') {
                        $data = substr($data, (int) $m[3], isset($m[4]) && $m[4] !== '' ? (int) $m[4] : null);
                        $origin = '<' . (int) $m[3] . '>';
                    }
                    $parts[] = 'BODY[' . $m[2] . ']' . $origin . ' {' . strlen($data) . "}\r\n" . $data;
                    $setSeen = $setSeen || strcasecmp($m[1], 'BODY') === 0;
                }
            }
            if ($setSeen && !$this->readOnly && !(int) $r['is_read']) {
                $seen[] = (int) $r['id'];
                $this->rows[$u]['is_read'] = 1;
                $parts[] = 'FLAGS (' . $this->flags($this->rows[$u]) . ')';
            }
            $this->write('* ' . ($seq[$u] + 1) . ' FETCH (' . implode(' ', $parts) . ")\r\n");
        }
        if ($seen) {
            Mailbox::setFlags($this->uid, $seen, ['is_read' => 1]);
            $this->stamp = $this->folderStamp();
        }
    }

    // ------------------------------------------------------------------ MIME

    /** [header block with its blank line, body] */
    private static function split(string $raw): array
    {
        $p = strpos($raw, "\r\n\r\n");
        if ($p === false) {
            return [$raw . "\r\n", ''];
        }
        return [substr($raw, 0, $p + 4), substr($raw, $p + 4)];
    }

    /** @return array<string, string> lower-case name => unfolded value (first one) */
    private static function headers(string $h): array
    {
        $out = [];
        foreach (preg_split("/\r\n(?![ \t])/", rtrim($h, "\r\n")) ?: [] as $line) {
            if (($c = strpos($line, ':')) !== false) {
                $k = strtolower(trim(substr($line, 0, $c)));
                $out[$k] ??= trim((string) preg_replace("/\r\n[ \t]+/", ' ', substr($line, $c + 1)));
            }
        }
        return $out;
    }

    private static function params(string $v): array
    {
        $out = [];
        preg_match_all('/;\s*([\w.*-]+)\s*=\s*("(?:[^"\\\\]|\\\\.)*"|[^;\s]+)/', $v, $m, PREG_SET_ORDER);
        foreach ($m as $p) {
            $out[strtolower($p[1])] = trim($p[2], '"');
        }
        return $out;
    }

    /** The body parts of a multipart body (what follows the closing boundary is not one). */
    private static function subparts(string $body, string $boundary): array
    {
        if ($boundary === '') {
            return [];
        }
        $b = preg_quote($boundary, '/');
        if (preg_match('/(?:^|\r\n)--' . $b . '--[ \t]*(?:\r\n|$)/', $body, $m, PREG_OFFSET_CAPTURE)) {
            $body = substr($body, 0, $m[0][1]);
        }
        $parts = preg_split('/(?:^|\r\n)--' . $b . '[ \t]*(?:\r\n|$)/', $body) ?: [];
        array_shift($parts); // preamble
        return $parts;
    }

    private static function ctype(array $h): array
    {
        $ct = $h['content-type'] ?? 'text/plain; charset=us-ascii';
        [$type, $sub] = array_pad(explode('/', strtolower(trim(explode(';', $ct)[0])), 2), 2, '');
        return [$type ?: 'text', $sub ?: 'plain', self::params($ct)];
    }

    /** The section of a message asked for: "", HEADER, TEXT, HEADER.FIELDS (…), 1, 1.2, 2.MIME… */
    private function section(string $raw, string $spec): string
    {
        $spec = trim($spec);
        if ($spec === '') {
            return $raw;
        }
        if (!preg_match('/^((?:\d+\.)*\d+)?\.?(HEADER\.FIELDS\.NOT|HEADER\.FIELDS|HEADER|TEXT|MIME)?\s*(?:\((.*)\))?$/i', $spec, $m)) {
            return '';
        }
        $path = $m[1] ?? '';
        $what = strtoupper($m[2] ?? '');
        $msg = $raw;
        $partHead = '';
        if ($path !== '') {
            [$partHead, $partBody, $msg] = self::part($raw, explode('.', $path));
            if ($what === '') {
                return $partBody;
            }
            if ($what === 'MIME') {
                return $partHead;
            }
        }
        [$h, $b] = self::split($msg);
        if ($what === 'TEXT') {
            return $b;
        }
        if ($what === 'HEADER') {
            return $h;
        }
        $names = array_map('strtolower', preg_split('/\s+/', trim($m[3] ?? '')) ?: []);
        $names = array_map(static fn($n) => trim($n, '"'), $names);
        $keep = '';
        foreach (preg_split("/\r\n(?![ \t])/", rtrim($h, "\r\n")) ?: [] as $line) {
            $k = strtolower(trim(strstr($line, ':', true) ?: ''));
            if ($k !== '' && (in_array($k, $names, true) xor $what === 'HEADER.FIELDS.NOT')) {
                $keep .= $line . "\r\n";
            }
        }
        return $keep . "\r\n";
    }

    /** @return array{0:string,1:string,2:string} [part headers, part body, the message inside when message/rfc822] */
    private static function part(string $raw, array $path): array
    {
        $head = '';
        $body = $raw;
        $msg = $raw;
        foreach ($path as $n) {
            [$h, $b] = self::split($msg);
            [$type, , $p] = self::ctype(self::headers($h));
            if ($type === 'multipart') {
                $parts = self::subparts($b, (string) ($p['boundary'] ?? ''));
                $one = $parts[(int) $n - 1] ?? '';
                [$head, $body] = self::split($one);
            } else {
                // A single-part message: part 1 is its body.
                [$head, $body] = [$h, $b];
            }
            [$t2, $s2] = self::ctype(self::headers($head));
            $msg = $t2 === 'message' && $s2 === 'rfc822' ? $body : $head . $body;
        }
        return [$head, $body, $msg];
    }

    private static function nstring(?string $s): string
    {
        return $s === null || $s === '' ? 'NIL' : self::quote($s);
    }

    private static function addresses(?string $v): string
    {
        if ($v === null || trim($v) === '') {
            return 'NIL';
        }
        $out = [];
        // Split on commas outside quotes and angle brackets.
        $cur = '';
        $q = false;
        $a = 0;
        foreach (str_split($v) as $ch) {
            if ($ch === '"') {
                $q = !$q;
            } elseif (!$q && $ch === '<') {
                $a++;
            } elseif (!$q && $ch === '>') {
                $a--;
            }
            if ($ch === ',' && !$q && $a <= 0) {
                $out[] = $cur;
                $cur = '';
            } else {
                $cur .= $ch;
            }
        }
        $out[] = $cur;
        $list = [];
        foreach ($out as $one) {
            $one = trim($one);
            if ($one === '') {
                continue;
            }
            if (preg_match('/^(.*)<([^>]*)>\s*$/s', $one, $m)) {
                $name = trim(trim($m[1]), '"');
                $addr = trim($m[2]);
            } else {
                $name = '';
                $addr = trim($one, '<> ');
            }
            [$mbox, $host] = array_pad(explode('@', $addr, 2), 2, '');
            $list[] = '(' . self::nstring($name) . ' NIL ' . self::nstring($mbox) . ' ' . self::nstring($host) . ')';
        }
        return $list ? '(' . implode('', $list) . ')' : 'NIL';
    }

    private static function envelope(string $header): string
    {
        $h = self::headers($header);
        $from = $h['from'] ?? null;
        return '(' . implode(' ', [
            self::nstring($h['date'] ?? null), self::nstring($h['subject'] ?? null),
            self::addresses($from), self::addresses($h['sender'] ?? $from), self::addresses($h['reply-to'] ?? $from),
            self::addresses($h['to'] ?? null), self::addresses($h['cc'] ?? null), self::addresses($h['bcc'] ?? null),
            self::nstring($h['in-reply-to'] ?? null), self::nstring($h['message-id'] ?? null),
        ]) . ')';
    }

    private static function plist(array $p): string
    {
        if (!$p) {
            return 'NIL';
        }
        $out = [];
        foreach ($p as $k => $v) {
            $out[] = self::quote(strtoupper((string) $k)) . ' ' . self::quote((string) $v);
        }
        return '(' . implode(' ', $out) . ')';
    }

    /** BODY / BODYSTRUCTURE of a whole message (or of a part: its headers and body together). */
    private static function structure(string $raw, bool $ext, int $depth = 0): string
    {
        [$h, $b] = self::split($raw);
        $hd = self::headers($h);
        [$type, $sub, $p] = self::ctype($hd);
        if ($type === 'multipart' && $depth < 20) {
            $kids = '';
            foreach (self::subparts($b, (string) ($p['boundary'] ?? '')) as $part) {
                $kids .= self::structure($part, $ext, $depth + 1);
            }
            if ($kids === '') {
                $kids = '("TEXT" "PLAIN" ("CHARSET" "US-ASCII") NIL NIL "7BIT" 0 0)';
            }
            return '(' . $kids . ' ' . self::quote(strtoupper($sub)) . ($ext ? ' ' . self::plist(array_intersect_key($p, ['boundary' => 1])) . ' NIL NIL NIL' : '') . ')';
        }
        $enc = strtoupper($hd['content-transfer-encoding'] ?? '7BIT');
        $s = '(' . self::quote(strtoupper($type)) . ' ' . self::quote(strtoupper($sub)) . ' ' . self::plist($p) . ' '
            . self::nstring($hd['content-id'] ?? null) . ' ' . self::nstring($hd['content-description'] ?? null) . ' ' . self::quote($enc) . ' ' . strlen($b);
        $lines = substr_count($b, "\r\n") + ($b !== '' && !str_ends_with($b, "\r\n") ? 1 : 0);
        if ($type === 'text') {
            $s .= ' ' . $lines;
        } elseif ($type === 'message' && $sub === 'rfc822' && $depth < 20) {
            $s .= ' ' . self::envelope(self::split($b)[0]) . ' ' . self::structure($b, $ext, $depth + 1) . ' ' . $lines;
        }
        if ($ext) {
            $disp = $hd['content-disposition'] ?? '';
            $s .= ' NIL ' . ($disp !== '' ? '(' . self::quote(strtoupper(trim(explode(';', $disp)[0]))) . ' ' . self::plist(self::params($disp)) . ')' : 'NIL') . ' NIL NIL';
        }
        return $s . ')';
    }

    // ------------------------------------------------------------------ STORE, COPY, MOVE, EXPUNGE

    private function store(string $set, string $op, mixed $list, bool $uidMode): void
    {
        if ($this->readOnly) {
            throw new \InvalidArgumentException('Read-only mailbox');
        }
        $flags = array_map(static fn($f) => strtolower(self::str($f)), is_array($list) && !isset($list['q']) ? $list : [$list]);
        $map = ['\\seen' => 'is_read', '\\flagged' => 'is_flagged', '\\answered' => 'is_answered', '\\deleted' => 'imap_deleted', '$forwarded' => 'is_forwarded', '\\draft' => 'is_draft'];
        $silent = str_ends_with($op, '.SILENT');
        $mode = $op[0] === '+' ? 1 : ($op[0] === '-' ? -1 : 0);
        $seq = array_flip($this->uids);
        foreach ($this->set($set, $uidMode) as $u) {
            $r = $this->rows[$u];
            $new = [];
            foreach ($map as $flag => $col) {
                $on = in_array($flag, $flags, true);
                $v = $mode === 1 ? ($on ? 1 : (int) $r[$col]) : ($mode === -1 ? ($on ? 0 : (int) $r[$col]) : ($on ? 1 : 0));
                if ($v !== (int) $r[$col]) {
                    $new[$col] = $v;
                }
            }
            if ($new) {
                DB::update('messages', $new, 'id = :id AND user_id = :u', ['id' => $r['id'], 'u' => $this->uid]);
                $this->rows[$u] = $new + $r;
            }
            if (!$silent) {
                $this->out('* ' . ($seq[$u] + 1) . ' FETCH (' . ($uidMode ? 'UID ' . $u . ' ' : '') . 'FLAGS (' . $this->flags($this->rows[$u]) . '))');
            }
        }
        $this->stamp = $this->folderStamp();
    }

    private function copy(string $set, string $to, bool $uidMode, bool $move): void
    {
        $target = $this->folder($to);
        if (!$target) {
            throw new \InvalidArgumentException('[TRYCREATE] No such mailbox');
        }
        $ids = array_map(fn($u) => (int) $this->rows[$u]['id'], $this->set($set, $uidMode));
        if (!$ids) {
            return;
        }
        if ($move) {
            if ((int) $target['id'] !== (int) $this->sel['id']) {
                Mailbox::move($this->uid, $ids, (int) $target['id']);
            }
            return;
        }
        foreach ($ids as $id) {
            $m = DB::one('SELECT * FROM messages WHERE id = :id AND user_id = :u', ['id' => $id, 'u' => $this->uid]);
            if ($m) {
                $new = Mailbox::store($this->uid, (int) $target['id'], Mailbox::raw($m), ['read' => (int) $m['is_read'], 'flagged' => (int) $m['is_flagged'], 'draft' => (int) $m['is_draft'], 'date_received' => (int) $m['date_received']]);
                DB::update('messages', ['is_answered' => (int) $m['is_answered'], 'is_forwarded' => (int) $m['is_forwarded']], 'id = :id', ['id' => $new]);
            }
        }
    }

    private function expunge(?string $uidSet, bool $tell): void
    {
        if ($this->readOnly) {
            return;
        }
        $only = $uidSet !== null ? array_flip($this->set($uidSet, true)) : null;
        $rows = DB::all('SELECT id, folder_id, storage_path, size, imap_uid FROM messages WHERE user_id = :u AND folder_id = :f AND imap_deleted = 1', ['u' => $this->uid, 'f' => $this->sel['id']]);
        foreach ($rows as $r) {
            if ($only !== null && !isset($only[(int) $r['imap_uid']])) {
                continue;
            }
            Mailbox::purge($this->uid, $r, 'deleted');
        }
        if (!$tell) {
            $this->load();
        }
    }

    // ------------------------------------------------------------------ APPEND

    private function append(array $a): void
    {
        $f = $this->folder(self::str($a[0] ?? ''));
        if (!$f) {
            throw new \InvalidArgumentException('[TRYCREATE] No such mailbox');
        }
        $raw = self::str(end($a));
        $flags = [];
        foreach (array_slice($a, 1, -1) as $x) {
            if (is_array($x) && !isset($x['q'])) {
                $flags = array_map(static fn($y) => strtolower(self::str($y)), $x);
            }
        }
        // A copy of what the app just sent through our SMTP: already in Sent.
        if (($f['role'] ?? '') === 'sent' && preg_match('/^Message-ID:\s*(<[^>]+>)/mi', $raw, $m)
            && DB::value('SELECT COUNT(*) FROM messages WHERE user_id = :u AND folder_id = :f AND message_id IN (:a, :b)', ['u' => $this->uid, 'f' => $f['id'], 'a' => trim($m[1], '<>'), 'b' => $m[1]])) {
            return;
        }
        $id = Mailbox::store($this->uid, (int) $f['id'], $raw, [
            'read' => in_array('\\seen', $flags, true), 'flagged' => in_array('\\flagged', $flags, true),
            'draft' => in_array('\\draft', $flags, true) || ($f['role'] ?? '') === 'drafts',
        ]);
        if (in_array('\\answered', $flags, true)) {
            DB::update('messages', ['is_answered' => 1], 'id = :id', ['id' => $id]);
        }
    }

    // ------------------------------------------------------------------ SEARCH

    private function search(array $a, bool $uidMode): void
    {
        // CHARSET x: everything is UTF-8 here.
        if (strtoupper(self::str($a[0] ?? '')) === 'CHARSET') {
            $a = array_slice($a, 2);
        }
        $i = 0;
        $test = $this->criteria($a, $i);
        $hits = [];
        $seq = array_flip($this->uids);
        foreach ($this->uids as $u) {
            if ($test($u)) {
                $hits[] = $uidMode ? $u : $seq[$u] + 1;
            }
        }
        $this->out('* SEARCH' . ($hits ? ' ' . implode(' ', $hits) : ''));
    }

    private ?array $textCache = null;

    private function text(int $u): array
    {
        if ($this->textCache === null) {
            $this->textCache = [];
            foreach (DB::all('SELECT id, imap_uid, from_name, from_email, to_list, cc_list, bcc_list, subject, body_text, message_id FROM messages WHERE user_id = :u AND folder_id = :f', ['u' => $this->uid, 'f' => $this->sel['id']]) as $r) {
                $this->textCache[(int) $r['imap_uid']] = $r;
            }
        }
        return $this->textCache[$u] ?? [];
    }

    /** One search key (and what follows it) as a test on a UID. */
    private function criteria(array $a, int &$i): \Closure
    {
        $this->textCache = null;
        $all = [];
        while ($i < count($a)) {
            $all[] = $this->criterion($a, $i);
        }
        return static fn(int $u) => array_reduce($all, static fn($ok, $t) => $ok && $t($u), true);
    }

    private function criterion(array $a, int &$i): \Closure
    {
        $tok = $a[$i++] ?? '';
        if (is_array($tok) && !isset($tok['q'])) {
            $j = 0;
            return $this->criteria($tok, $j);
        }
        $k = strtoupper(self::str($tok));
        $arg = function () use ($a, &$i): string {
            return self::str($a[$i++] ?? '');
        };
        $row = fn(int $u) => $this->rows[$u] ?? [];
        $like = fn(int $u, array $cols, string $s) => (bool) array_filter($cols, fn($c) => mb_stripos((string) ($this->text($u)[$c] ?? ''), $s) !== false);
        $day = static fn(string $d) => (int) strtotime($d . ' 00:00:00');
        switch ($k) {
            case 'ALL':
                return static fn() => true;
            case 'SEEN': return fn($u) => (int) $row($u)['is_read'] === 1;
            case 'UNSEEN': return fn($u) => (int) $row($u)['is_read'] === 0;
            case 'FLAGGED': return fn($u) => (int) $row($u)['is_flagged'] === 1;
            case 'UNFLAGGED': return fn($u) => (int) $row($u)['is_flagged'] === 0;
            case 'ANSWERED': return fn($u) => (int) $row($u)['is_answered'] === 1;
            case 'UNANSWERED': return fn($u) => (int) $row($u)['is_answered'] === 0;
            case 'DELETED': return fn($u) => (int) $row($u)['imap_deleted'] === 1;
            case 'UNDELETED': return fn($u) => (int) $row($u)['imap_deleted'] === 0;
            case 'DRAFT': return fn($u) => (int) $row($u)['is_draft'] === 1;
            case 'UNDRAFT': return fn($u) => (int) $row($u)['is_draft'] === 0;
            case 'RECENT': case 'NEW': return static fn() => false;
            case 'OLD': return static fn() => true;
            case 'SINCE': case 'SENTSINCE': $d = $day($arg()); return fn($u) => (int) $row($u)['date_received'] >= $d;
            case 'BEFORE': case 'SENTBEFORE': $d = $day($arg()); return fn($u) => (int) $row($u)['date_received'] < $d;
            case 'ON': case 'SENTON': $d = $day($arg()); return fn($u) => (int) $row($u)['date_received'] >= $d && (int) $row($u)['date_received'] < $d + 86400;
            case 'LARGER': $n = (int) $arg(); return fn($u) => (int) $row($u)['imap_size'] > $n;
            case 'SMALLER': $n = (int) $arg(); return fn($u) => (int) $row($u)['imap_size'] < $n;
            case 'FROM': $s = $arg(); return fn($u) => $like($u, ['from_name', 'from_email'], $s);
            case 'TO': $s = $arg(); return fn($u) => $like($u, ['to_list'], $s);
            case 'CC': $s = $arg(); return fn($u) => $like($u, ['cc_list'], $s);
            case 'BCC': $s = $arg(); return fn($u) => $like($u, ['bcc_list'], $s);
            case 'SUBJECT': $s = $arg(); return fn($u) => $like($u, ['subject'], $s);
            case 'BODY': $s = $arg(); return fn($u) => $like($u, ['body_text'], $s);
            case 'TEXT': $s = $arg(); return fn($u) => $like($u, ['subject', 'from_name', 'from_email', 'to_list', 'cc_list', 'body_text'], $s);
            case 'HEADER':
                $name = strtolower($arg());
                $s = $arg();
                $col = ['message-id' => 'message_id', 'subject' => 'subject', 'from' => 'from_email', 'to' => 'to_list', 'cc' => 'cc_list'][$name] ?? null;
                return fn($u) => $col === null ? $s === '' : $like($u, [$col], trim($s, '<>'));
            case 'KEYWORD': $s = strtolower($arg()); return fn($u) => $s === '$forwarded' && (int) $row($u)['is_forwarded'] === 1;
            case 'UNKEYWORD': $s = strtolower($arg()); return fn($u) => !($s === '$forwarded' && (int) $row($u)['is_forwarded'] === 1);
            case 'UID': $set = array_flip($this->set($arg(), true)); return static fn($u) => isset($set[$u]);
            case 'NOT': $t = $this->criterion($a, $i); return static fn($u) => !$t($u);
            case 'OR': $x = $this->criterion($a, $i); $y = $this->criterion($a, $i); return static fn($u) => $x($u) || $y($u);
            default:
                if (preg_match('/^[\d*:,]+$/', $k)) {
                    $set = array_flip($this->set($k, false));
                    return static fn($u) => isset($set[$u]);
                }
                throw new \InvalidArgumentException('Unknown search key ' . $k);
        }
    }

    // ------------------------------------------------------------------ IDLE

    private function idle(string $tag): void
    {
        $this->out('+ idling');
        $until = time() + self::IDLE_MAX;
        $next = 0;
        while (time() < $until) {
            $r = [$this->c];
            $w = $x = null;
            if (@stream_select($r, $w, $x, 2) > 0 || (stream_get_meta_data($this->c)['unread_bytes'] ?? 0) > 0) {
                $line = $this->readLine(30);
                if ($line === null) {
                    throw new \RuntimeException('closed');
                }
                if (strtoupper(trim($line)) === 'DONE') {
                    break;
                }
            }
            if (microtime(true) >= $next) {
                $next = microtime(true) + 2;
                $this->refresh(true);
            }
        }
        $this->out($tag . ' OK IDLE terminated');
    }
}
