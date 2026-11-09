#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * The certificate of the mail apps service, by Let's Encrypt through Cloudflare's DNS (see Acme).
 * Started from Administration › Mail server ("set everything up"); also by hand:
 *   php bin/cert.php [--dns] [--ip=203.0.113.5]    --dns: the service's name put in the DNS first
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

$ip = '';
foreach ($argv as $a) {
    if (str_starts_with($a, '--ip=')) {
        $ip = substr($a, 5);
    }
}
@set_time_limit(0);
M4W\Service\Acme::setUp(in_array('--dns', $argv, true), $ip);
$s = M4W\Service\Acme::status();
$last = end($s['steps']);
echo date('c') . ' certificate: ' . ($last['text'] ?? '') . "\n";
exit(($last['state'] ?? '') === 'fail' ? 1 : 0);
