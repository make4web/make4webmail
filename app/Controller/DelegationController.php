<?php
declare(strict_types=1);

namespace M4W\Controller;

use M4W\Core\Response;
use M4W\Service\Delegation;

final class DelegationController extends Controller
{
    /** Open another mailbox (administrator by default, or named delegate). */
    public function open(): Response
    {
        $actor = $this->actor();
        try {
            $owner = Delegation::open($actor, $this->req->int('user_id'), $this->req->str('reason'), $this->req->ip());
        } catch (\InvalidArgumentException $e) {
            return $this->req->isAjax() ? $this->fail($e->getMessage()) : $this->back($this->backTo(), 'danger', $e->getMessage());
        }
        if ($this->req->isAjax()) {
            return $this->ok(['redirect' => url('mail')]);
        }
        return $this->back('/mail', 'info', t('deleg.opened', ['name' => $owner['name'] ?: $owner['email']]));
    }

    public function close(): Response
    {
        Delegation::close($this->actor());
        return $this->back('/mail', 'success', t('deleg.closed'));
    }

    private function backTo(): string
    {
        $ref = (string) parse_url((string) ($this->req->server['HTTP_REFERER'] ?? ''), PHP_URL_PATH);
        $base = \M4W\Core\Request::basePath();
        if ($base !== '' && str_starts_with($ref, $base)) {
            $ref = substr($ref, strlen($base));
        }
        return AuthController::safePath($ref) ?? '/mail';
    }
}
