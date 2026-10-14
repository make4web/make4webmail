<?php
declare(strict_types=1);

namespace M4W\Mail;

/**
 * What a link really points to, and the classic phishing tricks: a text that shows one
 * address while the link goes to another, raw IP addresses, URL shorteners, look-alike
 * internationalised names (punycode), user@host deception, javascript/data links.
 */
final class LinkCheck
{
    /** Second-level suffixes under which the registrable name has three labels. */
    private const SLD = ['co.uk', 'org.uk', 'ac.uk', 'gov.uk', 'me.uk', 'com.au', 'net.au', 'org.au', 'co.nz', 'co.jp', 'co.za', 'com.br', 'com.mx',
        'com.cn', 'com.tr', 'co.in', 'co.il', 'co.kr', 'gouv.fr', 'asso.fr', 'nom.fr', 'com.fr', 'gov.br', 'gc.ca', 'qc.ca', 'com.es', 'com.pl', 'co.it'];
    private const SHORTENERS = ['bit.ly', 'tinyurl.com', 't.co', 'goo.gl', 'ow.ly', 'is.gd', 'buff.ly', 'cutt.ly', 'rebrand.ly', 'shorturl.at', 'rb.gy',
        'tiny.cc', 't.ly', 's.id', 'v.gd', 'bl.ink', 'lnkd.in', 'shorte.st', 'adf.ly', 'qrco.de', 'urlz.fr', 'snip.ly', 'tiny.one'];

    public static function host(string $url): string
    {
        $h = parse_url(trim($url), PHP_URL_HOST);
        return is_string($h) ? strtolower(rtrim($h, '.')) : '';
    }

    /** "mail.google.com" → "google.com", "www.impots.gouv.fr" → "impots.gouv.fr". */
    public static function registrable(string $host): string
    {
        $host = strtolower(trim($host, '.[]'));
        if ($host === '' || self::isIp($host)) {
            return $host;
        }
        $l = explode('.', $host);
        $n = count($l);
        if ($n <= 2) {
            return $host;
        }
        return in_array($l[$n - 2] . '.' . $l[$n - 1], self::SLD, true) ? implode('.', array_slice($l, -3)) : implode('.', array_slice($l, -2));
    }

    public static function isIp(string $host): bool
    {
        $host = trim($host, '[]');
        return filter_var($host, FILTER_VALIDATE_IP) !== false || (bool) preg_match('/^(0x[0-9a-f]+|\d{8,10})$/i', $host);
    }

    public static function isShortener(string $host): bool
    {
        return in_array(self::registrable($host), self::SHORTENERS, true) || in_array($host, self::SHORTENERS, true);
    }

    /** Domain written in a link text ("www.mabanque.fr", "https://paypal.com/login"), or ''. */
    public static function textDomain(string $text): string
    {
        $text = trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (preg_match('#^(?:https?://)?((?:[a-z0-9\-\x{00a1}-\x{ffff}]+\.)+[a-z\x{00a1}-\x{ffff}]{2,})(?:[/:?\#]\S*)?$#iu', $text, $m)) {
            return strtolower($m[1]);
        }
        return '';
    }

    /**
     * Problems with one link. @return string[] codes: mismatch, ip, shortener, punycode,
     * userinfo, script, nontls_login
     */
    public static function problems(string $href, string $text = ''): array
    {
        $out = [];
        $href = trim($href);
        if (preg_match('/^\s*(javascript|data|vbscript|file):/i', $href)) {
            return ['script'];
        }
        if (!preg_match('#^https?://#i', $href)) {
            return [];
        }
        $host = self::host($href);
        $shown = self::textDomain($text);
        if ($shown !== '' && $host !== '' && self::registrable(self::ascii($shown)) !== self::registrable(self::ascii($host))) {
            $out[] = 'mismatch';
        }
        if (self::isIp($host)) {
            $out[] = 'ip';
        }
        if (self::isShortener($host)) {
            $out[] = 'shortener';
        }
        if (str_contains($host, 'xn--') || preg_match('/[^\x00-\x7f]/', $host)) {
            $out[] = 'punycode';
        }
        // http://paypal.com@evil.example/ : everything before "@" is decoration.
        if (preg_match('#^https?://[^/?\#]*@#i', $href)) {
            $out[] = 'userinfo';
        }
        return $out;
    }

    private static function ascii(string $host): string
    {
        if (function_exists('idn_to_ascii') && preg_match('/[^\x00-\x7f]/', $host)) {
            $a = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            return $a !== false ? strtolower($a) : $host;
        }
        return $host;
    }

    /** @return array<int,array{href:string,text:string}> links of an HTML body */
    public static function links(string $html): array
    {
        if ($html === '' || stripos($html, '<a') === false) {
            return [];
        }
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . mb_substr($html, 0, 500000), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $out = [];
        foreach ($doc->getElementsByTagName('a') as $a) {
            $href = trim((string) $a->getAttribute('href'));
            if ($href !== '' && $href[0] !== '#' && !str_starts_with(strtolower($href), 'mailto:')) {
                $out[] = ['href' => $href, 'text' => trim((string) $a->textContent)];
            }
            if (count($out) >= 300) {
                break;
            }
        }
        return $out;
    }
}
