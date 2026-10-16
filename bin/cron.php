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
use M4W\Service\Jobs;

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

// Each task on its own: an error is logged and the next ones run; a task that stops the whole
// run is noticed here a minute later and, after a few times, set aside (see Jobs).
if (($stopped = Jobs::recover()) !== '') {
    $out('previous run stopped during: ' . $stopped);
}
$job = static function (string $name, callable $fn) use ($out): void {
    $r = Jobs::run($name, $fn);
    $out($name . ': ' . (is_array($r) ? implode(', ', $r) : (string) ($r ?? '—')));
};
// Full-text search index: built here, outside web requests (may take a while the first time).
$job('search index', static fn() => M4W\Service\Search::ready() ? 'ready' : (M4W\Service\Search::ensureIndex() ? 'built' : 'unavailable'));
// The queue first: what the provider sync or the rules add is taken in the same run.
$job('queue', static fn() => M4W\Service\Transport::processQueue(500, 20));
$job('provider sync', static fn() => M4W\Service\RemoteMailbox::syncAll());
$job('mail pictures', static fn() => M4W\Service\MailAssets::processPending(200));
$job('spreadsheets saved', static fn() => M4W\Service\Sheet::housekeeping());
$job('documents saved', static fn() => M4W\Service\DocEdit::housekeeping());
$job('calendar reminders', static fn() => M4W\Service\Calendar::processAlarms());
$job('scheduled', static fn() => M4W\Service\Composer::processScheduled(100));
if ((int) Settings::get('features.fetch_accounts', 1)) {
    $job('fetched', static fn() => M4W\Service\Fetcher::runAll());
}
// What the tasks above added (synced mail passed on by a rule, scheduled mail…): sent now.
$job('queue', static fn() => M4W\Service\Transport::processQueue(500, 20));
$now = time();
$hourly = (int) Settings::get('system.cron_hourly', 0) < $now - 3600;
if ($hourly) {
    $job('purged', static fn() => M4W\Service\Mailbox::autoPurge(30));
    $job('retention expired', static fn() => M4W\Service\Retention::expire());
    $job('collector purge', static fn() => M4W\Service\Collector::purge());
    $job('mail pictures gc', static fn() => M4W\Service\MailAssets::gc());
    $job('account guard gc', static fn() => M4W\Service\AccountGuard::gc());
    $job('dmarc reports gc', static fn() => M4W\Service\DmarcReports::gc());
    $job('health notices', static fn() => M4W\Service\Health::run());
    $job('files expiry', static function () {
        [$rem, $arc] = M4W\Service\Files::processExpiry();
        return $rem . ' reminder(s), ' . $arc . ' archived';
    });
    $job('files purged', static fn() => M4W\Service\Files::autoPurge(max(1, (int) Settings::get('files.trash_days', 30))));
    $job('old records', static function () use ($now) {
        DB::run('DELETE FROM login_attempts WHERE created_at < :t', ['t' => $now - 7 * 86400]);
        DB::run('DELETE FROM user_sessions WHERE (revoked = 1 OR last_seen_at < :t)', ['t' => $now - 30 * 86400]);
        DB::run('DELETE FROM vacation_log WHERE sent_at < :t', ['t' => $now - 60 * 86400]);
        DB::run("DELETE FROM mail_queue WHERE status = 'sent' AND sent_at < :t", ['t' => $now - 7 * 86400]);
        DB::run('DELETE FROM mail_log WHERE created_at < :t', ['t' => $now - 90 * 86400]);
        DB::run('DELETE FROM audit_log WHERE created_at < :t', ['t' => $now - 365 * 86400]);
        return 'done';
    });
    // Orphan uploads older than 2 days that no draft references.
    $job('orphan uploads', static function () use ($now) {
        $n = 0;
        foreach (DB::all('SELECT * FROM uploads WHERE created_at < :t', ['t' => $now - 2 * 86400]) as $up) {
            $used = DB::value('SELECT COUNT(*) FROM messages WHERE user_id = :u AND is_draft = 1 AND draft_meta LIKE :p', ['u' => $up['user_id'], 'p' => '%' . $up['token'] . '%']);
            if (!$used) {
                @unlink(M4W\Core\Config::storagePath($up['path']));
                DB::delete('uploads', 'id = :id', ['id' => $up['id']]);
                $n++;
            }
        }
        return $n;
    });
    $job('usage', static function () {
        foreach (DB::all('SELECT id FROM users') as $u) {
            M4W\Service\Users::recalcUsage((int) $u['id']);
        }
        return 'recalculated';
    });
    if ((int) Settings::get('security.disposable_autoupdate', 1) && (int) Settings::get('security.disposable_updated', 0) < $now - 7 * 86400
        && (int) Settings::get('security.disposable_tried', 0) < $now - 86400) {
        Settings::set('security.disposable_tried', $now);
        $job('disposable domains', static fn() => M4W\Service\Senders::update());
    }
    $job('link guard gc', static fn() => M4W\Service\LinkGuard::gc());
    if ((int) Settings::get('security.urlhaus', 1) && (int) Settings::get('security.urlhaus_updated', 0) < $now - 86400
        && (int) Settings::get('security.urlhaus_tried', 0) < $now - 6 * 3600) {
        Settings::set('security.urlhaus_tried', $now);
        $job('URLhaus feed', static fn() => M4W\Service\LinkGuard::updateFeed());
    }
    $job('expired sessions', static fn() => M4W\Core\DbSessionHandler::purge());
    $job('unused file contents', static fn() => M4W\Service\Files::gcBlobs());
    Settings::set('system.cron_hourly', $now);
}
Settings::set('system.cron_last', $now);
if (defined('M4W_STARTED_AS_ROOT')) {
    Settings::set('system.cron_root', $now);
}
flock($lock, LOCK_UN);
