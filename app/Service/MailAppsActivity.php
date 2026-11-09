<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Database as DB;

/**
 * What the mail apps service saw of this webmail's people (IMAP, SMTP, Exchange ActiveSync):
 * refused app passwords, connections accepted, the phones and what they synchronise. For one
 * person (Settings › Phone and calendar) or everyone (Administration › Mail server).
 */
final class MailAppsActivity
{
    public static function plainIp(string $ip): string
    {
        return (string) preg_replace('/^::ffff:/', '', $ip);
    }

    /**
     * @return array{events: array[], fails: int, last_fail: int, logins: array<string,int>, devices: array[], ips: string[]}
     */
    public static function collect(?array $user, int $since, int $limit = 15): array
    {
        $uid = $user ? (int) $user['id'] : null;
        $events = [];
        $ips = [];
        $names = [];
        $nameOf = static function (?int $id) use (&$names): string {
            if (!$id) {
                return '';
            }
            if (!isset($names[$id])) {
                $u = Users::find($id);
                $names[$id] = $u ? (string) $u['email'] : '#' . $id;
            }
            return $names[$id];
        };
        // Refused app passwords (one's own, or anyone's: the address tried).
        $fails = DB::all('SELECT ip, email, created_at FROM login_attempts WHERE ' . ($user ? 'email = :e AND ' : "email LIKE 'dav:%' AND ")
            . 'success = 0 AND created_at > :t ORDER BY id DESC LIMIT 200', ($user ? ['e' => 'dav:' . mb_strtolower((string) $user['email'])] : []) + ['t' => $since]);
        foreach ($fails as $i => $r) {
            $ips[] = (string) $r['ip'];
            if ($i < $limit * 2) {
                $events[] = ['at' => (int) $r['created_at'], 'kind' => 'fail', 'who' => substr((string) $r['email'], 4), 'ip' => self::plainIp((string) $r['ip']),
                    'text' => t('sync.ev_refused', ['ip' => self::plainIp((string) $r['ip'])])];
            }
        }
        // Connections accepted: the latest of each person, protocol and address.
        $logins = [];
        $seen = [];
        foreach (DB::all("SELECT user_id, action, details, created_at FROM audit_log WHERE " . ($uid ? 'user_id = :u AND ' : '')
            . "action IN ('imap.login', 'eas.login', 'smtp.login') AND created_at > :t ORDER BY id DESC LIMIT 1000", ($uid ? ['u' => $uid] : []) + ['t' => $since]) as $r) {
            $proto = explode('.', (string) $r['action'])[0];
            $ip = self::plainIp((string) (json_decode((string) $r['details'], true)['ip'] ?? ''));
            $logins[$proto] = max($logins[$proto] ?? 0, (int) $r['created_at']);
            $k = $r['user_id'] . $proto . $ip;
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $events[] = ['at' => (int) $r['created_at'], 'kind' => 'ok', 'who' => $nameOf((int) $r['user_id']), 'ip' => $ip, 'proto' => $proto,
                    'text' => t('sync.ev_login_' . $proto, ['ip' => $ip])];
            }
        }
        // Exchange devices, and what each synchronises.
        $devices = [];
        foreach (DB::all('SELECT * FROM eas_devices WHERE ' . ($uid ? 'user_id = :u AND ' : '') . 'last_seen_at > :t ORDER BY last_seen_at DESC LIMIT 200',
            ($uid ? ['u' => $uid] : []) + ['t' => time() - 30 * 86400]) as $d) {
            $what = ['folders' => 0, 'mail' => 0, 'calendar' => 0, 'contacts' => 0];
            foreach (DB::all('SELECT collection, updated_at FROM eas_states WHERE device = :d', ['d' => $d['id']]) as $s) {
                $c = (string) $s['collection'];
                $k = match (true) { $c === 'hier' => 'folders', (bool) preg_match('/^F\d+$/', $c) => 'mail', str_starts_with($c, 'Fc') => 'calendar', str_starts_with($c, 'Fk') => 'contacts', default => '' };
                if ($k !== '') {
                    $what[$k] = max($what[$k], (int) $s['updated_at']);
                }
            }
            $name = trim((string) $d['device_type']) ?: trim((string) $d['user_agent']) ?: t('sync.a_device');
            $devices[] = ['name' => $name, 'agent' => (string) $d['user_agent'], 'who' => $nameOf((int) $d['user_id']), 'last' => (int) $d['last_seen_at'],
                'last_time' => format_datetime((int) $d['last_seen_at']), 'ip' => self::plainIp((string) $d['last_ip']), 'since' => format_datetime((int) $d['created_at'], false)] + $what;
            if ((int) $d['last_seen_at'] > $since) {
                $parts = array_keys(array_filter(['mail' => $what['mail'], 'calendar' => $what['calendar'], 'contacts' => $what['contacts']]));
                $events[] = ['at' => (int) $d['last_seen_at'], 'kind' => 'ok', 'who' => $nameOf((int) $d['user_id']), 'ip' => self::plainIp((string) $d['last_ip']), 'proto' => 'eas',
                    'text' => t('sync.ev_device', ['device' => $name, 'what' => $parts ? implode(', ', array_map(static fn($p) => t('sync.what_' . $p), $parts)) : t('sync.what_none')])];
            }
        }
        usort($events, static fn($a, $b) => $b['at'] <=> $a['at']);
        foreach ($events as &$e) {
            $e['time'] = date(date('Ymd', $e['at']) === date('Ymd') ? 'H:i:s' : 'd/m H:i', $e['at']);
        }
        unset($e);
        return ['events' => array_slice($events, 0, $user ? $limit : 200), 'fails' => count($fails), 'last_fail' => (int) ($fails[0]['created_at'] ?? 0),
            'logins' => $logins, 'devices' => $devices, 'ips' => array_values(array_unique(array_map([self::class, 'plainIp'], $ips)))];
    }

    /** The mode one already uses: an Exchange phone of the last month, else IMAP, else none. */
    public static function modeOf(array $user): string
    {
        $uid = (int) $user['id'];
        if (DB::value('SELECT 1 FROM eas_devices WHERE user_id = :u AND last_seen_at > :t', ['u' => $uid, 't' => time() - 30 * 86400])) {
            return 'eas';
        }
        if (DB::value("SELECT 1 FROM audit_log WHERE user_id = :u AND action = 'imap.login' AND created_at > :t", ['u' => $uid, 't' => time() - 30 * 86400])) {
            return 'imap';
        }
        return '';
    }

    /** The service's own journal (every instance of the server), its last lines, newest first. */
    public static function journal(int $lines = 80): array
    {
        $f = MailGateway::dir() . '/maild.log';
        $size = (int) @filesize($f);
        $h = @fopen($f, 'r');
        if (!$h) {
            return [];
        }
        fseek($h, max(0, $size - 64 * 1024));
        $text = (string) stream_get_contents($h);
        fclose($h);
        $out = [];
        foreach (array_reverse(preg_split('/\R/', $text) ?: []) as $l) {
            if (preg_match('/^(\d{4}-\d\d-\d\dT[\d:]+[+-Z][\d:]*) (.+)$/', $l, $m)) {
                $out[] = ['time' => date('d/m H:i:s', (int) strtotime($m[1])), 'text' => $m[2]];
                if (count($out) >= $lines) {
                    break;
                }
            }
        }
        return $out;
    }
}
