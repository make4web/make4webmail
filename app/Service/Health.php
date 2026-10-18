<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Config;
use M4W\Core\Database as DB;
use M4W\Core\Settings;
use M4W\Mail\Address;
use M4W\Mail\MimeBuilder;

/**
 * Supervision: what can silently stop a mail server, checked every hour by cron and on the
 * administration page. A check that turns bad is reported to the administrators (in their
 * inbox and, because the mail itself may be what is broken, to an outside address if set);
 * a reminder follows every day until it is fixed, and a note when it is.
 */
final class Health
{
    /** @var ?callable test hook: fn(string $type, string $name): array */
    public static $resolver = null;
    /** @var ?callable test hook: fn(string $host): ?int expiry timestamp */
    public static $certProbe = null;

    /**
     * @return array<string,array{state:string,label:string,detail:string,link:string}>
     *   state: ok | warn | fail | off
     */
    public static function checks(bool $network = true): array
    {
        $c = [];
        $now = time();
        $add = static function (string $key, string $state, string $detail = '', string $link = '') use (&$c): void {
            $c[$key] = ['state' => $state, 'label' => t('health.c.' . explode(':', $key)[0], ['domain' => explode(':', $key)[1] ?? '']), 'detail' => $detail, 'link' => $link];
        };

        // Scheduled task.
        $cron = (int) Settings::get('system.cron_last', 0);
        $age = $now - $cron;
        $add('cron', $cron === 0 ? 'fail' : ($age > 1800 ? 'fail' : ($age > 300 ? 'warn' : 'ok')),
            $cron ? t('health.cron_last', ['ago' => self::ago($age)]) : t('health.cron_never'), 'help#a-ops');

        // New mail at the providers known at once (bin/watch.php, IMAP IDLE): only when there are
        // mailboxes to watch; without it, the scheduled passes still fetch it, later.
        if (\M4W\Service\RemoteMailbox::watched()) {
            $wAge = $now - (int) Settings::get('watch.last', 0);
            $add('watch', $wAge < 180 ? 'ok' : 'warn', $wAge < 180 ? t('health.watch_ok', ['n' => (int) Settings::get('watch.count', 0)])
                : t('health.watch_off', ['interval' => \M4W\Service\RemoteMailbox::interval()]) . ' ' . self::watchWhy($now), 'help#a-ops');
        }

        // Connections to the providers within their limits (per second, per minute, per mailbox,
        // all instances of this server together): refusals seen, pauses, peaks. The report written
        // by the scheduled task or the watcher (the web server may not share their temporary
        // folder), else read now.
        if ($hosts = \M4W\Service\RemoteMailbox::imapHosts()) {
            $saved = json_decode((string) Settings::get('imap.report', ''), true);
            $report = is_array($saved) && (int) ($saved['at'] ?? 0) > $now - 600 ? (array) $saved['hosts'] : ImapLimits::report($hosts);
            foreach ($hosts as $h => $n) {
                $r = ($report[$h] ?? null) ?: ImapLimits::report([$h => $n])[$h];
                [$state, $detail] = ImapLimits::judge($h, $r);
                $add('imap_limits:' . $h, $state, $detail, 'help#a-limits');
            }
        }

        // Files the web server cannot read (written by a command run as root): empty messages.
        $unreadable = 0;
        foreach (DB::all('SELECT storage_path FROM messages ORDER BY id DESC LIMIT 300') as $r) {
            $f = Config::storagePath((string) $r['storage_path']);
            if (is_file($f) && !is_readable($f)) {
                $unreadable++;
            }
        }
        $asRoot = (int) Settings::get('system.cron_root', 0) > $now - 86400;
        $who = function_exists('posix_geteuid') && posix_geteuid() !== 0 ? (posix_getpwuid(posix_geteuid())['name'] ?? 'www-data') : 'www-data';
        if ($unreadable || $asRoot) {
            $add('files', $unreadable ? 'fail' : 'warn', trim(($unreadable ? t('health.files_unreadable', ['n' => $unreadable, 'user' => $who, 'dir' => Config::storagePath()]) : '')
                . ($asRoot ? ' ' . t('health.cron_root', ['user' => $who]) : '')), 'help#a-ops');
        } else {
            $add('files', 'ok', t('health.files_ok'));
        }

        // Outgoing queue.
        $pending = (int) DB::value("SELECT COUNT(*) FROM mail_queue WHERE status = 'pending'");
        $oldest = (int) DB::value("SELECT MIN(created_at) FROM mail_queue WHERE status = 'pending' AND last_error NOT IN ('rate-limit', 'rate-limit-own')");
        $failed = (int) DB::value("SELECT COUNT(*) FROM mail_queue WHERE status = 'failed' AND created_at > :t", ['t' => $now - 86400]);
        $held = (int) DB::value("SELECT COUNT(*) FROM mail_queue WHERE status = 'held'");
        $qAge = $oldest ? $now - $oldest : 0;
        $parkedMail = (int) DB::value("SELECT COUNT(*) FROM mail_queue WHERE status = 'parked'");
        $add('queue', $qAge > 7200 ? 'fail' : ($qAge > 1800 || $failed > 10 || $parkedMail ? 'warn' : 'ok'),
            t('health.queue', ['n' => $pending, 'failed' => $failed, 'held' => $held]) . ($qAge > 1800 ? ' — ' . t('health.queue_oldest', ['ago' => self::ago($qAge)]) : '')
            . ($parkedMail ? ' — ' . t('health.queue_parked', ['n' => $parkedMail]) : ''), 'admin/logs');

        // Tasks of the scheduled run: set aside after stopping it, or failing.
        $parkedJobs = \M4W\Service\Jobs::parked($now);
        $failing = array_diff_key(\M4W\Service\Jobs::failing($now), $parkedJobs);
        if ($parkedJobs) {
            $add('jobs', 'fail', t('health.jobs_parked', ['tasks' => implode(', ', array_keys($parkedJobs))]), 'help#a-ops');
        } elseif ($failing) {
            $add('jobs', 'warn', t('health.jobs_failing', ['tasks' => implode(', ', array_map(static fn($k, $e) => $k . ' (' . $e['last_error'] . ')', array_keys($failing), $failing))]), 'help#a-ops');
        } else {
            $add('jobs', 'ok', t('health.jobs_ok'));
        }

        // Disk.
        $path = Config::storagePath('');
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);
        if ($free !== false && $total) {
            $pct = $free / $total * 100;
            $add('disk', $free < 500 * 1048576 || $pct < 3 ? 'fail' : ($free < 2 * 1073741824 || $pct < 10 ? 'warn' : 'ok'),
                t('health.disk', ['free' => format_bytes($free), 'pct' => (string) round($pct)]));
        }

        // Backups.
        if (Backup::enabled()) {
            $last = DB::one("SELECT * FROM backup_runs WHERE status = 'ok' ORDER BY finished_at DESC LIMIT 1");
            $lastFail = DB::one("SELECT * FROM backup_runs WHERE status = 'failed' ORDER BY finished_at DESC LIMIT 1");
            $since = $last ? $now - (int) $last['finished_at'] : PHP_INT_MAX;
            $add('backup', $since > 2 * 86400 ? 'fail' : ($since > 26 * 3600 ? 'warn' : 'ok'),
                $last ? t('health.backup_last', ['ago' => self::ago($since), 'size' => format_bytes((int) $last['size'])]) . ($lastFail && (int) $lastFail['finished_at'] > (int) $last['finished_at'] ? ' — ' . $lastFail['message'] : '') : t('health.backup_never'),
                'admin/files#backup');
        } else {
            $add('backup', 'warn', t('health.backup_off'), 'admin/files#backup');
        }

        // Antivirus.
        if (Antivirus::enabled()) {
            $up = Antivirus::ping();
            $add('antivirus', $up ? 'ok' : 'fail', $up ? Antivirus::version() : t('health.av_down'), 'admin/security#antivirus');
        }

        // Provider accounts (webmail / collector modes, imposed mailboxes).
        $errors = (int) DB::value("SELECT COUNT(*) FROM remote_accounts r JOIN users u ON u.id = r.user_id WHERE u.status = 'active' AND r.enabled = 1 AND r.last_error <> ''");
        if ($errors || RemoteMailbox::mode() !== 'server') {
            $add('remote', $errors ? 'warn' : 'ok', $errors ? t('health.remote_errors', ['n' => $errors]) : t('health.remote_ok'), 'admin/mail');
        }

        // Security alerts waiting.
        $open = (int) DB::value("SELECT COUNT(*) FROM security_alerts WHERE resolved_at = 0 AND severity = 'high'");
        $add('alerts', $open ? 'warn' : 'ok', $open ? t('health.alerts_open', ['n' => $open]) : t('health.alerts_none'), 'admin/security#guard');

        if ($network) {
            // HTTPS certificate of the public address.
            $url = public_url('/');
            $host = (string) parse_url($url, PHP_URL_HOST);
            if (str_starts_with($url, 'https://') && $host !== '' && !in_array($host, ['localhost', '127.0.0.1'], true)) {
                $exp = self::certExpiry($host);
                if ($exp === null) {
                    $add('cert', 'warn', t('health.cert_unknown', ['host' => $host]));
                } else {
                    $days = (int) floor(($exp - $now) / 86400);
                    $add('cert', $days < 7 ? 'fail' : ($days < 21 ? 'warn' : 'ok'), t('health.cert_days', ['host' => $host, 'n' => $days, 'date' => format_datetime($exp, false)]));
                }
            }
            // DNS of each domain: MX, SPF, DKIM, DMARC.
            foreach (self::dns() as $key => $r) {
                $add($key, $r[0], $r[1], 'admin/mail#dkim');
            }
        }
        return $c;
    }

    /** @return array<string,array{0:string,1:string}> */
    /** Why the live watch does not run, as far as can be seen from here. */
    private static function watchWhy(int $now): string
    {
        $spawned = (int) Settings::get('watch.spawned', 0);
        $started = (int) Settings::get('watch.started', 0);
        $err = json_decode((string) Settings::get('watch.error', ''), true);
        $log = Config::storagePath('logs') . '/watch.log';
        if ($spawned === -1) {
            return t('health.watch_no_exec');
        }
        if (is_array($err) && (int) ($err['at'] ?? 0) > $now - 3600 && (int) $err['at'] >= $started) {
            return t('health.watch_error', ['why' => (string) $err['why'], 'log' => $log]);
        }
        if ($spawned < $now - 300) {
            return t('health.watch_not_spawned');
        }
        if ($started < $spawned - 120) {
            return t('health.watch_not_started', ['log' => $log]);
        }
        return t('health.watch_stopped', ['log' => $log]);
    }

    private static function dns(): array
    {
        $out = [];
        $mode = RemoteMailbox::mode();
        $selector = (string) Settings::get('smtp.dkim_selector', '');
        $dkimDomain = (string) Settings::get('smtp.dkim_domain', '');
        $pub = '';
        $priv = Settings::secret('smtp.dkim_private');
        if ($priv !== '' && ($k = @openssl_pkey_get_private($priv))) {
            $pub = preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', openssl_pkey_get_details($k)['key'] ?? '') ?? '';
        }
        foreach (array_slice(array_column(DB::all('SELECT name FROM domains WHERE active = 1 ORDER BY name'), 'name'), 0, 10) as $d) {
            $txt = self::lookup('TXT', $d);
            $mx = self::lookup('MX', $d);
            $out['mx:' . $d] = $mx ? ['ok', implode(', ', array_slice($mx, 0, 3))] : ['fail', t('health.mx_none')];
            $spf = array_values(array_filter($txt, static fn($t) => stripos($t, 'v=spf1') === 0));
            $out['spf:' . $d] = match (true) {
                !$spf => ['fail', t('health.spf_none')],
                count($spf) > 1 => ['fail', t('health.spf_many')],
                (bool) preg_match('/[+?]all\b/i', $spf[0]) || !preg_match('/all\b/i', $spf[0]) => ['warn', t('health.spf_weak') . ' — ' . $spf[0]],
                default => ['ok', $spf[0]],
            };
            $dmarc = array_values(array_filter(self::lookup('TXT', '_dmarc.' . $d), static fn($t) => stripos($t, 'v=DMARC1') === 0));
            $p = $dmarc && preg_match('/\bp=(\w+)/i', $dmarc[0], $m) ? strtolower($m[1]) : '';
            $out['dmarc:' . $d] = match (true) {
                !$dmarc => ['fail', t('health.dmarc_none')],
                $p === 'none' => ['warn', t('health.dmarc_none_policy') . ' — ' . $dmarc[0]],
                default => ['ok', $dmarc[0]],
            };
            // DKIM: our own key (server and relay modes); the provider signs in the other modes.
            if (!in_array($mode, ['webmail', 'collector'], true) && $d === $dkimDomain) {
                if ($selector === '' || $pub === '') {
                    $out['dkim:' . $d] = ['fail', t('health.dkim_nokey')];
                } else {
                    $rec = implode('', self::lookup('TXT', $selector . '._domainkey.' . $d));
                    $out['dkim:' . $d] = $rec === '' ? ['fail', t('health.dkim_missing', ['name' => $selector . '._domainkey.' . $d])]
                        : (str_contains(preg_replace('/\s+/', '', $rec) ?? '', $pub) ? ['ok', t('health.dkim_ok', ['selector' => $selector])] : ['fail', t('health.dkim_mismatch')]);
                }
            }
            // BIMI (optional): the logo other mail apps may show next to our messages.
            try {
                $out['bimi:' . $d] = Bimi::check($d);
            } catch (\Throwable $e) {
                $out['bimi:' . $d] = ['warn', mb_substr($e->getMessage(), 0, 200)];
            }
        }
        return $out;
    }

    /** @return string[] */
    private static function lookup(string $type, string $name): array
    {
        if (self::$resolver) {
            return (array) (self::$resolver)($type, $name);
        }
        $cacheKey = 'health.dns.' . md5($type . $name);
        $cached = json_decode((string) Settings::get($cacheKey, ''), true);
        if (is_array($cached) && ($cached['t'] ?? 0) > time() - 1800) {
            return $cached['v'];
        }
        $out = [];
        $const = ['TXT' => DNS_TXT, 'MX' => DNS_MX][$type];
        foreach (@dns_get_record($name, $const) ?: [] as $r) {
            $out[] = $type === 'TXT' ? (isset($r['entries']) ? implode('', $r['entries']) : (string) ($r['txt'] ?? '')) : (string) ($r['target'] ?? '');
        }
        Settings::set($cacheKey, json_encode(['t' => time(), 'v' => $out]));
        return $out;
    }

    private static function certExpiry(string $host): ?int
    {
        if (self::$certProbe) {
            return (self::$certProbe)($host);
        }
        $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $s = @stream_socket_client('ssl://' . $host . ':443', $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) {
            return null;
        }
        $cert = stream_context_get_params($s)['options']['ssl']['peer_certificate'] ?? null;
        fclose($s);
        $info = $cert ? openssl_x509_parse($cert) : null;
        return $info ? (int) $info['validTo_time_t'] : null;
    }

    private static function ago(int $s): string
    {
        return match (true) {
            $s < 120 => t('health.ago_s', ['n' => $s]),
            $s < 7200 => t('health.ago_m', ['n' => intdiv($s, 60)]),
            $s < 172800 => t('health.ago_h', ['n' => intdiv($s, 3600)]),
            default => t('health.ago_d', ['n' => intdiv($s, 86400)]),
        };
    }

    /** Worst state among the checks. */
    public static function overall(array $checks): string
    {
        $states = array_column($checks, 'state');
        return in_array('fail', $states, true) ? 'fail' : (in_array('warn', $states, true) ? 'warn' : 'ok');
    }

    /**
     * Hourly from cron: compare with the last run and tell the administrators what changed.
     * Returns the number of notices sent.
     */
    public static function run(?int $now = null): int
    {
        $now ??= time();
        $checks = self::checks(true);
        $before = json_decode((string) Settings::get('health.state', '{}'), true) ?: [];
        $state = [];
        $bad = [];
        $fixed = [];
        foreach ($checks as $key => $c) {
            $prev = $before[$key] ?? ['state' => 'ok', 'since' => $now, 'told' => 0];
            $since = $prev['state'] === $c['state'] ? (int) $prev['since'] : $now;
            $told = $prev['state'] === $c['state'] ? (int) $prev['told'] : 0;
            if ($c['state'] === 'fail' || ($c['state'] === 'warn' && in_array(explode(':', $key)[0], ['cert', 'backup', 'disk', 'queue', 'cron', 'files', 'jobs'], true))) {
                // New problem, or still there a day later.
                if ($told === 0 || $now - $told >= 86400) {
                    $bad[] = $c;
                    $told = $now;
                }
            } elseif ($c['state'] === 'ok' && in_array($prev['state'], ['fail', 'warn'], true) && (int) $prev['told'] > 0) {
                $fixed[] = $c;
            }
            $state[$key] = ['state' => $c['state'], 'since' => $since, 'told' => $told];
        }
        Settings::set('health.state', json_encode($state));
        Settings::set('health.last_run', $now);
        if (!$bad && !$fixed) {
            return 0;
        }
        $lines = array_merge(
            array_map(static fn($c) => '✗ ' . $c['label'] . ' — ' . $c['detail'], $bad),
            array_map(static fn($c) => '✓ ' . $c['label'] . ' — ' . t('health.fixed'), $fixed)
        );
        return self::notify($bad ? t('health.mail_subject_bad', ['n' => count($bad)]) : t('health.mail_subject_fixed'), implode("\n", $lines));
    }

    /** To the administrators' inboxes, and to the outside address (mail may be what is broken). */
    private static function notify(string $subject, string $text): int
    {
        $n = 0;
        foreach (DB::all("SELECT id FROM users WHERE role IN ('admin', 'superadmin') AND status = 'active'") as $a) {
            $admin = Users::find((int) $a['id']);
            if ($admin) {
                AccountGuard::notifyUser($admin, $subject, $text, 'admin/health');
                $n++;
            }
        }
        $ext = trim((string) Settings::get('health.alert_email', ''));
        if ($ext !== '' && is_valid_email($ext)) {
            try {
                $domain = (string) (DB::value('SELECT name FROM domains WHERE active = 1 ORDER BY id LIMIT 1') ?: 'localhost');
                $b = new MimeBuilder();
                $b->from = ['email' => 'no-reply@' . $domain, 'name' => (string) Settings::get('brand.name', 'Make4WebMail')];
                $b->to = [['email' => $ext, 'name' => '']];
                $b->subject = $subject;
                $b->messageId = MimeBuilder::newMessageId($domain);
                $b->extraHeaders['Auto-Submitted'] = 'auto-generated';
                $b->text = $text . "\n\n" . public_url('admin/health');
                Transport::send($b->build(), 'no-reply@' . $domain, [$ext]);
                $n++;
            } catch (\Throwable $e) {
                error_log('[m4w] health alert: ' . $e->getMessage());
            }
        }
        return $n;
    }
}
