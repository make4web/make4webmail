<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Audit;
use M4W\Core\Crypto;
use M4W\Core\Database as DB;
use M4W\Core\Response;
use M4W\Core\Settings;

/**
 * Offline mode, server side.
 *
 * The device keeps the person's recent mail and their pending work encrypted (AES-GCM) with a key
 * of its own. The server keeps a copy of that key, tied to the person and the device: the device
 * drops its copy when the session ends (all it holds becomes unreadable), and gets it back when
 * the same person signs in again (nothing done offline is lost). Signing out deletes it on both
 * sides.
 *
 * Work done offline is replayed through the usual endpoints, each request carrying its offline
 * stamp (X-M4W-Offline: device, action id, order number, time). guard() lets a stamped request
 * through only from a known device of the signed-in person, in order, not too old, and applies
 * each action once: sent again (connection lost during the replay), the stored answer is given
 * back without doing it twice. The checks of the endpoint itself (rights, limits of the
 * compromised-account protection…) apply as online.
 */
final class Offline
{
    /** What can be done offline and replayed. */
    public const PATHS = ['/api/messages/action', '/api/compose/send', '/api/compose/draft'];
    /** Work older than this is not replayed (kept as "too old" on the device). */
    public const MAX_AGE = 7 * 86400;
    /** Clock difference tolerated between the device and the server. */
    private const SKEW = 600;

    public static function enabled(): bool
    {
        return (int) Settings::get('offline.enabled', 1) === 1;
    }

    public static function validDevice(string $device): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{16,64}$/', $device);
    }

    /** The device's key (32 raw bytes): created at the first sign-in on it, the same afterwards. */
    public static function key(int $userId, int $sessionId, string $device, string $userAgent = ''): string
    {
        if (!self::validDevice($device)) {
            throw new \InvalidArgumentException('invalid device');
        }
        $now = time();
        $row = DB::one('SELECT * FROM offline_devices WHERE user_id = :u AND device = :d', ['u' => $userId, 'd' => $device]);
        if ($row) {
            $key = Crypto::decrypt((string) $row['key_enc'], 'offline');
            if ($key !== null && strlen($key) === 32) {
                DB::update('offline_devices', ['session_id' => $sessionId, 'last_seen_at' => $now, 'user_agent' => mb_substr($userAgent, 0, 255)], 'id = :id', ['id' => $row['id']]);
                return $key;
            }
            DB::delete('offline_devices', 'id = :id', ['id' => $row['id']]);
        }
        $key = random_bytes(32);
        DB::insert('offline_devices', [
            'user_id' => $userId, 'device' => $device, 'key_enc' => Crypto::encrypt($key, 'offline'), 'session_id' => $sessionId,
            'user_agent' => mb_substr($userAgent, 0, 255), 'created_at' => $now, 'last_seen_at' => $now,
        ]);
        Audit::log('offline.device', mb_substr($userAgent, 0, 150), [], $userId);
        return $key;
    }

    private static function deleteDevices(string $where, array $params): int
    {
        $ids = array_map('intval', array_column(DB::all("SELECT id FROM offline_devices WHERE $where", $params), 'id'));
        if (!$ids) {
            return 0;
        }
        [$in, $p] = DB::in('d', $ids);
        DB::run("DELETE FROM offline_actions WHERE device_id IN $in", $p);
        DB::run("DELETE FROM offline_devices WHERE id IN $in", $p);
        return count($ids);
    }

    /** Signing out on a device: its key is gone, what it holds can never be read again. */
    public static function forget(int $userId, string $device): int
    {
        return self::deleteDevices('user_id = :u AND device = :d', ['u' => $userId, 'd' => $device]);
    }

    /** Signing out (whatever the device): the keys handed out to this session. */
    public static function forgetSession(int $sessionId): int
    {
        return $sessionId ? self::deleteDevices('session_id = :s', ['s' => $sessionId]) : 0;
    }

    /** Sessions closed elsewhere (password changed, "sign out everywhere"): their devices too. */
    public static function forgetOthers(int $userId, int $keepSessionId): int
    {
        return self::deleteDevices('user_id = :u AND session_id <> :k', ['u' => $userId, 'k' => $keepSessionId]);
    }

    /** Old devices never seen again. */
    public static function gc(int $days = 60): int
    {
        DB::run('DELETE FROM offline_actions WHERE applied_at > 0 AND applied_at < :t', ['t' => time() - 30 * 86400]);
        return self::deleteDevices('last_seen_at < :t', ['t' => time() - $days * 86400]);
    }

    /**
     * Check a replayed request and run it once.
     * @param string   $stamp  the X-M4W-Offline header (JSON: device, id, seq, at)
     * @param callable():Response $run the usual endpoint
     */
    public static function guard(array $user, string $path, string $stamp, callable $run, ?int $now = null): Response
    {
        $now ??= time();
        $refuse = static fn(string $why, int $status) => Response::json(['ok' => false, 'error' => t('offline.refused'), 'offline' => $why], $status);
        if (!self::enabled()) {
            return $refuse('disabled', 403);
        }
        if (!in_array($path, self::PATHS, true)) {
            return $refuse('not_offline', 400);
        }
        // Only for one's own mailbox: nothing of a delegated or shared mailbox is kept on a device.
        if (!empty($user['_actor']) || !empty($user['_shared'])) {
            return $refuse('not_own_mailbox', 403);
        }
        $m = json_decode($stamp, true);
        if (!is_array($m) || !self::validDevice((string) ($m['device'] ?? '')) || !preg_match('/^[A-Za-z0-9-]{8,64}$/', (string) ($m['id'] ?? ''))
            || !is_int($m['seq'] ?? null) || !is_int($m['at'] ?? null)) {
            return $refuse('bad_stamp', 400);
        }
        $dev = DB::one('SELECT * FROM offline_devices WHERE user_id = :u AND device = :d', ['u' => $user['id'], 'd' => $m['device']]);
        if (!$dev) {
            return $refuse('unknown_device', 403);
        }
        $done = DB::one('SELECT * FROM offline_actions WHERE device_id = :d AND action_id = :a', ['d' => $dev['id'], 'a' => $m['id']]);
        if ($done) {
            if ((int) $done['applied_at'] === 0) {
                return $refuse('in_progress', 409);
            }
            // Already applied (the answer was lost on the way): the same answer, nothing done twice.
            $r = new Response((string) $done['response'], (int) $done['status'], ['Content-Type' => 'application/json']);
            $r->header('X-M4W-Offline-Replayed', '1');
            return $r;
        }
        if ($m['seq'] <= (int) $dev['last_seq']) {
            return $refuse('out_of_order', 409);
        }
        if ($m['at'] > $now + self::SKEW || $m['at'] < $now - self::MAX_AGE || $m['at'] < (int) $dev['created_at'] - self::SKEW) {
            return $refuse('too_old', 422);
        }
        try {
            // Claimed first: two replays at once cannot both run it (unique device + action id).
            $aid = DB::insert('offline_actions', ['device_id' => $dev['id'], 'action_id' => $m['id'], 'seq' => $m['seq'], 'path' => $path, 'created_at' => (int) $m['at']]);
        } catch (\PDOException) {
            return $refuse('in_progress', 409);
        }
        DB::update('offline_devices', ['last_seq' => $m['seq'], 'last_seen_at' => $now], 'id = :id', ['id' => $dev['id']]);
        try {
            $res = $run();
        } catch (\Throwable $e) {
            // Not applied: it may be tried again, with the same order number.
            DB::delete('offline_actions', 'id = :id', ['id' => $aid]);
            DB::update('offline_devices', ['last_seq' => (int) $dev['last_seq']], 'id = :id AND last_seq = :s', ['id' => $dev['id'], 's' => $m['seq']]);
            throw $e;
        }
        DB::update('offline_actions', ['status' => $res->status, 'response' => $res->body, 'applied_at' => time()], 'id = :id', ['id' => $aid]);
        Audit::log('offline.replay', $path, ['seq' => $m['seq'], 'status' => $res->status, 'done_at' => date('c', (int) $m['at'])], (int) $user['id']);
        return $res;
    }

    /**
     * What the device keeps for offline use: the latest messages (inbox, own folders, sent) and
     * the calendar around today. Only ids and ranges: the device fetches them as usual.
     */
    public static function manifest(int $userId, int $limit = 100): array
    {
        $ids = array_map('intval', array_column(DB::all(
            "SELECT m.id FROM messages m JOIN folders f ON f.id = m.folder_id
             WHERE m.user_id = :u AND m.is_draft = 0 AND (f.role IS NULL OR f.role IN ('inbox', 'sent', 'archive'))" . Mailbox::privacySql($userId) . '
             ORDER BY m.date_received DESC LIMIT ' . max(1, min(500, $limit)),
            ['u' => $userId]
        ), 'id'));
        $from = (new \DateTimeImmutable('first day of this month 00:00'))->modify('-6 months');
        $to = (new \DateTimeImmutable('first day of this month 00:00'))->modify('+7 months');
        return [
            'messages' => $ids,
            'calendar' => ['start' => $from->format('Y-m-d'), 'end' => $to->format('Y-m-d')],
            'max_age' => self::MAX_AGE,
        ];
    }
}
