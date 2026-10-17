<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Audit;
use M4W\Core\Database as DB;
use M4W\Core\Request;
use M4W\Core\Settings;
use M4W\Mail\Address;
use M4W\Mail\MimeBuilder;

/**
 * Compromised accounts. A stolen password is used for two things: reading mail and sending
 * spam or phishing from a trusted address. So:
 *  - sending limits per person (outside recipients per hour and per day);
 *  - a sudden burst stops the account's outgoing mail at once, until an administrator checks;
 *  - sign-ins from a new place or device, repeated wrong passwords, a new forwarding to the
 *    outside, a changed password: the person is told (and administrators for serious cases).
 */
final class AccountGuard
{
    public const FOREVER = 2147483647;
    /** Automatic answers: never counted, only stopped while the account is blocked. */
    private const UNCOUNTED = ['vacation', 'autoreply'];

    public static function enabled(): bool
    {
        return (int) Settings::get('guard.enabled', 1) === 1;
    }

    /** @return array{hour:int,day:int,burst:int,burst_minutes:int} */
    public static function limits(): array
    {
        return [
            'hour' => max(1, (int) Settings::get('guard.hour_limit', 200)),
            'day' => max(1, (int) Settings::get('guard.day_limit', 1000)),
            'burst' => max(1, (int) Settings::get('guard.burst', 80)),
            'burst_minutes' => max(1, (int) Settings::get('guard.burst_minutes', 10)),
        ];
    }

    /** Outside recipients (colleagues do not count: they are not spam targets). */
    public static function external(array $recipients): array
    {
        return array_values(array_filter(array_unique(array_map('mb_strtolower', $recipients)),
            static fn($r) => !Users::isLocalDomain(Address::domain($r))));
    }

    public static function blocked(array $user): bool
    {
        return (int) ($user['send_blocked_until'] ?? 0) > time();
    }

    /** Outside recipients already sent to: [last hour, last day, last burst window]. */
    public static function counts(int $userId, ?int $now = null): array
    {
        $now ??= time();
        $sum = static fn(int $since) => (int) DB::value('SELECT COALESCE(SUM(recipients), 0) FROM guard_sends WHERE user_id = :u AND created_at > :t', ['u' => $userId, 't' => $since]);
        return [$sum($now - 3600), $sum($now - 86400), $sum($now - self::limits()['burst_minutes'] * 60)];
    }

    /**
     * Before a person's mail leaves. $interactive: they are sending it themselves (an error
     * message is shown); otherwise (forwards, invitations) the message is simply not sent.
     */
    public static function checkSend(int $userId, array $recipients, string $kind, bool $interactive): bool
    {
        if (!self::enabled()) {
            return true;
        }
        $user = Users::find($userId);
        if (!$user) {
            return true;
        }
        $fail = static function (string $msg) use ($interactive, $userId, $kind): bool {
            if ($interactive) {
                throw new \RuntimeException($msg);
            }
            Audit::log('guard.dropped', $kind, [], $userId);
            return false;
        };
        if (self::blocked($user)) {
            return $fail(t('guard.blocked'));
        }
        if (in_array($kind, self::UNCOUNTED, true)) {
            return true;
        }
        $ext = count(self::external($recipients));
        if ($ext === 0) {
            return true;
        }
        $l = self::limits();
        [$hour, $day, $burst] = self::counts($userId);
        if ($burst + $ext > $l['burst']) {
            self::block($userId, 'burst', t('guard.reason_burst', ['n' => $burst + $ext, 'minutes' => $l['burst_minutes']]));
            return $fail(t('guard.blocked'));
        }
        if ($hour + $ext > $l['hour']) {
            return $fail(t('guard.hour_limit', ['n' => $l['hour']]));
        }
        if ($day + $ext > $l['day']) {
            return $fail(t('guard.day_limit', ['n' => $l['day']]));
        }
        DB::insert('guard_sends', ['user_id' => $userId, 'kind' => mb_substr($kind, 0, 20), 'recipients' => $ext, 'created_at' => time()]);
        return true;
    }

    /** Stop the account's outgoing mail until an administrator looks (and, if chosen, sign it out). */
    public static function block(int $userId, string $why, string $details, ?int $until = null): void
    {
        $user = Users::find($userId);
        if (!$user || self::blocked($user)) {
            return;
        }
        DB::update('users', ['send_blocked_until' => $until ?? self::FOREVER, 'send_block_reason' => mb_substr($details, 0, 255)], 'id = :id', ['id' => $userId]);
        // Mail of this person still waiting in the queue stays there.
        DB::run("UPDATE mail_queue SET status = 'held', last_error = :e WHERE user_id = :u AND status = 'pending'", ['u' => $userId, 'e' => 'account blocked']);
        $locked = false;
        if (Settings::get('guard.on_burst', 'block_send') === 'lock' && !is_admin($user)) {
            DB::update('users', ['status' => 'disabled'], 'id = :id', ['id' => $userId]);
            DB::run('UPDATE user_sessions SET revoked = 1 WHERE user_id = :u', ['u' => $userId]);
            DB::run('DELETE FROM app_passwords WHERE user_id = :u', ['u' => $userId]);
            $locked = true;
        }
        Audit::log('guard.blocked', $user['email'], ['why' => $why, 'locked' => $locked], $userId);
        self::alert($userId, 'send_blocked', 'high', $details . ($locked ? ' — ' . t('guard.account_locked') : ''));
        self::notifyUser($user, t('guard.mail_blocked_subject'), t('guard.mail_blocked_body', ['details' => $details]));
    }

    public static function unblock(int $userId, int $adminId): void
    {
        $user = Users::find($userId);
        if (!$user) {
            return;
        }
        DB::update('users', ['send_blocked_until' => 0, 'send_block_reason' => ''], 'id = :id', ['id' => $userId]);
        DB::run("UPDATE mail_queue SET status = 'pending', last_error = '' WHERE user_id = :u AND status = 'held' AND last_error = 'account blocked'", ['u' => $userId]);
        DB::run("UPDATE security_alerts SET resolved_at = :t, resolved_by = :a WHERE user_id = :u AND kind = 'send_blocked' AND resolved_at = 0", ['t' => time(), 'a' => $adminId, 'u' => $userId]);
        // A fresh start for the counters, or the next message would block it again.
        DB::run('DELETE FROM guard_sends WHERE user_id = :u', ['u' => $userId]);
        Audit::log('guard.unblocked', $user['email'], [], $adminId);
    }

    /** Discard what was waiting in the queue (spam sent by an intruder). */
    public static function discardHeld(int $userId, int $adminId): int
    {
        $n = 0;
        foreach (DB::all("SELECT * FROM mail_queue WHERE user_id = :u AND status = 'held'", ['u' => $userId]) as $q) {
            @unlink(storage_path((string) $q['raw_path']));
            DB::delete('mail_queue', 'id = :id', ['id' => $q['id']]);
            $n++;
        }
        Audit::log('guard.discarded', (string) $n, ['user' => $userId], $adminId);
        return $n;
    }

    // ---------------------------------------------------------------- sign-ins

    /** The network a sign-in comes from: IPv4 /24, IPv6 /48 (an operator, an office, a home). */
    public static function network(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return $ip;
        }
        if (strlen($bin) === 4) {
            return inet_ntop(substr($bin, 0, 3) . "\0") . '/24';
        }
        return inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10)) . '/48';
    }

    /** After a successful sign-in: new place and device are reported to the person. */
    public static function onLogin(array $user, string $ip, string $userAgent, ?int $now = null): bool
    {
        if (!self::enabled()) {
            return false;
        }
        $now ??= time();
        $uid = (int) $user['id'];
        $net = self::network($ip);
        $agent = mb_substr(WebPush::deviceLabel($userAgent), 0, 120);
        $first = !DB::value('SELECT COUNT(*) FROM known_logins WHERE user_id = :u', ['u' => $uid]);
        $row = DB::one('SELECT id FROM known_logins WHERE user_id = :u AND net = :n AND agent = :a', ['u' => $uid, 'n' => $net, 'a' => $agent]);
        if ($row) {
            DB::update('known_logins', ['last_at' => $now, 'last_ip' => mb_substr($ip, 0, 64)], 'id = :id', ['id' => $row['id']]);
            return false;
        }
        $knownNet = (bool) DB::value('SELECT COUNT(*) FROM known_logins WHERE user_id = :u AND net = :n', ['u' => $uid, 'n' => $net]);
        DB::insert('known_logins', ['user_id' => $uid, 'net' => $net, 'agent' => $agent, 'first_at' => $now, 'last_at' => $now, 'last_ip' => mb_substr($ip, 0, 64)]);
        if ($first || $knownNet || in_array($ip, ['127.0.0.1', '::1'], true)) {
            return false; // the very first sign-in, or a new browser on a usual network
        }
        self::alert($uid, 'new_login', 'info', $agent . ' — ' . $ip, $ip);
        if ((int) Settings::get('guard.new_login_mail', 1)) {
            self::notifyUser($user, t('guard.mail_login_subject'), t('guard.mail_login_body', ['device' => $agent, 'ip' => $ip, 'date' => format_datetime($now)]));
        }
        return true;
    }

    /** Wrong passwords keep coming for an account (it is locked for a while): tell the person, once an hour. */
    public static function onLocked(string $email, string $ip): void
    {
        $user = Users::findByEmail($email);
        if (!$user || !self::enabled()) {
            return;
        }
        if (DB::value("SELECT COUNT(*) FROM security_alerts WHERE user_id = :u AND kind = 'brute_force' AND created_at > :t", ['u' => $user['id'], 't' => time() - 3600])) {
            return;
        }
        self::alert((int) $user['id'], 'brute_force', 'medium', $ip, $ip);
        self::notifyUser($user, t('guard.mail_brute_subject'), t('guard.mail_brute_body', ['ip' => $ip]));
    }

    /** Forwarding to the outside switched on (a favourite trick to keep reading someone's mail). */
    public static function onForwarding(array $user, array $addresses, string $how): void
    {
        $ext = self::external($addresses);
        if (!$ext || !self::enabled()) {
            return;
        }
        self::alert((int) $user['id'], 'forwarding', 'medium', $how . ' → ' . implode(', ', $ext));
        self::notifyUser($user, t('guard.mail_forward_subject'), t('guard.mail_forward_body', ['to' => implode(', ', $ext)]));
    }

    public static function onPasswordChanged(array $user): void
    {
        if (self::enabled()) {
            self::notifyUser($user, t('guard.mail_password_subject'), t('guard.mail_password_body', ['date' => format_datetime(time())]));
        }
    }

    // ---------------------------------------------------------------- alerts

    public static function alert(?int $userId, string $kind, string $severity, string $details, string $ip = ''): int
    {
        $id = DB::insert('security_alerts', ['user_id' => $userId, 'kind' => $kind, 'severity' => $severity, 'details' => mb_substr($details, 0, 2000),
            'ip' => mb_substr($ip, 0, 64), 'created_at' => time()]);
        if ($severity === 'high' || ((int) Settings::get('guard.alert_admins_all', 0) && $severity !== 'info')) {
            $who = $userId ? Users::find($userId) : null;
            foreach (DB::all("SELECT id FROM users WHERE role IN ('admin', 'superadmin') AND status = 'active'") as $a) {
                $admin = Users::find((int) $a['id']);
                if ($admin) {
                    self::notifyUser($admin, t('guard.admin_subject', ['who' => $who['email'] ?? '?']),
                        t('guard.admin_body', ['who' => $who['email'] ?? '?', 'what' => t('guard.kind_' . $kind), 'details' => $details]), 'admin/security#guard');
                }
            }
        }
        return $id;
    }

    public static function openAlerts(int $limit = 50): array
    {
        return DB::all('SELECT a.*, u.email FROM security_alerts a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.resolved_at > 0, a.created_at DESC LIMIT ' . max(1, $limit));
    }

    public static function resolve(int $alertId, int $adminId): void
    {
        DB::update('security_alerts', ['resolved_at' => time(), 'resolved_by' => $adminId], 'id = :id AND resolved_at = 0', ['id' => $alertId]);
    }

    /** A notice in the person's inbox, and on their devices. */
    public static function notifyUser(array $user, string $subject, string $text, string $link = 'settings/security'): void
    {
        try {
            $domain = Address::domain($user['email']) ?: 'localhost';
            $b = new MimeBuilder();
            $b->from = ['email' => 'no-reply@' . $domain, 'name' => (string) Settings::get('brand.name', 'Make4WebMail')];
            $b->to = [['email' => $user['email'], 'name' => $user['name'] ?? '']];
            $b->subject = $subject;
            $b->messageId = MimeBuilder::newMessageId($domain);
            $b->extraHeaders['Auto-Submitted'] = 'auto-generated';
            $b->html = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.5;color:#1f2937">'
                . '<p style="white-space:pre-line">' . e($text) . '</p>'
                . '<p><a href="' . e(public_url($link)) . '">' . e(t('guard.mail_link')) . '</a></p></div>';
            Mailbox::store((int) $user['id'], (int) Folders::byRole((int) $user['id'], 'inbox')['id'], str_replace("\r\n", "\n", $b->build()));
            WebPush::send((int) $user['id'], ['title' => $subject, 'body' => mb_substr(strtok($text, "\n") ?: $text, 0, 140), 'tag' => 'm4w-guard', 'url' => $link]);
        } catch (\Throwable $e) {
            error_log('[m4w] guard notice: ' . $e->getMessage());
        }
    }

    /** Known places of sign-in, for the person's security page. */
    public static function knownLogins(int $userId): array
    {
        return DB::all('SELECT * FROM known_logins WHERE user_id = :u ORDER BY last_at DESC LIMIT 20', ['u' => $userId]);
    }

    public static function forgetLogin(int $userId, int $id): void
    {
        DB::run('DELETE FROM known_logins WHERE user_id = :u AND id = :id', ['u' => $userId, 'id' => $id]);
    }

    public static function gc(): void
    {
        $now = time();
        DB::run('DELETE FROM guard_sends WHERE created_at < :t', ['t' => $now - 2 * 86400]);
        DB::run('DELETE FROM known_logins WHERE last_at < :t', ['t' => $now - 400 * 86400]);
        DB::run('DELETE FROM security_alerts WHERE created_at < :t', ['t' => $now - 400 * 86400]);
    }
}
