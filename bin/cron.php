#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Scheduled maintenance — run every minute:
 *   * * * * * php /path/to/bin/cron.php
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

use M4W\Core\Database as DB;
use M4W\Core\Settings;

$lock = fopen(M4W\Core\Config::storagePath('tmp/cron.lock'), 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
$out = static function (string $m) {
    if (in_array('-v', $GLOBALS['argv'], true)) {
        echo $m, "\n";
    }
};
M4W\Database\Migrator::migrate();
$out('scheduled: ' . M4W\Service\Composer::processScheduled(100));
$out('queue: ' . M4W\Service\Transport::processQueue(200));
if ((int) Settings::get('features.fetch_accounts', 1)) {
    $out('fetched: ' . M4W\Service\Fetcher::runAll());
}
$now = time();
$hourly = (int) Settings::get('system.cron_hourly', 0) < $now - 3600;
if ($hourly) {
    $out('purged: ' . M4W\Service\Mailbox::autoPurge(30));
    $out('files purged: ' . M4W\Service\Files::autoPurge(max(1, (int) Settings::get('files.trash_days', 30))));
    DB::run('DELETE FROM login_attempts WHERE created_at < :t', ['t' => $now - 7 * 86400]);
    DB::run('DELETE FROM user_sessions WHERE (revoked = 1 OR last_seen_at < :t)', ['t' => $now - 30 * 86400]);
    DB::run('DELETE FROM vacation_log WHERE sent_at < :t', ['t' => $now - 60 * 86400]);
    DB::run("DELETE FROM mail_queue WHERE status = 'sent' AND sent_at < :t", ['t' => $now - 7 * 86400]);
    DB::run('DELETE FROM mail_log WHERE created_at < :t', ['t' => $now - 90 * 86400]);
    DB::run('DELETE FROM audit_log WHERE created_at < :t', ['t' => $now - 365 * 86400]);
    // Orphan uploads older than 2 days that no draft references.
    foreach (DB::all('SELECT * FROM uploads WHERE created_at < :t', ['t' => $now - 2 * 86400]) as $up) {
        $used = DB::value('SELECT COUNT(*) FROM messages WHERE user_id = :u AND is_draft = 1 AND draft_meta LIKE :p', ['u' => $up['user_id'], 'p' => '%' . $up['token'] . '%']);
        if (!$used) {
            @unlink(M4W\Core\Config::storagePath($up['path']));
            DB::delete('uploads', 'id = :id', ['id' => $up['id']]);
        }
    }
    foreach (DB::all('SELECT id FROM users') as $u) {
        M4W\Service\Users::recalcUsage((int) $u['id']);
    }
    Settings::set('system.cron_hourly', $now);
}
Settings::set('system.cron_last', $now);
flock($lock, LOCK_UN);
