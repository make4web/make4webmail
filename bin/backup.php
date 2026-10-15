#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Backups (Administration → Files → Backup).
 *   php bin/backup.php --if-due   scheduled task, every minute: runs when the time has come
 *                                 or when an administrator asked for one
 *   php bin/backup.php --now      run one now
 *   php bin/backup.php --test     check the connection to the backup server
 *   php bin/backup.php --list     backups present on the server
 * Restoring: php bin/restore.php (see README).
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

use M4W\Core\Settings;
use M4W\Service\Backup;

$arg = $argv[1] ?? '';
switch ($arg) {
    case '--if-due':
        Settings::set('backup.cron_seen', time());
        $why = Backup::due();
        if ($why === null) {
            exit(0);
        }
        $r = Backup::run($why);
        echo date('c') . ' backup ' . $r['status'] . ' ' . $r['file'] . ' ' . $r['message'] . "\n";
        exit($r['status'] === 'failed' ? 1 : 0);
    case '--now':
        $r = Backup::run('cli');
        echo $r['status'] . ' ' . $r['file'] . ' (' . format_bytes($r['size']) . ') ' . $r['message'] . "\n";
        exit($r['status'] === 'ok' ? 0 : 1);
    case '--test':
        $e = Backup::test();
        echo $e === '' ? "OK\n" : "FAILED: $e\n";
        exit($e === '' ? 0 : 1);
    case '--list':
        $r = Backup::remote();
        foreach (Backup::remoteList($r) as $n) {
            echo $n, "\n";
        }
        $r['close']();
        exit(0);
    default:
        fwrite(STDERR, "usage: backup.php --if-due | --now | --test | --list\n");
        exit(64);
}
