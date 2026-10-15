<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Config;
use M4W\Core\Database as DB;
use M4W\Core\Net;
use M4W\Core\Settings;
use M4W\Mail\Address;
use M4W\Mail\Homograph;
use M4W\Mail\MailAuth;
use M4W\Mail\ParsedMessage;

/**
 * BIMI: the sender's logo instead of their initials. Shown only when it cannot be borrowed:
 *  - the message passes DMARC for the domain of its From, and that domain enforces DMARC
 *    (p=quarantine or reject on all its mail);
 *  - nothing else about the sender looks like an imitation (look-alike or homograph domain,
 *    a colleague's name from outside, several From addresses);
 *  - optionally (administrator's choice), a Verified Mark Certificate proves the logo belongs
 *    to the holder of the brand: a domain anyone can register cannot show a bank's logo.
 * The server downloads the logo once per domain (public addresses only, small, HTTPS), keeps
 * only a cleaned SVG (no script, no link, no external reference) and serves it itself: the
 * browser never contacts the sender, who cannot learn the message was opened.
 */
final class Bimi
{
    /** Tests: fn(string $url): ?array{status:int,body:string,location:string} */
    public static $http = null;
    /** Tests: PEM of the trusted mark certificate roots, instead of the bundled file. */
    public static ?string $roots = null;

    private const MAX_SVG = 65536;
    private const MAX_PEM = 65536;
    private const TTL_OK = 86400;
    private const TTL_NONE = 6 * 3600;
    private const EKU_BIMI = '1.3.6.1.5.5.7.3.31';

    /** off | dmarc (DMARC enforced) | vmc (and a verified mark certificate) */
    public static function mode(): string
    {
        $m = (string) Settings::get('security.bimi', 'dmarc');
        return in_array($m, ['off', 'dmarc', 'vmc'], true) ? $m : 'dmarc';
    }

    // ------------------------------------------------------------ eligibility

    /**
     * The domain whose logo goes with this message, or ''.
     * @param ?array $auth SMTP evaluation (MailAuth::evaluate) when the message reached our server directly
     */
    public static function forMessage(ParsedMessage $p, string $raw, ?array $auth, string $source): string
    {
        if (self::mode() === 'off' || $source === 'local') {
            return '';
        }
        try {
            $from = [];
            foreach ($p->headerAll('From') as $h) {
                $from = array_merge($from, Address::parseList($h));
            }
            if (count($from) !== 1) {
                return '';
            }
            $domain = mb_strtolower(Address::domain($from[0]['email']));
            if ($domain === '' || !preg_match('/^[a-z0-9.-]{1,190}$/', $domain) || Homograph::isIdn($domain)
                || SpamFilter::lookalikeDomain($domain) !== null || SpamFilter::colleagueName($p) !== null) {
                return '';
            }
            // DMARC: the server's own evaluation, or the DKIM signature aligned with the From
            // (mail collected at a provider: no connecting address to check SPF against).
            if ($auth !== null) {
                $pass = ($auth['dmarc'] ?? '') === 'pass' && mb_strtolower((string) ($auth['from_domain'] ?? '')) === $domain;
            } else {
                $pass = MailAuth::dmarc($domain, 'none', '', MailAuth::dkim($raw))['result'] === 'pass';
            }
            if (!$pass || !self::enforced($domain)) {
                return '';
            }
            $logo = self::logo($domain);
            if (!$logo || $logo['status'] !== 'ok' || (self::mode() === 'vmc' && !(int) $logo['vmc'])) {
                return '';
            }
            return (string) $logo['domain'];
        } catch (\Throwable $e) {
            error_log('[m4w] bimi: ' . $e->getMessage());
            return '';
        }
    }

    /** DMARC applied to all the domain's mail: p (or sp for a subdomain) quarantine/reject, pct 100. */
    public static function enforced(string $domain): bool
    {
        $org = MailAuth::orgDomain($domain);
        foreach (array_unique([$domain, $org]) as $d) {
            foreach (MailAuth::txt('_dmarc.' . $d) as $txt) {
                if (!preg_match('/^v=DMARC1\s*(;|$)/i', $txt)) {
                    continue;
                }
                $tags = self::tags($txt);
                $policy = $d !== $domain && isset($tags['sp']) ? $tags['sp'] : ($tags['p'] ?? 'none');
                return in_array($policy, ['quarantine', 'reject'], true) && (!isset($tags['pct']) || (int) $tags['pct'] === 100);
            }
        }
        return false;
    }

    /** @return array<string,string> tags of a "k=v; k=v" record, keys lower-case */
    private static function tags(string $record): array
    {
        $out = [];
        foreach (explode(';', $record) as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) === 2) {
                $out[strtolower(trim($kv[0]))] = trim($kv[1]);
            }
        }
        foreach (['p', 'sp'] as $k) {
            if (isset($out[$k])) {
                $out[$k] = strtolower($out[$k]);
            }
        }
        return $out;
    }

    /** The BIMI record of a domain (or of its organisational domain): [domain, l, a] or null. */
    public static function record(string $domain): ?array
    {
        foreach (array_unique([$domain, MailAuth::orgDomain($domain)]) as $d) {
            $recs = array_values(array_filter(MailAuth::txt('default._bimi.' . $d), static fn($t) => (bool) preg_match('/^v=BIMI1\s*(;|$)/i', $t)));
            if (count($recs) === 1) {
                $t = self::tags($recs[0]);
                return [$d, (string) ($t['l'] ?? ''), (string) ($t['a'] ?? '')];
            }
            if (count($recs) > 1) {
                return null; // ambiguous: none
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ logos

    /**
     * Logo of a domain, from the cache or fetched now.
     * @return ?array{domain:string,status:string,file:string,vmc:int,error:string,checked_at:int}
     */
    public static function logo(string $domain, bool $force = false): ?array
    {
        $rec = self::record($domain);
        $key = $rec ? $rec[0] : $domain;
        $row = DB::one('SELECT * FROM bimi_logos WHERE domain = :d', ['d' => $key]);
        if ($row && !$force && (int) $row['checked_at'] > time() - ((string) $row['status'] === 'ok' ? self::TTL_OK : self::TTL_NONE)) {
            return $row;
        }
        $new = ['domain' => $key, 'status' => 'none', 'file' => '', 'vmc' => 0, 'error' => '', 'checked_at' => time()];
        if ($rec && $rec[1] !== '') {
            [$svg, $vmc, $err] = self::fetchLogo($key, $rec[1], $rec[2]);
            $new['vmc'] = $vmc ? 1 : 0;
            $new['error'] = mb_substr($err, 0, 250);
            if ($svg !== null) {
                $file = hash('sha256', $svg) . '.svg';
                $dir = Config::storagePath('bimi');
                if (!is_dir($dir)) {
                    @mkdir($dir, 0750, true);
                }
                if (!is_file($dir . '/' . $file)) {
                    file_put_contents($dir . '/' . $file, $svg, LOCK_EX);
                }
                $new['status'] = 'ok';
                $new['file'] = $file;
            } else {
                $new['status'] = 'invalid';
            }
        }
        if ($row) {
            DB::update('bimi_logos', $new, 'id = :id', ['id' => $row['id']]);
        } else {
            DB::insert('bimi_logos', $new);
        }
        return $new;
    }

    /** @return array{0:?string,1:bool,2:string} cleaned SVG, mark certificate verified, why not */
    private static function fetchLogo(string $domain, string $l, string $a): array
    {
        $vmcSvg = null;
        $err = '';
        if ($a !== '') {
            [$vmcSvg, $err] = self::verifyVmc($domain, $a);
        }
        if ($vmcSvg !== null) {
            // The picture inside the certificate is the one the authority checked.
            $clean = self::sanitizeSvg($vmcSvg);
            return $clean !== null ? [$clean, true, ''] : [null, false, 'certificate logo is not a valid SVG'];
        }
        if (!preg_match('#^https://#i', $l)) {
            return [null, false, 'logo address must be https'];
        }
        $bytes = self::download($l, self::MAX_SVG);
        if ($bytes === null) {
            return [null, false, 'logo unreachable or too large'];
        }
        if (str_starts_with($bytes, "\x1f\x8b")) {
            $bytes = (string) @gzdecode($bytes, self::MAX_SVG);
        }
        $clean = self::sanitizeSvg($bytes);
        return $clean !== null ? [$clean, false, $err] : [null, false, 'not a valid SVG logo'];
    }

    /** Stored, cleaned logo of a domain for display, or null. */
    public static function file(string $domain): ?string
    {
        if (!preg_match('/^[a-z0-9.-]{1,190}$/', $domain)) {
            return null;
        }
        $row = DB::one("SELECT file FROM bimi_logos WHERE domain = :d AND status = 'ok'", ['d' => $domain]);
        $path = $row ? Config::storagePath('bimi/' . $row['file']) : '';
        return $row && preg_match('/^[a-f0-9]{64}\.svg$/', (string) $row['file']) && is_file($path) ? $path : null;
    }

    /** Whether the logo of a domain is backed by a verified mark certificate. */
    public static function verified(string $domain): bool
    {
        return (bool) DB::value("SELECT vmc FROM bimi_logos WHERE domain = :d AND status = 'ok'", ['d' => $domain]);
    }

    // -------------------------------------------------------------------- SVG

    private const SVG_ELEMENTS = ['svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'title', 'desc', 'defs',
        'lineargradient', 'radialgradient', 'stop', 'text', 'tspan', 'use', 'clippath', 'solidcolor'];
    private const SVG_ATTRS = ['id', 'version', 'baseprofile', 'viewbox', 'width', 'height', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry',
        'fx', 'fy', 'd', 'points', 'transform', 'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-opacity', 'stroke-linecap',
        'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'opacity', 'offset', 'stop-color', 'stop-opacity',
        'gradientunits', 'gradienttransform', 'spreadmethod', 'clip-path', 'clip-rule', 'clippathunits', 'font-family', 'font-size', 'font-weight',
        'font-style', 'text-anchor', 'letter-spacing', 'preserveaspectratio', 'color', 'solid-color', 'solid-opacity', 'display', 'visibility',
        'vector-effect', 'href', 'xlink:href', 'xmlns', 'xmlns:xlink', 'style', 'class'];

    /**
     * A logo reduced to drawing: only shapes, gradients and text; no script, event handler,
     * link, foreign content, embedded image, external reference or entity. Null if not an SVG.
     */
    public static function sanitizeSvg(string $svg): ?string
    {
        if ($svg === '' || strlen($svg) > self::MAX_SVG || preg_match('/<!(DOCTYPE|ENTITY)/i', $svg)) {
            return null;
        }
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($svg, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->documentElement;
        if (!$ok || !$root || strtolower($root->localName) !== 'svg') {
            return null;
        }
        self::cleanNode($root);
        if (!$root->hasAttribute('viewBox') && $root->hasAttribute('width') && $root->hasAttribute('height')) {
            $root->setAttribute('viewBox', '0 0 ' . (float) $root->getAttribute('width') . ' ' . (float) $root->getAttribute('height'));
        }
        $out = $doc->saveXML($root);
        return is_string($out) && $out !== '' ? '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $out : null;
    }

    private static function cleanNode(\DOMElement $el): void
    {
        foreach (iterator_to_array($el->attributes ?? []) as $attr) {
            $name = strtolower($attr->nodeName);
            $v = trim((string) $attr->nodeValue);
            $keep = in_array($name, self::SVG_ATTRS, true) && !preg_match('/javascript:|vbscript:|data:|expression\s*\(|@import|behavior\s*:/i', $v);
            if ($keep && ($name === 'href' || $name === 'xlink:href')) {
                $keep = (bool) preg_match('/^#[\w.-]+$/', $v); // inside the logo only
            }
            if ($keep && preg_match('/url\s*\(/i', $v)) {
                $keep = !preg_match('/url\s*\(\s*[\'"]?\s*(?!#)/i', $v); // url(#gradient) only
            }
            if (!$keep) {
                $el->removeAttributeNode($attr);
            }
        }
        foreach (iterator_to_array($el->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                if (!in_array(strtolower($child->localName), self::SVG_ELEMENTS, true) || ($child->namespaceURI !== null && $child->namespaceURI !== 'http://www.w3.org/2000/svg')) {
                    $el->removeChild($child);
                    continue;
                }
                self::cleanNode($child);
            } elseif (!($child instanceof \DOMText) || $child instanceof \DOMCdataSection) {
                $el->removeChild($child); // comments, processing instructions, CDATA
            }
        }
    }

    // ------------------------------------------------------- mark certificate

    /**
     * Verified Mark Certificate (or Common Mark Certificate): chain up to a trusted mark
     * authority, valid now, made for BIMI, naming this domain. Returns its embedded logo.
     * @return array{0:?string,1:string} SVG from the certificate, or why it was refused
     */
    public static function verifyVmc(string $domain, string $url): array
    {
        if (!preg_match('#^https://#i', $url)) {
            return [null, 'certificate address must be https'];
        }
        $pem = self::download($url, self::MAX_PEM);
        if ($pem === null) {
            return [null, 'certificate unreachable'];
        }
        return self::checkVmcPem($domain, $pem);
    }

    /** @return array{0:?string,1:string} */
    public static function checkVmcPem(string $domain, string $pem): array
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----[A-Za-z0-9+\/=\s]+-----END CERTIFICATE-----/', $pem, $m);
        $certs = [];
        foreach (array_slice($m[0], 0, 6) as $c) {
            $x = @openssl_x509_read($c);
            if ($x === false) {
                return [null, 'unreadable certificate'];
            }
            $certs[] = ['pem' => $c, 'x' => $x, 'info' => openssl_x509_parse($x)];
        }
        if (!$certs) {
            return [null, 'no certificate'];
        }
        $leaf = $certs[0]['info'];
        $now = time();
        if (($leaf['validFrom_time_t'] ?? PHP_INT_MAX) > $now || ($leaf['validTo_time_t'] ?? 0) < $now) {
            return [null, 'certificate expired or not yet valid'];
        }
        $eku = (string) ($leaf['extensions']['extendedKeyUsage'] ?? '');
        if (!str_contains($eku, self::EKU_BIMI) && stripos($eku, 'Brand Indicator') === false) {
            return [null, 'not a mark certificate'];
        }
        $names = array_map(static fn($s) => strtolower(trim(substr(trim($s), 4))), array_filter(explode(',', (string) ($leaf['extensions']['subjectAltName'] ?? '')), static fn($s) => stripos(trim($s), 'DNS:') === 0));
        if (!in_array($domain, $names, true) && !in_array(MailAuth::orgDomain($domain), $names, true)) {
            return [null, 'certificate issued for another domain'];
        }
        // Each certificate signed by the next, the last by a trusted mark authority.
        for ($i = 0; $i < count($certs) - 1; $i++) {
            if (openssl_x509_verify($certs[$i]['x'], $certs[$i + 1]['x']) !== 1) {
                return [null, 'broken certificate chain'];
            }
        }
        $roots = self::trustedRoots();
        if (!$roots) {
            return [null, 'no trusted mark authority configured'];
        }
        $last = end($certs);
        $trusted = false;
        foreach ($roots as $root) {
            if (openssl_x509_fingerprint($last['x'], 'sha256') === openssl_x509_fingerprint($root, 'sha256') || openssl_x509_verify($last['x'], $root) === 1) {
                $trusted = true;
                break;
            }
        }
        if (!$trusted) {
            return [null, 'certificate not issued by a trusted mark authority'];
        }
        // The logo is inside the certificate (logotype extension): data:image/svg+xml;base64,…
        $der = base64_decode(preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $certs[0]['pem']) ?? '', true) ?: '';
        $uri = self::logotypeUri($der);
        if ($uri === null) {
            return [null, 'no logo in the certificate'];
        }
        $svg = base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), true) ?: '';
        if (str_starts_with($svg, "\x1f\x8b")) {
            $svg = (string) @gzdecode($svg, self::MAX_SVG);
        }
        return $svg !== '' ? [$svg, ''] : [null, 'no logo in the certificate'];
    }

    /**
     * The logo URI of the logotype extension: the IA5String that holds "data:image/svg+xml;base64,…",
     * cut at the length its DER header gives (the bytes after it are not part of it).
     */
    private static function logotypeUri(string $der): ?string
    {
        $prefix = 'data:image/svg+xml;base64,';
        $at = strpos($der, $prefix);
        if ($at === false || $at < 2) {
            return null;
        }
        // Tag 0x16 (IA5String), then the length: short form, or 0x81 / 0x82 / 0x83 + bytes.
        for ($n = 0; $n <= 3; $n++) {
            $h = $at - 2 - $n;
            if ($h < 0 || ord($der[$h]) !== 0x16) {
                continue;
            }
            $first = ord($der[$h + 1]);
            if ($n === 0 && $first < 0x80) {
                $len = $first;
            } elseif ($n > 0 && $first === 0x80 + $n) {
                $len = 0;
                for ($i = 0; $i < $n; $i++) {
                    $len = ($len << 8) | ord($der[$h + 2 + $i]);
                }
            } else {
                continue;
            }
            $uri = substr($der, $at, $len);
            return strlen($uri) === $len && preg_match('#^data:image/svg\+xml;base64,[A-Za-z0-9+/=]+$#', $uri) ? $uri : null;
        }
        return null;
    }

    /** @return \OpenSSLCertificate[] trusted mark authorities (bundled file, or the one set in the configuration) */
    private static function trustedRoots(): array
    {
        $pem = self::$roots ?? '';
        if ($pem === '') {
            $file = (string) Config::get('bimi_roots', '') ?: M4W_APP . '/Data/bimi-roots.pem';
            $pem = is_file($file) ? (string) file_get_contents($file) : '';
        }
        preg_match_all('/-----BEGIN CERTIFICATE-----[A-Za-z0-9+\/=\s]+-----END CERTIFICATE-----/', $pem, $m);
        return array_values(array_filter(array_map(static fn($c) => @openssl_x509_read($c) ?: null, $m[0])));
    }

    // ---------------------------------------------------------------- network

    /** HTTPS only, public addresses only (pinned against DNS rebinding), size and time capped. */
    private static function download(string $url, int $max): ?string
    {
        for ($hop = 0; $hop < 3; $hop++) {
            if (!preg_match('#^https://([a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?)(?::(\d{1,5}))?(/[^\s"<>\\\\]*)?$#i', $url, $u)) {
                return null;
            }
            if (self::$http) {
                $res = (self::$http)($url);
            } else {
                try {
                    $ip = Net::publicIp($u[1]);
                } catch (\InvalidArgumentException) {
                    return null;
                }
                $res = self::curl($url, $u[1], (int) ($u[2] ?? '') ?: 443, $ip, $max);
            }
            if (!$res) {
                return null;
            }
            if (in_array($res['status'], [301, 302, 307, 308], true) && preg_match('#^https://#i', (string) $res['location'])) {
                $url = (string) $res['location'];
                continue;
            }
            return $res['status'] === 200 && $res['body'] !== '' && strlen($res['body']) <= $max ? $res['body'] : null;
        }
        return null;
    }

    private static function curl(string $url, string $host, int $port, string $ip, int $max): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $pinned = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $proxy = trim((string) Config::get('outbound_proxy', ''));
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, $proxy !== '' ? [CURLOPT_PROXY => $proxy] : [
            CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $pinned],
        ]);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false, CURLOPT_HEADER => false, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; MailBIMI/1.0)',
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, $max): int {
                $body .= $chunk;
                return strlen($body) > $max ? 0 : strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $used = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        curl_close($ch);
        if ($proxy === '' && $used !== '' && $used !== $ip) {
            return null;
        }
        return ['status' => $status, 'body' => strlen($body) > $max ? '' : $body, 'location' => $location];
    }

    // ------------------------------------------------------------ own domains

    /**
     * BIMI of one of our domains, for the supervision page: [state, detail].
     * @return array{0:string,1:string}
     */
    public static function check(string $domain): array
    {
        $rec = self::record($domain);
        if (!$rec) {
            return ['off', t('health.bimi_none', ['domain' => $domain])];
        }
        if (!self::enforced($domain)) {
            return ['warn', t('health.bimi_dmarc')];
        }
        if ($rec[1] === '') {
            return ['off', t('health.bimi_declined')];
        }
        $logo = self::logo($domain, true);
        if (($logo['status'] ?? '') !== 'ok') {
            return ['warn', t('health.bimi_invalid', ['why' => (string) ($logo['error'] ?? '') ?: '?'])];
        }
        return ['ok', (int) $logo['vmc'] ? t('health.bimi_ok_vmc') : t('health.bimi_ok') . ((string) $logo['error'] !== '' ? ' (' . $logo['error'] . ')' : '')];
    }
}
