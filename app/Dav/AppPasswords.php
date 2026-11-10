<?php
declare(strict_types=1);

namespace M4W\Dav;

use M4W\Core\Crypto;
use M4W\Core\Database as DB;
use M4W\Core\Settings;

/**
 * Passwords for phones and desktop programs (calendar and contacts sync). Never the account
 * password: one per device, shown once, revocable, and useless for the webmail itself.
 * 20 random letters: a keyed hash is enough to store them, and fast for sync clients that
 * authenticate on every request.
 */
final class AppPasswords
{
    public static function create(int $userId, string $name): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $raw = '';
        for ($i = 0; $i < 20; $i++) {
            $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $pw = implode('-', str_split($raw, 5));
        DB::insert('app_passwords', ['user_id' => $userId, 'name' => mb_substr(trim($name) ?: 'Appareil', 0, 100), 'hash' => self::hash($pw), 'created_at' => time()]);
        return $pw;
    }

    public static function hash(string $pw): string
    {
        return Crypto::hmac(strtolower(str_replace([' ', '-'], '', $pw)), 'app-password');
    }

    public static function list(int $userId): array
    {
        return DB::all('SELECT id, name, created_at, last_used_at, last_ip FROM app_passwords WHERE user_id = :u ORDER BY id DESC', ['u' => $userId]);
    }

    public static function revoke(int $userId, int $id): void
    {
        DB::run('DELETE FROM app_passwords WHERE user_id = :u AND id = :id', ['u' => $userId, 'id' => $id]);
        // The phones that signed in with it are forgotten too (they will ask for a password).
        try {
            foreach (DB::all('SELECT id FROM eas_devices WHERE user_id = :u AND app_password_id = :p', ['u' => $userId, 'p' => $id]) as $d) {
                DB::run('DELETE FROM eas_states WHERE device = :d', ['d' => $d['id']]);
                DB::run('DELETE FROM eas_devices WHERE id = :d', ['d' => $d['id']]);
            }
        } catch (\Throwable) {
        }
    }

    /** @return ?array the user, if this is one of their app passwords (with throttling). */
    /** Why the last check said no: locked (too many failures from this address), unknown, inactive, password. */
    public static string $refusal = '';
    /** The app password that matched in the last check, and whether it was its very first use. */
    public static int $matched = 0;
    public static bool $firstUse = false;

    public static function check(string $email, string $pw, string $ip): ?array
    {
        self::$refusal = '';
        self::$matched = 0;
        self::$firstUse = false;
        $email = mb_strtolower(trim($email));
        $window = max(1, (int) Settings::get('security.lockout_minutes', 15)) * 60;
        $max = max(3, (int) Settings::get('security.max_attempts', 5));
        $fails = (int) DB::value("SELECT COUNT(*) FROM login_attempts WHERE ip = :ip AND email LIKE 'dav:%' AND success = 0 AND created_at > :t", ['ip' => $ip, 't' => time() - $window]);
        if ($fails >= $max * 4) {
            self::$refusal = 'locked: ' . $fails . ' failures from this address in ' . ($window / 60) . ' min';
            return null;
        }
        $user = \M4W\Service\Users::findByEmail($email);
        $row = $user && $user['status'] === 'active'
            ? DB::one('SELECT id, last_used_at, last_ip FROM app_passwords WHERE user_id = :u AND hash = :h', ['u' => $user['id'], 'h' => self::hash($pw)])
            : null;
        if (!$row) {
            self::$refusal = !$user ? 'unknown account' : ($user['status'] !== 'active' ? 'account ' . $user['status'] : 'not an app password of this account');
            DB::insert('login_attempts', ['ip' => $ip, 'email' => 'dav:' . $email, 'success' => 0, 'created_at' => time()]);
            usleep(random_int(200000, 500000));
            return null;
        }
        self::$matched = (int) $row['id'];
        self::$firstUse = (int) $row['last_used_at'] === 0;
        if (self::$firstUse) {
            // A password just made, used for the first time: the person hears of it (a stolen one too).
            $name = (string) DB::value('SELECT name FROM app_passwords WHERE id = :id', ['id' => $row['id']]);
            \M4W\Service\AccountGuard::notifyUser($user, t('dav.first_use_subject', ['name' => $name]), t('dav.first_use_text', ['name' => $name, 'ip' => preg_replace('/^::ffff:/', '', $ip), 'date' => format_datetime(time())]), 'settings/sync');
        }
        if (time() - (int) $row['last_used_at'] > 300 || $row['last_ip'] !== $ip) {
            DB::update('app_passwords', ['last_used_at' => time(), 'last_ip' => mb_substr($ip, 0, 64)], 'id = :id', ['id' => $row['id']]);
        }
        return $user;
    }
}
