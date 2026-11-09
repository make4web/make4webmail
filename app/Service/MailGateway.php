<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Config;
use M4W\Core\Database as DB;
use M4W\Core\Settings;

/**
 * IMAP (and SMTP) for mail apps, shared by every Make4WebMail of this server: one service
 * (bin/maild.php) on fixed ports and one name (webmail.make4web.com by default), started by the
 * scheduled task of whichever instance finds it stopped. Each instance tells, every minute, in a
 * folder common to the server, where it is and which addresses it serves (hashed): the service
 * hands a connection to the instance of the address that signs in, which serves it with its own
 * code, database and settings.
 *
 * The shared settings (name, ports, certificate) are kept by each instance too, so that a
 * restart of the server (the common folder emptied) loses nothing; a change made in one is
 * taken up by the others.
 */
final class MailGateway
{
    public const DEFAULTS = ['host' => 'webmail.make4web.com', 'imap_port' => 51993, 'smtp_port' => 51465, 'cert' => '', 'key' => ''];

    /** @var ?string test hook */
    public static ?string $dir = null;

    public static function dir(): string
    {
        $d = self::$dir ?? ((string) Config::get('maild_dir', '') ?: rtrim(sys_get_temp_dir(), '/') . '/m4w-maild');
        if (!is_dir($d . '/instances')) {
            @mkdir($d . '/instances', 0770, true);
        }
        return $d;
    }

    public static function enabled(): bool
    {
        return (int) Settings::get('maild.enabled', 0) === 1;
    }

    /** The shared settings: the newer of the common file and this instance's copy. */
    public static function config(): array
    {
        $mine = json_decode((string) Settings::get('maild.config', ''), true);
        $shared = json_decode((string) @file_get_contents(self::dir() . '/maild.json'), true);
        $c = is_array($shared) && (int) ($shared['at'] ?? 0) >= (int) ($mine['at'] ?? 0) ? $shared : (is_array($mine) ? $mine : []);
        $c += self::DEFAULTS + ['at' => 0];
        $c['host'] = mb_strtolower((string) $c['host']);
        $c['imap_port'] = (int) $c['imap_port'];
        $c['smtp_port'] = (int) $c['smtp_port'];
        // Let's Encrypt's place, when nothing else is given.
        if ($c['cert'] === '') {
            $c['cert'] = '/etc/letsencrypt/live/' . $c['host'] . '/fullchain.pem';
        }
        if ($c['key'] === '') {
            $c['key'] = '/etc/letsencrypt/live/' . $c['host'] . '/privkey.pem';
        }
        return $c;
    }

    /** Changed here: for every instance of the server. */
    public static function saveConfig(array $in): void
    {
        $c = array_intersect_key($in, self::DEFAULTS) + self::config();
        $c['at'] = time();
        Settings::set('maild.config', json_encode($c));
        self::writeShared($c);
    }

    private static function writeShared(array $c): void
    {
        $f = self::dir() . '/maild.json';
        @file_put_contents($f . '.tmp', json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($f . '.tmp', 0660);
        @rename($f . '.tmp', $f);
    }

    public static function instanceId(): string
    {
        return substr(sha1(M4W_ROOT), 0, 16);
    }

    public static function hash(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }

    /**
     * Scheduled task, every minute: this instance in the common register (or out of it), the
     * shared settings kept in step, the service started when stopped. Returns what it did.
     */
    public static function tick(bool $spawn = true): string
    {
        $file = self::dir() . '/instances/' . self::instanceId() . '.json';
        if (!self::enabled()) {
            @unlink($file);
            return 'off';
        }
        // The shared settings: the newest wins, kept here and there.
        $mine = json_decode((string) Settings::get('maild.config', ''), true) ?: [];
        $shared = json_decode((string) @file_get_contents(self::dir() . '/maild.json'), true) ?: [];
        if ((int) ($shared['at'] ?? 0) > (int) ($mine['at'] ?? 0)) {
            Settings::set('maild.config', json_encode($shared));
        } elseif ((int) ($mine['at'] ?? 0) > (int) ($shared['at'] ?? 0) || !$shared) {
            self::writeShared(($mine ?: self::DEFAULTS) + ['at' => (int) ($mine['at'] ?? 0)]);
        }
        $users = array_map(static fn($e) => self::hash((string) $e), array_column(DB::all("SELECT email FROM users WHERE status = 'active'"), 'email'));
        $entry = [
            'root' => M4W_ROOT, 'config' => getenv('M4W_CONFIG') ?: Config::path(), 'php' => PHP_BINARY,
            'name' => (string) Settings::get('brand.name', ''), 'version' => M4W_VERSION, 'users' => $users, 'at' => time(),
        ];
        @file_put_contents($file . '.tmp', json_encode($entry));
        @chmod($file . '.tmp', 0660);
        @rename($file . '.tmp', $file);
        if (!$spawn || self::running()) {
            return 'registered, running';
        }
        if (!function_exists('exec')) {
            return 'registered (exec unavailable: not started)';
        }
        exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, M4W_ROOT . '/bin/maild.php', '--dir=' . self::dir()]))
            . ' >> ' . escapeshellarg(self::dir() . '/maild.log') . ' 2>&1 < /dev/null &');
        return 'registered, started';
    }

    /** The service holds its lock. */
    public static function running(): bool
    {
        $h = @fopen(self::dir() . '/maild.lock', 'c');
        if (!$h) {
            return false;
        }
        $free = flock($h, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($h, LOCK_UN);
        }
        fclose($h);
        return !$free;
    }

    /** What the service says of itself (written every few seconds). */
    public static function status(): array
    {
        $s = json_decode((string) @file_get_contents(self::dir() . '/maild-status.json'), true) ?: [];
        $instances = 0;
        foreach (glob(self::dir() . '/instances/*.json') ?: [] as $f) {
            $i = json_decode((string) @file_get_contents($f), true);
            $instances += is_array($i) && (int) ($i['at'] ?? 0) > time() - 300 ? 1 : 0;
        }
        $c = self::config();
        $expiry = 0;
        if (is_readable($c['cert']) && ($x = @openssl_x509_parse((string) file_get_contents($c['cert'])))) {
            $expiry = (int) ($x['validTo_time_t'] ?? 0);
        }
        return $s + ['running' => self::running(), 'instances' => $instances, 'cert_readable' => is_readable($c['cert']), 'key_readable' => is_readable($c['key']), 'cert_expiry' => $expiry];
    }
}
