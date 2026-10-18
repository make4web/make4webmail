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
    exit(0);
}
$lock = fopen(Config::storagePath('tmp') . '/watch.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // already watching
}

/** @var array<int, array{client:\M4W\Mail\ImapClient, since:int}> $watch */
$watch = [];
/** @var array<int, int> $retryAt mailbox => when to try again after a failure */
$retryAt = [];
$refreshed = 0;
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
    }
    if (!$watch) {
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
