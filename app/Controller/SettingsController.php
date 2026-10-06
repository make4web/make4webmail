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
use M4W\Core\Settings;
use M4W\Core\Totp;
use M4W\Mail\HtmlSanitizer;
use M4W\Service\Fetcher;
use M4W\Service\Folders;
use M4W\Service\Forwarding;
use M4W\Service\RuleEngine;
use M4W\Service\Signatures;
use M4W\Service\Users;
use M4W\Service\Vacation;

final class SettingsController extends Controller
{
    private function page(string $tpl, array $data = []): Response
    {
        return $this->view('settings/' . $tpl, $data + ['section' => $tpl], 'layouts/app');
    }

    public function general(): Response
    {
        return $this->page('general', ['languages' => I18n::LANGUAGES]);
    }

    public function saveGeneral(): Response
    {
        $u = $this->user();
        $r = $this->req;
        $profile = [
            'display_name' => mb_substr($r->str('display_name'), 0, 190),
            'first_name' => mb_substr($r->str('first_name'), 0, 100),
            'last_name' => mb_substr($r->str('last_name'), 0, 100),
            'phone' => mb_substr($r->str('phone'), 0, 60),
            'mobile' => mb_substr($r->str('mobile'), 0, 60),
            'language' => isset(I18n::LANGUAGES[$r->str('language')]) ? $r->str('language') : 'fr',
        ];
        // Job title / department are managed by the administrator (they feed the corporate signature).
        Users::update((int) $u['id'], $profile);
        Users::savePrefs((int) $u['id'], [
            'theme' => in_array($r->str('theme'), ['light', 'dark', 'auto'], true) ? $r->str('theme') : 'auto',
            'density' => $r->str('density') === 'compact' ? 'compact' : 'comfortable',
            'reading_pane' => in_array($r->str('reading_pane'), ['right', 'bottom', 'off'], true) ? $r->str('reading_pane') : 'right',
            'page_size' => max(20, min(200, $r->int('page_size', 50))),
            'conversations' => $r->bool('conversations') ? 1 : 0,
            'show_images' => $r->bool('show_images') ? 1 : 0,
            'mark_read_delay' => max(-1, min(30, $r->int('mark_read_delay', 1))),
            'shortcuts' => $r->bool('shortcuts') ? 1 : 0,
            'notifications' => $r->bool('notifications') ? 1 : 0,
            'undo_send' => $r->bool('undo_send') ? 1 : 0,
            'compose_font' => in_array($r->str('compose_font'), array_keys(self::fonts()), true) ? $r->str('compose_font') : 'Arial, Helvetica, sans-serif',
            'compose_size' => in_array($r->str('compose_size'), ['12px', '13px', '14px', '16px', '18px'], true) ? $r->str('compose_size') : '14px',
        ]);
        return $this->back('/settings', 'success', t('settings.saved'));
    }

    public static function fonts(): array
    {
        return [
            'Arial, Helvetica, sans-serif' => 'Arial', 'Calibri, Carlito, sans-serif' => 'Calibri',
            'Georgia, serif' => 'Georgia', 'Tahoma, Geneva, sans-serif' => 'Tahoma',
            '"Times New Roman", Times, serif' => 'Times New Roman', 'Verdana, Geneva, sans-serif' => 'Verdana',
            '"Courier New", Courier, monospace' => 'Courier New',
        ];
    }

    // ---- Rules -------------------------------------------------------------

    public function rules(): Response
    {
        $uid = $this->uid();
        return $this->page('rules', [
            'rules' => RuleEngine::forUser($uid),
            'folders' => Folders::listWithCounts($uid),
        ]);
    }

    public function saveRule(): Response
    {
        $uid = $this->uid();
        $in = json_decode($this->req->raw('rule'), true);
        if (!is_array($in)) {
            return $this->fail('Invalid payload');
        }
        try {
            $data = RuleEngine::normalize($uid, $in);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        $id = (int) ($in['id'] ?? 0);
        $data['updated_at'] = time();
        if ($id && DB::value('SELECT COUNT(*) FROM rules WHERE id = :id AND user_id = :u', ['id' => $id, 'u' => $uid])) {
            DB::update('rules', $data, 'id = :id AND user_id = :u', ['id' => $id, 'u' => $uid]);
        } else {
            $data['sort'] = (int) DB::value('SELECT COALESCE(MAX(sort),0) + 10 FROM rules WHERE user_id = :u', ['u' => $uid]);
            $data['created_at'] = time();
            $data['user_id'] = $uid;
            $id = DB::insert('rules', $data);
        }
        $applied = 0;
        if (!empty($in['apply_now'])) {
            $rule = array_values(array_filter(RuleEngine::forUser($uid), static fn($r) => (int) $r['id'] === $id))[0] ?? null;
            if ($rule) {
                $applied = RuleEngine::applyToFolder($uid, $rule, (int) Folders::byRole($uid, 'inbox')['id']);
            }
        }
        Audit::log('rule.saved', $data['name']);
        return $this->ok(['id' => $id, 'applied' => $applied]);
    }

    public function deleteRule(): Response
    {
        DB::delete('rules', 'id = :id AND user_id = :u', ['id' => $this->req->int('id'), 'u' => $this->uid()]);
        return $this->ok();
    }

    public function toggleRule(): Response
    {
        DB::update('rules', ['enabled' => $this->req->bool('enabled') ? 1 : 0], 'id = :id AND user_id = :u', ['id' => $this->req->int('id'), 'u' => $this->uid()]);
        return $this->ok();
    }

    public function orderRules(): Response
    {
        $uid = $this->uid();
        foreach ($this->req->arr('ids') as $i => $id) {
            DB::update('rules', ['sort' => ($i + 1) * 10], 'id = :id AND user_id = :u', ['id' => (int) $id, 'u' => $uid]);
        }
        return $this->ok();
    }

    public function runRule(): Response
    {
        $uid = $this->uid();
        $id = $this->req->int('id');
        $rule = array_values(array_filter(RuleEngine::forUser($uid), static fn($r) => (int) $r['id'] === $id))[0] ?? null;
        if (!$rule) {
            return $this->fail(t('mail.not_found'));
        }
        try {
            $n = RuleEngine::applyToFolder($uid, $rule, $this->req->int('folder') ?: (int) Folders::byRole($uid, 'inbox')['id']);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->ok(['applied' => $n, 'message' => t('rules.applied', ['n' => $n])]);
    }

    // ---- Vacation ----------------------------------------------------------

    public function vacation(): Response
    {
        return $this->page('vacation', ['v' => Vacation::get($this->uid())]);
    }

    public function saveVacation(): Response
    {
        $r = $this->req;
        $parse = static function (string $d, string $t, bool $end) {
            if ($d === '') {
                return 0;
            }
            $ts = strtotime($d . ' ' . ($t !== '' ? $t : ($end ? '23:59' : '00:00')));
            return $ts ?: 0;
        };
        try {
            Vacation::save($this->uid(), [
                'enabled' => $r->bool('enabled'),
                'start_at' => $parse($r->str('start_date'), $r->str('start_time'), false),
                'end_at' => $parse($r->str('end_date'), $r->str('end_time'), true),
                'subject' => $r->str('subject'),
                'body_html' => $r->raw('body_html'),
                'interval_days' => $r->int('interval_days', 4),
                'only_contacts' => $r->bool('only_contacts'),
                'internal_only' => $r->bool('internal_only'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->back('/settings/vacation', 'danger', $e->getMessage());
        }
        Audit::log('vacation.saved', $r->bool('enabled') ? 'on' : 'off');
        return $this->back('/settings/vacation', 'success', t('settings.saved'));
    }

    // ---- Forwarding --------------------------------------------------------

    public function forwarding(): Response
    {
        return $this->page('forwarding', ['f' => Forwarding::get($this->uid())]);
    }

    public function saveForwarding(): Response
    {
        $r = $this->req;
        try {
            Forwarding::save($this->uid(), $r->bool('enabled'), preg_split('/[\s,;]+/', $r->str('addresses')) ?: [], $r->str('keep_copy', '1') === '1');
        } catch (\InvalidArgumentException $e) {
            return $this->back('/settings/forwarding', 'danger', $e->getMessage());
        }
        Audit::log('forwarding.saved', $r->bool('enabled') ? $r->str('addresses') : 'off');
        return $this->back('/settings/forwarding', 'success', t('settings.saved'));
    }

    // ---- Signature ---------------------------------------------------------

    public function signature(): Response
    {
        $u = $this->user();
        return $this->page('signature', [
            'preview' => Signatures::render($u)['html'],
            'template' => Signatures::forUser($u),
            'allowPersonal' => (bool) (int) Settings::get('features.user_signature', 0),
        ]);
    }

    public function saveSignature(): Response
    {
        if (!(int) Settings::get('features.user_signature', 0)) {
            return $this->back('/settings/signature', 'danger', t('error.forbidden'));
        }
        $html = (new HtmlSanitizer(false))->sanitize(mb_substr($this->req->raw('personal_signature'), 0, 20000));
        Users::update($this->uid(), ['personal_signature' => $html]);
        return $this->back('/settings/signature', 'success', t('settings.saved'));
    }

    // ---- Security ----------------------------------------------------------

    public function security(): Response
    {
        $u = $this->user();
        $sessions = DB::all('SELECT * FROM user_sessions WHERE user_id = :u AND revoked = 0 AND last_seen_at > :t ORDER BY last_seen_at DESC LIMIT 20',
            ['u' => $u['id'], 't' => time() - 86400 * 30]);
        $logins = DB::all("SELECT * FROM audit_log WHERE user_id = :u AND action LIKE 'login.%' ORDER BY id DESC LIMIT 15", ['u' => $u['id']]);
        $pending = Session::get('totp_setup');
        $recovery = Session::get('recovery_codes_once');
        Session::forget('recovery_codes_once');
        return $this->page('security', [
            'sessions' => $sessions, 'logins' => $logins, 'currentSession' => (int) $u['_session_id'],
            'totpSetup' => is_string($pending) ? ['secret' => $pending, 'uri' => Totp::uri($pending, $u['email'], (string) Settings::get('brand.name'))] : null,
            'recovery' => $recovery,
        ]);
    }

    public function changePassword(): Response
    {
        $u = $this->user();
        $pw = $this->req->raw('password');
        if (!Crypto::verifyPassword($this->req->raw('current'), $u['password_hash'])) {
            return $this->back('/settings/security', 'danger', t('pw.current_invalid'));
        }
        if ($err = Auth::passwordPolicyError($pw)) {
            return $this->back('/settings/security', 'danger', $err);
        }
        if ($pw !== $this->req->raw('password2')) {
            return $this->back('/settings/security', 'danger', t('pw.mismatch'));
        }
        Users::update((int) $u['id'], ['password' => $pw, 'must_change_password' => 0]);
        Auth::revokeOtherSessions((int) $u['id'], (int) $u['_session_id']);
        Audit::log('password.changed');
        return $this->back('/settings/security', 'success', t('pw.changed'));
    }

    public function start2fa(): Response
    {
        Session::set('totp_setup', Totp::generateSecret());
        return Response::redirect('/settings/security#2fa');
    }

    public function enable2fa(): Response
    {
        $u = $this->user();
        $secret = Session::get('totp_setup');
        if (!is_string($secret) || !Totp::verify($secret, $this->req->str('code'))) {
            return $this->back('/settings/security#2fa', 'danger', t('auth.2fa_invalid'));
        }
        $codes = [];
        $hashes = [];
        for ($i = 0; $i < 8; $i++) {
            $c = strtoupper(substr(Totp::base32Encode(random_bytes(8)), 0, 10));
            $codes[] = substr($c, 0, 5) . '-' . substr($c, 5);
            $hashes[] = hash('sha256', $c);
        }
        $prefs = json_decode((string) DB::value('SELECT prefs FROM users WHERE id = :id', ['id' => $u['id']]), true) ?: [];
        $prefs['_recovery'] = $hashes;
        DB::update('users', ['totp_secret' => Crypto::encrypt($secret), 'totp_enabled' => 1, 'prefs' => json_encode($prefs)], 'id = :id', ['id' => $u['id']]);
        Session::forget('totp_setup');
        Session::set('recovery_codes_once', $codes);
        Audit::log('2fa.enabled');
        return $this->back('/settings/security#2fa', 'success', t('2fa.enabled'));
    }

    public function disable2fa(): Response
    {
        $u = $this->user();
        if (!Crypto::verifyPassword($this->req->raw('current'), $u['password_hash'])) {
            return $this->back('/settings/security#2fa', 'danger', t('pw.current_invalid'));
        }
        // Both factors are required to remove the second one.
        $secret = Crypto::decrypt((string) $u['totp_secret']);
        if (!$secret || !Totp::verify($secret, $this->req->str('code'))) {
            return $this->back('/settings/security#2fa', 'danger', t('auth.2fa_invalid'));
        }
        if ($u['role'] === 'admin' && (int) Settings::get('security.enforce_2fa_admin', 0)) {
            return $this->back('/settings/security#2fa', 'danger', t('2fa.required_admin'));
        }
        DB::update('users', ['totp_secret' => '', 'totp_enabled' => 0], 'id = :id', ['id' => $u['id']]);
        Audit::log('2fa.disabled');
        return $this->back('/settings/security#2fa', 'success', t('2fa.disabled'));
    }

    public function revokeSessions(): Response
    {
        $u = $this->user();
        $id = $this->req->int('id');
        if ($id) {
            DB::update('user_sessions', ['revoked' => 1], 'id = :id AND user_id = :u', ['id' => $id, 'u' => $u['id']]);
        } else {
            Auth::revokeOtherSessions((int) $u['id'], (int) $u['_session_id']);
        }
        Audit::log('sessions.revoked');
        return $this->back('/settings/security', 'success', t('sessions.revoked'));
    }

    // ---- External accounts -------------------------------------------------

    public function accounts(): Response
    {
        if (!(int) Settings::get('features.fetch_accounts', 1)) {
            return Response::redirect('/settings');
        }
        return $this->page('accounts', ['accounts' => Fetcher::forUser($this->uid())]);
    }

    public function saveAccount(): Response
    {
        if (!(int) Settings::get('features.fetch_accounts', 1)) {
            return Response::redirect('/settings');
        }
        try {
            Fetcher::save($this->uid(), $this->req->post, $this->req->int('id') ?: null);
        } catch (\InvalidArgumentException $e) {
            return $this->back('/settings/accounts', 'danger', $e->getMessage());
        }
        return $this->back('/settings/accounts', 'success', t('settings.saved'));
    }

    public function deleteAccount(): Response
    {
        DB::delete('fetch_accounts', 'id = :id AND user_id = :u', ['id' => $this->req->int('id'), 'u' => $this->uid()]);
        return $this->back('/settings/accounts', 'success', t('settings.saved'));
    }

    public function runAccount(): Response
    {
        $acc = DB::one('SELECT * FROM fetch_accounts WHERE id = :id AND user_id = :u', ['id' => $this->req->int('id'), 'u' => $this->uid()]);
        if (!$acc) {
            return $this->back('/settings/accounts');
        }
        Session::close();
        $res = Fetcher::run($acc);
        Session::start($this->req);
        if ($res['error'] !== '') {
            return $this->back('/settings/accounts', 'danger', $res['error']);
        }
        return $this->back('/settings/accounts', 'success', t('fetch.done', ['n' => $res['fetched']]));
    }

    public function folders(): Response
    {
        return $this->page('folders', ['folders' => Folders::listWithCounts($this->uid())]);
    }
}
