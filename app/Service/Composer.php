<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Audit;
use M4W\Core\Database as DB;
use M4W\Core\Settings;
use M4W\Mail\Address;
use M4W\Mail\HtmlSanitizer;
use M4W\Mail\MimeBuilder;

/**
 * Builds and sends messages written in the webmail.
 */
final class Composer
{
    /** Identities the user may send as (main address + aliases). */
    public static function identities(array $user): array
    {
        $ids = [['email' => $user['email'], 'name' => $user['name']]];
        foreach (DB::all('SELECT address FROM aliases WHERE user_id = :u ORDER BY address', ['u' => $user['id']]) as $a) {
            $ids[] = ['email' => $a['address'], 'name' => $user['name']];
        }
        return $ids;
    }

    /**
     * @return array{builder:MimeBuilder,recipients:string[]}
     */
    public static function build(array $user, array $in, bool $forSend): array
    {
        $from = mb_strtolower(trim((string) ($in['from'] ?? $user['email'])));
        $allowed = array_map(static fn($i) => mb_strtolower($i['email']), self::identities($user));
        if (!in_array($from, $allowed, true)) {
            $from = $user['email'];
        }
        $b = new MimeBuilder();
        $b->from = ['email' => $from, 'name' => $user['name']];
        if (!empty($user['_actor'])) {
            // Sent from a delegated mailbox: RFC 5322 "Sender" names who actually sent it.
            $b->extraHeaders['Sender'] = Address::encode($user['_actor']['email'], $user['_actor']['name']);
        }
        $b->to = Address::parseInput($in['to'] ?? '');
        $b->cc = Address::parseInput($in['cc'] ?? '');
        $b->bcc = Address::parseInput($in['bcc'] ?? '');
        $b->subject = mb_substr(trim((string) ($in['subject'] ?? '')), 0, 500);
        $b->priority = (int) ($in['priority'] ?? 3) === 1 ? 1 : ((int) ($in['priority'] ?? 3) === 5 ? 5 : 3);
        $b->readReceipt = !empty($in['receipt']);
        $b->messageId = MimeBuilder::newMessageId(Address::domain($from));

        if ($forSend) {
            foreach (array_merge($b->to, $b->cc, $b->bcc) as $a) {
                if (!is_valid_email(Address::asciiEmail($a['email']))) {
                    throw new \InvalidArgumentException(t('compose.invalid_recipient', ['email' => $a['email']]));
                }
            }
            if (!$b->to && !$b->cc && !$b->bcc) {
                throw new \InvalidArgumentException(t('compose.no_recipient'));
            }
            if (count($b->envelopeRecipients()) > 500) {
                throw new \InvalidArgumentException(t('compose.too_many'));
            }
        }

        // Threading.
        $mode = (string) ($in['mode'] ?? 'new');
        $refId = (int) ($in['ref_id'] ?? 0);
        $ref = $refId ? Mailbox::get((int) $user['id'], $refId) : null;
        if ($ref && in_array($mode, ['reply', 'reply_all'], true) && $ref['message_id'] !== '') {
            $b->inReplyTo = $ref['message_id'];
            $parsedRef = Mailbox::parsed($ref);
            $b->references = array_merge($parsedRef->references(), [$ref['message_id']]);
        }

        // Body.
        $html = (string) ($in['html'] ?? '');
        $html = (new HtmlSanitizer(false, null, true))->sanitize($html);
        $html = self::extractDataImages($html, $b);
        $html = self::restoreImages($html, $b, (int) $user['id']);
        if ($forSend) {
            $sig = Signatures::render($user, 'send', in_array($mode, ['reply', 'reply_all', 'forward'], true));
            $html = Signatures::inject($html, $sig['html']);
            if ($sig['needs_logo']) {
                $logo = storage_path('uploads/brand/' . basename((string) Settings::get('brand.logo', '')));
                if (is_file($logo)) {
                    $b->inline[] = [
                        'name' => 'logo.' . pathinfo($logo, PATHINFO_EXTENSION),
                        'mime' => mime_content_type($logo) ?: 'image/png',
                        'content' => (string) file_get_contents($logo),
                        'cid' => 'm4w-logo',
                    ];
                }
            }
        }
        $b->html = $html;

        // Attachments: fresh uploads + parts kept from the referenced message (forward / draft).
        $total = 0;
        $maxBytes = max(1, (int) Settings::get('security.max_attachment_mb', 25)) * 1024 * 1024;
        foreach ((array) ($in['attachments'] ?? []) as $token) {
            $up = DB::one('SELECT * FROM uploads WHERE token = :t AND user_id = :u', ['t' => (string) $token, 'u' => $user['id']]);
            if (!$up) {
                continue;
            }
            $path = storage_path($up['path']);
            if (!is_file($path)) {
                continue;
            }
            $total += (int) $up['size'];
            $b->attachments[] = ['name' => $up['filename'], 'mime' => $up['mime'], 'content' => (string) file_get_contents($path)];
        }
        if ($ref && !empty($in['ref_parts'])) {
            $parsedRef ??= Mailbox::parsed($ref);
            foreach ((array) $in['ref_parts'] as $partId) {
                $part = $parsedRef->findPart((string) $partId);
                if ($part && !$part->isMultipart()) {
                    $content = $part->decodedBody();
                    $total += strlen($content);
                    $b->attachments[] = ['name' => $part->filename() ?: 'attachment', 'mime' => $part->type, 'content' => $content];
                }
            }
        }
        if (!empty($in['attach_original']) && $ref) {
            $raw = Mailbox::raw($ref);
            $total += strlen($raw);
            $b->attachments[] = ['name' => (preg_replace('/[^\w\s.-]/u', '', $ref['subject']) ?: 'message') . '.eml', 'mime' => 'message/rfc822', 'content' => $raw];
        }
        if ($total > $maxBytes) {
            throw new \InvalidArgumentException(t('compose.too_big', ['mb' => (int) Settings::get('security.max_attachment_mb', 25)]));
        }
        return ['builder' => $b, 'recipients' => $b->envelopeRecipients()];
    }

    /**
     * Images referenced through the webmail (quoted inline parts, uploaded pictures, blocked remote
     * images in quotes) are turned back into real images for the recipients.
     */
    private static function restoreImages(string $html, MimeBuilder $b, int $userId): string
    {
        return preg_replace_callback('#<img\b[^>]*>#i', static function ($m) use ($b, $userId) {
            $tag = $m[0];
            if (preg_match('#data-m4w-src="(https?://[^"]+)"#i', $tag, $r)) {
                $tag = preg_replace('#\ssrc="[^"]*"#i', ' src="' . $r[1] . '"', $tag) ?? $tag;
                $tag = preg_replace('#\sdata-m4w-src="[^"]*"#i', '', $tag) ?? $tag;
                return str_replace('m4w-blocked', '', $tag);
            }
            if (!preg_match('#\ssrc="([^"]*?/api/(?:messages/\d+/part|upload)/[^"]+)"#i', $tag, $r)) {
                return $tag;
            }
            $src = html_entity_decode($r[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $content = null;
            $mime = 'image/png';
            $name = 'image';
            if (preg_match('#/api/messages/(\d+)/part/([^?"]+)#', $src, $p)) {
                $msg = Mailbox::get($userId, (int) $p[1]);
                if ($msg) {
                    $parsed = Mailbox::parsed($msg);
                    $partId = rawurldecode($p[2]);
                    $part = str_starts_with($partId, 'cid:') ? $parsed->findByCid(substr($partId, 4)) : $parsed->findPart($partId);
                    if ($part && str_starts_with($part->type, 'image/')) {
                        $content = $part->decodedBody();
                        $mime = $part->type;
                        $name = $part->filename() ?: 'image';
                    }
                }
            } elseif (preg_match('#/api/upload/([a-f0-9]{32})#', $src, $p)) {
                $up = DB::one('SELECT * FROM uploads WHERE token = :t AND user_id = :u', ['t' => $p[1], 'u' => $userId]);
                if ($up && str_starts_with($up['mime'], 'image/') && is_file(storage_path($up['path']))) {
                    $content = (string) file_get_contents(storage_path($up['path']));
                    $mime = $up['mime'];
                    $name = $up['filename'];
                }
            }
            if ($content === null) {
                return '';
            }
            $cid = 'img.' . bin2hex(random_bytes(8)) . '@m4w';
            $b->inline[] = ['name' => $name, 'mime' => $mime, 'content' => $content, 'cid' => $cid];
            return str_replace($r[0], ' src="cid:' . $cid . '"', $tag);
        }, $html) ?? $html;
    }

    /** Pasted images (data: URIs) become proper inline CID parts. */
    private static function extractDataImages(string $html, MimeBuilder $b): string
    {
        $i = 0;
        return preg_replace_callback('#src="data:(image/(?:png|gif|jpe?g|webp));base64,([A-Za-z0-9+/=\s]+)"#', static function ($m) use ($b, &$i) {
            $i++;
            $cid = 'img' . $i . '.' . bin2hex(random_bytes(6)) . '@m4w';
            $ext = explode('/', $m[1])[1];
            $b->inline[] = ['name' => "image$i.$ext", 'mime' => $m[1], 'content' => (string) base64_decode($m[2]), 'cid' => $cid];
            return 'src="cid:' . $cid . '"';
        }, $html) ?? $html;
    }

    /** @return array{id:int,warnings:array} */
    public static function send(array $user, array $in): array
    {
        if (Users::overQuota($user)) {
            throw new \InvalidArgumentException(t('compose.quota'));
        }
        ['builder' => $b, 'recipients' => $rcpts] = self::build($user, $in, true);
        $raw = $b->build(false);
        $res = Transport::send($raw, $b->from['email'], $rcpts, (int) $user['id']);
        if (!$res['sent'] && $res['failed']) {
            throw new \RuntimeException(t('compose.send_failed') . ' ' . implode(' — ', array_unique($res['failed'])));
        }
        if (!empty($user['_actor'])) {
            Delegation::countSent($user);
            \M4W\Core\Audit::log('delegation.sent', $user['email'], ['subject' => mb_substr($b->subject, 0, 120), 'to' => count($rcpts)], (int) $user['_actor']['id']);
        }
        $uid = (int) $user['id'];
        $sent = Folders::byRole($uid, 'sent');
        $id = Mailbox::store($uid, (int) $sent['id'], $b->build(true), ['read' => true]);

        $mode = (string) ($in['mode'] ?? 'new');
        $refId = (int) ($in['ref_id'] ?? 0);
        if ($refId) {
            if (in_array($mode, ['reply', 'reply_all'], true)) {
                Mailbox::setFlags($uid, [$refId], ['is_answered' => 1]);
            } elseif ($mode === 'forward') {
                Mailbox::setFlags($uid, [$refId], ['is_forwarded' => 1]);
            }
        }
        if (!empty($in['draft_id'])) {
            $draft = Mailbox::get($uid, (int) $in['draft_id']);
            if ($draft && $draft['is_draft']) {
                Mailbox::purge($uid, $draft);
            }
        }
        Contacts::touchRecipients($uid, array_merge($b->to, $b->cc, $b->bcc));
        self::cleanupUploads($uid, (array) ($in['attachments'] ?? []));
        Audit::log('mail.sent', $b->subject, ['rcpt' => count($rcpts)], $uid);
        return ['id' => $id, 'warnings' => $res['failed']];
    }

    public static function saveDraft(array $user, array $in): int
    {
        $uid = (int) $user['id'];
        ['builder' => $b] = self::build($user, $in, false);
        $drafts = Folders::byRole($uid, 'drafts');
        $meta = [
            'to' => (string) ($in['to'] ?? ''), 'cc' => (string) ($in['cc'] ?? ''), 'bcc' => (string) ($in['bcc'] ?? ''),
            'subject' => $b->subject, 'html' => (string) ($in['html'] ?? ''), 'from' => $b->from['email'],
            'attachments' => array_values(array_map('strval', (array) ($in['attachments'] ?? []))),
            'mode' => (string) ($in['mode'] ?? 'new'), 'ref_id' => (int) ($in['ref_id'] ?? 0),
            'ref_parts' => array_values((array) ($in['ref_parts'] ?? [])), 'priority' => $b->priority,
            'receipt' => !empty($in['receipt']) ? 1 : 0, 'attach_original' => !empty($in['attach_original']) ? 1 : 0,
            'scheduled_at' => max(0, (int) ($in['scheduled_at'] ?? 0)),
            // Written from a delegated mailbox: the scheduler must still name the real sender.
            'actor' => !empty($user['_actor']) ? ['id' => (int) $user['_actor']['id'], 'email' => $user['_actor']['email'], 'name' => $user['_actor']['name'], 'role' => $user['_actor']['role']] : null,
        ];
        $id = Mailbox::store($uid, (int) $drafts['id'], $b->build(true), ['read' => true, 'draft' => true, 'draft_meta' => $meta]);
        if (!empty($in['draft_id'])) {
            $old = Mailbox::get($uid, (int) $in['draft_id']);
            if ($old && $old['is_draft']) {
                Mailbox::purge($uid, $old);
            }
        }
        return $id;
    }

    /**
     * Schedule a message: validated now, stored as a draft and sent by the scheduler
     * (signature injected at actual send time).
     */
    public static function schedule(array $user, array $in, int $sendAt): int
    {
        if ($sendAt < time() + 60) {
            throw new \InvalidArgumentException(t('compose.schedule_past'));
        }
        if ($sendAt > time() + 366 * 86400) {
            throw new \InvalidArgumentException(t('compose.schedule_far'));
        }
        self::build($user, $in, true); // validation only
        return self::saveDraft($user, ['scheduled_at' => $sendAt] + $in);
    }

    /** Send drafts whose scheduled time has come. Returns number sent. */
    public static function processScheduled(int $max = 50): int
    {
        $rows = DB::all(
            "SELECT * FROM messages WHERE is_draft = 1 AND scheduled_at > 0 AND scheduled_at <= :now ORDER BY scheduled_at LIMIT $max",
            ['now' => time()]
        );
        $n = 0;
        foreach ($rows as $row) {
            $meta = json_decode((string) $row['draft_meta'], true) ?: [];
            $at = (int) ($meta['scheduled_at'] ?? 0);
            if ($at <= 0 || $at > time() || $n >= $max) {
                continue;
            }
            $user = Users::find((int) $row['user_id']);
            if (!$user || $user['status'] !== 'active') {
                continue;
            }
            if (!empty($meta['actor']['id']) && DB::value('SELECT 1 FROM users WHERE id = :i', ['i' => (int) $meta['actor']['id']])) {
                $user['_actor'] = $meta['actor'];
            }
            // Claim: clear the schedule first so a concurrent worker cannot send it twice.
            $meta['scheduled_at'] = 0;
            $claimed = DB::run('UPDATE messages SET draft_meta = :m, scheduled_at = 0 WHERE id = :id AND scheduled_at = :old', [
                'm' => json_encode($meta), 'id' => $row['id'], 'old' => $row['scheduled_at'],
            ])->rowCount();
            if (!$claimed) {
                continue;
            }
            try {
                self::send($user, $meta + ['draft_id' => (int) $row['id']]);
                $n++;
            } catch (\Throwable $e) {
                $meta['schedule_error'] = mb_substr($e->getMessage(), 0, 300);
                DB::update('messages', ['draft_meta' => json_encode($meta), 'is_read' => 0], 'id = :id', ['id' => $row['id']]);
                Audit::log('mail.schedule_failed', (string) $row['subject'], ['error' => $meta['schedule_error']], (int) $user['id']);
            }
        }
        return $n;
    }

    private static function cleanupUploads(int $userId, array $tokens): void
    {
        foreach ($tokens as $t) {
            $up = DB::one('SELECT * FROM uploads WHERE token = :t AND user_id = :u', ['t' => (string) $t, 'u' => $userId]);
            if ($up) {
                // Keep files still referenced by another draft.
                $used = DB::value('SELECT COUNT(*) FROM messages WHERE user_id = :u AND is_draft = 1 AND draft_meta LIKE :p', ['u' => $userId, 'p' => '%' . $up['token'] . '%']);
                if (!$used) {
                    @unlink(storage_path($up['path']));
                    DB::delete('uploads', 'id = :id', ['id' => $up['id']]);
                }
            }
        }
    }

    public static function storeUpload(int $userId, array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new \InvalidArgumentException(t('upload.failed'));
        }
        $max = max(1, (int) Settings::get('security.max_attachment_mb', 25)) * 1024 * 1024;
        if ((int) $file['size'] > $max) {
            throw new \InvalidArgumentException(t('compose.too_big', ['mb' => (int) Settings::get('security.max_attachment_mb', 25)]));
        }
        $token = bin2hex(random_bytes(16));
        $rel = 'tmp/up-' . $userId . '-' . $token;
        $dest = storage_path($rel);
        if (!is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0750, true);
        }
        move_uploaded_file($file['tmp_name'], $dest);
        $name = mb_substr(str_replace(["\0", '/', '\\', "\r", "\n", '"'], '_', (string) $file['name']), 0, 200) ?: 'fichier';
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($dest) ?: 'application/octet-stream';
        DB::insert('uploads', [
            'user_id' => $userId, 'token' => $token, 'filename' => $name, 'mime' => $mime,
            'size' => (int) filesize($dest), 'path' => $rel, 'created_at' => time(),
        ]);
        return ['token' => $token, 'name' => $name, 'size' => (int) filesize($dest), 'mime' => $mime];
    }
}
