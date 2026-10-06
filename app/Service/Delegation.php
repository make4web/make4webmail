<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Audit;
use M4W\Core\Database as DB;
use M4W\Core\Session;
use M4W\Core\Settings;
use M4W\Mail\Address;
use M4W\Mail\MimeBuilder;

/**
 * Mailbox delegation. In a business context mailboxes belong to the organisation:
 * administrators may open any mailbox (absence, emergency) and an administrator can
 * also name delegates for a mailbox (assistant, colleague covering an absence).
 *
 * The person acting stays signed in as themselves; only the mail screens switch to
 * the delegated mailbox. Every opening is journaled with its reason, shown to the
 * owner and (optionally) notified to them. Messages and folders marked personal
 * stay hidden from delegates.
 */
final class Delegation
{
    private const KEY = 'mbx';

    /** @var array<string, array|null> */
    private static array $current = [];

    public static function adminsByDefault(): bool
    {
        return (int) Settings::get('delegation.admins', 1) === 1;
    }

    /** How the actor may open this mailbox: 'admin', 'delegate' or null. */
    public static function via(array $actor, int $ownerId): ?string
    {
        if ($ownerId <= 0 || $ownerId === (int) $actor['id']) {
            return null;
        }
        if (DB::value('SELECT 1 FROM mailbox_delegates WHERE owner_id = :o AND delegate_id = :d', ['o' => $ownerId, 'd' => (int) $actor['id']])) {
            return 'delegate';
        }
        if (($actor['role'] ?? '') === 'admin' && self::adminsByDefault()) {
            return 'admin';
        }
        return null;
    }

    public static function reasonRequired(array $actor, int $ownerId): bool
    {
        return self::via($actor, $ownerId) === 'admin' && (int) Settings::get('delegation.require_reason', 1) === 1;
    }

    /** Mailboxes explicitly delegated to the user (administrators reach the others from the console). */
    public static function delegatedTo(int $userId): array
    {
        return DB::all(
            "SELECT u.id, u.email, u.display_name, u.first_name, u.last_name, u.status FROM mailbox_delegates d
             JOIN users u ON u.id = d.owner_id WHERE d.delegate_id = :u ORDER BY u.display_name, u.email",
            ['u' => $userId]
        );
    }

    public static function delegates(int $ownerId): array
    {
        return DB::all(
            'SELECT u.id, u.email, u.display_name, u.role, d.created_at FROM mailbox_delegates d
             JOIN users u ON u.id = d.delegate_id WHERE d.owner_id = :o ORDER BY u.display_name, u.email',
            ['o' => $ownerId]
        );
    }

    /** Replace the delegates of a mailbox (administration). */
    public static function setDelegates(int $ownerId, array $userIds, int $by): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn($i) => $i > 0 && $i !== $ownerId)));
        $ids = array_values(array_filter($ids, static fn($i) => (bool) DB::value('SELECT 1 FROM users WHERE id = :i', ['i' => $i])));
        $before = array_map('intval', array_column(DB::all('SELECT delegate_id FROM mailbox_delegates WHERE owner_id = :o', ['o' => $ownerId]), 'delegate_id'));
        sort($before);
        $sorted = $ids;
        sort($sorted);
        if ($before === $sorted) {
            return;
        }
        DB::transaction(function () use ($ownerId, $ids, $by) {
            DB::delete('mailbox_delegates', 'owner_id = :o', ['o' => $ownerId]);
            foreach ($ids as $id) {
                DB::insert('mailbox_delegates', ['owner_id' => $ownerId, 'delegate_id' => $id, 'created_by' => $by, 'created_at' => time()]);
            }
        });
        $owner = DB::one('SELECT email FROM users WHERE id = :i', ['i' => $ownerId]);
        Audit::log('delegation.delegates_changed', (string) ($owner['email'] ?? $ownerId), ['delegates' => $ids], $by);
    }

    /** Start acting on a mailbox. */
    public static function open(array $actor, int $ownerId, string $reason, string $ip = ''): array
    {
        $via = self::via($actor, $ownerId);
        $owner = Users::find($ownerId);
        if (!$via || !$owner) {
            throw new \M4W\Core\HttpException(403, t('deleg.forbidden'));
        }
        $reason = mb_substr(trim(preg_replace('/\s+/u', ' ', $reason) ?? ''), 0, 500);
        if ($reason === '' && self::reasonRequired($actor, $ownerId)) {
            throw new \InvalidArgumentException(t('deleg.reason_required'));
        }
        self::close($actor);
        $now = time();
        $logId = DB::insert('delegation_log', [
            'owner_id' => $ownerId, 'actor_id' => (int) $actor['id'], 'actor_name' => mb_substr($actor['name'] ?: $actor['email'], 0, 190),
            'via' => $via, 'reason' => $reason, 'ip' => mb_substr($ip, 0, 64), 'opened_at' => $now, 'last_seen_at' => $now,
        ]);
        Session::set(self::KEY, ['owner' => $ownerId, 'log' => $logId, 'since' => $now]);
        self::$current = [];
        Audit::log('delegation.opened', $owner['email'], ['via' => $via, 'reason' => $reason], (int) $actor['id']);
        if ((int) Settings::get('delegation.notify_owner', 1) === 1) {
            self::notifyOwner($owner, $actor, $reason, $now);
        }
        return $owner;
    }

    public static function close(array $actor): void
    {
        $s = Session::get(self::KEY);
        if (is_array($s)) {
            DB::update('delegation_log', ['closed_at' => time()], 'id = :id AND actor_id = :a', ['id' => (int) ($s['log'] ?? 0), 'a' => (int) $actor['id']]);
            $owner = DB::one('SELECT email FROM users WHERE id = :i', ['i' => (int) ($s['owner'] ?? 0)]);
            Audit::log('delegation.closed', (string) ($owner['email'] ?? ''), [], (int) $actor['id']);
        }
        Session::forget(self::KEY);
        self::$current = [];
        \M4W\Service\Mailbox::$privacy = false;
    }

    /**
     * Mailbox currently opened by the actor, as a user array whose "_actor" key names
     * the person acting. Rights are re-checked on every request.
     */
    public static function current(array $actor): ?array
    {
        $key = (string) $actor['id'];
        if (array_key_exists($key, self::$current)) {
            return self::$current[$key];
        }
        $s = Session::get(self::KEY);
        $owner = null;
        if (is_array($s) && self::via($actor, (int) ($s['owner'] ?? 0))) {
            $owner = Users::find((int) $s['owner']);
        } elseif (is_array($s)) {
            Session::forget(self::KEY); // rights withdrawn since the mailbox was opened
        }
        if ($owner) {
            // The interface follows the actor's own preferences; the data are the owner's.
            $owner['prefs'] = $actor['prefs'];
            $owner['_actor'] = ['id' => (int) $actor['id'], 'email' => $actor['email'], 'name' => $actor['name'], 'role' => $actor['role']];
            $owner['_delegation'] = ['log' => (int) ($s['log'] ?? 0), 'since' => (int) ($s['since'] ?? 0)];
            $log = (int) ($s['log'] ?? 0);
            if ($log) {
                DB::run('UPDATE delegation_log SET last_seen_at = :t WHERE id = :id AND last_seen_at < :old', ['t' => time(), 'id' => $log, 'old' => time() - 60]);
            }
        }
        return self::$current[$key] = $owner;
    }

    /**
     * Mailbox to work on for this request: the delegated one (personal items then hidden)
     * or the actor's own.
     */
    public static function mailbox(array $actor): array
    {
        $owner = self::current($actor);
        if (!$owner) {
            return $actor;
        }
        Mailbox::$privacy = (int) Settings::get('delegation.hide_personal', 1) === 1;
        return $owner;
    }

    /** Lightweight view of the delegation state for layouts. */
    public static function banner(?array $actor): ?array
    {
        if (!$actor) {
            return null;
        }
        $owner = self::current($actor);
        return $owner ? ['id' => (int) $owner['id'], 'name' => $owner['name'], 'email' => $owner['email']] : null;
    }

    public static function countSent(array $owner): void
    {
        if (!empty($owner['_delegation']['log'])) {
            DB::run('UPDATE delegation_log SET sent = sent + 1 WHERE id = :id', ['id' => (int) $owner['_delegation']['log']]);
        }
    }

    /** Access journal of a mailbox, newest first. */
    public static function journal(int $ownerId, int $limit = 20): array
    {
        return DB::all('SELECT * FROM delegation_log WHERE owner_id = :o ORDER BY opened_at DESC LIMIT ' . max(1, min(200, $limit)), ['o' => $ownerId]);
    }

    public static function deleteUser(int $userId): void
    {
        DB::delete('mailbox_delegates', 'owner_id = :u OR delegate_id = :u', ['u' => $userId]);
    }

    /** Transparency: a message in the owner's inbox (stored directly, no rules nor auto-replies). */
    private static function notifyOwner(array $owner, array $actor, string $reason, int $at): void
    {
        try {
            $b = new MimeBuilder();
            $b->from = ['email' => $actor['email'], 'name' => $actor['name']];
            $b->to = [['email' => $owner['email'], 'name' => $owner['name']]];
            $b->subject = t('deleg.notify_subject', ['name' => $actor['name'] ?: $actor['email']]);
            $b->html = '<p>' . e(t('deleg.notify_body', ['name' => $actor['name'] ?: $actor['email'], 'date' => format_datetime($at)])) . '</p>'
                . ($reason !== '' ? '<p><strong>' . e(t('deleg.reason')) . ' :</strong> ' . e($reason) . '</p>' : '')
                . '<p style="color:#64748b">' . e(t('deleg.notify_footer')) . '</p>';
            $b->messageId = MimeBuilder::newMessageId(Address::domain($owner['email']) ?: 'localhost');
            $b->extraHeaders['Auto-Submitted'] = 'auto-generated';
            $b->extraHeaders['X-M4W-Delegation'] = 'opened';
            $inbox = Folders::byRole((int) $owner['id'], 'inbox');
            Mailbox::store((int) $owner['id'], (int) $inbox['id'], $b->build());
        } catch (\Throwable $e) {
            error_log('[m4w] delegation notice: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------- privacy

    /** Folder names that mark a personal folder (French and English). */
    public static function isPersonalName(string $name): bool
    {
        return (bool) preg_match('/^\s*[\[\(]?\s*(perso|personnel|personnelle|personal|private|priv[ée]|confidentiel[\s-]+perso)/iu', $name);
    }

    /** Subjects explicitly tagged personal, e.g. "[PERSO] …", "Privé : …", "Re: [Personnel] …". */
    public static function isPersonalSubject(string $subject): bool
    {
        $s = preg_replace('/^\s*((re|tr|fw|fwd|réf)\s*:\s*)+/iu', '', $subject) ?? $subject;
        return (bool) preg_match('/^\s*[\[\(]\s*(perso|personnel|personnelle|personal|private|priv[ée])\s*[\]\)]|^\s*(perso|personnel|personal|private|priv[ée])\s*:/iu', $s);
    }
}
