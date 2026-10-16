<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Database as DB;
use M4W\Core\Settings;
use M4W\Mail\Address;
use M4W\Mail\MimeBuilder;
use M4W\Mail\MimeParser;

/**
 * Local delivery agent: forwarding, rules, spam, storage, auto-replies.
 */
final class Delivery
{
    /**
     * @param string[] $recipients envelope recipients
     * @return array<string,string> recipient => 'ok' | error
     */
    public static function deliver(string $raw, string $envelopeFrom, array $recipients, string $source = 'smtp', bool $applyRules = true, ?array $auth = null): array
    {
        // Only the synchronisation writes where a message was collected; nobody else may claim it.
        if ($source !== 'sync') {
            $raw = self::stripHeader(str_replace(["\r\n", "\r"], "\n", $raw), 'X-M4W-Fetched');
        }
        $raw = self::sanitizeIncoming($raw);
        if ($auth !== null) {
            $raw = \M4W\Mail\MailAuth::headers($auth, (string) Settings::get('smtp.helo', '') ?: (gethostname() ?: 'localhost')) . $raw;
        }
        // Unauthenticated mail must not trigger forwarding of spoofed content to auto-replies, nor rule actions keyed on the sender.
        $suspicious = $auth !== null && $auth['verdict'] !== 'ok';
        $parsed = MimeParser::parse($raw);
        // Disposable senders (bots, probes, spam): refused by default, never answered.
        $disposable = $source !== 'local' && Senders::inboundAction() !== 'off' ? Senders::disposableSender($parsed, $envelopeFrom) : '';
        if ($disposable !== '' && Senders::inboundAction() === 'reject') {
            $results = [];
            foreach ($recipients as $rcpt) {
                $user = Users::resolveLocal($rcpt);
                $results[$rcpt] = $user ? 'rejected: disposable sender' : 'unknown recipient';
                if ($user) {
                    Transport::log('in', (int) $user['id'], $envelopeFrom, [$rcpt], $raw, 'rejected', 'disposable ' . $disposable);
                }
            }
            return $results;
        }
        // DMARC aggregate reports about our domains feed the supervision page.
        if ($source !== 'local' && DmarcReports::looksLike($parsed)) {
            try {
                DmarcReports::ingest($parsed);
            } catch (\Throwable $e) {
                error_log('[m4w] dmarc: ' . $e->getMessage());
            }
        }
        $loopHeaders = array_map('mb_strtolower', $parsed->headerAll('X-M4W-Loop'));
        $spamBase = $source !== 'local' && SpamFilter::enabled() ? SpamFilter::evaluate($parsed, $auth, $envelopeFrom) : null;
        $results = [];
        $done = [];
        // Sender's logo (BIMI): looked up once per message, shown only if DMARC vouches for it.
        $bimi = $disposable === '' && !$suspicious ? Bimi::forMessage($parsed, $raw, $auth, $source) : '';

        // Several people of this server: the message is written once, each keeps its own header lines.
        $people = [];
        foreach ($recipients as $r) {
            if ($u = Users::resolveLocal($r)) {
                $people[(int) $u['id']] = true;
            }
        }
        $shared = count($people) > 1;
        $sharedFile = null;

        foreach ($recipients as $rcpt) {
            $user = Users::resolveLocal($rcpt);
            if (!$user) {
                $results[$rcpt] = 'unknown recipient';
                continue;
            }
            $uid = (int) $user['id'];
            if (isset($done[$uid])) {
                $results[$rcpt] = 'ok';
                continue;
            }
            $done[$uid] = true;
            if (Users::overQuota($user, strlen($raw))) {
                $results[$rcpt] = 'mailbox full';
                Transport::log('in', $uid, $envelopeFrom, [$rcpt], $raw, 'rejected', 'quota');
                continue;
            }
            $prefix = 'Delivered-To: ' . $user['email'] . "\n";
            $userRaw = $prefix . $raw;
            $isLoop = in_array(mb_strtolower($user['email']), $loopHeaders, true);

            // Spam filter: inbox, Junk (suspect), administrators' quarantine, or deleted.
            $spam = $spamBase ? SpamFilter::forUser($spamBase, $uid, $parsed, $auth) : null;
            $level = $spam['level'] ?? (((int) Settings::get('inbound.spam_header', 1) && $parsed->isSpamFlagged()) ? 'suspect' : 'ok');
            // Failed authentication, or a disposable sender in "spam" mode, is at least suspect.
            if (($suspicious || $disposable !== '') && $level === 'ok') {
                $level = 'suspect';
            }
            if ($spam) {
                $spam['level'] = $level;
                $prefix = SpamFilter::header($spam) . $prefix;
                $userRaw = SpamFilter::header($spam) . $userRaw;
            }
            if ($level === 'delete' || $level === 'quarantine') {
                Retention::keepIncoming($user, $userRaw, $level === 'delete' ? 'spam' : 'quarantine', $level === 'quarantine');
                $results[$rcpt] = 'ok';
                Transport::log('in', $uid, $envelopeFrom, [$rcpt], $raw, 'ok', ($level === 'delete' ? 'spam deleted' : 'quarantined') . ', score ' . ($spam['score'] ?? '?'));
                continue;
            }

            // 1. Account-level forwarding.
            $fwd = Forwarding::get($uid);
            // Junk is not forwarded: it would hurt the reputation of our server.
            if ($fwd['enabled'] && $fwd['addresses'] && !$isLoop && $level === 'ok') {
                Forwarding::redirect($user, $raw, $fwd['addresses']);
                if (!$fwd['keep_copy']) {
                    $results[$rcpt] = 'ok';
                    Transport::log('in', $uid, $envelopeFrom, [$rcpt], $raw, 'ok', 'forwarded');
                    continue;
                }
            }

            // 2. Default folder (spam detection by upstream filter headers).
            $folder = Folders::byRole($uid, $level === 'suspect' ? 'spam' : 'inbox');
            $folderId = (int) $folder['id'];

            // 3. User rules.
            $actions = $applyRules ? RuleEngine::evaluate($uid, $parsed) : ['folder' => null, 'copies' => [], 'read' => false, 'flag' => false, 'discard' => false, 'redirects' => [], 'diverts' => [], 'replies' => [], 'matched' => []];
            if ($actions['folder'] && Folders::find($uid, (int) $actions['folder']) && $level === 'ok') {
                $folderId = (int) $actions['folder'];
            }
            if ($suspicious || $level !== 'ok') {
                $actions['redirects'] = [];
                $actions['diverts'] = []; // kept here (spam folder) rather than passed on
                $actions['replies'] = [];
            }
            $actions['copies'] = array_values(array_filter($actions['copies'], static fn($c) => (bool) Folders::find($uid, (int) $c)));
            $diverted = [];
            if (!$isLoop) {
                // Redirected: sent on as it came, not kept in this mailbox. Should no address be
                // allowed (any more), the message is kept: nothing is lost.
                foreach (array_unique($actions['diverts']) as $to) {
                    try {
                        Forwarding::assertAllowed($to);
                        Forwarding::redirect($user, $raw, [$to]);
                        $diverted[] = $to;
                    } catch (\InvalidArgumentException) {
                    }
                }
                foreach (array_diff(array_unique($actions['redirects']), $diverted) as $to) {
                    try {
                        Forwarding::assertAllowed($to);
                        Forwarding::redirect($user, $raw, [$to]);
                    } catch (\InvalidArgumentException) {
                    }
                }
                if ($envelopeFrom !== '' || $source === 'local') {
                    foreach ($actions['replies'] as $reply) {
                        self::ruleReply($user, $parsed, $reply, $envelopeFrom);
                    }
                }
            }
            if ($diverted && !$actions['discard']) {
                // Redirected, but also filed by a rule ("Copy to", "Move to"): kept in those folders
                // only, never in the inbox.
                $moved = $actions['folder'] && $folderId === (int) $actions['folder'] ? $folderId : 0;
                $filed = array_values(array_unique(array_filter(array_merge([$moved], $actions['copies']))));
                if (!$filed) {
                    Retention::keepIncoming($user, $userRaw);
                    $results[$rcpt] = 'ok';
                    Transport::log('in', $uid, $envelopeFrom, [$rcpt], $raw, 'ok', 'redirected by rule to ' . implode(', ', $diverted));
                    continue;
                }
                $folderId = (int) array_shift($filed);
                $actions['copies'] = $filed;
            }
            if ($actions['discard']) {
                Retention::keepIncoming($user, $userRaw);
                $results[$rcpt] = 'ok';
                Transport::log('in', $uid, $envelopeFrom, [$rcpt], $raw, 'ok', 'discarded by rule');
                continue;
            }

            // 4. Store.
            if ($shared) {
                $sharedFile ??= Mailbox::writeShared($raw);
                $newId = Mailbox::store($uid, $folderId, $raw, ['read' => $actions['read'], 'flagged' => $actions['flag'], 'file' => $sharedFile, 'prefix' => $prefix], $parsed);
            } else {
                $newId = Mailbox::store($uid, $folderId, $userRaw, ['read' => $actions['read'], 'flagged' => $actions['flag']], $parsed);
            }
            $stored = DB::one('SELECT storage_path, raw_prefix FROM messages WHERE id = :id', ['id' => $newId]);
            if ($bimi !== '' && $level === 'ok') {
                DB::update('messages', ['bimi' => $bimi], 'id = :id', ['id' => $newId]);
            }
            // Phone / desktop notification: unread mail landing in the inbox, or filed by a rule in one
            // of the person's own folders (not spam, trash…: nothing to wait for there).
            $landed = Folders::find($uid, $folderId);
            if (!$actions['read'] && $landed && (($landed['role'] ?? null) === 'inbox' || empty($landed['role']))) {
                try {
                    WebPush::newMail($user, $newId, $parsed->from(), $parsed->subject(), $folderId);
                } catch (\Throwable $e) {
                    error_log('[m4w] push: ' . $e->getMessage());
                }
            }
            // Delivered here without passing through the provider (a colleague of the server, our MX):
            // the person's mailbox at the provider gets it too, so their other mail apps see it.
            if ($source !== 'sync' && $folderId === (int) Folders::byRole($uid, 'inbox')['id']) {
                RemoteMailbox::queueCopy($uid, (int) $newId);
            }
            $copyIds = [];
            foreach (array_unique($actions['copies']) as $copy) {
                if ($copy !== $folderId) {
                    // Filed in a second folder by a rule: same file, no second copy on disk.
                    $copyIds[] = Mailbox::store($uid, $copy, $shared ? $raw : $userRaw, ['read' => $actions['read'], 'flagged' => $actions['flag'],
                        'file' => (string) $stored['storage_path'], 'prefix' => (string) $stored['raw_prefix']], $parsed);
                }
            }
            // Remote pictures are fetched by the server soon after delivery, never at reading time.
            // Not for spam: fetching them would confirm to a spammer that the address is alive.
            if ($folderId !== (int) Folders::byRole($uid, 'spam')['id']) {
                try {
                    foreach (array_merge([$newId], $copyIds) as $mid) {
                        MailAssets::register((int) $mid, $uid, $parsed->html());
                    }
                } catch (\Throwable $e) {
                    error_log('[m4w] mail assets: ' . $e->getMessage());
                }
            }
            Contacts::touchSender($uid, $parsed->from());
            // Answers to our invitations, updates and cancellations of events already in the calendar.
            if ($level === 'ok' && !$suspicious && $source !== 'local') {
                try {
                    Scheduling::incoming($user, $parsed);
                } catch (\Throwable $e) {
                    error_log('[m4w] itip: ' . $e->getMessage());
                }
            }

            // 5. Out-of-office.
            $spamId = (int) Folders::byRole($uid, 'spam')['id'];
            if (!$isLoop && $folderId !== $spamId && ($envelopeFrom !== '' || $source === 'local')) {
                Vacation::maybeRespond($user, $parsed, $envelopeFrom);
            }
            $results[$rcpt] = 'ok';
            Transport::log('in', $uid, $envelopeFrom, [$rcpt], $raw, 'ok', $source . ($level === 'suspect' ? ', junk' . ($spam ? ', score ' . $spam['score'] : '') : ''));
        }
        return $results;
    }

    /** Remove every occurrence of a header (with continuation lines) from a LF-normalized message. */
    /**
     * Every received message, whatever the path (SMTP, pipe, provider sync, provider spam):
     * our own trust markers sent by outsiders are dropped, then the antivirus pass runs
     * (infected attachments replaced by a notice before anything else sees them).
     */
    public static function sanitizeIncoming(string $raw): string
    {
        $raw = self::stripHeader(str_replace(["\r\n", "\r"], "\n", $raw), 'X-M4W-Auth');
        $raw = self::stripHeader($raw, SpamFilter::HEADER);
        return AttachmentGuard::process($raw)['raw'];
    }

    public static function stripHeader(string $raw, string $name): string
    {
        $pos = strpos($raw, "\n\n");
        $head = $pos === false ? $raw : substr($raw, 0, $pos);
        if (stripos($head, $name . ':') === false) {
            return $raw;
        }
        $head = preg_replace('/^' . preg_quote($name, '/') . ':.*(?:\n[ \t].*)*\n?/mi', '', $head) ?? $head;
        return $pos === false ? $head : $head . substr($raw, $pos);
    }

    private static function ruleReply(array $user, \M4W\Mail\ParsedMessage $msg, array $reply, string $envelopeFrom): void
    {
        $sender = $envelopeFrom !== '' ? $envelopeFrom : $msg->from()['email'];
        if (Senders::noAutoReply($sender) || $msg->isAutomated()) {
            return;
        }
        // One rule reply per sender per day (backscatter protection).
        $key = 'rule:' . mb_strtolower($sender);
        if (\M4W\Core\Database::value('SELECT COUNT(*) FROM vacation_log WHERE user_id = :u AND sender = :s AND sent_at > :t',
            ['u' => $user['id'], 's' => mb_substr($key, 0, 190), 't' => time() - 86400])) {
            return;
        }
        \M4W\Core\Database::insert('vacation_log', ['user_id' => $user['id'], 'sender' => mb_substr($key, 0, 190), 'sent_at' => time()]);
        $b = new MimeBuilder();
        $b->from = ['email' => $user['email'], 'name' => $user['name']];
        $b->to = [['email' => $sender, 'name' => '']];
        $b->subject = $reply['subject'] !== '' ? $reply['subject'] : 'Re: ' . $msg->subject();
        $b->html = nl2br(e($reply['body']));
        $b->messageId = MimeBuilder::newMessageId(Address::domain($user['email']));
        if ($msg->messageId() !== '') {
            $b->inReplyTo = $msg->messageId();
            $b->references = [$msg->messageId()];
        }
        $b->extraHeaders['Auto-Submitted'] = 'auto-replied';
        $b->extraHeaders['X-M4W-Loop'] = $user['email'];
        Transport::enqueue($b->build(), '', [$sender], (int) $user['id'], 'autoreply');
    }
}
