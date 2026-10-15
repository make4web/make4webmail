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
    }

    /** @return ?array the user, if this is one of their app passwords (with throttling). */
    public static function check(string $email, string $pw, string $ip): ?array
    {
        $email = mb_strtolower(trim($email));
        $window = max(1, (int) Settings::get('security.lockout_minutes', 15)) * 60;
        $max = max(3, (int) Settings::get('security.max_attempts', 5));
        $fails = (int) DB::value("SELECT COUNT(*) FROM login_attempts WHERE ip = :ip AND email LIKE 'dav:%' AND success = 0 AND created_at > :t", ['ip' => $ip, 't' => time() - $window]);
        if ($fails >= $max * 4) {
            return null;
        }
        $user = \M4W\Service\Users::findByEmail($email);
        $row = $user && $user['status'] === 'active'
            ? DB::one('SELECT id, last_used_at, last_ip FROM app_passwords WHERE user_id = :u AND hash = :h', ['u' => $user['id'], 'h' => self::hash($pw)])
            : null;
        if (!$row) {
            DB::insert('login_attempts', ['ip' => $ip, 'email' => 'dav:' . $email, 'success' => 0, 'created_at' => time()]);
            usleep(random_int(200000, 500000));
            return null;
        }
        if (time() - (int) $row['last_used_at'] > 300 || $row['last_ip'] !== $ip) {
            DB::update('app_passwords', ['last_used_at' => time(), 'last_ip' => mb_substr($ip, 0, 64)], 'id = :id', ['id' => $row['id']]);
        }
        return $user;
    }
}
