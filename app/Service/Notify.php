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
 * browsers let no page choose it). Built-in sounds are made by the page itself (Web Audio), a
 * person may also give their own (MP3, OGG, WAV; 1 MB at most), kept here and served to them only.
 */
final class Notify
{
    public const SOUNDS = ['none', 'ding', 'chime', 'bell', 'pop', 'marimba', 'beep', 'custom'];
    public const VIBRATIONS = ['none' => [], 'short' => [200], 'long' => [600], 'pulse' => [200, 100, 200, 100, 200]];
    public const REMINDER_MINUTES = [5, 10, 15, 30, 60, 120];
    public const KINDS = ['new', 'reminder'];
    private const MAX_SOUND = 1048576;

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

    private static function dir(): string
    {
        $d = storage_path('uploads/sounds');
        if (!is_dir($d)) {
            @mkdir($d, 0750, true);
        }
        return $d;
    }

    /** A person's own sound for one kind of alert. Returns the stored name. */
    public static function storeSound(int $userId, string $kind, array $file): string
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('kind');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new \InvalidArgumentException(t('upload.failed'));
        }
        return self::storeSoundFile($userId, $kind, (string) $file['tmp_name'], (int) $file['size'], true);
    }

    /** (Separate for the tests: no real upload there.) */
    public static function storeSoundFile(int $userId, string $kind, string $path, int $size, bool $uploaded = false): string
    {
        if ($size <= 0 || $size > self::MAX_SOUND) {
            throw new \InvalidArgumentException(t('notify.sound_too_big'));
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
        $u = Users::find($userId);
        $name = $userId . '-' . $kind . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
        $dest = self::dir() . '/' . $name;
        if ($uploaded ? !move_uploaded_file($path, $dest) : !copy($path, $dest)) {
            throw new \InvalidArgumentException(t('upload.failed'));
        }
        $old = (string) ($u['prefs']['sound_file_' . $kind] ?? '');
        if ($old !== '' && preg_match('/^\d+-(new|reminder)-[0-9a-f]{12}\.(mp3|ogg|wav)$/', $old)) {
            @unlink(self::dir() . '/' . $old);
        }
        Users::savePrefs($userId, ['sound_file_' . $kind => $name, 'sound_' . $kind => 'custom']);
        return $name;
    }

    /** Path and type of a person's own sound, or null. */
    public static function soundFile(array $user, string $kind): ?array
    {
        $name = (string) ($user['prefs']['sound_file_' . $kind] ?? '');
        if (!in_array($kind, self::KINDS, true) || !preg_match('/^(\d+)-(new|reminder)-[0-9a-f]{12}\.(mp3|ogg|wav)$/', $name, $m) || (int) $m[1] !== (int) $user['id']) {
            return null;
        }
        $path = self::dir() . '/' . $name;
        return is_file($path) ? ['path' => $path, 'mime' => ['mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav'][$m[3]], 'name' => $name] : null;
    }

    public static function deleteSound(int $userId, string $kind): void
    {
        $u = Users::find($userId);
        if ($u && ($f = self::soundFile($u, $kind))) {
            @unlink($f['path']);
        }
        Users::savePrefs($userId, ['sound_file_' . $kind => '', 'sound_' . $kind => $kind === 'new' ? 'ding' : 'chime']);
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
            ], 3600);
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
