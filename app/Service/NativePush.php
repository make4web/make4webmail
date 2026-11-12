<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Crypto;
use M4W\Core\Database as DB;

/**
 * Notifications of the Android app, without Google: the app keeps a long wait open on the
 * server (as messaging apps do without Firebase) and gets, in order, what the PWA gets by Web
 * Push, with the sound the person chose; it plays it itself (their own sounds too).
 */
final class NativePush
{
    /** How long one wait is held (Cloudflare cuts at 100 s). */
    public const WAIT = 50;

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** A device of the app, signed in to this person's account: its token (shown once). */
    public static function register(int $userId, string $name, string $model): string
    {
        $token = Crypto::token(32);
        DB::insert('native_devices', ['user_id' => $userId, 'token_hash' => self::hash($token), 'name' => mb_substr(trim($name) ?: 'Android', 0, 100),
            'model' => mb_substr($model, 0, 100), 'created_at' => time(), 'last_seen_at' => time(), 'last_ip' => '']);
        return $token;
    }

    public static function device(string $token): ?array
    {
        return $token === '' ? null : DB::one('SELECT * FROM native_devices WHERE token_hash = :h', ['h' => self::hash($token)]);
    }

    public static function unregister(int $userId, string $token = '', int $id = 0): void
    {
        $where = $id ? ['id' => $id, 'u' => $userId] : ['h' => self::hash($token), 'u' => $userId];
        foreach (DB::all('SELECT id FROM native_devices WHERE user_id = :u AND ' . ($id ? 'id = :id' : 'token_hash = :h'), $where) as $d) {
            DB::run('DELETE FROM native_queue WHERE device_id = :d', ['d' => $d['id']]);
            DB::run('DELETE FROM native_devices WHERE id = :d', ['d' => $d['id']]);
        }
    }

    public static function devices(int $userId): array
    {
        return DB::all('SELECT id, name, model, created_at, last_seen_at, last_ip FROM native_devices WHERE user_id = :u ORDER BY last_seen_at DESC', ['u' => $userId]);
    }

    /** The sound of this notice for this person (as the open app would play it). */
    private static function sound(int $userId, array $message): array
    {
        $kind = (string) ($message['kind'] ?? '');
        if (!empty($message['silent'])) {
            return ['sound' => 'none'];
        }
        $user = Users::find($userId);
        $k = in_array($kind, ['new', 'reminder'], true) ? $kind : 'reminder';
        $s = (string) ($user['prefs']['sound_' . $k] ?? Notify::defaultSound($k));
        if (preg_match('/^own:(\d+)$/', $s, $m)) {
            return Notify::soundFile($userId, (int) $m[1]) ? ['sound' => $s, 'sound_url' => 'api/native/sound/' . $m[1]] : ['sound' => Notify::defaultSound($k)];
        }
        return ['sound' => in_array($s, Notify::SOUNDS, true) || $s === 'none' ? $s : Notify::defaultSound($k)];
    }

    /** What the PWA is sent, queued for each of the person's app devices. */
    public static function queue(int $userId, array $message): int
    {
        if ((int) \M4W\Core\Settings::get('push.enabled', 1) !== 1) {
            return 0;
        }
        $devices = DB::all('SELECT id FROM native_devices WHERE user_id = :u', ['u' => $userId]);
        if (!$devices) {
            return 0;
        }
        $body = json_encode($message + self::sound($userId, $message), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ($devices as $d) {
            DB::insert('native_queue', ['device_id' => $d['id'], 'payload' => $body, 'created_at' => time()]);
        }
        return count($devices);
    }

    /**
     * The app's wait: what is queued after $ack (what it already showed is dropped), at once or
     * as soon as something comes, else empty after a while.
     * @param ?callable $sleep test hook
     */
    public static function wait(array $device, int $ack, string $ip, int $seconds = self::WAIT, ?callable $sleep = null): array
    {
        DB::run('DELETE FROM native_queue WHERE device_id = :d AND (id <= :a OR created_at < :old)', ['d' => $device['id'], 'a' => $ack, 'old' => time() - 2 * 86400]);
        DB::update('native_devices', ['last_seen_at' => time(), 'last_ip' => mb_substr($ip, 0, 64)], 'id = :id', ['id' => $device['id']]);
        $until = time() + max(0, $seconds);
        do {
            $rows = DB::all('SELECT id, payload FROM native_queue WHERE device_id = :d AND id > :a ORDER BY id LIMIT 20', ['d' => $device['id'], 'a' => $ack]);
            if ($rows) {
                return array_map(static fn($r) => ['id' => (int) $r['id']] + (json_decode((string) $r['payload'], true) ?: []), $rows);
            }
            if (time() >= $until) {
                break;
            }
            $sleep ? $sleep(2) : sleep(2);
        } while (true);
        return [];
    }
}
