#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Local delivery agent for MTAs (Postfix "pipe", Exim, procmail…).
 *   php bin/deliver.php -f sender@example.com -- rcpt1@domain rcpt2@domain < message.eml
 * Exit codes follow sysexits.h: 0 OK, 67 unknown user, 75 temporary failure.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

$args = array_slice($argv, 1);
$from = '';
$rcpts = [];
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '-f' && isset($args[$i + 1])) {
        $from = $args[++$i];
    } elseif ($args[$i] !== '--') {
        $rcpts[] = $args[$i];
    }
}
if (!$rcpts) {
    fwrite(STDERR, "usage: deliver.php -f sender -- recipient...\n");
    exit(64);
}
$raw = stream_get_contents(STDIN);
try {
    $res = M4W\Service\Delivery::deliver((string) $raw, $from, $rcpts, 'pipe');
    M4W\Service\Transport::processQueue(20);
} catch (\Throwable $e) {
    fwrite(STDERR, 'delivery failed: ' . $e->getMessage() . "\n");
    exit(75);
}
$ok = array_filter($res, static fn($r) => $r === 'ok');
if (!$ok) {
    fwrite(STDERR, implode('; ', array_map(static fn($k, $v) => "$k: $v", array_keys($res), $res)) . "\n");
    exit(in_array('mailbox full', $res, true) ? 75 : 67);
}
exit(0);
