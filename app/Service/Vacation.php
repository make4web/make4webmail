<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Database as DB;
use M4W\Mail\Address;
use M4W\Mail\HtmlSanitizer;
use M4W\Mail\MimeBuilder;
use M4W\Mail\ParsedMessage;

/**
 * Out-of-office auto-responder (RFC 3834 compliant).
 */
final class Vacation
{
    public static function get(int $userId): array
    {
        $row = DB::one('SELECT * FROM vacations WHERE user_id = :u', ['u' => $userId]);
        return [
            'enabled'       => (bool) ($row['enabled'] ?? false),
            'start_at'      => (int) ($row['start_at'] ?? 0),
            'end_at'        => (int) ($row['end_at'] ?? 0),
            'subject'       => (string) ($row['subject'] ?? ''),
            'body_html'     => (string) ($row['body_html'] ?? ''),
            'interval_days' => (int) ($row['interval_days'] ?? 4),
            'only_contacts' => (bool) ($row['only_contacts'] ?? false),
            'internal_only' => (bool) ($row['internal_only'] ?? false),
        ];
    }

    public static function save(int $userId, array $d): void
    {
        $data = [
            'enabled'       => !empty($d['enabled']) ? 1 : 0,
            'start_at'      => max(0, (int) ($d['start_at'] ?? 0)),
            'end_at'        => max(0, (int) ($d['end_at'] ?? 0)),
            'subject'       => mb_substr(trim((string) ($d['subject'] ?? '')), 0, 255),
            'body_html'     => (new HtmlSanitizer(false))->sanitize((string) ($d['body_html'] ?? '')),
            'interval_days' => max(0, min(30, (int) ($d['interval_days'] ?? 4))),
            'only_contacts' => !empty($d['only_contacts']) ? 1 : 0,
            'internal_only' => !empty($d['internal_only']) ? 1 : 0,
            'updated_at'    => time(),
        ];
        if ($data['end_at'] && $data['start_at'] && $data['end_at'] < $data['start_at']) {
            throw new \InvalidArgumentException(t('vac.bad_dates'));
        }
        if ($data['enabled'] && trim(strip_tags($data['body_html'])) === '') {
            throw new \InvalidArgumentException(t('vac.need_body'));
        }
        if (DB::value('SELECT COUNT(*) FROM vacations WHERE user_id = :u', ['u' => $userId])) {
            DB::update('vacations', $data, 'user_id = :u', ['u' => $userId]);
        } else {
            DB::insert('vacations', $data + ['user_id' => $userId]);
        }
        if (!$data['enabled']) {
            DB::delete('vacation_log', 'user_id = :u', ['u' => $userId]);
        }
    }

    public static function isActive(array $v, ?int $now = null): bool
    {
        $now ??= time();
        return $v['enabled'] && ($v['start_at'] === 0 || $now >= $v['start_at']) && ($v['end_at'] === 0 || $now <= $v['end_at']);
    }

    /** Called by Delivery for each message stored in a user's mailbox. */
    public static function maybeRespond(array $user, ParsedMessage $msg, string $envelopeFrom): bool
    {
        $v = self::get((int) $user['id']);
        if (!self::isActive($v)) {
            return false;
        }
        $sender = mb_strtolower($envelopeFrom !== '' ? $envelopeFrom : $msg->from()['email']);
        if ($sender === '' || !is_valid_email($sender) || $sender === mb_strtolower($user['email']) || $msg->isAutomated() || $msg->isSpamFlagged()) {
            return false;
        }
        // RFC 3834: only respond if the user is an explicit recipient.
        $explicit = false;
        $aliases = array_column(DB::all('SELECT address FROM aliases WHERE user_id = :u', ['u' => $user['id']]), 'address');
        $mine = array_merge([mb_strtolower($user['email'])], $aliases);
        foreach (array_merge($msg->to(), $msg->cc()) as $a) {
            if (in_array(mb_strtolower($a['email']), $mine, true)) {
                $explicit = true;
                break;
            }
        }
        if (!$explicit) {
            return false;
        }
        if ($v['internal_only'] && !Users::isLocalDomain(Address::domain($sender))) {
            return false;
        }
        if ($v['only_contacts'] && !DB::value('SELECT COUNT(*) FROM contacts WHERE user_id = :u AND LOWER(email) = :e', ['u' => $user['id'], 'e' => $sender])) {
            return false;
        }
        $since = time() - max(1, $v['interval_days']) * 86400;
        if ($v['interval_days'] > 0 && DB::value(
            'SELECT COUNT(*) FROM vacation_log WHERE user_id = :u AND sender = :s AND sent_at > :t',
            ['u' => $user['id'], 's' => $sender, 't' => $since]
        )) {
            return false;
        }

        $b = new MimeBuilder();
        $b->from = ['email' => $user['email'], 'name' => $user['name']];
        $b->to = [['email' => $sender, 'name' => '']];
        $subject = $v['subject'] !== '' ? $v['subject'] : t('vac.default_subject');
        $b->subject = str_replace('{subject}', $msg->subject(), $subject);
        if (!str_contains($subject, '{subject}') && $msg->subject() !== '') {
            $b->subject .= ' — Re: ' . $msg->subject();
        }
        $b->html = $v['body_html'];
        $b->messageId = MimeBuilder::newMessageId(Address::domain($user['email']));
        if ($msg->messageId() !== '') {
            $b->inReplyTo = $msg->messageId();
            $b->references = array_merge($msg->references(), [$msg->messageId()]);
        }
        $b->extraHeaders['Auto-Submitted'] = 'auto-replied';
        $b->extraHeaders['X-Auto-Response-Suppress'] = 'All';
        $b->extraHeaders['Precedence'] = 'auto_reply';
        $b->extraHeaders['X-M4W-Loop'] = $user['email'];
        // Null envelope sender avoids bounce loops.
        Transport::enqueue($b->build(), '', [$sender], (int) $user['id'], 'vacation');
        DB::insert('vacation_log', ['user_id' => $user['id'], 'sender' => $sender, 'sent_at' => time()]);
        return true;
    }
}
