<?php
declare(strict_types=1);

namespace M4W\Core;

use M4W\Service\Users;

final class Auth
{
    private static ?array $user = null;
    private static bool $resolved = false;

    public static function user(): ?array
    {
        if (!self::$resolved) {
            self::$resolved = true;
            self::$user = self::resolve();
        }
        return self::$user;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int) $u['id'] : null;
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'admin';
    }

    /** For CLI/tests. */
    public static function setUser(?array $user): void
    {
        self::$user = $user;
        self::$resolved = true;
    }

    private static function resolve(): ?array
    {
        $uid = Session::get('uid');
        $token = Session::get('stoken');
        if (!$uid || !is_string($token)) {
            return null;
        }
        $row = Database::one(
            'SELECT * FROM user_sessions WHERE token_hash = :h AND user_id = :u',
            ['h' => hash('sha256', $token), 'u' => (int) $uid]
        );
        $now = time();
        $idle = max(5, (int) Settings::get('security.session_idle', 60)) * 60;
        $abs = max(30, (int) Settings::get('security.session_absolute', 720)) * 60;
        if (!$row || $row['revoked'] || ($now - (int) $row['last_seen_at']) > $idle || ($now - (int) $row['created_at']) > $abs) {
            Session::forget('uid');
            Session::forget('stoken');
            return null;
        }
        $user = Users::find((int) $uid);
        if (!$user || $user['status'] !== 'active') {
            return null;
        }
        if ($now - (int) $row['last_seen_at'] > 30) {
            Database::update('user_sessions', ['last_seen_at' => $now], 'id = :id', ['id' => $row['id']]);
        }
        $user['_session_id'] = (int) $row['id'];
        return $user;
    }

    /**
     * Check credentials with brute-force protection.
     * @return array{ok:bool,error?:string,user?:array}
     */
    public static function attempt(string $email, string $password, Request $req): array
    {
        $email = mb_strtolower(trim($email));
        $ip = $req->ip();
        $window = max(1, (int) Settings::get('security.lockout_minutes', 15)) * 60;
        $max = max(3, (int) Settings::get('security.max_attempts', 5));
        $since = time() - $window;

        $failsByEmail = (int) Database::value(
            'SELECT COUNT(*) FROM login_attempts WHERE email = :e AND success = 0 AND created_at > :s',
            ['e' => $email, 's' => $since]
        );
        $failsByIp = (int) Database::value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = :ip AND success = 0 AND created_at > :s',
            ['ip' => $ip, 's' => $since]
        );
        if ($failsByEmail >= $max || $failsByIp >= $max * 4) {
            Audit::log('login.locked', $email, ['ip' => $ip], null);
            return ['ok' => false, 'error' => t('auth.locked', ['minutes' => (int) ($window / 60)])];
        }

        $user = Users::findByEmail($email);
        // Constant-ish time: always run a hash verification.
        $hash = $user['password_hash'] ?? Crypto::hashPassword(Crypto::token(8));
        $valid = Crypto::verifyPassword($password, $hash) && $user !== null;

        if (!$valid || $user['status'] !== 'active') {
            Database::insert('login_attempts', ['ip' => $ip, 'email' => $email, 'success' => 0, 'created_at' => time()]);
            Audit::log('login.failed', $email, ['ip' => $ip], $user['id'] ?? null);
            usleep(random_int(150000, 400000));
            return ['ok' => false, 'error' => $user && $user['status'] !== 'active' && $valid
                ? t('auth.disabled') : t('auth.invalid')];
        }
        if (Crypto::needsRehash($user['password_hash'])) {
            Database::update('users', ['password_hash' => Crypto::hashPassword($password)], 'id = :id', ['id' => $user['id']]);
        }
        Database::insert('login_attempts', ['ip' => $ip, 'email' => $email, 'success' => 1, 'created_at' => time()]);
        return ['ok' => true, 'user' => $user];
    }

    public static function login(array $user, Request $req): void
    {
        Session::regenerate();
        $token = Crypto::token(32);
        $now = time();
        Database::insert('user_sessions', [
            'user_id'      => $user['id'],
            'token_hash'   => hash('sha256', $token),
            'ip'           => $req->ip(),
            'user_agent'   => $req->userAgent(),
            'created_at'   => $now,
            'last_seen_at' => $now,
        ]);
        Database::update('users', ['last_login_at' => $now, 'last_login_ip' => $req->ip()], 'id = :id', ['id' => $user['id']]);
        Session::forget('pending_2fa');
        Session::set('uid', (int) $user['id']);
        Session::set('stoken', $token);
        Session::set('_csrf', Crypto::token(32));
        self::$resolved = false;
        Audit::log('login.success', $user['email'], ['ip' => $req->ip()], (int) $user['id']);
    }

    public static function logout(): void
    {
        $token = Session::get('stoken');
        if (is_string($token)) {
            Database::update('user_sessions', ['revoked' => 1], 'token_hash = :h', ['h' => hash('sha256', $token)]);
        }
        Session::destroy();
        self::$user = null;
    }

    public static function revokeOtherSessions(int $userId, int $keepSessionId = 0): void
    {
        Database::update('user_sessions', ['revoked' => 1], 'user_id = :u AND id <> :k', ['u' => $userId, 'k' => $keepSessionId]);
    }

    /** Validate password strength against the admin policy. Returns error message or null. */
    public static function passwordPolicyError(string $pw): ?string
    {
        $min = max(8, (int) Settings::get('security.password_min', 10));
        $classesNeeded = (int) Settings::get('security.password_classes', 3);
        if (mb_strlen($pw) < $min) {
            return t('pw.too_short', ['n' => $min]);
        }
        $classes = (int) preg_match('/[a-z]/', $pw) + (int) preg_match('/[A-Z]/', $pw)
            + (int) preg_match('/\d/', $pw) + (int) preg_match('/[^a-zA-Z\d]/', $pw);
        if ($classes < $classesNeeded) {
            return t('pw.classes', ['n' => $classesNeeded]);
        }
        return null;
    }
}
