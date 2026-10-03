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
    public static function deliver(string $raw, string $envelopeFrom, array $recipients, string $source = 'smtp', bool $applyRules = true): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
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
            if ((int) Settings::get('inbound.spam_header', 1) && $parsed->isSpamFlagged()) {
                $folder = Folders::byRole($uid, 'spam');
            }
            $folderId = (int) $folder['id'];

            // 3. User rules.
            $actions = $applyRules ? RuleEngine::evaluate($uid, $parsed) : ['folder' => null, 'copies' => [], 'read' => false, 'flag' => false, 'discard' => false, 'redirects' => [], 'replies' => [], 'matched' => []];
            if ($actions['folder']) {
                $folderId = $actions['folder'];
            }
            if (!$isLoop) {
                foreach (array_unique($actions['redirects']) as $to) {
                    try {
                        Forwarding::assertAllowed($to);
                        Forwarding::redirect($user, $raw, [$to]);
                    } catch (\InvalidArgumentException) {
                    }
                }
                foreach ($actions['replies'] as $reply) {
                    self::ruleReply($user, $parsed, $reply, $envelopeFrom);
                }
            }
            if ($actions['discard']) {
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
            if (!$isLoop && $folderId !== $spamId) {
                Vacation::maybeRespond($user, $parsed, $envelopeFrom);
            }
            $results[$rcpt] = 'ok';
            Transport::log('in', $uid, $envelopeFrom, [$rcpt], $raw, 'ok', $source);
        }
        return $results;
    }

    private static function ruleReply(array $user, \M4W\Mail\ParsedMessage $msg, array $reply, string $envelopeFrom): void
    {
        $sender = $envelopeFrom !== '' ? $envelopeFrom : $msg->from()['email'];
        if ($sender === '' || $msg->isAutomated()) {
            return;
        }
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
