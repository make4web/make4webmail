<?php
declare(strict_types=1);

namespace M4W\Core;

/**
 * Outbound connection guard against SSRF: resolves A and AAAA records, rejects any
 * private/reserved address and returns a validated IP to connect to (prevents DNS rebinding).
 */
final class Net
{
    public static function publicIp(string $host): string
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $ips = [];
            foreach ([DNS_A, DNS_AAAA] as $type) {
                foreach (@dns_get_record($host, $type) ?: [] as $r) {
                    $ips[] = $r['ip'] ?? $r['ipv6'] ?? '';
                }
            }
            $ips = array_merge($ips, @gethostbynamel($host) ?: []);
            $ips = array_values(array_unique(array_filter($ips)));
        }
        if (!$ips) {
            throw new \InvalidArgumentException(t('fetch.invalid'));
        }
        foreach ($ips as $ip) {
            if (!self::isPublic($ip)) {
                throw new \InvalidArgumentException(t('fetch.private_host'));
            }
        }
        return $ips[0];
    }

    /** Globally routable unicast address only (no private, loopback, CGNAT, benchmark, multicast…). */
    public static function isPublic(string $ip): bool
    {
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (defined('FILTER_FLAG_GLOBAL_RANGE')) {
            $flags |= FILTER_FLAG_GLOBAL_RANGE;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, $flags)) {
            return false;
        }
        foreach (self::BLOCKED as $cidr) {
            if (App::ipInCidr($ip, $cidr)) {
                return false;
            }
        }
        return true;
    }

    /** Ranges filter_var does not reject on every PHP version. */
    private const BLOCKED = [
        '0.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '100::/64', '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];
}
