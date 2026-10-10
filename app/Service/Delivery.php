<?php
declare(strict_types=1);

namespace M4W\Service;

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
        $raw = self::stripHeader(str_replace(["\r\n", "\r"], "\n", $raw), 'X-M4W-Auth');
        if ($auth !== null) {
            $raw = \M4W\Mail\MailAuth::headers($auth, (string) Settings::get('smtp.helo', '') ?: (gethostname() ?: 'localhost')) . $raw;
        }
        // Unauthenticated mail must not trigger forwarding of spoofed content to auto-replies, nor rule actions keyed on the sender.
        $suspicious = $auth !== null && $auth['verdict'] !== 'ok';
        $parsed = MimeParser::parse($raw);
        $loopHeaders = array_map('mb_strtolower', $parsed->headerAll('X-M4W-Loop'));
        $results = [];
        $done = [];

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
            $userRaw = 'Delivered-To: ' . $user['email'] . "\n" . $raw;
            $isLoop = in_array(mb_strtolower($user['email']), $loopHeaders, true);

            // 1. Account-level forwarding.
            $fwd = Forwarding::get($uid);
            if ($fwd['enabled'] && $fwd['addresses'] && !$isLoop) {
                Forwarding::redirect($user, $raw, $fwd['addresses']);
                if (!$fwd['keep_copy']) {
                    $results[$rcpt] = 'ok';
                    Transport::log('in', $uid, $envelopeFrom, [$rcpt], $raw, 'ok', 'forwarded');
                    continue;
                }
            }

            // 2. Default folder (spam detection by upstream filter headers).
            $folder = Folders::byRole($uid, 'inbox');
            if (((int) Settings::get('inbound.spam_header', 1) && $parsed->isSpamFlagged()) || $suspicious) {
                $folder = Folders::byRole($uid, 'spam');
            }
            $folderId = (int) $folder['id'];

            // 3. User rules.
            $actions = $applyRules ? RuleEngine::evaluate($uid, $parsed) : ['folder' => null, 'copies' => [], 'read' => false, 'flag' => false, 'discard' => false, 'redirects' => [], 'replies' => [], 'matched' => []];
            if ($actions['folder'] && Folders::find($uid, (int) $actions['folder']) && !$suspicious) {
                $folderId = (int) $actions['folder'];
            }
            if ($suspicious) {
                $actions['redirects'] = [];
                $actions['replies'] = [];
            }
            $actions['copies'] = array_values(array_filter($actions['copies'], static fn($c) => (bool) Folders::find($uid, (int) $c)));
            if (!$isLoop) {
                foreach (array_unique($actions['redirects']) as $to) {
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
            if ($actions['discard']) {
                Retention::keepIncoming($user, $userRaw);
                $results[$rcpt] = 'ok';
                Transport::log('in', $uid, $envelopeFrom, [$rcpt], $raw, 'ok', 'discarded by rule');
                continue;
            }

            // 4. Store.
            Mailbox::store($uid, $folderId, $userRaw, ['read' => $actions['read'], 'flagged' => $actions['flag']], $parsed);
            foreach (array_unique($actions['copies']) as $copy) {
                if ($copy !== $folderId) {
                    Mailbox::store($uid, $copy, $userRaw, ['read' => $actions['read'], 'flagged' => $actions['flag']], $parsed);
                }
            }
            Contacts::touchSender($uid, $parsed->from());

            // 5. Out-of-office.
            $spamId = (int) Folders::byRole($uid, 'spam')['id'];
            if (!$isLoop && $folderId !== $spamId && ($envelopeFrom !== '' || $source === 'local')) {
                Vacation::maybeRespond($user, $parsed, $envelopeFrom);
            }
            $results[$rcpt] = 'ok';
            Transport::log('in', $uid, $envelopeFrom, [$rcpt], $raw, 'ok', $source);
        }
        return $results;
    }

    /** Remove every occurrence of a header (with continuation lines) from a LF-normalized message. */
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
        if ($sender === '' || !is_valid_email($sender) || $msg->isAutomated()) {
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
