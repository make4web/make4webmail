<?php
declare(strict_types=1);

namespace M4W\Core;

final class Session
{
    private static bool $started = false;

    public static function start(Request $req): void
    {
        if (self::$started || PHP_SAPI === 'cli') {
            self::$started = true;
            if (PHP_SAPI === 'cli' && !isset($_SESSION)) {
                $_SESSION = [];
            }
            return;
        }
        $dir = Config::storagePath('sessions');
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
        ini_set('session.gc_maxlifetime', (string) (max(60, (int) Settings::get('security.session_absolute', 720)) * 60));
        session_save_path($dir);
        session_name('m4w_sid');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => (Request::basePath() ?: '') . '/',
            'secure'   => $req->isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        self::$started = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function regenerate(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'],
                'httponly' => true, 'samesite' => $p['samesite'] ?: 'Lax',
            ]);
            session_destroy();
        }
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function pullFlash(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $f;
    }

    /** Release the session lock early for long-running/parallel AJAX requests. */
    public static function close(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
            self::$started = false;
        }
    }
}
