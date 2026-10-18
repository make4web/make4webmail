#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * New mail at the providers, known at once: one connection per watched mailbox (own mailboxes,
 * the collector, webmail mode), waiting with IMAP IDLE. When the provider tells of new mail, the
 * inbox is fetched right away (and the phones notified), instead of at the next scheduled pass.
 *
 *   php bin/watch.php              watch 58 s then stop (a crontab line every minute; --for=N)
 *   php bin/watch.php --forever    keep watching (systemd service, see deploy/m4w-watch.service)
 *
 * One watcher at a time (lock); the scheduled task still does the full passes (folders, flags,
 * deletions) and remains the fallback for mailboxes whose provider does not offer IDLE.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

use M4W\Core\Config;
use M4W\Core\Settings;
use M4W\Mail\ImapLimitException;
use M4W\Service\ImapLimits;
use M4W\Service\RemoteMailbox;

$forever = in_array('--forever', $argv, true);
$verbose = in_array('-v', $argv, true);
$for = 58;
foreach ($argv as $a) {
    if (preg_match('/^--for=(\d+)$/', $a, $m)) {
        $for = max(1, (int) $m[1]);
    }
}
$deadline = $forever ? PHP_INT_MAX : time() + $for;
$say = static function (string $m) use ($verbose): void {
    if ($verbose) {
        echo date('H:i:s') . ' ' . $m . "\n";
    }
};

if (!Config::installed()) {
    $say('not installed (' . Config::path() . ')');
    exit(0);
}
// Why it stopped, for the supervision page (a fatal error, the lock it cannot open).
$problem = static function (string $why): void {
    try {
        Settings::set('watch.error', json_encode(['at' => time(), 'why' => mb_substr($why, 0, 300)]));
    } catch (Throwable) {
    }
    fwrite(STDERR, date('c') . ' watch: ' . $why . "\n");
};
register_shutdown_function(static function () use ($problem): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $problem($e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ')');
    }
});
$lockFile = Config::storagePath('tmp') . '/watch.lock';
$lock = @fopen($lockFile, 'c');
if (!$lock) {
    $problem('cannot open ' . $lockFile . ' (owner: ' . (function_exists('posix_getpwuid') && is_file($lockFile) ? (posix_getpwuid((int) fileowner($lockFile))['name'] ?? '?') : '?') . ')');
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    // Another watch holds the lock. If it still reports (every half-minute), it does the work;
    // if it has not for minutes, it is stuck (a connection that never answers…) and keeps the
    // others from starting: it is stopped and this one takes over.
    $pid = (int) trim((string) @file_get_contents($lockFile));
    $quiet = time() - (int) Settings::get('watch.last', 0);
    if ($quiet < 180 || $pid <= 0 || !function_exists('posix_kill') || !posix_kill($pid, 0)) {
        $say($pid > 0 ? "already watching (process $pid, last report {$quiet} s ago)" : 'already watching (an older version: stop it with  pkill -f bin/watch.php)');
        exit(0);
    }
    $problem("process $pid held the watch without reporting for $quiet s: stopped and replaced");
    posix_kill($pid, SIGTERM);
    for ($i = 0; $i < 50 && !flock($lock, LOCK_EX | LOCK_NB); $i++) {
        usleep(100000);
        if ($i === 30) {
            posix_kill($pid, SIGKILL);
        }
    }
    if ($i >= 50) {
        $say("process $pid does not let go of the lock");
        exit(1);
    }
}
Settings::set('watch.started', time());
ftruncate($lock, 0);
fwrite($lock, (string) getmypid()); // who is watching (to stop it: kill that number)
fflush($lock);

/** @var array<int, array{client:\M4W\Mail\ImapClient, since:int}> $watch */
$watch = [];
/** @var array<int, int> $retryAt mailbox => when to try again after a failure */
$retryAt = [];
$refreshed = 0;
$accounts = [];
$toldEmpty = false;
$beat = 0;

$close = static function (int $id) use (&$watch): void {
    if (isset($watch[$id])) {
        try {
            $watch[$id]['client']->idleDone();
        } catch (Throwable) {
        }
        $watch[$id]['client']->logout();
        unset($watch[$id]);
    }
};
$open = static function (array $acc) use (&$watch, &$retryAt, $say): void {
    $id = (int) $acc['id'];
    try {
        $c = RemoteMailbox::watchClient($acc);
        if (!$c->supportsIdle()) {
            $c->logout();
            $retryAt[$id] = time() + 3600; // the scheduled passes only
            $say("#$id: no IDLE");
            return;
        }
        $c->idleStart();
        $watch[$id] = ['client' => $c, 'since' => time()];
        $say("#$id: watching");
    } catch (ImapLimitException $e) {
        // The provider limits this server (or as many connections as allowed are open): the
        // scheduled passes keep fetching, the watch resumes after the pause.
        $retryAt[$id] = max(time() + 60, $e->until + random_int(0, 30));
        $say("#$id: {$e->kind}: " . $e->getMessage());
    } catch (Throwable $e) {
        $retryAt[$id] = time() + 60;
        $say("#$id: " . $e->getMessage());
    }
};

while (time() < $deadline) {
    // The mailboxes to watch (added, removed, changed) every two minutes.
    if (time() - $refreshed >= 120) {
        $refreshed = time();
        $accounts = [];
        foreach (RemoteMailbox::watched() as $acc) {
            $accounts[(int) $acc['id']] = $acc;
        }
        foreach (array_keys($watch) as $id) {
            if (!isset($accounts[$id])) {
                $close($id);
            }
        }
        foreach ($accounts as $id => $acc) {
            if (!isset($watch[$id]) && ($retryAt[$id] ?? 0) <= time()) {
                $open($acc);
            }
        }
    }
    if (time() - $beat >= 30) {
        $beat = time();
        Settings::set('watch.last', time()); // shown by the supervision page
        Settings::set('watch.count', count($watch));
        // The shared ledger seen from here (the web server may not see the same temporary
        // folder): for the supervision page.
        ImapLimits::heartbeat();
        Settings::set('imap.report', json_encode(['at' => time(), 'hosts' => ImapLimits::report(RemoteMailbox::imapHosts())]));
    }
    if (!$watch) {
        if (!$accounts && !$toldEmpty) {
            $toldEmpty = true;
            $say('no mailbox to watch (none at a provider, or no password saved)');
        }
        sleep(min(5, max(1, $deadline - time())));
        continue;
    }
    $read = [];
    foreach ($watch as $id => $w) {
        $read[$id] = $w['client']->stream();
    }
    $write = $except = null;
    $n = @stream_select($read, $write, $except, 5);
    if ($n === false) {
        continue;
    }
    foreach ($read as $id => $_) {
        $c = $watch[$id]['client'];
        try {
            // Every line already there (several may come at once, kept in the TLS buffer that
            // stream_select does not see): flags changed, keep-alive… are for the full pass.
            $new = false;
            do {
                $new = $new || (bool) preg_match('/^\* \d+ (EXISTS|RECENT)\b/i', $c->idleLine());
            } while ((stream_get_meta_data($c->stream())['unread_bytes'] ?? 0) > 0);
            if (!$new) {
                continue;
            }
            $c->idleDone();
            $n = RemoteMailbox::wake($id);
            $say("#$id: new mail, $n fetched");
            $c->idleStart();
        } catch (Throwable $e) {
            $say("#$id: " . $e->getMessage());
            $close($id);
            $retryAt[$id] = time() + 30;
        }
    }
    // Servers drop an idle connection after 30 min: renewed every 20.
    foreach ($watch as $id => $w) {
        if (time() - $w['since'] > 1200) {
            try {
                $w['client']->idleDone();
                $w['client']->idleStart();
                $watch[$id]['since'] = time();
            } catch (Throwable) {
                $close($id);
                $retryAt[$id] = time();
            }
        }
    }
    $retryAt = array_filter($retryAt, static fn($t) => $t > time() - 3600);
}
foreach (array_keys($watch) as $id) {
    $close($id);
}
flock($lock, LOCK_UN);
