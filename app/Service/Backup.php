<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Config;
use M4W\Core\Database as DB;
use M4W\Core\Settings;
use M4W\Storage\Storage;

/**
 * Daily backup to an FTP / FTPS / SFTP server (or a mounted folder), as one zip:
 *
 *   manifest.json, LISEZMOI.txt     what it is and how to restore it
 *   database/                       the whole database (SQLite file or SQL dump)
 *   storage/                        mail store, queue, uploads, lists (everything to restore)
 *   config/config.php               holds the key that decrypts the stored secrets
 *   fichiers/utilisateurs/<email>/  the file space as people see it, readable without
 *   fichiers/espaces/<espace>/      the application (current version of each file)
 *   fichiers/archives/
 *
 * Optional AES-256 password on the zip. The N most recent backups are kept on the server.
 * Run by bin/backup.php (its own scheduled task, so a long backup never delays mail).
 */
final class Backup
{
    public const PROTOCOLS = ['sftp', 'ftps', 'ftp', 'local'];
    private const PREFIX = 'm4w-backup-';
    /** Temporary copies of stored files are flushed into the zip beyond this size. */
    private const FLUSH_BYTES = 256 * 1048576;
    private const STORED_EXT = ['zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'mp3', 'mp4', 'mov', 'avi', 'mkv',
        'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp', 'pdf', 'heic'];

    private \ZipArchive $zip;
    private string $zipPath;
    private ?string $password;
    /** @var string[] */
    private array $temps = [];
    private int $tempBytes = 0;
    private int $entries = 0;
    /** @var array<string,true> */
    private array $names = [];

    // ---------------------------------------------------------------- settings

    public static function enabled(): bool
    {
        return (int) Settings::get('backup.enabled', 0) === 1;
    }

    public static function timezone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone((string) Settings::get('backup.timezone', 'Europe/Paris'));
        } catch (\Throwable) {
            return new \DateTimeZone('Europe/Paris');
        }
    }

    /** Next scheduled start (timestamp), or 0 when off. */
    public static function nextRun(?int $now = null): int
    {
        if (!self::enabled()) {
            return 0;
        }
        $now ??= time();
        [$h, $m] = self::time();
        $days = (string) Settings::get('backup.days', '1234567');
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone(self::timezone());
        for ($i = 0; $i < 8; $i++) {
            $d = $local->modify("+$i day")->setTime($h, $m);
            if (str_contains($days, $d->format('N')) && $d->getTimestamp() > $now && !self::ranOn($d)) {
                return $d->getTimestamp();
            }
        }
        return 0;
    }

    /** @return array{int,int} */
    private static function time(): array
    {
        return preg_match('/^(\d{1,2}):(\d{2})$/', (string) Settings::get('backup.time', '03:00'), $m)
            ? [min(23, (int) $m[1]), min(59, (int) $m[2])] : [3, 0];
    }

    private static function ranOn(\DateTimeImmutable $day): bool
    {
        $last = (int) Settings::get('backup.last_scheduled', 0);
        return $last > 0 && (new \DateTimeImmutable('@' . $last))->setTimezone(self::timezone())->format('Y-m-d') === $day->format('Y-m-d');
    }

    /** Asked from the administration, or the scheduled time has come (catching up for 6 hours). */
    public static function due(?int $now = null): ?string
    {
        if ((int) Settings::get('backup.requested', 0)) {
            return 'manual';
        }
        if (!self::enabled()) {
            return null;
        }
        $now ??= time();
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone(self::timezone());
        [$h, $m] = self::time();
        $at = $local->setTime($h, $m);
        if (!str_contains((string) Settings::get('backup.days', '1234567'), $local->format('N')) || $local < $at
            || $now > $at->getTimestamp() + 6 * 3600 || self::ranOn($local)) {
            return null;
        }
        return 'scheduled';
    }

    // ---------------------------------------------------------------- remote

    /** @return array{put:\Closure,list:\Closure,delete:\Closure,rename:\Closure,close:\Closure} */
    public static function remote(?array $cfg = null): array
    {
        $cfg ??= self::config();
        return match ($cfg['protocol']) {
            'sftp' => self::sftp($cfg),
            'local' => self::localDir($cfg),
            default => self::ftp($cfg),
        };
    }

    public static function config(): array
    {
        $protocol = in_array(Settings::get('backup.protocol', 'sftp'), self::PROTOCOLS, true) ? (string) Settings::get('backup.protocol', 'sftp') : 'sftp';
        return [
            'protocol' => $protocol,
            'host' => (string) Settings::get('backup.host', ''),
            'port' => (int) Settings::get('backup.port', 0) ?: ($protocol === 'sftp' ? 22 : 21),
            'user' => (string) Settings::get('backup.user', ''),
            'password' => Settings::secret('backup.password'),
            'path' => '/' . trim((string) Settings::get('backup.path', '/'), '/'),
            'verify' => (int) Settings::get('backup.tls_verify', 0) === 1,
            'retry' => true,
        ];
    }

    /** @var ?callable fn(int $sent, int $total): progress of the upload under way (see run) */
    private static $progress = null;

    /**
     * FTP and FTPS through one connection kept for every step (logging in once, as FileZilla
     * does: servers that limit connections, or ask the data connection to reuse the TLS
     * session, refused the many short ones). Passive mode (PASV, the server's own address kept
     * when it answers with an inner one), implicit FTPS on port 990, a transfer that stalls is
     * cut after two minutes, and what the server said is given with any error.
     */
    private static function ftp(array $c): array
    {
        $implicit = $c['protocol'] === 'ftps' && (int) $c['port'] === 990;
        $base = ($implicit ? 'ftps://' : 'ftp://') . $c['host'] . ':' . $c['port'] . rtrim($c['path'], '/') . '/';
        // DELE, RNFR and RNTO are sent once in the backup folder (after the transfer step, which
        // goes there), whether the connection is new or kept from the previous step.
        $ch = curl_init();
        // Passive mode: the address the server gives for the transfers is used, except a private
        // one (a server behind a router): its own address then. Some servers give another
        // machine on purpose (Online.net dedibackup): what worked is kept for next time.
        $pasvKey = 'backup.pasv_ip.' . md5($c['host']);
        $skipIp = (string) Settings::get($pasvKey, '') === 'control';
        $curl = static function (string $url, array $opts, string $what = '', bool $retried = false) use ($c, $ch, $pasvKey, &$skipIp, &$curl): string {
            curl_reset($ch);
            $log = fopen('php://temp', 'w+');
            curl_setopt_array($ch, $opts + [
                CURLOPT_URL => $url,
                CURLOPT_USERPWD => $c['user'] . ':' . $c['password'],
                CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', // a backup disk is reached directly
                CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 0,
                CURLOPT_LOW_SPEED_LIMIT => 1, CURLOPT_LOW_SPEED_TIME => 120,
                CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR,
                CURLOPT_PROTOCOLS => CURLPROTO_FTP | CURLPROTO_FTPS,
                CURLOPT_FTP_USE_EPSV => false, CURLOPT_FTP_SKIP_PASV_IP => $skipIp,
                CURLOPT_VERBOSE => true, CURLOPT_STDERR => $log,
            ] + ($c['protocol'] === 'ftps' ? [
                CURLOPT_USE_SSL => CURLUSESSL_ALL, CURLOPT_FTPSSLAUTH => CURLFTPAUTH_TLS,
                CURLOPT_SSL_VERIFYPEER => $c['verify'], CURLOPT_SSL_VERIFYHOST => $c['verify'] ? 2 : 0,
            ] : []));
            if (isset($opts[CURLOPT_INFILE])) {
                rewind($opts[CURLOPT_INFILE]);
            }
            $out = curl_exec($ch);
            if ($out === false && !$retried && in_array(curl_errno($ch), [7, 28, 30], true)) {
                rewind($log);
                $dialog = (string) stream_get_contents($log);
                // Logged in, then the transfer could not be reached: the other passive address.
                if (preg_match('/227 Entering Passive Mode \((\d+),(\d+),(\d+),(\d+),/i', $dialog, $m)) {
                    $private = !filter_var("$m[1].$m[2].$m[3].$m[4]", FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
                    if (!$skipIp || !$private) {
                        $skipIp = !$skipIp;
                        fclose($log);
                        $res = $curl($url, $opts, $what, true);
                        Settings::set($pasvKey, $skipIp ? 'control' : 'reply');
                        return $res;
                    }
                }
            }
            if ($out === false) {
                $err = curl_error($ch);
                rewind($log);
                // The last words of the server (never the password).
                $said = array_values(array_filter(array_map('trim', explode("\n", (string) stream_get_contents($log))), static fn($l) => str_starts_with($l, '< ') && !str_contains($l, 'PASS')));
                fclose($log);
                throw new \RuntimeException(($what !== '' ? $what . ' — ' : '') . ($c['protocol'] === 'ftps' ? 'FTPS' : 'FTP') . ' : ' . $err
                    . ($said ? ' · ' . t('backup.server_said') . ' « ' . implode(' / ', array_slice($said, -3)) . ' »' : ''));
            }
            fclose($log);
            return (string) $out;
        };
        // A cut connection or a timeout is tried again (a new connection), twice, then given up.
        $again = static function (callable $fn) use ($c) {
            for ($i = 0; ; $i++) {
                try {
                    return $fn();
                } catch (\RuntimeException $e) {
                    if ($i >= 2 || empty($c['retry']) || preg_match('/\b5[0-9]{2}\b|Login|login|denied|incorrect/', $e->getMessage())) {
                        throw $e;
                    }
                    sleep($i ? 30 : 10);
                }
            }
        };
        return [
            'put' => static function (string $local, string $name) use ($curl, $base, $again): void {
                $again(static function () use ($curl, $base, $local, $name) {
                    $in = fopen($local, 'rb');
                    $size = (int) filesize($local);
                    try {
                        $curl($base . rawurlencode($name), [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $in, CURLOPT_INFILESIZE => $size,
                            CURLOPT_NOPROGRESS => self::$progress === null, CURLOPT_XFERINFOFUNCTION => static function ($h, $dt, $d, $ut, $u) use ($size) {
                                if (self::$progress) {
                                    (self::$progress)((int) $u, $size);
                                }
                                return 0;
                            }], t('backup.step_upload'));
                    } finally {
                        fclose($in);
                    }
                });
            },
            'list' => static fn(): array => $again(static fn() => array_values(array_filter(array_map(static fn($l) => basename(trim($l)), explode("\n", $curl($base, [CURLOPT_DIRLISTONLY => true], t('backup.step_list'))))))),
            'delete' => static function (string $name) use ($curl, $base): void {
                $curl($base, [CURLOPT_POSTQUOTE => ['DELE ' . $name], CURLOPT_NOBODY => true], t('backup.step_delete'));
            },
            'rename' => static function (string $from, string $to) use ($curl, $base, $again): void {
                $again(static fn() => $curl($base, [CURLOPT_POSTQUOTE => ['RNFR ' . $from, 'RNTO ' . $to], CURLOPT_NOBODY => true], t('backup.step_rename')));
            },
            'close' => static fn() => curl_close($ch),
        ];
    }

    private static function sftp(array $c): array
    {
        $s = new \phpseclib3\Net\SFTP($c['host'], $c['port'], 20);
        if (!$s->login($c['user'], $c['password'])) {
            throw new \RuntimeException('SFTP : ' . t('backup.login_failed'));
        }
        $dir = rtrim($c['path'], '/') ?: '/';
        if (!$s->is_dir($dir) && !$s->mkdir($dir, -1, true)) {
            throw new \RuntimeException('SFTP : ' . t('backup.mkdir_failed', ['dir' => $dir]));
        }
        $p = static fn(string $name) => rtrim($dir, '/') . '/' . $name;
        $check = static function (bool $ok, string $what) use ($s): void {
            if (!$ok) {
                throw new \RuntimeException('SFTP : ' . $what . ' (' . ($s->getLastSFTPError() ?: 'erreur') . ')');
            }
        };
        return [
            'put' => static fn(string $local, string $name) => $check($s->put($p($name), $local, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE), 'put ' . $name),
            'list' => static fn(): array => array_values(array_filter((array) $s->nlist($dir), static fn($n) => $n !== '.' && $n !== '..')),
            'delete' => static fn(string $name) => $check($s->delete($p($name), false), 'delete ' . $name),
            'rename' => static fn(string $from, string $to) => $check($s->rename($p($from), $p($to)), 'rename ' . $from),
            'close' => static fn() => $s->disconnect(),
        ];
    }

    /** A local folder under the web root would publish the backups. */
    public static function unsafeLocalPath(string $dir): bool
    {
        $public = realpath(M4W_ROOT . '/public') ?: M4W_ROOT . '/public';
        $real = realpath($dir) ?: $dir;
        return str_starts_with(rtrim($real, '/') . '/', rtrim($public, '/') . '/');
    }

    private static function localDir(array $c): array
    {
        $dir = rtrim($c['path'], '/');
        if (self::unsafeLocalPath($dir)) {
            throw new \RuntimeException(t('backup.public_path'));
        }
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            throw new \RuntimeException(t('backup.mkdir_failed', ['dir' => $dir]));
        }
        $check = static function (bool $ok, string $what): void {
            if (!$ok) {
                throw new \RuntimeException($what);
            }
        };
        return [
            'put' => static fn(string $local, string $name) => $check(@copy($local, $dir . '/' . $name), 'copy ' . $name),
            'list' => static fn(): array => array_values(array_diff(scandir($dir) ?: [], ['.', '..'])),
            'delete' => static fn(string $name) => $check(@unlink($dir . '/' . $name), 'delete ' . $name),
            'rename' => static fn(string $from, string $to) => $check(@rename($dir . '/' . $from, $dir . '/' . $to), 'rename ' . $from),
            'close' => static fn() => null,
        ];
    }

    /** Connect, write a small file, list it, delete it. Returns '' or the error. */
    public static function test(?array $cfg = null): string
    {
        try {
            // The test answers at once: no second try.
            $r = self::remote(['retry' => false] + ($cfg ?? self::config()));
            $tmp = (string) tempnam(sys_get_temp_dir(), 'm4wt');
            file_put_contents($tmp, 'Make4WebMail backup test ' . date('c'));
            $name = 'm4w-test-' . bin2hex(random_bytes(4)) . '.txt';
            try {
                $r['put']($tmp, $name);
            } finally {
                @unlink($tmp);
            }
            $found = in_array($name, $r['list'](), true);
            $r['delete']($name);
            $r['close']();
            return $found ? '' : t('backup.test_not_listed');
        } catch (\Throwable $e) {
            return mb_substr($e->getMessage(), 0, 300);
        }
    }

    /** Backups present on the server, newest first. @return string[] */
    public static function remoteList(array $r): array
    {
        $names = array_values(array_filter($r['list'](), static fn($n) => str_starts_with($n, self::PREFIX) && str_ends_with($n, '.zip')));
        rsort($names);
        return $names;
    }

    // ---------------------------------------------------------------- run

    /**
     * Build the zip, send it, keep the N most recent. One run at a time.
     * @return array{status:string,message:string,size:int,file:string}
     */
    public static function run(string $trigger = 'manual'): array
    {
        $lockFile = Config::storagePath('tmp/backup.lock');
        @mkdir(dirname($lockFile), 0750, true);
        $lock = fopen($lockFile, 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['status' => 'busy', 'message' => t('backup.busy'), 'size' => 0, 'file' => ''];
        }
        Settings::set('backup.requested', 0);
        if ($trigger === 'scheduled') {
            Settings::set('backup.last_scheduled', time());
        }
        @set_time_limit(0);
        $started = time();
        $runId = DB::insert('backup_runs', ['started_at' => $started, 'status' => 'running', 'origin' => $trigger, 'file' => '', 'message' => '', 'size' => 0, 'entries' => 0, 'finished_at' => 0]);
        $name = self::PREFIX . (new \DateTimeImmutable('@' . $started))->setTimezone(self::timezone())->format('Y-m-d-His') . '-' . $runId . '.zip';
        $local = Config::storagePath('tmp/' . $name);
        $res = ['status' => 'ok', 'message' => '', 'size' => 0, 'file' => $name];
        try {
            // Each step said in the list of backups, which the page refreshes while it runs.
            $step = static fn(string $m) => DB::update('backup_runs', ['message' => mb_substr($m, 0, 500)], 'id = :id', ['id' => $runId]);
            self::checkSpace();
            $step(t('backup.step_build'));
            $b = new self();
            $entries = $b->build($local, Settings::secret('backup.zip_password') ?: null, $started);
            $res['size'] = (int) filesize($local);
            DB::update('backup_runs', ['size' => $res['size'], 'entries' => $entries], 'id = :id', ['id' => $runId]);
            $step(t('backup.step_connect', ['host' => (string) self::config()['host']]));
            $r = self::remote();
            $said = 0;
            self::$progress = static function (int $sent, int $total) use ($step, &$said): void {
                if (time() - $said >= 2 && $total > 0) {
                    $said = time();
                    $step(t('backup.step_sending', ['pct' => (int) floor($sent * 100 / $total), 'sent' => format_bytes($sent), 'total' => format_bytes($total)]));
                }
            };
            // Sent under a temporary name, renamed once complete: a cut transfer never looks like a backup.
            $r['put']($local, $name . '.part');
            self::$progress = null;
            $r['rename']($name . '.part', $name);
            $step(t('backup.step_rotate'));
            $keep = max(1, (int) Settings::get('backup.keep', 7));
            foreach (array_slice(self::remoteList($r), $keep) as $old) {
                $r['delete']($old);
            }
            $r['close']();
            $res['message'] = t('backup.done', ['n' => $entries]);
            DB::update('backup_runs', ['entries' => $entries], 'id = :id', ['id' => $runId]);
        } catch (\Throwable $e) {
            self::$progress = null;
            $res['status'] = 'failed';
            $res['message'] = mb_substr($e->getMessage(), 0, 500);
            error_log('[m4w] backup: ' . $e->getMessage());
        } finally {
            @unlink($local);
            DB::update('backup_runs', ['status' => $res['status'], 'message' => $res['message'], 'size' => $res['size'], 'file' => $res['file'], 'finished_at' => time()], 'id = :id', ['id' => $runId]);
            DB::run('DELETE FROM backup_runs WHERE started_at < :t', ['t' => time() - 180 * 86400]);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        if ($res['status'] === 'failed') {
            self::alertAdmins($res['message']);
        }
        \M4W\Core\Audit::log('backup.' . $res['status'], $name, ['trigger' => $trigger, 'size' => $res['size']]);
        return $res;
    }

    /** Rough size of a backup (before compression). */
    public static function estimate(): int
    {
        $n = (int) (DB::value('SELECT SUM(size) FROM fs_files WHERE deleted_at = 0') ?? 0);
        $n += self::dirSize(Config::storagePath(), self::excluded());
        if (DB::driver() === 'sqlite') {
            $n += (int) @filesize((string) (Config::get('db')['path'] ?? ''));
        } else {
            $n += (int) (DB::value('SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = DATABASE()') ?? 0);
        }
        return $n;
    }

    private static function checkSpace(): void
    {
        $need = (int) (self::estimate() * 1.05) + self::FLUSH_BYTES;
        $free = (int) @disk_free_space(Config::storagePath('tmp'));
        if ($free > 0 && $free < $need) {
            throw new \RuntimeException(t('backup.no_space', ['need' => format_bytes($need), 'free' => format_bytes($free)]));
        }
    }

    private static function excluded(): array
    {
        return ['tmp', 'sessions', 'logs'];
    }

    private static function dirSize(string $dir, array $skip = []): int
    {
        $n = 0;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..' || in_array($f, $skip, true)) {
                continue;
            }
            $p = $dir . '/' . $f;
            $n += is_dir($p) && !is_link($p) ? self::dirSize($p) : (int) @filesize($p);
        }
        return $n;
    }

    private static function alertAdmins(string $why): void
    {
        foreach (DB::all("SELECT id FROM users WHERE role IN ('admin', 'superadmin') AND status = 'active'") as $row) {
            try {
                $u = Users::find((int) $row['id']);
                $domain = \M4W\Mail\Address::domain($u['email']) ?: 'localhost';
                $b = new \M4W\Mail\MimeBuilder();
                $b->from = ['email' => 'no-reply@' . $domain, 'name' => (string) Settings::get('brand.name', 'Make4WebMail')];
                $b->to = [['email' => $u['email'], 'name' => $u['name']]];
                $b->subject = t('backup.alert_subject');
                $b->messageId = \M4W\Mail\MimeBuilder::newMessageId($domain);
                $b->extraHeaders['Auto-Submitted'] = 'auto-generated';
                $b->html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#1f2937"><p>' . e(t('backup.alert_body')) . '</p>'
                    . '<pre style="background:#fef2f2;color:#991b1b;padding:10px;border-radius:6px;white-space:pre-wrap">' . e($why) . '</pre>'
                    . '<p><a href="' . e(public_url('admin/files#backup')) . '">' . e(t('backup.alert_link')) . '</a></p></div>';
                Mailbox::store((int) $u['id'], (int) Folders::byRole((int) $u['id'], 'inbox')['id'], str_replace("\r\n", "\n", $b->build()));
            } catch (\Throwable $e) {
                error_log('[m4w] backup alert: ' . $e->getMessage());
            }
        }
    }

    // ---------------------------------------------------------------- zip

    /** @return int number of entries */
    public function build(string $zipPath, ?string $password, int $at): int
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZIP extension missing');
        }
        if ($password !== null && !(defined('ZipArchive::EM_AES_256') && \ZipArchive::isEncryptionMethodSupported(\ZipArchive::EM_AES_256))) {
            throw new \RuntimeException(t('backup.no_aes'));
        }
        $this->zipPath = $zipPath;
        $this->password = $password;
        $this->zip = new \ZipArchive();
        if ($this->zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create ' . $zipPath);
        }
        $driver = DB::driver();
        $this->addString('manifest.json', (string) json_encode([
            'application' => 'Make4WebMail', 'version' => M4W_VERSION, 'created_at' => date('c', $at), 'database' => $driver,
            'host' => gethostname() ?: '', 'files_storage' => (string) Config::get('files.storage', 'database'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->addString('LISEZMOI.txt', t('backup.readme', ['date' => date('d/m/Y H:i', $at)]));
        $this->addDatabase($driver);
        $this->addTree(Config::storagePath(), 'storage', self::excluded());
        $filesPath = (string) Config::get('files.path', '');
        if ((string) Config::get('files.storage', 'database') === 'local' && $filesPath !== '' && !str_starts_with(realpath($filesPath) ?: $filesPath, realpath(Config::storagePath()) ?: '-')) {
            $this->addTree($filesPath, 'storage-files');
        }
        if (is_file(Config::path())) {
            $this->addFile(Config::path(), 'config/config.php');
        }
        $this->addFileSpace();
        $this->flush(true);
        return $this->entries;
    }

    private function unique(string $name): string
    {
        $n = $name;
        $i = 2;
        while (isset($this->names[mb_strtolower($n)])) {
            $dot = strrpos($name, '.');
            $slash = strrpos($name, '/');
            $n = $dot !== false && $dot > (int) $slash ? substr($name, 0, $dot) . " ($i)" . substr($name, $dot) : $name . " ($i)";
            $i++;
        }
        $this->names[mb_strtolower($n)] = true;
        return $n;
    }

    private function protect(string $name, bool $compressed): void
    {
        $this->entries++;
        if ($compressed) {
            $this->zip->setCompressionName($name, \ZipArchive::CM_STORE);
        }
        if ($this->password !== null) {
            $this->zip->setEncryptionName($name, \ZipArchive::EM_AES_256, $this->password);
        }
    }

    private static function isCompressed(string $name): bool
    {
        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::STORED_EXT, true);
    }

    private function addString(string $name, string $content): void
    {
        $name = $this->unique($name);
        $this->zip->addFromString($name, $content);
        $this->protect($name, false);
    }

    private function addFile(string $path, string $name, bool $temporary = false): void
    {
        $name = $this->unique($name);
        if (!$this->zip->addFile($path, $name)) {
            throw new \RuntimeException('zip: ' . $name);
        }
        $this->protect($name, self::isCompressed($name));
        if ($temporary) {
            $this->temps[] = $path;
            $this->tempBytes += (int) filesize($path);
            $this->flush();
        }
    }

    /** ZipArchive reads the files at close(): close and reopen now and then to free the temporary copies. */
    private function flush(bool $final = false): void
    {
        if (!$final && $this->tempBytes < self::FLUSH_BYTES) {
            return;
        }
        if (!$this->zip->close()) {
            throw new \RuntimeException('zip: ' . $this->zip->getStatusString());
        }
        foreach ($this->temps as $t) {
            @unlink($t);
        }
        $this->temps = [];
        $this->tempBytes = 0;
        if (!$final && $this->zip->open($this->zipPath) !== true) {
            throw new \RuntimeException('zip: reopen');
        }
    }

    private function tempPath(): string
    {
        $dir = Config::storagePath('tmp/backup-work');
        @mkdir($dir, 0750, true);
        return (string) tempnam($dir, 'b');
    }

    private function addTree(string $dir, string $prefix, array $skip = []): void
    {
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..' || in_array($f, $skip, true) || is_link($dir . '/' . $f)) {
                continue;
            }
            $p = $dir . '/' . $f;
            if (is_dir($p)) {
                $this->addTree($p, $prefix . '/' . $f);
            } elseif (is_readable($p)) {
                $this->addFile($p, $prefix . '/' . $f);
            }
        }
    }

    private function addDatabase(string $driver): void
    {
        if ($driver === 'sqlite') {
            // A consistent copy while the application keeps running.
            $copy = $this->tempPath();
            @unlink($copy);
            DB::pdo()->exec('VACUUM INTO ' . DB::pdo()->quote($copy));
            $this->addFile($copy, 'database/m4w.sqlite', true);
            return;
        }
        $sql = $this->tempPath();
        $out = fopen($sql, 'wb');
        $pdo = DB::pdo();
        fwrite($out, "-- Make4WebMail database dump " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
        foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(\PDO::FETCH_NUM)[1];
            fwrite($out, "DROP TABLE IF EXISTS `$table`;\n" . $create . ";\n");
            $st = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`', \PDO::FETCH_NUM);
            $batch = [];
            while (($row = $st->fetch()) !== false) {
                $batch[] = '(' . implode(',', array_map(static fn($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : (preg_match('//u', (string) $v) ? $pdo->quote((string) $v) : '0x' . bin2hex((string) $v))), $row)) . ')';
                if (count($batch) >= 200) {
                    fwrite($out, "INSERT INTO `$table` VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                fwrite($out, "INSERT INTO `$table` VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            fwrite($out, "\n");
        }
        fwrite($out, "SET FOREIGN_KEY_CHECKS = 1;\n");
        fclose($out);
        $this->addFile($sql, 'database/m4w.sql', true);
    }

    private static function clean(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1f\/\\\\]+/u', '_', $name) ?? '_');
        return $name === '' || $name === '.' || $name === '..' ? '_' : mb_substr($name, 0, 200);
    }

    /** The file space as people see it: one folder per person, per space, and the archives. */
    private function addFileSpace(): void
    {
        foreach (DB::all("SELECT f.*, u.email FROM fs_folders f LEFT JOIN users u ON u.id = f.owner_id WHERE f.parent_id IS NULL AND f.deleted_at = 0 ORDER BY f.id") as $root) {
            $base = match ($root['kind']) {
                'personal' => 'fichiers/utilisateurs/' . self::clean((string) ($root['email'] ?: 'utilisateur-' . $root['owner_id'])),
                'space' => 'fichiers/espaces/' . self::clean((string) $root['name']),
                'archive' => 'fichiers/archives',
                default => 'fichiers/autres/' . self::clean((string) $root['name']),
            };
            $this->addFolder((int) $root['id'], $base);
        }
    }

    private function addFolder(int $folderId, string $path, int $depth = 0): void
    {
        if ($depth > 50) {
            return;
        }
        $store = Storage::store();
        foreach (DB::all('SELECT id, name, blob_id FROM fs_files WHERE folder_id = :f AND deleted_at = 0 ORDER BY name', ['f' => $folderId]) as $f) {
            $tmp = $this->tempPath();
            $out = fopen($tmp, 'wb');
            $store->read((string) $f['blob_id'], static function (string $buf) use ($out) {
                fwrite($out, $buf);
            });
            fclose($out);
            $this->addFile($tmp, $path . '/' . self::clean((string) $f['name']), true);
        }
        foreach (DB::all('SELECT id, name FROM fs_folders WHERE parent_id = :p AND deleted_at = 0 ORDER BY name', ['p' => $folderId]) as $sub) {
            $this->addFolder((int) $sub['id'], $path . '/' . self::clean((string) $sub['name']), $depth + 1);
        }
    }

    /** Last runs for the administration. */
    /** For the page while a backup runs: the latest runs, and whether one was asked for. */
    public static function status(): array
    {
        $runs = [];
        foreach (self::history(10) as $h) {
            $d = (int) ($h['finished_at'] ?: time()) - (int) $h['started_at'];
            $runs[] = [
                'id' => (int) $h['id'], 'status' => (string) $h['status'], 'status_label' => t('backup.s_' . $h['status']),
                'date' => format_datetime((int) $h['started_at']), 'origin' => t('backup.o_' . $h['origin']), 'file' => (string) $h['file'],
                'size' => $h['size'] ? format_bytes((int) $h['size']) : '—', 'message' => (string) $h['message'],
                'duration' => gmdate($d >= 3600 ? 'G\h i\m\i\n' : 'i\m\i\n s\s', max(0, $d)), 'running' => $h['status'] === 'running',
            ];
        }
        return ['runs' => $runs, 'requested' => (int) Settings::get('backup.requested', 0) === 1];
    }

    public static function history(int $limit = 10): array
    {
        return DB::all('SELECT * FROM backup_runs ORDER BY id DESC LIMIT ' . max(1, $limit));
    }
}
