<?php
// Router for PHP's built-in web server: php -S 0.0.0.0:8080 -t public public/router.php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = realpath(__DIR__ . rawurldecode($path));
if ($path !== '/' && $file !== false && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR) && is_file($file) && !str_ends_with($file, '.php')) {
    // Served here (not with "return false") so static files get the same hardening headers as with nginx/Apache.
    $types = [
        'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'json' => 'application/json',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'ico' => 'image/x-icon', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'webmanifest' => 'application/manifest+json',
        'txt' => 'text/plain; charset=utf-8', 'map' => 'application/json',
    ];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header_remove('X-Powered-By');
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: public, max-age=2592000');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cross-Origin-Resource-Policy: same-origin');
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; font-src 'self'; img-src 'self' data:; frame-ancestors 'none'");
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        readfile($file);
    }
    return true;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
