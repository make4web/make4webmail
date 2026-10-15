#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Restore a Make4WebMail backup (zip made by bin/backup.php or the administration).
 *
 *   php bin/restore.php m4w-backup-2026-10-15-030000.zip [--password=…] [--config] --yes
 *
 * Replaces the database and the storage folder (mail, queue, uploads, lists, files when
 * stored on disk) by the backup's. The current configuration is kept unless --config is
 * given or there is none yet (new server): the backup's config/config.php holds the key
 * that decrypts the stored secrets, so a new server needs it.
 * Without --yes, only shows what would be restored. Run it as the web server user
 * (sudo -u www-data php bin/restore.php …), the web site stopped or in maintenance.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

use M4W\Core\Config;
use M4W\Core\Database as DB;

$file = '';
$password = null;
$yes = false;
$withConfig = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--yes') {
        $yes = true;
    } elseif ($a === '--config') {
        $withConfig = true;
    } elseif (str_starts_with($a, '--password=')) {
        $password = substr($a, 11);
    } elseif ($a !== '' && $a[0] !== '-') {
        $file = $a;
    }
}
$fail = static function (string $m): never {
    fwrite(STDERR, $m . "\n");
    exit(1);
};
if ($file === '' || !is_file($file)) {
    $fail('usage: restore.php <backup.zip> [--password=…] [--config] --yes');
}
$zip = new ZipArchive();
if ($zip->open($file, ZipArchive::RDONLY) !== true) {
    $fail('Not a zip file: ' . $file);
}
if ($password !== null) {
    $zip->setPassword($password);
}
$manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
if (!is_array($manifest) || ($manifest['application'] ?? '') !== 'Make4WebMail') {
    $fail($zip->getFromName('LISEZMOI.txt') === false && $password === null
        ? 'Cannot read the backup: wrong file, or encrypted (add --password=…).'
        : 'Cannot read the backup manifest (wrong password?).');
}
$names = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $n = (string) $zip->getNameIndex($i);
    if (str_contains($n, '..') || str_starts_with($n, '/') || str_contains($n, '\\')) {
        $fail('Refused: unsafe path in the archive (' . $n . ')');
    }
    $names[] = $n;
}
$hasConfig = Config::installed();
$useConfig = in_array('config/config.php', $names, true) && ($withConfig || !$hasConfig);
$count = static fn(string $prefix) => count(array_filter($names, static fn($n) => str_starts_with($n, $prefix)));

echo "Backup of {$manifest['created_at']} (Make4WebMail {$manifest['version']}, {$manifest['database']} database, host {$manifest['host']})\n";
echo '  database        ' . ($count('database/') ? 'yes' : 'MISSING') . "\n";
echo '  storage         ' . $count('storage/') . " files\n";
echo '  configuration   ' . ($useConfig ? 'restored' : ($hasConfig ? 'kept (current one; --config to restore it)' : 'missing')) . "\n";
echo '  file space      ' . $count('fichiers/') . " files (readable copy, nothing to restore: the database and storage hold them)\n";
if (!$yes) {
    echo "\nNothing changed. Add --yes to restore (the current data is replaced).\n";
    exit(0);
}

$work = sys_get_temp_dir() . '/m4w-restore-' . bin2hex(random_bytes(4));
mkdir($work, 0700, true);
$extract = static function (array $entries) use ($zip, $work, $fail): void {
    if ($entries && !$zip->extractTo($work, $entries)) {
        $fail('Extraction failed: ' . $zip->getStatusString());
    }
};
$copyTree = static function (string $from, string $to) use (&$copyTree): int {
    $n = 0;
    @mkdir($to, 0750, true);
    foreach (scandir($from) ?: [] as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        if (is_dir("$from/$f")) {
            $n += $copyTree("$from/$f", "$to/$f");
        } else {
            copy("$from/$f", "$to/$f");
            $n++;
        }
    }
    return $n;
};

// 1. Configuration (new server, or asked).
if ($useConfig) {
    $extract(['config/config.php']);
    if (is_file(Config::path())) {
        copy(Config::path(), Config::path() . '.before-restore');
    }
    @mkdir(dirname(Config::path()), 0750, true);
    copy("$work/config/config.php", Config::path());
    chmod(Config::path(), 0640);
    Config::load();
    DB::reset();
    echo "Configuration restored (" . Config::path() . ")\n";
}

// 2. Database.
$db = (array) Config::get('db', []);
if (($db['driver'] ?? 'sqlite') === 'sqlite') {
    if (!in_array('database/m4w.sqlite', $names, true)) {
        $fail('This backup holds a MySQL dump: configure MySQL first (config/config.php), then run again.');
    }
    $extract(['database/m4w.sqlite']);
    $path = (string) $db['path'];
    DB::reset();
    if (is_file($path)) {
        rename($path, $path . '.before-restore');
    }
    @mkdir(dirname($path), 0750, true);
    copy("$work/database/m4w.sqlite", $path);
    @unlink($path . '-wal');
    @unlink($path . '-shm');
    echo "SQLite database restored ($path)\n";
} else {
    if (!in_array('database/m4w.sql', $names, true)) {
        $fail('This backup holds a SQLite database: set "db" to sqlite in config/config.php, then run again.');
    }
    $extract(['database/m4w.sql']);
    $pdo = DB::pdo();
    // Statement by statement, quotes respected (mail bodies may contain ";" and new lines).
    $in = fopen("$work/database/m4w.sql", 'rb');
    $stmt = '';
    $quote = false;
    $escape = false;
    $n = 0;
    while (($chunk = fread($in, 1 << 20)) !== false && $chunk !== '') {
        $len = strlen($chunk);
        $start = 0;
        for ($i = 0; $i < $len; $i++) {
            $c = $chunk[$i];
            if ($escape) {
                $escape = false;
            } elseif ($quote && $c === '\\') {
                $escape = true;
            } elseif ($c === "'") {
                $quote = !$quote;
            } elseif (!$quote && $c === ';') {
                $stmt .= substr($chunk, $start, $i - $start);
                $start = $i + 1;
                $sql = trim(preg_replace('/^(\s*--[^\n]*\n)+/', '', $stmt) ?? $stmt);
                if ($sql !== '') {
                    $pdo->exec($sql);
                    $n++;
                }
                $stmt = '';
            }
        }
        $stmt .= substr($chunk, $start);
    }
    fclose($in);
    echo "MySQL database restored ($n statements)\n";
}

// 3. Storage (mail, queue, uploads, lists…), and files stored on disk elsewhere.
$storage = array_values(array_filter($names, static fn($n) => str_starts_with($n, 'storage/') || str_starts_with($n, 'storage-files/')));
foreach (array_chunk($storage, 500) as $part) {
    $extract($part);
}
if (is_dir("$work/storage")) {
    echo 'Storage restored: ' . $copyTree("$work/storage", Config::storagePath()) . " files (" . Config::storagePath() . ")\n";
}
if (is_dir("$work/storage-files")) {
    echo 'Files restored: ' . $copyTree("$work/storage-files", (string) Config::get('files.path')) . " files\n";
}

$rm = static function (string $d) use (&$rm): void {
    foreach (scandir($d) ?: [] as $f) {
        if ($f !== '.' && $f !== '..') {
            is_dir("$d/$f") ? $rm("$d/$f") : unlink("$d/$f");
        }
    }
    rmdir($d);
};
$rm($work);
echo "\nDone. Check the server with: php bin/check.php\n";
