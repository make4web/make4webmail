<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Database as DB;
use M4W\Mail\Address;
use M4W\Mail\MailAuth;
use M4W\Mail\ParsedMessage;

/**
 * Is the sender really who the From says? Shown as a padlock after the address.
 *
 * - internal: sent by a colleague signed in here (local delivery);
 * - verified: DMARC passes (SPF or DKIM aligned with the From domain);
 * - partial: SPF or DKIM passes, for another domain than the From;
 * - failed: the checks fail (the warning banner says why);
 * - none: nothing to judge with.
 *
 * Mail received over SMTP carries our X-M4W-Auth line. Mail collected from a provider (own
 * mailbox, collector, webmail mode) is checked here: DKIM verified again, SPF taken from the
 * provider's own Authentication-Results line. Worked out once, kept in messages.auth_cache.
 */
final class SenderCheck
{
    public const STATES = ['internal', 'verified', 'partial', 'failed', 'none'];

    /** @return array{state:string,spf:string,dkim:string,dmarc:string,by:string} */
    public static function forMessage(array $m, ParsedMessage $p, ?callable $raw = null): array
    {
        if (($h = $p->header('X-M4W-Auth')) !== null && $h !== '') {
            return self::fromOurs(MailAuth::parse($h));
        }
        if ((string) $p->header('X-M4W-Fetched') === '') {
            return self::result('none');
        }
        $cached = json_decode((string) ($m['auth_cache'] ?? ''), true);
        if (is_array($cached) && in_array($cached['state'] ?? '', self::STATES, true)) {
            return $cached + self::result('none');
        }
        try {
            $r = self::collected($p, $raw ? (string) $raw() : '');
        } catch (\Throwable) {
            return self::result('none'); // DNS unavailable: judged again next time
        }
        // Only a positive answer is kept: a failure may be a DNS hiccup, judged again next time.
        if (!empty($m['id']) && in_array($r['state'], ['verified', 'partial'], true)) {
            DB::update('messages', ['auth_cache' => mb_substr((string) json_encode($r), 0, 255)], 'id = :id', ['id' => (int) $m['id']]);
        }
        return $r;
    }

    private static function result(string $state, string $spf = '', string $dkim = '', string $dmarc = '', string $by = ''): array
    {
        return ['state' => $state, 'spf' => $spf, 'dkim' => $dkim, 'dmarc' => $dmarc, 'by' => $by];
    }

    private static function fromOurs(array $a): array
    {
        $verdict = (string) ($a['verdict'] ?? '');
        if ($verdict === 'internal') {
            return self::result('internal');
        }
        $spf = (string) ($a['spf'] ?? '');
        $dkim = (string) ($a['dkim'] ?? '');
        $dmarc = (string) ($a['dmarc'] ?? '');
        $state = match (true) {
            $verdict !== 'ok' => 'failed',
            $dmarc === 'pass' => 'verified',
            $spf === 'pass' || str_contains($dkim, 'pass@') => 'partial',
            default => 'none',
        };
        return self::result($state, $spf, $dkim, $dmarc);
    }

    /** Org domains of the providers we collect from: their Authentication-Results are trusted. */
    public static function providerDomains(): array
    {
        static $out = null;
        if ($out !== null) {
            return $out;
        }
        $out = [];
        foreach (DB::all('SELECT * FROM remote_accounts WHERE enabled = 1 LIMIT 500') as $acc) {
            foreach (['imap_host', 'smtp_host'] as $k) {
                $h = mb_strtolower((string) (RemoteMailbox::effective($acc)[$k] ?? ''));
                if ($h !== '' && !filter_var($h, FILTER_VALIDATE_IP)) {
                    $out[MailAuth::orgDomain($h)] = true;
                }
            }
        }
        return $out = array_keys($out);
    }

    /**
     * A message collected from a provider. Only the Authentication-Results lines written by the
     * provider count: same organisation as its servers, and above the last Received line its
     * servers added (anything lower was written before it reached them, so by anyone).
     */
    public static function collected(ParsedMessage $p, string $raw, ?array $trusted = null): array
    {
        $trusted ??= self::providerDomains();
        $from = Address::parseList((string) $p->header('From'));
        $fromDomain = $from ? mb_strtolower(Address::domain($from[0]['email'])) : '';
        if ($fromDomain === '' || count($from) !== 1) {
            return self::result('none');
        }
        $isTrusted = static function (string $host) use ($trusted): bool {
            $host = mb_strtolower(trim($host, " .\t[]"));
            return $host !== '' && in_array(MailAuth::orgDomain($host), $trusted, true);
        };
        $headers = $p->headers();
        $lastOurs = -1;
        foreach ($headers as $i => [$name, $value]) {
            if (strcasecmp($name, 'Received') === 0 && preg_match('/\bby\s+([^\s;()]+)/i', $value, $b) && $isTrusted($b[1])) {
                $lastOurs = $i;
            }
        }
        $spf = '';
        $spfDomain = '';
        $dmarc = '';
        $by = '';
        foreach ($headers as $i => [$name, $value]) {
            if ($i > $lastOurs || strcasecmp($name, 'Authentication-Results') !== 0) {
                continue;
            }
            $value = (string) preg_replace('/\s+/', ' ', $value);
            $id = trim((string) strtok($value, ';'));
            if (!$isTrusted((string) preg_replace('/\s.*$/', '', $id))) {
                continue;
            }
            $by = $by ?: $id;
            if ($spf === '' && preg_match('/\bspf=(\w+)/i', $value, $s)) {
                $spf = strtolower($s[1]);
                $spfDomain = preg_match('/smtp\.mailfrom=(?:[^@;\s]*@)?([^;\s]+)/i', $value, $d) ? mb_strtolower(trim($d[1], '<>')) : '';
            }
            if ($dmarc === '' && preg_match('/\bdmarc=(\w+)/i', $value, $s)) {
                $dmarc = strtolower($s[1]);
            }
        }
        $dkim = $raw !== '' ? MailAuth::dkim($raw) : [];
        $dkimSummary = $dkim ? implode(',', array_map(static fn($d) => $d['result'] . '@' . $d['domain'], $dkim)) : 'none';
        // Our own check of DMARC with what we know (our DKIM verification, the provider's SPF).
        $own = MailAuth::dmarc($fromDomain, $spf, $spfDomain, $dkim);
        if ($own['result'] === 'pass') {
            $dmarc = 'pass';
        } elseif ($dmarc === '' && $own['result'] === 'fail' && $spf !== '') {
            // Only when the provider's SPF is known: without it, an unaligned signature proves nothing either way.
            $dmarc = 'fail';
        }
        $dkimPass = (bool) array_filter($dkim, static fn($d) => $d['result'] === 'pass');
        $dkimFail = $dkim && !$dkimPass;
        $state = match (true) {
            $dmarc === 'pass' => 'verified',
            $dmarc === 'fail' && $own['policy'] !== 'none' => 'failed',
            $spf === 'pass' || $dkimPass => 'partial',
            in_array($spf, ['fail'], true) || $dkimFail || $dmarc === 'fail' => 'failed',
            default => 'none',
        };
        return self::result($state, $spf, $dkimSummary, $dmarc, mb_substr($by, 0, 80));
    }
}
