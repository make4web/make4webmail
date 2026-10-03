<?php
declare(strict_types=1);

namespace M4W\Mail;

/**
 * Minimal pure PHP IMAP4rev1 client used to fetch mail from external accounts
 * (no ext-imap required).
 */
final class ImapClient
{
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
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }

    /** @return array{lines:string[],literals:string[]} */
    private function command(string $cmd, bool $secret = false): array
    {
        $tag = 'A' . (++$this->tag);
        fwrite($this->sock, $tag . ' ' . $cmd . "\r\n");
        $lines = [];
        $literals = [];
        while (true) {
            $line = $this->readLine();
            if (preg_match('/\{(\d+)\}$/', $line, $m)) {
                $len = (int) $m[1];
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
                if ($status !== 'OK') {
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
