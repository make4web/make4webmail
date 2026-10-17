<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Database as DB;
use M4W\Core\Settings;
use M4W\Mail\MimeParser;

/**
 * Health and reputation of one of our domains, for the administrator: will mail sent from it
 * be delivered, and if not, why. Gathered on demand (and kept an hour):
 *  - authentication: SPF (evaluated for the addresses that really send, lookups counted), DKIM
 *    (the keys found: ours, the ones seen in mail received from the domain, the usual selectors),
 *    DMARC (policy, reports);
 *  - the servers that send: their reverse DNS (and that it points back), their presence in the
 *    main blocklists; the domain itself in the domain blocklists;
 *  - what happened to mail sent lately: handed over, refused, waiting, and the delivery failure
 *    notices that came back;
 *  - advice for the receivers the domain writes to most (Free, Orange, Gmail, Outlook…).
 * Only public DNS is queried (no external service, no account).
 */
final class DomainHealth
{
    /** Tests: fn(string $type, string $name): array (TXT strings, MX / PTR names, A / AAAA addresses). */
    public static $resolver = null;

    /** Blocklists of sending addresses. */
    public const IP_LISTS = [
        'zen.spamhaus.org' => 'Spamhaus ZEN', 'b.barracudacentral.org' => 'Barracuda', 'bl.spamcop.net' => 'SpamCop',
        'psbl.surriel.com' => 'PSBL', 'bl.mailspike.net' => 'Mailspike', 'dnsbl-1.uceprotect.net' => 'UCEPROTECT',
        'dnsbl.dronebl.org' => 'DroneBL', 'ix.dnsbl.manitu.net' => 'NiX Spam',
    ];
    /** Blocklists of domains (links in messages, senders). */
    public const DOMAIN_LISTS = ['dbl.spamhaus.org' => 'Spamhaus DBL', 'multi.surbl.org' => 'SURBL', 'multi.uribl.com' => 'URIBL'];
    /** DKIM selectors commonly used by providers and software. */
    private const SELECTORS = ['default', 'dkim', 'mail', 'm4w', 'selector1', 'selector2', 'google', 'k1', 's1', 's2', 'ovh', 'mx', 'smtp'];
    /** Receivers with advice of their own. */
    public const RECEIVERS = ['free.fr' => 'free', 'orange.fr' => 'orange', 'wanadoo.fr' => 'orange', 'sfr.fr' => 'sfr', 'laposte.net' => 'laposte',
        'gmail.com' => 'gmail', 'googlemail.com' => 'gmail', 'outlook.com' => 'microsoft', 'hotmail.com' => 'microsoft', 'hotmail.fr' => 'microsoft', 'live.fr' => 'microsoft', 'live.com' => 'microsoft',
        'yahoo.fr' => 'yahoo', 'yahoo.com' => 'yahoo', 'icloud.com' => 'apple', 'me.com' => 'apple'];
    private const CACHE = 3600;

    // ---------------------------------------------------------------- DNS

    public static function lookup(string $type, string $name): array
    {
        $name = rtrim($name, '.');
        if (self::$resolver) {
            return array_values((self::$resolver)($type, $name));
        }
        $map = ['TXT' => DNS_TXT, 'MX' => DNS_MX, 'A' => DNS_A, 'AAAA' => DNS_AAAA, 'PTR' => DNS_PTR];
        if (!isset($map[$type]) || $name === '' || strlen($name) > 253) {
            return [];
        }
        $rows = @dns_get_record($name, $map[$type]) ?: [];
        if ($type === 'MX') {
            usort($rows, static fn($a, $b) => ($a['pri'] ?? 0) <=> ($b['pri'] ?? 0));
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = match ($type) {
                'TXT' => isset($r['entries']) ? implode('', $r['entries']) : (string) ($r['txt'] ?? ''),
                'MX', 'PTR' => rtrim((string) ($r['target'] ?? ''), '.'),
                'A' => (string) ($r['ip'] ?? ''),
                'AAAA' => (string) ($r['ipv6'] ?? ''),
            };
        }
        return array_values(array_filter($out, static fn($v) => $v !== ''));
    }

    /** IPv4 / IPv6 addresses of a name. */
    private static function addresses(string $name): array
    {
        return array_merge(self::lookup('A', $name), self::lookup('AAAA', $name));
    }

    private static function reverseName(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return implode('.', array_reverse(explode('.', $ip)));
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $hex = bin2hex((string) inet_pton($ip));
            return implode('.', array_reverse(str_split($hex)));
        }
        return null;
    }

    // ---------------------------------------------------------------- SPF

    public static function spfRecords(string $domain): array
    {
        return array_values(array_filter(self::lookup('TXT', $domain), static fn($t) => (bool) preg_match('/^v=spf1(\s|$)/i', trim($t))));
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $a = @inet_pton($ip);
        $b = @inet_pton((string) $net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = $bits === null ? strlen($a) * 8 : max(0, min(strlen($a) * 8, (int) $bits));
        $bytes = intdiv($bits, 8);
        if (strncmp($a, $b, $bytes) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }

    /**
     * What a receiver concludes for mail from $ip with $domain as sender (RFC 7208, without
     * macros): pass, fail, softfail, neutral, none or permerror (more than 10 lookups).
     */
    public static function spfCheck(string $ip, string $domain, int &$lookups = 0, int $depth = 0): string
    {
        if ($depth > 10) {
            return 'permerror';
        }
        $records = self::spfRecords($domain);
        if (!$records) {
            return 'none';
        }
        if (count($records) > 1) {
            return 'permerror';
        }
        $results = ['+' => 'pass', '-' => 'fail', '~' => 'softfail', '?' => 'neutral'];
        $redirect = null;
        foreach (preg_split('/\s+/', trim($records[0])) as $term) {
            if (preg_match('/^redirect=(.+)$/i', $term, $m)) {
                $redirect = $m[1];
                continue;
            }
            if (!preg_match('/^([+\-~?]?)(all|ip4|ip6|a|mx|include|exists|ptr)(?::([^\/]+))?(\/\d+)?(?:\/\/\d+)?$/i', $term, $m)) {
                continue; // v=spf1, exp=…, unknown modifiers
            }
            $q = $results[$m[1] !== '' ? $m[1] : '+'];
            $mech = strtolower($m[2]);
            $arg = $m[3] ?? '';
            $cidr = $m[4] ?? '';
            $target = $arg !== '' ? $arg : $domain;
            if (in_array($mech, ['a', 'mx', 'include', 'exists', 'ptr'], true) && ++$lookups > 10) {
                return 'permerror';
            }
            $match = match ($mech) {
                'all' => true,
                'ip4', 'ip6' => self::inCidr($ip, $arg . $cidr),
                'a' => (bool) array_filter(self::addresses($target), static fn($x) => self::inCidr($ip, $x . $cidr)),
                'mx' => (bool) array_filter(array_merge(...array_map(static fn($mx) => self::addresses($mx), self::lookup('MX', $target)) ?: [[]]),
                    static fn($x) => self::inCidr($ip, $x . $cidr)),
                'include' => self::spfCheck($ip, $target, $lookups, $depth + 1) === 'pass',
                default => false, // exists, ptr: not judged here
            };
            if ($match) {
                return $q;
            }
        }
        if ($redirect !== null) {
            if (++$lookups > 10) {
                return 'permerror';
            }
            return self::spfCheck($ip, $redirect, $lookups, $depth + 1);
        }
        return 'neutral';
    }

    /** DNS lookups the SPF record makes in all (receivers give up past 10). */
    public static function spfLookups(string $domain, int $depth = 0): int
    {
        $records = self::spfRecords($domain);
        if (!$records || $depth > 10) {
            return 0;
        }
        $n = 0;
        foreach (preg_split('/\s+/', trim($records[0])) as $term) {
            if (preg_match('/^[+\-~?]?(include:|redirect=)(.+)$/i', $term, $m)) {
                $n += 1 + self::spfLookups($m[2], $depth + 1);
            } elseif (preg_match('/^[+\-~?]?(a|mx|exists|ptr)([:\/]|$)/i', $term)) {
                $n++;
            }
        }
        return $n;
    }

    // ---------------------------------------------------------------- DKIM

    /** Selectors really used for the domain, read in the signatures of mail received from it. */
    public static function seenSelectors(string $domain, int $max = 40): array
    {
        $out = [];
        $rows = DB::all("SELECT * FROM messages WHERE LOWER(from_email) LIKE :d AND is_draft = 0 ORDER BY id DESC LIMIT $max", ['d' => '%@' . mb_strtolower($domain)]);
        foreach ($rows as $m) {
            try {
                $raw = Mailbox::raw($m);
            } catch (\Throwable) {
                continue;
            }
            $head = substr($raw, 0, (int) (strpos(str_replace("\r\n", "\n", $raw), "\n\n") ?: 65536));
            $head = (string) preg_replace("/\r?\n[ \t]+/", ' ', $head);
            if (preg_match_all('/^DKIM-Signature:(.*)$/mi', $head, $sig)) {
                foreach ($sig[1] as $s) {
                    if (preg_match('/\bd=([^;\s]+)/i', $s, $d) && preg_match('/\bs=([^;\s]+)/i', $s, $sel) && strcasecmp($d[1], $domain) === 0) {
                        $out[strtolower($sel[1])] = true;
                    }
                }
            }
        }
        return array_keys($out);
    }

    /** DKIM keys published: [selector => ['origin' => configured|seen|usual, 'key' => bool, 'bits' => int]]. */
    public static function dkim(string $domain): array
    {
        $candidates = [];
        if (strcasecmp((string) Settings::get('smtp.dkim_domain', ''), $domain) === 0 && Settings::get('smtp.dkim_selector', '') !== '') {
            $candidates[strtolower((string) Settings::get('smtp.dkim_selector'))] = 'configured';
        }
        foreach (self::seenSelectors($domain) as $s) {
            $candidates[$s] ??= 'seen';
        }
        foreach (self::SELECTORS as $s) {
            $candidates[$s] ??= 'usual';
        }
        $out = [];
        foreach ($candidates as $sel => $origin) {
            $txt = implode('', self::lookup('TXT', $sel . '._domainkey.' . $domain));
            if ($txt === '' && $origin === 'usual') {
                continue;
            }
            $bits = 0;
            if (preg_match('/\bp=([A-Za-z0-9+\/=\s]+)/', $txt, $m) && ($pem = base64_decode(preg_replace('/\s+/', '', $m[1]), true))) {
                $key = @openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($pem), 64, "\n") . "-----END PUBLIC KEY-----\n");
                $bits = $key ? (int) (openssl_pkey_get_details($key)['bits'] ?? 0) : 0;
            }
            $out[$sel] = ['origin' => $origin, 'key' => $txt !== '' && !preg_match('/\bp=\s*(;|$)/', $txt), 'bits' => $bits];
        }
        return $out;
    }

    // ---------------------------------------------------------------- senders

    /**
     * The addresses that send the domain's mail: the relay configured, the provider mailboxes
     * (webmail mode, own mailboxes), this server itself, and the ones receivers reported in DMARC
     * reports. [ip => [labels]]
     */
    public static function sendingIps(string $domain, int $max = 8): array
    {
        $hosts = [];
        $mode = RemoteMailbox::mode();
        if (in_array($mode, ['relay', ''], true) && Settings::get('smtp.host', '') !== '') {
            $hosts[(string) Settings::get('smtp.host')] = 'relay';
        }
        if ($mode === 'server') {
            $hosts[(string) (Settings::get('smtp.helo', '') ?: gethostname())] = 'server';
        }
        foreach (DB::all("SELECT r.* FROM remote_accounts r JOIN users u ON u.id = r.user_id WHERE r.enabled = 1 AND LOWER(u.email) LIKE :d LIMIT 50", ['d' => '%@' . mb_strtolower($domain)]) as $acc) {
            $h = (string) RemoteMailbox::effective($acc)['smtp_host'];
            if ($h !== '') {
                $hosts[$h] = 'provider';
            }
        }
        if ($mode === 'collector' && ($c = Collector::account())) {
            $h = (string) RemoteMailbox::effective($c)['smtp_host'];
            if ($h !== '') {
                $hosts[$h] = 'provider';
            }
        }
        $ips = [];
        foreach ($hosts as $h => $label) {
            foreach (filter_var($h, FILTER_VALIDATE_IP) ? [$h] : self::addresses($h) as $ip) {
                $ips[$ip][] = $label . ':' . $h;
            }
        }
        // What receivers saw really sending for the domain (DMARC aggregate reports, 30 days).
        foreach (DB::all("SELECT r.source_ip, SUM(r.messages) n FROM dmarc_records r JOIN dmarc_reports p ON p.id = r.report_id
            WHERE LOWER(r.header_from) = :d AND p.date_end > :t GROUP BY r.source_ip ORDER BY n DESC LIMIT 6", ['d' => mb_strtolower($domain), 't' => time() - 30 * 86400]) as $r) {
            if (filter_var($r['source_ip'], FILTER_VALIDATE_IP)) {
                $ips[$r['source_ip']][] = 'dmarc:' . (int) $r['n'];
            }
        }
        return array_slice($ips, 0, $max, true);
    }

    /** Reverse DNS of an address, and whether that name points back to it. */
    public static function ptr(string $ip): array
    {
        $rev = self::reverseName($ip);
        if ($rev === null) {
            return ['name' => '', 'confirmed' => false];
        }
        $names = self::lookup('PTR', $rev . (str_contains($ip, ':') ? '.ip6.arpa' : '.in-addr.arpa'));
        $name = (string) ($names[0] ?? '');
        return ['name' => $name, 'confirmed' => $name !== '' && in_array($ip, self::addresses($name), true)];
    }

    /**
     * An address or a domain in a blocklist: listed, clean, or unknown (the list refused to
     * answer: public resolvers are often turned away, by Spamhaus for instance).
     */
    public static function listed(string $query, string $zone): array
    {
        $codes = self::lookup('A', $query . '.' . $zone);
        if (!$codes) {
            return ['state' => 'clean', 'code' => ''];
        }
        $code = (string) $codes[0];
        // Error answers: Spamhaus 127.255.255.x, SURBL / URIBL 127.0.0.1 (refused, over the limit).
        $refused = str_starts_with($code, '127.255.255.') || ($code === '127.0.0.1' && in_array($zone, ['multi.surbl.org', 'multi.uribl.com'], true));
        return ['state' => $refused ? 'unknown' : (str_starts_with($code, '127.') ? 'listed' : 'unknown'), 'code' => $code];
    }

    // ---------------------------------------------------------------- delivery

    /** Mail sent from the domain lately: totals, the last ones, what waits, what came back. */
    public static function delivery(string $domain, int $days = 7): array
    {
        $like = '%@' . mb_strtolower($domain);
        $since = time() - $days * 86400;
        $totals = ['ok' => 0, 'partial' => 0, 'failed' => 0, 'held' => 0];
        foreach (DB::all("SELECT status, COUNT(*) n FROM mail_log WHERE direction = 'out' AND LOWER(sender) LIKE :d AND created_at > :t GROUP BY status", ['d' => $like, 't' => $since]) as $r) {
            $totals[$r['status']] = (int) $r['n'];
        }
        $recent = DB::all("SELECT sender, recipients, subject, status, info, created_at FROM mail_log WHERE direction = 'out' AND LOWER(sender) LIKE :d ORDER BY id DESC LIMIT 25", ['d' => $like]);
        $queue = DB::all("SELECT envelope_from, recipients, status, attempts, last_error, next_attempt_at, created_at FROM mail_queue
            WHERE LOWER(envelope_from) LIKE :d AND status IN ('pending', 'held', 'failed', 'parked') AND created_at > :t ORDER BY id DESC LIMIT 25", ['d' => $like, 't' => $since]);
        // Who the domain writes to: receivers with advice of their own.
        $receivers = [];
        foreach (DB::all("SELECT recipients FROM mail_log WHERE direction = 'out' AND LOWER(sender) LIKE :d AND created_at > :t ORDER BY id DESC LIMIT 500", ['d' => $like, 't' => time() - 30 * 86400]) as $r) {
            foreach (preg_split('/\s*,\s*/', (string) $r['recipients']) as $rc) {
                $rd = mb_strtolower(substr(strrchr($rc, '@') ?: '', 1));
                if (isset(self::RECEIVERS[$rd])) {
                    $receivers[self::RECEIVERS[$rd]][$rd] = ($receivers[self::RECEIVERS[$rd]][$rd] ?? 0) + 1;
                }
            }
        }
        return ['totals' => $totals, 'recent' => $recent, 'queue' => $queue, 'bounces' => self::bounces($domain, $days), 'receivers' => $receivers,
            'tracked' => DeliveryReports::domainTotals($domain, $days)];
    }

    /**
     * Delivery failure notices received by the domain's people: the recipient refused and the
     * reason given by the receiving server (RFC 3464 report, or the usual text of one).
     */
    public static function bounces(string $domain, int $days = 7, int $max = 25): array
    {
        $rows = DB::all("SELECT m.* FROM messages m JOIN users u ON u.id = m.user_id WHERE LOWER(u.email) LIKE :d AND m.date_received > :t AND m.is_draft = 0
            AND (LOWER(m.from_email) LIKE 'mailer-daemon@%' OR m.subject LIKE :s1 OR m.subject LIKE :s2 OR m.subject LIKE :s3 OR m.subject LIKE :s4)
            ORDER BY m.date_received DESC LIMIT $max",
            ['d' => '%@' . mb_strtolower($domain), 't' => time() - $days * 86400, 's1' => '%ndeliver%', 's2' => '%non remis%', 's3' => '%Delivery Status%', 's4' => '%chec de la remise%']);
        $out = [];
        foreach ($rows as $m) {
            $text = '';
            try {
                $p = MimeParser::parse(Mailbox::raw($m));
                $text = $p->text() . "\n";
                foreach ($p->attachmentParts() as $part) {
                    if (preg_match('#^(message/delivery-status|message/global-delivery-status|text/rfc822-headers)#i', (string) $part->type)) {
                        $text .= "\n" . $part->decodedBody();
                    }
                }
            } catch (\Throwable) {
                $text = (string) $m['body_text'];
            }
            $text = $text ?: (string) $m['body_text'];
            $rcpt = preg_match('/^(?:Final|Original)-Recipient:\s*(?:rfc822;)?\s*<?([^\s>]+@[^\s>]+)/mi', $text, $x) ? $x[1]
                : (preg_match('/<([^\s<>@]+@[^\s<>]+)>/', $text, $x) ? $x[1] : '');
            $status = preg_match('/^Status:\s*([245]\.\d+\.\d+)/mi', $text, $x) ? $x[1] : (preg_match('/\b([245]\.\d\.\d{1,3})\b/', $text, $x) ? $x[1] : '');
            $why = preg_match('/^Diagnostic-Code:\s*(?:smtp;)?\s*(.+(?:\n[ \t].+)*)/mi', $text, $x) ? $x[1]
                : (preg_match('/\b([45]\d\d[ \-][^\n]{6,240})/', $text, $x) ? $x[1] : mb_substr(trim((string) $m['snippet']), 0, 240));
            if ($rcpt === '' && $status === '') {
                continue; // not a delivery failure notice after all
            }
            $out[] = ['date' => (int) $m['date_received'], 'user' => (int) $m['user_id'], 'subject' => (string) $m['subject'], 'recipient' => $rcpt,
                'status' => $status, 'reason' => mb_substr(trim((string) preg_replace('/\s+/', ' ', $why)), 0, 300), 'permanent' => str_starts_with($status, '5')];
        }
        return $out;
    }

    // ---------------------------------------------------------------- report

    /** Everything about one domain, kept an hour (unless asked again). */
    public static function report(string $domain, bool $fresh = false): array
    {
        $key = 'domain.health.' . mb_strtolower($domain);
        $cached = json_decode((string) Settings::get($key, ''), true);
        if (!$fresh && is_array($cached) && ($cached['at'] ?? 0) > time() - self::CACHE) {
            return $cached + ['delivery' => self::delivery($domain)];
        }
        $r = self::build($domain);
        Settings::set($key, json_encode($r));
        return $r + ['delivery' => self::delivery($domain)];
    }

    /** Worst state of a report: ok, warn or fail (for the list of domains). */
    public static function cachedState(string $domain): ?string
    {
        $c = json_decode((string) Settings::get('domain.health.' . mb_strtolower($domain), ''), true);
        return is_array($c) ? (string) ($c['state'] ?? '') : null;
    }

    private static function build(string $domain): array
    {
        $checks = [];
        $add = static function (string $key, string $state, string $detail, string $tip = '') use (&$checks): void {
            $checks[] = ['key' => $key, 'state' => $state, 'detail' => $detail, 'tip' => $tip];
        };
        // MX
        $mx = self::lookup('MX', $domain);
        $add('mx', $mx ? 'ok' : 'fail', $mx ? implode(', ', array_slice($mx, 0, 4)) : t('dh.mx_none'), $mx ? '' : t('dh.tip_mx'));
        // SPF
        $spf = self::spfRecords($domain);
        $lookups = $spf ? self::spfLookups($domain) : 0;
        if (!$spf) {
            $add('spf', 'fail', t('dh.spf_none'), t('dh.tip_spf_none'));
        } elseif (count($spf) > 1) {
            $add('spf', 'fail', t('dh.spf_many', ['n' => count($spf)]), t('dh.tip_spf_many'));
        } elseif ($lookups > 10) {
            $add('spf', 'fail', t('dh.spf_lookups', ['n' => $lookups]) . ' — ' . $spf[0], t('dh.tip_spf_lookups'));
        } elseif (preg_match('/[+?]all\b/i', $spf[0]) || !preg_match('/[~\-]all\b|redirect=/i', $spf[0])) {
            $add('spf', 'warn', $spf[0], t('dh.tip_spf_weak'));
        } else {
            $add('spf', 'ok', $spf[0] . ' (' . t('dh.spf_n_lookups', ['n' => $lookups]) . ')');
        }
        // Who sends, and whether SPF lets them.
        $ips = self::sendingIps($domain);
        $senders = [];
        $spfFails = 0;
        foreach ($ips as $ip => $labels) {
            $n = 0;
            $res = $spf ? self::spfCheck($ip, $domain, $n) : 'none';
            $ptr = self::ptr($ip);
            $lists = [];
            foreach (self::IP_LISTS as $zone => $label) {
                $rev = self::reverseName($ip);
                $lists[$zone] = $rev === null || str_contains($ip, ':') ? ['state' => 'unknown', 'code' => ''] : self::listed($rev, $zone) + ['label' => $label];
                $lists[$zone]['label'] = $label;
            }
            $senders[$ip] = ['labels' => $labels, 'spf' => $res, 'ptr' => $ptr, 'lists' => $lists];
            if (!in_array($res, ['pass'], true)) {
                $spfFails++;
            }
        }
        if ($ips && $spf) {
            $bad = array_keys(array_filter($senders, static fn($s) => $s['spf'] !== 'pass'));
            $add('spf_senders', $bad ? 'fail' : 'ok', $bad ? t('dh.spf_senders_bad', ['ips' => implode(', ', $bad)]) : t('dh.spf_senders_ok', ['n' => count($senders)]), $bad ? t('dh.tip_spf_senders') : '');
        }
        // DKIM
        $dkim = self::dkim($domain);
        $valid = array_filter($dkim, static fn($k) => $k['key']);
        $weak = array_filter($valid, static fn($k) => $k['bits'] > 0 && $k['bits'] < 1024);
        if ($valid) {
            $add('dkim', $weak ? 'warn' : 'ok', implode(', ', array_map(static fn($s, $k) => $s . ($k['bits'] ? ' (' . $k['bits'] . ' bits)' : '') . ' · ' . t('dh.dkim_origin_' . $k['origin']), array_keys($valid), $valid)), $weak ? t('dh.tip_dkim_weak') : '');
        } else {
            $add('dkim', 'fail', t('dh.dkim_none'), t('dh.tip_dkim_none'));
        }
        // DMARC
        $dmarc = array_values(array_filter(self::lookup('TXT', '_dmarc.' . $domain), static fn($t) => stripos(trim($t), 'v=DMARC1') === 0));
        $policy = $dmarc && preg_match('/\bp=(\w+)/i', $dmarc[0], $m) ? strtolower($m[1]) : '';
        if (!$dmarc) {
            $add('dmarc', 'fail', t('dh.dmarc_none'), t('dh.tip_dmarc_none', ['domain' => $domain]));
        } else {
            $add('dmarc', $policy === 'none' ? 'warn' : 'ok', $dmarc[0], $policy === 'none' ? t('dh.tip_dmarc_none_policy') : (stripos($dmarc[0], 'rua=') === false ? t('dh.tip_dmarc_rua', ['domain' => $domain]) : ''));
            if (!$valid && $policy !== 'none' && $spfFails) {
                $add('alignment', 'fail', t('dh.alignment_fail', ['policy' => $policy]), t('dh.tip_alignment'));
            }
        }
        // Reverse DNS of the senders.
        foreach ($senders as $ip => $s) {
            $add('ptr:' . $ip, $s['ptr']['name'] === '' ? 'fail' : ($s['ptr']['confirmed'] ? 'ok' : 'warn'),
                $s['ptr']['name'] === '' ? t('dh.ptr_none', ['ip' => $ip]) : t($s['ptr']['confirmed'] ? 'dh.ptr_ok' : 'dh.ptr_unconfirmed', ['ip' => $ip, 'name' => $s['ptr']['name']]),
                $s['ptr']['name'] === '' || !$s['ptr']['confirmed'] ? t('dh.tip_ptr') : '');
        }
        // Blocklists: the senders, the domain.
        $listedIps = [];
        $unknown = 0;
        foreach ($senders as $ip => $s) {
            foreach ($s['lists'] as $l) {
                if ($l['state'] === 'listed') {
                    $listedIps[] = $ip . ' (' . $l['label'] . ')';
                } elseif ($l['state'] === 'unknown') {
                    $unknown++;
                }
            }
        }
        $domainLists = [];
        foreach (self::DOMAIN_LISTS as $zone => $label) {
            $domainLists[$zone] = self::listed($domain, $zone) + ['label' => $label];
            if ($domainLists[$zone]['state'] === 'listed') {
                $listedIps[] = $domain . ' (' . $label . ')';
            }
        }
        if ($senders || $domainLists) {
            $add('blocklists', $listedIps ? 'fail' : 'ok', $listedIps ? t('dh.listed', ['what' => implode(', ', $listedIps)]) : t('dh.not_listed', ['n' => count(self::IP_LISTS) * count($senders) + count(self::DOMAIN_LISTS)])
                . ($unknown ? ' ' . t('dh.lists_unknown', ['n' => $unknown]) : ''), $listedIps ? t('dh.tip_listed') : '');
        }
        $worst = 'ok';
        foreach ($checks as $c) {
            if ($c['state'] === 'fail') {
                $worst = 'fail';
                break;
            }
            if ($c['state'] === 'warn') {
                $worst = 'warn';
            }
        }
        return ['at' => time(), 'domain' => $domain, 'state' => $worst, 'checks' => $checks, 'senders' => $senders, 'dkim' => $dkim, 'domain_lists' => $domainLists,
            'dmarc_reports' => array_values(array_filter(DmarcReports::summary(30)['domains'] ?? [], static fn($r) => strcasecmp((string) $r['domain'], $domain) === 0))];
    }
}
