<?php
declare(strict_types=1);

namespace M4W\Controller;

use M4W\Core\Audit;
use M4W\Core\Auth;
use M4W\Core\Crypto;
use M4W\Core\Database as DB;
use M4W\Core\I18n;
use M4W\Core\Response;
use M4W\Core\Session;
use M4W\Core\Totp;
use M4W\Service\Users;

final class AuthController extends Controller
{
    public function home(): Response
    {
        return Response::redirect(Auth::user() ? '/mail' : '/login');
    }

    public function lang(string $code): Response
    {
        if (isset(I18n::LANGUAGES[$code])) {
            // Session only: a GET link must not change stored account data (cross-site forgeable).
            // Signed-in users change their language in Settings (POST + CSRF).
            Session::set('lang', $code);
        }
        $back = (string) ($this->req->query['back'] ?? '/login');
        return Response::redirect(self::safePath($back) ?? '/login');
    }

    /** Local path only: no scheme, host, backslash or control characters. */
    public static function safePath(string $p): ?string
    {
        if (!preg_match('#^/(?![/\\\\])[^\\\\\x00-\x1f\x7f]*$#', $p) || parse_url($p, PHP_URL_HOST) !== null) {
            return null;
        }
        return $p;
    }

    public function loginForm(): Response
    {
        if (Auth::user()) {
            return Response::redirect('/mail');
        }
        return $this->view('auth/login', ['email' => (string) ($this->req->query['email'] ?? '')], 'layouts/auth');
    }

    public function login(): Response
    {
        $email = $this->req->str('email');
        $res = Auth::attempt($email, $this->req->raw('password'), $this->req);
        if (!$res['ok']) {
            Session::flash('danger', $res['error']);
            return Response::redirect('/login?email=' . rawurlencode($email));
        }
        $user = $res['user'];
        if ($user['totp_enabled']) {
            Session::regenerate();
            Session::set('pending_2fa', ['uid' => (int) $user['id'], 'at' => time(), 'tries' => 0]);
            return Response::redirect('/login/2fa');
        }
        return $this->complete($user);
    }

    private function complete(array $user): Response
    {
        Auth::login($user, $this->req);
        $intended = (string) Session::get('intended', '/mail');
        Session::forget('intended');
        if (self::safePath($intended) === null || str_starts_with($intended, '/api/')) {
            $intended = '/mail';
        }
        return Response::redirect($intended);
    }

    public function twoFactorForm(): Response
    {
        $p = Session::get('pending_2fa');
        if (!is_array($p) || time() - $p['at'] > 300) {
            Session::forget('pending_2fa');
            return Response::redirect('/login');
        }
        return $this->view('auth/2fa', [], 'layouts/auth');
    }

    public function twoFactor(): Response
    {
        $p = Session::get('pending_2fa');
        if (!is_array($p) || time() - $p['at'] > 300 || $p['tries'] >= 5) {
            Session::forget('pending_2fa');
            Session::flash('danger', t('auth.2fa_expired'));
            return Response::redirect('/login');
        }
        $user = Users::find((int) $p['uid']);
        $window = max(1, (int) \M4W\Core\Settings::get('security.lockout_minutes', 15)) * 60;
        $max = max(3, (int) \M4W\Core\Settings::get('security.max_attempts', 5));
        if ($user && (int) DB::value('SELECT COUNT(*) FROM login_attempts WHERE email = :e AND success = 0 AND created_at > :t',
            ['e' => $user['email'], 't' => time() - $window]) >= $max) {
            Session::forget('pending_2fa');
            Session::flash('danger', t('auth.locked', ['minutes' => (int) ($window / 60)]));
            return Response::redirect('/login');
        }
        $secret = $user ? Crypto::decrypt((string) $user['totp_secret']) : null;
        $code = $this->req->str('recovery') !== '' ? $this->req->str('recovery') : $this->req->str('code');
        $prefsRaw = $user ? (json_decode((string) DB::value('SELECT prefs FROM users WHERE id = :id', ['id' => $user['id']]), true) ?: []) : [];
        $step = $secret ? Totp::match($secret, $code, 1, (int) ($prefsRaw['_totp_step'] ?? 0)) : null;
        $ok = $step !== null;
        if ($ok) {
            $prefsRaw['_totp_step'] = $step;
            DB::update('users', ['prefs' => json_encode($prefsRaw)], 'id = :id', ['id' => $user['id']]);
        }
        // Recovery codes
        if (!$ok && $user) {
            $ok = $this->useRecoveryCode($user, $code);
        }
        if (!$ok) {
            $p['tries']++;
            Session::set('pending_2fa', $p);
            if ($user) {
                DB::insert('login_attempts', ['ip' => $this->req->ip(), 'email' => $user['email'], 'success' => 0, 'created_at' => time()]);
            }
            Audit::log('login.2fa_failed', $user['email'] ?? '', [], $user['id'] ?? null);
            Session::flash('danger', t('auth.2fa_invalid'));
            return Response::redirect('/login/2fa');
        }
        return $this->complete($user);
    }

    private function useRecoveryCode(array $user, string $code): bool
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
        if (strlen($code) !== 10) {
            return false;
        }
        $stored = json_decode((string) (DB::value('SELECT prefs FROM users WHERE id = :id', ['id' => $user['id']]) ?? '{}'), true) ?: [];
        $hashes = $stored['_recovery'] ?? [];
        foreach ($hashes as $i => $h) {
            if (hash_equals($h, hash('sha256', $code))) {
                unset($hashes[$i]);
                $stored['_recovery'] = array_values($hashes);
                DB::update('users', ['prefs' => json_encode($stored)], 'id = :id', ['id' => $user['id']]);
                Audit::log('login.recovery_code', $user['email'], [], (int) $user['id']);
                return true;
            }
        }
        return false;
    }

    public function logout(): Response
    {
        Audit::log('logout');
        Auth::logout();
        return Response::redirect('/login');
    }

    public function changeForm(): Response
    {
        return $this->view('auth/change', [], 'layouts/auth');
    }

    public function change(): Response
    {
        $u = $this->user();
        $pw = $this->req->raw('password');
        if (!Crypto::verifyPassword($this->req->raw('current'), $u['password_hash'])) {
            return $this->back('/password/change', 'danger', t('pw.current_invalid'));
        }
        if ($err = Auth::passwordPolicyError($pw)) {
            return $this->back('/password/change', 'danger', $err);
        }
        if ($pw !== $this->req->raw('password2')) {
            return $this->back('/password/change', 'danger', t('pw.mismatch'));
        }
        if (Crypto::verifyPassword($pw, $u['password_hash'])) {
            return $this->back('/password/change', 'danger', t('pw.same'));
        }
        Users::update((int) $u['id'], ['password' => $pw, 'must_change_password' => 0]);
        Auth::revokeOtherSessions((int) $u['id'], (int) $u['_session_id']);
        Audit::log('password.changed');
        return $this->back('/mail', 'success', t('pw.changed'));
    }
}
