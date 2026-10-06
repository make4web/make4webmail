#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Make4Web Mail — built-in inbound SMTP server.
 *
 *   php bin/smtpd.php [--listen=0.0.0.0:2525] [--foreground]
 *
 * Accepts mail for hosted domains only (never an open relay), then hands it to the
 * local delivery agent (rules, vacation, forwarding). Supports STARTTLS when
 * 'inbound' => ['tls_cert' => ..., 'tls_key' => ...] is set in config/config.php.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

use M4W\Core\App;
use M4W\Core\Config;
use M4W\Core\Database;
use M4W\Core\Settings;
use M4W\Service\Delivery;
use M4W\Service\Transport;
use M4W\Service\Users;

if (!Config::installed()) {
    fwrite(STDERR, "Make4Web Mail is not installed.\n");
    exit(1);
}

$opts = getopt('', ['listen::', 'foreground', 'max-clients::', 'max-per-ip::']);
$listen = (string) ($opts['listen'] ?? Settings::get('inbound.listen', '0.0.0.0:2525'));
$maxSize = max(1, (int) Settings::get('inbound.max_size_mb', 35)) * 1024 * 1024;
$allowed = array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) Settings::get('inbound.allowed_ips', '')) ?: []));
$hostname = (string) (Settings::get('smtp.helo', '') ?: gethostname() ?: 'localhost');
$cert = (string) Config::get('inbound.tls_cert', '');
$key = (string) Config::get('inbound.tls_key', '');
$tls = $cert !== '' && is_file($cert);

$ctx = stream_context_create(['ssl' => [
    'local_cert' => $cert, 'local_pk' => $key ?: null, 'allow_self_signed' => true, 'verify_peer' => false,
    'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_SERVER') ? STREAM_CRYPTO_METHOD_TLSv1_3_SERVER : 0),
], 'socket' => ['so_reuseport' => true]]);
$server = @stream_socket_server('tcp://' . $listen, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if (!$server) {
    fwrite(STDERR, "Cannot listen on $listen: $errstr\n");
    exit(1);
}
smtpd_log("listening on $listen" . ($tls ? ' (STARTTLS available)' : ''));
$canFork = function_exists('pcntl_fork');
if ($canFork) {
    pcntl_async_signals(true);
    pcntl_signal(SIGCHLD, static function () use (&$children) {
        while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
            unset($children[$pid]);
        }
    });
    $stop = static function () use ($server) {
        smtpd_log('shutting down');
        fclose($server);
        exit(0);
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

// Connection limits against slow or flooding clients.
$maxClients = max(1, (int) ($opts['max-clients'] ?? 50));
$maxPerIp = max(1, (int) ($opts['max-per-ip'] ?? 5));
$children = [];
while (true) {
    $client = @stream_socket_accept($server, 60, $peer);
    if (!$client) {
        continue;
    }
    if ($canFork) {
        $peerIp = trim(preg_replace('/:\d+$/', '', (string) $peer) ?? '', '[]');
        $sameIp = count(array_filter($children, static fn($ip) => $ip === $peerIp));
        if (count($children) >= $maxClients || $sameIp >= $maxPerIp) {
            @fwrite($client, "421 4.7.0 Too many connections, try again later\r\n");
            fclose($client);
            smtpd_log("refused $peerIp: " . count($children) . " sessions, $sameIp from this address");
            continue;
        }
        $pid = pcntl_fork();
        if ($pid > 0) {
            $children[$pid] = $peerIp;
        }
        if ($pid === 0) {
            fclose($server);
            Database::reset();
            Settings::flush();
            smtpd_session($client, (string) $peer, $hostname, $maxSize, $allowed, $tls);
            exit(0);
        }
        fclose($client);
        continue;
    }
    smtpd_session($client, (string) $peer, $hostname, $maxSize, $allowed, $tls);
}

function smtpd_log(string $msg): void
{
    $line = '[' . date('c') . '] smtpd: ' . $msg . "\n";
    file_put_contents(M4W\Core\Config::storagePath('logs/smtpd.log'), $line, FILE_APPEND | LOCK_EX);
    if (posix_isatty(STDOUT)) {
        echo $line;
    }
}

/** @param resource $c */
function smtpd_session($c, string $peer, string $hostname, int $maxSize, array $allowed, bool $tls): void
{
    $ip = preg_replace('/:\d+$/', '', $peer) ?? $peer;
    $ip = trim($ip, '[]');
    stream_set_timeout($c, 120);
    $send = static function (string $line) use ($c) {
        @fwrite($c, $line . "\r\n");
    };
    if ($allowed) {
        $ok = false;
        foreach ($allowed as $cidr) {
            if (App::ipInCidr($ip, $cidr)) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            $send('554 5.7.1 Access denied');
            fclose($c);
            smtpd_log("rejected connection from $ip");
            return;
        }
    }
    $send("220 $hostname ESMTP Make4Web Mail ready");
    $helo = '';
    $from = null;
    $rcpts = [];
    $secure = false;
    $errors = 0;
    while (!feof($c)) {
        $line = fgets($c, 4096);
        if ($line === false) {
            break;
        }
        $line = rtrim($line, "\r\n");
        $verb = strtoupper(substr($line, 0, 4));
        $arg = trim(substr($line, 4));
        if (preg_match('/^(EHLO|HELO)\s*(.*)$/i', $line, $m)) {
            $helo = mb_substr(preg_replace('/[^\w.\-\[\]:]/', '', $m[2]) ?? '', 0, 100);
            $from = null;
            $rcpts = [];
            if (strtoupper($m[1]) === 'HELO') {
                $send("250 $hostname");
            } else {
                $send("250-$hostname greets $helo");
                $send('250-SIZE ' . $maxSize);
                $send('250-8BITMIME');
                $send('250-PIPELINING');
                if ($tls && !$secure) {
                    $send('250-STARTTLS');
                }
                $send('250 SMTPUTF8');
            }
            continue;
        }
        switch ($verb) {
            case 'STAR':
                if (!$tls || $secure) {
                    $send('454 4.7.0 TLS not available');
                    break;
                }
                // Anything pipelined after STARTTLS was sent in clear text: refuse it (CVE-2011-0411 class).
                stream_set_blocking($c, false);
                $leftover = fread($c, 1);
                stream_set_blocking($c, true);
                if ($leftover !== false && $leftover !== '') {
                    $send('554 5.5.1 Pipelining not allowed after STARTTLS');
                    fclose($c);
                    return;
                }
                $send('220 2.0.0 Ready to start TLS');
                if (!@stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_SERVER') ? STREAM_CRYPTO_METHOD_TLSv1_3_SERVER : 0))) {
                    fclose($c);
                    return;
                }
                $secure = true;
                $helo = '';
                break;
            case 'MAIL':
                if ($helo === '') {
                    $send('503 5.5.1 Say EHLO first');
                    break;
                }
                if (!preg_match('/^FROM:\s*<([^>]*)>(.*)$/i', $arg, $m)) {
                    $send('501 5.5.4 Syntax: MAIL FROM:<address>');
                    break;
                }
                if (preg_match('/SIZE=(\d+)/i', $m[2], $s) && (int) $s[1] > $maxSize) {
                    $send('552 5.3.4 Message size exceeds limit');
                    break;
                }
                $from = mb_substr(trim($m[1]), 0, 255);
                if ($from !== '' && !is_valid_email($from)) {
                    $from = null;
                    $send('553 5.1.7 Invalid sender address');
                    break;
                }
                $rcpts = [];
                $send('250 2.1.0 OK');
                break;
            case 'RCPT':
                if ($from === null) {
                    $send('503 5.5.1 Need MAIL first');
                    break;
                }
                if (!preg_match('/^TO:\s*<([^>]+)>/i', $arg, $m)) {
                    $send('501 5.5.4 Syntax: RCPT TO:<address>');
                    break;
                }
                if (count($rcpts) >= 100) {
                    $send('452 4.5.3 Too many recipients');
                    break;
                }
                $addr = mb_strtolower(trim($m[1]));
                if (!is_valid_email($addr)) {
                    $send('553 5.1.3 Invalid recipient address');
                    break;
                }
                $user = Users::resolveLocal($addr);
                $domain = substr($addr, (int) strrpos($addr, '@') + 1);
                if (!$user) {
                    $errors++;
                    $send(Users::isLocalDomain($domain) ? '550 5.1.1 User unknown' : '554 5.7.1 Relay access denied');
                    break;
                }
                if (Users::overQuota($user)) {
                    $send('452 4.2.2 Mailbox full');
                    break;
                }
                $rcpts[] = $addr;
                $send('250 2.1.5 OK');
                break;
            case 'DATA':
                if (!$rcpts) {
                    $send('503 5.5.1 Need RCPT first');
                    break;
                }
                $send('354 End data with <CR><LF>.<CR><LF>');
                $data = '';
                $tooBig = false;
                while (($l = fgets($c, 8192)) !== false) {
                    if ($l === ".\r\n" || $l === ".\n") {
                        break;
                    }
                    if (isset($l[0]) && $l[0] === '.') {
                        $l = substr($l, 1);
                    }
                    if (!$tooBig) {
                        $data .= $l;
                        if (strlen($data) > $maxSize) {
                            $tooBig = true;
                            $data = '';
                        }
                    }
                }
                if ($tooBig) {
                    $send('552 5.3.4 Message size exceeds limit');
                } else {
                    $id = bin2hex(random_bytes(6));
                    $received = 'Return-Path: <' . $from . ">\r\n"
                        . 'Received: from ' . ($helo ?: 'unknown') . ' ([' . $ip . "])\r\n\tby $hostname (Make4Web Mail) with ESMTP" . ($secure ? 'S' : '') . " id $id\r\n\tfor <" . $rcpts[0] . '>; ' . date('r') . "\r\n";
                    try {
                        $auth = null;
                        if ((int) M4W\Core\Settings::get('inbound.auth_checks', 1)) {
                            try {
                                $auth = M4W\Mail\MailAuth::evaluate($data, $ip, (string) $from, $helo, [Users::class, 'isLocalDomain']);
                            } catch (\Throwable $e) {
                                smtpd_log('auth check error: ' . $e->getMessage());
                            }
                        }
                        $res = Delivery::deliver($received . $data, (string) $from, $rcpts, 'smtp', true, $auth);
                        $ok = count(array_filter($res, static fn($r) => $r === 'ok'));
                        $send($ok ? "250 2.0.0 OK queued as $id" : '451 4.3.0 Delivery failed');
                        smtpd_log("from=<$from> ip=$ip rcpt=" . implode(',', $rcpts) . ' size=' . strlen($data) . " id=$id ok=$ok" . ($auth ? ' auth=' . $auth['verdict'] . ' spf=' . $auth['spf'] . ' dmarc=' . $auth['dmarc'] : ''));
                        Transport::processQueue(20);
                    } catch (\Throwable $e) {
                        smtpd_log('delivery error: ' . $e->getMessage());
                        $send('451 4.3.0 Local error, try again later');
                    }
                }
                $from = null;
                $rcpts = [];
                break;
            case 'RSET':
                $from = null;
                $rcpts = [];
                $send('250 2.0.0 OK');
                break;
            case 'NOOP':
                $send('250 2.0.0 OK');
                break;
            case 'QUIT':
                $send('221 2.0.0 Bye');
                fclose($c);
                return;
            case 'VRFY':
                $send('252 2.5.2 Cannot verify user');
                break;
            default:
                $errors++;
                $send('502 5.5.2 Command not recognized');
        }
        if ($errors > 10) {
            $send('421 4.7.0 Too many errors');
            break;
        }
    }
    @fclose($c);
}
