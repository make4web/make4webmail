#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * IMAP for mail apps, for every Make4WebMail of this server (see MailGateway): one service on
 * the shared ports, started by the scheduled task of any instance (one at a time: lock).
 *
 * It stands alone until someone signs in: TLS 1.3 only, the name asked for must be the shared
 * one (scanners trying the address get nothing), bans after failed attempts (for all the
 * instances: they share the address), no software name given. A signed-in connection is served,
 * in its own process, by the instance that knows the address, with its code and its database.
 *
 *   php bin/maild.php --dir=/tmp/m4w-maild     (the common folder: maild.json, instances/)
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$dir = '';
foreach ($argv as $a) {
    if (str_starts_with($a, '--dir=')) {
        $dir = rtrim(substr($a, 6), '/');
    }
}
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "usage: maild.php --dir=<common folder>\n");
    exit(1);
}
$lock = fopen($dir . '/maild.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // already running
}
ftruncate($lock, 0);
fwrite($lock, (string) getmypid());
fflush($lock);
if (!function_exists('pcntl_fork')) {
    file_put_contents($dir . '/maild-status.json', json_encode(['error' => 'pcntl missing (php-cli: pcntl extension)', 'at' => time()]));
    exit(1);
}
@set_time_limit(0);
pcntl_async_signals(true);
$stop = false;
pcntl_signal(SIGTERM, static function () use (&$stop) { $stop = true; });

function maild_log(string $dir, string $m): void
{
    $f = $dir . '/maild.log';
    if (@filesize($f) > 5 * 1048576) {
        @rename($f, $f . '.1');
    }
    @file_put_contents($f, date('c') . ' ' . $m . "\n", FILE_APPEND);
}

function maild_config(string $dir): array
{
    $c = json_decode((string) @file_get_contents($dir . '/maild.json'), true) ?: [];
    $c += ['host' => 'webmail.make4web.com', 'imap_port' => 51993, 'smtp_port' => 51465, 'cert' => '', 'key' => ''];
    $c['host'] = strtolower((string) $c['host']);
    $c['cert'] = $c['cert'] ?: '/etc/letsencrypt/live/' . $c['host'] . '/fullchain.pem';
    $c['key'] = $c['key'] ?: '/etc/letsencrypt/live/' . $c['host'] . '/privkey.pem';
    return $c;
}

// ---------------------------------------------------------------- bans (shared by the instances)

/** Read and change the bans under their lock. */
function maild_bans(string $dir, ?callable $fn = null): array
{
    $h = @fopen($dir . '/bans.json', 'c+');
    if (!$h) {
        return [];
    }
    flock($h, $fn ? LOCK_EX : LOCK_SH);
    $b = json_decode((string) stream_get_contents($h), true) ?: [];
    if ($fn) {
        $now = time();
        $b = $fn($b);
        // Forgotten: no ban running and nothing for a week.
        $b = array_filter($b, static fn($x) => (int) ($x['until'] ?? 0) > $now || (int) ($x['last'] ?? 0) > $now - 7 * 86400);
        ftruncate($h, 0);
        rewind($h);
        fwrite($h, json_encode($b));
    }
    flock($h, LOCK_UN);
    fclose($h);
    return $b;
}

/** A failed attempt (or a probe: the wrong name, nothing sent); too many: banned, longer each time. */
function maild_fail(string $dir, string $ip, string $why, int $limit = 5): void
{
    maild_bans($dir, static function (array $b) use ($ip, $limit, $dir, $why) {
        $now = time();
        $x = $b[$ip] ?? ['fails' => [], 'level' => 0, 'until' => 0];
        $x['fails'] = array_values(array_filter((array) $x['fails'], static fn($t) => $t > $now - 900));
        $x['fails'][] = $now;
        $x['last'] = $now;
        if (count($x['fails']) >= $limit) {
            $x['until'] = $now + [900, 3600, 86400, 604800][min(3, (int) $x['level'])];
            $x['level'] = (int) $x['level'] + 1;
            $x['fails'] = [];
            maild_log($dir, "$ip banned until " . date('c', $x['until']) . " ($why)");
        }
        $b[$ip] = $x;
        return $b;
    });
}

function maild_succeeded(string $dir, string $ip): void
{
    maild_bans($dir, static function (array $b) use ($ip) {
        if (isset($b[$ip])) {
            $b[$ip]['fails'] = [];
        }
        return $b;
    });
}

// ---------------------------------------------------------------- TLS: the name asked for

/** The name in a TLS ClientHello (SNI), '' if none. */
function maild_sni(string $d): string
{
    if (strlen($d) < 43 || ord($d[0]) !== 0x16 || ord($d[5]) !== 0x01) {
        return '';
    }
    $p = 43; // record (5) + handshake header (4) + version (2) + random (32)
    $p += 1 + ord($d[$p]);                                  // session id
    $p += 2 + (int) unpack('n', substr($d, $p, 2))[1];      // cipher suites
    $p += 1 + ord($d[$p] ?? "\0");                          // compression
    $end = $p + 2 + (int) (unpack('n', substr($d, $p, 2))[1] ?? 0);
    for ($p += 2; $p + 4 <= min($end, strlen($d));) {
        $type = unpack('n', substr($d, $p, 2))[1];
        $len = unpack('n', substr($d, $p + 2, 2))[1];
        if ($type === 0 && $len > 5) {
            $nl = unpack('n', substr($d, $p + 7, 2))[1];
            return strtolower(substr($d, $p + 9, $nl));
        }
        $p += 4 + $len;
    }
    return '';
}

// ---------------------------------------------------------------- one connection (its own process)

function maild_serve($c, string $ip, array $cfg, string $dir): void
{
    stream_set_timeout($c, 15);
    // The beginning of the TLS handshake, read without taking it.
    $hello = '';
    for ($i = 0; $i < 30 && strlen($hello) < 64; $i++) {
        $hello = (string) @stream_socket_recvfrom($c, 4096, STREAM_PEEK);
        if ($hello === '' && feof($c)) {
            return;
        }
        if (strlen($hello) < 64) {
            usleep(100000);
        }
    }
    $sni = maild_sni($hello);
    if ($sni !== $cfg['host']) {
        // A scanner (by address, or another name): nothing to see, and counted.
        maild_fail($dir, $ip, 'probe' . ($sni !== '' ? ' ' . $sni : ''), 3);
        return;
    }
    stream_context_set_option($c, 'ssl', 'local_cert', $cfg['cert']);
    stream_context_set_option($c, 'ssl', 'local_pk', $cfg['key']);
    stream_context_set_option($c, 'ssl', 'disable_compression', true);
    stream_context_set_option($c, 'ssl', 'verify_peer', false);
    stream_set_blocking($c, true);
    if (!@stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLSv1_3_SERVER)) {
        return;
    }
    $out = static function (string $l) use ($c): void { @fwrite($c, $l . "\r\n"); };
    $out('* OK [CAPABILITY IMAP4rev1 LITERAL+ SASL-IR ID AUTH=PLAIN] Ready');
    $fails = 0;
    $instance = null;
    $registry = static function () use ($dir): array {
        $out = [];
        foreach (glob($dir . '/instances/*.json') ?: [] as $f) {
            $i = json_decode((string) @file_get_contents($f), true);
            if (is_array($i) && (int) ($i['at'] ?? 0) > time() - 600 && is_file(($i['root'] ?? '') . '/app/bootstrap.php')) {
                $out[] = $i;
            }
        }
        return $out;
    };
    while (true) {
        stream_set_timeout($c, 60);
        $line = fgets($c, 8192);
        if ($line === false) {
            return;
        }
        $line = rtrim($line, "\r\n");
        [$tag, $cmd, $rest] = array_pad(explode(' ', $line, 3), 3, '');
        $cmd = strtoupper($cmd);
        $email = $pw = null;
        if ($cmd === 'CAPABILITY') {
            $out('* CAPABILITY IMAP4rev1 LITERAL+ SASL-IR ID AUTH=PLAIN');
            $out("$tag OK CAPABILITY completed");
            continue;
        }
        if ($cmd === 'NOOP' || $cmd === 'ID') {
            if ($cmd === 'ID') {
                $out('* ID NIL');
            }
            $out("$tag OK $cmd completed");
            continue;
        }
        if ($cmd === 'LOGOUT') {
            $out('* BYE');
            $out("$tag OK LOGOUT completed");
            return;
        }
        if ($cmd === 'LOGIN') {
            // Two strings, quoted or as literals.
            $args = [];
            $s = $rest;
            while (count($args) < 2 && $s !== '') {
                $s = ltrim($s);
                if (preg_match('/^\{(\d+)(\+?)\}$/', $s, $m) || preg_match('/^\{(\d+)(\+?)\}/', $s, $m)) {
                    if ($m[2] === '') {
                        $out('+ Ready');
                    }
                    $args[] = (string) fread($c, min(1024, (int) $m[1]));
                    $s = rtrim((string) fgets($c, 8192), "\r\n");
                } elseif ($s !== '' && $s[0] === '"') {
                    preg_match('/^"((?:[^"\\\\]|\\\\.)*)"/', $s, $m);
                    $args[] = stripcslashes($m[1] ?? '');
                    $s = substr($s, strlen($m[0] ?? $s));
                } else {
                    [$w, $s] = array_pad(explode(' ', $s, 2), 2, '');
                    $args[] = $w;
                }
            }
            [$email, $pw] = array_pad($args, 2, '');
        } elseif ($cmd === 'AUTHENTICATE' && strtoupper(strtok($rest, ' ')) === 'PLAIN') {
            $ir = trim((string) substr($rest, 5));
            if ($ir === '') {
                $out('+ ');
                $ir = rtrim((string) fgets($c, 8192), "\r\n");
            }
            $p = explode("\0", (string) base64_decode($ir, true));
            [$email, $pw] = [$p[1] ?? '', $p[2] ?? ''];
        } elseif ($cmd === 'STARTTLS') {
            $out("$tag BAD Already secure");
            continue;
        } else {
            $out("$tag BAD Sign in first");
            continue;
        }
        // The instance that serves this address; then its own check of the password.
        $found = null;
        foreach ($registry() as $i) {
            if (in_array(hash('sha256', strtolower(trim((string) $email))), (array) ($i['users'] ?? []), true)) {
                $found = $i;
                break;
            }
        }
        $user = null;
        if ($found && ($instance === null || $instance['root'] === $found['root'])) {
            if ($instance === null) {
                $instance = $found;
                putenv('M4W_CONFIG=' . $found['config']);
                chdir($found['root']);
                // In a scope of its own: its variables must not replace these ones.
                (static function (string $file): void {
                    require $file;
                })($found['root'] . '/app/bootstrap.php');
            }
            $user = \M4W\Mail\ImapServer::authenticate((string) $email, (string) $pw, $ip);
        }
        if (!$user) {
            maild_log($dir, "$ip login refused for " . substr((string) $email, 0, 80) . ($found ? '' : ' (no instance knows it)'));
            maild_fail($dir, $ip, 'login ' . substr((string) $email, 0, 80) . ($found ? '' : ' (unknown address)'));
            sleep(random_int(2, 4)); // slow guessing down
            $out("$tag NO [AUTHENTICATIONFAILED] Invalid credentials");
            if (++$fails >= 3) {
                $out('* BYE Too many failures');
                return;
            }
            continue;
        }
        maild_succeeded($dir, $ip);
        maild_log($dir, "$ip imap login " . $user['email'] . ' (' . basename($instance['root']) . ')');
        try {
            (new \M4W\Mail\ImapServer($c, $user, $ip))->run($tag);
        } catch (\Throwable $e) {
            // The connection went away.
        }
        return;
    }
}

// ---------------------------------------------------------------- the service

$listeners = [];
$cfgSig = '';
$children = []; // pid => ip
$banCache = ['at' => 0, 'bans' => []];
$status = static function (array $extra = []) use ($dir, &$children): void {
    @file_put_contents($dir . '/maild-status.json', json_encode($extra + ['pid' => getmypid(), 'connections' => count($children), 'at' => time()]));
};
maild_log($dir, 'started (pid ' . getmypid() . ')');
$idleSince = time();
while (!$stop) {
    $cfg = maild_config($dir);
    $sig = md5(json_encode([$cfg['imap_port'], $cfg['cert'], $cfg['key']]));
    if ($sig !== $cfgSig) {
        foreach ($listeners as $l) {
            @fclose($l);
        }
        $listeners = [];
        $cfgSig = $sig;
        if (!is_readable($cfg['cert']) || !is_readable($cfg['key'])) {
            $status(['error' => 'certificate not readable: ' . $cfg['cert'], 'listening' => false]);
            maild_log($dir, 'certificate not readable: ' . $cfg['cert'] . ' / ' . $cfg['key']);
            sleep(30);
            $cfgSig = '';
            if (!glob($dir . '/instances/*.json')) {
                break;
            }
            continue;
        }
        $l = @stream_socket_server('tcp://[::]:' . $cfg['imap_port'], $en, $es) ?: @stream_socket_server('tcp://0.0.0.0:' . $cfg['imap_port'], $en, $es);
        if (!$l) {
            $status(['error' => "port {$cfg['imap_port']}: $es", 'listening' => false]);
            maild_log($dir, "cannot listen on {$cfg['imap_port']}: $es");
            sleep(30);
            $cfgSig = '';
            continue;
        }
        $listeners['imap'] = $l;
        maild_log($dir, "listening: IMAP {$cfg['imap_port']} ({$cfg['host']})");
    }
    $status(['listening' => true, 'host' => $cfg['host'], 'imap_port' => $cfg['imap_port']]);
    // No instance left (all removed or turned off): stopped after ten minutes.
    if (!glob($dir . '/instances/*.json')) {
        if (time() - $idleSince > 600) {
            break;
        }
    } else {
        $idleSince = time();
    }
    while (($pid = pcntl_waitpid(-1, $st, WNOHANG)) > 0) {
        unset($children[$pid]);
    }
    $r = array_values($listeners);
    $w = $x = null;
    if (!@stream_select($r, $w, $x, 5)) {
        continue;
    }
    foreach ($r as $l) {
        $c = @stream_socket_accept($l, 0, $peer);
        if (!$c) {
            continue;
        }
        $ip = trim(preg_replace('/\]?:\d+$/', '', (string) $peer) ?? '', '[]');
        if (time() - $banCache['at'] >= 5) {
            $banCache = ['at' => time(), 'bans' => maild_bans($dir)];
        }
        $perIp = count(array_filter($children, static fn($x) => $x === $ip));
        if ((int) ($banCache['bans'][$ip]['until'] ?? 0) > time() || $perIp >= 10 || count($children) >= 300) {
            fclose($c); // not a word
            continue;
        }
        $pid = pcntl_fork();
        if ($pid === 0) {
            foreach ($listeners as $ll) {
                fclose($ll);
            }
            pcntl_signal(SIGTERM, SIG_DFL);
            try {
                maild_serve($c, $ip, $cfg, $dir);
            } catch (\Throwable $e) {
                maild_log($dir, "$ip error: " . $e->getMessage());
            }
            @fclose($c);
            exit(0);
        }
        fclose($c);
        if ($pid > 0) {
            $children[$pid] = $ip;
        }
    }
}
foreach ($listeners as $l) {
    @fclose($l);
}
maild_log($dir, 'stopped');
@unlink($dir . '/maild-status.json');
flock($lock, LOCK_UN);
