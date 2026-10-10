<?php
declare(strict_types=1);

namespace M4W\Mail;

use M4W\Core\App;

/**
 * Inbound sender authentication: SPF (RFC 7208), DKIM verification (RFC 6376)
 * and DMARC alignment (RFC 7489). DNS is pluggable for tests.
 */
final class MailAuth
{
    /** @var callable|null fn(string $type, string $name): array  type = TXT|A|AAAA|MX */
    public static $resolver = null;

    private const MAX_LOOKUPS = 10;
    private const SLD = ['co', 'com', 'net', 'org', 'gov', 'ac', 'edu', 'gouv', 'asso', 'ne', 'or', 'go'];

    // ---------------------------------------------------------------- DNS

    private static function dns(string $type, string $name): array
    {
        $name = rtrim($name, '.');
        if ($name === '' || strlen($name) > 253) {
            return [];
        }
        if (self::$resolver) {
            return (array) (self::$resolver)($type, $name);
        }
        $const = ['TXT' => DNS_TXT, 'A' => DNS_A, 'AAAA' => DNS_AAAA, 'MX' => DNS_MX][$type] ?? null;
        if ($const === null) {
            return [];
        }
        $out = [];
        foreach (@dns_get_record($name, $const) ?: [] as $r) {
            $out[] = match ($type) {
                'TXT' => isset($r['entries']) ? implode('', $r['entries']) : (string) ($r['txt'] ?? ''),
                'A' => (string) ($r['ip'] ?? ''),
                'AAAA' => (string) ($r['ipv6'] ?? ''),
                'MX' => (string) ($r['target'] ?? ''),
            };
        }
        return array_values(array_filter($out, static fn($v) => $v !== ''));
    }

    // ---------------------------------------------------------------- SPF

    /** @return string pass|fail|softfail|neutral|none|permerror|temperror */
    public static function spf(string $ip, string $domain): string
    {
        $lookups = 0;
        return self::spfCheck($ip, mb_strtolower(trim($domain, '.')), $lookups, 0);
    }

    private static function spfCheck(string $ip, string $domain, int &$lookups, int $depth): string
    {
        if ($domain === '' || $depth > 10 || !preg_match('/^[a-z0-9_.-]+$/i', $domain)) {
            return 'none';
        }
        $records = array_values(array_filter(self::dns('TXT', $domain), static fn($t) => preg_match('/^v=spf1(\s|$)/i', $t)));
        if (!$records) {
            return 'none';
        }
        if (count($records) > 1) {
            return 'permerror';
        }
        $redirect = null;
        foreach (preg_split('/\s+/', trim(substr($records[0], 6))) ?: [] as $term) {
            if ($term === '') {
                continue;
            }
            if (preg_match('/^redirect=(.+)$/i', $term, $m)) {
                $redirect = $m[1];
                continue;
            }
            if (str_contains($term, '=')) {
                continue; // other modifiers (exp=…)
            }
            $q = '+';
            if (strpbrk($term[0], '+-~?') !== false) {
                $q = $term[0];
                $term = substr($term, 1);
            }
            [$mech, $arg] = array_pad(preg_split('/[:\/]/', $term, 2) ?: [], 2, null);
            $mech = strtolower((string) $mech);
            $arg = $arg !== null ? substr($term, strlen($mech) + ($term[strlen($mech)] === ':' ? 1 : 0)) : '';
            if (str_contains($arg, '%')) {
                continue; // macros are not supported: the mechanism never matches
            }
            $match = false;
            switch ($mech) {
                case 'all':
                    $match = true;
                    break;
                case 'ip4':
                case 'ip6':
                    $match = $arg !== '' && App::ipInCidr($ip, $arg);
                    break;
                case 'a':
                case 'mx':
                    if (++$lookups > self::MAX_LOOKUPS) {
                        return 'permerror';
                    }
                    [$host, $len4, $len6] = self::spfTarget($arg, $domain);
                    $hosts = $mech === 'a' ? [$host] : array_slice(self::dns('MX', $host), 0, 10);
                    foreach ($hosts as $h) {
                        foreach (array_merge(self::dns('A', $h), self::dns('AAAA', $h)) as $addr) {
                            $len = str_contains($addr, ':') ? $len6 : $len4;
                            if (App::ipInCidr($ip, $addr . ($len !== null ? '/' . $len : ''))) {
                                $match = true;
                                break 2;
                            }
                        }
                    }
                    break;
                case 'include':
                    if (++$lookups > self::MAX_LOOKUPS) {
                        return 'permerror';
                    }
                    $r = self::spfCheck($ip, mb_strtolower($arg), $lookups, $depth + 1);
                    if ($r === 'pass') {
                        $match = true;
                    } elseif ($r === 'permerror' || $r === 'none') {
                        return 'permerror';
                    } elseif ($r === 'temperror') {
                        return 'temperror';
                    }
                    break;
                case 'exists':
                    if (++$lookups > self::MAX_LOOKUPS) {
                        return 'permerror';
                    }
                    $match = (bool) self::dns('A', $arg);
                    break;
                default:
                    break; // ptr (deprecated) and unknown mechanisms never match
            }
            if ($match) {
                return ['+' => 'pass', '-' => 'fail', '~' => 'softfail', '?' => 'neutral'][$q];
            }
        }
        if ($redirect !== null) {
            if (++$lookups > self::MAX_LOOKUPS) {
                return 'permerror';
            }
            $r = self::spfCheck($ip, mb_strtolower($redirect), $lookups, $depth + 1);
            return $r === 'none' ? 'permerror' : $r;
        }
        return 'neutral';
    }

    /** "a:host/24//64" → [host, 24, 64] */
    private static function spfTarget(string $arg, string $domain): array
    {
        $len4 = $len6 = null;
        if (preg_match('#^(.*?)(?:/(\d{1,2}))?(?://(\d{1,3}))?$#', $arg, $m)) {
            $arg = $m[1];
            $len4 = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;
            $len6 = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;
        }
        return [$arg !== '' ? mb_strtolower($arg) : $domain, $len4, $len6];
    }

    // --------------------------------------------------------------- DKIM

    /**
     * Verify every DKIM-Signature (at most 5).
     * @return array<int, array{domain:string, result:string}>  result = pass|fail|neutral|permerror
     */
    public static function dkim(string $raw): array
    {
        $raw = str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $raw));
        $pos = strpos($raw, "\r\n\r\n");
        if ($pos === false) {
            return [];
        }
        $headerBlock = substr($raw, 0, $pos);
        $body = substr($raw, $pos + 4);
        $headers = [];
        foreach (preg_split("/\r\n(?![ \t])/", $headerBlock) ?: [] as $h) {
            $colon = strpos($h, ':');
            if ($colon !== false) {
                $headers[] = [strtolower(trim(substr($h, 0, $colon))), $h];
            }
        }
        $out = [];
        foreach ($headers as $hdr) {
            if ($hdr[0] !== 'dkim-signature' || count($out) >= 5) {
                continue;
            }
            $out[] = self::verifyOne($hdr[1], $headers, $body);
        }
        return $out;
    }

    private static function verifyOne(string $sigHeader, array $headers, string $body): array
    {
        $tags = [];
        $value = substr($sigHeader, strpos($sigHeader, ':') + 1);
        foreach (explode(';', $value) as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) === 2) {
                $tags[strtolower(trim($kv[0]))] = preg_replace('/\s+/', '', $kv[1]) ?? '';
            }
        }
        $domain = mb_strtolower($tags['d'] ?? '');
        $res = static fn(string $r) => ['domain' => $domain, 'result' => $r];
        if (($tags['v'] ?? '') !== '1' || $domain === '' || empty($tags['s']) || empty($tags['h']) || !isset($tags['b'], $tags['bh'])) {
            return $res('permerror');
        }
        if (strtolower($tags['a'] ?? '') !== 'rsa-sha256') {
            return $res('neutral'); // rsa-sha1 is obsolete, ed25519 not supported by OpenSSL bindings here
        }
        if (!in_array('from', array_map('strtolower', explode(':', $tags['h'])), true)) {
            return $res('permerror');
        }
        if (isset($tags['x']) && ctype_digit($tags['x']) && (int) $tags['x'] < time()) {
            return $res('fail');
        }
        [$hc, $bc] = array_pad(explode('/', strtolower($tags['c'] ?? 'simple/simple')), 2, 'simple');
        $canonBody = $bc === 'relaxed' ? self::relaxedBody($body) : self::simpleBody($body);
        if (isset($tags['l']) && ctype_digit($tags['l'])) {
            $canonBody = substr($canonBody, 0, (int) $tags['l']);
        }
        if (!hash_equals(base64_decode($tags['bh']) ?: '-', hash('sha256', $canonBody, true))) {
            return $res('fail');
        }
        // Headers in h= order, bottom-up for repeated names.
        $used = [];
        $data = '';
        foreach (explode(':', $tags['h']) as $name) {
            $name = strtolower(trim($name));
            $count = 0;
            for ($i = count($headers) - 1; $i >= 0; $i--) {
                if ($headers[$i][0] !== $name) {
                    continue;
                }
                if ($count++ < ($used[$name] ?? 0)) {
                    continue;
                }
                $used[$name] = ($used[$name] ?? 0) + 1;
                $data .= ($hc === 'relaxed' ? self::relaxedHeader($headers[$i][1]) : $headers[$i][1]) . "\r\n";
                break;
            }
        }
        $unsigned = preg_replace('/(\bb=)[^;]*/', '$1', $sigHeader, 1) ?? $sigHeader;
        $data .= $hc === 'relaxed' ? self::relaxedHeader($unsigned) : $unsigned;

        $keyTxt = self::dns('TXT', $tags['s'] . '._domainkey.' . $domain);
        if (!$keyTxt) {
            return $res('permerror');
        }
        $ktags = [];
        foreach (explode(';', $keyTxt[0]) as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) === 2) {
                $ktags[strtolower(trim($kv[0]))] = preg_replace('/\s+/', '', $kv[1]) ?? '';
            }
        }
        if (($ktags['k'] ?? 'rsa') !== 'rsa' || empty($ktags['p'])) {
            return $res(empty($ktags['p']) ? 'fail' : 'neutral');
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($ktags['p'], 64, "\n") . "-----END PUBLIC KEY-----\n";
        $key = @openssl_pkey_get_public($pem);
        if ($key === false) {
            return $res('permerror');
        }
        $ok = openssl_verify($data, base64_decode($tags['b']) ?: '', $key, OPENSSL_ALGO_SHA256);
        return $res($ok === 1 ? 'pass' : 'fail');
    }

    private static function relaxedHeader(string $h): string
    {
        $colon = strpos($h, ':');
        $name = strtolower(trim(substr($h, 0, $colon)));
        $value = substr($h, $colon + 1);
        $value = preg_replace("/\r\n[ \t]+/", ' ', $value) ?? $value;
        $value = preg_replace('/[ \t]+/', ' ', $value) ?? $value;
        return $name . ':' . trim($value);
    }

    private static function relaxedBody(string $body): string
    {
        $lines = explode("\r\n", $body);
        foreach ($lines as &$l) {
            $l = rtrim(preg_replace('/[ \t]+/', ' ', $l) ?? $l, " \t");
        }
        unset($l);
        $body = rtrim(implode("\r\n", $lines), "\r\n");
        return $body === '' ? '' : $body . "\r\n";
    }

    private static function simpleBody(string $body): string
    {
        $body = preg_replace("/(\r\n)+$/", '', $body) ?? $body;
        return $body . "\r\n";
    }

    // -------------------------------------------------------------- DMARC

    public static function orgDomain(string $domain): string
    {
        $labels = explode('.', mb_strtolower(trim($domain, '.')));
        $n = count($labels);
        if ($n <= 2) {
            return implode('.', $labels);
        }
        $take = (strlen($labels[$n - 1]) === 2 && in_array($labels[$n - 2], self::SLD, true)) ? 3 : 2;
        return implode('.', array_slice($labels, -$take));
    }

    private static function aligned(string $a, string $b, bool $strict): bool
    {
        $a = mb_strtolower($a);
        $b = mb_strtolower($b);
        return $strict ? $a === $b : ($a !== '' && self::orgDomain($a) === self::orgDomain($b));
    }

    /**
     * @param array<int, array{domain:string,result:string}> $dkim
     * @return array{result:string, policy:string}  result = pass|fail|none
     */
    public static function dmarc(string $fromDomain, string $spf, string $spfDomain, array $dkim): array
    {
        $fromDomain = mb_strtolower($fromDomain);
        $record = null;
        foreach (array_unique([$fromDomain, self::orgDomain($fromDomain)]) as $d) {
            foreach (self::dns('TXT', '_dmarc.' . $d) as $txt) {
                if (preg_match('/^v=DMARC1\s*(;|$)/i', $txt)) {
                    $record = $txt;
                    $sub = $d !== $fromDomain;
                    break 2;
                }
            }
        }
        if ($record === null) {
            return ['result' => 'none', 'policy' => 'none'];
        }
        $tags = [];
        foreach (explode(';', $record) as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) === 2) {
                $tags[strtolower(trim($kv[0]))] = strtolower(trim($kv[1]));
            }
        }
        $policy = ($sub ?? false) && isset($tags['sp']) ? $tags['sp'] : ($tags['p'] ?? 'none');
        if (!in_array($policy, ['none', 'quarantine', 'reject'], true)) {
            $policy = 'none';
        }
        $pass = $spf === 'pass' && self::aligned($spfDomain, $fromDomain, ($tags['aspf'] ?? 'r') === 's');
        foreach ($dkim as $d) {
            if ($d['result'] === 'pass' && self::aligned($d['domain'], $fromDomain, ($tags['adkim'] ?? 'r') === 's')) {
                $pass = true;
            }
        }
        return ['result' => $pass ? 'pass' : 'fail', 'policy' => $policy];
    }

    // ------------------------------------------------------------ summary

    /**
     * Full evaluation of a message received over SMTP.
     * @param callable(string):bool $isLocalDomain
     */
    public static function evaluate(string $raw, string $ip, string $mailFrom, string $helo, callable $isLocalDomain): array
    {
        $parsed = MimeParser::parse($raw);
        $fromList = [];
        foreach ($parsed->headerAll('From') as $h) {
            $fromList = array_merge($fromList, Address::parseList($h));
        }
        $fromDomain = $fromList ? Address::domain($fromList[0]['email']) : '';
        $spfDomain = $mailFrom !== '' ? Address::domain($mailFrom) : $helo;
        $spf = ($ip !== '' && $spfDomain !== '') ? self::spf($ip, $spfDomain) : 'none';
        $dkim = self::dkim($raw);
        $dmarc = $fromDomain !== '' ? self::dmarc($fromDomain, $spf, $spfDomain, $dkim) : ['result' => 'none', 'policy' => 'none'];

        // Our own domains are authenticated only by an aligned SPF or DKIM pass,
        // even when they publish no DMARC record.
        $local = $fromDomain !== '' && $isLocalDomain($fromDomain);
        $alignedLocal = ($spf === 'pass' && self::aligned($spfDomain, $fromDomain, false))
            || (bool) array_filter($dkim, static fn($d) => $d['result'] === 'pass' && self::aligned($d['domain'], $fromDomain, false));
        $verdict = 'ok';
        if (count($fromList) !== 1) {
            $verdict = 'suspicious';
        } elseif ($local && !$alignedLocal) {
            $verdict = 'spoof';
        } elseif ($dmarc['result'] === 'fail' && $dmarc['policy'] !== 'none') {
            $verdict = 'dmarc';
        }
        $dkimSummary = $dkim ? implode(',', array_map(static fn($d) => $d['result'] . '@' . $d['domain'], $dkim)) : 'none';
        return [
            'spf' => $spf, 'spf_domain' => $spfDomain, 'dkim' => $dkim, 'dkim_summary' => $dkimSummary,
            'dmarc' => $dmarc['result'], 'policy' => $dmarc['policy'], 'from_domain' => $fromDomain, 'verdict' => $verdict,
        ];
    }

    /** Header lines added on delivery (the X-M4W-Auth line is what the webmail trusts). */
    public static function headers(array $a, string $hostname): string
    {
        $clean = static fn(string $s) => preg_replace('/[^\w.@,\-]/', '', $s) ?? '';
        $dkim = $a['dkim'] ? implode(' ', array_map(static fn($d) => 'dkim=' . $d['result'] . ' header.d=' . $clean($d['domain']), $a['dkim'])) : 'dkim=none';
        return 'Authentication-Results: ' . $clean($hostname) . '; spf=' . $a['spf'] . ' smtp.mailfrom=' . $clean($a['spf_domain'])
            . '; ' . $dkim . '; dmarc=' . $a['dmarc'] . ' (p=' . $a['policy'] . ') header.from=' . $clean($a['from_domain']) . "\n"
            . 'X-M4W-Auth: verdict=' . $a['verdict'] . '; spf=' . $a['spf'] . '; dkim=' . $clean($a['dkim_summary']) . '; dmarc=' . $a['dmarc'] . '; policy=' . $a['policy'] . "\n";
    }

    /** Parse our X-M4W-Auth header back. */
    public static function parse(string $header): array
    {
        $out = [];
        foreach (explode(';', $header) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) === 2) {
                $out[$kv[0]] = $kv[1];
            }
        }
        return $out;
    }
}
