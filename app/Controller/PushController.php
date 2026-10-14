<?php
declare(strict_types=1);

namespace M4W\Controller;

use M4W\Core\Csrf;
use M4W\Core\Response;
use M4W\Service\WebPush;

/** Push notifications of the signed-in person (never of a delegated mailbox). */
final class PushController extends Controller
{
    public function key(): Response
    {
        return $this->ok(['enabled' => WebPush::enabled(), 'key' => WebPush::enabled() ? WebPush::vapid()['public'] : '', 'csrf' => Csrf::token()]);
    }

    public function subscribe(): Response
    {
        $sub = $this->req->arr('subscription');
        try {
            WebPush::subscribe((int) $this->actor()['id'], (string) ($sub['endpoint'] ?? ''), (string) ($sub['keys']['p256dh'] ?? ''), (string) ($sub['keys']['auth'] ?? ''), $this->req->userAgent());
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->ok(['devices' => count(WebPush::devices((int) $this->actor()['id']))]);
    }

    /** Called by the service worker when the browser rotates the subscription. */
    public function renew(): Response
    {
        $old = $this->req->str('old');
        if ($old !== '') {
            WebPush::unsubscribe((int) $this->actor()['id'], $old);
        }
        return $this->subscribe();
    }

    public function unsubscribe(): Response
    {
        $uid = (int) $this->actor()['id'];
        $id = $this->req->int('id');
        $id ? WebPush::unsubscribe($uid, null, $id) : WebPush::unsubscribe($uid, $this->req->str('endpoint'));
        return $this->ok();
    }

    public function test(): Response
    {
        $u = $this->actor();
        $n = WebPush::send((int) $u['id'], ['title' => t('push.test_title'), 'body' => t('push.test_body'), 'tag' => 'm4w-test', 'url' => 'mail'], 300);
        return $n > 0 ? $this->ok(['message' => t('push.test_sent', ['n' => $n])]) : $this->fail(t('push.test_none'));
    }
}
