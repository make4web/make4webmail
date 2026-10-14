#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Disposable sender list.
 *   php bin/disposable.php --update            download the current list
 *   php bin/disposable.php address@domain …    tell how each address is treated
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

use M4W\Service\Senders;

$args = array_slice($argv, 1);
if (!$args) {
    fwrite(STDERR, "usage: disposable.php --update | address...\n");
    exit(64);
}
if ($args === ['--update']) {
    try {
        echo Senders::update() . " domains\n";
        exit(0);
    } catch (\Throwable $e) {
        fwrite(STDERR, 'update failed: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
foreach ($args as $a) {
    echo $a . ': ' . (Senders::isDisposable($a) ? 'disposable (' . Senders::inboundAction() . ')' : (Senders::isNoReply($a) ? 'no-reply (never answered automatically)' : 'normal')) . "\n";
}
