<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Config;
use M4W\Core\Settings;

/**
 * A Let's Encrypt certificate for the mail apps service, by ACME (RFC 8555) with the dns-01
 * challenge through Cloudflare's API: no root, no certbot, no port opened. The certificate and
 * its key go to the common folder of the server (MailGateway), where the service reads them;
 * renewed by the scheduled task 30 days before it ends.
 */
final class Acme
{
    public const DIRECTORY = 'https://acme-v02.api.letsencrypt.org/directory';

    /** @var ?callable test hook: fn(string $method, string $url, ?string $body): array{0:int,1:array<string,string>,2:string} */
    public static $transport = null;
    /** @var ?callable test hook: fn(int $seconds) */
    public static $sleep = null;

    private array $dir = [];
    private string $nonce = '';
    private string $kid = '';
    /** @var \OpenSSLAsymmetricKey */
    private $account;

    /** @param ?callable $say fn(string $step): each step told (the page shows them) */
    public function __construct(private $say = null)
    {
    }

    private function say(string $m): void
    {
        if ($this->say) {
            ($this->say)($m);
        }
    }

    private function wait(int $s): void
    {
        self::$sleep ? (self::$sleep)($s) : sleep($s);
    }

    // ------------------------------------------------------------------ HTTP and JWS

    /** @return array{0:int,1:array<string,string>,2:string} */
    private function http(string $method, string $url, ?string $body = null): array
    {
        if (self::$transport) {
            [$code, $headers, $out] = (self::$transport)($method, $url, $body);
        } else {
            $headers = [];
            $ch = curl_init($url);
            $proxy = trim((string) Config::get('outbound_proxy', ''));
            curl_setopt_array($ch, ($proxy !== '' ? [CURLOPT_PROXY => $proxy] : []) + [
                CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_NOBODY => $method === 'HEAD', CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $body !== null ? ['Content-Type: application/jose+json'] : [],
                CURLOPT_HEADERFUNCTION => static function ($ch, $h) use (&$headers) {
                    if (str_contains($h, ':')) {
                        [$k, $v] = explode(':', $h, 2);
                        $headers[strtolower(trim($k))] = trim($v);
                    }
                    return strlen($h);
                },
            ]);
            $out = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if (!is_string($out)) {
                throw new \RuntimeException("Let's Encrypt : " . $err);
            }
        }
        if (isset($headers['replay-nonce'])) {
            $this->nonce = $headers['replay-nonce'];
        }
        return [$code, $headers, $out];
    }

    public static function b64u(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    /** @return array{crv:string,kty:string,x:string,y:string} */
    private function jwk(): array
    {
        $d = openssl_pkey_get_details($this->account)['ec'];
        return ['crv' => 'P-256', 'kty' => 'EC', 'x' => self::b64u(str_pad($d['x'], 32, "\0", STR_PAD_LEFT)), 'y' => self::b64u(str_pad($d['y'], 32, "\0", STR_PAD_LEFT))];
    }

    public function thumbprint(): string
    {
        $j = $this->jwk();
        return self::b64u(hash('sha256', json_encode(['crv' => $j['crv'], 'kty' => $j['kty'], 'x' => $j['x'], 'y' => $j['y']]), true));
    }

    /** An ECDSA signature as JWS wants it: r and s, 32 bytes each (OpenSSL gives DER). */
    public static function rawSignature(string $der): string
    {
        $p = 2;
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$p + 1]);
            $int = ltrim(substr($der, $p + 2, $len), "\0");
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
            $p += 2 + $len;
        }
        return $out;
    }

    /** A signed request; $payload null: POST-as-GET. */
    private function post(string $url, ?array $payload, bool $jwk = false): array
    {
        for ($try = 0; $try < 2; $try++) {
            if ($this->nonce === '') {
                $this->http('HEAD', $this->dir['newNonce']);
            }
            $protected = ['alg' => 'ES256', 'nonce' => $this->nonce, 'url' => $url] + ($jwk ? ['jwk' => $this->jwk()] : ['kid' => $this->kid]);
            $this->nonce = '';
            $p64 = self::b64u((string) json_encode($protected, JSON_UNESCAPED_SLASHES));
            $b64 = $payload === null ? '' : self::b64u((string) json_encode($payload === [] ? new \stdClass() : $payload, JSON_UNESCAPED_SLASHES));
            openssl_sign($p64 . '.' . $b64, $sig, $this->account, OPENSSL_ALGO_SHA256);
            [$code, $headers, $out] = $this->http('POST', $url, (string) json_encode(['protected' => $p64, 'payload' => $b64, 'signature' => self::b64u(self::rawSignature($sig))]));
            $j = json_decode($out, true);
            // A nonce refused (they expire): once more with a fresh one.
            if ($code === 400 && is_array($j) && str_ends_with((string) ($j['type'] ?? ''), ':badNonce')) {
                continue;
            }
            if ($code >= 400) {
                throw new \RuntimeException("Let's Encrypt : " . (is_array($j) ? (string) ($j['detail'] ?? $j['type'] ?? '') : 'HTTP ' . $code));
            }
            return [$headers, is_array($j) ? $j : [], $out];
        }
        throw new \RuntimeException("Let's Encrypt : nonce refusé");
    }

    // ------------------------------------------------------------------ the certificate

    /**
     * Ask, prove (TXT record through Cloudflare), receive. Returns [certificate chain PEM, key PEM].
     * @return array{0:string,1:string}
     */
    public function issue(string $host, string $email): array
    {
        $this->say(t('acme.step_account'));
        [, , $out] = $this->http('GET', (string) Settings::get('maild.acme_directory', self::DIRECTORY));
        $this->dir = json_decode($out, true) ?: [];
        if (empty($this->dir['newOrder'])) {
            throw new \RuntimeException("Let's Encrypt : annuaire illisible");
        }
        // Kept in this instance's storage (the common folder may be emptied by a restart).
        @mkdir(Config::storagePath('maild'), 0750, true);
        $keyFile = Config::storagePath('maild') . '/acme-account.pem';
        $pem = (string) @file_get_contents($keyFile);
        $this->account = $pem !== '' ? openssl_pkey_get_private($pem) : false;
        if (!$this->account) {
            $this->account = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            openssl_pkey_export($this->account, $pem);
            @file_put_contents($keyFile, $pem);
            @chmod($keyFile, 0600);
        }
        [$h] = $this->post($this->dir['newAccount'], ['termsOfServiceAgreed' => true] + ($email !== '' ? ['contact' => ['mailto:' . $email]] : []), true);
        $this->kid = (string) ($h['location'] ?? '');

        $this->say(t('acme.step_order', ['host' => $host]));
        [$h, $order] = $this->post($this->dir['newOrder'], ['identifiers' => [['type' => 'dns', 'value' => $host]]]);
        $orderUrl = (string) ($h['location'] ?? '');
        $zone = Cloudflare::zoneFor($host);
        $records = [];
        try {
            $challenges = [];
            foreach ((array) $order['authorizations'] as $authUrl) {
                [, $auth] = $this->post($authUrl, null);
                if (($auth['status'] ?? '') === 'valid') {
                    continue;
                }
                $ch = array_values(array_filter((array) $auth['challenges'], static fn($c) => ($c['type'] ?? '') === 'dns-01'))[0] ?? null;
                if (!$ch) {
                    throw new \RuntimeException("Let's Encrypt : pas de défi DNS");
                }
                $value = self::b64u(hash('sha256', $ch['token'] . '.' . $this->thumbprint(), true));
                $this->say(t('acme.step_txt'));
                $records[] = Cloudflare::upsert($zone['id'], 'TXT', '_acme-challenge.' . $auth['identifier']['value'], $value, 60);
                $challenges[] = [$authUrl, $ch['url']];
            }
            if ($challenges) {
                // Cloudflare's servers have it within seconds; Let's Encrypt asks them directly.
                $this->say(t('acme.step_wait'));
                $this->wait(20);
                foreach ($challenges as [, $chUrl]) {
                    $this->post($chUrl, []);
                }
                foreach ($challenges as [$authUrl]) {
                    for ($i = 0; ; $i++) {
                        [, $auth] = $this->post($authUrl, null);
                        if (($auth['status'] ?? '') === 'valid') {
                            break;
                        }
                        if (($auth['status'] ?? '') === 'invalid' || $i > 30) {
                            $why = '';
                            foreach ((array) ($auth['challenges'] ?? []) as $c) {
                                $why .= (string) ($c['error']['detail'] ?? '');
                            }
                            throw new \RuntimeException(t('acme.refused', ['why' => $why ?: (string) ($auth['status'] ?? '?')]));
                        }
                        $this->wait(3);
                    }
                }
            }
        } finally {
            foreach ($records as $r) {
                Cloudflare::delete($zone['id'], $r);
            }
        }

        $this->say(t('acme.step_cert'));
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $cnf = tempnam(sys_get_temp_dir(), 'm4wcsr');
        file_put_contents($cnf, "[req]\ndistinguished_name=dn\nreq_extensions=ext\n[dn]\n[ext]\nsubjectAltName=DNS:$host\n");
        $csr = openssl_csr_new(['commonName' => $host], $key, ['config' => $cnf, 'req_extensions' => 'ext', 'digest_alg' => 'sha256']);
        @unlink($cnf);
        openssl_csr_export($csr, $csrPem);
        $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s/', '', $csrPem));
        [, $order] = $this->post((string) $order['finalize'], ['csr' => self::b64u($der)]);
        for ($i = 0; ($order['status'] ?? '') !== 'valid'; $i++) {
            if (($order['status'] ?? '') === 'invalid' || $i > 30) {
                throw new \RuntimeException("Let's Encrypt : commande refusée");
            }
            $this->wait(2);
            [, $order] = $this->post($orderUrl, null);
        }
        [, , $chain] = $this->post((string) $order['certificate'], null);
        openssl_pkey_export($key, $keyPem);
        if (!openssl_x509_parse($chain)) {
            throw new \RuntimeException("Let's Encrypt : certificat illisible");
        }
        return [$chain, $keyPem];
    }

    /**
     * The whole setting up, one click: the token checked, the service's name in the DNS
     * ("DNS only", to this server), the certificate asked for and put in place. Run by
     * bin/cert.php (it takes a minute or two), each step written for the page.
     */
    public static function setUp(bool $dnsRecord, string $ip = ''): void
    {
        $status = MailGateway::dir() . '/cert-status.json';
        $steps = [];
        $say = static function (string $m, string $state = 'run') use (&$steps, $status): void {
            // The step under way: done, or the one that failed.
            if ($steps && end($steps)['state'] === 'run') {
                $steps[count($steps) - 1]['state'] = $state === 'fail' ? 'fail' : 'ok';
            }
            $steps[] = ['text' => $m, 'state' => $state];
            @file_put_contents($status, json_encode(['at' => time(), 'running' => $state === 'run', 'steps' => $steps]));
        };
        try {
            $c = MailGateway::config();
            $say(t('acme.step_token'));
            Cloudflare::verify();
            if ($dnsRecord) {
                $ip = $ip !== '' ? $ip : Cloudflare::publicIp();
                if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                    throw new \RuntimeException(t('acme.no_ip'));
                }
                $say(t('acme.step_dns', ['host' => $c['host'], 'ip' => $ip]));
                $zone = Cloudflare::zoneFor($c['host']);
                Cloudflare::upsert($zone['id'], str_contains($ip, ':') ? 'AAAA' : 'A', $c['host'], $ip);
            }
            [$chain, $key] = (new self($say))->issue($c['host'], (string) Settings::get('maild.acme_email', ''));
            // In this instance's storage (lasting, readable by the service, which runs as the
            // web server's account); the shared settings point at it for every instance.
            $d = Config::storagePath('maild');
            @mkdir($d, 0750, true);
            file_put_contents($d . '/fullchain.pem.tmp', $chain);
            file_put_contents($d . '/privkey.pem.tmp', $key);
            @chmod($d . '/privkey.pem.tmp', 0640);
            rename($d . '/fullchain.pem.tmp', $d . '/fullchain.pem');
            rename($d . '/privkey.pem.tmp', $d . '/privkey.pem');
            MailGateway::saveConfig(['cert' => $d . '/fullchain.pem', 'key' => $d . '/privkey.pem']);
            Settings::set('maild.acme_host', $c['host']);
            $until = (int) (openssl_x509_parse($chain)['validTo_time_t'] ?? 0);
            $say(t('acme.done', ['date' => format_datetime($until, false)]), 'ok');
            \M4W\Core\Audit::log('maild.certificate', $c['host'], ['until' => $until]);
        } catch (\Throwable $e) {
            $say($e->getMessage(), 'fail');
            error_log('[m4w] certificate: ' . $e->getMessage());
        }
    }

    /** Started in the background from the page (a minute or two). */
    public static function start(bool $dnsRecord, string $ip): void
    {
        @file_put_contents(MailGateway::dir() . '/cert-status.json', json_encode(['at' => time(), 'running' => true, 'steps' => [['text' => t('acme.step_start'), 'state' => 'run']]]));
        exec(implode(' ', array_map('escapeshellarg', [php_cli(), M4W_ROOT . '/bin/cert.php', $dnsRecord ? '--dns' : '--no-dns', '--ip=' . $ip]))
            . ' >> ' . escapeshellarg(MailGateway::dir() . '/maild.log') . ' 2>&1 < /dev/null &');
    }

    public static function status(): array
    {
        $s = json_decode((string) @file_get_contents(MailGateway::dir() . '/cert-status.json'), true) ?: [];
        // Said to run, but silent for two minutes: it did not start, or stopped (see the log).
        if (!empty($s['running']) && (int) ($s['at'] ?? 0) < time() - 120) {
            $s['running'] = false;
            $s['steps'][] = ['text' => t('acme.stalled', ['log' => MailGateway::dir() . '/maild.log', 'cmd' => php_cli() . ' ' . M4W_ROOT . '/bin/cert.php --dns']), 'state' => 'fail'];
        }
        return $s;
    }

    /** Scheduled task, daily: renewed 30 days before the end, when it was made here. */
    public static function renewIfDue(): string
    {
        $c = MailGateway::config();
        if (Cloudflare::token() === '' || (string) Settings::get('maild.acme_host', '') !== $c['host'] || !is_readable($c['cert'])) {
            return 'not managed here';
        }
        $until = (int) (openssl_x509_parse((string) file_get_contents($c['cert']))['validTo_time_t'] ?? 0);
        if ($until > time() + 30 * 86400) {
            return 'valid until ' . date('Y-m-d', $until);
        }
        self::setUp(false);
        $s = self::status();
        return (string) (end($s['steps'])['text'] ?? '');
    }
}
