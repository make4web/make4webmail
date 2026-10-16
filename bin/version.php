<?php
declare(strict_types=1);

/**
 * Version of Make4WebMail: major.minor.year.MMDD.counter, e.g. 1.1.2026.1016.3 is the third
 * version published on 16 October 2026.
 *
 *   php bin/version.php            show the current version
 *   php bin/version.php bump       next version: same day, counter + 1; new day, counter 1
 *   php bin/version.php minor      next minor (counter of the day kept going)
 *   php bin/version.php major      next major (minor back to 0)
 *
 * The day is taken in Europe/Paris (TZ=… to change it). Every push carries a new version.
 */

$file = dirname(__DIR__) . '/app/bootstrap.php';
$src = (string) file_get_contents($file);
if (!preg_match("/define\\('M4W_VERSION', '([^']*)'\\);/", $src, $m)) {
    fwrite(STDERR, "M4W_VERSION not found in app/bootstrap.php\n");
    exit(1);
}
$current = $m[1];
$what = $argv[1] ?? '';
if ($what === '') {
    echo $current, "\n";
    exit(0);
}
if (!in_array($what, ['bump', 'minor', 'major'], true)) {
    fwrite(STDERR, "usage: php bin/version.php [bump|minor|major]\n");
    exit(1);
}

$tz = new DateTimeZone(getenv('TZ') ?: 'Europe/Paris');
$now = new DateTimeImmutable('now', $tz);
$year = $now->format('Y');
$day = $now->format('md');

// Older numbering (1.0.0): major and minor kept, the date part starts today.
$p = explode('.', $current);
$major = (int) ($p[0] ?? 1);
$minor = (int) ($p[1] ?? 0);
$sameDay = count($p) === 5 && $p[2] === $year && $p[3] === $day;
$counter = $sameDay ? (int) $p[4] + 1 : 1;
if ($what === 'minor') {
    $minor++;
} elseif ($what === 'major') {
    $major++;
    $minor = 0;
}
$next = "$major.$minor.$year.$day.$counter";

$src = str_replace("define('M4W_VERSION', '$current');", "define('M4W_VERSION', '$next');", $src);
file_put_contents($file, $src);
echo "$current -> $next\n";
