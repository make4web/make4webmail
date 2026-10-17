<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Audit;
use M4W\Core\Crypto;
use M4W\Core\Database as DB;
use M4W\Core\Request;
use M4W\Core\Session;
use M4W\Core\Auth;
use M4W\Core\Totp;

/**
 * "My other mailboxes": mailboxes attached to a person's account (contact@, support@,
 * a mailbox several people share…) that they switch to from the account menu.
 *
 * Unlike delegation this is a standing, legitimate access: no reason asked, no access
 * journal, no notification, no Sender: header, nothing hidden. Attaching a mailbox
 * proves the right to it: its password (and 2FA code) — or being an administrator.
 */
final class SharedMailboxes
{
    private const KEY = 'shared_mbx';

    /** @var array<string, array|null> */
    private static array $current = [];

    /**
     * Unread mail that waits for someone: the inbox and the person's own folders (where rules
     * file it), not spam, trash, sent, drafts or archive. SQL for a mailbox id in $col.
     */
    private static function unreadSql(string $col): string
    {
        return "(SELECT COUNT(*) FROM messages m JOIN folders f ON f.id = m.folder_id WHERE m.user_id = $col AND (f.role = 'inbox' OR f.role IS NULL) AND m.is_read = 0 AND m.is_draft = 0)";
    }

    /** Unread mail waiting in a mailbox (see unreadSql). */
    public static function unread(int $mailboxId): int
    {
        return (int) DB::value('SELECT ' . self::unreadSql(':u'), ['u' => $mailboxId]);
    }

    /**
     * Unread counts of the mailboxes someone can switch to: 0 = their own, then each of their
     * other mailboxes by id. @return array<int,int>
     */
    public static function counts(int $userId): array
    {
        $out = [0 => self::unread($userId)];
        foreach (self::of($userId) as $mb) {
            $out[(int) $mb['id']] = (int) $mb['unread'];
        }
        return $out;
    }

    /** Mailboxes attached to a person's account, with their unread count (inbox and own folders). */
    public static function of(int $userId): array
    {
        return DB::all(
            "SELECT u.id, u.email, u.display_name, u.first_name, u.last_name, l.id AS link_id, l.created_at, l.method,
                " . self::unreadSql('u.id') . " AS unread
             FROM mailbox_links l JOIN users u ON u.id = l.mailbox_id
             WHERE l.user_id = :u AND u.status = 'active' ORDER BY u.email",
            ['u' => $userId]
        );
    }

    /** People who have a mailbox among their other mailboxes. @return int[] */
    public static function usersOf(int $mailboxId): array
    {
        return array_map('intval', array_column(DB::all(
            "SELECT l.user_id FROM mailbox_links l JOIN users u ON u.id = l.user_id WHERE l.mailbox_id = :m AND u.status = 'active'",
            ['m' => $mailboxId]
        ), 'user_id'));
    }

    public static function members(int $mailboxId): array
    {
        return DB::all('SELECT u.id, u.email, u.display_name, l.method, l.created_at FROM mailbox_links l JOIN users u ON u.id = l.user_id WHERE l.mailbox_id = :m ORDER BY u.email', ['m' => $mailboxId]);
    }

    public static function linked(int $userId, int $mailboxId): bool
    {
        return (bool) DB::value('SELECT 1 FROM mailbox_links WHERE user_id = :u AND mailbox_id = :m', ['u' => $userId, 'm' => $mailboxId]);
    }

    /**
     * Attach a mailbox to $actor's account. Administrators need no password; anyone
     * else proves the right to the mailbox with its password and, when it has two-factor
     * authentication, its current code. Failures count like failed logins.
     */
    public static function add(array $actor, string $email, string $password, string $code, Request $req): array
    {
        $email = mb_strtolower(trim($email));
        if (!is_valid_email($email)) {
            throw new \InvalidArgumentException(t('shared.invalid'));
        }
        if ($email === mb_strtolower((string) $actor['email'])) {
            throw new \InvalidArgumentException(t('shared.self'));
        }
        $isAdmin = is_admin($actor);
        if ($isAdmin) {
            $box = Users::findByEmail($email);
            if (!$box || $box['status'] !== 'active') {
                throw new \InvalidArgumentException(t('shared.unknown'));
            }
            $method = 'admin';
        } else {
            // Same throttling and same answer as the login form: no account enumeration.
            $res = Auth::attempt($email, $password, $req);
            if (!$res['ok']) {
                throw new \InvalidArgumentException($res['error']);
            }
            $box = $res['user'];
            if ((int) $box['totp_enabled']) {
                if (\M4W\Core\Auth::twoFactorLocked($email)) {
                    throw new \InvalidArgumentException(t('auth.locked', ['minutes' => max(1, (int) \M4W\Core\Settings::get('security.lockout_minutes', 15))]));
                }
                if (!self::checkTotp($box, $code)) {
                    \M4W\Core\Auth::twoFactorFailed($email, $req->ip());
                    throw new \InvalidArgumentException($code === '' ? t('shared.need_code') : t('auth.2fa_invalid'));
                }
            }
            $method = 'password';
        }
        if (self::linked((int) $actor['id'], (int) $box['id'])) {
            return $box;
        }
        DB::insert('mailbox_links', ['user_id' => (int) $actor['id'], 'mailbox_id' => (int) $box['id'], 'method' => $method, 'created_by' => (int) $actor['id'], 'created_at' => time()]);
        Audit::log('shared.added', $box['email'], ['method' => $method], (int) $actor['id']);
        if ($method === 'password') {
            // Opened with its password: the mailbox is told who, when and from where (a password
            // that leaked would otherwise give silent and lasting access).
            AccountGuard::notifyUser(Users::find((int) $box['id']) ?? $box, t('shared.notice_subject', ['name' => $actor['name'] ?? $actor['email']]),
                t('shared.notice_text', ['name' => $actor['name'] ?? $actor['email'], 'email' => $actor['email'], 'box' => $box['email'], 'date' => format_datetime(time()), 'ip' => $req->ip()]));
        }
        return $box;
    }

    /** Administration: give people access to a mailbox (replaces the list). */
    public static function setMembers(int $mailboxId, array $userIds, int $by): void
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn($i) => $i > 0 && $i !== $mailboxId)));
        $current = array_map('intval', array_column(DB::all('SELECT user_id FROM mailbox_links WHERE mailbox_id = :m', ['m' => $mailboxId]), 'user_id'));
        foreach (array_diff($current, $userIds) as $gone) {
            DB::delete('mailbox_links', 'user_id = :u AND mailbox_id = :m', ['u' => $gone, 'm' => $mailboxId]);
        }
        foreach (array_diff($userIds, $current) as $new) {
            if (DB::value("SELECT 1 FROM users WHERE id = :i AND status = 'active'", ['i' => $new])) {
                DB::insert('mailbox_links', ['user_id' => $new, 'mailbox_id' => $mailboxId, 'method' => 'admin', 'created_by' => $by, 'created_at' => time()]);
            }
        }
        if (array_diff($current, $userIds) || array_diff($userIds, $current)) {
            $email = (string) DB::value('SELECT email FROM users WHERE id = :i', ['i' => $mailboxId]);
            Audit::log('shared.members', $email, ['users' => count($userIds)], $by);
        }
    }

    public static function remove(int $userId, int $mailboxId): void
    {
        DB::delete('mailbox_links', 'user_id = :u AND mailbox_id = :m', ['u' => $userId, 'm' => $mailboxId]);
        $s = Session::get(self::KEY);
        if (is_array($s) && (int) ($s['box'] ?? 0) === $mailboxId) {
            self::close();
        }
    }

    public static function deleteUser(int $userId): void
    {
        DB::run('DELETE FROM mailbox_links WHERE user_id = :u OR mailbox_id = :u', ['u' => $userId]);
    }

    private static function checkTotp(array $box, string $code): bool
    {
        $secret = Crypto::decrypt((string) $box['totp_secret']);
        if (!$secret || $code === '') {
            return false;
        }
        $prefs = json_decode((string) DB::value('SELECT prefs FROM users WHERE id = :id', ['id' => $box['id']]), true) ?: [];
        $step = Totp::match($secret, preg_replace('/\s+/', '', $code) ?? '', 1, (int) ($prefs['_totp_step'] ?? 0));
        if ($step === null) {
            return false;
        }
        $prefs['_totp_step'] = $step; // a code is never accepted twice
        DB::update('users', ['prefs' => json_encode($prefs)], 'id = :id', ['id' => $box['id']]);
        return true;
    }

    // ------------------------------------------------------------- switching

    /** Work in one of my other mailboxes (0 = back to mine). */
    public static function open(array $actor, int $mailboxId): ?array
    {
        if ($mailboxId === 0 || $mailboxId === (int) $actor['id']) {
            self::close();
            Delegation::close($actor);
            return null;
        }
        if (!self::linked((int) $actor['id'], $mailboxId)) {
            throw new \M4W\Core\HttpException(403, t('shared.forbidden'));
        }
        Delegation::close($actor);
        Session::set(self::KEY, ['box' => $mailboxId]);
        self::$current = [];
        return Users::find($mailboxId);
    }

    public static function close(): void
    {
        Session::forget(self::KEY);
        self::$current = [];
    }

    /** The other mailbox open for this session, rights re-checked on every request. */
    public static function current(array $actor): ?array
    {
        $key = (string) $actor['id'];
        if (array_key_exists($key, self::$current)) {
            return self::$current[$key];
        }
        $s = Session::get(self::KEY);
        $box = null;
        if (is_array($s)) {
            $id = (int) ($s['box'] ?? 0);
            $box = self::linked((int) $actor['id'], $id) ? Users::find($id) : null;
            if (!$box || $box['status'] !== 'active') {
                $box = null;
                Session::forget(self::KEY);
            }
        }
        if ($box) {
            // The interface follows the person's own preferences; the mail is the mailbox's.
            $box['prefs'] = $actor['prefs'];
            $box['_shared'] = ['id' => (int) $actor['id'], 'email' => $actor['email'], 'name' => $actor['name']];
        }
        return self::$current[$key] = $box;
    }

    public static function banner(?array $actor): ?array
    {
        $box = $actor ? self::current($actor) : null;
        return $box ? ['id' => (int) $box['id'], 'name' => $box['name'], 'email' => $box['email']] : null;
    }
}
