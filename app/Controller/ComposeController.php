<?php
declare(strict_types=1);

namespace M4W\Controller;

use M4W\Core\Database as DB;
use M4W\Core\HttpException;
use M4W\Core\I18n;
use M4W\Core\Response;
use M4W\Core\Session;
use M4W\Mail\Address;
use M4W\Mail\HtmlSanitizer;
use M4W\Service\Composer;
use M4W\Service\Contacts;
use M4W\Service\Mailbox;
use M4W\Service\Signatures;

final class ComposeController extends Controller
{
    public function prefill(): Response
    {
        $u = $this->user();
        Session::close();
        $mode = (string) ($this->req->query['mode'] ?? 'new');
        $id = (int) ($this->req->query['id'] ?? 0);
        $out = ['mode' => $mode, 'to' => '', 'cc' => '', 'bcc' => '', 'subject' => '', 'html' => '', 'ref_id' => 0,
            'ref_attachments' => [], 'attachments' => [], 'draft_id' => 0, 'from' => $u['email'], 'priority' => 3,
            'receipt' => 0, 'attach_original' => 0, 'scheduled_at' => 0, 'schedule_error' => ''];

        if ($mode === 'new') {
            $out['to'] = (string) ($this->req->query['to'] ?? '');
            $out['subject'] = (string) ($this->req->query['subject'] ?? '');
            return $this->ok(['compose' => $out, 'signature' => Signatures::render($u)['html']]);
        }
        $m = Mailbox::get((int) $u['id'], $id);
        if (!$m) {
            throw new HttpException(404, t('mail.not_found'));
        }
        if ($mode === 'draft' || $mode === 'edit') {
            $meta = json_decode((string) $m['draft_meta'], true) ?: [];
            $out = array_replace($out, array_intersect_key($meta, $out));
            $out['mode'] = $meta['mode'] ?? 'new';
            $out['draft_id'] = $mode === 'draft' ? (int) $m['id'] : 0;
            $out['ref_id'] = (int) ($meta['ref_id'] ?? 0);
            if ($mode === 'edit') {
                // "Edit as new" from sent items.
                $parsed = Mailbox::parsed($m);
                $out['to'] = implode(', ', array_map(static fn($a) => Address::format($a['email'], $a['name']), $parsed->to()));
                $out['cc'] = implode(', ', array_map(static fn($a) => Address::format($a['email'], $a['name']), $parsed->cc()));
                $out['subject'] = $parsed->subject();
                $out['html'] = $this->quotedHtml($m, $parsed->html());
                $out['mode'] = 'new';
            }
            $out['attachments'] = [];
            foreach ((array) ($meta['attachments'] ?? []) as $tok) {
                $up = DB::one('SELECT token, filename, size, mime FROM uploads WHERE token = :t AND user_id = :u', ['t' => (string) $tok, 'u' => $u['id']]);
                if ($up) {
                    $out['attachments'][] = ['token' => $up['token'], 'name' => $up['filename'], 'size' => (int) $up['size'], 'mime' => $up['mime']];
                }
            }
            if ($out['ref_id'] && !empty($meta['ref_parts'])) {
                $ref = Mailbox::get((int) $u['id'], $out['ref_id']);
                if ($ref) {
                    foreach (json_decode((string) $ref['attachments'], true) ?: [] as $a) {
                        if (in_array($a['part'], (array) $meta['ref_parts'], true)) {
                            $out['ref_attachments'][] = $a;
                        }
                    }
                }
            }
            return $this->ok(['compose' => $out, 'signature' => Signatures::render($u, 'preview', $out['mode'] !== 'new')['html']]);
        }

        $parsed = Mailbox::parsed($m);
        $out['ref_id'] = (int) $m['id'];
        $subject = $parsed->subject();
        $clean = preg_replace('/^((re|tr|fw|fwd|aw|wg|rv|sv)\s*(\[\d+\])?\s*:\s*)+/iu', '', $subject) ?? $subject;
        $fr = I18n::language() === 'fr';
        $from = $parsed->from();
        $dateStr = format_datetime($parsed->date() ?: (int) $m['date_received']);
        $quotedBody = $this->quotedHtml($m, $parsed->html());
        $me = array_map('mb_strtolower', array_column(Composer::identities($u), 'email'));

        if ($mode === 'reply' || $mode === 'reply_all') {
            $replyTo = $parsed->replyTo() ?: [$from];
            // Replying to one's own sent message: reply to its recipients. Mail received over SMTP
            // (it carries our authentication header) is never "our own", even if its From: says so.
            if (in_array(mb_strtolower($from['email']), $me, true) && $parsed->header('X-M4W-Auth') === null) {
                $replyTo = $parsed->to();
            }
            $to = $replyTo;
            $cc = [];
            if ($mode === 'reply_all') {
                $seen = array_map(static fn($a) => mb_strtolower($a['email']), $to);
                foreach (array_merge($parsed->to(), $parsed->cc()) as $a) {
                    $k = mb_strtolower($a['email']);
                    if (!in_array($k, $me, true) && !in_array($k, $seen, true)) {
                        $cc[] = $a;
                        $seen[] = $k;
                    }
                }
            }
            $fmt = static fn(array $l) => implode(', ', array_map(static fn($a) => Address::format($a['email'], $a['name']), $l));
            $out['to'] = $fmt($to);
            $out['cc'] = $fmt($cc);
            $out['subject'] = ($fr ? 'RE: ' : 'Re: ') . $clean;
            $who = e(Address::format($from['email'], $from['name']));
            $intro = $fr ? "Le $dateStr, $who a écrit :" : "On $dateStr, $who wrote:";
            $out['html'] = '<p><br></p><div data-m4w-quote="1"><div style="color:#64748b;margin:12px 0 6px">' . $intro . '</div>'
                . '<blockquote style="margin:0 0 0 .8ex;border-left:2px solid #cbd5e1;padding-left:1ex">' . $quotedBody . '</blockquote></div>';
            // Pick the identity the original was addressed to.
            foreach (array_merge($parsed->to(), $parsed->cc()) as $a) {
                if (in_array(mb_strtolower($a['email']), $me, true)) {
                    $out['from'] = mb_strtolower($a['email']);
                    break;
                }
            }
        } elseif ($mode === 'forward') {
            $out['subject'] = ($fr ? 'TR: ' : 'Fwd: ') . $clean;
            $fmt = static fn(array $l) => e(implode(', ', array_map(static fn($a) => Address::format($a['email'], $a['name']), $l)));
            $hdr = '<div style="color:#475569;margin:12px 0">---------- ' . ($fr ? 'Message transféré' : 'Forwarded message') . ' ----------<br>'
                . '<b>' . ($fr ? 'De' : 'From') . ' :</b> ' . e(Address::format($from['email'], $from['name'])) . '<br>'
                . '<b>Date :</b> ' . e($dateStr) . '<br>'
                . '<b>' . ($fr ? 'Objet' : 'Subject') . ' :</b> ' . e($subject) . '<br>'
                . '<b>' . ($fr ? 'À' : 'To') . ' :</b> ' . $fmt($parsed->to())
                . ($parsed->cc() ? '<br><b>Cc :</b> ' . $fmt($parsed->cc()) : '') . '</div>';
            $out['html'] = '<p><br></p><div data-m4w-quote="1">' . $hdr . $quotedBody . '</div>';
            $out['ref_attachments'] = array_values(array_filter(json_decode((string) $m['attachments'], true) ?: [], static fn($a) => !$a['inline']));
        }
        return $this->ok(['compose' => $out, 'signature' => Signatures::render($u, 'preview', true)['html']]);
    }

    private function quotedHtml(array $m, string $html): string
    {
        $mid = (int) $m['id'];
        $resolver = static fn(string $cid) => url('api/messages/' . $mid . '/part/cid:' . rawurlencode($cid), ['inline' => 1]);
        $clean = (new HtmlSanitizer(true, $resolver, false))->sanitize($html);
        // Drop previous signature markers so only the new signature is injected at the top.
        return str_replace('data-m4w-quote', 'data-m4w-q', $clean);
    }

    public function send(): Response
    {
        $u = $this->user();
        try {
            $res = Composer::send($u, $this->payload());
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->ok($res);
    }

    public function schedule(): Response
    {
        $u = $this->user();
        try {
            $id = Composer::schedule($u, $this->payload(), $this->req->int('send_at'));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->ok(['draft_id' => $id]);
    }

    public function draft(): Response
    {
        $u = $this->user();
        try {
            $id = Composer::saveDraft($u, $this->payload());
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->ok(['draft_id' => $id, 'saved_at' => time()]);
    }

    private function payload(): array
    {
        $r = $this->req;
        return [
            'from' => $r->str('from'), 'to' => $r->raw('to'), 'cc' => $r->raw('cc'), 'bcc' => $r->raw('bcc'),
            'subject' => $r->raw('subject'), 'html' => $r->raw('html'), 'mode' => $r->str('mode', 'new'),
            'ref_id' => $r->int('ref_id'), 'ref_parts' => $r->arr('ref_parts'), 'attachments' => $r->arr('attachments'),
            'draft_id' => $r->int('draft_id'), 'priority' => $r->int('priority', 3), 'receipt' => $r->bool('receipt'),
            'attach_original' => $r->bool('attach_original'), 'scheduled_at' => $r->int('scheduled_at'),
        ];
    }

    public function upload(): Response
    {
        $u = $this->user();
        $file = $this->req->files['file'] ?? null;
        if (!is_array($file)) {
            return $this->fail(t('upload.failed'));
        }
        try {
            $info = Composer::storeUpload((int) $u['id'], $file);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        $info['url'] = url('api/upload/' . $info['token']);
        return $this->ok(['file' => $info]);
    }

    public function uploadPreview(string $token): Response
    {
        $up = DB::one('SELECT * FROM uploads WHERE token = :t AND user_id = :u', ['t' => $token, 'u' => $this->uid()]);
        if (!$up || !is_file(storage_path($up['path']))) {
            throw new HttpException(404, t('mail.not_found'));
        }
        $inline = in_array($up['mime'], ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true);
        return Response::file(storage_path($up['path']), $inline ? $up['mime'] : 'application/octet-stream', $up['filename'], $inline);
    }

    public function suggest(): Response
    {
        $uid = $this->uid();
        Session::close();
        return $this->ok(['items' => Contacts::suggest($uid, (string) ($this->req->query['q'] ?? ''))]);
    }

    public function signaturePreview(): Response
    {
        return $this->ok(['html' => Signatures::render($this->user(), 'preview', !empty($this->req->query['reply']))['html']]);
    }
}
