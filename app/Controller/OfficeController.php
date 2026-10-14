<?php
declare(strict_types=1);

namespace M4W\Controller;

use M4W\Core\Audit;
use M4W\Core\HttpException;
use M4W\Core\I18n;
use M4W\Core\Request;
use M4W\Core\Response;
use M4W\Core\Session;
use M4W\Core\View;
use M4W\Service\Files;
use M4W\Service\Office;

/**
 * Online editing: the editor page (signed-in users), the WOPI endpoints called by the
 * office server (authenticated by access token only), and the built-in text editor.
 */
final class OfficeController extends Controller
{
    private const TEXT_EXT = ['txt', 'md', 'csv', 'log', 'json', 'xml', 'ini', 'conf', 'yml', 'yaml'];
    private const TEXT_MAX = 2 * 1024 * 1024;

    // --------------------------------------------------------- editor page

    public function editor(string $id): Response
    {
        $user = $this->user();
        $f = Files::requireFile($user, (int) $id, Files::READ);
        if (!Office::enabled()) {
            throw new HttpException(404, t('office.not_configured'));
        }
        $canWrite = Files::filePermission($user, $f) >= Files::WRITE;
        $mode = ($this->req->query['mode'] ?? '') === 'view' || !$canWrite ? 'view' : 'edit';
        $base = ($this->req->isSecure() ? 'https' : 'http') . '://' . ($this->req->server['HTTP_HOST'] ?? 'localhost') . Request::basePath();
        $wopiSrc = rtrim((string) \M4W\Core\Settings::get('office.wopi_base', ''), '/') ?: $base;
        $action = Office::actionUrl($f, $mode, $wopiSrc . '/wopi/files/' . (int) $f['id'], I18n::language() === 'fr' ? 'fr-FR' : 'en-US');
        if ($action === null) {
            throw new HttpException(415, t('office.unsupported'));
        }
        $tok = Office::issueToken($user, $f, $mode === 'edit');
        if ($mode === 'edit') {
            Audit::log('files.opened_editor', $f['name'], [], (int) $this->actor()['id']);
        }
        $html = View::render('files/office', [
            'file' => $f, 'action' => $action, 'token' => $tok['token'], 'ttl' => $tok['ttl'], 'mode' => $mode,
            'origin' => Office::origin(), 'embedded' => !empty($this->req->query['embed']),
        ], 'layouts/blank');
        $origin = Office::origin();
        return new Response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            // The office server is the only other origin this page may frame or post to.
            'Content-Security-Policy' => implode('; ', [
                "default-src 'self'", "script-src 'self'", "style-src 'self' 'unsafe-inline'", "img-src 'self' data:", "font-src 'self' data:",
                "connect-src 'self'", "frame-src 'self' $origin", "form-action 'self' $origin", "object-src 'none'", "base-uri 'self'",
                "frame-ancestors 'self'",
            ]),
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);
    }

    // ------------------------------------------------------------- WOPI

    private function ctx(string $id): array
    {
        $c = Office::resolve((string) ($this->req->query['access_token'] ?? ''), (int) $id);
        if (!$c) {
            throw new HttpException(401, 'Invalid access token');
        }
        return $c;
    }

    /** CheckFileInfo */
    public function checkFileInfo(string $id): Response
    {
        Session::close();
        $c = $this->ctx($id);
        $f = $c['file'];
        $u = $c['user'];
        return Response::json([
            'BaseFileName' => $f['name'],
            'Size' => (int) $f['size'],
            'Version' => (string) $f['version'] . '-' . substr((string) $f['sha256'], 0, 12),
            'OwnerId' => (string) (int) $f['created_by'],
            'UserId' => (string) (int) $u['id'],
            'UserFriendlyName' => $u['name'] ?: $u['email'],
            'UserCanWrite' => $c['write'],
            'ReadOnly' => !$c['write'],
            'UserCanNotWriteRelative' => true,
            'UserCanRename' => false,
            'SupportsLocks' => true,
            'SupportsGetLock' => true,
            'SupportsUpdate' => true,
            'SupportsRename' => false,
            'LastModifiedTime' => Office::modifiedIso($f),
            'PostMessageOrigin' => ($this->req->isSecure() ? 'https' : 'http') . '://' . ($this->req->server['HTTP_HOST'] ?? ''),
            // Nothing fetched by the office server on the user's behalf, no sharing outside.
            'EnableInsertRemoteImage' => false,
            'EnableShare' => false,
            'HideExportOption' => false,
            'DisablePrint' => false,
        ]);
    }

    /** GetFile */
    public function getFile(string $id): Response
    {
        Session::close();
        $c = $this->ctx($id);
        $f = $c['file'];
        return new Response('', 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) (int) $f['size'],
            'X-WOPI-ItemVersion' => (string) $f['version'],
        ], static function () use ($f) {
            Files::stream($f['blob_id'], static function (string $buf) {
                echo $buf;
                flush();
            });
        });
    }

    /** PutFile */
    public function putFile(string $id): Response
    {
        Session::close();
        $c = $this->ctx($id);
        $f = $c['file'];
        if (!$c['write']) {
            return new Response('', 401);
        }
        $lock = Office::lock((int) $f['id']);
        $sent = $this->req->header('X-WOPI-Lock');
        if ($lock !== null && $sent !== $lock) {
            return new Response('', 409, ['X-WOPI-Lock' => $lock]);
        }
        // Collabora: refuse to overwrite a version saved by someone else meanwhile.
        $ts = $this->req->header('X-COOL-WOPI-Timestamp') ?: $this->req->header('X-LOOL-WOPI-Timestamp');
        if ($ts !== '' && $ts !== Office::modifiedIso($f)) {
            return Response::json(['COOLStatusCode' => 1010, 'LOOLStatusCode' => 1010], 409);
        }
        $tmp = (string) tempnam(storage_path('tmp'), 'wopi');
        try {
            $in = fopen('php://input', 'rb');
            $out = fopen($tmp, 'wb');
            $n = stream_copy_to_stream($in, $out, Files::maxFileBytes() + 1);
            fclose($in);
            fclose($out);
            if ($n > Files::maxFileBytes()) {
                return new Response('', 413);
            }
            $saved = Files::putContent($c['user'], (int) $f['id'], $tmp);
        } catch (\InvalidArgumentException) {
            return new Response('', 413);
        } finally {
            @unlink($tmp);
        }
        $fresh = Files::file((int) $f['id']);
        return Response::json(['LastModifiedTime' => Office::modifiedIso($fresh)])->header('X-WOPI-ItemVersion', (string) $saved['version']);
    }

    /** Lock, GetLock, RefreshLock, Unlock (X-WOPI-Override on POST /wopi/files/{id}). */
    public function fileOperation(string $id): Response
    {
        Session::close();
        $c = $this->ctx($id);
        $fid = (int) $c['file']['id'];
        $op = strtoupper($this->req->header('X-WOPI-Override'));
        $want = $this->req->header('X-WOPI-Lock');
        $current = Office::lock($fid);
        $conflict = static fn() => new Response('', 409, ['X-WOPI-Lock' => (string) $current]);
        switch ($op) {
            case 'GET_LOCK':
                return new Response('', 200, ['X-WOPI-Lock' => (string) $current]);
            case 'LOCK':
                if (!$c['write']) {
                    return new Response('', 401);
                }
                $old = $this->req->header('X-WOPI-OldLock');
                if ($old !== '') { // UnlockAndRelock
                    if ($current !== $old) {
                        return $conflict();
                    }
                } elseif ($current !== null && $current !== $want) {
                    return $conflict();
                }
                Office::setLock($fid, $want);
                return new Response('', 200, ['X-WOPI-ItemVersion' => (string) $c['file']['version']]);
            case 'REFRESH_LOCK':
            case 'UNLOCK':
                // Only an editor may keep or release the lock (a viewer could break someone's session).
                if (!$c['write']) {
                    return new Response('', 401);
                }
                if ($current !== $want) {
                    return $conflict();
                }
                if ($op === 'UNLOCK') {
                    Office::unlock($fid);
                    return new Response('', 200, ['X-WOPI-ItemVersion' => (string) $c['file']['version']]);
                }
                Office::setLock($fid, $want);
                return new Response('', 200);
            default:
                // PUT_RELATIVE, RENAME_FILE, DELETE…: not offered (UserCanNotWriteRelative).
                return new Response('', 501);
        }
    }

    // ---------------------------------------------------- built-in text editor

    public function text(string $id): Response
    {
        $user = $this->user();
        $f = Files::requireFile($user, (int) $id, Files::READ);
        if (!in_array(strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)), self::TEXT_EXT, true)) {
            return $this->fail(t('office.text_only'));
        }
        if ((int) $f['size'] > self::TEXT_MAX) {
            return $this->fail(t('office.text_too_big'));
        }
        $s = Files::contents($f['blob_id']);
        if (str_starts_with($s, "\xEF\xBB\xBF")) {
            $s = substr($s, 3);
        }
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = (string) mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        }
        return $this->ok(['file' => Files::fileOut($f), 'content' => $s, 'version' => (int) $f['version'],
            'can_write' => Files::filePermission($user, $f) >= Files::WRITE]);
    }

    public function saveText(string $id): Response
    {
        $user = $this->user();
        $f = Files::requireFile($user, (int) $id, Files::WRITE);
        if ($this->req->int('version') !== (int) $f['version']) {
            // Someone saved in between: never overwrite their work silently.
            $by = (string) \M4W\Core\Database::value('SELECT display_name FROM users WHERE id = :u', ['u' => (int) $f['updated_by']]);
            return $this->fail(t('office.text_conflict', ['name' => $by ?: '—']), 409, ['conflict' => true]);
        }
        $content = $this->req->raw('content');
        if (strlen($content) > self::TEXT_MAX) {
            return $this->fail(t('office.text_too_big'));
        }
        $tmp = (string) tempnam(storage_path('tmp'), 'txt');
        file_put_contents($tmp, $content);
        try {
            $saved = Files::putContent($user, (int) $f['id'], $tmp);
        } catch (\InvalidArgumentException $e) {
            return $this->failWith($e);
        } finally {
            @unlink($tmp);
        }
        return $this->ok(['file' => $saved, 'version' => (int) $saved['version']]);
    }
}
