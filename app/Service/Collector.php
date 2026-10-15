<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Audit;
use M4W\Core\Database as DB;
use M4W\Core\Settings;
use M4W\Mail\Address;
use M4W\Mail\MimeParser;
use M4W\Mail\ParsedMessage;
use M4W\Mail\SmtpException;

/**
 * "Collector" mode: a single mailbox at the provider (e.g. mail@domain.com) receives
 * the mail of every user through the provider's redirections (a@ → mail@, b@ → mail@…).
 *
 *   - Reception: the collector mailbox is pulled over IMAP and every message is handed
 *     to the user(s) it was addressed to, read from the envelope traces the provider
 *     leaves (X-Original-To, Delivered-To, Received … for <…>), else from To/Cc.
 *     What cannot be attributed lands in a fallback mailbox, never lost.
 *   - Sending: everything leaves through the collector account (envelope sender = the
 *     collector address, From: = the user). Colleagues are served locally.
 *   - The provider caps the account (OVH: 200 messages per hour). Every external
 *     recipient is counted; beyond the cap messages wait in the queue and leave
 *     on their own as soon as the window allows.
 */
final class Collector
{
    /** Pseudo user id of the collector account in remote_accounts. */
    public const ACCOUNT = 0;

    /** Marker of queue entries held back by the hourly limit. */
    public const HELD = 'rate-limit';

    public static function active(): bool
    {
        return RemoteMailbox::mode() === 'collector';
    }

    // ------------------------------------------------------------ account

    public static function account(): ?array
    {
        return RemoteMailbox::find(self::ACCOUNT);
    }

    public static function address(): string
    {
        return mb_strtolower((string) (self::account()['login'] ?? ''));
    }

    /** Save the collector credentials (administration). An empty password keeps the stored one. */
    public static function save(string $address, string $password, int $by): void
    {
        $address = mb_strtolower(trim($address));
        if (!is_valid_email($address)) {
            throw new \InvalidArgumentException(t('col.invalid_address'));
        }
        if (Users::findByEmail($address)) {
            // Its mail would be dispatched to itself.
            throw new \InvalidArgumentException(t('col.address_is_user'));
        }
        $old = self::account();
        RemoteMailbox::save(self::ACCOUNT, ['login' => $address, 'password' => $password, 'enabled' => 1], $by);
        if ($old && mb_strtolower((string) $old['login']) !== $address) {
            // Another mailbox: its message numbers have nothing to do with the previous one.
            DB::update('remote_accounts', ['inbox_uid' => 0, 'inbox_validity' => 0, 'spam_uid' => 0, 'spam_validity' => 0, 'last_ok_at' => 0, 'last_error' => ''], 'id = :id', ['id' => $old['id']]);
            DB::delete('remote_uids', 'account_id = :a', ['a' => $old['id']]);
        }
        Audit::log('collector.saved', $address, ['password_changed' => $password !== ''], $by);
    }

    // ----------------------------------------------------------- reception

    /** Hand a message pulled from the collector mailbox to its recipients. @return int[] user ids */
    public static function dispatch(string $raw, string $folder = 'inbox'): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $p = MimeParser::parse($raw);
        $users = self::recipients($p, $raw);
        $unrouted = !$users;
        if ($unrouted) {
            $fallback = self::fallbackUser();
            if (!$fallback) {
                error_log('[m4w] collector: no recipient and no fallback mailbox for ' . $p->messageId());
                return [];
            }
            $users = [$fallback];
        }
        // Without Message-ID, the content itself (our collection line apart) identifies the copies.
        $hash = hash('sha256', $p->messageId() !== '' ? 'id:' . mb_strtolower($p->messageId()) : 'raw:' . (preg_replace('/^X-M4W-Fetched: [^\n]*\n/', '', str_replace("\r\n", "\n", $raw)) ?? $raw));
        $rp = trim((string) $p->header('Return-Path'));
        $envelope = preg_match('/<([^>]*)>/', $rp, $m) ? trim($m[1]) : ($rp !== '' ? $rp : ($p->from()['email'] ?? ''));
        if ($envelope !== '' && !is_valid_email(Address::asciiEmail($envelope))) {
            $envelope = '';
        }
        $done = [];
        foreach ($users as $u) {
            // A user with an own mailbox imposed by the administrator gets mail there (no double copy).
            if (!$unrouted && RemoteMailbox::personal((int) $u['id'])) {
                continue;
            }
            // The provider may deliver one copy per redirected address: each user gets it once.
            try {
                DB::insert('collector_seen', ['msg_hash' => $hash, 'user_id' => (int) $u['id'], 'created_at' => time()]);
            } catch (\PDOException) {
                continue;
            }
            $head = 'Delivered-To: ' . $u['email'] . "\n" . ($unrouted ? 'X-M4W-Unrouted: ' . self::address() . "\n" : '');
            if ($folder === 'spam') {
                Mailbox::store((int) $u['id'], (int) Folders::byRole((int) $u['id'], 'spam')['id'], $head . Delivery::sanitizeIncoming($raw));
            } else {
                Delivery::deliver($head . $raw, $envelope, [$u['email']], 'sync');
            }
            $done[] = (int) $u['id'];
        }
        return $done;
    }

    /**
     * Local users a message was addressed to.
     * Envelope traces first (they name Bcc recipients too), then the visible headers;
     * a delivery report goes back to the colleague who sent the original message.
     * @return array<int,array> users keyed by id
     */
    public static function recipients(ParsedMessage $p, string $raw): array
    {
        $collector = self::address();
        $resolve = static function (array $addresses) use ($collector): array {
            $out = [];
            foreach ($addresses as $a) {
                $a = mb_strtolower(trim($a, " \t<>\"'"));
                if ($a === '' || $a === $collector || !str_contains($a, '@')) {
                    continue;
                }
                $u = Users::resolveLocal($a);
                if ($u) {
                    $out[(int) $u['id']] = $u;
                }
            }
            return $out;
        };
        $envelope = [];
        foreach (['X-Original-To', 'X-Envelope-To', 'Envelope-To', 'X-Rcpt-To', 'X-Delivered-To', 'Delivered-To'] as $h) {
            foreach ($p->headerAll($h) as $v) {
                foreach (preg_split('/[\s,;]+/', (string) $v) ?: [] as $a) {
                    $envelope[] = $a;
                }
            }
        }
        if ($found = $resolve($envelope)) {
            return $found;
        }
        // "Received: … for <a@domain.com>; date" written by the provider's mail exchangers.
        $for = [];
        foreach (array_slice($p->headerAll('Received'), 0, 6) as $v) {
            if (preg_match('/\bfor\s+<?([^\s<>;]+@[^\s<>;]+)>?/i', (string) $v, $m)) {
                $for[] = $m[1];
            }
        }
        if ($found = $resolve($for)) {
            return $found;
        }
        if ($found = $resolve(array_column(array_merge($p->to(), $p->cc()), 'email'))) {
            return $found;
        }
        // Bounce of a message we sent with the collector as envelope sender.
        $ct = mb_strtolower((string) $p->header('Content-Type'));
        if (str_contains($ct, 'multipart/report') || $p->isAutomated()) {
            $body = substr($raw, (int) strpos($raw, "\n\n"));
            if (preg_match_all('/^From:[ \t]*(.+)$/mi', $body, $m)) {
                foreach ($m[1] as $line) {
                    if ($found = $resolve(array_column(Address::parseList(trim($line)), 'email'))) {
                        return $found;
                    }
                }
            }
        }
        return [];
    }

    /** Mailbox receiving what cannot be attributed (setting, else the first administrator). */
    public static function fallbackUser(): ?array
    {
        $id = (int) Settings::get('collector.fallback_user', 0);
        $u = $id ? Users::find($id) : null;
        if ($u && $u['status'] === 'active') {
            return $u;
        }
        $id = (int) DB::value("SELECT id FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id LIMIT 1");
        return $id ? Users::find($id) : null;
    }

    /** Addresses the administrator must redirect to the collector at the provider. @return string[] */
    public static function addressesToRedirect(): array
    {
        // Not the users who keep their own mailbox at the provider: their mail stays there.
        $own = "NOT EXISTS (SELECT 1 FROM remote_accounts r WHERE r.user_id = u.id AND r.forced = 1 AND r.enabled = 1)";
        $list = array_column(DB::all("SELECT u.email FROM users u WHERE u.status = 'active' AND $own ORDER BY u.email"), 'email');
        foreach (DB::all("SELECT a.address FROM aliases a JOIN users u ON u.id = a.user_id WHERE u.status = 'active' AND $own ORDER BY a.address") as $a) {
            $list[] = $a['address'];
        }
        return array_values(array_unique(array_map('mb_strtolower', $list)));
    }

    // -------------------------------------------------------------- sending

    public static function limit(): int
    {
        return max(1, (int) Settings::get('collector.hourly_limit', 200));
    }

    /** External recipients sent during the last hour. */
    public static function used(?int $now = null): int
    {
        $now ??= time();
        return (int) DB::value('SELECT COALESCE(SUM(recipients), 0) FROM send_log WHERE account = 0 AND created_at > :t', ['t' => $now - 3600]);
    }

    /** Messages waiting for the window to reopen. */
    public static function held(): int
    {
        return (int) DB::value("SELECT COUNT(*) FROM mail_queue WHERE status = 'pending' AND last_error = :h", ['h' => self::HELD]);
    }

    /** When at least one recipient can leave again. */
    public static function nextSlot(?int $now = null): int
    {
        $now ??= time();
        $limit = self::limit();
        $used = self::used($now);
        if ($used < $limit) {
            return $now;
        }
        $block = self::providerBlock();
        foreach (DB::all('SELECT recipients, created_at FROM send_log WHERE account = 0 AND created_at > :t ORDER BY created_at', ['t' => $now - 3600]) as $r) {
            $used -= (int) $r['recipients'];
            if ($used < $limit) {
                return max((int) $r['created_at'] + 3601, $block ? $block['until'] : 0);
            }
        }
        return $now + 60;
    }

    /** Refusal from the provider itself (its counter may differ from ours). */
    public static function providerBlock(): ?array
    {
        $b = json_decode((string) Settings::get('collector.block', ''), true);
        return is_array($b) && (int) ($b['until'] ?? 0) > time() ? $b : null;
    }

    /**
     * Send through the collector account, within the hourly limit.
     * @return array{sent:string[],failed:array<string,string>,deferred:string[],retry_at:int}
     */
    public static function send(string $raw, array $rcpts, ?int $userId, bool $fromQueue, string $kind = 'mail'): array
    {
        $out = ['sent' => [], 'failed' => [], 'deferred' => [], 'retry_at' => 0];
        $acc = self::account();
        if (!$acc || !(int) $acc['enabled'] || (string) $acc['password_enc'] === '') {
            $out['failed'] = array_fill_keys($rcpts, t('col.not_configured'));
            return $out;
        }
        $available = self::limit() - self::used();
        // First come, first served: nothing overtakes messages already waiting.
        if ((!$fromQueue && self::held() > 0) || self::providerBlock()) {
            $available = 0;
        }
        $now = array_slice($rcpts, 0, max(0, $available));
        $out['deferred'] = array_values(array_slice($rcpts, count($now)));
        if ($now) {
            // Counted before the connection, so concurrent senders see it.
            $logId = DB::insert('send_log', ['user_id' => $userId, 'kind' => $kind, 'recipients' => count($now), 'created_at' => time()]);
            try {
                $client = RemoteMailbox::smtp($acc);
                $client->connect();
                $rejected = $client->send((string) $acc['login'], $now, $raw);
                $client->quit();
                $out['sent'] = array_values(array_diff($now, array_keys($rejected)));
                foreach ($rejected as $rcpt => $why) {
                    if (self::isRateLimitText($why)) {
                        $out['deferred'][] = $rcpt;
                    } else {
                        $out['failed'][$rcpt] = $why;
                    }
                }
            } catch (SmtpException $e) {
                if (self::isRateLimit($e)) {
                    // The provider counts differently (other software on the account…): wait.
                    Settings::set('collector.block', json_encode(['at' => time(), 'until' => time() + 900, 'message' => mb_substr($e->getMessage(), 0, 300)]));
                    Audit::log('collector.blocked', mb_substr($e->getMessage(), 0, 200));
                    $out['deferred'] = array_merge($now, $out['deferred']);
                } else {
                    $out['failed'] = array_fill_keys($now, $e->getMessage());
                    if (in_array($e->smtpCode, [530, 534, 535], true) || stripos($e->getMessage(), 'auth') !== false) {
                        DB::update('remote_accounts', ['last_error' => mb_substr('SMTP : ' . $e->getMessage(), 0, 500)], 'id = :id', ['id' => $acc['id']]);
                    }
                }
                $out['sent'] = [];
            }
            if (count($out['sent']) === 0) {
                DB::delete('send_log', 'id = :id', ['id' => $logId]);
            } elseif (count($out['sent']) !== count($now)) {
                DB::update('send_log', ['recipients' => count($out['sent'])], 'id = :id', ['id' => $logId]);
            }
        }
        if ($out['deferred']) {
            $out['retry_at'] = self::nextSlot();
        }
        return $out;
    }

    private static function isRateLimit(SmtpException $e): bool
    {
        return self::isRateLimitText($e->getMessage()) && !in_array($e->smtpCode, [530, 534, 535, 552], true);
    }

    /** Provider wording for a sending cap ("rate limit exceeded", "too many messages"…), not a full mailbox. */
    public static function isRateLimitText(string $msg): bool
    {
        return (bool) preg_match('/rate.?limit|too many (messages|mails|emails|recipients)|sending limit|limit (exceeded|reached)|exceeded .*limit|throttl|trop de messages|limite d.envoi/i', $msg);
    }

    // ------------------------------------------------------------- display

    /** Gauge shown to administrators. */
    public static function stats(): array
    {
        $now = time();
        $limit = self::limit();
        $used = self::used($now);
        $buckets = array_fill(0, 12, 0);
        foreach (DB::all('SELECT recipients, created_at FROM send_log WHERE account = 0 AND created_at > :t', ['t' => $now - 3600]) as $r) {
            $i = min(11, max(0, 11 - intdiv($now - (int) $r['created_at'], 300)));
            $buckets[$i] += (int) $r['recipients'];
        }
        $top = DB::all('SELECT s.user_id, u.email, SUM(s.recipients) AS n FROM send_log s LEFT JOIN users u ON u.id = s.user_id WHERE s.account = 0 AND s.created_at > :t GROUP BY s.user_id, u.email ORDER BY n DESC LIMIT 5', ['t' => $now - 3600]);
        $held = self::held();
        $block = self::providerBlock();
        $pct = (int) min(100, round($used / $limit * 100));
        return [
            'limit' => $limit, 'used' => $used, 'left' => max(0, $limit - $used), 'pct' => $pct,
            'level' => ($used >= $limit || $block) ? 'full' : ($pct >= 80 ? 'high' : ($pct >= 50 ? 'mid' : 'low')),
            'held' => $held, 'next' => ($held || $used >= $limit || $block) ? self::nextSlot($now) : 0,
            'buckets' => $buckets, 'top' => $top, 'block' => $block,
            'held_rcpts' => (int) array_sum(array_map(static fn($r) => count(json_decode((string) $r['recipients'], true) ?: []),
                DB::all("SELECT recipients FROM mail_queue WHERE status = 'pending' AND last_error = :h", ['h' => self::HELD]))),
        ];
    }

    /** Hourly maintenance. */
    public static function purge(): void
    {
        DB::run('DELETE FROM send_log WHERE created_at < :t', ['t' => time() - 2 * 86400]);
        DB::run('DELETE FROM collector_seen WHERE created_at < :t', ['t' => time() - 30 * 86400]);
    }
}
