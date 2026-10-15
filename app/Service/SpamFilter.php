<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Database as DB;
use M4W\Core\Settings;
use M4W\Mail\Address;
use M4W\Mail\LinkCheck;
use M4W\Mail\ParsedMessage;

/**
 * Server-wide spam filter. Each received message gets a score from weighted tests
 * (authentication, upstream filter verdict, headers, wording, links, attachments,
 * optional DNS block lists), then the administrator's thresholds decide:
 *
 *   below "suspect"        → inbox
 *   suspect                → the user's Junk folder, with the reasons shown
 *   quarantine             → held by the administrators, the user does not see it
 *   delete                 → not delivered (a copy stays in the deleted messages)
 *
 * Administrator allow / block lists (addresses or domains) bypass the score.
 */
final class SpamFilter
{
    public const HEADER = 'X-M4W-Spam';
    public const LEVELS = ['ok', 'suspect', 'quarantine', 'delete'];

    private const FREEMAIL = ['gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.fr', 'hotmail.com', 'hotmail.fr', 'outlook.com', 'outlook.fr', 'live.com', 'live.fr',
        'msn.com', 'aol.com', 'gmx.com', 'gmx.fr', 'gmx.de', 'mail.ru', 'yandex.ru', 'yandex.com', 'orange.fr', 'wanadoo.fr', 'free.fr', 'laposte.net', 'sfr.fr',
        'icloud.com', 'me.com', 'proton.me', 'protonmail.com', 'zoho.com', 'mail.com', 'web.de'];
    private const WORDS = ['viagra', 'cialis', 'casino', 'lottery', 'loterie', 'winner', 'gagnant', 'you have won', 'vous avez gagné', 'félicitations', 'congratulations',
        'bitcoin', 'crypto', 'investment opportunity', 'opportunité d\'investissement', 'prêt rapide', 'crédit rapide', 'make money', 'gagner de l\'argent', 'free gift',
        'cadeau gratuit', '100% gratuit', '100% free', 'act now', 'agissez maintenant', 'limited time', 'offre limitée', 'dear friend', 'cher ami', 'inheritance', 'héritage',
        'western union', 'moneygram', 'nigeria', 'sexy', 'singles', 'weight loss', 'perdre du poids', 'rolex', 'replica'];
    private const BAIT = ['mot de passe', 'password', 'vérifiez votre compte', 'vérifier votre compte', 'verify your account', 'confirm your account', 'confirmez votre compte',
        'compte suspendu', 'compte bloqué', 'account suspended', 'account locked', 'identifiants', 'credentials', 'mise à jour de vos informations', 'update your information',
        'colis en attente', 'frais de livraison', 'remboursement en attente', 'carte vitale', 'votre boîte mail est pleine', 'mailbox is full', 'quota dépassé'];

    public static function enabled(): bool
    {
        return (int) Settings::get('spam.enabled', 1) === 1;
    }

    /** @return array{suspect:float,quarantine:float,delete:float} 0 disables a level */
    public static function thresholds(): array
    {
        return [
            'suspect' => (float) Settings::get('spam.suspect', 5),
            'quarantine' => (float) Settings::get('spam.quarantine', 10),
            'delete' => (float) Settings::get('spam.delete', 20),
        ];
    }

    public static function level(float $score): string
    {
        $t = self::thresholds();
        foreach (['delete', 'quarantine', 'suspect'] as $l) {
            if ($t[$l] > 0 && $score >= $t[$l]) {
                return $l;
            }
        }
        return 'ok';
    }

    /** @return string[] */
    private static function list(string $key): array
    {
        return array_values(array_filter(array_map(static fn($s) => strtolower(trim($s)), preg_split('/[\s,;]+/', (string) Settings::get($key, '')) ?: [])));
    }

    private static function listed(string $email, array $list): bool
    {
        $email = strtolower($email);
        if ($email === '') {
            return false;
        }
        $domain = Address::domain($email);
        foreach ($list as $e) {
            $e = ltrim($e, '@');
            if ($e === $email || $e === $domain || ($domain !== '' && str_ends_with($domain, '.' . $e))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Score shared by every recipient.
     * @param array|null $auth MailAuth::evaluate() result (+ 'ip' when known)
     * @return array{score:float,tests:array<string,float>,list:string}
     */
    public static function evaluate(ParsedMessage $p, ?array $auth, string $envelopeFrom = ''): array
    {
        $from = strtolower($p->from()['email']);
        $authFails = $auth !== null && ($auth['verdict'] ?? 'ok') !== 'ok';
        // Lists first. An allowed sender that fails authentication is a forgery: not allowed.
        foreach ([$from, strtolower($envelopeFrom)] as $who) {
            if (self::listed($who, self::list('spam.block'))) {
                return ['score' => 100.0, 'tests' => ['BLOCKLIST' => 100.0], 'list' => 'block'];
            }
        }
        if (!$authFails && self::listed($from, self::list('spam.allow'))) {
            return ['score' => -100.0, 'tests' => ['ALLOWLIST' => -100.0], 'list' => 'allow'];
        }

        $t = [];
        $add = static function (string $code, float $w) use (&$t): void {
            $t[$code] = round(($t[$code] ?? 0) + $w, 1);
        };

        // Upstream filters (provider, SpamAssassin, rspamd). A sender can forge these headers,
        // so they may only raise the score, never lower it.
        if ((int) Settings::get('inbound.spam_header', 1)) {
            $up = self::upstreamScore($p);
            if ($up !== null && $up > 0) {
                $add('UPSTREAM_SCORE', min(20.0, $up));
            } elseif ($up === null && ($p->isSpamFlagged() || preg_match('/^\s*(yes|spam)/i', (string) ($p->header('X-VR-SPAMSTATE') ?? $p->header('X-Spam') ?? '')))) {
                $add('UPSTREAM_SPAM', 6);
            }
        }

        // Authentication (only known for mail received by our own SMTP daemon).
        if ($auth !== null) {
            match ($auth['spf'] ?? 'none') {
                'fail' => $add('SPF_FAIL', 3),
                'softfail' => $add('SPF_SOFTFAIL', 1),
                default => null,
            };
            $dkim = $auth['dkim'] ?? [];
            if ($dkim && !array_filter($dkim, static fn($d) => ($d['result'] ?? '') === 'pass')) {
                $add('DKIM_FAIL', 2);
            }
            if (($auth['dmarc'] ?? '') === 'fail') {
                $add('DMARC_FAIL', ($auth['policy'] ?? 'none') === 'none' ? 2 : 4);
            }
            match ($auth['verdict'] ?? 'ok') {
                'spoof' => $add('SPOOF_LOCAL', 6),
                'suspicious' => $add('MULTIPLE_FROM', 5),
                default => null,
            };
            if (($auth['spf'] ?? '') === 'pass' && ($auth['dmarc'] ?? '') === 'pass') {
                $add('AUTH_OK', -1);
            }
            foreach (self::dnsbl((string) ($auth['ip'] ?? '')) as $zone) {
                $add('DNSBL ' . $zone, 5);
            }
        }

        // Headers.
        if ($p->messageId() === '') {
            $add('NO_MESSAGE_ID', 1.5);
        }
        $date = $p->date();
        if ($date === 0) {
            $add('NO_DATE', 1);
        } elseif ($date > time() + 86400) {
            $add('DATE_FUTURE', 2);
        }
        $name = $p->from()['name'] ?? '';
        if (preg_match('/[\w.+-]+@([\w-]+\.)+[\w-]{2,}/', $name, $m) && Address::domain($m[0]) !== Address::domain($from)) {
            $add('FROM_NAME_OTHER_ADDRESS', 3);
        }
        $replyTo = strtolower($p->replyTo()[0]['email'] ?? '');
        if ($replyTo !== '' && Address::domain($replyTo) !== Address::domain($from) && in_array(Address::domain($replyTo), self::FREEMAIL, true)) {
            $add('REPLYTO_FREEMAIL', 2);
        }
        if (!$p->to() && !$p->cc()) {
            $add('NO_RECIPIENT_HEADER', 1);
        }

        // Wording.
        $subject = $p->subject();
        if (trim($subject) === '') {
            $add('EMPTY_SUBJECT', 1);
        }
        $letters = preg_replace('/[^\p{L}]/u', '', $subject) ?? '';
        if (mb_strlen($letters) >= 10 && mb_strtoupper($letters) === $letters) {
            $add('SUBJECT_CAPS', 1.5);
        }
        if (substr_count($subject, '!') >= 3 || str_contains($subject, '$$') || str_contains($subject, '€€')) {
            $add('SUBJECT_SHOUTING', 1);
        }
        $text = mb_strtolower($subject . ' ' . mb_substr($p->text(), 0, 20000));
        $hits = 0;
        foreach (self::WORDS as $w) {
            if (str_contains($text, $w)) {
                $hits++;
            }
        }
        if ($hits) {
            $add('SPAM_WORDS', min(4.5, 1.5 * $hits));
        }

        // Links.
        $html = $p->html();
        $links = LinkCheck::links($html);
        $seen = [];
        foreach ($links as $l) {
            foreach (LinkCheck::problems($l['href'], $l['text']) as $pb) {
                $seen[$pb] = true;
            }
        }
        $weights = ['mismatch' => 4, 'ip' => 2.5, 'shortener' => 1, 'punycode' => 2, 'userinfo' => 3, 'script' => 3];
        foreach (array_keys($seen) as $pb) {
            $add('LINK_' . strtoupper($pb), $weights[$pb] ?? 1);
        }
        $bait = false;
        foreach (self::BAIT as $b) {
            if (str_contains($text, $b)) {
                $bait = true;
                break;
            }
        }
        if ($bait && $links) {
            $add('CREDENTIAL_BAIT', 2.5);
        }
        if ($html !== '' && preg_match('/(font-size\s*:\s*0|display\s*:\s*none|visibility\s*:\s*hidden)[^>]*>[^<]{20,}/i', $html)) {
            $add('HIDDEN_TEXT', 1.5);
        }
        if ($html !== '' && !$p->root->isMultipart() && count($links) >= 1 && mb_strlen(trim(strip_tags($html))) < 40) {
            $add('LINK_ONLY', 1.5);
        }

        // Links known as dangerous: URLhaus feed and the administrator's list (local, free); Google
        // Safe Browsing too when the administrator chose to check at reception.
        if ($links && LinkGuard::threats(array_column($links, 'href'), (int) Settings::get('security.safebrowsing_delivery', 0) === 1)) {
            $add('LINK_THREAT', 15);
        }
        // Impersonation: a colleague's name on an outside address, a domain imitating ours.
        if (self::colleagueName($p) !== null) {
            $add('COLLEAGUE_NAME', 3);
        }
        if (self::lookalikeDomain(Address::domain($from)) !== null) {
            $add('LOOKALIKE_DOMAIN', 4);
        }

        // What people of the organisation taught the filter (Junk / Not junk), and senders they reported.
        $prob = Bayes::score($p);
        if ($prob !== null && ($w = Bayes::weight($prob)) != 0.0) {
            $add($w > 0 ? 'BAYES_SPAM' : 'BAYES_HAM', $w);
        }
        if ($from !== '' && DB::value('SELECT COUNT(*) FROM reported_senders WHERE address = :a', ['a' => $from])) {
            $add('REPORTED_SENDER', 6);
        }

        // Attachments (risk analysis already done on delivery).
        foreach ($p->attachmentList() as $a) {
            if (!empty($a['removed'])) {
                $add('VIRUS', 10);
            } elseif (($a['risk'] ?? '') === 'block') {
                $add('ATTACH_PROGRAM', ($a['why'] ?? '') === 'disguised' ? 8 : 5);
            } elseif (in_array($a['why'] ?? '', ['macros', 'active_content', 'double_ext'], true)) {
                $add('ATTACH_' . strtoupper((string) $a['why']), 2);
            }
        }
        return ['score' => round(array_sum($t), 1), 'tests' => $t, 'list' => ''];
    }

    /** Per recipient: people the user already wrote to are trusted a little more. */
    public static function forUser(array $base, int $userId, ParsedMessage $p, ?array $auth): array
    {
        if ($base['list'] !== '') {
            return $base + ['level' => $base['list'] === 'block' ? 'delete' : 'ok'];
        }
        $from = strtolower($p->from()['email']);
        $authOk = $auth === null || (($auth['verdict'] ?? 'ok') === 'ok' && (($auth['spf'] ?? '') === 'pass' || array_filter($auth['dkim'] ?? [], static fn($d) => ($d['result'] ?? '') === 'pass')));
        if ($from !== '' && $authOk && DB::value('SELECT COUNT(*) FROM contacts WHERE user_id = :u AND LOWER(email) = :e', ['u' => $userId, 'e' => $from])) {
            $base['tests']['KNOWN_CORRESPONDENT'] = -3.0;
            $base['score'] = round($base['score'] - 3, 1);
        }
        $base['level'] = self::level($base['score']);
        return $base;
    }

    private static function plain(string $s): string
    {
        $s = mb_strtolower(trim($s));
        if (class_exists(\Normalizer::class)) {
            $s = preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($s, \Normalizer::FORM_D)) ?? $s;
        }
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? $s);
    }

    /** The colleague whose name an outside sender uses ("Marie Dupont <marie.dupont.pdg@gmail.com>"), or null. */
    public static function colleagueName(ParsedMessage $p): ?string
    {
        $from = $p->from();
        $name = self::plain((string) ($from['name'] ?? ''));
        if ($name === '' || !str_contains($name, ' ') || Users::isLocalDomain(Address::domain((string) $from['email']))) {
            return null;
        }
        foreach (DB::all("SELECT email, display_name, first_name, last_name FROM users") as $u) {
            $names = [self::plain((string) $u['display_name']), self::plain($u['first_name'] . ' ' . $u['last_name']), self::plain($u['last_name'] . ' ' . $u['first_name'])];
            if (in_array($name, array_filter($names, static fn($n) => str_contains($n, ' ')), true)) {
                return (string) $u['email'];
            }
        }
        return null;
    }

    /** Our hosted domain that an outside domain imitates (acme-corp.fr, acrne.fr, acme.com for acme.fr), or null. */
    public static function lookalikeDomain(string $domain): ?string
    {
        $domain = strtolower($domain);
        if ($domain === '' || Users::isLocalDomain($domain)) {
            return null;
        }
        $reg = LinkCheck::registrable($domain);
        $name = strstr($reg, '.', true) ?: $reg;
        $flat = str_replace('-', '', $name);
        foreach (DB::all('SELECT name FROM domains WHERE active = 1') as $d) {
            $ours = (string) $d['name'];
            $oName = strstr(LinkCheck::registrable($ours), '.', true) ?: $ours;
            if ($reg === LinkCheck::registrable($ours)) {
                continue; // a subdomain of ours is not an imitation
            }
            if ($name === $oName || $flat === $oName) {
                return $ours; // same name, other extension or with a dash
            }
            if (strlen($oName) >= 5 && (levenshtein($flat, $oName) <= (strlen($oName) >= 8 ? 2 : 1) || (str_contains($flat, $oName) && strlen($flat) - strlen($oName) <= 8))) {
                return $ours;
            }
            // Characters that look alike once rendered: rn/m, vv/w, 0/o, 1/l.
            if (strtr(str_replace(['rn', 'vv'], ['m', 'w'], $flat), '01', 'ol') === strtr($oName, '01', 'ol') && $flat !== $oName) {
                return $ours;
            }
        }
        return null;
    }

    /** SpamAssassin / rspamd score in the headers, or null. */
    private static function upstreamScore(ParsedMessage $p): ?float
    {
        foreach (['X-Spam-Score', 'X-Rspamd-Score'] as $h) {
            $v = $p->header($h);
            if ($v !== null && preg_match('/^\s*(-?\d+(?:\.\d+)?)/', $v, $m)) {
                return (float) $m[1];
            }
        }
        if (preg_match('/\bscore=(-?\d+(?:\.\d+)?)/i', (string) $p->header('X-Spam-Status'), $m)) {
            return (float) $m[1];
        }
        if (preg_match('/\[\s*(-?\d+(?:\.\d+)?)\s*\/\s*\d/', (string) $p->header('X-Spamd-Result'), $m)) {
            return (float) $m[1];
        }
        return null;
    }

    /** @return string[] block list zones listing this address */
    private static function dnsbl(string $ip): array
    {
        $zones = self::list('spam.dnsbl');
        if ($ip === '' || !$zones || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return [];
        }
        $rev = implode('.', array_reverse(explode('.', $ip)));
        $hits = [];
        foreach (array_slice($zones, 0, 5) as $z) {
            $a = gethostbyname($rev . '.' . $z);
            // 127.0.0.2-127.0.0.254 = listed; 127.255.255.x = the list refuses the resolver.
            if (preg_match('/^127\.0\.0\.(\d+)$/', $a, $m) && (int) $m[1] >= 2) {
                $hits[] = $z;
            }
        }
        return $hits;
    }

    /** X-M4W-Spam header for the stored copy. */
    public static function header(array $r): string
    {
        $tests = implode(',', array_map(static fn($k, $v) => str_replace([' ', ',', ';', ':'], '_', $k) . ':' . $v, array_keys($r['tests']), $r['tests']));
        return self::HEADER . ': score=' . $r['score'] . '; level=' . $r['level'] . '; tests=' . $tests . "\n";
    }

    /** @return array{score:float,level:string,tests:array<string,float>}|null */
    public static function parse(?string $header): ?array
    {
        if ($header === null || !preg_match('/score=(-?[\d.]+);\s*level=(\w+);\s*tests=(.*)$/', $header, $m)) {
            return null;
        }
        $tests = [];
        foreach (array_filter(explode(',', trim($m[3]))) as $kv) {
            [$k, $v] = array_pad(explode(':', $kv, 2), 2, '0');
            $tests[$k] = (float) $v;
        }
        return ['score' => (float) $m[1], 'level' => in_array($m[2], self::LEVELS, true) ? $m[2] : 'ok', 'tests' => $tests];
    }

    /** Human label of a test code. */
    public static function label(string $code): string
    {
        if (str_starts_with($code, 'DNSBL')) {
            return t('spam.t.DNSBL', ['zone' => trim(substr($code, 5), ' _')]);
        }
        $k = 'spam.t.' . $code;
        $l = t($k);
        return $l === $k ? $code : $l;
    }
}
