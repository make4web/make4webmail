<?php
declare(strict_types=1);

namespace M4W\Mail;

/**
 * Minimal pure PHP IMAP4rev1 client used to fetch mail from external accounts
 * (no ext-imap required).
 */
final class ImapClient
{
    private const MAX_LITERAL = 64 * 1024 * 1024;

    /** @var resource|null */
    private $sock = null;
    private int $tag = 0;

    public function __construct(
        private string $host,
        private int $port = 993,
        private string $security = 'ssl',
        private int $timeout = 30,
        private bool $verifyPeer = true,
        private string $connectTo = '',
    ) {
    }

    public function connect(string $user, string $pass): void
    {
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => $this->verifyPeer, 'verify_peer_name' => $this->verifyPeer,
            'allow_self_signed' => !$this->verifyPeer, 'peer_name' => $this->host, 'SNI_enabled' => true,
        ]]);
        $target = $this->connectTo !== '' ? $this->connectTo : $this->host;
        if (str_contains($target, ':')) {
            $target = '[' . $target . ']';
        }
        $remote = ($this->security === 'ssl' ? 'ssl://' : 'tcp://') . $target . ':' . $this->port;
        $sock = @stream_socket_client($remote, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            throw new \RuntimeException("IMAP: connexion impossible ($errstr)");
        }
        stream_set_timeout($sock, $this->timeout);
        $this->sock = $sock;
        $greeting = $this->readLine();
        if (!str_starts_with($greeting, '* OK') && !str_starts_with($greeting, '* PREAUTH')) {
            throw new \RuntimeException('IMAP: accueil inattendu');
        }
        if ($this->security === 'tls') {
            $this->command('STARTTLS');
            if (!@stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0))) {
                throw new \RuntimeException('IMAP: échec STARTTLS');
            }
        }
        $this->command('LOGIN ' . self::quote($user) . ' ' . self::quote($pass), true);
    }

    /** @return array{exists:int,uidvalidity:int} */
    public function select(string $folder): array
    {
        $res = $this->command('SELECT ' . self::quote($folder));
        $info = ['exists' => 0, 'uidvalidity' => 0];
        foreach ($res['lines'] as $l) {
            if (preg_match('/^\* (\d+) EXISTS/i', $l, $m)) {
                $info['exists'] = (int) $m[1];
            }
            if (preg_match('/UIDVALIDITY (\d+)/i', $l, $m)) {
                $info['uidvalidity'] = (int) $m[1];
            }
        }
        return $info;
    }

    /** @return int[] */
    public function searchUidsAbove(int $lastUid): array
    {
        $res = $this->command('UID SEARCH UID ' . ($lastUid + 1) . ':*');
        $uids = [];
        foreach ($res['lines'] as $l) {
            if (preg_match('/^\* SEARCH(.*)$/i', $l, $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $u) {
                    if ($u !== '' && (int) $u > $lastUid) {
                        $uids[] = (int) $u;
                    }
                }
            }
        }
        sort($uids);
        return $uids;
    }

    public function fetchRaw(int $uid): ?string
    {
        $res = $this->command('UID FETCH ' . $uid . ' (BODY.PEEK[])');
        return $res['literals'][0] ?? null;
    }

    /**
     * The message with what the provider knows about it: flags (\\Seen, \\Flagged…) and the
     * date it arrived in the mailbox (INTERNALDATE).
     * @return ?array{raw:string,flags:string[],date:int}
     */
    public function fetchMessage(int $uid): ?array
    {
        $res = $this->command('UID FETCH ' . $uid . ' (FLAGS INTERNALDATE BODY.PEEK[])');
        if (!isset($res['literals'][0])) {
            return null;
        }
        $meta = implode(' ', $res['lines']);
        return ['raw' => $res['literals'][0], 'flags' => self::flags($meta), 'date' => self::internalDate($meta)];
    }

    /**
     * Flags of every message of the selected folder, by Message-ID.
     * @return array<string,string[]>
     */
    public function flagsByMessageId(int $max = 20000): array
    {
        $out = [];
        $uids = array_slice($this->searchUidsAbove(0), -$max);
        foreach (array_chunk($uids, 300) as $chunk) {
            $res = $this->command('UID FETCH ' . implode(',', $chunk) . ' (FLAGS BODY.PEEK[HEADER.FIELDS (MESSAGE-ID)])');
            // Each response line announcing a literal goes with the next literal.
            $i = 0;
            foreach ($res['lines'] as $line) {
                if (!preg_match('/\{\d+\}$/', $line)) {
                    continue;
                }
                $hdr = $res['literals'][$i++] ?? '';
                if (preg_match('/Message-ID:\s*<([^>]+)>/i', $hdr, $m)) {
                    $out[$m[1]] = self::flags($line);
                }
            }
        }
        return $out;
    }

    /**
     * What tells two copies of one message apart from two messages: its Message-ID, and, for a copy
     * whose Message-ID was changed on the way (forwarding, a server without one), the sender, the
     * date and the subject together. From the raw header lines.
     * @return array{msgid:string,key:string}
     */
    public static function fingerprint(string $headers): array
    {
        $h = preg_replace("/\r?\n[ \t]+/", ' ', str_replace("\r\n", "\n", $headers));
        $get = static fn(string $name) => preg_match('/^' . $name . ':[ \t]*(.*)$/mi', (string) $h, $m) ? trim($m[1]) : '';
        $from = preg_match('/[\w.+%\'-]+@[\w.-]+/', $get('From'), $fm) ? mb_strtolower($fm[0]) : '';
        $date = $get('Date');
        $ts = $date !== '' ? (strtotime(preg_replace('/\s*\([^)]*\)\s*$/', '', $date)) ?: 0) : 0;
        $subject = mb_strtolower((string) preg_replace('/\s+/', ' ', $get('Subject')));
        return [
            'msgid' => preg_match('/<([^>\s]+)>/', $get('Message-ID'), $mm) ? $mm[1] : '',
            'key' => $from !== '' && $ts > 0 ? sha1($from . '|' . $ts . '|' . $subject) : '',
        ];
    }

    /**
     * Every message of the selected folder: UID => fingerprint() + flags.
     * @return array<int,array{msgid:string,key:string,flags:string[]}>
     */
    public function fingerprints(int $max = 20000): array
    {
        $out = [];
        $uids = array_slice($this->searchUidsAbove(0), -$max);
        foreach (array_chunk($uids, 300) as $chunk) {
            $res = $this->command('UID FETCH ' . implode(',', $chunk) . ' (UID FLAGS BODY.PEEK[HEADER.FIELDS (MESSAGE-ID DATE FROM SUBJECT)])');
            $i = 0;
            foreach ($res['lines'] as $line) {
                if (!preg_match('/\{\d+\}$/', $line)) {
                    continue;
                }
                $hdr = $res['literals'][$i++] ?? '';
                if (preg_match('/\bUID (\d+)/i', $line, $u)) {
                    $out[(int) $u[1]] = self::fingerprint($hdr) + ['flags' => self::flags($line)];
                }
            }
        }
        return $out;
    }

    /** @return string[] */
    private static function flags(string $meta): array
    {
        return preg_match('/FLAGS \(([^)]*)\)/i', $meta, $m) ? array_filter(explode(' ', strtolower($m[1]))) : [];
    }

    private static function internalDate(string $meta): int
    {
        if (preg_match('/INTERNALDATE "([^"]+)"/i', $meta, $m)) {
            $t = strtotime(trim($m[1]));
            return $t !== false ? $t : 0;
        }
        return 0;
    }

    /**
     * Put a message into a folder (IMAP APPEND), with its flags and its original date.
     * @param string[] $flags e.g. ['\\Seen']
     */
    public function append(string $folder, string $raw, array $flags = [], int $date = 0): void
    {
        $raw = str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $raw);
        $flags = array_values(array_filter($flags, static fn($f) => preg_match('/^\\\\?[A-Za-z$]+$/', $f) === 1));
        $tag = 'A' . (++$this->tag);
        $cmd = $tag . ' APPEND ' . self::quote($folder) . ($flags ? ' (' . implode(' ', $flags) . ')' : '')
            . ($date > 0 ? ' "' . date('d-M-Y H:i:s O', $date) . '"' : '') . ' {' . strlen($raw) . '}';
        fwrite($this->sock, $cmd . "\r\n");
        // The server answers "+" before it takes the message.
        while (true) {
            $line = $this->readLine();
            if (str_starts_with($line, '+')) {
                break;
            }
            if (str_starts_with($line, $tag . ' ')) {
                throw new \RuntimeException('IMAP: ' . substr($line, strlen($tag) + 1));
            }
        }
        fwrite($this->sock, $raw . "\r\n");
        while (true) {
            $line = $this->readLine();
            if (str_starts_with($line, $tag . ' ')) {
                if (strtoupper(substr($line, strlen($tag) + 1, 2)) !== 'OK') {
                    throw new \RuntimeException('IMAP: ' . substr($line, strlen($tag) + 1));
                }
                return;
            }
        }
    }

    /** Folder list (LIST "" "*"), read once per connection: name => attributes. */
    private ?array $folders = null;
    private ?string $delimiter = null;

    /** The separator of the folder hierarchy ("/" or "."), as the server lists it. */
    public function delimiter(): string
    {
        $this->folders();
        return $this->delimiter ?: '/';
    }

    public function createFolder(string $name): void
    {
        $this->command('CREATE ' . self::quote($name));
        $this->command('SUBSCRIBE ' . self::quote($name), false, true);
        $this->folders = null;
    }

    public function renameFolder(string $from, string $to): void
    {
        $this->command('RENAME ' . self::quote($from) . ' ' . self::quote($to));
        $this->command('SUBSCRIBE ' . self::quote($to), false, true);
        $this->folders = null;
    }

    public function deleteFolder(string $name): void
    {
        $this->command('DELETE ' . self::quote($name));
        $this->folders = null;
    }

    /** Modified UTF-7 (RFC 3501) to UTF-8, and back: folder names as people read them. */
    public static function decodeName(string $name): string
    {
        $s = @mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP');
        return is_string($s) && $s !== '' ? $s : $name;
    }

    public static function encodeName(string $name): string
    {
        return (string) mb_convert_encoding($name, 'UTF7-IMAP', 'UTF-8');
    }

    /** @return array<string,string> */
    public function folders(): array
    {
        if ($this->folders !== null) {
            return $this->folders;
        }
        $this->folders = [];
        foreach ($this->command('LIST "" "*"')['lines'] as $l) {
            if (!preg_match('/^\* LIST \(([^)]*)\) ("[^"]*"|NIL) (.+)$/i', $l, $m)) {
                continue;
            }
            if ($this->delimiter === null && $m[2] !== 'NIL') {
                $this->delimiter = stripcslashes(substr($m[2], 1, -1));
            }
            $m[2] = $m[3];
            $name = trim($m[2]);
            if (str_starts_with($name, '"')) {
                $name = stripcslashes(substr($name, 1, -1));
            }
            $this->folders[$name] = $m[1];
        }
        return $this->folders;
    }

    /**
     * A folder by its role: special-use attribute (\Sent, \Trash, \Archive, \Junk) first,
     * else a usual name. Names as the server lists them (modified UTF-7), as APPEND expects them.
     */
    public function specialFolder(string $use, array $guesses): string
    {
        $folders = $this->folders();
        foreach ($folders as $name => $attrs) {
            if (stripos($attrs, $use) !== false) {
                return $name;
            }
        }
        foreach ($guesses as $guess) {
            foreach (array_keys($folders) as $n) {
                if (strcasecmp((string) $n, $guess) === 0) {
                    return (string) $n;
                }
            }
        }
        return '';
    }

    /** The folder the provider uses for sent mail. */
    public function sentFolder(): string
    {
        return $this->specialFolder('\\Sent', ['Sent', 'INBOX.Sent', 'Sent Items', 'Sent Messages', 'Envoy&AOk-s', 'INBOX.Envoy&AOk-s', '&AMk-l&AOk-ments envoy&AOk-s']);
    }

    public function trashFolder(): string
    {
        return $this->specialFolder('\\Trash', ['Trash', 'INBOX.Trash', 'Deleted Items', 'Deleted Messages', 'Corbeille', 'INBOX.Corbeille', '&AMk-l&AOk-ments supprim&AOk-s', 'Bin']);
    }

    public function archiveFolder(): string
    {
        return $this->specialFolder('\\Archive', ['Archive', 'Archives', 'INBOX.Archive', 'INBOX.Archives']);
    }

    /**
     * Every message of the selected folder: UID => Message-ID (without <>) and flags.
     * @return array<int,array{msgid:string,flags:string[]}>
     */
    public function index(int $max = 20000): array
    {
        $out = [];
        $uids = array_slice($this->searchUidsAbove(0), -$max);
        foreach (array_chunk($uids, 300) as $chunk) {
            $res = $this->command('UID FETCH ' . implode(',', $chunk) . ' (UID FLAGS BODY.PEEK[HEADER.FIELDS (MESSAGE-ID)])');
            $i = 0;
            foreach ($res['lines'] as $line) {
                if (!preg_match('/\{\d+\}$/', $line)) {
                    continue;
                }
                $hdr = $res['literals'][$i++] ?? '';
                if (preg_match('/\bUID (\d+)/i', $line, $u)) {
                    $out[(int) $u[1]] = ['msgid' => preg_match('/Message-ID:\s*<([^>]+)>/i', $hdr, $m) ? $m[1] : '', 'flags' => self::flags($line)];
                }
            }
        }
        return $out;
    }

    /** Add or remove flags (\Seen, \Flagged…) on one message of the selected folder. */
    public function storeFlags(int $uid, bool $add, array $flags): void
    {
        $flags = array_values(array_filter($flags, static fn($f) => preg_match('/^\\\\?[A-Za-z$]+$/', $f) === 1));
        if ($flags) {
            $this->command('UID STORE ' . $uid . ' ' . ($add ? '+' : '-') . 'FLAGS.SILENT (' . implode(' ', $flags) . ')');
        }
    }

    /** Move one message of the selected folder to another (MOVE, else COPY + delete). */
    public function moveTo(int $uid, string $folder): void
    {
        try {
            $this->command('UID MOVE ' . $uid . ' ' . self::quote($folder));
        } catch (\RuntimeException) {
            $this->command('UID COPY ' . $uid . ' ' . self::quote($folder));
            $this->markDeleted($uid);
            $this->expunge();
        }
    }

    /** UIDs of the selected folder holding this Message-ID. @return int[] */
    public function searchMessageId(string $msgid): array
    {
        if ($msgid === '' || preg_match('/[\r\n"\\\\]/', $msgid)) {
            return [];
        }
        $res = $this->command('UID SEARCH HEADER Message-ID ' . self::quote('<' . $msgid . '>'));
        foreach ($res['lines'] as $l) {
            if (preg_match('/^\* SEARCH(.*)$/i', $l, $m)) {
                return array_map('intval', array_filter(preg_split('/\s+/', trim($m[1])) ?: []));
            }
        }
        return [];
    }

    public function markDeleted(int $uid): void
    {
        $this->command('UID STORE ' . $uid . ' +FLAGS.SILENT (\\Deleted)');
    }

    public function expunge(): void
    {
        $this->command('EXPUNGE');
    }

    public function logout(): void
    {
        if ($this->sock) {
            try {
                $this->command('LOGOUT');
            } catch (\Throwable) {
            }
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    public static function quote(string $s): string
    {
        // A quoted string can never contain line breaks or NUL: they would start a new command.
        if (preg_match('/[\r\n\x00]/', $s)) {
            throw new \InvalidArgumentException('IMAP: caractère interdit');
        }
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }

    /** @return array{lines:string[],literals:string[]} */
    /** @param bool $quiet a refusal is not an error (optional commands such as SUBSCRIBE) */
    private function command(string $cmd, bool $secret = false, bool $quiet = false): array
    {
        $tag = 'A' . (++$this->tag);
        fwrite($this->sock, $tag . ' ' . $cmd . "\r\n");
        $lines = [];
        $literals = [];
        while (true) {
            $line = $this->readLine();
            if (preg_match('/\{(\d+)\}$/', $line, $m)) {
                $len = (int) $m[1];
                // A hostile server could announce an enormous literal to exhaust memory.
                if ($len > self::MAX_LITERAL) {
                    throw new \RuntimeException('IMAP: réponse trop volumineuse');
                }
                $data = '';
                while (strlen($data) < $len) {
                    $chunk = fread($this->sock, min(65536, $len - strlen($data)));
                    if ($chunk === false || $chunk === '') {
                        throw new \RuntimeException('IMAP: lecture interrompue');
                    }
                    $data .= $chunk;
                }
                $literals[] = $data;
                $lines[] = $line;
                continue;
            }
            if (str_starts_with($line, $tag . ' ')) {
                $status = strtoupper(substr($line, strlen($tag) + 1, 2));
                if ($status !== 'OK' && !$quiet) {
                    throw new \RuntimeException('IMAP: ' . ($secret ? 'authentification refusée' : substr($line, strlen($tag) + 1)));
                }
                break;
            }
            $lines[] = $line;
        }
        return ['lines' => $lines, 'literals' => $literals];
    }

    private function readLine(): string
    {
        $line = fgets($this->sock, 8192);
        if ($line === false) {
            throw new \RuntimeException('IMAP: connexion fermée');
        }
        return rtrim($line, "\r\n");
    }
}
