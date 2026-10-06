<?php
declare(strict_types=1);

namespace M4W\Controller;

use M4W\Core\Csrf;
use M4W\Core\Database as DB;
use M4W\Core\HttpException;
use M4W\Core\I18n;
use M4W\Core\Response;
use M4W\Core\Session;
use M4W\Core\Settings;
use M4W\Mail\Address;
use M4W\Mail\HtmlSanitizer;
use M4W\Service\Composer;
use M4W\Service\Folders;
use M4W\Service\Mailbox;
use M4W\Service\Signatures;
use M4W\Service\Users;
use M4W\Service\Vacation;

final class MailController extends Controller
{
    public function shell(): Response
    {
        $u = $this->user();
        return $this->view('mail/app', ['user' => $u], 'layouts/mail');
    }

    public function bootstrap(): Response
    {
        $u = $this->user();
        Session::close();
        $sig = Signatures::render($u, 'preview');
        return $this->ok([
            'user' => [
                'id' => (int) $u['id'], 'email' => $u['email'], 'name' => $u['name'], 'role' => $u['role'],
                'prefs' => array_diff_key($u['prefs'], ['_recovery' => 1, '_totp_step' => 1]), 'language' => I18n::language(),
                'quota_mb' => (int) $u['quota_mb'], 'used_bytes' => (int) $u['used_bytes'],
            ],
            'identities' => Composer::identities($u),
            'folders'    => Folders::listWithCounts((int) $u['id']),
            'signature'  => $sig['html'],
            'vacation'   => Vacation::isActive(Vacation::get((int) $u['id'])),
            'settings'   => [
                'undo_send'      => (int) Settings::get('features.undo_send_seconds', 5),
                'max_attach_mb'  => (int) Settings::get('security.max_attachment_mb', 25),
                'block_images'   => (int) Settings::get('security.block_remote_images', 1),
            ],
        ]);
    }

    public function folders(): Response
    {
        $uid = $this->uid();
        Session::close();
        return $this->ok(['folders' => Folders::listWithCounts($uid)]);
    }

    public function createFolder(): Response
    {
        try {
            $parent = $this->req->int('parent_id') ?: null;
            $id = Folders::create($this->uid(), $this->req->str('name'), $parent, $this->req->str('color'));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->ok(['id' => $id, 'folders' => Folders::listWithCounts($this->uid())]);
    }

    public function updateFolder(string $id): Response
    {
        try {
            Folders::rename($this->uid(), (int) $id, $this->req->str('name'), $this->req->str('color'));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->ok(['folders' => Folders::listWithCounts($this->uid())]);
    }

    public function deleteFolder(string $id): Response
    {
        try {
            Folders::delete($this->uid(), (int) $id);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->ok(['folders' => Folders::listWithCounts($this->uid())]);
    }

    public function emptyFolder(string $id): Response
    {
        $f = Folders::find($this->uid(), (int) $id);
        if (!$f || !in_array($f['role'], ['trash', 'spam'], true)) {
            return $this->fail(t('folder.cannot_empty'));
        }
        $n = Mailbox::emptyFolder($this->uid(), (int) $id);
        return $this->ok(['count' => $n, 'folders' => Folders::listWithCounts($this->uid())]);
    }

    public function readAll(string $id): Response
    {
        DB::run('UPDATE messages SET is_read = 1 WHERE user_id = :u AND folder_id = :f', ['u' => $this->uid(), 'f' => (int) $id]);
        return $this->ok(['folders' => Folders::listWithCounts($this->uid())]);
    }

    public function list(): Response
    {
        $u = $this->user();
        Session::close();
        $q = $this->req->query;
        $res = Mailbox::list((int) $u['id'], [
            'folder'  => (int) ($q['folder'] ?? 0),
            'q'       => (string) ($q['q'] ?? ''),
            'filter'  => (string) ($q['filter'] ?? ''),
            'offset'  => (int) ($q['offset'] ?? 0),
            'limit'   => (int) ($q['limit'] ?? ($u['prefs']['page_size'] ?? 50)),
            'sort'    => (string) ($q['sort'] ?? 'date'),
            'dir'     => (string) ($q['dir'] ?? 'desc'),
            'threads' => !empty($q['threads']),
            'in_folder' => !empty($q['in_folder']),
        ]);
        return $this->ok($res);
    }

    private function message(string $id): array
    {
        $m = Mailbox::get($this->uid(), (int) $id);
        if (!$m) {
            throw new HttpException(404, t('mail.not_found'));
        }
        return $m;
    }

    /** Message (or whole conversation) detail. */
    public function show(string $id): Response
    {
        $u = $this->user();
        $m = $this->message($id);
        $ids = !empty($this->req->query['thread']) && $m['thread_key'] !== '' ? Mailbox::threadIds((int) $u['id'], $m['thread_key']) : [(int) $m['id']];
        if (!in_array((int) $m['id'], $ids, true)) {
            $ids[] = (int) $m['id'];
        }
        $items = [];
        $toMark = [];
        foreach ($ids as $mid) {
            $row = $mid === (int) $m['id'] ? $m : Mailbox::get((int) $u['id'], $mid);
            if (!$row) {
                continue;
            }
            $items[] = $this->detail($row);
            if (!$row['is_read']) {
                $toMark[] = $mid;
            }
        }
        $folder = Folders::find((int) $u['id'], (int) $m['folder_id']);
        return $this->ok([
            'message'  => $this->detail($m),
            'thread'   => $items,
            'unread_ids' => $toMark,
            'folder'   => $folder ? ['id' => (int) $folder['id'], 'role' => $folder['role'], 'name' => Folders::displayName($folder)] : null,
        ]);
    }

    private function detail(array $m): array
    {
        $parsed = Mailbox::parsed($m);
        $u = $this->user();
        $blockImages = (int) Settings::get('security.block_remote_images', 1) && !(int) $u['prefs']['show_images'];
        // Count blocked images without rendering the full body.
        $blocked = 0;
        if ($blockImages) {
            $s = new HtmlSanitizer(true, static fn() => 'about:blank');
            $s->sanitize($parsed->html());
            $blocked = $s->blockedCount();
        }
        $listUnsub = (string) $parsed->header('List-Unsubscribe');
        $unsub = '';
        if (preg_match('/<(https:[^>]+)>/i', $listUnsub, $mm)) {
            $unsub = $mm[1];
        } elseif (preg_match('/<(mailto:[^>]+)>/i', $listUnsub, $mm)) {
            $unsub = $mm[1];
        }
        $draftMeta = $m['is_draft'] ? (json_decode((string) $m['draft_meta'], true) ?: null) : null;
        return [
            'id'          => (int) $m['id'],
            'folder_id'   => (int) $m['folder_id'],
            'subject'     => $m['subject'],
            'from'        => ['name' => $m['from_name'], 'email' => $m['from_email']],
            'to'          => json_decode((string) $m['to_list'], true) ?: [],
            'cc'          => json_decode((string) $m['cc_list'], true) ?: [],
            'bcc'         => json_decode((string) $m['bcc_list'], true) ?: [],
            'reply_to'    => $parsed->replyTo(),
            'auth'        => ($a = $parsed->header('X-M4W-Auth')) ? \M4W\Mail\MailAuth::parse($a) : null,
            'date'        => (int) ($m['date_sent'] ?: $m['date_received']),
            'received'    => (int) $m['date_received'],
            'size'        => (int) $m['size'],
            'snippet'     => $m['snippet'],
            'unread'      => !$m['is_read'],
            'flagged'     => (bool) $m['is_flagged'],
            'answered'    => (bool) $m['is_answered'],
            'forwarded'   => (bool) $m['is_forwarded'],
            'draft'       => (bool) $m['is_draft'],
            'draft_meta'  => $draftMeta,
            'priority'    => (int) $m['priority'],
            'attachments' => json_decode((string) $m['attachments'], true) ?: [],
            'blocked_images' => $blocked,
            'unsubscribe' => $unsub,
            'auto'        => $parsed->isAutomated(),
            'text'        => mb_substr($parsed->text(), 0, 50000),
        ];
    }

    /** Sanitized HTML document, loaded in a sandboxed iframe. */
    public function body(string $id): Response
    {
        $u = $this->user();
        Session::close();
        $m = $this->message($id);
        $parsed = Mailbox::parsed($m);
        $allowImages = !empty($this->req->query['images']) || (int) $u['prefs']['show_images'] || !(int) Settings::get('security.block_remote_images', 1);
        $mid = (int) $m['id'];
        $resolver = static fn(string $cid) => url('api/messages/' . $mid . '/part/cid:' . rawurlencode($cid), ['inline' => 1]);
        $sanitizer = new HtmlSanitizer(!$allowImages, $resolver);
        $html = $sanitizer->sanitize($parsed->html());
        $dark = ($this->req->query['theme'] ?? '') === 'dark';
        $doc = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<base target="_blank">'
            . '<style>html,body{margin:0;padding:0}body{font-family:-apple-system,"Segoe UI",Roboto,Arial,sans-serif;font-size:14px;line-height:1.55;color:#1f2937;word-wrap:break-word;overflow-wrap:anywhere;padding:2px 2px 8px}'
            . 'img{max-width:100%;height:auto}table{max-width:100%}pre{white-space:pre-wrap}a{color:#2563eb}'
            . 'blockquote{margin:8px 0 8px 4px;padding-left:12px;border-left:3px solid #cbd5e1;color:#475569}'
            . '.m4w-blocked{background:#f1f5f9;outline:1px dashed #cbd5e1;min-width:16px;min-height:16px}'
            . ($dark ? 'html{filter:invert(1) hue-rotate(180deg);background:#fff}img,video,[style*="background-image"]{filter:invert(1) hue-rotate(180deg)}' : '')
            . '</style></head><body>' . $html . '</body></html>';
        $imgSrc = $allowImages ? "'self' data: https: http:" : "'self' data:";
        return new Response($doc, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => "default-src 'none'; img-src $imgSrc; style-src 'unsafe-inline'; font-src data:; frame-ancestors 'self'; base-uri 'none'; form-action 'none'; sandbox allow-same-origin allow-popups allow-popups-to-escape-sandbox",
            'X-Frame-Options' => 'SAMEORIGIN',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function part(string $id, string $part): Response
    {
        Session::close();
        $m = $this->message($id);
        $parsed = Mailbox::parsed($m);
        $part = rawurldecode($part);
        $p = str_starts_with($part, 'cid:') ? $parsed->findByCid(substr($part, 4)) : $parsed->findPart($part);
        if (!$p || $p->isMultipart()) {
            throw new HttpException(404, t('mail.not_found'));
        }
        $content = $p->decodedBody();
        $name = $p->filename() ?: 'attachment';
        $mime = $p->type;
        $inline = !empty($this->req->query['inline']);
        // Only render safe types inline; everything else is a download.
        $safeInline = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp', 'application/pdf', 'text/plain'];
        if ($inline && !in_array($mime, $safeInline, true)) {
            $inline = false;
        }
        if ($mime === 'text/plain') {
            $mime = 'text/plain; charset=utf-8';
            $content = $p->text();
        }
        $r = Response::download($content, $inline ? $mime : 'application/octet-stream', $name, $inline);
        $r->header('Content-Security-Policy', "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; frame-ancestors 'self'; sandbox");
        $r->header('X-Frame-Options', 'SAMEORIGIN');
        return $r;
    }

    /** All attachments of a message as a single ZIP archive. */
    public function zip(string $id): Response
    {
        Session::close();
        $m = $this->message($id);
        $parsed = Mailbox::parsed($m);
        if (!class_exists(\ZipArchive::class)) {
            throw new HttpException(500, 'ZIP extension missing');
        }
        $tmp = tempnam(storage_path('tmp'), 'zip');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $used = [];
        foreach ($parsed->attachmentList() as $a) {
            if ($a['inline']) {
                continue;
            }
            $part = $parsed->findPart($a['part']);
            if (!$part) {
                continue;
            }
            $name = $a['name'];
            $i = 1;
            while (isset($used[mb_strtolower($name)])) {
                $name = pathinfo($a['name'], PATHINFO_FILENAME) . " ($i)." . pathinfo($a['name'], PATHINFO_EXTENSION);
                $i++;
            }
            $used[mb_strtolower($name)] = true;
            $zip->addFromString($name, $part->decodedBody());
        }
        $count = $zip->numFiles;
        $zip->close();
        if ($count === 0) {
            @unlink($tmp);
            throw new HttpException(404, t('mail.not_found'));
        }
        $content = (string) file_get_contents($tmp);
        @unlink($tmp);
        $base = preg_replace('/[^\p{L}\p{N}\s._-]/u', '', $m['subject']) ?: 'pieces-jointes';
        return Response::download($content, 'application/zip', mb_substr($base, 0, 80) . '.zip');
    }

    public function source(string $id): Response
    {
        $m = $this->message($id);
        $r = Response::download(Mailbox::raw($m), 'text/plain; charset=utf-8', 'message.txt', true);
        $r->header('Content-Security-Policy', "default-src 'none'; sandbox");
        return $r;
    }

    public function download(string $id): Response
    {
        $m = $this->message($id);
        $name = (preg_replace('/[^\p{L}\p{N}\s._-]/u', '', $m['subject']) ?: 'message') . '.eml';
        return Response::download(Mailbox::raw($m), 'message/rfc822', $name);
    }

    public function printView(string $id): Response
    {
        $m = $this->message($id);
        $parsed = Mailbox::parsed($m);
        $mid = (int) $m['id'];
        $html = (new HtmlSanitizer(true, static fn(string $cid) => url('api/messages/' . $mid . '/part/cid:' . rawurlencode($cid), ['inline' => 1]), false))->sanitize($parsed->html());
        return $this->view('mail/print', ['m' => $m, 'parsed' => $parsed, 'html' => $html], 'layouts/blank');
    }

    public function unsubscribe(string $id): Response
    {
        $m = $this->message($id);
        $parsed = Mailbox::parsed($m);
        $header = (string) $parsed->header('List-Unsubscribe');
        $post = stripos((string) $parsed->header('List-Unsubscribe-Post'), 'One-Click') !== false;
        if ($post && preg_match('/<(https:[^>]+)>/i', $header, $mm)) {
            // RFC 8058 one-click unsubscribe, performed server-side (hides the user's IP).
            // Strict URL shape first: parsers disagree on "user@host", backslashes, etc.
            $url = $mm[1];
            if (!preg_match('#^https://([a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?)(?::(\d{1,5}))?(/[^\s\\\\@<>"]*)?$#i', $url, $um)) {
                return $this->fail(t('mail.unsub_failed'));
            }
            $host = $um[1];
            $port = (int) ($um[2] ?? '') ?: 443;
            try {
                $ip = \M4W\Core\Net::publicIp($host);
            } catch (\InvalidArgumentException) {
                return $this->fail(t('mail.unsub_failed'));
            }
            if (!function_exists('curl_init')) {
                return $this->ok(['url' => $url]);
            }
            $pinned = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => 'List-Unsubscribe=One-Click', CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 10, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $pinned],
                // Every connection goes to the validated address, whatever host curl derives from the URL.
                CURLOPT_CONNECT_TO => ['::' . $pinned . ':' . $port],
            ]);
            curl_exec($ch);
            $used = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
            curl_close($ch);
            if ($used !== '' && $used !== $ip) {
                error_log('[m4w] unsubscribe: connected to unexpected address ' . $used);
                return $this->fail(t('mail.unsub_failed'));
            }
            return $this->ok(['done' => true]);
        }
        if (preg_match('/<(https:[^>]+)>/i', $header, $mm)) {
            return $this->ok(['url' => $mm[1]]);
        }
        if (preg_match('/<mailto:([^>?]+)(?:\?subject=([^>&]*))?/i', $header, $mm)) {
            return $this->ok(['mailto' => rawurldecode($mm[1]), 'subject' => rawurldecode($mm[2] ?? 'unsubscribe')]);
        }
        return $this->fail(t('mail.unsub_failed'));
    }

    public function action(): Response
    {
        $uid = $this->uid();
        $ids = array_map('intval', $this->req->arr('ids'));
        if ($this->req->bool('thread')) {
            $expanded = [];
            foreach ($ids as $i) {
                $m = Mailbox::get($uid, $i);
                if ($m) {
                    $expanded = array_merge($expanded, $m['thread_key'] !== '' ? $this->threadInFolder($uid, $m) : [$i]);
                }
            }
            $ids = array_values(array_unique($expanded ?: $ids));
        }
        $action = $this->req->str('action');
        $n = 0;
        try {
            switch ($action) {
                case 'read':
                case 'unread':
                    $n = Mailbox::setFlags($uid, $ids, ['is_read' => $action === 'read']);
                    break;
                case 'flag':
                case 'unflag':
                    $n = Mailbox::setFlags($uid, $ids, ['is_flagged' => $action === 'flag']);
                    break;
                case 'move':
                    $n = Mailbox::move($uid, $ids, $this->req->int('folder'));
                    break;
                case 'archive':
                case 'spam':
                case 'inbox':
                    $n = Mailbox::move($uid, $ids, (int) Folders::byRole($uid, $action)['id']);
                    if ($action === 'spam') {
                        Mailbox::setFlags($uid, $ids, ['is_read' => 1]);
                    }
                    break;
                case 'delete':
                    $n = Mailbox::delete($uid, $ids);
                    break;
                case 'purge':
                    $n = Mailbox::delete($uid, $ids, true);
                    break;
                default:
                    return $this->fail('Unknown action');
            }
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->ok(['count' => $n, 'ids' => $ids, 'folders' => Folders::listWithCounts($uid)]);
    }

    private function threadInFolder(int $uid, array $m): array
    {
        $rows = DB::all('SELECT id FROM messages WHERE user_id = :u AND thread_key = :t AND folder_id = :f', ['u' => $uid, 't' => $m['thread_key'], 'f' => $m['folder_id']]);
        return array_map('intval', array_column($rows, 'id'));
    }

    public function poll(): Response
    {
        $u = $this->user();
        Session::close();
        $since = (int) ($this->req->query['since'] ?? 0);
        $inbox = Folders::byRole((int) $u['id'], 'inbox');
        $new = DB::all(
            'SELECT id, from_name, from_email, subject FROM messages WHERE user_id = :u AND folder_id = :f AND id > :s AND is_read = 0 ORDER BY id DESC LIMIT 5',
            ['u' => $u['id'], 'f' => $inbox['id'], 's' => $since]
        );
        $max = (int) DB::value('SELECT COALESCE(MAX(id),0) FROM messages WHERE user_id = :u', ['u' => $u['id']]);
        return $this->ok([
            'max_id'  => $max,
            'new'     => $since > 0 ? $new : [],
            'folders' => Folders::listWithCounts((int) $u['id']),
            'csrf'    => Csrf::token(),
        ]);
    }

    public function prefs(): Response
    {
        $u = $this->user();
        Users::savePrefs((int) $u['id'], $this->req->arr('prefs'));
        return $this->ok(['prefs' => array_diff_key(Users::find((int) $u['id'])['prefs'], ['_recovery' => 1, '_totp_step' => 1])]);
    }
}
