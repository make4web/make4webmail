#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Checks the server: PHP and extensions, writable folders, antivirus daemon, scheduled task.
 *   php bin/check.php            exit code 1 if something required is missing
 *   php bin/check.php --quiet    only the problems
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

use M4W\Core\Config;
use M4W\Core\Settings;
use M4W\Service\Antivirus;
use M4W\Service\Installer;

$quiet = in_array('--quiet', $argv, true);
$fail = 0;
$line = static function (string $state, string $label, string $note = '') use ($quiet): void {
    if ($quiet && $state === 'ok') {
        return;
    }
    $mark = ['ok' => "\033[32m ok \033[0m", 'warn' => "\033[33mwarn\033[0m", 'fail' => "\033[31mFAIL\033[0m"][$state];
    echo "[$mark] $label" . ($note !== '' ? " — $note" : '') . "\n";
};
foreach (Installer::requirements() as $label => $ok) {
    $line($ok ? 'ok' : 'fail', $label);
    $fail += $ok ? 0 : 1;
}
foreach (['intl' => 'dates and sorting by language', 'pcntl' => 'built-in SMTP daemon'] as $ext => $why) {
    $line(extension_loaded($ext) ? 'ok' : 'warn', "ext-$ext", extension_loaded($ext) ? '' : "recommended: $why");
}
if (Config::installed()) {
    $av = Antivirus::enabled();
    $up = $av && Antivirus::ping();
    $line(!$av ? 'warn' : ($up ? 'ok' : 'fail'), 'ClamAV (' . Antivirus::address() . ')', !$av ? 'disabled in Administration → Security' : ($up ? Antivirus::version() : 'daemon unreachable: attachments are not scanned'));
    $fail += $av && !$up ? 1 : 0;
    if ($up) {
        // End-to-end: the harmless EICAR test string must be reported.
        $r = Antivirus::scan('X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*');
        $line($r['status'] === 'infected' ? 'ok' : 'fail', 'ClamAV detects the EICAR test file', $r['signature']);
        $fail += $r['status'] === 'infected' ? 0 : 1;
    }
    $last = (int) Settings::get('system.cron_last', 0);
    $line($last > time() - 300 ? 'ok' : 'warn', 'Scheduled task (bin/cron.php)', $last ? 'last run ' . date('Y-m-d H:i', $last) : 'never run');
} else {
    $line('warn', 'Not installed yet', 'run bin/install.php or open /install');
}
exit($fail ? 1 : 0);
