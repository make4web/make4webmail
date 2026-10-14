<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Database as DB;
use M4W\Core\Settings;

/**
 * Online editing of office documents through an office server speaking WOPI
 * (Collabora Online, ONLYOFFICE Docs…). Make4WebMail is the WOPI host: it serves the
 * document, checks rights at every call, and stores each save as a new version.
 * Several people opening the same document edit it together in real time (the
 * office server joins them in one session).
 *
 * Access tokens are random, hashed at rest, tied to one document and one person,
 * and short-lived; rights are re-checked on every WOPI call.
 */
final class Office
{
    public const TOKEN_TTL = 10 * 3600;
    public const LOCK_TTL = 30 * 60;

    /** Formats offered for editing when the office server's discovery is not known yet. */
    private const DEFAULT_EDIT = ['docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp', 'doc', 'xls', 'ppt', 'txt', 'csv', 'rtf'];

    /** @var callable|null test hook: fn(string $url): ?string */
    public static $fetch = null;

    public static function url(): string
    {
        return rtrim((string) Settings::get('office.url', ''), '/');
    }

    public static function enabled(): bool
    {
        return self::url() !== '';
    }

    public static function origin(): string
    {
        $p = parse_url(self::url());
        return isset($p['scheme'], $p['host']) ? $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') : '';
    }

    // ------------------------------------------------------------ discovery

    /**
     * Actions of the office server per extension: ['docx' => ['edit' => urlsrc, 'view' => urlsrc], …].
     * Read from its /hosting/discovery, cached for a day.
     */
    public static function actions(bool $refresh = false): array
    {
        if (!self::enabled()) {
            return [];
        }
        $cache = json_decode((string) Settings::get('office.discovery', ''), true);
        if (!$refresh && is_array($cache) && ($cache['url'] ?? '') === self::url() && (int) ($cache['at'] ?? 0) > time() - 86400) {
            return $cache['map'] ?? [];
        }
        $xml = self::get(self::url() . '/hosting/discovery');
        $map = $xml !== null ? self::parseDiscovery($xml) : [];
        if ($map) {
            Settings::set('office.discovery', json_encode(['url' => self::url(), 'at' => time(), 'map' => $map]));
        } elseif (is_array($cache) && ($cache['url'] ?? '') === self::url()) {
            return $cache['map'] ?? []; // server briefly unreachable: keep what we knew
        }
        return $map;
    }

    public static function parseDiscovery(string $xml): array
    {
        $d = new \DOMDocument();
        if (!@$d->loadXML($xml, LIBXML_NONET)) {
            return [];
        }
        $map = [];
        foreach ($d->getElementsByTagName('action') as $a) {
            $ext = strtolower($a->getAttribute('ext'));
            $name = $a->getAttribute('name');
            $src = $a->getAttribute('urlsrc');
            if ($ext === '' || $src === '' || !preg_match('#^https?://#i', $src)) {
                continue;
            }
            if (in_array($name, ['edit', 'view'], true) && !isset($map[$ext][$name])) {
                $map[$ext][$name] = $src;
            }
        }
        return $map;
    }

    /** Extensions that can be edited online / only viewed. */
    public static function editable(): array
    {
        if (!self::enabled()) {
            return [];
        }
        $a = self::actions();
        return $a ? array_values(array_keys(array_filter($a, static fn($x) => isset($x['edit'])))) : self::DEFAULT_EDIT;
    }

    public static function viewable(): array
    {
        return self::enabled() ? array_values(array_keys(self::actions())) : [];
    }

    /** Test the configured server (administration). Returns '' when fine. */
    public static function test(): string
    {
        if (!self::enabled()) {
            return t('office.not_configured');
        }
        $map = self::actions(true);
        return $map ? '' : t('office.discovery_failed', ['url' => self::url() . '/hosting/discovery']);
    }

    private static function get(string $url): ?string
    {
        if (self::$fetch) {
            return (self::$fetch)($url);
        }
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return is_string($body) && $code === 200 ? $body : null;
    }

    /** Editor address for a document: the action's urlsrc with its placeholders filled and WOPISrc added. */
    public static function actionUrl(array $file, string $mode, string $wopiSrc, string $lang): ?string
    {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $a = self::actions()[$ext] ?? [];
        $src = $a[$mode] ?? ($a['view'] ?? $a['edit'] ?? null);
        if ($src === null) {
            return null;
        }
        // Placeholders look like <ui=UI_LLCC&> or <rs=DC_LLCC&>: fill the language ones, drop the rest.
        $src = preg_replace_callback('/<([a-z_]+)=([A-Z_]+)&?>/i', static function ($m) use ($lang) {
            return in_array($m[2], ['UI_LLCC', 'DC_LLCC'], true) ? $m[1] . '=' . $lang . '&' : '';
        }, $src) ?? $src;
        $sep = str_contains($src, '?') ? (str_ends_with($src, '?') || str_ends_with($src, '&') ? '' : '&') : '?';
        return $src . $sep . 'WOPISrc=' . rawurlencode($wopiSrc) . '&lang=' . rawurlencode($lang);
    }

    // --------------------------------------------------------------- tokens

    /** @return array{token:string,ttl:int} ttl in milliseconds since the epoch, as WOPI expects */
    public static function issueToken(array $user, array $file, bool $write): array
    {
        $token = bin2hex(random_bytes(32));
        $exp = time() + self::TOKEN_TTL;
        DB::insert('wopi_tokens', ['token_hash' => hash('sha256', $token), 'file_id' => (int) $file['id'], 'user_id' => (int) $user['id'],
            'can_write' => $write ? 1 : 0, 'expires_at' => $exp, 'created_at' => time()]);
        DB::run('DELETE FROM wopi_tokens WHERE expires_at < :t', ['t' => time()]);
        return ['token' => $token, 'ttl' => $exp * 1000];
    }

    /** Person and rights behind a token for this document, or null. Rights are re-read now. */
    public static function resolve(string $token, int $fileId): ?array
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        $t = DB::one('SELECT * FROM wopi_tokens WHERE token_hash = :h', ['h' => hash('sha256', $token)]);
        if (!$t || (int) $t['file_id'] !== $fileId || (int) $t['expires_at'] < time()) {
            return null;
        }
        $user = Users::find((int) $t['user_id']);
        $file = Files::file($fileId);
        if (!$user || $user['status'] !== 'active' || !$file) {
            return null;
        }
        Files::resetCache();
        $level = Files::filePermission($user, $file);
        if ($level < Files::READ) {
            return null;
        }
        return ['user' => $user, 'file' => $file, 'write' => (int) $t['can_write'] === 1 && $level >= Files::WRITE];
    }

    // ---------------------------------------------------------------- locks

    public static function lock(int $fileId): ?string
    {
        $l = DB::one('SELECT * FROM wopi_locks WHERE file_id = :f', ['f' => $fileId]);
        if ($l && (int) $l['expires_at'] < time()) {
            DB::delete('wopi_locks', 'file_id = :f', ['f' => $fileId]);
            return null;
        }
        return $l['lock_id'] ?? null;
    }

    public static function setLock(int $fileId, string $lockId): void
    {
        DB::delete('wopi_locks', 'file_id = :f', ['f' => $fileId]);
        DB::insert('wopi_locks', ['file_id' => $fileId, 'lock_id' => mb_substr($lockId, 0, 1024), 'expires_at' => time() + self::LOCK_TTL]);
    }

    public static function unlock(int $fileId): void
    {
        DB::delete('wopi_locks', 'file_id = :f', ['f' => $fileId]);
    }

    public static function modifiedIso(array $file): string
    {
        return gmdate('Y-m-d\TH:i:s.000\Z', (int) $file['updated_at']);
    }
}
