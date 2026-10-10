<?php
declare(strict_types=1);

namespace M4W\Mail;

/**
 * Pure PHP SMTP client: implicit TLS, STARTTLS, AUTH PLAIN/LOGIN/CRAM-MD5, SIZE, 8BITMIME.
 */
final class SmtpClient
{
    /** @var resource|null */
    private $sock = null;
    private array $ext = [];
    public array $transcript = [];

    public function __construct(
        private string $host,
        private int $port = 587,
        private string $security = 'tls', // ssl | tls | none
        private string $username = '',
        private string $password = '',
        private int $timeout = 20,
        private bool $verifyPeer = true,
        private string $helo = '',
        private bool $opportunisticTls = false,
    ) {
    }

    public function connect(): void
    {
        $ctx = stream_context_create(['ssl' => [
            'verify_peer'       => $this->verifyPeer,
            'verify_peer_name'  => $this->verifyPeer,
            'allow_self_signed' => !$this->verifyPeer,
            'peer_name'         => $this->host,
            'SNI_enabled'       => true,
        ]]);
        $remote = ($this->security === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client($remote, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            throw new SmtpException("Connexion SMTP impossible à {$this->host}:{$this->port} ($errstr)");
        }
        stream_set_timeout($sock, $this->timeout);
        $this->sock = $sock;
        $this->expect([220]);
        $this->ehlo();

        $canTls = isset($this->ext['STARTTLS']);
        if ($this->security === 'tls' || ($this->opportunisticTls && $canTls && $this->security !== 'ssl')) {
            if (!$canTls) {
                throw new SmtpException('Le serveur SMTP ne propose pas STARTTLS.');
            }
            $this->command('STARTTLS', [220]);
            $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            if (!@stream_socket_enable_crypto($this->sock, true, $method)) {
                throw new SmtpException('Échec de la négociation TLS (STARTTLS).');
            }
            $this->ehlo();
        }
        if ($this->username !== '') {
            $this->authenticate();
        }
    }

    private function ehlo(): void
    {
        $name = $this->helo ?: (gethostname() ?: 'localhost');
        try {
            $lines = $this->command('EHLO ' . $name, [250]);
        } catch (SmtpException) {
            $this->command('HELO ' . $name, [250]);
            $this->ext = [];
            return;
        }
        $this->ext = [];
        foreach (array_slice($lines, 1) as $l) {
            $parts = explode(' ', strtoupper(trim(substr($l, 4))), 2);
            $this->ext[$parts[0]] = $parts[1] ?? '';
        }
    }

    private function authenticate(): void
    {
        $mechs = explode(' ', $this->ext['AUTH'] ?? 'PLAIN LOGIN');
        if (in_array('PLAIN', $mechs, true)) {
            $this->command('AUTH PLAIN ' . base64_encode("\0" . $this->username . "\0" . $this->password), [235], true);
        } elseif (in_array('LOGIN', $mechs, true)) {
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($this->username), [334], true);
            $this->command(base64_encode($this->password), [235], true);
        } elseif (in_array('CRAM-MD5', $mechs, true)) {
            $lines = $this->command('AUTH CRAM-MD5', [334]);
            $challenge = base64_decode(substr($lines[0], 4));
            $this->command(base64_encode($this->username . ' ' . hash_hmac('md5', $challenge, $this->password)), [235], true);
        } else {
            throw new SmtpException('Aucun mécanisme d\'authentification compatible.');
        }
    }

    /**
     * @param string[] $recipients
     * @return array<string,string> rejected recipients => reason
     */
    public function send(string $from, array $recipients, string $data): array
    {
        if (!$this->sock) {
            $this->connect();
        }
        foreach (array_merge([$from], $recipients) as $addr) {
            // Envelope addresses go verbatim into SMTP commands: no spaces, brackets or control characters.
            if (preg_match('/[\s<>\x00-\x1f\x7f]/', (string) $addr)) {
                throw new SmtpException('Invalid envelope address');
            }
        }
        $size = isset($this->ext['SIZE']) ? ' SIZE=' . strlen($data) : '';
        $body8 = isset($this->ext['8BITMIME']) ? ' BODY=8BITMIME' : '';
        $this->command('MAIL FROM:<' . Address::asciiEmail($from) . '>' . $size . $body8, [250]);
        $rejected = [];
        $accepted = 0;
        foreach ($recipients as $rcpt) {
            try {
                $this->command('RCPT TO:<' . Address::asciiEmail($rcpt) . '>', [250, 251]);
                $accepted++;
            } catch (SmtpException $e) {
                $rejected[$rcpt] = $e->getMessage();
            }
        }
        if ($accepted === 0) {
            $this->command('RSET', [250]);
            throw new SmtpException('Tous les destinataires ont été refusés : ' . implode('; ', $rejected), 550, true);
        }
        $this->command('DATA', [354]);
        $data = str_replace(["\r\n", "\r"], "\n", $data);
        $data = str_replace("\n", "\r\n", $data);
        // Dot-stuffing
        $data = preg_replace('/^\./m', '..', $data) ?? $data;
        if (!str_ends_with($data, "\r\n")) {
            $data .= "\r\n";
        }
        $this->write($data . ".\r\n");
        $this->expect([250]);
        return $rejected;
    }

    public function quit(): void
    {
        if ($this->sock) {
            try {
                $this->command('QUIT', [221]);
            } catch (\Throwable) {
            }
            fclose($this->sock);
            $this->sock = null;
        }
    }

    public function __destruct()
    {
        if ($this->sock) {
            @fclose($this->sock);
        }
    }

    private function write(string $s): void
    {
        $len = strlen($s);
        $off = 0;
        while ($off < $len) {
            $n = @fwrite($this->sock, substr($s, $off, 65536));
            if ($n === false || $n === 0) {
                throw new SmtpException('Connexion SMTP interrompue pendant l\'envoi.');
            }
            $off += $n;
        }
    }

    /** @return string[] response lines */
    private function command(string $cmd, array $expect, bool $secret = false): array
    {
        $this->transcript[] = 'C: ' . ($secret ? '***' : $cmd);
        $this->write($cmd . "\r\n");
        return $this->expect($expect);
    }

    /** @return string[] */
    private function expect(array $codes): array
    {
        $lines = [];
        while (true) {
            $line = fgets($this->sock, 4096);
            if ($line === false) {
                $meta = stream_get_meta_data($this->sock);
                throw new SmtpException($meta['timed_out'] ? 'Délai SMTP dépassé.' : 'Connexion SMTP fermée par le serveur.');
            }
            $line = rtrim($line, "\r\n");
            $lines[] = $line;
            $this->transcript[] = 'S: ' . $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $code = (int) substr(end($lines), 0, 3);
        if (!in_array($code, $codes, true)) {
            $msg = implode(' ', array_map(static fn($l) => substr($l, 4), $lines));
            throw new SmtpException("SMTP $code : $msg", $code, $code >= 500);
        }
        return $lines;
    }
}
