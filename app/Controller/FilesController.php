<?php
declare(strict_types=1);

namespace M4W\Controller;

use M4W\Core\HttpException;
use M4W\Core\Response;
use M4W\Core\Session;
use M4W\Service\Files;
use M4W\Service\Mailbox;

final class FilesController extends Controller
{
    /** Types a browser may render inline without running anything. */
    private const INLINE = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp', 'application/pdf', 'text/plain', 'audio/mpeg', 'audio/ogg', 'audio/wav', 'video/mp4', 'video/webm'];

    public function index(): Response
    {
        return $this->view('files/app', ['maxFile' => Files::maxFileBytes()]);
    }

    public function roots(): Response
    {
        return $this->ok(Files::roots($this->user()));
    }

    public function folder(string $id): Response
    {
        return $this->ok(Files::listing($this->user(), (int) $id));
    }

    public function tree(): Response
    {
        return $this->ok(['items' => Files::writableTree($this->user())]);
    }

    public function search(): Response
    {
        return $this->ok(Files::search($this->user(), (string) ($this->req->query['q'] ?? '')));
    }

    public function trash(): Response
    {
        return $this->ok(['items' => Files::trash($this->user())]);
    }

    public function createFolder(): Response
    {
        try {
            $id = Files::createFolder($this->user(), $this->req->int('parent_id'), $this->req->str('name'));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->ok(['id' => $id]);
    }

    public function createSpace(): Response
    {
        try {
            $id = Files::createSpace($this->user(), $this->req->str('name'), $this->req->str('description'));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->ok(['id' => $id]);
    }

    public function updateSpace(string $id): Response
    {
        try {
            Files::updateSpace($this->user(), (int) $id, $this->req->str('name'), $this->req->str('description'));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->ok();
    }

    public function upload(): Response
    {
        $user = $this->user();
        $file = $this->req->files['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $tooBig = is_array($file) && in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            return $this->fail($tooBig ? t('files.too_big', ['mb' => (int) (Files::maxFileBytes() / 1048576)]) : t('upload.failed'));
        }
        try {
            $folder = $this->req->int('folder_id');
            $rel = trim($this->req->str('relpath'), '/');
            if ($rel !== '' && str_contains($rel, '/')) {
                $folder = Files::ensurePath($user, $folder, dirname($rel));
            }
            $out = Files::addFromPath($user, $folder, $file['tmp_name'], (string) $file['name'], $this->req->bool('replace'));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        } finally {
            @unlink($file['tmp_name']);
        }
        return $this->ok(['file' => $out]);
    }

    public function rename(): Response
    {
        try {
            $name = Files::rename($this->user(), $this->req->str('type'), $this->req->int('id'), $this->req->str('name'));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->ok(['name' => $name]);
    }

    public function move(): Response
    {
        try {
            $n = Files::move($this->user(), $this->req->arr('items'), $this->req->int('target'));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->ok(['count' => $n]);
    }

    public function copy(): Response
    {
        try {
            $n = Files::copy($this->user(), $this->req->arr('items'), $this->req->int('target'));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->ok(['count' => $n]);
    }

    public function delete(): Response
    {
        return $this->ok(['count' => Files::delete($this->user(), $this->req->arr('items'))]);
    }

    public function restore(): Response
    {
        return $this->ok(['count' => Files::restore($this->user(), $this->req->arr('items'))]);
    }

    public function purge(): Response
    {
        return $this->ok(['count' => Files::purge($this->user(), $this->req->arr('items'))]);
    }

    public function acl(string $id): Response
    {
        return $this->ok(Files::acl($this->user(), (int) $id));
    }

    public function saveAcl(string $id): Response
    {
        Files::setAcl($this->user(), (int) $id, $this->req->arr('entries'));
        return $this->ok(Files::acl($this->user(), (int) $id));
    }

    public function principals(): Response
    {
        Session::close();
        return $this->ok(['items' => Files::principalsSearch((string) ($this->req->query['q'] ?? ''))]);
    }

    public function versions(string $id): Response
    {
        return $this->ok(['items' => Files::versions($this->user(), (int) $id)]);
    }

    public function restoreVersion(string $id): Response
    {
        try {
            Files::restoreVersion($this->user(), (int) $id, $this->req->int('version_id'));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->ok();
    }

    public function download(string $id): Response
    {
        $user = $this->user();
        Session::close();
        $f = Files::requireFile($user, (int) $id, Files::READ);
        $blob = $f['blob_id'];
        $size = (int) $f['size'];
        $name = $f['name'];
        $mime = $f['mime'];
        $vid = (int) ($this->req->query['version'] ?? 0);
        if ($vid) {
            $v = \M4W\Core\Database::one('SELECT * FROM fs_versions WHERE id = :v AND file_id = :f', ['v' => $vid, 'f' => $f['id']]);
            if (!$v) {
                throw new HttpException(404, t('files.not_found'));
            }
            [$blob, $size, $mime] = [$v['blob_id'], (int) $v['size'], $v['mime']];
        }
        $inline = !empty($this->req->query['inline']) && in_array($mime, self::INLINE, true);
        $type = $inline ? ($mime === 'text/plain' ? 'text/plain; charset=utf-8' : $mime) : 'application/octet-stream';
        $r = new Response('', 200, [
            'Content-Type' => $type,
            'Content-Length' => (string) $size,
            'Content-Disposition' => Response::disposition($inline ? 'inline' : 'attachment', $name),
            'Cache-Control' => 'private, max-age=0, no-store',
            // Nothing served from the file space may run script or be framed elsewhere.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'; sandbox",
            'X-Frame-Options' => 'SAMEORIGIN',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ], static function () use ($blob) {
            Files::stream($blob, static function (string $buf) {
                echo $buf;
                flush();
            });
        });
        return $r;
    }

    /** ZIP of a folder (and sub-folders) the user can read. */
    public function zip(string $id): Response
    {
        $user = $this->user();
        Session::close();
        $root = Files::require($user, (int) $id, Files::READ);
        if (!class_exists(\ZipArchive::class)) {
            throw new HttpException(500, 'ZIP extension missing');
        }
        $tmp = tempnam(storage_path('tmp'), 'zip');
        $temps = [];
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $total = 0;
        $limit = 2 * 1024 * 1024 * 1024;
        $walk = function (int $folderId, string $prefix, int $depth) use (&$walk, $zip, &$temps, &$total, $limit, $user) {
            if ($depth > 30) {
                return;
            }
            foreach (\M4W\Core\Database::all('SELECT * FROM fs_files WHERE folder_id = :f AND deleted_at = 0', ['f' => $folderId]) as $f) {
                $total += (int) $f['size'];
                if ($total > $limit) {
                    throw new HttpException(413, t('files.zip_too_big'));
                }
                $temps[] = $path = Files::toTempFile($f['blob_id']);
                $zip->addFile($path, $prefix . $f['name']);
            }
            foreach (\M4W\Core\Database::all('SELECT * FROM fs_folders WHERE parent_id = :f AND deleted_at = 0', ['f' => $folderId]) as $sub) {
                if (Files::permission($user, (int) $sub['id']) >= Files::READ) {
                    $zip->addEmptyDir($prefix . $sub['name']);
                    $walk((int) $sub['id'], $prefix . $sub['name'] . '/', $depth + 1);
                }
            }
        };
        try {
            $walk((int) $root['id'], '', 0);
            if ($zip->numFiles === 0) {
                $zip->addFromString('.keep', '');
            }
            $zip->close();
        } finally {
            foreach ($temps as $t) {
                @unlink($t);
            }
        }
        $name = ($root['kind'] === 'personal' ? t('files.my_files') : $root['name']) . '.zip';
        $r = Response::file($tmp, 'application/zip', $name);
        register_shutdown_function(static fn() => @unlink($tmp));
        return $r;
    }

    /** Compose: turn shared files into attachments of the message being written. */
    public function attach(): Response
    {
        try {
            $actor = $this->user();
            $mailbox = \M4W\Service\Delegation::mailbox($actor);
            $items = Files::toUploads($actor, $this->req->arr('ids'), (int) $mailbox['id']);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        foreach ($items as &$it) {
            $it['url'] = url('api/upload/' . $it['token']);
        }
        return $this->ok(['files' => $items]);
    }

    /** Reader: save one (or all) attachments of a message to a folder. */
    public function saveAttachment(string $id): Response
    {
        $user = $this->user();
        $mailbox = \M4W\Service\Delegation::mailbox($user);
        $m = Mailbox::get((int) $mailbox['id'], (int) $id);
        if (!$m) {
            throw new HttpException(404, t('mail.not_found'));
        }
        $parsed = Mailbox::parsed($m);
        $folder = $this->req->int('folder_id');
        $parts = $this->req->arr('parts');
        $saved = [];
        try {
            foreach (array_slice($parts, 0, 50) as $partId) {
                $p = $parsed->findPart((string) $partId);
                if (!$p || $p->isMultipart()) {
                    continue;
                }
                $saved[] = Files::addFromString($user, $folder, $p->decodedBody(), $p->filename() ?: 'piece-jointe', $p->type);
            }
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        if (!$saved) {
            return $this->fail(t('mail.not_found'), 404);
        }
        return $this->ok(['files' => $saved]);
    }
}
