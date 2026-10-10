#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * User management from the command line.
 *   php bin/user.php create jane@example.com 'Password!123' [--admin] [--name="Jane Doe"]
 *   php bin/user.php password jane@example.com 'NewPassword!1'
 *   php bin/user.php disable|enable|delete jane@example.com
 *   php bin/user.php list
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

use M4W\Service\Users;

$cmd = $argv[1] ?? 'help';
$email = strtolower($argv[2] ?? '');
$named = getopt('', ['admin', 'name::']);
switch ($cmd) {
    case 'create':
        $parts = preg_split('/\s+/', (string) ($named['name'] ?? ''), 2) ?: [];
        $id = Users::create(['email' => $email, 'password' => $argv[3] ?? bin2hex(random_bytes(8)), 'role' => isset($named['admin']) ? 'admin' : 'user',
            'display_name' => $named['name'] ?? '', 'first_name' => $parts[0] ?? '', 'last_name' => $parts[1] ?? '']);
        echo "created #$id\n";
        break;
    case 'password':
        $u = Users::findByEmail($email) ?? exit("unknown user\n");
        Users::update((int) $u['id'], ['password' => $argv[3]]);
        echo "password updated\n";
        break;
    case 'enable':
    case 'disable':
        $u = Users::findByEmail($email) ?? exit("unknown user\n");
        Users::update((int) $u['id'], ['status' => $cmd === 'enable' ? 'active' : 'disabled']);
        echo "$cmd: ok\n";
        break;
    case 'delete':
        $u = Users::findByEmail($email) ?? exit("unknown user\n");
        Users::delete((int) $u['id']);
        echo "deleted\n";
        break;
    case 'list':
        foreach (M4W\Core\Database::all('SELECT email, role, status FROM users ORDER BY email') as $u) {
            printf("%-40s %-6s %s\n", $u['email'], $u['role'], $u['status']);
        }
        break;
    default:
        echo "usage: user.php create|password|enable|disable|delete|list ...\n";
}
