<?php
declare(strict_types=1);

namespace M4W\Controller;

use M4W\Core\Audit;
use M4W\Core\Response;
use M4W\Core\Session;
use M4W\Service\Files;
use M4W\Service\Sheet;

/** Built-in spreadsheet: page, workbook, operations, polling, saving. */
final class SheetController extends Controller
{
    public function page(string $id): Response
    {
        $user = $this->user();
        $f = Files::requireFile($user, (int) $id, Files::READ);
        if (!Sheet::supports($f['name'])) {
            return Response::redirect('/files#f/' . (int) $f['folder_id']);
        }
        // Opened over the file space (framed by it) or on its own.
        return $this->view('files/sheet', ['file' => $f, 'user' => $user], 'layouts/blank')->header('X-Frame-Options', 'SAMEORIGIN');
    }

    public function data(string $id): Response
    {
        $user = $this->user();
        Session::close();
        $f = Files::requireFile($user, (int) $id, Files::READ);
        try {
            $o = Sheet::open($user, $f);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        if ($o['can_write']) {
            Audit::log('files.opened_sheet', $f['name'], [], (int) $this->actor()['id']);
        }
        return $this->ok($o + ['me' => ['id' => (int) $user['id'], 'color' => Sheet::color((int) $user['id'])], 'people' => Sheet::people((int) $f['id'], (int) $user['id'])]);
    }

    public function ops(string $id): Response
    {
        $user = $this->user();
        Session::close();
        $f = Files::requireFile($user, (int) $id, Files::WRITE);
        $ops = $this->req->json()['ops'] ?? null;
        if (!is_array($ops)) {
            return $this->fail('Invalid payload');
        }
        try {
            return $this->ok(Sheet::applyOps($user, $f, $ops));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage() === 'op' || strlen($e->getMessage()) < 8 ? t('sheet.bad_op') : $e->getMessage());
        }
    }

    public function poll(string $id): Response
    {
        $user = $this->user();
        Session::close();
        $f = Files::requireFile($user, (int) $id, Files::READ);
        $q = $this->req->query;
        return $this->ok(Sheet::poll($user, $f, (int) ($q['since'] ?? 0), (int) ($q['sheet'] ?? 0), strtoupper((string) ($q['cell'] ?? ''))));
    }

    public function save(string $id): Response
    {
        $user = $this->user();
        Session::close();
        $f = Files::requireFile($user, (int) $id, Files::WRITE);
        try {
            $r = Sheet::publish($user, $f);
        } catch (\InvalidArgumentException $e) {
            return $this->failWith($e);
        }
        Audit::log('files.sheet_saved', $f['name'], ['copy' => $r['copy'] ? $r['file']['name'] : ''], (int) $this->actor()['id']);
        return $this->ok(['file' => Files::fileOut($r['file']), 'copy' => $r['copy'],
            'message' => $r['copy'] ? t('sheet.saved_copy', ['name' => $r['file']['name']]) : t('sheet.saved')]);
    }

    public function leave(string $id): Response
    {
        $user = $this->user();
        Session::close();
        Sheet::leave($user, (int) $id);
        return $this->ok();
    }
}
