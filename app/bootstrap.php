<?php
declare(strict_types=1);

/**
 * Make4WebMail — application bootstrap.
 * Loaded by every entry point (web front controller and CLI tools).
 */

define('M4W_VERSION', '1.0.0');
define('M4W_ROOT', dirname(__DIR__));
define('M4W_APP', __DIR__);

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Make4WebMail requires PHP 8.1 or newer.');
}

// Messages, database and uploads must not be readable by other local accounts.
umask(0027);

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'M4W\\')) {
        return;
    }
    $file = M4W_APP . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require M4W_APP . '/helpers.php';
require M4W_APP . '/vendor/autoload.php';

mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');

M4W\Core\Config::load();

// A command run as root (cron.php typed by hand, a pipe set up with the wrong user) would leave
// messages and folders that the web server can no longer read: it takes the identity of the
// owner of the storage directory first, before writing anything.
if (PHP_SAPI === 'cli' && function_exists('posix_geteuid') && posix_geteuid() === 0) {
    $st = @stat(M4W\Core\Config::storagePath());
    if ($st && $st['uid'] !== 0) {
        $pw = posix_getpwuid($st['uid']);
        if ($pw && function_exists('posix_initgroups')) {
            @posix_initgroups($pw['name'], $st['gid']);
        }
        if (!@posix_setgid($st['gid']) || !@posix_setuid($st['uid'])) {
            fwrite(STDERR, "Make4WebMail: cannot switch to the owner of the storage directory; run this command as that user (e.g. sudo -u www-data).\n");
            exit(1);
        }
        if ($pw) {
            putenv('HOME=' . $pw['dir']);
        }
    }
    unset($st, $pw);
}

$tz = M4W\Core\Config::get('timezone', 'Europe/Paris');
if (is_string($tz) && in_array($tz, timezone_identifiers_list(), true)) {
    date_default_timezone_set($tz);
}

if (M4W\Core\Config::get('debug', false)) {
    error_reporting(E_ALL);
    ini_set('display_errors', PHP_SAPI === 'cli' ? '1' : '0');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');
ini_set('error_log', M4W\Core\Config::storagePath('logs/php-error.log'));
