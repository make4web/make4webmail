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

/** TLS 1.3 with the shared name only: a scanner (by address, another name) gets nothing and is counted. */
function maild_tls($c, string $ip, array $cfg, string $dir): bool
{
    stream_set_timeout($c, 15);
    // The beginning of the TLS handshake, read without taking it.
    $hello = '';
    for ($i = 0; $i < 30 && strlen($hello) < 64; $i++) {
        $hello = (string) @stream_socket_recvfrom($c, 4096, STREAM_PEEK);
        if ($hello === '' && feof($c)) {
            return false;
        }
        if (strlen($hello) < 64) {
            usleep(100000);
        }
    }
    $sni = maild_sni($hello);
    if ($sni !== $cfg['host']) {
        // A scanner (by address, or another name): nothing to see, and counted.
        maild_fail($dir, $ip, 'probe' . ($sni !== '' ? ' ' . $sni : ''), 3);
        return false;
    }
    stream_context_set_option($c, 'ssl', 'local_cert', $cfg['cert']);
    stream_context_set_option($c, 'ssl', 'local_pk', $cfg['key']);
    stream_context_set_option($c, 'ssl', 'disable_compression', true);
    stream_context_set_option($c, 'ssl', 'verify_peer', false);
    stream_set_blocking($c, true);
    return (bool) @stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLSv1_3_SERVER);
}

/**
 * The instance that serves this address (the register), loaded in this process (once: a
 * connection belongs to one instance), then its own check of the app password.
 */
function maild_login(string $dir, string $ip, string $email, string $pw, ?array &$instance, string $proto): ?array
{
    $found = null;
    foreach (glob($dir . '/instances/*.json') ?: [] as $f) {
        $i = json_decode((string) @file_get_contents($f), true);
        if (is_array($i) && (int) ($i['at'] ?? 0) > time() - 600 && is_file(($i['root'] ?? '') . '/app/bootstrap.php')
            && in_array(hash('sha256', strtolower(trim($email))), (array) ($i['users'] ?? []), true)) {
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
        $user = \M4W\Mail\ImapServer::authenticate($email, $pw, $ip, $proto);
    }
    if (!$user) {
        maild_log($dir, "$ip $proto login refused for " . substr($email, 0, 80) . ($found ? '' : ' (no instance knows it)'));
        maild_fail($dir, $ip, $proto . ' login ' . substr($email, 0, 80));
        sleep(random_int(2, 4)); // slow guessing down
        return null;
    }
    maild_succeeded($dir, $ip);
    maild_log($dir, "$ip $proto login " . $user['email'] . ' (' . basename($instance['root']) . ')');
    return $user;
}

/** IMAP until signed in, then the instance's ImapServer. */
function maild_imap($c, string $ip, array $cfg, string $dir): void
{
    $out = static function (string $l) use ($c): void { @fwrite($c, $l . "\r\n"); };
    $out('* OK [CAPABILITY IMAP4rev1 LITERAL+ SASL-IR ID AUTH=PLAIN] Ready');
    $fails = 0;
    $instance = null;
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
        $user = maild_login($dir, $ip, (string) $email, (string) $pw, $instance, 'imap');
        if (!$user) {
            $out("$tag NO [AUTHENTICATIONFAILED] Invalid credentials");
            if (++$fails >= 3) {
                $out('* BYE Too many failures');
                return;
            }
            continue;
        }
        try {
            (new \M4W\Mail\ImapServer($c, $user, $ip))->run($tag);
        } catch (\Throwable $e) {
            // The connection went away.
        }
        return;
    }
}


/** SMTP until signed in (AUTH PLAIN or LOGIN), then the instance's SubmissionServer. */
function maild_smtp($c, string $ip, array $cfg, string $dir): void
{
    $out = static function (string $l) use ($c): void { @fwrite($c, $l . "\r\n"); };
    $read = static function () use ($c): ?string {
        stream_set_timeout($c, 60);
        $l = fgets($c, 8192);
        return $l === false ? null : rtrim($l, "\r\n");
    };
    $ehlo = static function () use ($out, $cfg): void {
        $out('250-' . $cfg['host']);
        $out('250-AUTH PLAIN LOGIN');
        $out('250-8BITMIME');
        $out('250 ENHANCEDSTATUSCODES');
    };
    $out('220 ' . $cfg['host'] . ' ESMTP ready');
    $fails = 0;
    $instance = null;
    while (($line = $read()) !== null) {
        $verb = strtoupper((string) strtok($line, ' '));
        $email = $pw = null;
        if ($verb === 'EHLO') {
            $ehlo();
            continue;
        }
        if ($verb === 'HELO') {
            $out('250 ' . $cfg['host']);
            continue;
        }
        if ($verb === 'NOOP' || $verb === 'RSET') {
            $out('250 2.0.0 OK');
            continue;
        }
        if ($verb === 'QUIT') {
            $out('221 2.0.0 Bye');
            return;
        }
        if ($verb === 'AUTH') {
            $parts = preg_split('/\s+/', trim($line));
            $mech = strtoupper($parts[1] ?? '');
            if ($mech === 'PLAIN') {
                $ir = $parts[2] ?? '';
                if ($ir === '' || $ir === '=') {
                    $out('334 ');
                    $ir = (string) $read();
                }
                $p = explode("\0", (string) base64_decode($ir, true));
                [$email, $pw] = [$p[1] ?? '', $p[2] ?? ''];
            } elseif ($mech === 'LOGIN') {
                $u = $parts[2] ?? '';
                if ($u === '') {
                    $out('334 VXNlcm5hbWU6');
                    $u = (string) $read();
                }
                $out('334 UGFzc3dvcmQ6');
                [$email, $pw] = [(string) base64_decode($u, true), (string) base64_decode((string) $read(), true)];
            } else {
                $out('504 5.5.4 Unrecognized authentication type');
                continue;
            }
            $user = maild_login($dir, $ip, (string) $email, (string) $pw, $instance, 'smtp');
            if (!$user) {
                $out('535 5.7.8 Authentication credentials invalid');
                if (++$fails >= 3) {
                    $out('421 4.7.0 Too many failures');
                    return;
                }
                continue;
            }
            $out('235 2.7.0 Authentication successful');
            try {
                (new \M4W\Mail\SubmissionServer($c, $user, $ip, $cfg['host']))->run();
            } catch (\Throwable $e) {
                // The connection went away.
            }
            return;
        }
        if ($verb === 'STARTTLS') {
            $out('503 5.5.1 Already secure');
            continue;
        }
        $out('530 5.7.0 Authentication required');
    }
}

// ---------------------------------------------------------------- known scanners

/** Addresses of known scanners and bad networks (blocklist.json, kept by the instances), as ranges. */
function maild_blocklist(string $dir): array
{
    $ranges = [];
    foreach ((array) (json_decode((string) @file_get_contents($dir . '/blocklist.json'), true)['ranges'] ?? []) as $cidr) {
        [$net, $bits] = array_pad(explode('/', (string) $cidr, 2), 2, '32');
        $n = ip2long($net);
        if ($n === false || (int) $bits < 8 || (int) $bits > 32) {
            continue;
        }
        $size = 2 ** (32 - (int) $bits);
        $start = $n - ($n % $size);
        $ranges[] = [$start, $start + $size - 1];
    }
    usort($ranges, static fn($a, $b) => $a[0] <=> $b[0]);
    return $ranges;
}

function maild_listed(array $ranges, string $ip): bool
{
    $n = ip2long($ip);
    if ($n === false) {
        return false;
    }
    [$lo, $hi] = [0, count($ranges) - 1];
    while ($lo <= $hi) {
        $mid = intdiv($lo + $hi, 2);
        if ($n < $ranges[$mid][0]) {
            $hi = $mid - 1;
        } elseif ($n > $ranges[$mid][1]) {
            // A wider range further left may still hold it.
            for ($k = $mid - 1; $k >= max(0, $mid - 64); $k--) {
                if ($n >= $ranges[$k][0] && $n <= $ranges[$k][1]) {
                    return true;
                }
            }
            $lo = $mid + 1;
        } else {
            return true;
        }
    }
    return false;
}

/** Scanning services by their reverse name (Shodan, Censys…): banned at once. */
function maild_scanner_name(string $ip): bool
{
    $name = (string) @gethostbyaddr($ip);
    return $name !== $ip && (bool) preg_match('/(censys|shodan|binaryedge|internet-measurement|shadowserver|stretchoid|onyphe|criminalip|leakix|rapid7|alphastrike|recyber|netsystemsresearch|internettl|ipip\.net|bufferover|security\.ipip)/i', $name);
}

// ---------------------------------------------------------------- the service

$listeners = [];
$cfgSig = '';
$children = []; // pid => ip
$banCache = ['at' => 0, 'bans' => []];
$blocked = [];
$blockAt = 0;
$status = static function (array $extra = []) use ($dir, &$children): void {
    @file_put_contents($dir . '/maild-status.json', json_encode($extra + ['pid' => getmypid(), 'connections' => count($children), 'at' => time()]));
};
maild_log($dir, 'started (pid ' . getmypid() . ')');
$idleSince = time();
while (!$stop) {
    $cfg = maild_config($dir);
    $sig = md5(json_encode([$cfg['imap_port'], $cfg['smtp_port'], $cfg['cert'], $cfg['key']]));
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
        $error = '';
        foreach (['imap' => $cfg['imap_port'], 'smtp' => $cfg['smtp_port']] as $proto => $port) {
            $l = @stream_socket_server('tcp://[::]:' . $port, $en, $es) ?: @stream_socket_server('tcp://0.0.0.0:' . $port, $en, $es);
            if (!$l) {
                $error .= "port $port: $es. ";
                maild_log($dir, "cannot listen on $port: $es");
                continue;
            }
            $listeners[$proto] = $l;
        }
        if (!$listeners) {
            $status(['error' => trim($error), 'listening' => false]);
            sleep(30);
            $cfgSig = '';
            continue;
        }
        maild_log($dir, 'listening: ' . implode(', ', array_map(static fn($p) => strtoupper($p) . ' ' . $cfg[$p . '_port'], array_keys($listeners))) . " ({$cfg['host']})");
    }
    if (time() - $blockAt >= 60) {
        $blockAt = time();
        $blocked = maild_blocklist($dir);
    }
    $status(['listening' => true, 'host' => $cfg['host'], 'imap_port' => $cfg['imap_port'], 'smtp_port' => $cfg['smtp_port'],
        'protocols' => array_keys($listeners), 'error' => $error ?? '', 'blocklist' => count($blocked)]);
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
        $proto = (string) array_search($l, $listeners, true);
        $c = @stream_socket_accept($l, 0, $peer);
        if (!$c) {
            continue;
        }
        $ip = trim(preg_replace('/\]?:\d+$/', '', (string) $peer) ?? '', '[]');
        if (time() - $banCache['at'] >= 5) {
            $banCache = ['at' => time(), 'bans' => maild_bans($dir)];
        }
        $perIp = count(array_filter($children, static fn($x) => $x === $ip));
        if ((int) ($banCache['bans'][$ip]['until'] ?? 0) > time() || maild_listed($blocked, $ip) || $perIp >= 10 || count($children) >= 300) {
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
                if (maild_scanner_name($ip)) {
                    maild_fail($dir, $ip, 'scanner', 1);
                } elseif (maild_tls($c, $ip, $cfg, $dir)) {
                    $proto === 'smtp' ? maild_smtp($c, $ip, $cfg, $dir) : maild_imap($c, $ip, $cfg, $dir);
                }
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
