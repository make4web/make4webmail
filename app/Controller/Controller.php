<?php
declare(strict_types=1);

namespace M4W\Controller;

use M4W\Core\Auth;
use M4W\Core\HttpException;
use M4W\Core\Request;
use M4W\Core\Response;
use M4W\Core\Session;
use M4W\Core\View;

abstract class Controller
{
    public function __construct(protected Request $req)
    {
    }

    protected function user(): array
    {
        $u = Auth::user();
        if (!$u) {
            throw new HttpException(401, t('auth.session_expired'));
        }
        return $u;
    }

    protected function uid(): int
    {
        return (int) $this->user()['id'];
    }

    protected function view(string $tpl, array $data = [], string $layout = 'layouts/app'): Response
    {
        $data['flash'] = Session::pullFlash();
        return Response::html(View::render($tpl, $data, $layout));
    }

    protected function ok(array $data = []): Response
    {
        return Response::json(['ok' => true] + $data);
    }

    protected function fail(string $error, int $status = 422, array $extra = []): Response
    {
        return Response::json(['ok' => false, 'error' => $error] + $extra, $status);
    }

    protected function back(string $to, string $type = '', string $message = ''): Response
    {
        if ($message !== '') {
            Session::flash($type ?: 'success', $message);
        }
        return Response::redirect($to);
    }
}
