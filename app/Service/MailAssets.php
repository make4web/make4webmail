<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Database as DB;
use M4W\Core\Net;
use M4W\Core\Settings;
use M4W\Storage\Storage;

/**
 * Pictures of received messages, served by the webmail itself.
 *
 * Remote images are fetched once by the server (soon after delivery, never from the
 * reader's browser), so opening a message reveals nothing to the sender: no IP, no
 * opening time, no "read" signal. Every picture — remote, embedded (cid:) or attached
 * and shown inline — is decoded and re-encoded with GD: size capped, metadata (EXIF,
 * XMP, comments) and anything hidden after the pixels dropped, so only plain pixels
 * reach the browser. Tracking pixels (1×1 and similar) are recognised and removed.
 *
 * Storage is by content checksum: the same picture received a thousand times is kept
 * once; each message holds a reference, and a picture no message references any more
 * is deleted.
 */
final class MailAssets
{
    private const MAX_DOWNLOAD = 6 * 1024 * 1024;
    private const MAX_PIXELS = 40_000_000;          // decompression bombs
    private const MAX_HEIGHT = 4000;                // very long images are cropped
    private const MAX_PER_MESSAGE = 60;

    /** @var callable|null test hook: fn(string $url): ?array{status:int,type:string,body:string,location:string} */
    public static $http = null;

    public static function enabled(): bool
    {
        return (int) Settings::get('security.image_proxy', 1) === 1 && function_exists('imagecreatefromstring');
    }

    // ------------------------------------------------------------- register

    /** Remote picture URLs of an HTML body (img src/srcset, background attribute). @return string[] */
    public static function remoteUrls(string $html): array
    {
        if ($html === '' || !preg_match_all('#<(?:img|image|input|td|table|body)\b[^>]*?\b(?:src|background)\s*=\s*(["\']?)(https?:)?//([^"\'\s>]+)\1#i', $html, $m, PREG_SET_ORDER)) {
            return [];
        }
        $out = [];
        foreach ($m as $x) {
            $url = html_entity_decode(($x[2] ?: 'https:') . '//' . $x[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (strlen($url) <= 2048) {
                $out[$url] = true;
            }
        }
        return array_slice(array_keys($out), 0, self::MAX_PER_MESSAGE);
    }

    /** Note the remote pictures of a delivered message; the scheduler fetches them. */
    public static function register(int $messageId, int $userId, string $html): int
    {
        if (!self::enabled()) {
            return 0;
        }
        $n = 0;
        foreach (self::remoteUrls($html) as $url) {
            try {
                DB::insert('mail_asset_refs', ['message_id' => $messageId, 'user_id' => $userId, 'ref_key' => self::key($url), 'url' => $url, 'status' => 'pending', 'created_at' => time()]);
                $n++;
            } catch (\PDOException) {
                // already noted
            }
        }
        return $n;
    }

    public static function key(string $url): string
    {
        return hash('sha256', $url);
    }

    // --------------------------------------------------------------- fetch

    /** Scheduler (and first opening): fetch pending pictures. Returns the number processed. */
    public static function processPending(int $max = 200, ?int $messageId = null, float $budget = 50.0): int
    {
        $start = microtime(true);
        $sql = "SELECT * FROM mail_asset_refs WHERE status = 'pending'" . ($messageId ? ' AND message_id = :m' : '') . ' ORDER BY id LIMIT ' . max(1, $max);
        $n = 0;
        $cache = [];
        foreach (DB::all($sql, $messageId ? ['m' => $messageId] : []) as $r) {
            if (microtime(true) - $start > $budget) {
                break;
            }
            $url = (string) $r['url'];
            $res = $cache[$url] ??= self::fetchAndStore($url);
            DB::update('mail_asset_refs', ['status' => $res['status'], 'asset_id' => $res['asset'], 'tries' => (int) $r['tries'] + 1], 'id = :id', ['id' => $r['id']]);
            $n++;
        }
        return $n;
    }

    /** @return array{status:string,asset:?int} ok | tracker | failed */
    private static function fetchAndStore(string $url): array
    {
        $bytes = self::download($url);
        if ($bytes === null) {
            return ['status' => 'failed', 'asset' => null];
        }
        $a = self::storeBytes($bytes);
        if (!$a) {
            return ['status' => 'failed', 'asset' => null];
        }
        return ['status' => $a['kind'] === 'tracker' ? 'tracker' : 'ok', 'asset' => (int) $a['id']];
    }

    /** Download with the protections of the rest of the application: public addresses only, pinned, size and time capped. */
    private static function download(string $url): ?string
    {
        for ($hop = 0; $hop < 4; $hop++) {
            if (!preg_match('#^(https?)://([a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?)(?::(\d{1,5}))?(/[^\s"<>\\\\]*)?$#i', $url, $u)) {
                return null;
            }
            if (self::$http) {
                $res = (self::$http)($url);
            } else {
                try {
                    $ip = Net::publicIp($u[2]);
                } catch (\InvalidArgumentException) {
                    return null;
                }
                $res = self::curl($url, strtolower($u[1]), $u[2], (int) ($u[3] ?? '') ?: (strtolower($u[1]) === 'https' ? 443 : 80), $ip);
            }
            if (!$res) {
                return null;
            }
            if (in_array($res['status'], [301, 302, 303, 307, 308], true) && $res['location'] !== '') {
                $loc = $res['location'];
                $url = preg_match('#^https?://#i', $loc) ? $loc : ($u[1] . '://' . $u[2] . (isset($u[3]) && $u[3] !== '' ? ':' . $u[3] : '') . '/' . ltrim($loc, '/'));
                continue;
            }
            return $res['status'] === 200 && strlen($res['body']) > 0 ? $res['body'] : null;
        }
        return null;
    }

    private static function curl(string $url, string $scheme, string $host, int $port, string $ip): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $pinned = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        // An outbound proxy is used only when the administrator configured one; otherwise
        // proxies from the environment are ignored and the connection is pinned to the
        // address validated above (no DNS rebinding).
        $proxy = trim((string) \M4W\Core\Config::get('outbound_proxy', ''));
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, $proxy !== '' ? [CURLOPT_PROXY => $proxy] : [
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $pinned],
            CURLOPT_CONNECT_TO => ['::' . $pinned . ':' . $port],
        ]);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false, CURLOPT_HEADER => false, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; MailImageProxy/1.0)',
            CURLOPT_HTTPHEADER => ['Accept: image/*'],
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body): int {
                $body .= $chunk;
                return strlen($body) > self::MAX_DOWNLOAD ? 0 : strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $used = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        curl_close($ch);
        if ($proxy === '' && $used !== '' && $used !== $ip) {
            return null;
        }
        return ['status' => $status, 'type' => '', 'body' => strlen($body) > self::MAX_DOWNLOAD ? '' : $body, 'location' => $location];
    }

    // --------------------------------------------------------------- clean

    /**
     * Decode and re-encode a picture. Returns null when the bytes are not a picture GD can
     * read (SVG, scripts, polyglots…), kind "tracker" for 1×1-like images.
     * @return ?array{bytes:string,mime:string,width:int,height:int,kind:string}
     */
    public static function clean(string $bytes): ?array
    {
        $info = @getimagesizefromstring($bytes);
        if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            return null;
        }
        [$w, $h] = $info;
        if ($w * $h <= 9 || ($w <= 3 || $h <= 3) && $w * $h <= 64) {
            return ['bytes' => '', 'mime' => '', 'width' => $w, 'height' => $h, 'kind' => 'tracker'];
        }
        $src = @imagecreatefromstring($bytes);
        if (!$src) {
            return null;
        }
        $max = max(200, (int) Settings::get('security.image_max_px', 1600));
        $scale = min(1.0, $max / $w);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $cropH = min($nh, self::MAX_HEIGHT);
        $alpha = self::hasAlpha($src, $info[2]);
        $dst = imagecreatetruecolor($nw, $cropH);
        if ($alpha) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        } else {
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        }
        // Only pixels are copied: whatever else the file carried stays behind.
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        if ($alpha) {
            imagepng($dst, null, 6);
            $mime = 'image/png';
        } else {
            imagejpeg($dst, null, 85);
            $mime = 'image/jpeg';
        }
        $out = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);
        return $out === '' ? null : ['bytes' => $out, 'mime' => $mime, 'width' => $nw, 'height' => $cropH, 'kind' => 'image'];
    }

    private static function hasAlpha(\GdImage $img, int $type): bool
    {
        if (!in_array($type, [IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
            return false;
        }
        if (!imageistruecolor($img)) {
            return imagecolortransparent($img) >= 0;
        }
        // Sample the picture: fully opaque PNGs become (smaller) JPEGs.
        $w = imagesx($img);
        $h = imagesy($img);
        $step = max(1, (int) (max($w, $h) / 64));
        for ($y = 0; $y < $h; $y += $step) {
            for ($x = 0; $x < $w; $x += $step) {
                if (((imagecolorat($img, $x, $y) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Clean and store once per original content. */
    public static function storeBytes(string $bytes): ?array
    {
        $sha = hash('sha256', $bytes);
        $existing = DB::one('SELECT * FROM mail_assets WHERE sha256 = :s', ['s' => $sha]);
        if ($existing && self::intact($existing)) {
            return $existing;
        }
        $c = self::clean($bytes);
        if (!$c) {
            return null;
        }
        $blob = '';
        if ($c['kind'] === 'image') {
            $blob = 'mimg-' . bin2hex(random_bytes(12));
            $fh = fopen('php://temp', 'w+b');
            fwrite($fh, $c['bytes']);
            rewind($fh);
            Storage::store()->put($blob, $fh);
            fclose($fh);
        }
        try {
            $id = DB::insert('mail_assets', ['sha256' => $sha, 'blob_id' => $blob, 'kind' => $c['kind'], 'mime' => $c['mime'], 'width' => $c['width'],
                'height' => $c['height'], 'size' => strlen($c['bytes']), 'created_at' => time()]);
        } catch (\PDOException) {
            // Stored meanwhile by a concurrent run.
            if ($blob !== '') {
                Storage::store()->delete($blob);
            }
            return DB::one('SELECT * FROM mail_assets WHERE sha256 = :s', ['s' => $sha]);
        }
        return DB::one('SELECT * FROM mail_assets WHERE id = :id', ['id' => $id]);
    }

    /** Embedded or attached picture shown inline: cleaned, cached, tied to its message. */
    public static function inline(int $messageId, int $userId, string $key, string $bytes): ?array
    {
        $ref = DB::one('SELECT r.*, a.blob_id, a.mime, a.kind, a.size, a.width, a.height FROM mail_asset_refs r JOIN mail_assets a ON a.id = r.asset_id WHERE r.message_id = :m AND r.ref_key = :k', ['m' => $messageId, 'k' => hash('sha256', $key)]);
        if ($ref) {
            return $ref;
        }
        $a = self::storeBytes($bytes);
        if (!$a) {
            return null;
        }
        try {
            DB::insert('mail_asset_refs', ['message_id' => $messageId, 'user_id' => $userId, 'ref_key' => hash('sha256', $key), 'url' => $key, 'asset_id' => (int) $a['id'],
                'status' => $a['kind'] === 'tracker' ? 'tracker' : 'ok', 'created_at' => time()]);
        } catch (\PDOException) {
        }
        return $a;
    }

    // ------------------------------------------------------------- display

    /** State of the remote pictures of a message: url → ['status' => …, 'asset' => id]. */
    public static function forMessage(int $messageId): array
    {
        $out = [];
        foreach (DB::all('SELECT url, status, asset_id FROM mail_asset_refs WHERE message_id = :m', ['m' => $messageId]) as $r) {
            $out[(string) $r['url']] = ['status' => $r['status'], 'asset' => $r['asset_id'] !== null ? (int) $r['asset_id'] : null];
        }
        return $out;
    }

    public static function asset(int $messageId, int $assetId): ?array
    {
        $a = DB::one("SELECT a.* FROM mail_assets a JOIN mail_asset_refs r ON r.asset_id = a.id WHERE r.message_id = :m AND a.id = :a AND a.kind = 'image' LIMIT 1",
            ['m' => $messageId, 'a' => $assetId]);
        return $a && self::intact($a) ? $a : null;
    }

    /**
     * The stored copy is still there. If it was lost (storage restored or wiped), the record
     * goes and its references are fetched again, instead of announcing an empty picture.
     */
    private static function intact(array $a): bool
    {
        if ((string) $a['blob_id'] === '' || Storage::store()->exists((string) $a['blob_id'])) {
            return true;
        }
        DB::run("UPDATE mail_asset_refs SET asset_id = NULL, status = 'pending', tries = 0 WHERE asset_id = :a", ['a' => $a['id']]);
        DB::delete('mail_assets', 'id = :id', ['id' => $a['id']]);
        return false;
    }

    public static function stream(array $asset, callable $sink): void
    {
        Storage::store()->read((string) $asset['blob_id'], $sink);
    }

    // ------------------------------------------------------------- cleanup

    /** A message is gone: drop its references, then the pictures nobody uses any more. */
    public static function forget(array $messageIds): void
    {
        $messageIds = array_values(array_filter(array_map('intval', $messageIds)));
        if (!$messageIds) {
            return;
        }
        [$in, $params] = DB::in('m', $messageIds);
        $assets = array_map('intval', array_column(DB::all("SELECT DISTINCT asset_id FROM mail_asset_refs WHERE asset_id IS NOT NULL AND message_id IN $in", $params), 'asset_id'));
        DB::run("DELETE FROM mail_asset_refs WHERE message_id IN $in", $params);
        self::gc($assets);
    }

    public static function forgetUser(int $userId): void
    {
        $assets = array_map('intval', array_column(DB::all('SELECT DISTINCT asset_id FROM mail_asset_refs WHERE asset_id IS NOT NULL AND user_id = :u', ['u' => $userId]), 'asset_id'));
        DB::delete('mail_asset_refs', 'user_id = :u', ['u' => $userId]);
        self::gc($assets);
    }

    /** @param int[] $assetIds candidates; null = all unreferenced pictures */
    public static function gc(?array $assetIds = null): int
    {
        $sql = 'SELECT a.id, a.blob_id FROM mail_assets a WHERE NOT EXISTS (SELECT 1 FROM mail_asset_refs r WHERE r.asset_id = a.id)';
        $params = [];
        if ($assetIds !== null) {
            if (!$assetIds) {
                return 0;
            }
            [$in, $params] = DB::in('a', $assetIds);
            $sql .= " AND a.id IN $in";
        }
        $n = 0;
        foreach (DB::all($sql, $params) as $a) {
            if ($a['blob_id'] !== '') {
                Storage::store()->delete((string) $a['blob_id']);
            }
            DB::delete('mail_assets', 'id = :id', ['id' => $a['id']]);
            $n++;
        }
        return $n;
    }
}
