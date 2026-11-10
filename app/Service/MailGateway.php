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
        $d = self::$dir ?? ((string) Config::get('maild_dir', '') ?: shared_dir('m4w-maild'));
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
            'root' => M4W_ROOT, 'config' => getenv('M4W_CONFIG') ?: Config::path(), 'php' => php_cli(),
            'name' => (string) Settings::get('brand.name', ''), 'version' => M4W_VERSION, 'users' => $users, 'at' => time(),
        ];
        @file_put_contents($file . '.tmp', json_encode($entry));
        @chmod($file . '.tmp', 0660);
        @rename($file . '.tmp', $file);
        // A service of an older version, in the old common folder (/tmp): it gives way.
        $old = rtrim(sys_get_temp_dir(), '/') . '/m4w-maild';
        if ($old !== self::dir() && is_file($old . '/maild.lock') && function_exists('posix_kill')) {
            $pid = (int) trim((string) @file_get_contents($old . '/maild.lock'));
            if ($pid > 0 && @posix_kill($pid, 0)) {
                @posix_kill($pid, SIGTERM);
            }
            @unlink($old . '/instances/' . self::instanceId() . '.json');
        }
        // A service started from older code (another instance not yet updated, or this one before
        // its update): it gives way to this version.
        $st = json_decode((string) @file_get_contents(self::dir() . '/maild-status.json'), true) ?: [];
        if ($spawn && self::running() && !empty($st['pid']) && function_exists('posix_kill')
            && version_compare((string) ($st['version'] ?? '0'), M4W_VERSION, '<')) {
            @posix_kill((int) $st['pid'], SIGTERM);
            for ($i = 0; $i < 40 && self::running(); $i++) {
                usleep(250000);
            }
        }
        if (!$spawn || self::running()) {
            return 'registered, running';
        }
        if (!function_exists('exec')) {
            return 'registered (exec unavailable: not started)';
        }
        exec(implode(' ', array_map('escapeshellarg', [php_cli(), M4W_ROOT . '/bin/maild.php', '--dir=' . self::dir()]))
            . ' >> ' . escapeshellarg(self::dir() . '/maild.log') . ' 2>&1 < /dev/null &');
        return 'registered, started';
    }

    /** Lists of bad networks: FireHOL level 1 (Spamhaus DROP, DShield…) and blocklist.de (brute force). */
    public const BLOCKLISTS = ['https://iplists.firehol.org/files/firehol_level1.netset', 'https://lists.blocklist.de/lists/all.txt'];

    /**
     * The addresses of known scanners and attackers, for the service (every 12 hours, by
     * whichever instance comes first: the file is shared). Private and reserved ranges never
     * (FireHOL level 1 lists them as "bogons": they would shut out the server's own network).
     * @param ?callable $fetch test hook: fn(string $url): string
     */
    public static function refreshBlocklist(?callable $fetch = null, bool $force = false): int
    {
        $file = self::dir() . '/blocklist.json';
        $cur = json_decode((string) @file_get_contents($file), true) ?: [];
        if (!$force && (int) ($cur['at'] ?? 0) > time() - 12 * 3600) {
            return count((array) ($cur['ranges'] ?? []));
        }
        $fetch ??= static function (string $url): string {
            $ch = curl_init($url);
            $proxy = trim((string) Config::get('outbound_proxy', ''));
            curl_setopt_array($ch, ($proxy !== '' ? [CURLOPT_PROXY => $proxy] : []) + [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if (!is_string($body) || $code !== 200) {
                throw new \RuntimeException('HTTP ' . $code);
            }
            return $body;
        };
        $ranges = [];
        $ranges6 = [];
        $errors = [];
        foreach (self::BLOCKLISTS as $url) {
            try {
                foreach (preg_split('/\R/', $fetch($url)) ?: [] as $l) {
                    $l = trim(explode('#', $l)[0]);
                    [$net, $bits] = array_pad(explode('/', $l, 2), 2, '32');
                    if (filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) && !str_contains($net, '.')) {
                        // IPv6: by the /64 of the address (what one machine is given).
                        $ranges6[inet_ntop(substr((string) inet_pton($net), 0, 8) . str_repeat("\0", 8)) . '/64'] = true;
                        continue;
                    }
                    if (!filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !ctype_digit($bits) || (int) $bits < 8 || (int) $bits > 32) {
                        continue;
                    }
                    if (!filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) || (int) $bits < 12) {
                        continue;
                    }
                    $ranges[$net . '/' . (int) $bits] = true;
                }
            } catch (\Throwable $e) {
                $errors[] = parse_url($url, PHP_URL_HOST) . ': ' . $e->getMessage();
            }
        }
        // Nothing fetched: the old list is kept.
        if (!$ranges) {
            throw new \RuntimeException('no list fetched (' . implode('; ', $errors) . ')');
        }
        @file_put_contents($file . '.tmp', json_encode(['at' => time(), 'ranges' => array_keys($ranges), 'ranges6' => array_keys($ranges6), 'errors' => $errors]));
        @rename($file . '.tmp', $file);
        return count($ranges);
    }

    /** The mail domains of this instance (its domains, and those of its people's addresses). */
    public static function mailDomains(): array
    {
        $d = array_column(DB::all('SELECT name FROM domains WHERE active = 1'), 'name');
        foreach (DB::all("SELECT email FROM users WHERE status = 'active'") as $u) {
            $d[] = substr(strrchr((string) $u['email'], '@') ?: '', 1);
        }
        return array_values(array_unique(array_filter(array_map('mb_strtolower', $d))));
    }

    /**
     * What the DNS must hold for mail apps to find everything from an address alone: the
     * service's name, and for each mail domain the service records (Exchange Autodiscover,
     * IMAP and SMTP submission, RFC 6186). Each with its state: ok, missing, wrong.
     * Through Cloudflare's API when a token is there (exact, at once), else the DNS.
     */
    public static function dnsChecks(): array
    {
        $c = self::config();
        $want = [['type' => 'A', 'name' => $c['host'], 'value' => '']];
        foreach (self::mailDomains() as $d) {
            foreach (['_autodiscover._tcp' => $c['imap_port'], '_imaps._tcp' => $c['imap_port'], '_submissions._tcp' => $c['smtp_port']] as $svc => $port) {
                $want[] = ['type' => 'SRV', 'name' => $svc . '.' . $d, 'value' => $port . ' ' . $c['host'], 'port' => (int) $port];
            }
        }
        $api = Cloudflare::token() !== '';
        $zones = [];
        foreach ($want as &$w) {
            $w['state'] = 'missing';
            $w['found'] = '';
            try {
                if ($api) {
                    $zoneName = '';
                    foreach ($zones as $zn => $zid) {
                        if (str_ends_with($w['name'], '.' . $zn) || $w['name'] === $zn) {
                            $zoneName = $zn;
                        }
                    }
                    if ($zoneName === '') {
                        $z = Cloudflare::zoneFor($w['name']);
                        $zones[$z['name']] = $z['id'];
                        $zoneName = $z['name'];
                    }
                    $w['zone'] = $zones[$zoneName];
                    $recs = $w['type'] === 'A' ? array_merge(Cloudflare::records($zones[$zoneName], 'A', $w['name']), Cloudflare::records($zones[$zoneName], 'AAAA', $w['name'])) : Cloudflare::records($zones[$zoneName], 'SRV', $w['name']);
                    foreach ($recs as $r) {
                        $found = $w['type'] === 'A' ? (string) $r['content'] . (!empty($r['proxied']) ? ' (proxied)' : '') : ((int) ($r['data']['port'] ?? 0)) . ' ' . rtrim((string) ($r['data']['target'] ?? ''), '.');
                        $w['found'] = $found;
                        $w['state'] = $w['type'] === 'A' ? (empty($r['proxied']) ? 'ok' : 'wrong') : (strcasecmp($found, $w['value']) === 0 ? 'ok' : 'wrong');
                    }
                } else {
                    $recs = @dns_get_record($w['name'], $w['type'] === 'A' ? DNS_A : DNS_SRV) ?: [];
                    foreach ($recs as $r) {
                        $found = $w['type'] === 'A' ? (string) $r['ip'] : ((int) $r['port']) . ' ' . rtrim((string) $r['target'], '.');
                        $w['found'] = $found;
                        $w['state'] = $w['type'] === 'A' || strcasecmp($found, $w['value']) === 0 ? 'ok' : 'wrong';
                    }
                }
            } catch (\Throwable $e) {
                $w['state'] = 'unreachable';
                $w['found'] = $e->getMessage();
            }
        }
        unset($w);
        return $want;
    }

    /** "Install / fix": the service records missing or wrong put right through Cloudflare. */
    public static function fixDns(): array
    {
        $c = self::config();
        $done = [];
        foreach (self::dnsChecks() as $w) {
            if ($w['state'] === 'ok' || $w['type'] !== 'SRV' || empty($w['zone'])) {
                continue;
            }
            Cloudflare::upsertSrv((string) $w['zone'], $w['name'], $c['host'], (int) $w['port']);
            $done[] = $w['name'];
        }
        return $done;
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

    /** @return array[] the instances of this server (registered in the last ten minutes): name, root, version, this one */
    public static function instances(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/instances/*.json') ?: [] as $f) {
            $i = json_decode((string) @file_get_contents($f), true);
            if (is_array($i) && (int) ($i['at'] ?? 0) > time() - 600) {
                $out[] = ['name' => (string) ($i['name'] ?? '') ?: basename((string) ($i['root'] ?? '')), 'root' => (string) ($i['root'] ?? ''),
                    'version' => (string) ($i['version'] ?? '?'), 'users' => count((array) ($i['users'] ?? [])), 'me' => ($i['root'] ?? '') === M4W_ROOT];
            }
        }
        usort($out, static fn($a, $b) => version_compare($b['version'], $a['version']));
        return $out;
    }

    /** @return array<string,array> the addresses banned now: ip => [until, level, why] */
    public static function bans(): array
    {
        $bans = json_decode((string) @file_get_contents(self::dir() . '/bans.json'), true) ?: [];
        $out = array_filter($bans, static fn($x) => (int) ($x['until'] ?? 0) > time());
        uasort($out, static fn($a, $b) => (int) $b['until'] <=> (int) $a['until']);
        return $out;
    }

    /** A ban lifted (the admin's own address, a household behind one box…): forgotten, level included. */
    public static function unban(string $ip): bool
    {
        $h = @fopen(self::dir() . '/bans.json', 'c+');
        if (!$h) {
            return false;
        }
        flock($h, LOCK_EX);
        $b = json_decode((string) stream_get_contents($h), true) ?: [];
        $found = isset($b[$ip]);
        unset($b[$ip]);
        ftruncate($h, 0);
        rewind($h);
        fwrite($h, json_encode($b));
        flock($h, LOCK_UN);
        fclose($h);
        // The failures of app passwords from there, too (they lock the address out on their own).
        $plain = (string) preg_replace('/^::ffff:/', '', $ip);
        DB::run("DELETE FROM login_attempts WHERE ip IN (:a, :b) AND email LIKE 'dav:%' AND success = 0", ['a' => $plain, 'b' => '::ffff:' . $plain]);
        return $found;
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
        $s['blocklist_at'] = (int) (json_decode((string) @file_get_contents(self::dir() . '/blocklist.json'), true)['at'] ?? 0);
        $bans = json_decode((string) @file_get_contents(self::dir() . '/bans.json'), true) ?: [];
        $s['banned'] = count(array_filter($bans, static fn($x) => (int) ($x['until'] ?? 0) > time()));
        return $s + ['running' => self::running(), 'instances' => $instances, 'cert_readable' => is_readable($c['cert']), 'key_readable' => is_readable($c['key']), 'cert_expiry' => $expiry];
    }
}
