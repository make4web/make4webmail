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

    /**
     * Mailbox owner of the request: the signed-in user, or the mailbox they opened
     * through delegation when this controller action works on mailbox data.
     */
    protected function user(): array
    {
        $actor = $this->actor();
        if ($this->delegable()) {
            $owner = \M4W\Service\Delegation::mailbox($actor);
            if ($owner !== $actor) {
                // Every change made in a delegated mailbox is traced to the person who made it.
                static $logged = false;
                if (!$logged && !in_array($this->req->method, ['GET', 'HEAD'], true)) {
                    $logged = true;
                    \M4W\Core\Audit::log('delegation.action', $owner['email'], ['path' => $this->req->path], (int) $actor['id']);
                }
                return $owner;
            }
        }
        return $actor;
    }

    /** The person actually signed in (security, profile, administration, files). */
    protected function actor(): array
    {
        $u = Auth::user();
        if (!$u) {
            throw new HttpException(401, t('auth.session_expired'));
        }
        return $u;
    }

    /** Whether this action works on mailbox data and follows an opened delegation. */
    protected function delegable(): bool
    {
        return false;
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
