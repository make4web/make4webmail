<?php
declare(strict_types=1);

namespace M4W\Controller;

use M4W\Core\Response;
use M4W\Core\Session;
use M4W\Core\Settings;
use M4W\Service\Offline;

/** Offline mode: the device's key, what to keep, signing a device out (see Offline). */
final class OfflineController extends Controller
{
    protected function delegable(): bool
    {
        return true;
    }

    /** Offline mode for this mailbox: only one's own, and when the administrator allows it. */
    private function allowed(): bool
    {
        $u = $this->user();
        return Offline::enabled() && empty($u['_actor']) && empty($u['_shared']);
    }

    /**
     * The device's key, after signing in (the same as before on this device: work kept offline
     * becomes readable again), with the session limits the device applies when offline.
     */
    public function key(): Response
    {
        if (!$this->allowed()) {
            return $this->ok(['enabled' => false]);
        }
        $actor = $this->actor();
        try {
            $key = Offline::key((int) $actor['id'], (int) ($actor['_session_id'] ?? 0), $this->req->str('device'), $this->req->userAgent());
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        $session = \M4W\Core\Database::one('SELECT created_at FROM user_sessions WHERE id = :id', ['id' => (int) ($actor['_session_id'] ?? 0)]);
        Session::close();
        return $this->ok([
            'enabled' => true,
            'key' => rtrim(strtr(base64_encode($key), '+/', '-_'), '='),
            'user' => (int) $actor['id'],
            'session' => [
                'login_at' => (int) ($session['created_at'] ?? time()),
                'idle' => max(5, (int) Settings::get('security.session_idle', 30)) * 60,
                'max' => max(30, (int) Settings::get('security.session_absolute', 720)) * 60,
            ],
            'manifest' => Offline::manifest((int) $actor['id']),
        ]);
    }

    public function manifest(): Response
    {
        if (!$this->allowed()) {
            return $this->ok(['enabled' => false]);
        }
        $uid = (int) $this->actor()['id'];
        Session::close();
        return $this->ok(['enabled' => true] + Offline::manifest($uid));
    }

    /** Signing out on this device: its key is deleted, what it kept can never be read again. */
    public function forget(): Response
    {
        $n = Offline::forget((int) $this->actor()['id'], $this->req->str('device'));
        return $this->ok(['forgotten' => $n]);
    }
}
