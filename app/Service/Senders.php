<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Config;
use M4W\Core\Settings;
use M4W\Mail\Address;
use M4W\Mail\ParsedMessage;

/**
 * Addresses not worth answering: disposable mailboxes (throwaway domains used by bots,
 * spammers and probes) and "no-reply" senders.
 *
 *  - Nothing automatic (out-of-office, rule replies) is ever sent to them.
 *  - Mail from a disposable domain is refused by default: at SMTP time (550, no bounce
 *    from us), or dropped and logged when it arrives through a mailbox we fetch.
 *
 * The domain list is the public-domain disposable-email-domains project, shipped in
 * app/data and refreshed weekly into storage/ (Administration → Security).
 */
final class Senders
{
    public const LIST_URL = 'https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/main/disposable_email_blocklist.conf';
    private const BUNDLED = __DIR__ . '/../data/disposable-domains.txt';

    /** @var array<string,true>|null */
    private static ?array $domains = null;

    private static function storedList(): string
    {
        return Config::storagePath('data/disposable-domains.txt');
    }

    /** @return array<string,true> */
    private static function domains(): array
    {
        if (self::$domains === null) {
            $file = is_file(self::storedList()) ? self::storedList() : self::BUNDLED;
            $set = [];
            foreach (array_merge(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], self::lines('security.disposable_extra')) as $d) {
                $d = strtolower(trim($d));
                if ($d !== '' && $d[0] !== '#') {
                    $set[$d] = true;
                }
            }
            foreach (self::lines('security.disposable_allow') as $d) {
                unset($set[strtolower($d)]);
            }
            self::$domains = $set;
        }
        return self::$domains;
    }

    /** @return string[] one domain per line in a setting */
    private static function lines(string $key): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) Settings::get($key, '')) ?: [])));
    }

    public static function reset(): void
    {
        self::$domains = null;
    }

    public static function listSize(): int
    {
        return count(self::domains());
    }

    /** The address belongs to a throwaway mail service (its domain or a parent domain is listed). */
    public static function isDisposable(string $email): bool
    {
        $domain = strtolower(Address::domain($email));
        if ($domain === '' || Users::isLocalDomain($domain)) {
            return false;
        }
        $set = self::domains();
        $labels = explode('.', $domain);
        for ($i = 0; $i < count($labels) - 1; $i++) {
            if (isset($set[implode('.', array_slice($labels, $i))])) {
                return true;
            }
        }
        return false;
    }

    /** "noreply@", "ne-pas-repondre@", "mailer-daemon@"… : nobody reads what is sent there. */
    public static function isNoReply(string $email): bool
    {
        $local = strtolower(strstr($email, '@', true) ?: '');
        return (bool) preg_match('/^(no[-_.]?reply|no[-_.]?response|do[-_.]?not[-_.]?reply|dont[-_.]?reply|ne[-_.]?pas[-_.]?repondre|nepasrepondre|pas[-_.]?de[-_.]?reponse|noresponder|no[-_.]?responder'
            . '|mailer[-_.]?daemon|postmaster|bounces?|notifications?|notify|alerts?|auto[-_.]?(reply|confirm|mailer)|automated|daemon|unsubscribe|robot)([-_.+].*)?$/', $local);
    }

    /** Never send anything automatic to this address. */
    public static function noAutoReply(string $email): bool
    {
        return $email === '' || !is_valid_email($email) || self::isNoReply($email) || self::isDisposable($email);
    }

    /** reject | spam | off */
    public static function inboundAction(): string
    {
        $a = (string) Settings::get('security.disposable_action', 'reject');
        return in_array($a, ['reject', 'spam', 'off'], true) ? $a : 'reject';
    }

    /** The disposable sender of a received message (envelope, From, Reply-To, Sender), or ''. */
    public static function disposableSender(?ParsedMessage $msg, string $envelopeFrom): string
    {
        $candidates = [$envelopeFrom];
        if ($msg) {
            $candidates[] = $msg->from()['email'];
            foreach (['Reply-To', 'Sender'] as $h) {
                foreach (Address::parseList((string) $msg->header($h)) as $a) {
                    $candidates[] = $a['email'];
                }
            }
        }
        foreach ($candidates as $c) {
            if ($c !== '' && self::isDisposable($c)) {
                return mb_strtolower($c);
            }
        }
        return '';
    }

    /** Download the current list (weekly from cron, or from the administration). */
    public static function update(): int
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('curl extension missing');
        }
        $ch = curl_init(self::LIST_URL);
        $proxy = trim((string) Config::get('outbound_proxy', ''));
        curl_setopt_array($ch, ($proxy !== '' ? [CURLOPT_PROXY => $proxy] : []) + [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'Make4WebMail',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body) || $code !== 200 || strlen($body) > 5_000_000) {
            throw new \RuntimeException('download failed (HTTP ' . $code . ')');
        }
        $domains = [];
        foreach (explode("\n", $body) as $l) {
            $l = strtolower(trim($l));
            if ($l !== '' && $l[0] !== '#' && preg_match('/^[a-z0-9.-]+\.[a-z0-9-]{2,}$/', $l)) {
                $domains[$l] = true;
            }
        }
        // A truncated or hijacked answer must not empty (or shrink) the protection.
        if (count($domains) < 1000) {
            throw new \RuntimeException('unexpected list (' . count($domains) . ' domains)');
        }
        $domains = array_keys($domains);
        sort($domains);
        $file = self::storedList();
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0750, true);
        }
        file_put_contents($file . '.tmp', "# " . self::LIST_URL . "\n" . implode("\n", $domains) . "\n");
        rename($file . '.tmp', $file);
        Settings::set('security.disposable_updated', time());
        self::reset();
        return count($domains);
    }
}
