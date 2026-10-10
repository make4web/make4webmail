<?php
declare(strict_types=1);

namespace M4W\Core;

/**
 * Authenticated encryption for secrets at rest (libsodium XSalsa20-Poly1305).
 */
final class Crypto
{
    private static function key(string $context = 'secrets'): string
    {
        $raw = base64_decode((string) Config::get('app_key', ''), true);
        if ($raw === false || strlen($raw) < 32) {
            throw new \RuntimeException('Invalid or missing app_key in config.');
        }
        return sodium_crypto_generichash($context, $raw, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public static function encrypt(string $plain, string $context = 'secrets'): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key($context)));
    }

    public static function decrypt(string $cipher, string $context = 'secrets'): ?string
    {
        if (!str_starts_with($cipher, 'v1:')) {
            return null;
        }
        $bin = base64_decode(substr($cipher, 3), true);
        if ($bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key($context));
        return $plain === false ? null : $plain;
    }

    public static function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function hmac(string $data, string $context = 'hmac'): string
    {
        return hash_hmac('sha256', $data, self::key($context));
    }

    public static function hashPassword(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_hash($password, $algo);
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_needs_rehash($hash, $algo);
    }
}
