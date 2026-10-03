<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Database as DB;
use M4W\Core\Settings;
use M4W\Mail\Address;

final class Forwarding
{
    public static function get(int $userId): array
    {
        $row = DB::one('SELECT * FROM forwardings WHERE user_id = :u', ['u' => $userId]);
        return [
            'enabled'   => (bool) ($row['enabled'] ?? false),
            'addresses' => json_decode((string) ($row['addresses'] ?? '[]'), true) ?: [],
            'keep_copy' => (bool) ($row['keep_copy'] ?? true),
        ];
    }

    public static function save(int $userId, bool $enabled, array $addresses, bool $keepCopy): void
    {
        $clean = [];
        foreach ($addresses as $a) {
            $a = mb_strtolower(trim((string) $a));
            if ($a === '') {
                continue;
            }
            if (!is_valid_email($a)) {
                throw new \InvalidArgumentException(t('fwd.invalid', ['email' => $a]));
            }
            self::assertAllowed($a);
            $clean[$a] = $a;
        }
        if ($enabled && !$clean) {
            throw new \InvalidArgumentException(t('fwd.need_address'));
        }
        $clean = array_slice(array_values($clean), 0, 10);
        $data = ['enabled' => $enabled ? 1 : 0, 'addresses' => json_encode($clean), 'keep_copy' => $keepCopy ? 1 : 0, 'updated_at' => time()];
        if (DB::value('SELECT COUNT(*) FROM forwardings WHERE user_id = :u', ['u' => $userId])) {
            DB::update('forwardings', $data, 'user_id = :u', ['u' => $userId]);
        } else {
            DB::insert('forwardings', $data + ['user_id' => $userId]);
        }
    }

    /** Enforce the admin policy on external forwarding (data-leak prevention). */
    public static function assertAllowed(string $address): void
    {
        $domain = Address::domain($address);
        if (Users::isLocalDomain($domain)) {
            return;
        }
        if (!(int) Settings::get('security.allow_external_forward', 1)) {
            throw new \InvalidArgumentException(t('fwd.external_forbidden'));
        }
        $wl = array_filter(array_map('trim', preg_split('/[\s,;]+/', mb_strtolower((string) Settings::get('security.forward_whitelist', ''))) ?: []));
        if ($wl && !in_array($domain, $wl, true)) {
            throw new \InvalidArgumentException(t('fwd.domain_forbidden', ['domain' => $domain]));
        }
    }

    /**
     * Redirect (resend) the original message, keeping its headers, with loop protection.
     */
    public static function redirect(array $user, string $raw, array $targets): void
    {
        $targets = array_values(array_filter($targets, static fn($t) => mb_strtolower($t) !== mb_strtolower($user['email'])));
        if (!$targets) {
            return;
        }
        $headers = 'X-M4W-Loop: ' . $user['email'] . "\r\n"
            . 'Resent-From: ' . $user['email'] . "\r\n"
            . 'Resent-Date: ' . date('r') . "\r\n"
            . 'Resent-To: ' . implode(', ', $targets) . "\r\n";
        Transport::enqueue($headers . str_replace("\n", "\r\n", str_replace("\r\n", "\n", $raw)), $user['email'], $targets, (int) $user['id'], 'forward');
    }
}
