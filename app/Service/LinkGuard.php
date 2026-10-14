<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Config;
use M4W\Core\Crypto;
use M4W\Core\Database as DB;
use M4W\Core\Settings;
use M4W\Mail\LinkCheck;

/**
 * Links in received mail are checked when they are clicked. The sanitizer rewrites each
 * web link to /go, signed so that it cannot serve as an open redirect. There:
 *
 *  - a known threat (Google Safe Browsing / Web Risk, the public URLhaus feed, the
 *    administrator's block list) is blocked;
 *  - a misleading link (text showing another address, raw IP, shortener, look-alike
 *    name, "user@host" trick) shows its real destination and asks for confirmation;
 *  - anything else goes straight through, without telling the site where it comes from.
 */
final class LinkGuard
{
    public const URLHAUS_URL = 'https://urlhaus.abuse.ch/downloads/text_online/';
    private const SB_URL = 'https://safebrowsing.googleapis.com/v4/threatMatches:find';
    private const WR_URL = 'https://webrisk.googleapis.com/v1/uris:search';

    /** Test hook: fn(string $api, string[] $urls): array<string,string> url => threat type. */
    public static ?\Closure $transport = null;
    /** @var array<string,true>|null */
    private static ?array $feed = null;

    public static function enabled(): bool
    {
        return (int) Settings::get('security.link_check', 1) === 1;
    }

    // ---- Signed redirect -------------------------------------------------------------

    public static function wrap(string $href, string $text, int $messageId): string
    {
        $text = mb_substr(trim(preg_replace('/\s+/u', ' ', $text) ?? ''), 0, 200);
        return url('go', ['u' => $href, 't' => $text, 'm' => $messageId, 's' => self::sign($href, $text, $messageId)]);
    }

    public static function sign(string $href, string $text, int $messageId): string
    {
        return substr(Crypto::hmac($messageId . "\n" . $href . "\n" . $text, 'links'), 0, 32);
    }

    public static function verify(string $href, string $text, int $messageId, string $sig): bool
    {
        return $sig !== '' && hash_equals(self::sign($href, $text, $messageId), $sig);
    }

    // ---- Assessment ------------------------------------------------------------------

    /**
     * @return array{problems:string[],threat:string,host:string}
     *         threat: '' or MALWARE, SOCIAL_ENGINEERING, UNWANTED_SOFTWARE, URLHAUS, ADMIN_BLOCK…
     */
    public static function assess(string $href, string $text = ''): array
    {
        $host = LinkCheck::host($href);
        return ['problems' => LinkCheck::problems($href, $text), 'threat' => self::threats([$href])[$href] ?? '', 'host' => $host];
    }

    /** @param string[] $urls @return array<string,string> url => threat (only the threats) */
    public static function threats(array $urls, bool $remoteApi = true): array
    {
        $out = [];
        $remote = [];
        $block = self::blockedDomains();
        foreach (array_unique($urls) as $u) {
            if (!preg_match('#^https?://#i', $u)) {
                continue;
            }
            $host = LinkCheck::host($u);
            foreach ($block as $d) {
                if ($host === $d || str_ends_with($host, '.' . $d)) {
                    $out[$u] = 'ADMIN_BLOCK';
                    continue 2;
                }
            }
            if (isset(self::feed()[self::normalize($u)])) {
                $out[$u] = 'URLHAUS';
                continue;
            }
            $remote[] = $u;
        }
        if ($remote && $remoteApi && self::apiKey() !== '') {
            $out += self::remoteThreats($remote);
        }
        return $out;
    }

    /** @return string[] */
    private static function blockedDomains(): array
    {
        return array_values(array_filter(array_map(static fn($s) => strtolower(trim($s, " .\t")), preg_split('/[\s,;]+/', (string) Settings::get('security.link_block', '')) ?: [])));
    }

    private static function apiKey(): string
    {
        $enc = (string) Settings::get('security.safebrowsing_key', '');
        return $enc === '' ? '' : (string) (Crypto::decrypt($enc) ?? '');
    }

    public static function setApiKey(string $key): void
    {
        Settings::set('security.safebrowsing_key', $key === '' ? '' : Crypto::encrypt($key));
    }

    public static function hasApiKey(): bool
    {
        return (string) Settings::get('security.safebrowsing_key', '') !== '';
    }

    public static function normalize(string $url): string
    {
        $u = trim($url);
        if (preg_match('#^(https?)://([^/?\#]+)(.*)$#i', $u, $m)) {
            $u = strtolower($m[1]) . '://' . strtolower($m[2]) . ($m[3] === '' ? '/' : $m[3]);
        }
        return preg_replace('/#.*$/', '', $u) ?? $u;
    }

    /** Google Safe Browsing (v4 Lookup) or Web Risk, with a cache. @return array<string,string> */
    private static function remoteThreats(array $urls): array
    {
        $now = time();
        $out = [];
        $ask = [];
        foreach (array_slice($urls, 0, 200) as $u) {
            $row = DB::one('SELECT threat, checked_at FROM url_verdicts WHERE url_hash = :h', ['h' => hash('sha256', self::normalize($u))]);
            // Clean verdicts are trusted 1 hour, threats 24 hours.
            if ($row && (int) $row['checked_at'] > $now - ($row['threat'] === '' ? 3600 : 86400)) {
                if ($row['threat'] !== '') {
                    $out[$u] = $row['threat'];
                }
                continue;
            }
            $ask[] = $u;
        }
        if (!$ask) {
            return $out;
        }
        $api = (string) Settings::get('security.safebrowsing_api', 'safebrowsing');
        try {
            $found = self::$transport ? (self::$transport)($api, $ask) : ($api === 'webrisk' ? self::webRisk($ask) : self::safeBrowsing($ask));
        } catch (\Throwable $e) {
            error_log('[m4w] link check: ' . $e->getMessage());
            return $out; // unreachable API: fall back to the other checks
        }
        foreach ($ask as $u) {
            $threat = mb_substr((string) ($found[$u] ?? ''), 0, 40);
            $h = hash('sha256', self::normalize($u));
            DB::run('DELETE FROM url_verdicts WHERE url_hash = :h', ['h' => $h]);
            DB::insert('url_verdicts', ['url_hash' => $h, 'threat' => $threat, 'checked_at' => $now]);
            if ($threat !== '') {
                $out[$u] = $threat;
            }
        }
        return $out;
    }

    private static function http(string $url, ?string $json = null): array
    {
        $ch = curl_init($url);
        $proxy = trim((string) Config::get('outbound_proxy', ''));
        curl_setopt_array($ch, ($proxy !== '' ? [CURLOPT_PROXY => $proxy] : []) + [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ] + ($json !== null ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json] : []));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body) || $code !== 200) {
            throw new \RuntimeException('HTTP ' . $code);
        }
        return json_decode($body, true) ?: [];
    }

    private static function safeBrowsing(array $urls): array
    {
        $r = self::http(self::SB_URL . '?key=' . rawurlencode(self::apiKey()), (string) json_encode([
            'client' => ['clientId' => 'make4webmail', 'clientVersion' => M4W_VERSION],
            'threatInfo' => [
                'threatTypes' => ['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE', 'POTENTIALLY_HARMFUL_APPLICATION'],
                'platformTypes' => ['ANY_PLATFORM'], 'threatEntryTypes' => ['URL'],
                'threatEntries' => array_map(static fn($u) => ['url' => $u], array_values($urls)),
            ],
        ]));
        $out = [];
        foreach ($r['matches'] ?? [] as $m) {
            $out[(string) ($m['threat']['url'] ?? '')] = (string) ($m['threatType'] ?? 'MALWARE');
        }
        return $out;
    }

    private static function webRisk(array $urls): array
    {
        $out = [];
        foreach (array_slice($urls, 0, 20) as $u) {
            $r = self::http(self::WR_URL . '?threatTypes=MALWARE&threatTypes=SOCIAL_ENGINEERING&threatTypes=UNWANTED_SOFTWARE&uri=' . rawurlencode($u) . '&key=' . rawurlencode(self::apiKey()));
            if (!empty($r['threat']['threatTypes'][0])) {
                $out[$u] = (string) $r['threat']['threatTypes'][0];
            }
        }
        return $out;
    }

    // ---- URLhaus feed (abuse.ch, CC0): malware distribution sites, no key needed --------

    private static function feedFile(): string
    {
        return Config::storagePath('data/urlhaus.txt');
    }

    /** @return array<string,true> */
    private static function feed(): array
    {
        if (self::$feed === null) {
            self::$feed = [];
            if ((int) Settings::get('security.urlhaus', 1) && is_file(self::feedFile())) {
                foreach (file(self::feedFile(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
                    self::$feed[$l] = true;
                }
            }
        }
        return self::$feed;
    }

    public static function feedSize(): int
    {
        return count(self::feed());
    }

    public static function updateFeed(?string $body = null): int
    {
        if ($body === null) {
            $ch = curl_init(self::URLHAUS_URL);
            $proxy = trim((string) Config::get('outbound_proxy', ''));
            curl_setopt_array($ch, ($proxy !== '' ? [CURLOPT_PROXY => $proxy] : []) + [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if (!is_string($body) || $code !== 200) {
                throw new \RuntimeException('download failed (HTTP ' . $code . ')');
            }
        }
        $set = [];
        foreach (explode("\n", $body) as $l) {
            $l = trim($l);
            if ($l !== '' && $l[0] !== '#' && preg_match('#^https?://\S+$#i', $l)) {
                $set[self::normalize($l)] = true;
            }
        }
        if (count($set) < 10) {
            throw new \RuntimeException('unexpected feed (' . count($set) . ' entries)');
        }
        $file = self::feedFile();
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0750, true);
        }
        file_put_contents($file . '.tmp', implode("\n", array_keys($set)) . "\n");
        rename($file . '.tmp', $file);
        Settings::set('security.urlhaus_updated', time());
        self::$feed = null;
        return count($set);
    }

    public static function gc(): void
    {
        DB::run('DELETE FROM url_verdicts WHERE checked_at < :t', ['t' => time() - 2 * 86400]);
    }
}
