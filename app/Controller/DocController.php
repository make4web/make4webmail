<?php
declare(strict_types=1);

namespace M4W\Controller;

use M4W\Core\Audit;
use M4W\Core\Response;
use M4W\Core\Session;
use M4W\Service\DocEdit;
use M4W\Service\Files;

/** Built-in word processor: page, document, pen, drafts, following, saving. */
final class DocController extends Controller
{
    public function page(string $id): Response
    {
        $user = $this->user();
        $f = Files::requireFile($user, (int) $id, Files::READ);
        if (!DocEdit::supports($f['name'])) {
            return Response::redirect('/files#f/' . (int) $f['folder_id']);
        }
        return $this->view('files/doc', ['file' => $f], 'layouts/blank');
    }

    public function data(string $id): Response
    {
        $user = $this->user();
        Session::close();
        $f = Files::requireFile($user, (int) $id, Files::READ);
        try {
            return $this->ok(DocEdit::open($user, $f) + ['me' => (int) $user['id']]);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function take(string $id): Response
    {
        $user = $this->user();
        Session::close();
        $f = Files::requireFile($user, (int) $id, Files::WRITE);
        try {
            $r = DocEdit::take($user, $f);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        if ($r['ok']) {
            Audit::log('files.opened_doc', $f['name'], [], (int) $this->actor()['id']);
        }
        return $this->ok($r);
    }

    public function release(string $id): Response
    {
        $user = $this->user();
        Session::close();
        DocEdit::release($user, (int) $id);
        return $this->ok();
    }

    public function draft(string $id): Response
    {
        $user = $this->user();
        Session::close();
        $f = Files::requireFile($user, (int) $id, Files::WRITE);
        try {
            return $this->ok(['rev' => DocEdit::draft($user, $f, (string) ($this->req->json()['html'] ?? ''))]);
        } catch (\DomainException $e) {
            return $this->fail($e->getMessage(), 409, ['pen_lost' => true]);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function poll(string $id): Response
    {
        $user = $this->user();
        Session::close();
        $f = Files::requireFile($user, (int) $id, Files::READ);
        return $this->ok(DocEdit::poll($user, $f, (int) ($this->req->query['rev'] ?? 0)));
    }

    public function save(string $id): Response
    {
        $user = $this->user();
        Session::close();
        $f = Files::requireFile($user, (int) $id, Files::WRITE);
        try {
            $r = DocEdit::publish($user, $f);
        } catch (\InvalidArgumentException $e) {
            return $this->failWith($e);
        }
        Audit::log('files.doc_saved', $f['name'], ['copy' => $r['copy'] ? $r['file']['name'] : ''], (int) $this->actor()['id']);
        return $this->ok(['file' => Files::fileOut($r['file']), 'copy' => $r['copy'],
            'message' => $r['copy'] ? t('sheet.saved_copy', ['name' => $r['file']['name']]) : t('doc.saved')]);
    }
}
