<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Audit;
use M4W\Core\Config;
use M4W\Core\Settings;

/**
 * Update of the application from its GitHub repository, started by the super administrator
 * (never on its own). The repository is private: access goes through the owner's GitHub account
 * with a personal access token (GitHub no longer accepts passwords for this).
 *
 * The release is downloaded whole (zip of the branch), checked, then only the application's own
 * files are copied: app/, bin/, public/, deploy/ and a few files at the root. Tests, samples,
 * .git* files and anything in storage/ or config/config.php are never touched. Before copying,
 * the files about to change are saved in storage/backups/updates/, so the previous version can
 * be put back. Database migrations run by themselves on the next page.
 */
final class Updater
{
    public const REPO = 'make4web/make4webmail';

    /** What a release brings: paths copied (prefixes ending with / are folders). */
    public const INCLUDE = ['app/', 'bin/', 'public/', 'deploy/', 'config/config.example.php', 'config/.htaccess', '.htaccess', 'README.md'];

    /** Never copied, wherever they are (development and test material). */
    private const EXCLUDE = ['#(^|/)\.git#', '#(^|/)\.github/#', '#(^|/)\.claude/#', '#^tests/#', '#^storage/#', '#^config/config\.php$#',
        '#(^|/)(samples?|examples?)/#i', '#\.sample(\.\w+)?$#i', '#^bin/social-icons\.js$#', '#(^|/)CLAUDE\.md$#', '#^readme\.m4w$#', '#(^|/)\.DS_Store$#'];

    /** Tests: the API address and the folder updated. */
    public static string $api = 'https://api.github.com';
    public static ?string $root = null;

    private static function root(): string
    {
        return rtrim(self::$root ?? M4W_ROOT, '/');
    }

    public static function branch(): string
    {
        $b = (string) Settings::get('update.branch', 'main');
        return preg_match('#^[\w./-]{1,100}$#', $b) ? $b : 'main';
    }

    public static function connected(): bool
    {
        return Settings::secret('update.token') !== '';
    }

    /** Whether a path of the repository is part of what is installed. */
    public static function included(string $path): bool
    {
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/') || str_ends_with($path, '/')) {
            return false;
        }
        foreach (self::EXCLUDE as $re) {
            if (preg_match($re, $path)) {
                return false;
            }
        }
        foreach (self::INCLUDE as $inc) {
            if (str_ends_with($inc, '/') ? str_starts_with($path, $inc) : $path === $inc) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------ GitHub

    /** @return array{0:int,1:string,2:string} status, body, Location header */
    private static function http(string $url, ?string $token, string $accept = 'application/vnd.github+json', $sink = null): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException(t('upd.no_curl'));
        }
        $location = '';
        $ch = curl_init($url);
        $proxy = trim((string) Config::get('outbound_proxy', ''));
        $headers = ['Accept: ' . $accept, 'X-GitHub-Api-Version: 2022-11-28'];
        if ($token !== null && $token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        curl_setopt_array($ch, ($proxy !== '' ? [CURLOPT_PROXY => $proxy] : []) + [
            CURLOPT_RETURNTRANSFER => $sink === null, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => $sink === null ? 30 : 300, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_PROTOCOLS => str_starts_with($url, 'http://127.0.0.1') ? CURLPROTO_HTTP | CURLPROTO_HTTPS : CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Make4WebMail/' . M4W_VERSION, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($c, string $h) use (&$location) {
                if (stripos($h, 'Location:') === 0) {
                    $location = trim(substr($h, 9));
                }
                return strlen($h);
            },
        ] + ($sink !== null ? [CURLOPT_FILE => $sink] : []));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException(t('upd.unreachable', ['error' => $err]));
        }
        return [$code, is_string($body) ? $body : '', $location];
    }

    private static function api(string $path, ?string $token = null, string $accept = 'application/vnd.github+json'): string
    {
        [$code, $body] = self::http(self::$api . $path, $token ?? Settings::secret('update.token'), $accept);
        if ($code === 401) {
            throw new \RuntimeException(t('upd.bad_token'));
        }
        if ($code === 403 || $code === 404) {
            throw new \RuntimeException(t('upd.no_access', ['repo' => self::REPO]));
        }
        if ($code !== 200) {
            throw new \RuntimeException(t('upd.http_error', ['code' => $code]));
        }
        return $body;
    }

    /** The account and its token are checked against the repository before being kept. */
    public static function connect(string $login, string $token): array
    {
        $token = trim($token);
        if (!preg_match('/^[A-Za-z0-9_]{20,255}$/', $token)) {
            throw new \InvalidArgumentException(t('upd.token_format'));
        }
        $user = json_decode(self::api('/user', $token), true);
        $repo = json_decode(self::api('/repos/' . self::REPO, $token), true);
        $name = (string) ($user['login'] ?? '');
        if ($login !== '' && strcasecmp($login, $name) !== 0) {
            throw new \InvalidArgumentException(t('upd.login_mismatch', ['login' => $name]));
        }
        Settings::setSecret('update.token', $token);
        Settings::set('update.login', $name);
        if (!empty($repo['default_branch']) && Settings::get('update.branch', '') === '') {
            Settings::set('update.branch', (string) $repo['default_branch']);
        }
        Audit::log('update.connected', $name);
        return ['login' => $name, 'repo' => (string) ($repo['full_name'] ?? self::REPO)];
    }

    public static function disconnect(): void
    {
        Settings::setSecret('update.token', '');
        Settings::set('update.login', '');
        Audit::log('update.disconnected');
    }

    /** The version of a bootstrap.php. */
    public static function versionOf(string $bootstrap): string
    {
        return preg_match("/define\\('M4W_VERSION',\\s*'([^']+)'\\)/", $bootstrap, $m) ? $m[1] : '';
    }

    /** Installed version, the one waiting in the repository, and the latest changes. */
    public static function status(): array
    {
        $branch = self::branch();
        $commits = json_decode(self::api('/repos/' . self::REPO . '/commits?sha=' . rawurlencode($branch) . '&per_page=10'), true);
        if (!is_array($commits) || !$commits) {
            throw new \RuntimeException(t('upd.no_commits', ['branch' => $branch]));
        }
        $sha = (string) $commits[0]['sha'];
        $remote = self::versionOf(self::api('/repos/' . self::REPO . '/contents/app/bootstrap.php?ref=' . $sha, null, 'application/vnd.github.raw'));
        return [
            'local' => M4W_VERSION, 'remote' => $remote, 'sha' => $sha, 'branch' => $branch,
            'newer' => $remote !== '' && self::compare($remote, M4W_VERSION) > 0,
            'commits' => array_map(static fn($c) => [
                'sha' => substr((string) $c['sha'], 0, 7), 'message' => strtok((string) ($c['commit']['message'] ?? ''), "\n"),
                'date' => strtotime((string) ($c['commit']['committer']['date'] ?? '')) ?: 0, 'author' => (string) ($c['commit']['author']['name'] ?? ''),
            ], $commits),
            'at' => time(),
        ];
    }

    /** Versions "1.1.2026.1017.3": compared part by part, as numbers. */
    public static function compare(string $a, string $b): int
    {
        $pa = array_map('intval', explode('.', $a));
        $pb = array_map('intval', explode('.', $b));
        for ($i = 0, $n = max(count($pa), count($pb)); $i < $n; $i++) {
            if (($pa[$i] ?? 0) !== ($pb[$i] ?? 0)) {
                return ($pa[$i] ?? 0) <=> ($pb[$i] ?? 0);
            }
        }
        return 0;
    }

    // ------------------------------------------------------------------ update

    private static function backupDir(): string
    {
        $d = Config::storagePath('backups/updates');
        if (!is_dir($d)) {
            mkdir($d, 0750, true);
        }
        return $d;
    }

    /** Files installed by the last update (those a later one may remove). */
    private static function manifest(): array
    {
        $m = json_decode((string) @file_get_contents(Config::storagePath('update-manifest.json')), true);
        return is_array($m) ? $m : [];
    }

    /**
     * Download, check and install the release at the head of the branch.
     * @return array{from:string,to:string,sha:string,written:int,removed:int,backup:string}
     */
    public static function apply(?string $sha = null): array
    {
        @set_time_limit(600);
        $lock = fopen(Config::storagePath('tmp') . '/update.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException(t('upd.running'));
        }
        $zipFile = Config::storagePath('tmp/update-' . bin2hex(random_bytes(4)) . '.zip');
        try {
            $sha ??= self::status()['sha'];
            if (!preg_match('/^[0-9a-f]{7,40}$/', $sha)) {
                throw new \RuntimeException(t('upd.http_error', ['code' => 0]));
            }
            // 1. The release, whole (GitHub answers with a short-lived download address).
            [$code, , $location] = self::http(self::$api . '/repos/' . self::REPO . '/zipball/' . $sha, Settings::secret('update.token'));
            if ($code !== 302 || $location === '') {
                throw new \RuntimeException($code === 401 ? t('upd.bad_token') : t('upd.http_error', ['code' => $code]));
            }
            $out = fopen($zipFile, 'wb');
            [$code] = self::http($location, null, 'application/zip', $out);
            fclose($out);
            if ($code !== 200) {
                throw new \RuntimeException(t('upd.http_error', ['code' => $code]));
            }
            $zip = new \ZipArchive();
            if ($zip->open($zipFile) !== true) {
                throw new \RuntimeException(t('upd.bad_archive'));
            }
            // 2. What it holds: the application's files only.
            $files = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $rel = (string) preg_replace('#^[^/]+/#', '', $name); // "make4web-make4webmail-<sha>/…"
                if (self::included($rel)) {
                    $files[$rel] = $i;
                }
            }
            $boot = isset($files['app/bootstrap.php']) ? (string) $zip->getFromIndex($files['app/bootstrap.php']) : '';
            $to = self::versionOf($boot);
            if ($to === '' || !isset($files['public/index.php'], $files['app/Core/App.php'])) {
                throw new \RuntimeException(t('upd.bad_archive'));
            }
            // 3. Everything must be writable before anything is changed.
            $root = self::root();
            $denied = [];
            foreach (array_keys($files) as $rel) {
                $target = $root . '/' . $rel;
                $dir = dirname($target);
                while (!is_dir($dir) && $dir !== $root) {
                    $dir = dirname($dir);
                }
                if ((file_exists($target) && !is_writable($target)) || !is_writable($dir)) {
                    $denied[] = $rel;
                }
            }
            if ($denied) {
                $who = function_exists('posix_geteuid') && ($pw = @posix_getpwuid(posix_geteuid())) ? (string) $pw['name'] : 'www-data';
                throw new \RuntimeException(t('upd.not_writable', ['files' => implode(', ', array_slice($denied, 0, 5)) . (count($denied) > 5 ? '…' : ''), 'user' => $who, 'root' => $root]));
            }
            // 4. What disappears: files of the previous update the new one no longer has.
            $gone = array_values(array_filter(self::manifest(), static fn($rel) => !isset($files[$rel]) && self::included($rel) && is_file($root . '/' . $rel)));
            // 5. Saved first: the files about to change or go, and the list of the new ones.
            $from = M4W_VERSION;
            $backup = self::backupDir() . '/m4w-' . $from . '-' . date('Ymd-His') . '.zip';
            $bz = new \ZipArchive();
            if ($bz->open($backup, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException(t('upd.backup_failed'));
            }
            $created = [];
            $changed = [];
            foreach ($files as $rel => $i) {
                $target = $root . '/' . $rel;
                if (!is_file($target)) {
                    $created[] = $rel;
                    continue;
                }
                $stat = $zip->statIndex($i);
                if ($stat && (int) $stat['size'] === filesize($target) && hash_file('crc32b', $target) === sprintf('%08x', $stat['crc'])) {
                    continue; // the same file: left as it is
                }
                $changed[$rel] = $i;
                $bz->addFile($target, 'files/' . $rel);
            }
            foreach ($gone as $rel) {
                $bz->addFile($root . '/' . $rel, 'files/' . $rel);
            }
            $bz->addFromString('m4w-update.json', (string) json_encode(['from' => $from, 'to' => $to, 'sha' => $sha, 'at' => time(), 'created' => $created, 'manifest' => self::manifest()]));
            if (!$bz->close()) {
                throw new \RuntimeException(t('upd.backup_failed'));
            }
            // 6. Copied, each file replaced at once (written aside, then renamed).
            $written = 0;
            foreach ($changed + array_intersect_key($files, array_flip($created)) as $rel => $i) {
                $target = $root . '/' . $rel;
                if (!is_dir(dirname($target))) {
                    mkdir(dirname($target), 0755, true);
                }
                $tmp = $target . '.m4w-new';
                if (file_put_contents($tmp, (string) $zip->getFromIndex($i)) === false || !rename($tmp, $target)) {
                    @unlink($tmp);
                    throw new \RuntimeException(t('upd.write_failed', ['file' => $rel, 'backup' => basename($backup)]));
                }
                @chmod($target, str_starts_with($rel, 'bin/') || str_ends_with($rel, '.sh') ? 0755 : 0644);
                $written++;
            }
            foreach ($gone as $rel) {
                @unlink($root . '/' . $rel);
            }
            $zip->close();
            file_put_contents(Config::storagePath('update-manifest.json'), json_encode(array_keys($files)), LOCK_EX);
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            $entry = ['from' => $from, 'to' => $to, 'sha' => $sha, 'at' => time(), 'written' => $written, 'removed' => count($gone), 'backup' => basename($backup)];
            self::remember($entry);
            Audit::log('update.applied', $from . ' → ' . $to, ['sha' => substr($sha, 0, 7), 'files' => $written]);
            self::prune();
            return $entry;
        } finally {
            @unlink($zipFile);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Puts back the version saved before the last update. */
    public static function rollback(): array
    {
        $last = self::history()[0] ?? null;
        if (!$last || ($last['rolled_back'] ?? false)) {
            throw new \RuntimeException(t('upd.nothing_to_undo'));
        }
        $file = self::backupDir() . '/' . basename((string) $last['backup']);
        $bz = new \ZipArchive();
        if (!is_file($file) || $bz->open($file) !== true) {
            throw new \RuntimeException(t('upd.backup_missing'));
        }
        $meta = json_decode((string) $bz->getFromName('m4w-update.json'), true) ?: [];
        $root = self::root();
        $restored = 0;
        for ($i = 0; $i < $bz->numFiles; $i++) {
            $name = (string) $bz->getNameIndex($i);
            if (!str_starts_with($name, 'files/') || !self::included($rel = substr($name, 6))) {
                continue;
            }
            $target = $root . '/' . $rel;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }
            file_put_contents($target . '.m4w-new', (string) $bz->getFromIndex($i));
            rename($target . '.m4w-new', $target);
            $restored++;
        }
        foreach ((array) ($meta['created'] ?? []) as $rel) {
            if (self::included((string) $rel)) {
                @unlink($root . '/' . $rel);
            }
        }
        $bz->close();
        file_put_contents(Config::storagePath('update-manifest.json'), json_encode((array) ($meta['manifest'] ?? [])), LOCK_EX);
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        $h = self::history();
        $h[0]['rolled_back'] = time();
        Settings::set('update.history', json_encode($h));
        Audit::log('update.rolled_back', ($meta['to'] ?? '?') . ' → ' . ($meta['from'] ?? '?'));
        return ['to' => (string) ($meta['from'] ?? ''), 'restored' => $restored];
    }

    public static function history(): array
    {
        $h = json_decode((string) Settings::get('update.history', ''), true);
        return is_array($h) ? $h : [];
    }

    private static function remember(array $entry): void
    {
        Settings::set('update.history', json_encode(array_slice(array_merge([$entry], self::history()), 0, 20)));
    }

    /** The five latest saved versions are kept. */
    private static function prune(): void
    {
        $all = glob(self::backupDir() . '/m4w-*.zip') ?: [];
        usort($all, static fn($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($all, 5) as $old) {
            @unlink($old);
        }
    }
}
