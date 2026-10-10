#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Command-line installer.
 *   php bin/install.php --domain=example.com --admin=admin@example.com --password='S3cure!pass' [--name="Jane Admin"] [--brand="Acme Mail"] [--lang=fr]
 *   MySQL: add --db=mysql --db-host=127.0.0.1 --db-name=m4w --db-user=m4w --db-pass=secret
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

use M4W\Core\Config;

if (Config::installed() && !in_array('--force', $argv, true)) {
    fwrite(STDERR, "Already installed (config/config.php exists). Use --force to reinstall.\n");
    exit(1);
}
$o = getopt('', ['domain:', 'admin:', 'password:', 'name::', 'brand::', 'lang::', 'db::', 'db-host::', 'db-name::', 'db-user::', 'db-pass::', 'db-port::', 'force']);
foreach (['domain', 'admin', 'password'] as $req) {
    if (empty($o[$req])) {
        fwrite(STDERR, "Missing --$req\n");
        exit(64);
    }
}
$db = ($o['db'] ?? 'sqlite') === 'mysql'
    ? ['driver' => 'mysql', 'host' => $o['db-host'] ?? '127.0.0.1', 'port' => (int) ($o['db-port'] ?? 3306), 'name' => $o['db-name'] ?? 'm4w', 'user' => $o['db-user'] ?? '', 'pass' => $o['db-pass'] ?? '']
    : ['driver' => 'sqlite', 'path' => Config::storagePath('data/m4w.sqlite')];
if (isset($o['force']) && $db['driver'] === 'sqlite' && is_file($db['path'])) {
    unlink($db['path']);
}
M4W\Core\I18n::setLanguage($o['lang'] ?? 'fr');
M4W\Service\Installer::install([
    'lang' => $o['lang'] ?? 'fr', 'brand' => $o['brand'] ?? 'Make4WebMail', 'domain' => strtolower($o['domain']),
    'admin_email' => strtolower($o['admin']), 'admin_password' => $o['password'], 'admin_name' => $o['name'] ?? 'Administrateur', 'db' => $db,
]);
echo "Make4WebMail installed. Sign in as {$o['admin']}.\n";
