<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Database as DB;
use M4W\Core\Settings;

/**
 * Notification settings of a person: when (always, or weekly hours as for the rooms), what
 * (each new message, and a reminder while mail stays unread), how (sound, vibration).
 *
 * Sounds play when the app or the tab is open (a closed app gets the system's own sound: the
 * browsers let no page choose it). Classic sounds are files of the application; a person may also
 * add their own (MP3, OGG, WAV; 1 MB at most), kept here, served to them only, offered for every
 * kind of alert.
 */
final class Notify
{
    /** Classic sounds (public/assets/sounds, made by bin/sounds.php). */
    public const SOUNDS = ['ding', 'chime', 'bell', 'pop', 'marimba', 'beep'];
    public const VIBRATIONS = ['none' => [], 'short' => [200], 'long' => [600], 'pulse' => [200, 100, 200, 100, 200]];
    public const REMINDER_MINUTES = [5, 10, 15, 30, 60, 120];
    public const KINDS = ['new', 'reminder'];
    private const MAX_SOUND = 1048576;
    private const MAX_OWN = 12;

    /** Weekly hours "08:00-19:00,…" (Monday first, empty = off that day) → [1 => ['08:00','19:00']|null, …]. */
    public static function parseHours(string $s): array
    {
        $parts = explode(',', $s);
        $out = [];
        for ($d = 1; $d <= 7; $d++) {
            $p = trim($parts[$d - 1] ?? '');
            $out[$d] = preg_match('/^([01]\d|2[0-3]):([0-5]\d)-([01]\d|2[0-3]):([0-5]\d)$/', $p, $m) && $m[1] . $m[2] < $m[3] . $m[4]
                ? [$m[1] . ':' . $m[2], $m[3] . ':' . $m[4]] : null;
        }
        return $out;
    }

    public static function formatHours(array $h): string
    {
        $out = [];
        for ($d = 1; $d <= 7; $d++) {
            $out[] = isset($h[$d]) && is_array($h[$d]) ? $h[$d][0] . '-' . $h[$d][1] : '';
        }
        return implode(',', $out);
    }

    public static function defaultHours(): string
    {
        return '08:00-19:00,08:00-19:00,08:00-19:00,08:00-19:00,08:00-19:00,,';
    }

    /** Whether a person wants to be alerted at this moment (their own time zone is the server's). */
    public static function inPeriod(array $prefs, ?int $ts = null): bool
    {
        if (($prefs['notify_period'] ?? 'always') !== 'custom') {
            return true;
        }
        $ts ??= time();
        $h = self::parseHours((string) ($prefs['notify_hours'] ?? ''))[(int) date('N', $ts)];
        if ($h === null) {
            return false;
        }
        $now = date('H:i', $ts);
        return $now >= $h[0] && ($now < $h[1] || $h[1] === '23:59');
    }

    /** "No sound" chosen: the system notification is silent too (browsers then do not vibrate either). */
    public static function silent(array $prefs, string $kind): bool
    {
        return ($prefs['sound_' . $kind] ?? '') === 'none';
    }

    public static function vibration(array $prefs): array
    {
        return self::VIBRATIONS[$prefs['vibrate'] ?? 'short'] ?? [];
    }

    // ------------------------------------------------------------- sounds

    /** A sound chosen for an alert: "none", a classic one, or one of the person's own ("own:12"). */
    public static function validSound(int $userId, string $v): bool
    {
        if ($v === 'none' || in_array($v, self::SOUNDS, true)) {
            return true;
        }
        return (bool) preg_match('/^own:(\d+)$/', $v, $m)
            && (bool) DB::value('SELECT COUNT(*) FROM user_sounds WHERE id = :id AND user_id = :u', ['id' => (int) $m[1], 'u' => $userId]);
    }

    public static function defaultSound(string $kind): string
    {
        return $kind === 'reminder' ? 'chime' : 'ding';
    }

    private static function dir(): string
    {
        $d = storage_path('uploads/sounds');
        if (!is_dir($d)) {
            @mkdir($d, 0750, true);
        }
        return $d;
    }

    /** The person's own sounds, offered in every list of sounds. */
    public static function ownSounds(int $userId): array
    {
        return DB::all('SELECT id, label FROM user_sounds WHERE user_id = :u ORDER BY label, id', ['u' => $userId]);
    }

    /** A sound sent by a person. Returns ['id', 'label']. */
    public static function addSound(int $userId, array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new \InvalidArgumentException(t('upload.failed'));
        }
        return self::addSoundFile($userId, (string) $file['tmp_name'], (int) $file['size'], (string) ($file['name'] ?? ''), true);
    }

    /** (Separate for the tests: no real upload there.) */
    public static function addSoundFile(int $userId, string $path, int $size, string $name, bool $uploaded = false): array
    {
        if ($size <= 0 || $size > self::MAX_SOUND) {
            throw new \InvalidArgumentException(t('notify.sound_too_big'));
        }
        if ((int) DB::value('SELECT COUNT(*) FROM user_sounds WHERE user_id = :u', ['u' => $userId]) >= self::MAX_OWN) {
            throw new \InvalidArgumentException(t('notify.sound_too_many', ['n' => self::MAX_OWN]));
        }
        $head = (string) file_get_contents($path, false, null, 0, 16);
        // The content decides, not the name: MP3 (ID3 or frame sync), OGG, WAV.
        $ext = match (true) {
            str_starts_with($head, 'ID3') || (strlen($head) > 1 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0) => 'mp3',
            str_starts_with($head, 'OggS') => 'ogg',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WAVE' => 'wav',
            default => null,
        };
        if ($ext === null) {
            throw new \InvalidArgumentException(t('notify.sound_not_audio'));
        }
        $file = $userId . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
        if ($uploaded ? !move_uploaded_file($path, self::dir() . '/' . $file) : !copy($path, self::dir() . '/' . $file)) {
            throw new \InvalidArgumentException(t('upload.failed'));
        }
        $label = (string) preg_replace('/\.[a-z0-9]{2,4}$/i', '', basename(str_replace('\\', '/', $name)));
        $label = trim((string) preg_replace('/[\x00-\x1f<>"\s]+/u', ' ', $label));
        $label = mb_substr($label !== '' ? $label : t('notify.own_sound_name'), 0, 60);
        $id = DB::insert('user_sounds', ['user_id' => $userId, 'label' => $label, 'file' => $file, 'created_at' => time()]);
        return ['id' => $id, 'label' => $label];
    }

    /** Path and type of one of a person's own sounds, or null. */
    public static function soundFile(int $userId, int $id): ?array
    {
        $row = DB::one('SELECT file FROM user_sounds WHERE id = :id AND user_id = :u', ['id' => $id, 'u' => $userId]);
        if (!$row || !preg_match('/^\d+-(?:(?:new|reminder)-)?[0-9a-f]{12}\.(mp3|ogg|wav)$/', (string) $row['file'], $m)) {
            return null;
        }
        $path = self::dir() . '/' . $row['file'];
        return is_file($path) ? ['path' => $path, 'mime' => ['mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav'][$m[1]], 'ext' => $m[1]] : null;
    }

    /** Removed: alerts that used it go back to the classic sound. */
    public static function deleteSound(int $userId, int $id): bool
    {
        $f = self::soundFile($userId, $id);
        if (!DB::delete('user_sounds', 'id = :id AND user_id = :u', ['id' => $id, 'u' => $userId])) {
            return false;
        }
        if ($f) {
            @unlink($f['path']);
        }
        $u = Users::find($userId);
        $reset = [];
        foreach (self::KINDS as $k) {
            if (($u['prefs']['sound_' . $k] ?? '') === 'own:' . $id) {
                $reset['sound_' . $k] = self::defaultSound($k);
            }
        }
        if ($reset) {
            Users::savePrefs($userId, $reset);
        }
        return true;
    }

    /** All of a person's sounds (account deleted). */
    public static function deleteUser(int $userId): void
    {
        foreach (DB::all('SELECT id FROM user_sounds WHERE user_id = :u', ['u' => $userId]) as $r) {
            self::deleteSound($userId, (int) $r['id']);
        }
    }

    // ---------------------------------------------------------- reminders

    /** Unread mail waiting for someone for at least $minutes (inbox and own folders). */
    public static function waiting(int $userId, int $minutes): array
    {
        $rows = DB::all(
            "SELECT m.from_name, m.from_email, m.date_received FROM messages m JOIN folders f ON f.id = m.folder_id
             WHERE m.user_id = :u AND m.is_read = 0 AND m.is_draft = 0 AND (f.role = 'inbox' OR f.role IS NULL) AND m.date_received <= :t
             ORDER BY m.date_received LIMIT 200",
            ['u' => $userId, 't' => time() - $minutes * 60]
        );
        return $rows;
    }

    /**
     * "3 unread messages": sent again every N minutes while mail stays unread, within the
     * person's hours (scheduled task, every minute). Returns the number of people reminded.
     */
    public static function reminders(?int $now = null): int
    {
        if (!WebPush::enabled()) {
            return 0;
        }
        $now ??= time();
        $n = 0;
        $users = DB::all('SELECT DISTINCT u.id FROM users u JOIN push_subscriptions s ON s.user_id = u.id WHERE u.status = :s', ['s' => 'active']);
        foreach ($users as $row) {
            $u = Users::find((int) $row['id']);
            if (!$u) {
                continue;
            }
            $p = $u['prefs'];
            if (!(int) $p['notifications'] || !(int) $p['notify_reminder']) {
                continue;
            }
            $every = in_array((int) $p['reminder_minutes'], self::REMINDER_MINUTES, true) ? (int) $p['reminder_minutes'] : 30;
            $state = DB::one('SELECT * FROM notify_reminders WHERE user_id = :u', ['u' => $u['id']]);
            $waiting = self::waiting((int) $u['id'], $every);
            if (!$waiting) {
                if ($state) {
                    DB::delete('notify_reminders', 'user_id = :u', ['u' => $u['id']]);
                }
                continue;
            }
            if ($state && $now - (int) $state['sent_at'] < $every * 60) {
                continue;
            }
            if (!self::inPeriod($p, $now)) {
                continue;
            }
            $count = count($waiting);
            $since = (int) floor(($now - (int) $waiting[0]['date_received']) / 60);
            $who = array_values(array_unique(array_map(static fn($r) => trim((string) $r['from_name']) ?: (string) $r['from_email'], $waiting)));
            $preview = (int) Settings::get('push.preview', 1) === 1;
            $body = t('notify.reminder_since', ['time' => $since >= 120 ? t('notify.hours', ['n' => intdiv($since, 60)]) : t('notify.minutes', ['n' => $since])])
                . ($preview ? "\n" . t('notify.reminder_from', ['who' => implode(', ', array_slice($who, 0, 3)) . (count($who) > 3 ? '…' : '')]) : '');
            $sent = WebPush::send((int) $u['id'], [
                'title' => t($count > 1 ? 'notify.reminder_title_n' : 'notify.reminder_title_1', ['n' => $count]),
                'body' => $body, 'tag' => 'm4w-reminder', 'url' => 'mail', 'kind' => 'reminder', 'renotify' => true,
                'vibrate' => self::vibration($p), 'silent' => self::silent($p, 'reminder'), 'count' => array_sum(SharedMailboxes::counts((int) $u['id'])), 'icon' => WebPush::iconPath(),
            ], 3600, true);
            if ($sent) {
                $n++;
            }
            if ($state) {
                DB::update('notify_reminders', ['sent_at' => $now, 'unread' => $count], 'user_id = :u', ['u' => $u['id']]);
            } else {
                DB::insert('notify_reminders', ['user_id' => $u['id'], 'sent_at' => $now, 'unread' => $count]);
            }
        }
        return $n;
    }
}
