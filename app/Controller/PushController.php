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

    /** The same test, on every device at once, with how fast each got it (settings page). */
    public function testAll(): Response
    {
        $r = WebPush::testAll((int) $this->actor()['id']);
        return $r['devices'] ? $this->ok($r) : $this->fail(t('push.test_none'));
    }

    public function testStatus(string $id): Response
    {
        $r = WebPush::testStatus((int) $this->actor()['id'], $id);
        return $r ? $this->ok($r) : $this->fail(t('error.not_found'), 404);
    }

    /** From a device's service worker: the test notification is shown (no session needed). */
    public function ack(): Response
    {
        return WebPush::ack($this->req->str('test'), $this->req->str('device'), $this->req->str('stage') ?: 'shown', $this->req->str('state')) ? $this->ok() : $this->fail('', 404);
    }

    public function test(): Response
    {
        $u = $this->actor();
        $n = WebPush::send((int) $u['id'], ['title' => t('push.test_title'), 'body' => t('push.test_body'), 'tag' => 'm4w-test', 'url' => 'mail'], 300);
        return $n > 0 ? $this->ok(['message' => t('push.test_sent', ['n' => $n])]) : $this->fail(t('push.test_none'));
    }

    // ------------------------------------------------------------ the Android app (NativePush)

    /** The app, signed in: this device gets its token (the page hands it to the app). */
    public function nativeRegister(): Response
    {
        $u = $this->user();
        $token = \M4W\Service\NativePush::register((int) $u['id'], $this->req->str('name'), $this->req->str('model'));
        \M4W\Core\Audit::log('native.registered', $this->req->str('model'), [], (int) $u['id']);
        return $this->ok(['token' => $token]);
    }

    /** Signed out on purpose, or turned off: the device gets nothing more. */
    public function nativeUnregister(): Response
    {
        $u = $this->user();
        \M4W\Service\NativePush::unregister((int) $u['id'], $this->req->str('token'), $this->req->int('id'));
        return $this->ok();
    }

    private function nativeDevice(): ?array
    {
        $auth = $this->req->header('authorization');
        return preg_match('/^Bearer\s+([A-Za-z0-9_-]{20,100})$/', $auth, $m) ? \M4W\Service\NativePush::device($m[1]) : null;
    }

    /** The app's long wait for its notifications (no session: its token). */
    public function nativeWait(): Response
    {
        \M4W\Core\Session::close();
        $d = $this->nativeDevice();
        if (!$d) {
            return Response::json(['ok' => false, 'error' => 'unknown device'], 401);
        }
        @set_time_limit(\M4W\Service\NativePush::WAIT + 30);
        $events = \M4W\Service\NativePush::wait($d, $this->req->int('ack'), $this->req->ip(), min(\M4W\Service\NativePush::WAIT, max(0, $this->req->int('wait', \M4W\Service\NativePush::WAIT))));
        return Response::json(['ok' => true, 'events' => $events, 'wait' => \M4W\Service\NativePush::WAIT]);
    }

    /** One of the person's own sounds, for the app to play (kept by it). */
    public function nativeSound(string $id): Response
    {
        \M4W\Core\Session::close();
        $d = $this->nativeDevice();
        $f = $d && ctype_digit($id) ? \M4W\Service\Notify::soundFile((int) $d['user_id'], (int) $id) : null;
        if (!$f) {
            return new Response('', 404);
        }
        return new Response((string) file_get_contents($f['path']), 200, ['Content-Type' => $f['mime'], 'Cache-Control' => 'private, max-age=31536000']);
    }

    /** The Android app itself (android/apk/), to install by hand. */
    public function androidApk(): Response
    {
        $f = M4W_ROOT . '/android/apk/make4webmail.apk';
        if (!is_file($f)) {
            return new Response('', 404);
        }
        return Response::download((string) file_get_contents($f), 'application/vnd.android.package-archive', 'make4webmail.apk');
    }
}
