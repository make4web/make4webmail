<?php
declare(strict_types=1);

namespace M4W\Core;

/**
 * Runtime settings stored in DB (branding, SMTP, security policy...).
 */
final class Settings
{
    private static ?array $cache = null;

    public const DEFAULTS = [
        // Branding
        'brand.name'           => 'Make4Web Mail',
        'brand.tagline'        => 'Votre messagerie professionnelle, simple et sécurisée.',
        'brand.logo'           => '',
        'brand.logo_dark'      => '',
        'brand.favicon'        => '',
        'brand.login_bg'       => '',
        'brand.primary'        => '#2563eb',
        'brand.accent'         => '#0ea5e9',
        'brand.sidebar'        => 'light',
        'brand.radius'         => 10,
        'brand.font'           => 'inter',
        'brand.login_message'  => '',
        'brand.footer'         => '',
        'brand.custom_css'     => '',
        'brand.company'        => 'Make4Web',
        'brand.website'        => '',
        'brand.address'        => '',
        // Outgoing SMTP
        'smtp.host'            => '',
        'smtp.port'            => 587,
        'smtp.security'        => 'tls',
        'smtp.auth'            => 1,
        'smtp.username'        => '',
        'smtp.password'        => '',
        'smtp.helo'            => '',
        'smtp.verify_peer'     => 1,
        'smtp.timeout'         => 20,
        'smtp.auth_as_user'    => 0,
        'smtp.local_delivery'  => 1,
        'smtp.dkim_domain'     => '',
        'smtp.dkim_selector'   => '',
        'smtp.dkim_private'    => '',
        // Incoming
        'inbound.listen'       => '0.0.0.0:2525',
        'inbound.max_size_mb'  => 35,
        'inbound.allowed_ips'  => '',
        'inbound.spam_header'  => 1,
        // Security
        'security.password_min'      => 10,
        'security.password_classes'  => 3,
        'security.max_attempts'      => 5,
        'security.lockout_minutes'   => 15,
        'security.session_idle'      => 60,
        'security.session_absolute'  => 720,
        'security.enforce_2fa_admin' => 0,
        'security.allow_external_forward' => 1,
        'security.forward_whitelist' => '',
        'security.block_remote_images' => 1,
        'security.max_attachment_mb' => 25,
        'security.allowed_ips_admin' => '',
        // Features
        'features.user_signature'    => 0,
        'features.fetch_accounts'    => 1,
        'features.undo_send_seconds' => 5,
        'features.default_language'  => 'fr',
        'features.registration'      => 0,
    ];

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = self::DEFAULTS;
            try {
                foreach (Database::all('SELECT name, value FROM settings') as $row) {
                    $decoded = json_decode((string) $row['value'], true);
                    self::$cache[$row['name']] = $decoded;
                }
            } catch (\Throwable) {
                // DB not installed yet.
            }
        }
        return self::$cache;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $exists = Database::value('SELECT COUNT(*) FROM settings WHERE name = :n', ['n' => $key]);
        if ($exists) {
            Database::update('settings', ['value' => $json], 'name = :n', ['n' => $key]);
        } else {
            Database::insert('settings', ['name' => $key, 'value' => $json]);
        }
        if (self::$cache !== null) {
            self::$cache[$key] = $value;
        }
    }

    public static function setMany(array $values): void
    {
        Database::transaction(function () use ($values) {
            foreach ($values as $k => $v) {
                self::set($k, $v);
            }
        });
    }

    /** Encrypted secret setting (e.g. SMTP password). */
    public static function secret(string $key): string
    {
        $v = (string) self::get($key, '');
        return $v === '' ? '' : (Crypto::decrypt($v) ?? '');
    }

    public static function setSecret(string $key, string $plain): void
    {
        self::set($key, $plain === '' ? '' : Crypto::encrypt($plain));
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
