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
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                || preg_match('/^(::ffff:|64:ff9b::)/i', $ip)) {
                throw new \InvalidArgumentException(t('fetch.private_host'));
            }
        }
        return $ips[0];
    }
}
