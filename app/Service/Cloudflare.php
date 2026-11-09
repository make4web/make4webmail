<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Config;
use M4W\Core\Settings;

/**
 * Cloudflare's API with a token limited to editing the DNS of a zone: the name of the mail apps
 * service ("DNS only": Cloudflare does not relay IMAP) and the TXT record that proves the name
 * to Let's Encrypt (see Acme). Nothing reaches the server: no port to open.
 */
final class Cloudflare
{
    private const API = 'https://api.cloudflare.com/client/v4';
    /** Where a token is made, with the "Edit zone DNS" template. */
    public const TOKEN_PAGE = 'https://dash.cloudflare.com/profile/api-tokens';

    /** @var ?callable test hook: fn(string $method, string $url, ?array $body, array $headers): array{0:int,1:string} */
    public static $transport = null;

    public static function token(): string
    {
        return Settings::secret('cloudflare.token');
    }

    public static function setToken(string $token): void
    {
        Settings::setSecret('cloudflare.token', trim($token));
    }

    private static function http(string $method, string $url, ?array $body = null, array $headers = []): array
    {
        if (self::$transport) {
            return (self::$transport)($method, $url, $body, $headers);
        }
        $ch = curl_init($url);
        $proxy = trim((string) Config::get('outbound_proxy', ''));
        curl_setopt_array($ch, ($proxy !== '' ? [CURLOPT_PROXY => $proxy] : []) + [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body !== null ? json_encode($body) : null,
        ]);
        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if (!is_string($out)) {
            throw new \RuntimeException('Cloudflare : ' . $err);
        }
        return [$code, $out];
    }

    private static function api(string $method, string $path, ?array $body = null): array
    {
        [$code, $out] = self::http($method, self::API . $path, $body, ['Authorization: Bearer ' . self::token(), 'Content-Type: application/json']);
        $j = json_decode($out, true);
        if (!is_array($j) || empty($j['success'])) {
            $msg = is_array($j) ? implode(' ; ', array_map(static fn($e) => (string) ($e['message'] ?? ''), (array) ($j['errors'] ?? []))) : '';
            throw new \RuntimeException('Cloudflare (HTTP ' . $code . ') : ' . ($msg ?: 'réponse inattendue'));
        }
        return $j;
    }

    /** The token works (active). */
    public static function verify(): void
    {
        $j = self::api('GET', '/user/tokens/verify');
        if (($j['result']['status'] ?? '') !== 'active') {
            throw new \RuntimeException(t('cf.token_inactive'));
        }
    }

    /** The zone holding a name (example.com for webmail.example.com), as the token sees it. */
    public static function zoneFor(string $host): array
    {
        $parts = explode('.', mb_strtolower($host));
        for ($i = 0; $i < count($parts) - 1; $i++) {
            $name = implode('.', array_slice($parts, $i));
            $j = self::api('GET', '/zones?name=' . rawurlencode($name));
            if (!empty($j['result'][0]['id'])) {
                return ['id' => (string) $j['result'][0]['id'], 'name' => $name];
            }
        }
        throw new \RuntimeException(t('cf.no_zone', ['host' => $host]));
    }

    /** One record of this type and name, created or changed (never proxied: "DNS only"). */
    public static function upsert(string $zoneId, string $type, string $name, string $content, int $ttl = 120): string
    {
        $j = self::api('GET', '/zones/' . $zoneId . '/dns_records?type=' . $type . '&name=' . rawurlencode($name));
        $body = ['type' => $type, 'name' => $name, 'content' => $content, 'ttl' => $ttl] + ($type === 'TXT' ? [] : ['proxied' => false]);
        $id = (string) ($j['result'][0]['id'] ?? '');
        if ($id !== '' && $type !== 'TXT') {
            self::api('PUT', '/zones/' . $zoneId . '/dns_records/' . $id, $body);
            return $id;
        }
        return (string) (self::api('POST', '/zones/' . $zoneId . '/dns_records', $body)['result']['id'] ?? '');
    }

    /** @return array[] the records of this type and name */
    public static function records(string $zoneId, string $type, string $name): array
    {
        return (array) (self::api('GET', '/zones/' . $zoneId . '/dns_records?type=' . $type . '&name=' . rawurlencode($name))['result'] ?? []);
    }

    /** A service record (autodiscovery of mail apps), created or changed. */
    public static function upsertSrv(string $zoneId, string $name, string $target, int $port, int $priority = 0, int $weight = 1): void
    {
        $body = ['type' => 'SRV', 'name' => $name, 'ttl' => 3600, 'data' => ['priority' => $priority, 'weight' => $weight, 'port' => $port, 'target' => $target]];
        $id = (string) (self::records($zoneId, 'SRV', $name)[0]['id'] ?? '');
        $id !== '' ? self::api('PUT', '/zones/' . $zoneId . '/dns_records/' . $id, $body) : self::api('POST', '/zones/' . $zoneId . '/dns_records', $body);
    }

    public static function delete(string $zoneId, string $recordId): void
    {
        try {
            self::api('DELETE', '/zones/' . $zoneId . '/dns_records/' . $recordId);
        } catch (\Throwable) {
            // Left behind: harmless (a TXT record that proves nothing any more).
        }
    }

    /** This server's public address, as Cloudflare sees it. */
    public static function publicIp(): string
    {
        try {
            [, $out] = self::http('GET', 'https://cloudflare.com/cdn-cgi/trace');
            if (preg_match('/^ip=(\S+)$/m', $out, $m) && filter_var($m[1], FILTER_VALIDATE_IP)) {
                return $m[1];
            }
        } catch (\Throwable) {
        }
        return '';
    }
}
