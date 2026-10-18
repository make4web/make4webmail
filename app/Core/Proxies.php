<?php
declare(strict_types=1);

namespace M4W\Core;

/**
 * Who stands between the visitor and this server. Behind a proxy (Cloudflare, nginx, a load
 * balancer) the connection comes from the proxy: the visitor's address is in a header the proxy
 * writes (CF-Connecting-IP, X-Forwarded-For). That header is believed only when the connection
 * comes from a proxy known here, since anyone else can write it too.
 *   - Cloudflare: its published ranges (refreshed weekly by the scheduled task), when the
 *     administrator keeps "behind Cloudflare" on (default: on, it only applies to connections
 *     that come from Cloudflare);
 *   - other proxies: config/config.php 'trusted_proxies' and Administration › Security, as
 *     addresses or ranges (127.0.0.1, 10.0.0.0/8).
 */
final class Proxies
{
    /** https://www.cloudflare.com/ips/ (used until the first refresh). */
    public const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
        '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];
    private const CF_URLS = ['https://www.cloudflare.com/ips-v4', 'https://www.cloudflare.com/ips-v6'];

    public static function cloudflareOn(): bool
    {
        return (int) Settings::get('security.cloudflare', 1) === 1;
    }

    /** @return string[] */
    public static function cloudflareRanges(): array
    {
        $saved = json_decode((string) Settings::get('security.cloudflare_ranges', ''), true);
        return is_array($saved) && count($saved) >= 10 ? $saved : self::CLOUDFLARE;
    }

    public static function isCloudflare(string $ip): bool
    {
        return self::inList($ip, self::cloudflareRanges());
    }

    /** @return string[] the proxies declared by the administrator (configuration file and settings) */
    public static function declared(): array
    {
        $list = array_map('strval', (array) Config::get('trusted_proxies', []));
        foreach (preg_split('/[\s,;]+/', (string) Settings::get('security.trusted_proxies', '')) ?: [] as $x) {
            $list[] = $x;
        }
        return array_values(array_filter(array_map('trim', $list), static fn($x) => $x !== ''));
    }

    /** A connection from there may tell who the visitor is. */
    public static function trusted(string $ip): bool
    {
        return self::inList($ip, self::declared()) || (self::cloudflareOn() && self::isCloudflare($ip));
    }

    private static function inList(string $ip, array $list): bool
    {
        foreach ($list as $cidr) {
            if (App::ipInCidr($ip, (string) $cidr)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The visitor's address: from the connection back through the proxies known here, the first
     * address that is not one of them (X-Forwarded-For is read from its end: what a visitor
     * writes at its start is never believed). Through Cloudflare, its own header.
     */
    public static function client(string $remote, string $forwardedFor, string $cfConnectingIp): string
    {
        $hop = $remote;
        $chain = array_reverse(array_values(array_filter(array_map('trim', explode(',', $forwardedFor)), static fn($x) => $x !== '')));
        for ($i = 0; $i < 20; $i++) {
            if (self::cloudflareOn() && self::isCloudflare($hop) && filter_var($cfConnectingIp, FILTER_VALIDATE_IP)) {
                return $cfConnectingIp;
            }
            if (!self::trusted($hop) || !$chain) {
                break;
            }
            $next = array_shift($chain);
            if (!filter_var($next, FILTER_VALIDATE_IP)) {
                break;
            }
            $hop = $next;
        }
        return $hop;
    }

    /** Scheduled task, once a week: Cloudflare's ranges as it publishes them. */
    public static function refreshCloudflare(?callable $fetch = null): int
    {
        $fetch ??= static function (string $url): string {
            $ch = curl_init($url);
            $proxy = trim((string) Config::get('outbound_proxy', ''));
            curl_setopt_array($ch, ($proxy !== '' ? [CURLOPT_PROXY => $proxy] : []) + [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if (!is_string($body) || $code !== 200) {
                throw new \RuntimeException('HTTP ' . $code);
            }
            return $body;
        };
        $ranges = [];
        foreach (self::CF_URLS as $url) {
            foreach (preg_split('/\s+/', $fetch($url)) ?: [] as $l) {
                [$net, $bits] = array_pad(explode('/', $l, 2), 2, '');
                if (filter_var($net, FILTER_VALIDATE_IP) && ctype_digit($bits) && (int) $bits >= 8 && (int) $bits <= 128) {
                    $ranges[] = $net . '/' . (int) $bits;
                }
            }
        }
        // A page that changed shape (or an error page) must not empty the list.
        if (count($ranges) < 10) {
            throw new \RuntimeException('unexpected list (' . count($ranges) . ' ranges)');
        }
        Settings::set('security.cloudflare_ranges', json_encode(array_values(array_unique($ranges))));
        return count($ranges);
    }
}
