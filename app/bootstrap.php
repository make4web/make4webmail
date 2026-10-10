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

mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');

M4W\Core\Config::load();

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
