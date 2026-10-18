<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Settings;
use M4W\Mail\ImapClient;
use M4W\Mail\ImapLimitException;

/**
 * Connections to the providers' IMAP servers kept within what they accept. Providers (OVH among
 * them) limit the connections from one address: so many per second or minute, so many open at
 * once per mailbox. Every instance of this webmail on the server shares the same address, so
 * they share one ledger (a file per provider server in a common folder, under a lock):
 *   - opened connections are paced (per second; per minute for the background passes);
 *   - the background passes do not open more than the allowed connections per mailbox;
 *   - a refusal from the provider pauses the background passes for that mailbox (or the whole
 *     server), 5 min then doubling up to an hour, instead of insisting and being blocked longer;
 *   - what was seen (open connections, peaks, refusals) is shown by the supervision page.
 * Someone reading their mail (a web request) is never held back by a pause, only paced.
 */
final class ImapLimits
{
    public const PER_SECOND = 3;
    public const PER_MINUTE = 60;
    public const PER_BOX = 8;
    private const PAUSE_FIRST = 300;
    private const PAUSE_MAX = 3600;
    /** A connection entry not refreshed for this long belongs to a process that is gone. */
    private const STALE = 900;

    /** @var ?string test hook: the ledger folder */
    public static ?string $dir = null;
    /** @var ?bool test hook: background pass (CLI) or someone waiting (web) */
    public static ?bool $background = null;

    /** @var array<string, array<string, true>> host => this process' connection keys */
    private static array $mine = [];

    public static function perSecond(): int
    {
        return max(1, min(50, (int) Settings::get('imap.max_per_second', self::PER_SECOND)));
    }

    public static function perMinute(): int
    {
        return max(5, min(1000, (int) Settings::get('imap.max_per_minute', self::PER_MINUTE)));
    }

    public static function perBox(): int
    {
        return max(1, min(50, (int) Settings::get('imap.max_per_box', self::PER_BOX)));
    }

    /** The folder shared by the instances of the server (all running as the same user). */
    public static function dir(): string
    {
        // config/config.php 'imap_ledger_dir' => '/var/lib/make4webmail-imap': the same folder in
        // every instance, when they do not share the temporary folder.
        $d = self::$dir ?? ((string) \M4W\Core\Config::get('imap_ledger_dir', '') ?: rtrim(sys_get_temp_dir(), '/') . '/m4w-imap');
        if (!is_dir($d)) {
            @mkdir($d, 0770, true);
        }
        return $d;
    }

    /** This instance (its folder on the server). */
    public static function instance(): string
    {
        return substr(sha1(dirname(__DIR__, 2)), 0, 10);
    }

    private static function background(): bool
    {
        return self::$background ?? PHP_SAPI === 'cli';
    }

    /**
     * Connect within the limits. Throws ImapLimitException (paused, busy: nothing tried; limit,
     * refused: the provider turned it away, recorded) or the client's own errors.
     */
    public static function connect(ImapClient $c, string $host, string $login, string $password): void
    {
        $host = mb_strtolower($host);
        $login = mb_strtolower($login);
        $bg = self::background();
        $key = self::instance() . ':' . getmypid() . ':' . bin2hex(random_bytes(4));
        $waitUntil = microtime(true) + ($bg ? 30 : 3);
        while (true) {
            $wait = self::edit($host, static function (array &$l) use ($host, $login, $bg, $key, $waitUntil) {
                $now = microtime(true);
                $paused = max((int) ($l['pause'][$login] ?? 0), (int) ($l['pause']['*'] ?? 0));
                if ($bg && $paused > time()) {
                    throw new ImapLimitException(t('imapl.paused', ['host' => $host, 'time' => date('H:i', $paused)]), 'paused', $paused);
                }
                $open = array_filter($l['conns'], static fn($x) => $x['login'] === $login);
                if ($bg && count($open) >= self::perBox()) {
                    $l['busy'] = (int) ($l['busy'] ?? 0) + 1;
                    throw new ImapLimitException(t('imapl.busy_box', ['n' => count($open), 'login' => $login]), 'busy');
                }
                $second = array_filter($l['opens'], static fn($o) => $o[0] > $now - 1);
                $minute = array_filter($l['opens'], static fn($o) => $o[0] > $now - 60);
                if ($now < $waitUntil) {
                    if (count($second) >= self::perSecond()) {
                        return min(array_column($second, 0)) + 1 - $now;
                    }
                    if ($bg && count($minute) >= self::perMinute()) {
                        return min(array_column($minute, 0)) + 60 - $now;
                    }
                } elseif ($bg && count($minute) >= self::perMinute()) {
                    $l['busy'] = (int) ($l['busy'] ?? 0) + 1;
                    throw new ImapLimitException(t('imapl.busy_minute', ['n' => count($minute), 'host' => $host]), 'busy');
                }
                $l['opens'][] = [$now, self::instance()];
                $l['conns'][$key] = ['login' => $login, 'inst' => self::instance(), 'pid' => getmypid(), 'since' => time(), 'seen' => time()];
                self::peaks($l);
                return 0.0;
            });
            if ($wait <= 0) {
                break;
            }
            usleep((int) (min($wait, max(0.05, $waitUntil - microtime(true))) * 1e6) + 1000);
        }
        self::$mine[$host][$key] = true;
        try {
            $c->connect($login, $password);
        } catch (\Throwable $e) {
            self::release($host, $key);
            if ($e instanceof ImapLimitException) {
                self::refused($host, $login, $e);
            }
            throw $e;
        }
        $c->onClose = static fn() => self::release($host, $key);
        self::edit($host, static function (array &$l) use ($login) {
            $l['ok_at'] = time();
            unset($l['strikes'][$login]);
        });
    }

    /** Connections of this process still open (the watcher's, every half-minute): not stale. */
    public static function heartbeat(): void
    {
        foreach (self::$mine as $host => $keys) {
            if (!$keys) {
                continue;
            }
            self::edit((string) $host, static function (array &$l) use ($keys) {
                foreach (array_keys($keys) as $k) {
                    if (isset($l['conns'][$k])) {
                        $l['conns'][$k]['seen'] = time();
                    }
                }
            });
        }
    }

    private static function release(string $host, string $key): void
    {
        unset(self::$mine[$host][$key]);
        self::edit($host, static function (array &$l) use ($key) {
            unset($l['conns'][$key]);
        });
    }

    /** The provider turned a connection away: noted, and the background passes pause. */
    private static function refused(string $host, string $login, ImapLimitException $e): void
    {
        $until = self::edit($host, static function (array &$l) use ($login, $e) {
            $now = time();
            $l['events'][] = ['at' => $now, 'kind' => $e->kind, 'login' => $login, 'inst' => self::instance(), 'msg' => mb_substr($e->getMessage(), 0, 200)];
            $l['events'] = array_slice($l['events'], -50);
            $recent = array_filter($l['events'], static fn($x) => $x['at'] > $now - 600);
            $limited = array_unique(array_column(array_filter($recent, static fn($x) => $x['kind'] === 'limit'), 'login'));
            $refused = count(array_filter($recent, static fn($x) => $x['kind'] === 'refused'));
            // A connection refused once is a network hiccup (or a wrong port): only again and
            // again, on a server that answered today, is it taken for a block.
            if ($e->kind === 'refused' && ($refused < 3 || (int) $l['ok_at'] < $now - 86400)) {
                return 0;
            }
            $strikes = $l['strikes'][$login] = (int) ($l['strikes'][$login] ?? 0) + 1;
            $pause = min(self::PAUSE_MAX, self::PAUSE_FIRST * 2 ** ($strikes - 1));
            $l['pause'][$login] = max((int) ($l['pause'][$login] ?? 0), $now + $pause);
            // Refused before any login, or several mailboxes limited within minutes: the
            // address itself is limited, every mailbox of this server waits.
            if ($e->kind === 'refused' || count($limited) >= 3) {
                $l['pause']['*'] = max((int) ($l['pause']['*'] ?? 0), $now + $pause);
            }
            return max((int) $l['pause'][$login], (int) ($l['pause']['*'] ?? 0));
        });
        if (!$until) {
            return;
        }
        error_log('[m4w] imap limited: ' . $host . ' ' . $login . ' (' . $e->kind . ') paused until ' . date('c', (int) $until) . ': ' . $e->getMessage());
        try {
            \M4W\Core\Audit::log('imap.limited', $host, ['box' => $login, 'kind' => $e->kind, 'until' => (int) $until, 'answer' => mb_substr($e->getMessage(), 0, 200)]);
        } catch (\Throwable) {
        }
    }

    /** Highest values of the last 24 hours (per second, per minute, open at once). */
    private static function peaks(array &$l): void
    {
        $now = microtime(true);
        if ((int) ($l['peaks']['since'] ?? 0) < time() - 86400) {
            $l['peaks'] = ['since' => time(), 'second' => 0, 'minute' => 0, 'box' => 0, 'open' => 0];
        }
        $perBox = array_count_values(array_column($l['conns'], 'login'));
        foreach ([
            'second' => count(array_filter($l['opens'], static fn($o) => $o[0] > $now - 1)),
            'minute' => count(array_filter($l['opens'], static fn($o) => $o[0] > $now - 60)),
            'box' => $perBox ? max($perBox) : 0,
            'open' => count($l['conns']),
        ] as $k => $v) {
            $l['peaks'][$k] = max((int) ($l['peaks'][$k] ?? 0), $v);
        }
    }

    /** Read and change a provider server's ledger under its lock; returns what $fn returns. */
    private static function edit(string $host, callable $fn): mixed
    {
        $file = self::dir() . '/' . preg_replace('/[^a-z0-9.-]/', '_', $host) . '.json';
        $h = @fopen($file, 'c+');
        if (!$h) {
            // No shared folder: pace this process alone rather than not connect at all.
            static $own = [];
            $l = self::clean($own[$host] ?? []);
            $r = $fn($l);
            $own[$host] = $l;
            return $r;
        }
        @chmod($file, 0660);
        try {
            flock($h, LOCK_EX);
            $l = self::clean(json_decode((string) stream_get_contents($h), true) ?: [], $host);
            $r = $fn($l);
            ftruncate($h, 0);
            rewind($h);
            fwrite($h, (string) json_encode($l));
            fflush($h);
            return $r;
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    private static function clean(array $l, string $host = ''): array
    {
        $now = time();
        $l += ['host' => $host, 'opens' => [], 'conns' => [], 'events' => [], 'pause' => [], 'strikes' => [], 'peaks' => [], 'ok_at' => 0];
        $l['opens'] = array_values(array_filter((array) $l['opens'], static fn($o) => is_array($o) && (float) ($o[0] ?? 0) > $now - 120));
        foreach ((array) $l['conns'] as $k => $x) {
            if (!is_array($x) || (int) ($x['seen'] ?? 0) < $now - self::STALE || !self::alive((int) ($x['pid'] ?? 0))) {
                unset($l['conns'][$k]);
            }
        }
        $l['events'] = array_values(array_filter((array) $l['events'], static fn($x) => is_array($x) && (int) ($x['at'] ?? 0) > $now - 86400));
        $l['pause'] = array_filter((array) $l['pause'], static fn($t) => (int) $t > $now);
        return $l;
    }

    private static function alive(int $pid): bool
    {
        if ($pid <= 0 || !function_exists('posix_kill')) {
            return $pid > 0;
        }
        // EPERM: alive, run by another user.
        return posix_kill($pid, 0) || posix_get_last_error() === 1;
    }

    /**
     * What the ledger of each given provider server says, for the supervision page.
     * @param array<string, int> $hosts host => mailboxes of this instance there
     * @return array<string, array>
     */
    public static function report(array $hosts): array
    {
        $out = [];
        foreach ($hosts as $host => $boxes) {
            $host = mb_strtolower((string) $host);
            $l = self::edit($host, static fn(array &$l) => $l);
            $now = microtime(true);
            $perBox = array_count_values(array_column($l['conns'], 'login'));
            $out[$host] = [
                'boxes' => $boxes,
                'open' => count($l['conns']),
                'box_max' => $perBox ? max($perBox) : 0,
                'instances' => count(array_unique(array_merge(array_column($l['conns'], 'inst'), array_column($l['opens'], 1)))),
                'minute' => count(array_filter($l['opens'], static fn($o) => $o[0] > $now - 60)),
                'peaks' => $l['peaks'] + ['second' => 0, 'minute' => 0, 'box' => 0, 'open' => 0],
                'events' => $l['events'],
                'pause' => $l['pause'] ? max($l['pause']) : 0,
                'ok_at' => (int) $l['ok_at'],
            ];
        }
        return $out;
    }

    /**
     * The supervision line of one provider server: [state, detail].
     * fail: paused now; warn: refused today, or close to a limit; ok otherwise.
     */
    public static function judge(string $host, array $r): array
    {
        $now = time();
        $limits = ['second' => self::perSecond(), 'minute' => self::perMinute(), 'box' => self::perBox()];
        $seen = t('imapl.seen', [
            'open' => $r['open'], 'boxes' => $r['boxes'], 'instances' => max(1, $r['instances']), 'box_max' => $r['box_max'], 'per_box' => $limits['box'],
            'minute' => $r['minute'], 'peak_s' => $r['peaks']['second'], 'per_s' => $limits['second'], 'peak_m' => $r['peaks']['minute'], 'per_m' => $limits['minute'],
        ]);
        $limited = array_values(array_filter($r['events'], static fn($e) => $e['kind'] === 'limit'));
        $refused = array_values(array_filter($r['events'], static fn($e) => $e['kind'] === 'refused' && $e['at'] > $now - 3600));
        $last = $limited ? end($limited) : ($refused ? end($refused) : null);
        if ($r['pause'] > $now) {
            return ['fail', t('imapl.paused_now', ['host' => $host, 'time' => date('H:i', (int) $r['pause']), 'answer' => $last['msg'] ?? '']) . ' ' . $seen];
        }
        if ($limited) {
            return ['warn', t('imapl.limited_today', ['n' => count($limited), 'host' => $host, 'answer' => $last['msg'], 'date' => format_datetime((int) $last['at'])]) . ' ' . $seen];
        }
        // Refused now and then is a network hiccup; again and again after it worked, a block.
        if (count($refused) >= 3 && $r['ok_at'] > $now - 86400) {
            return ['warn', t('imapl.refused_often', ['n' => count($refused), 'host' => $host]) . ' ' . $seen];
        }
        // (Per second, the pacing itself holds the connections at the limit: nothing to say.)
        foreach (['box' => $r['peaks']['box'], 'minute' => $r['peaks']['minute']] as $k => $v) {
            if ($v >= max(1, (int) ceil($limits[$k] * 0.8))) {
                return ['warn', t('imapl.close_' . $k, ['n' => $v, 'limit' => $limits[$k]]) . ' ' . $seen];
            }
        }
        return ['ok', $seen];
    }
}
