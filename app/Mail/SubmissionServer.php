<?php
declare(strict_types=1);

namespace M4W\Mail;

use M4W\Core\Settings;
use M4W\Service\Composer;
use M4W\Service\HardBounces;

/**
 * Sending from mail apps (SMTP submission, after bin/maild.php checked the app password): the
 * message is sent as if written in the webmail (Composer::submit): the person's own addresses
 * only, signature, copy in Sent, sending limits, addresses known not to exist refused.
 */
final class SubmissionServer
{
    /** @var resource */
    private $c;
    private string $from = '';
    /** @var string[] */
    private array $rcpts = [];

    /** @param resource $conn */
    public function __construct($conn, private array $user, private string $ip, private string $host)
    {
        $this->c = $conn;
    }

    private function out(string $line): void
    {
        @fwrite($this->c, $line . "\r\n");
    }

    private function maxBytes(): int
    {
        // The attachments allowed, plus room for the text and the encoding (base64: 4/3).
        return (int) (max(1, (int) Settings::get('security.max_attachment_mb', 25)) * 1048576 * 1.4) + 1048576;
    }

    public function run(): void
    {
        while (true) {
            stream_set_timeout($this->c, 300);
            $line = fgets($this->c, 8192);
            if ($line === false) {
                return;
            }
            $line = rtrim($line, "\r\n");
            $verb = strtoupper((string) strtok($line, ' :'));
            switch ($verb) {
                case 'EHLO':
                    $this->out('250-' . $this->host);
                    $this->out('250-SIZE ' . $this->maxBytes());
                    $this->out('250-8BITMIME');
                    $this->out('250 ENHANCEDSTATUSCODES');
                    break;
                case 'HELO':
                    $this->out('250 ' . $this->host);
                    break;
                case 'MAIL':
                    if (!preg_match('/^MAIL FROM:\s*<([^>]*)>/i', $line, $m)) {
                        $this->out('501 5.5.4 Syntax: MAIL FROM:<address>');
                        break;
                    }
                    $this->from = mb_strtolower($m[1]);
                    $this->rcpts = [];
                    if (preg_match('/\bSIZE=(\d+)/i', $line, $sz) && (int) $sz[1] > $this->maxBytes()) {
                        $this->out('552 5.3.4 Message too big');
                        break;
                    }
                    $this->out('250 2.1.0 OK');
                    break;
                case 'RCPT':
                    if (!preg_match('/^RCPT TO:\s*<([^>]+@[^>]+)>/i', $line, $m)) {
                        $this->out('501 5.5.4 Syntax: RCPT TO:<address>');
                        break;
                    }
                    if (count($this->rcpts) >= 500) {
                        $this->out('452 4.5.3 Too many recipients');
                        break;
                    }
                    if (HardBounces::among([$m[1]])) {
                        $this->out('550 5.1.1 <' . $m[1] . '>: address does not exist (refused for good by its server)');
                        break;
                    }
                    $this->rcpts[] = $m[1];
                    $this->out('250 2.1.5 OK');
                    break;
                case 'DATA':
                    if (!$this->rcpts) {
                        $this->out('554 5.5.1 No valid recipients');
                        break;
                    }
                    $this->out('354 End data with <CR><LF>.<CR><LF>');
                    $raw = $this->readData();
                    if ($raw === null) {
                        return;
                    }
                    if ($raw === false) {
                        $this->out('552 5.3.4 Message too big');
                    } else {
                        $this->deliver($raw);
                    }
                    $this->rcpts = [];
                    break;
                case 'RSET':
                    $this->rcpts = [];
                    $this->out('250 2.0.0 OK');
                    break;
                case 'NOOP':
                    $this->out('250 2.0.0 OK');
                    break;
                case 'VRFY':
                    $this->out('252 2.1.5 Cannot verify');
                    break;
                case 'QUIT':
                    $this->out('221 2.0.0 Bye');
                    return;
                default:
                    $this->out('502 5.5.2 Command not recognized');
            }
        }
    }

    /** The message, dot-unstuffed; false when too big (read to its end all the same), null when cut. */
    private function readData(): string|false|null
    {
        $data = '';
        $max = $this->maxBytes();
        $over = false;
        while (true) {
            stream_set_timeout($this->c, 600);
            $l = fgets($this->c, 65536);
            if ($l === false) {
                return null;
            }
            if ($l === ".\r\n" || $l === ".\n") {
                return $over ? false : $data;
            }
            if (!$over) {
                $data .= str_starts_with($l, '.') ? substr($l, 1) : $l;
                $over = strlen($data) > $max;
            }
        }
    }

    private function deliver(string $raw): void
    {
        try {
            $res = Composer::submit($this->user, $raw, $this->rcpts);
            $this->out('250 2.0.0 OK' . (!empty($res['deferred']) ? ' (queued until ' . $res['deferred']['time'] . ')' : ''));
        } catch (\InvalidArgumentException $e) {
            // The person's own addresses only, recipients refused, quota…
            $this->out('550 5.7.1 ' . str_replace(["\r", "\n"], ' ', mb_substr($e->getMessage(), 0, 300)));
        } catch (\Throwable $e) {
            error_log('[m4w] submission: ' . $e->getMessage());
            $this->out('451 4.3.0 ' . str_replace(["\r", "\n"], ' ', mb_substr($e->getMessage(), 0, 200)));
        }
    }
}
