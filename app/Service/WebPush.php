<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Database as DB;
use M4W\Core\Settings;

/**
 * Web Push without dependencies: VAPID authentication (RFC 8292, ES256 JWT) and
 * payload encryption "aes128gcm" (RFC 8291 / RFC 8188), with PHP's OpenSSL only.
 *
 * Notifications reach browsers and installed web apps (Android, iOS 16.4+ when
 * added to the home screen, desktop) even when Make4WebMail is closed.
 */
final class WebPush
{
    /** Push services of the browsers: endpoints are only accepted there (no SSRF). */
    private const HOSTS = ['fcm.googleapis.com', 'android.googleapis.com', 'updates.push.services.mozilla.com', 'push.services.mozilla.com', 'web.push.apple.com', 'notify.windows.com'];

    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** @var callable|null fn(string $endpoint, array $headers, string $body): int  (tests) */
    public static $transport = null;

    public static function enabled(): bool
    {
        return (int) Settings::get('push.enabled', 1) === 1 && function_exists('openssl_pkey_derive');
    }

    // ------------------------------------------------------------ helpers

    public static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    /** Raw uncompressed P-256 point (65 bytes) of an OpenSSL key. */
    private static function rawPublic(\OpenSSLAsymmetricKey $key): string
    {
        $d = openssl_pkey_get_details($key)['ec'];
        return "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
    }

    private static function publicFromRaw(string $raw): \OpenSSLAsymmetricKey
    {
        if (strlen($raw) !== 65 || $raw[0] !== "\x04") {
            throw new \InvalidArgumentException('Invalid P-256 public key');
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin(self::P256_SPKI_PREFIX) . $raw), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $k = openssl_pkey_get_public($pem);
        if ($k === false) {
            throw new \InvalidArgumentException('Invalid P-256 public key');
        }
        return $k;
    }

    private static function newKey(): \OpenSSLAsymmetricKey
    {
        $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($k === false) {
            throw new \RuntimeException('Cannot create EC key');
        }
        return $k;
    }

    private static function hkdf(string $salt, string $ikm, string $info, int $len): string
    {
        return hash_hkdf('sha256', $ikm, $len, $info, $salt);
    }

    // -------------------------------------------------------------- VAPID

    /** Application server key pair, created once per instance. @return array{public:string,private:string} */
    public static function vapid(): array
    {
        $pub = (string) Settings::get('push.vapid_public', '');
        $priv = Settings::secret('push.vapid_private');
        if ($pub === '' || $priv === '') {
            $k = self::newKey();
            openssl_pkey_export($k, $priv);
            $pub = self::b64u(self::rawPublic($k));
            Settings::set('push.vapid_public', $pub);
            Settings::setSecret('push.vapid_private', $priv);
        }
        return ['public' => $pub, 'private' => $priv];
    }

    /** ES256 JWT for the push service of $endpoint (RFC 8292). */
    public static function vapidHeader(string $endpoint): string
    {
        $v = self::vapid();
        $p = parse_url($endpoint);
        $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        $contact = (string) Settings::get('push.contact', '') ?: 'mailto:postmaster@' . (DB::value('SELECT name FROM domains WHERE active = 1 ORDER BY id LIMIT 1') ?: 'localhost');
        $input = self::b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.' . self::b64u(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $contact]));
        $key = openssl_pkey_get_private($v['private']);
        if ($key === false || !openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('VAPID signing failed');
        }
        return 'vapid t=' . $input . '.' . self::b64u(self::derToRaw($der)) . ', k=' . $v['public'];
    }

    /** ECDSA DER signature → raw R||S (64 bytes), as JWS expects. */
    public static function derToRaw(string $der): string
    {
        $pos = 2;
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);
            $int = substr($der, $pos + 2, $len);
            $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
            $pos += 2 + $len;
        }
        return $out;
    }

    // --------------------------------------------------------- encryption

    /**
     * aes128gcm content encoding of $payload for a subscription (RFC 8291).
     * $serverKey / $salt are only injected by tests.
     */
    public static function encrypt(string $payload, string $p256dh, string $auth, ?\OpenSSLAsymmetricKey $serverKey = null, ?string $salt = null): string
    {
        $uaPublic = self::b64uDecode($p256dh);
        $authSecret = self::b64uDecode($auth);
        $serverKey ??= self::newKey();
        $salt ??= random_bytes(16);
        $asPublic = self::rawPublic($serverKey);
        $shared = openssl_pkey_derive(self::publicFromRaw($uaPublic), $serverKey, 32);
        if ($shared === false) {
            throw new \RuntimeException('ECDH failed');
        }
        $ikm = self::hkdf($authSecret, $shared, "WebPush: info\0" . $uaPublic . $asPublic, 32);
        $cek = self::hkdf($salt, $ikm, "Content-Encoding: aes128gcm\0", 16);
        $nonce = self::hkdf($salt, $ikm, "Content-Encoding: nonce\0", 12);
        $tag = '';
        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        // Header: salt | record size | key id length | key id (server public key)
        return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
    }

    // -------------------------------------------------------- subscriptions

    private const MAX_DEVICES = 20;

    public static function allowedEndpoint(string $endpoint): bool
    {
        $p = parse_url($endpoint);
        if (($p['scheme'] ?? '') !== 'https' || empty($p['host'])) {
            return false;
        }
        $host = mb_strtolower($p['host']);
        foreach (self::HOSTS as $h) {
            if ($host === $h || str_ends_with($host, '.' . $h)) {
                return true;
            }
        }
        return false;
    }

    public static function subscribe(int $userId, string $endpoint, string $p256dh, string $auth, string $ua): void
    {
        if (!self::allowedEndpoint($endpoint) || strlen($endpoint) > 1000) {
            throw new \InvalidArgumentException(t('push.bad_endpoint'));
        }
        if (strlen(self::b64uDecode($p256dh)) !== 65 || strlen(self::b64uDecode($auth)) !== 16) {
            throw new \InvalidArgumentException(t('push.bad_endpoint'));
        }
        $hash = hash('sha256', $endpoint);
        // One browser, one subscription: it moves to whoever signs in on it.
        DB::delete('push_subscriptions', 'endpoint_hash = :h', ['h' => $hash]);
        $old = DB::all('SELECT id FROM push_subscriptions WHERE user_id = :u ORDER BY last_used_at DESC', ['u' => $userId]);
        foreach (array_slice($old, self::MAX_DEVICES - 1) as $o) {
            DB::delete('push_subscriptions', 'id = :id', ['id' => $o['id']]);
        }
        DB::insert('push_subscriptions', [
            'user_id' => $userId, 'endpoint' => $endpoint, 'endpoint_hash' => $hash, 'p256dh' => $p256dh, 'auth' => $auth,
            'user_agent' => mb_substr($ua, 0, 255), 'created_at' => time(), 'last_used_at' => time(),
        ]);
    }

    public static function unsubscribe(int $userId, ?string $endpoint = null, ?int $id = null): void
    {
        if ($endpoint !== null) {
            DB::delete('push_subscriptions', 'user_id = :u AND endpoint_hash = :h', ['u' => $userId, 'h' => hash('sha256', $endpoint)]);
        } elseif ($id !== null) {
            DB::delete('push_subscriptions', 'user_id = :u AND id = :id', ['u' => $userId, 'id' => $id]);
        }
    }

    public static function devices(int $userId): array
    {
        return DB::all('SELECT id, user_agent, created_at, last_used_at, endpoint_hash FROM push_subscriptions WHERE user_id = :u ORDER BY created_at DESC', ['u' => $userId]);
    }

    /** Human name of a subscribed browser, from its user agent. */
    public static function deviceLabel(string $ua): string
    {
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS') => 'Firefox',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => t('push.browser'),
        };
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => '',
        };
        return $os !== '' ? $browser . ' · ' . $os : $browser;
    }

    public static function deviceIcon(string $ua): string
    {
        return preg_match('/iPhone|Android.*Mobile|Mobile/', $ua) ? 'phone' : (preg_match('/iPad|Android/', $ua) ? 'tablet' : 'laptop');
    }

    // ---------------------------------------------------------------- send

    /** @return int number of devices reached */
    public static function send(int $userId, array $message, int $ttl = 86400): int
    {
        if (!self::enabled()) {
            return 0;
        }
        $subs = DB::all('SELECT * FROM push_subscriptions WHERE user_id = :u', ['u' => $userId]);
        if (!$subs) {
            return 0;
        }
        $body = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $n = 0;
        foreach ($subs as $s) {
            try {
                $cipher = self::encrypt($body, $s['p256dh'], $s['auth']);
                $headers = [
                    'Authorization' => self::vapidHeader($s['endpoint']),
                    'Content-Encoding' => 'aes128gcm',
                    'Content-Type' => 'application/octet-stream',
                    'TTL' => (string) $ttl,
                    'Urgency' => 'high',
                ];
                $status = self::post($s['endpoint'], $headers, $cipher);
            } catch (\Throwable $e) {
                error_log('[m4w] push: ' . $e->getMessage());
                $status = 0;
            }
            if ($status >= 200 && $status < 300) {
                DB::update('push_subscriptions', ['last_used_at' => time(), 'failures' => 0], 'id = :id', ['id' => $s['id']]);
                $n++;
            } elseif (in_array($status, [404, 410], true) || (int) $s['failures'] >= 20) {
                // Expired or revoked by the browser: forget it.
                DB::delete('push_subscriptions', 'id = :id', ['id' => $s['id']]);
            } else {
                DB::update('push_subscriptions', ['failures' => (int) $s['failures'] + 1], 'id = :id', ['id' => $s['id']]);
            }
        }
        return $n;
    }

    private static function post(string $endpoint, array $headers, string $body): int
    {
        if (self::$transport) {
            return (int) (self::$transport)($endpoint, $headers, $body);
        }
        if (!self::allowedEndpoint($endpoint) || !function_exists('curl_init')) {
            return 0;
        }
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $status;
    }

    /** New message in a user's inbox. Called by local delivery. */
    /** The app's icon (the client's logo when there is one), relative to the service worker scope. */
    private static function icon(): string
    {
        return AppIcon::custom() ? 'app-icon/192.png' : 'assets/img/icon-192.png';
    }

    public static function newMail(array $user, int $messageId, array $from, string $subject, int $folderId): void
    {
        if (!self::enabled()) {
            return;
        }
        $preview = (int) Settings::get('push.preview', 1) === 1;
        $name = trim((string) ($from['name'] ?? '')) ?: (string) ($from['email'] ?? '');
        $isPersonal = Delegation::isPersonalSubject($subject);
        // Said plainly: who it is from, what it is about, where it was filed (when not the inbox).
        $title = $preview && $name !== '' ? t('push.title_from', ['name' => mb_substr($name, 0, 60)]) : t('push.new_mail');
        $body = $preview ? ($isPersonal ? t('push.personal') : ($subject !== '' ? mb_substr($subject, 0, 140) : t('mail.no_subject'))) : t('push.new_mail_body');
        $folder = DB::one('SELECT name, role FROM folders WHERE id = :f', ['f' => $folderId]);
        if ($folder && empty($folder['role'])) {
            $body .= "\n" . t('push.in_folder', ['folder' => mb_substr((string) $folder['name'], 0, 40)]);
        }
        // Relative to the service worker scope: deliveries also run from the CLI, where the base path is unknown.
        $hash = '#f/' . $folderId . '/m/' . $messageId;
        // The unread total of all the person's mailboxes: the badge of the installed app, even closed.
        $count = static fn(int $uid) => array_sum(SharedMailboxes::counts($uid));
        if (!empty($user['prefs']['notifications'])) {
            self::send((int) $user['id'], ['title' => $title, 'body' => $body, 'tag' => 'm4w-' . $messageId, 'url' => 'mail' . $hash, 'count' => $count((int) $user['id']), 'icon' => self::icon()]);
        }
        // People who have this mailbox among their other mailboxes are alerted too; tapping opens it.
        foreach (SharedMailboxes::usersOf((int) $user['id']) as $memberId) {
            $member = Users::find($memberId);
            if ($member && !empty($member['prefs']['notifications'])) {
                self::send($memberId, ['title' => $title, 'body' => '[' . $user['email'] . '] ' . $body, 'tag' => 'm4w-' . $messageId, 'url' => 'mail?mbx=' . (int) $user['id'] . $hash, 'count' => $count($memberId), 'icon' => self::icon()]);
            }
        }
    }
}
