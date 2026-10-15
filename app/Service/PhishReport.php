<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Audit;
use M4W\Core\Database as DB;
use M4W\Core\Settings;
use M4W\Mail\Address;
use M4W\Mail\LinkCheck;

/**
 * "Report phishing": one person spots a fraudulent message and the whole organisation is
 * protected. The message leaves their inbox (to Junk, as evidence), the same message is
 * removed from colleagues' mailboxes (kept in the administrators' quarantine, restorable),
 * the antispam learns it, the sender is marked, and administrators are alerted.
 */
final class PhishReport
{
    /** @return array{removed:int,internal:bool} */
    public static function report(array $user, array $m): array
    {
        $uid = (int) $user['id'];
        $parsed = Mailbox::parsed($m);
        $from = mb_strtolower((string) ($parsed->from()['email'] ?? $m['from_email'] ?? ''));
        $internal = $from !== '' && Users::resolveLocal($from) !== null;

        // 1. Out of the reporter's inbox, into Junk (which teaches the antispam).
        $spam = (int) Folders::byRole($uid, 'spam')['id'];
        if ((int) $m['folder_id'] !== $spam) {
            Mailbox::move($uid, [(int) $m['id']], $spam);
        } else {
            Bayes::feedback($uid, [$m], true);
        }

        // 2. The same message elsewhere in the organisation: same Message-ID, or same sender
        //    and subject in the last 3 days (a campaign sends one copy per person).
        $copies = [];
        $msgId = (string) ($m['message_id'] ?? '');
        if ($msgId !== '') {
            $copies = DB::all('SELECT * FROM messages WHERE message_id = :m AND user_id <> :u AND is_draft = 0', ['m' => $msgId, 'u' => $uid]);
        }
        if ($from !== '' && !$internal && trim((string) $m['subject']) !== '') {
            $copies = array_merge($copies, DB::all('SELECT * FROM messages WHERE LOWER(from_email) = :f AND subject = :s AND user_id <> :u AND is_draft = 0 AND date_received > :t',
                ['f' => $from, 's' => $m['subject'], 'u' => $uid, 't' => time() - 3 * 86400]));
        }
        $removed = 0;
        $seen = [];
        foreach ($copies as $c) {
            if (isset($seen[(int) $c['id']])) {
                continue;
            }
            $seen[(int) $c['id']] = true;
            $role = DB::value('SELECT role FROM folders WHERE id = :id', ['id' => $c['folder_id']]);
            if (in_array($role, ['sent', 'drafts'], true)) {
                continue; // what someone wrote themselves stays
            }
            try {
                if (Retention::enabled()) {
                    Mailbox::purge((int) $c['user_id'], $c, 'phishing');
                } else {
                    Mailbox::move((int) $c['user_id'], [(int) $c['id']], (int) Folders::byRole((int) $c['user_id'], 'spam')['id']);
                }
                $removed++;
            } catch (\Throwable $e) {
                error_log('[m4w] phishing copy: ' . $e->getMessage());
            }
        }

        // 3. The sender: future messages are scored as suspicious for everyone.
        //    A colleague's address means their account is probably compromised: alert, not mark.
        if ($from !== '' && !$internal) {
            $row = DB::one('SELECT reports FROM reported_senders WHERE address = :a', ['a' => $from]);
            if ($row) {
                DB::update('reported_senders', ['reports' => (int) $row['reports'] + 1, 'last_at' => time()], 'address = :a', ['a' => $from]);
            } else {
                DB::insert('reported_senders', ['address' => mb_substr($from, 0, 190), 'reports' => 1, 'first_at' => time(), 'last_at' => time()]);
            }
        }

        // 4. Administrators.
        $links = array_slice(array_unique(array_map(static fn($l) => LinkCheck::host((string) $l['href']), LinkCheck::links($parsed->html()))), 0, 8);
        $details = t('phish.alert_details', ['from' => $from ?: '?', 'subject' => mb_substr((string) $m['subject'], 0, 150), 'n' => $removed])
            . ($links ? ' — ' . t('phish.alert_links', ['links' => implode(', ', array_filter($links))]) : '');
        AccountGuard::alert($uid, $internal ? 'phishing_internal' : 'phishing', 'high', $details);
        if ($internal) {
            $sender = Users::resolveLocal($from);
            AccountGuard::alert((int) $sender['id'], 'reported_by_colleague', 'high', t('phish.reported_by', ['who' => $user['email'], 'subject' => mb_substr((string) $m['subject'], 0, 150)]));
        }
        Audit::log('mail.phishing_reported', mb_substr((string) $m['subject'], 0, 120), ['from' => $from, 'removed' => $removed], $uid);
        return ['removed' => $removed, 'internal' => $internal];
    }

    public static function reportedSenders(int $limit = 50): array
    {
        return DB::all('SELECT * FROM reported_senders ORDER BY last_at DESC LIMIT ' . max(1, $limit));
    }

    public static function forgetSender(string $address): void
    {
        DB::run('DELETE FROM reported_senders WHERE address = :a', ['a' => mb_strtolower($address)]);
    }
}
