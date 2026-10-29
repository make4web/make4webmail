<?php
declare(strict_types=1);

namespace M4W\Controller;

use M4W\Core\Audit;
use M4W\Core\Auth;
use M4W\Core\Database as DB;
use M4W\Core\HttpException;
use M4W\Core\I18n;
use M4W\Core\Response;
use M4W\Core\Settings;
use M4W\Mail\DkimSigner;
use M4W\Mail\MimeBuilder;
use M4W\Service\Branding;
use M4W\Service\Signatures;
use M4W\Service\Transport;
use M4W\Service\Users;

final class AdminController extends Controller
{
    private function page(string $tpl, array $data = []): Response
    {
        return $this->view('admin/' . $tpl, $data + ['section' => $tpl], 'layouts/app');
    }

    public function dashboard(): Response
    {
        $since = time() - 86400;
        $stats = [
            'users'      => (int) DB::value('SELECT COUNT(*) FROM users'),
            'active'     => (int) DB::value("SELECT COUNT(*) FROM users WHERE status = 'active'"),
            'domains'    => (int) DB::value('SELECT COUNT(*) FROM domains'),
            'messages'   => (int) DB::value('SELECT COUNT(*) FROM messages'),
            'storage'    => (int) DB::value('SELECT COALESCE(SUM(used_bytes),0) FROM users'),
            'in24'       => (int) DB::value("SELECT COUNT(*) FROM mail_log WHERE direction = 'in' AND created_at > :t", ['t' => $since]),
            'out24'      => (int) DB::value("SELECT COUNT(*) FROM mail_log WHERE direction = 'out' AND created_at > :t", ['t' => $since]),
            'queue'      => (int) DB::value("SELECT COUNT(*) FROM mail_queue WHERE status IN ('pending','sending')"),
            'failed'     => (int) DB::value("SELECT COUNT(*) FROM mail_queue WHERE status = 'failed'"),
            'logins_ko'  => (int) DB::value('SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at > :t', ['t' => $since]),
            'twofa'      => (int) DB::value('SELECT COUNT(*) FROM users WHERE totp_enabled = 1'),
            'vacations'  => (int) DB::value('SELECT COUNT(*) FROM vacations WHERE enabled = 1'),
        ];
        // 14-day traffic histogram
        $days = [];
        for ($i = 13; $i >= 0; $i--) {
            $start = strtotime('today -' . $i . ' days');
            $days[] = [
                'label' => date('d/m', $start),
                'in'  => (int) DB::value("SELECT COUNT(*) FROM mail_log WHERE direction = 'in' AND created_at >= :s AND created_at < :e", ['s' => $start, 'e' => $start + 86400]),
                'out' => (int) DB::value("SELECT COUNT(*) FROM mail_log WHERE direction = 'out' AND created_at >= :s AND created_at < :e", ['s' => $start, 'e' => $start + 86400]),
            ];
        }
        $mode = \M4W\Service\RemoteMailbox::mode();
        $checks = [
            // Relay mode needs its relay; server mode sends on its own; webmail mode needs every mailbox linked.
            'smtp'  => match ($mode) {
                'relay' => trim((string) Settings::get('smtp.host', '')) !== '',
                'collector' => \M4W\Service\RemoteMailbox::status(\M4W\Service\Collector::account()) === 'ok',
                'webmail' => !DB::value("SELECT COUNT(*) FROM users u LEFT JOIN remote_accounts r ON r.user_id = u.id WHERE u.status = 'active' AND (r.id IS NULL OR r.password_enc = '' OR r.last_error <> '')"),
                default => true,
            },
            'dkim'  => in_array($mode, ['webmail', 'collector'], true) || (Settings::get('smtp.dkim_selector', '') !== '' && Settings::get('smtp.dkim_private', '') !== ''),
            'https' => $this->req->isSecure(),
            'logo'  => Settings::get('brand.logo', '') !== '',
            'cron'  => (int) Settings::get('system.cron_last', 0) > time() - 900,
            'twofa' => (bool) DB::value("SELECT COUNT(*) FROM users WHERE role IN ('admin', 'superadmin') AND totp_enabled = 1"),
        ];
        $recent = DB::all('SELECT a.*, u.email FROM audit_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 8');
        $top = DB::all('SELECT id, email, display_name, role, used_bytes, quota_mb FROM users ORDER BY used_bytes DESC LIMIT 5');
        return $this->page('dashboard', compact('stats', 'days', 'checks', 'recent', 'top'));
    }

    // ---- Online meetings --------------------------------------------------------

    public function meetings(): Response
    {
        return $this->page('meetings', []);
    }

    public function saveMeetings(): Response
    {
        $r = $this->req;
        $mode = static fn(string $v) => in_array($v, ['off', 'api', 'personal'], true) ? $v : 'off';
        $jitsi = rtrim($r->str('jitsi_url'), '/');
        if ($jitsi !== '' && !preg_match('#^https://[a-z0-9.-]+(:\d+)?(/[\w.-]*)*$#i', $jitsi)) {
            return $this->back('/admin/meetings', 'danger', t('meet.bad_url'));
        }
        Settings::setMany([
            'meet.jitsi' => $r->bool('jitsi') ? 'on' : 'off', 'meet.jitsi_url' => $jitsi ?: 'https://meet.jit.si',
            'meet.teams' => $mode($r->str('teams')), 'meet.teams_tenant' => preg_replace('/[^\w.-]/', '', $r->str('teams_tenant')) ?? '', 'meet.teams_client' => preg_replace('/[^\w-]/', '', $r->str('teams_client')) ?? '',
            'meet.meet' => $mode($r->str('meet')),
            'meet.zoom' => $mode($r->str('zoom')), 'meet.zoom_account' => preg_replace('/[^\w-]/', '', $r->str('zoom_account')) ?? '', 'meet.zoom_client' => preg_replace('/[^\w-]/', '', $r->str('zoom_client')) ?? '',
        ]);
        // Secrets are only replaced when typed (never shown again).
        foreach (['teams_secret', 'zoom_secret'] as $k) {
            if ($r->raw($k) !== '') {
                Settings::setSecret('meet.' . $k, trim($r->raw($k)));
            }
        }
        if ($r->raw('google_key') !== '') {
            $key = json_decode($r->raw('google_key'), true);
            if (!is_array($key) || empty($key['client_email']) || empty($key['private_key']) || !openssl_pkey_get_private((string) $key['private_key'])) {
                return $this->back('/admin/meetings', 'danger', t('meet.bad_key'));
            }
            Settings::setSecret('meet.google_key', (string) json_encode(['client_email' => $key['client_email'], 'private_key' => $key['private_key']]));
        }
        Audit::log('admin.meetings_saved');
        return $this->back('/admin/meetings', 'success', t('settings.saved'));
    }

    /** Create (and remove) a real meeting in the administrator's name to check the settings. */
    public function testMeeting(): Response
    {
        $p = $this->req->str('provider');
        $u = $this->user();
        try {
            $info = \M4W\Service\Meetings::create($u, $p, 'Test Make4WebMail', time() + 3600, time() + 5400);
            \M4W\Service\Meetings::delete($u, $info);
        } catch (\InvalidArgumentException $e) {
            return $this->back('/admin/meetings', 'danger', $e->getMessage());
        }
        return $this->back('/admin/meetings', 'success', t('meet.test_ok', ['provider' => \M4W\Service\Meetings::LABELS[$p] ?? $p, 'url' => $info['url']]));
    }

    // ---- Supervision -----------------------------------------------------------

    public function health(): Response
    {
        $checks = \M4W\Service\Health::checks(true);
        $days = max(1, min(365, (int) ($this->req->query['days'] ?? 30)));
        return $this->page('health', ['checks' => $checks, 'overall' => \M4W\Service\Health::overall($checks), 'dmarc' => \M4W\Service\DmarcReports::summary($days), 'days' => $days,
            'domains' => array_column(DB::all('SELECT name FROM domains WHERE active = 1 ORDER BY name'), 'name')]);
    }

    public function saveHealth(): Response
    {
        $email = mb_strtolower(trim($this->req->str('alert_email')));
        if ($email !== '' && !is_valid_email($email)) {
            return $this->back('/admin/health', 'danger', t('health.bad_email'));
        }
        Settings::set('health.alert_email', $email);
        if ($this->req->bool('refresh')) {
            DB::run("DELETE FROM settings WHERE name LIKE 'health.dns.%'");
        }
        return $this->back('/admin/health', 'success', t('settings.saved'));
    }

    // ---- Users ---------------------------------------------------------------

    public function users(): Response
    {
        $q = trim((string) ($this->req->query['q'] ?? ''));
        $status = (string) ($this->req->query['status'] ?? '');
        $where = '1=1';
        $p = [];
        if ($q !== '') {
            $where .= ' AND (LOWER(u.email) LIKE :q OR LOWER(u.display_name) LIKE :q OR LOWER(u.department) LIKE :q OR LOWER(u.job_title) LIKE :q)';
            $p['q'] = '%' . mb_strtolower($q) . '%';
        }
        if (in_array($status, ['active', 'disabled'], true)) {
            $where .= ' AND u.status = :s';
            $p['s'] = $status;
        } elseif ($status === 'admin') {
            $where .= " AND u.role IN ('admin', 'superadmin')";
        }
        if (!is_superadmin($this->user())) {
            $where .= " AND u.role <> 'superadmin'"; // the provider's accounts are not the customer's to manage
        }
        $users = DB::all("SELECT u.*, t.name AS template_name FROM users u LEFT JOIN signature_templates t ON t.id = u.signature_template_id WHERE $where ORDER BY u.email", $p);
        $remotes = [];
        foreach (DB::all('SELECT * FROM remote_accounts') as $ra) {
            $remotes[(int) $ra['user_id']] = $ra;
        }
        return $this->page('users', ['users' => $users, 'q' => $q, 'status' => $status, 'templates' => Signatures::all(), 'remotes' => $remotes]);
    }

    public function userForm(?string $id = null): Response
    {
        $user = $id ? Users::find((int) $id) : null;
        if ($id && (!$user || !$this->mayManage($user))) {
            throw new HttpException(404, t('error.not_found'));
        }
        $aliases = $user ? DB::all('SELECT * FROM aliases WHERE user_id = :u ORDER BY address', ['u' => $user['id']]) : [];
        return $this->page('user_form', [
            'remote' => $user ? \M4W\Service\RemoteMailbox::find((int) $user['id']) : null,
            'user' => $user,
            'aliases' => $aliases,
            'delegates' => $user ? array_map('intval', array_column(\M4W\Service\Delegation::delegates((int) $user['id']), 'id')) : [],
            'members' => $user ? array_map('intval', array_column(\M4W\Service\SharedMailboxes::members((int) $user['id']), 'id')) : [],
            'journal' => $user ? \M4W\Service\Delegation::journal((int) $user['id'], 10) : [],
            'others' => $user ? DB::all("SELECT id, email, display_name, role FROM users WHERE id <> :u AND status = 'active' ORDER BY display_name, email", ['u' => $user['id']]) : [],
            'templates' => Signatures::all(),
            'domains' => DB::all('SELECT * FROM domains WHERE active = 1 ORDER BY name'),
            'languages' => I18n::LANGUAGES,
            'departments' => array_column(DB::all("SELECT DISTINCT department FROM users WHERE department <> '' ORDER BY department"), 'department'),
            'section' => 'users',
        ]);
    }

    /** Super administrators' accounts are managed by super administrators only. */
    private function mayManage(array $target): bool
    {
        return is_superadmin($this->user()) || $target['role'] !== 'superadmin';
    }

    public function saveUser(): Response
    {
        $r = $this->req;
        $id = $r->int('id');
        if (!is_superadmin($this->user()) && $r->str('role') === 'superadmin') {
            return $this->back($id ? '/admin/users/' . $id : '/admin/users/new', 'danger', t('admin.no_superadmin_role'));
        }
        if ($id && ($t = Users::find($id)) && !$this->mayManage($t)) {
            throw new HttpException(404, t('error.not_found'));
        }
        $data = [
            'display_name' => $r->str('display_name'), 'first_name' => $r->str('first_name'), 'last_name' => $r->str('last_name'),
            'job_title' => $r->str('job_title'), 'department' => $r->str('department'), 'phone' => $r->str('phone'),
            'mobile' => $r->str('mobile'), 'role' => in_array($r->str('role'), Users::ROLES, true) ? $r->str('role') : 'user',
            'status' => $r->str('status') === 'disabled' ? 'disabled' : 'active', 'quota_mb' => max(0, $r->int('quota_mb', 2048)),
            'language' => isset(I18n::LANGUAGES[$r->str('language')]) ? $r->str('language') : 'fr',
            'must_change_password' => $r->bool('must_change_password') ? 1 : 0,
        ];
        if ($data['display_name'] === '') {
            $data['display_name'] = trim($data['first_name'] . ' ' . $data['last_name']);
        }
        $pw = $r->raw('password');
        $redirect = $id ? '/admin/users/' . $id : '/admin/users/new';
        if ($pw !== '' && ($err = Auth::passwordPolicyError($pw))) {
            return $this->back($redirect, 'danger', $err);
        }
        if ($id) {
            $existing = Users::find($id);
            if (!$existing) {
                throw new HttpException(404);
            }
            if ($id === $this->uid() && ($data['role'] !== $existing['role'] || $data['status'] !== 'active')) {
                return $this->back($redirect, 'danger', t('admin.cannot_demote_self'));
            }
            // There is always a super administrator, active.
            if ($existing['role'] === 'superadmin' && ($data['role'] !== 'superadmin' || $data['status'] !== 'active')
                && (int) DB::value("SELECT COUNT(*) FROM users WHERE role = 'superadmin' AND status = 'active' AND id <> :id", ['id' => $id]) === 0) {
                return $this->back($redirect, 'danger', t('admin.last_superadmin'));
            }
            if ($pw !== '') {
                $data['password'] = $pw;
            }
            Users::update($id, $data);
            if ($r->bool('delegates_present')) {
                \M4W\Service\Delegation::setDelegates($id, $r->arr('delegates'), $this->uid());
            }
            if ($r->bool('members_present')) {
                \M4W\Service\SharedMailboxes::setMembers($id, $r->arr('members'), $this->uid());
            }
            if ($err = $this->saveRemoteFields($id)) {
                return $this->back($redirect, 'warning', t('admin.user_saved') . ' ' . $err);
            }
            if ($data['status'] === 'disabled' || $pw !== '') {
                Auth::revokeOtherSessions($id, 0);
            }
            Audit::log('admin.user_updated', $existing['email']);
            return $this->back('/admin/users', 'success', t('admin.user_saved'));
        }
        $local = mb_strtolower($r->str('local_part'));
        $domain = mb_strtolower($r->str('domain'));
        $email = $local . '@' . $domain;
        if (!preg_match('/^[a-z0-9._+-]{1,64}$/', $local) || !Users::isLocalDomain($domain) || !is_valid_email($email)) {
            return $this->back($redirect, 'danger', t('admin.invalid_email'));
        }
        if (Users::findByEmail($email) || DB::value('SELECT COUNT(*) FROM aliases WHERE address = :a', ['a' => $email])) {
            return $this->back($redirect, 'danger', t('admin.email_taken'));
        }
        if ($pw === '') {
            return $this->back($redirect, 'danger', t('pw.required'));
        }
        $newId = Users::create($data + ['email' => $email, 'password' => $pw]);
        Audit::log('admin.user_created', $email);
        if ($err = $this->saveRemoteFields($newId)) {
            return $this->back('/admin/users/' . $newId, 'warning', t('admin.user_created', ['email' => $email]) . ' ' . $err);
        }
        if ($r->bool('send_welcome')) {
            \M4W\Service\Installer::welcome($newId);
        }
        return $this->back('/admin/users', 'success', t('admin.user_created', ['email' => $email]));
    }

    /** Webmail mode: provider mailbox link entered in the user form. Returns an error message or null. */
    private function saveRemoteFields(int $userId): ?string
    {
        $r = $this->req;
        if (!$r->bool('remote_present')) {
            return null;
        }
        $hasAccount = \M4W\Service\RemoteMailbox::find($userId) !== null;
        if (!$hasAccount && $r->raw('remote_password') === '') {
            // Nothing entered yet: the mailbox stays "not linked" (an own mailbox needs its password).
            return !\M4W\Service\RemoteMailbox::active() && $r->bool('remote_forced') ? t('wm.need_password') : null;
        }
        try {
            \M4W\Service\RemoteMailbox::save($userId, [
                'login' => $r->str('remote_login'), 'password' => $r->raw('remote_password'), 'enabled' => $r->bool('remote_enabled') || !$hasAccount,
                'imap_host' => $r->str('remote_imap_host'), 'imap_port' => $r->int('remote_imap_port'), 'imap_security' => $r->str('remote_imap_security'),
                'smtp_host' => $r->str('remote_smtp_host'), 'smtp_port' => $r->int('remote_smtp_port'), 'smtp_security' => $r->str('remote_smtp_security'),
                'two_way' => $r->bool('remote_two_way'),
                'sync_folders' => $r->bool('remote_sync_folders'),
            ] + (\M4W\Service\RemoteMailbox::active() ? [] : [
                // Other modes: the administrator imposes (or not) the user's own mailbox.
                'forced' => $r->bool('remote_forced'), 'overflow' => $r->bool('remote_overflow'), 'hourly_limit' => $r->int('remote_hourly_limit'),
                'leave_days' => $r->int('remote_leave_days', -1), 'save_sent' => $r->bool('remote_save_sent'),
            ]), $this->uid());
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
        return null;
    }

    /** Webmail mode: check IMAP and SMTP credentials of a mailbox (AJAX). */
    public function testRemote(string $id): Response
    {
        if (!($t = Users::find((int) $id)) || !$this->mayManage($t)) {
            throw new HttpException(404, t('error.not_found'));
        }
        $res = \M4W\Service\RemoteMailbox::test((int) $id);
        return $this->ok(['imap' => $res['imap'], 'smtp' => $res['smtp'], 'message' => $res['imap'] === '' && $res['smtp'] === '' ? t('wm.test_ok') : t('wm.test_failed')]);
    }

    /** Collector mode: check the shared mailbox (AJAX). */
    public function testCollector(): Response
    {
        return $this->testRemote((string) \M4W\Service\Collector::ACCOUNT);
    }

    /** Collector mode: pull and dispatch now (AJAX). */
    public function syncCollector(): Response
    {
        return $this->syncRemote((string) \M4W\Service\Collector::ACCOUNT);
    }

    /** Collector mode: live sending gauge (AJAX, refreshed by the admin pages). */
    public function collectorStats(): Response
    {
        $s = \M4W\Service\Collector::stats();
        return $this->ok(['stats' => $s + ['next_time' => $s['next'] ? date('H:i', $s['next']) : '']]);
    }

    /** Webmail mode: pull new mail now (AJAX). */
    public function syncRemote(string $id): Response
    {
        if (!($t = Users::find((int) $id)) || !$this->mayManage($t)) {
            throw new HttpException(404, t('error.not_found'));
        }
        $acc = \M4W\Service\RemoteMailbox::find((int) $id);
        if (!$acc) {
            return $this->fail(t('wm.not_linked'));
        }
        DB::update('remote_accounts', ['last_sync_at' => 0], 'id = :id', ['id' => $acc['id']]);
        $acc['last_sync_at'] = 0;
        $res = \M4W\Service\RemoteMailbox::sync($acc);
        return $res['error'] !== '' ? $this->fail($res['error']) : $this->ok(['message' => t('wm.synced', ['n' => $res['fetched']])]);
    }

    /** Copy the mail kept here back into the person's own mailbox (done during the next syncs). */
    public function restoreRemote(string $id): Response
    {
        if (!($t = Users::find((int) $id)) || !$this->mayManage($t)) {
            throw new HttpException(404, t('error.not_found'));
        }
        try {
            $n = \M4W\Service\RemoteMailbox::startRestore((int) $id, $this->req->arr('folders') ?: ['inbox', 'sent'], $this->uid());
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        // Picked up by the next run of the scheduler (every minute), batch after batch.
        DB::update('remote_accounts', ['last_sync_at' => 0], 'user_id = :u', ['u' => (int) $id]);
        return $this->ok(['message' => t('own.restore_started', ['n' => $n])]);
    }

    public function deleteUser(): Response
    {
        $id = $this->req->int('id');
        if ($id === $this->uid()) {
            return $this->back('/admin/users', 'danger', t('admin.cannot_delete_self'));
        }
        $u = Users::find($id);
        if ($u && !$this->mayManage($u)) {
            throw new HttpException(404, t('error.not_found'));
        }
        if ($u && $this->lastSuperadmin($u)) {
            return $this->back('/admin/users', 'danger', t('admin.last_superadmin'));
        }
        if ($u) {
            Users::delete($id);
            Audit::log('admin.user_deleted', $u['email']);
        }
        return $this->back('/admin/users', 'success', t('admin.user_deleted'));
    }

    /** The only active super administrator left (never disabled nor deleted). */
    private function lastSuperadmin(array $u): bool
    {
        return $u['role'] === 'superadmin'
            && (int) DB::value("SELECT COUNT(*) FROM users WHERE role = 'superadmin' AND status = 'active' AND id <> :id", ['id' => $u['id']]) === 0;
    }

    public function bulkUsers(): Response
    {
        $ids = array_filter(array_map('intval', $this->req->arr('ids')), fn($i) => $i !== $this->uid() && ($bu = Users::find($i)) && $this->mayManage($bu) && !$this->lastSuperadmin($bu));
        $action = $this->req->str('bulk_action');
        foreach ($ids as $id) {
            switch ($action) {
                case 'enable':
                    Users::update($id, ['status' => 'active']);
                    break;
                case 'disable':
                    Users::update($id, ['status' => 'disabled']);
                    Auth::revokeOtherSessions($id, 0);
                    break;
                case 'force_pw':
                    Users::update($id, ['must_change_password' => 1]);
                    break;
                case 'delete':
                    Users::delete($id);
                    break;
            }
        }
        Audit::log('admin.bulk_' . $action, implode(',', $ids));
        return $this->back('/admin/users', 'success', t('admin.bulk_done', ['n' => count($ids)]));
    }

    /** CSV: email;first_name;last_name;job_title;department;phone;password */
    public function importUsers(): Response
    {
        $f = $this->req->files['file'] ?? null;
        if (!is_array($f) || $f['error'] !== UPLOAD_ERR_OK) {
            return $this->back('/admin/users', 'danger', t('upload.failed'));
        }
        $n = 0;
        $errors = [];
        foreach (preg_split('/\r?\n/', (string) file_get_contents($f['tmp_name'])) ?: [] as $i => $line) {
            if (trim($line) === '' || ($i === 0 && stripos($line, 'email') !== false)) {
                continue;
            }
            $c = array_map('trim', str_getcsv($line, str_contains($line, ';') ? ';' : ',', '"', '\\'));
            $email = mb_strtolower($c[0] ?? '');
            if (!is_valid_email($email) || !Users::isLocalDomain(substr($email, strpos($email, '@') + 1)) || Users::findByEmail($email)) {
                $errors[] = $email ?: ('#' . ($i + 1));
                continue;
            }
            $pw = ($c[6] ?? '') !== '' ? $c[6] : \M4W\Core\Crypto::token(12) . 'aA1!';
            if (Auth::passwordPolicyError($pw) !== null) {
                $errors[] = $email;
                continue;
            }
            $newId = Users::create([
                'email' => $email, 'first_name' => $c[1] ?? '', 'last_name' => $c[2] ?? '', 'job_title' => $c[3] ?? '',
                'department' => $c[4] ?? '', 'phone' => $c[5] ?? '', 'password' => $pw, 'must_change_password' => 1,
                'display_name' => trim(($c[1] ?? '') . ' ' . ($c[2] ?? '')),
            ]);
            // Webmail mode: optional 8th column = mailbox password at the provider.
            if (\M4W\Service\RemoteMailbox::active() && trim($c[7] ?? '') !== '') {
                try {
                    \M4W\Service\RemoteMailbox::save($newId, ['login' => '', 'password' => trim($c[7]), 'enabled' => 1], $this->uid());
                } catch (\InvalidArgumentException) {
                    $errors[] = $email . ' (boîte)';
                }
            }
            $n++;
        }
        Audit::log('admin.users_imported', (string) $n);
        $msg = t('admin.imported', ['n' => $n]) . ($errors ? ' ' . t('admin.import_skipped', ['list' => implode(', ', array_slice($errors, 0, 10))]) : '');
        return $this->back('/admin/users', $errors ? 'warning' : 'success', $msg);
    }

    public function reset2fa(): Response
    {
        $id = $this->req->int('id');
        if (!($t = Users::find($id)) || !$this->mayManage($t)) {
            throw new HttpException(404, t('error.not_found'));
        }
        DB::update('users', ['totp_enabled' => 0, 'totp_secret' => ''], 'id = :id', ['id' => $id]);
        Audit::log('admin.2fa_reset', (string) $id);
        return $this->back('/admin/users/' . $id, 'success', t('2fa.disabled'));
    }

    // ---- Domains & aliases ---------------------------------------------------

    public function domains(): Response
    {
        $domains = DB::all('SELECT d.*, (SELECT COUNT(*) FROM users u WHERE u.email LIKE ' . (DB::driver() === 'mysql' ? "CONCAT('%@', d.name)" : "'%@' || d.name") . ') AS users FROM domains d ORDER BY name');
        $aliases = DB::all('SELECT a.*, u.email AS target FROM aliases a JOIN users u ON u.id = a.user_id ORDER BY a.address');
        $users = DB::all('SELECT id, email FROM users ORDER BY email');
        return $this->page('domains', compact('domains', 'aliases', 'users'));
    }

    /** Health and reputation of a domain: authentication, senders, blocklists, deliveries (DomainHealth). */
    public function domainHealth(string $id): Response
    {
        $d = DB::one('SELECT * FROM domains WHERE id = :id', ['id' => (int) $id]);
        if (!$d) {
            return Response::redirect('/admin/domains');
        }
        \M4W\Core\Session::close();
        if ($this->req->method === 'POST' && $this->req->input('selectors') !== null) {
            \M4W\Service\DomainHealth::setSelectors((string) $d['name'], $this->req->str('selectors'));
        }
        $report = \M4W\Service\DomainHealth::report((string) $d['name'], $this->req->method === 'POST');
        return $this->page('domain_health', ['domain' => $d, 'r' => $report]);
    }

    public function saveDomain(): Response
    {
        $name = mb_strtolower($this->req->str('name'));
        if (!preg_match('/^(?=.{3,190}$)([a-z0-9-]+\.)+[a-z]{2,}$/', $name)) {
            return $this->back('/admin/domains', 'danger', t('install.bad_domain'));
        }
        $id = $this->req->int('id');
        if ($id) {
            DB::update('domains', ['active' => $this->req->bool('active') ? 1 : 0], 'id = :id', ['id' => $id]);
        } elseif (!DB::value('SELECT COUNT(*) FROM domains WHERE name = :n', ['n' => $name])) {
            DB::insert('domains', ['name' => $name, 'active' => 1, 'created_at' => time()]);
        }
        Audit::log('admin.domain_saved', $name);
        return $this->back('/admin/domains', 'success', t('settings.saved'));
    }

    public function deleteDomain(): Response
    {
        $d = DB::one('SELECT * FROM domains WHERE id = :id', ['id' => $this->req->int('id')]);
        if ($d && DB::value('SELECT COUNT(*) FROM users WHERE email LIKE :p', ['p' => '%@' . $d['name']])) {
            return $this->back('/admin/domains', 'danger', t('admin.domain_in_use'));
        }
        if ($d) {
            DB::delete('domains', 'id = :id', ['id' => $d['id']]);
            Audit::log('admin.domain_deleted', $d['name']);
        }
        return $this->back('/admin/domains', 'success', t('settings.saved'));
    }

    public function saveAlias(): Response
    {
        $addr = mb_strtolower($this->req->str('address'));
        $uid = $this->req->int('user_id');
        $back = $this->req->str('back') === 'user' ? '/admin/users/' . $uid : '/admin/domains';
        if (!is_valid_email($addr) || !Users::isLocalDomain(substr($addr, strpos($addr, '@') + 1))) {
            return $this->back($back, 'danger', t('admin.invalid_email'));
        }
        if (Users::findByEmail($addr) || DB::value('SELECT COUNT(*) FROM aliases WHERE address = :a', ['a' => $addr])) {
            return $this->back($back, 'danger', t('admin.email_taken'));
        }
        if (!($target = Users::find($uid)) || !$this->mayManage($target)) {
            return $this->back($back, 'danger', t('error.not_found'));
        }
        DB::insert('aliases', ['address' => $addr, 'user_id' => $uid, 'created_at' => time()]);
        Audit::log('admin.alias_created', $addr);
        return $this->back($back, 'success', t('settings.saved'));
    }

    public function deleteAlias(): Response
    {
        $a = DB::one('SELECT * FROM aliases WHERE id = :id', ['id' => $this->req->int('id')]);
        // Outside the domains page (super administrator), only the aliases of an account one manages.
        if ($a && !is_superadmin($this->user()) && (!($owner = Users::find((int) $a['user_id'])) || !$this->mayManage($owner))) {
            throw new HttpException(404, t('error.not_found'));
        }
        if ($a) {
            DB::delete('aliases', 'id = :id', ['id' => $a['id']]);
            Audit::log('admin.alias_deleted', $a['address']);
        }
        return $this->back($this->req->str('back') === 'user' && $a ? '/admin/users/' . $a['user_id'] : '/admin/domains', 'success', t('settings.saved'));
    }

    // ---- Signatures ----------------------------------------------------------

    public function signatures(): Response
    {
        $templates = Signatures::all();
        $sample = Users::find($this->uid());
        foreach ($templates as &$t) {
            $t['preview'] = Signatures::renderTemplate((string) $t['html'], $sample, 'preview', Signatures::options($t));
        }
        return $this->page('signatures', ['templates' => $templates, 'departments' => array_column(DB::all("SELECT DISTINCT department FROM users WHERE department <> '' ORDER BY department"), 'department')]);
    }

    public function signatureForm(?string $id = null): Response
    {
        $tpl = $id ? Signatures::find((int) $id) : ['id' => 0, 'name' => '', 'description' => '', 'html' => Signatures::defaultTemplate(), 'options' => '', 'is_default' => 0, 'apply_on_reply' => 1];
        if (!$tpl) {
            throw new HttpException(404);
        }
        $users = DB::all("SELECT id, email, display_name FROM users WHERE status = 'active' ORDER BY display_name LIMIT 500");
        return $this->page('signature_form', ['tpl' => $tpl, 'opts' => Signatures::options($tpl), 'variables' => Signatures::VARIABLES, 'users' => $users, 'section' => 'signatures']);
    }

    /** Options of the guided editor, as posted (checked by Signatures::cleanOptions). */
    private function signatureOptions(): array
    {
        $o = (array) ($this->req->input('opt') ?? []);
        $nets = (array) ($this->req->input('soc_net') ?? []);
        $urls = (array) ($this->req->input('soc_url') ?? []);
        $o['socials'] = [];
        foreach ($nets as $i => $net) {
            $o['socials'][] = ['net' => (string) $net, 'url' => (string) ($urls[$i] ?? '')];
        }
        return $o;
    }

    /** The guided template's HTML, or the administrator's own when they chose to write it. */
    private function signatureHtml(): string
    {
        return $this->req->bool('custom_html') ? $this->req->raw('html') : '';
    }

    public function saveSignature(): Response
    {
        $id = $this->req->int('id') ?: null;
        $old = $id ? Signatures::options(Signatures::find($id)) : [];
        $id = Signatures::save([
            'name' => $this->req->str('name'), 'description' => $this->req->str('description'), 'html' => $this->signatureHtml(),
            'options' => $this->signatureOptions(), 'old_options' => $old,
            'is_default' => $this->req->bool('is_default'), 'apply_on_reply' => 1,
        ], $id);
        Audit::log('admin.signature_saved', (string) $id);
        return $this->back('/admin/signatures/' . $id, 'success', t('admin.signature_saved'));
    }

    public function deleteSignature(): Response
    {
        Signatures::delete($this->req->int('id'));
        Audit::log('admin.signature_deleted', (string) $this->req->int('id'));
        return $this->back('/admin/signatures', 'success', t('admin.signature_deleted'));
    }

    public function previewSignature(): Response
    {
        $user = Users::find($this->req->int('user_id') ?: $this->uid()) ?? $this->user();
        $opts = Signatures::cleanOptions($this->signatureOptions());
        $html = Signatures::renderTemplate(Signatures::cleanTemplate($this->signatureHtml()), $user, 'preview', $opts);
        // The guided layout's HTML, for the one who wants to start writing their own from it.
        return $this->ok(['html' => $html, 'built' => Signatures::build($opts)]);
    }

    /** A logo of its own for a signature template (stored with the branding images). */
    public function signatureLogo(): Response
    {
        $f = $this->req->files['logo'] ?? null;
        try {
            if (!is_array($f)) {
                throw new \InvalidArgumentException(t('upload.failed'));
            }
            $name = \M4W\Service\Branding::storeImage($f, 'siglogo', 2_000_000);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->ok(['file' => $name, 'url' => url('brand/' . $name)]);
    }

    /** The chosen template becomes the one applied to everyone. */
    public function activateSignature(string $id): Response
    {
        $tpl = Signatures::find((int) $id);
        if (!$tpl) {
            throw new HttpException(404);
        }
        DB::run('UPDATE signature_templates SET is_default = CASE WHEN id = :id THEN 1 ELSE 0 END', ['id' => $tpl['id']]);
        Audit::log('admin.signature_activated', $tpl['name']);
        return $this->back('/admin/signatures', 'success', t('admin.sig_activated', ['name' => $tpl['name']]));
    }

    // ---- Update from GitHub (super administrator) -------------------------------

    public function update(): Response
    {
        $status = json_decode((string) Settings::get('update.status', ''), true);
        return $this->page('update', [
            'connected' => \M4W\Service\Updater::connected(), 'login' => (string) Settings::get('update.login', ''),
            'branch' => \M4W\Service\Updater::branch(), 'status' => is_array($status) ? $status : null,
            'history' => \M4W\Service\Updater::history(),
        ]);
    }

    public function updateAction(string $action): Response
    {
        $U = \M4W\Service\Updater::class; // the session stays open: the outcome is shown on the page
        try {
            switch ($action) {
                case 'connect':
                    $b = $this->req->str('branch') ?: 'main';
                    Settings::set('update.branch', preg_match('#^[\w./-]{1,100}$#', $b) ? $b : 'main');
                    $r = $U::connect($this->req->str('login'), $this->req->raw('token'));
                    Settings::set('update.status', json_encode($U::status()));
                    return $this->back('/admin/update', 'success', t('upd.connected', ['login' => $r['login']]));
                case 'disconnect':
                    $U::disconnect();
                    Settings::set('update.status', '');
                    return $this->back('/admin/update', 'success', t('upd.disconnected'));
                case 'check':
                    Settings::set('update.status', json_encode($U::status()));
                    return $this->back('/admin/update');
                case 'apply':
                    $r = $U::apply($this->req->str('sha') ?: null);
                    Settings::set('update.status', '');
                    return $this->back('/admin/update', 'success', t('upd.applied', ['from' => $r['from'], 'to' => $r['to'], 'n' => $r['written']]));
                case 'rollback':
                    $r = $U::rollback();
                    Settings::set('update.status', '');
                    return $this->back('/admin/update', 'success', t('upd.rolled_back', ['version' => $r['to']]));
            }
        } catch (\Throwable $e) {
            error_log('[m4w] update ' . $action . ': ' . $e->getMessage());
            return $this->back('/admin/update', 'danger', $e->getMessage());
        }
        throw new HttpException(404);
    }

    // ---- Branding ------------------------------------------------------------

    public function branding(): Response
    {
        return $this->page('branding');
    }

    public function saveBranding(): Response
    {
        $r = $this->req;
        $color = static fn(string $v, string $d) => preg_match('/^#[0-9a-f]{6}$/i', $v) ? strtolower($v) : $d;
        $values = [
            'brand.name' => mb_substr($r->str('name'), 0, 100) ?: 'Make4WebMail',
            'brand.tagline' => mb_substr($r->str('tagline'), 0, 200),
            'brand.company' => mb_substr($r->str('company'), 0, 190),
            'brand.website' => preg_match('#^https?://#i', $r->str('website')) ? mb_substr($r->str('website'), 0, 190) : '',
            'brand.address' => mb_substr($r->str('address'), 0, 255),
            'brand.primary' => $color($r->str('primary'), '#2563eb'),
            'brand.accent' => $color($r->str('accent'), '#0ea5e9'),
            'brand.sidebar' => in_array($r->str('sidebar'), Branding::SIDEBAR_STYLES, true) ? $r->str('sidebar') : 'light',
            'brand.sidebar_color' => $color($r->str('sidebar_color'), '#0f766e'),
            'brand.sidebar_header' => $r->bool('sidebar_header') ? 1 : 0,
            'brand.sidebar_main' => $r->bool('sidebar_main') ? 1 : 0,
            'brand.sidebar_bg_fit' => $r->str('sidebar_bg_fit') === 'tile' ? 'tile' : 'cover',
            'brand.radius' => max(0, min(24, $r->int('radius', 10))),
            'brand.font' => $r->str('font') === 'system' ? 'system' : 'inter',
            'brand.login_message' => mb_substr($r->str('login_message'), 0, 500),
            'brand.footer' => mb_substr($r->str('footer'), 0, 300),
            'brand.custom_css' => mb_substr($r->raw('custom_css'), 0, 20000),
            'brand.show_name' => $r->bool('show_name') ? 1 : 0,
            'brand.reader_bg_fit' => in_array($r->str('reader_bg_fit'), ['cover', 'contain', 'tile'], true) ? $r->str('reader_bg_fit') : 'cover',
        ];
        try {
            foreach (['logo' => 'logo', 'logo_dark' => 'logodark', 'favicon' => 'favicon', 'login_bg' => 'loginbg', 'reader_bg' => 'readerbg', 'sidebar_bg' => 'sidebarbg'] as $field => $prefix) {
                if ($r->bool('remove_' . $field)) {
                    $values['brand.' . $field] = '';
                }
                $f = $r->files[$field] ?? null;
                if (is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $values['brand.' . $field] = Branding::storeImage($f, $prefix, in_array($field, ['login_bg', 'reader_bg', 'sidebar_bg'], true) ? 6_000_000 : 2_000_000);
                    if ($field === 'sidebar_bg') {
                        // Its average colour decides the text colour (light on dark, dark on light).
                        $values['brand.sidebar_bg_avg'] = Branding::averageColor(storage_path('uploads/brand/' . $values['brand.sidebar_bg']));
                    }
                }
            }
        } catch (\InvalidArgumentException $e) {
            return $this->back('/admin/branding', 'danger', $e->getMessage());
        }
        Settings::setMany($values);
        Audit::log('admin.branding_saved');
        return $this->back('/admin/branding', 'success', t('settings.saved'));
    }

    // ---- Mail server -----------------------------------------------------------

    public function mail(): Response
    {
        $dkimPublic = '';
        $priv = Settings::secret('smtp.dkim_private');
        if ($priv !== '' && ($k = openssl_pkey_get_private($priv))) {
            $pub = openssl_pkey_get_details($k)['key'] ?? '';
            $dkimPublic = 'v=DKIM1; k=rsa; p=' . preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $pub);
        }
        $domains = array_column(DB::all('SELECT name FROM domains WHERE active = 1 ORDER BY name'), 'name');
        $linked = [
            'total' => (int) DB::value("SELECT COUNT(*) FROM users WHERE status = 'active'"),
            'ok' => (int) DB::value("SELECT COUNT(*) FROM remote_accounts r JOIN users u ON u.id = r.user_id WHERE u.status = 'active' AND r.enabled = 1 AND r.password_enc <> ''"),
        ];
        $collector = [
            'account' => \M4W\Service\Collector::account(),
            'stats' => \M4W\Service\Collector::stats(),
            'redirect' => \M4W\Service\Collector::addressesToRedirect(),
            'users' => DB::all("SELECT id, email, display_name, role FROM users WHERE status = 'active' ORDER BY role IN ('admin', 'superadmin') DESC, email"),
            'fallback' => \M4W\Service\Collector::fallbackUser(),
        ];
        return $this->page('mail', ['dkimPublic' => $dkimPublic, 'domains' => $domains, 'hasPassword' => Settings::get('smtp.password', '') !== '', 'linked' => $linked, 'collector' => $collector]);
    }

    public function saveMail(): Response
    {
        $r = $this->req;
        $values = [
            'smtp.host' => preg_replace('/[^a-z0-9.:-]/i', '', $r->str('host')) ?? '',
            'smtp.port' => max(1, min(65535, $r->int('port', 587))),
            'smtp.security' => in_array($r->str('security'), ['ssl', 'tls', 'none'], true) ? $r->str('security') : 'tls',
            'smtp.auth' => $r->bool('auth') ? 1 : 0,
            'smtp.username' => $r->str('username'),
            'smtp.helo' => preg_replace('/[^a-z0-9.-]/i', '', $r->str('helo')) ?? '',
            'smtp.verify_peer' => $r->bool('verify_peer') ? 1 : 0,
            'smtp.timeout' => max(5, min(120, $r->int('timeout', 20))),
            'smtp.local_delivery' => $r->bool('local_delivery') ? 1 : 0,
            'smtp.dkim_domain' => mb_strtolower($r->str('dkim_domain')),
            'smtp.dkim_selector' => preg_replace('/[^a-z0-9_-]/i', '', $r->str('dkim_selector')) ?? '',
            'inbound.listen' => $r->str('inbound_listen', '0.0.0.0:2525'),
            'inbound.max_size_mb' => max(1, min(200, $r->int('inbound_max', 35))),
            'inbound.allowed_ips' => $r->str('inbound_ips'),
            'inbound.spam_header' => $r->bool('spam_header') ? 1 : 0,
        ];
        $mode = in_array($r->str('mode'), ['server', 'relay', 'webmail', 'collector'], true) ? $r->str('mode') : \M4W\Service\RemoteMailbox::mode();
        $values['mail.mode'] = $mode;
        $host = static fn(string $v) => mb_strtolower(preg_replace('/[^a-z0-9.-]/i', '', $v) ?? '');
        $sec = static fn(string $v) => in_array($v, ['ssl', 'tls', 'none'], true) ? $v : 'ssl';
        $values += [
            'webmail.preset' => array_key_exists($r->str('wm_preset'), \M4W\Service\RemoteMailbox::PRESETS) ? $r->str('wm_preset') : 'custom',
            'webmail.imap_host' => $host($r->str('wm_imap_host')), 'webmail.imap_port' => max(1, min(65535, $r->int('wm_imap_port', 993))), 'webmail.imap_security' => $sec($r->str('wm_imap_security')),
            'webmail.smtp_host' => $host($r->str('wm_smtp_host')), 'webmail.smtp_port' => max(1, min(65535, $r->int('wm_smtp_port', 465))), 'webmail.smtp_security' => $sec($r->str('wm_smtp_security')),
            'webmail.interval' => max(1, min(15, $r->int('wm_interval', 1))),
            'imap.max_per_second' => max(1, min(50, $r->int('wm_per_second', \M4W\Service\ImapLimits::PER_SECOND))),
            'imap.max_per_minute' => max(5, min(1000, $r->int('wm_per_minute', \M4W\Service\ImapLimits::PER_MINUTE))),
            'imap.max_per_box' => max(1, min(50, $r->int('wm_per_box', \M4W\Service\ImapLimits::PER_BOX))),
            'webmail.leave_days' => in_array($r->int('wm_leave_days', -1), [-1, 0, 7, 30, 90], true) ? $r->int('wm_leave_days', -1) : -1,
            'webmail.spam_folder' => mb_substr(preg_replace('/[\r\n\x00"\\\\]/', '', $r->str('wm_spam_folder')) ?? '', 0, 100),
            'webmail.verify_peer' => $r->bool('wm_verify_peer') ? 1 : 0,
            'collector.hourly_limit' => max(1, min(100000, $r->int('col_limit', 200))),
            'collector.fallback_user' => (int) DB::value("SELECT id FROM users WHERE id = :i AND status = 'active'", ['i' => $r->int('col_fallback')]),
        ];
        if ($mode === 'relay' && $values['smtp.host'] === '') {
            return $this->back('/admin/mail', 'danger', t('wm.relay_needs_host'));
        }
        // Collector mode: the shared mailbox at the provider.
        if ($r->str('col_address') !== '' || $r->raw('col_password') !== '') {
            try {
                $current = \M4W\Service\Collector::account();
                if (!$current && $r->raw('col_password') === '') {
                    throw new \InvalidArgumentException(t('wm.need_password'));
                }
                \M4W\Service\Collector::save($r->str('col_address') ?: (string) ($current['login'] ?? ''), $r->raw('col_password'), $this->uid());
            } catch (\InvalidArgumentException $e) {
                return $this->back('/admin/mail', 'danger', $e->getMessage());
            }
        }
        if ($mode === 'collector' && !\M4W\Service\Collector::account()) {
            return $this->back('/admin/mail', 'danger', t('col.needs_account'));
        }
        Settings::setMany($values);
        if ($r->raw('password') !== '') {
            Settings::setSecret('smtp.password', $r->raw('password'));
        } elseif ($r->bool('clear_password')) {
            Settings::set('smtp.password', '');
        }
        Audit::log('admin.mail_saved', $mode . ($mode === 'relay' ? ' ' . $values['smtp.host'] : ''));
        return $this->back('/admin/mail', 'success', t('settings.saved'));
    }

    public function generateDkim(): Response
    {
        [$priv] = DkimSigner::generateKeys();
        Settings::setSecret('smtp.dkim_private', $priv);
        if (Settings::get('smtp.dkim_selector', '') === '') {
            Settings::set('smtp.dkim_selector', 'm4w' . date('Ym'));
        }
        if (Settings::get('smtp.dkim_domain', '') === '') {
            Settings::set('smtp.dkim_domain', (string) DB::value('SELECT name FROM domains ORDER BY id LIMIT 1'));
        }
        Audit::log('admin.dkim_generated');
        return $this->back('/admin/mail#dkim', 'success', t('admin.dkim_generated'));
    }

    public function testMail(): Response
    {
        $u = $this->user();
        $to = $this->req->str('to') ?: $u['email'];
        if (!is_valid_email($to)) {
            return $this->fail(t('admin.invalid_email'));
        }
        $b = new MimeBuilder();
        $b->from = ['email' => $u['email'], 'name' => (string) Settings::get('brand.name')];
        $b->to = [['email' => $to, 'name' => '']];
        $b->subject = t('admin.test_subject');
        $b->html = '<p>' . e(t('admin.test_body')) . '</p><p style="color:#64748b">' . date('r') . '</p>';
        $b->messageId = MimeBuilder::newMessageId(substr($u['email'], strpos($u['email'], '@') + 1));
        $res = Transport::send($b->build(), $u['email'], [$to], (int) $u['id']);
        if ($res['failed']) {
            return $this->fail(implode(' — ', $res['failed']));
        }
        if ($res['deferred']) {
            return $this->ok(['message' => t('col.test_held', ['time' => date('H:i', $res['retry_at'])])]);
        }
        return $this->ok(['message' => t('admin.test_ok', ['email' => $to])]);
    }

    // ---- Security ------------------------------------------------------------

    public function files(): Response
    {
        $spaces = [];
        foreach (DB::all("SELECT f.*, u.display_name, u.email FROM fs_folders f LEFT JOIN users u ON u.id = f.created_by WHERE f.kind = 'space' ORDER BY f.deleted_at > 0, f.name") as $s) {
            $s['members'] = (int) DB::value('SELECT COUNT(*) FROM fs_acl WHERE folder_id = :f', ['f' => $s['id']]);
            $spaces[] = $s;
        }
        $stats = [
            'files' => (int) DB::value('SELECT COUNT(*) FROM fs_files WHERE deleted_at = 0 AND archived_at = 0'),
            'versions' => (int) DB::value('SELECT COUNT(*) FROM fs_versions'),
            'trash' => (int) DB::value('SELECT COUNT(*) FROM fs_files WHERE deleted_at > 0'),
        ];
        return $this->page('files', [
            'spaces' => $spaces, 'stats' => $stats, 'usage' => \M4W\Service\Files::usage(), 'archived' => \M4W\Service\Files::archived(),
            'driver' => \M4W\Storage\Storage::store()->name(),
            'phpMax' => min(self::iniBytes((string) ini_get('upload_max_filesize')), self::iniBytes((string) ini_get('post_max_size'))),
        ]);
    }

    private static function iniBytes(string $v): int
    {
        $n = (int) $v;
        return match (strtolower(substr(trim($v), -1))) {
            'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n,
        } ?: PHP_INT_MAX;
    }

    public function saveFiles(): Response
    {
        $r = $this->req;
        $values = [
            'files.max_file_mb' => max(1, min(10240, $r->int('max_file_mb', 200))),
            'files.transfer_services' => mb_substr(str_replace("\r", '', $r->raw('transfer_services')), 0, 2000),
            'files.quota_gb' => max(0, min(100000, $r->int('quota_gb', 0))),
            'files.versions' => max(0, min(100, $r->int('versions', 10))),
            'files.trash_days' => max(1, min(365, $r->int('trash_days', 30))),
            'files.users_create_spaces' => $r->bool('users_create_spaces') ? 1 : 0,
            'files.expiry_max_days' => max(30, min(3650, $r->int('expiry_max_days', 1095))),
        ];
        $url = static fn(string $v) => preg_match('#^https?://[a-z0-9.-]+(:\d+)?(/[^\s"<>]*)?$#i', $v) ? rtrim($v, '/') : '';
        $values['office.url'] = $url($r->str('office_url'));
        $values['office.wopi_base'] = $url($r->str('office_wopi_base'));
        $values['files.expiry_default_days'] = max(1, min($values['files.expiry_max_days'], $r->int('expiry_default_days', 365)));
        foreach ($values as $k => $v) {
            Settings::set($k, $v);
        }
        Audit::log('admin.files_saved');
        return $this->back('/admin/files', 'success', t('settings.saved'));
    }

    /** Backup settings; "Back up now" asks the backup task (bin/backup.php) to start within a minute. */
    public function saveBackup(): Response
    {
        $r = $this->req;
        $protocol = in_array($r->str('protocol'), \M4W\Service\Backup::PROTOCOLS, true) ? $r->str('protocol') : 'sftp';
        $host = trim($r->str('host'));
        $path = '/' . trim(str_replace('\\', '/', $r->str('path')), '/');
        if (($host !== '' && !preg_match('/^[a-z0-9.\-]+$/i', $host)) || preg_match('/[\x00-\x1f]|\.\./', $path)) {
            return $this->back('/admin/files#backup', 'danger', t('backup.invalid'));
        }
        if ($protocol === 'local' && \M4W\Service\Backup::unsafeLocalPath($path)) {
            return $this->back('/admin/files#backup', 'danger', t('backup.public_path'));
        }
        $days = implode('', array_values(array_intersect(['1', '2', '3', '4', '5', '6', '7'], (array) ($r->post['days'] ?? []))));
        $time = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $r->str('time')) ? $r->str('time') : '03:00';
        $tz = in_array($r->str('timezone'), \DateTimeZone::listIdentifiers(), true) ? $r->str('timezone') : 'Europe/Paris';
        Settings::setMany([
            'backup.enabled' => $r->bool('enabled') ? 1 : 0, 'backup.protocol' => $protocol, 'backup.host' => mb_strtolower($host),
            'backup.port' => max(0, min(65535, $r->int('port'))), 'backup.user' => mb_substr($r->str('user'), 0, 190), 'backup.path' => mb_substr($path, 0, 250),
            'backup.tls_verify' => $r->bool('tls_verify') ? 1 : 0, 'backup.time' => $time, 'backup.timezone' => $tz,
            'backup.days' => $days !== '' ? $days : '1234567', 'backup.keep' => max(1, min(365, $r->int('keep', 7))),
        ]);
        if ($r->raw('password') !== '') {
            Settings::setSecret('backup.password', $r->raw('password'));
        }
        if ($r->raw('zip_password') !== '') {
            Settings::setSecret('backup.zip_password', $r->raw('zip_password'));
        } elseif ($r->bool('zip_password_clear')) {
            Settings::set('backup.zip_password', '');
        }
        Audit::log('admin.backup_saved');
        if ($r->bool('now')) {
            Settings::set('backup.requested', 1);
            return $this->back('/admin/files#backup', 'success', t('backup.requested'));
        }
        return $this->back('/admin/files#backup', 'success', t('settings.saved'));
    }

    /** Backup server reachable and writable (AJAX, saved settings). */
    public function testBackup(): Response
    {
        $e = \M4W\Service\Backup::test();
        return $e === '' ? $this->ok(['message' => t('backup.test_ok')]) : $this->fail($e);
    }

    /** Online editing: reach the office server and read its formats (AJAX). */
    public function officeTest(): Response
    {
        $err = \M4W\Service\Office::test();
        return $err === '' ? $this->ok(['message' => t('admin.office_ok', ['n' => count(\M4W\Service\Office::editable())])]) : $this->fail($err);
    }

    /** Expired documents: put back, download or delete for good. */
    public function archivedFile(string $id): Response
    {
        $f = DB::one('SELECT * FROM fs_files WHERE id = :id AND archived_at > 0', ['id' => (int) $id]);
        if (!$f) {
            throw new HttpException(404, t('files.not_found'));
        }
        $action = $this->req->str('action');
        if ($this->req->method === 'GET') {
            $tmp = \M4W\Service\Files::toTempFile($f['blob_id']);
            register_shutdown_function(static fn() => @unlink($tmp));
            Audit::log('files.archive_downloaded', $f['name']);
            return Response::file($tmp, 'application/octet-stream', $f['name']);
        }
        if ($action === 'restore') {
            \M4W\Service\Files::restoreArchived((int) $f['id'], $this->uid());
            return $this->back('/admin/files#archive', 'success', t('files.archive_restored', ['name' => $f['name']]));
        }
        if ($action === 'delete') {
            \M4W\Service\Files::destroyFile((int) $f['id']);
            Audit::log('files.archive_deleted', $f['name']);
            return $this->back('/admin/files#archive', 'success', t('files.archive_deleted', ['name' => $f['name']]));
        }
        return $this->back('/admin/files#archive');
    }

    public function spam(?array $test = null): Response
    {
        $since = time() - 7 * 86400;
        $count = static fn(string $like) => (int) DB::value("SELECT COUNT(*) FROM mail_log WHERE direction = 'in' AND created_at > :t AND info LIKE :l", ['t' => $since, 'l' => $like]);
        $stats = [
            'received' => (int) DB::value("SELECT COUNT(*) FROM mail_log WHERE direction = 'in' AND created_at > :t", ['t' => $since]),
            'suspect' => $count('%, junk%'),
            'quarantine' => $count('quarantined%'),
            'delete' => $count('spam deleted%'),
            'held' => (int) DB::value("SELECT COUNT(*) FROM deleted_messages WHERE reason = 'quarantine' AND restored_at = 0"),
        ];
        return $this->page('spam', ['stats' => $stats, 'test' => $test]);
    }

    public function forgetReported(): Response
    {
        \M4W\Service\PhishReport::forgetSender($this->req->str('address'));
        Audit::log('admin.reported_forgotten', $this->req->str('address'));
        return $this->back('/admin/spam#reported', 'success', t('settings.saved'));
    }

    public function saveSpam(): Response
    {
        $r = $this->req;
        if ($r->bool('bayes_reset')) {
            \M4W\Service\Bayes::reset();
            Audit::log('admin.bayes_reset');
            return $this->back('/admin/spam#bayes', 'success', t('bayes.reset_done'));
        }
        $sus = max(1, min(100, (float) $r->str('suspect', '5')));
        $qua = (float) $r->str('quarantine', '10');
        $del = (float) $r->str('delete', '20');
        // Each level above the previous one; 0 switches a level off.
        $qua = $qua <= 0 ? 0 : max($sus + 0.5, min(200, $qua));
        $del = $del <= 0 ? 0 : max(max($sus, $qua) + 0.5, min(500, $del));
        $zones = [];
        foreach (preg_split('/[\s,;]+/', mb_strtolower($r->str('dnsbl'))) ?: [] as $z) {
            if (preg_match('/^(?:[a-z0-9-]+\.)+[a-z]{2,}$/', $z)) {
                $zones[] = $z;
            }
        }
        Settings::setMany([
            'spam.enabled' => $r->bool('enabled') ? 1 : 0,
            'spam.suspect' => $sus,
            'spam.quarantine' => $qua,
            'spam.delete' => $del,
            'spam.allow' => self::senderList($r->str('allow')),
            'spam.block' => self::senderList($r->str('block')),
            'spam.dnsbl' => implode("\n", array_slice(array_unique($zones), 0, 5)),
            'spam.keep_days' => max(1, min(365, $r->int('keep_days', 30))),
            'inbound.spam_header' => $r->bool('upstream') ? 1 : 0,
            'spam.bayes' => $r->bool('bayes') ? 1 : 0,
        ]);
        Audit::log('admin.spam_saved');
        return $this->back('/admin/spam', 'success', t('settings.saved'));
    }

    /** Score a pasted message source with the current settings, without delivering it. */
    public function testSpam(): Response
    {
        $raw = str_replace(["\r\n", "\r"], "\n", mb_substr($this->req->str('source'), 0, 2_000_000));
        if (trim($raw) === '') {
            return $this->back('/admin/spam', 'danger', t('admin.spam_test_empty'));
        }
        $p = \M4W\Mail\MimeParser::parse($raw);
        $r = \M4W\Service\SpamFilter::evaluate($p, null, '');
        $r['level'] = $r['list'] === 'block' ? 'delete' : ($r['list'] === 'allow' ? 'ok' : \M4W\Service\SpamFilter::level($r['score']));
        $r['subject'] = $p->subject();
        $r['from'] = $p->from()['email'];
        $r['source'] = $raw;
        return $this->spam($r);
    }

    /** Addresses or domains, one per line, lower case. */
    private static function senderList(string $raw): string
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', mb_strtolower($raw)) ?: [] as $d) {
            $d = trim($d);
            if (is_valid_email($d) || preg_match('/^@?(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $d)) {
                $out[ltrim($d, '@')] = true;
            }
        }
        return implode("\n", array_keys($out));
    }

    public function security(): Response
    {
        $blocked = DB::all('SELECT ip, COUNT(*) AS n, MAX(created_at) AS last FROM login_attempts WHERE success = 0 AND created_at > :t GROUP BY ip ORDER BY n DESC LIMIT 20', ['t' => time() - 86400]);
        $sendBlocked = DB::all('SELECT id, email, send_blocked_until, send_block_reason, status FROM users WHERE send_blocked_until > :t ORDER BY email', ['t' => time()]);
        $held = [];
        foreach ($sendBlocked as $b) {
            $held[(int) $b['id']] = (int) DB::value("SELECT COUNT(*) FROM mail_queue WHERE user_id = :u AND status = 'held'", ['u' => $b['id']]);
        }
        return $this->page('security', ['blocked' => $blocked, 'sendBlocked' => $sendBlocked, 'held' => $held, 'alerts' => \M4W\Service\AccountGuard::openAlerts(40)]);
    }

    /** Blocked accounts and security alerts: unblock, discard the held mail, mark as handled. */
    public function guardAction(): Response
    {
        $admin = (int) $this->user()['id'];
        $uid = $this->req->int('user_id');
        switch ($this->req->str('action')) {
            case 'unblock':
                \M4W\Service\AccountGuard::unblock($uid, $admin);
                return $this->back('/admin/security#guard', 'success', t('guard.unblocked'));
            case 'discard':
                $n = \M4W\Service\AccountGuard::discardHeld($uid, $admin);
                return $this->back('/admin/security#guard', 'success', t('guard.discarded', ['n' => $n]));
            case 'resolve':
                \M4W\Service\AccountGuard::resolve($this->req->int('alert_id'), $admin);
                return $this->back('/admin/security#guard', 'success', t('guard.resolved'));
            case 'block':
                \M4W\Service\AccountGuard::block($uid, 'admin', t('guard.reason_admin', ['name' => $this->user()['email']]));
                return $this->back('/admin/security#guard', 'success', t('guard.blocked_by_admin'));
        }
        return $this->back('/admin/security#guard');
    }

    public function saveSecurity(): Response
    {
        $r = $this->req;
        if ($r->bool('unlock_all')) {
            DB::run('DELETE FROM login_attempts WHERE success = 0');
            Audit::log('admin.unlock_all');
            return $this->back('/admin/security', 'success', t('admin.unlocked'));
        }
        $values = [
            'security.password_min' => max(8, min(64, $r->int('password_min', 10))),
            'security.password_classes' => max(1, min(4, $r->int('password_classes', 3))),
            'security.max_attempts' => max(3, min(50, $r->int('max_attempts', 5))),
            'security.lockout_minutes' => max(1, min(1440, $r->int('lockout_minutes', 15))),
            'security.session_idle' => max(5, min(1440, $r->int('session_idle', 30))),
            'security.session_absolute' => max(30, min(43200, $r->int('session_absolute', 720))),
            'security.session_keepalive' => $r->bool('session_keepalive') ? 1 : 0,
            'security.trusted_enabled' => $r->bool('trusted_enabled') ? 1 : 0,
            'security.trusted_idle_days' => max(1, min(90, $r->int('trusted_idle_days', 7))),
            'security.trusted_days' => max(1, min(365, $r->int('trusted_days', 30))),
            'security.session_persist' => $r->bool('session_persist') ? 1 : 0,
            'offline.enabled' => $r->bool('offline_enabled') ? 1 : 0,
            'security.enforce_2fa_admin' => $r->bool('enforce_2fa_admin') ? 1 : 0,
            'roles.admin_users' => $r->bool('admin_users') ? 1 : 0,
            'security.allow_external_forward' => $r->bool('allow_external_forward') ? 1 : 0,
            'security.forward_whitelist' => mb_strtolower($r->str('forward_whitelist')),
            'security.block_remote_images' => $r->bool('block_remote_images') ? 1 : 0,
            'security.image_proxy' => $r->bool('image_proxy') ? 1 : 0,
            'security.bimi' => in_array($r->str('bimi'), ['dmarc', 'vmc', 'off'], true) ? $r->str('bimi') : 'dmarc',
            'security.antivirus' => $r->bool('antivirus') ? 1 : 0,
            'security.clamd' => preg_match('#^(unix:///[\w./-]+|tcp://[a-z0-9.\-\[\]:]+:\d{1,5})$#i', $r->str('clamd')) ? $r->str('clamd') : 'unix:///var/run/clamav/clamd.ctl',
            'security.block_executables' => $r->bool('block_executables') ? 1 : 0,
            'security.disposable_action' => in_array($r->str('disposable_action'), ['reject', 'spam', 'off'], true) ? $r->str('disposable_action') : 'reject',
            'security.disposable_extra' => self::domainList($r->str('disposable_extra')),
            'security.disposable_allow' => self::domainList($r->str('disposable_allow')),
            'security.disposable_autoupdate' => $r->bool('disposable_autoupdate') ? 1 : 0,
            'security.link_check' => $r->bool('link_check') ? 1 : 0,
            'security.idn_links' => in_array($r->str('idn_links'), ['block', 'suspicious', 'warn'], true) ? $r->str('idn_links') : 'block',
            'security.link_block' => self::domainList($r->str('link_block')),
            'security.safebrowsing_api' => $r->str('safebrowsing_api') === 'webrisk' ? 'webrisk' : 'safebrowsing',
            'security.safebrowsing_delivery' => $r->bool('safebrowsing_delivery') ? 1 : 0,
            'security.urlhaus' => $r->bool('urlhaus') ? 1 : 0,
            'security.image_max_px' => max(200, min(4000, $r->int('image_max_px', 1600))),
            'security.max_attachment_mb' => max(1, min(100, $r->int('max_attachment_mb', 25))),
            'security.allowed_ips_admin' => $r->str('allowed_ips_admin'),
            'delegation.admins' => $r->bool('deleg_admins') ? 1 : 0,
            'delegation.require_reason' => $r->bool('deleg_reason') ? 1 : 0,
            'delegation.notify_owner' => $r->bool('deleg_notify') ? 1 : 0,
            'delegation.hide_personal' => $r->bool('deleg_private') ? 1 : 0,
            'features.fetch_accounts' => $r->bool('fetch_accounts') ? 1 : 0,
            'push.enabled' => $r->bool('push_enabled') ? 1 : 0,
            'push.preview' => $r->int('push_preview', 1) === 0 ? 0 : 1,
            'features.undo_send_seconds' => max(0, min(30, $r->int('undo_send_seconds', 5))),
            'features.default_language' => isset(I18n::LANGUAGES[$r->str('default_language')]) ? $r->str('default_language') : 'fr',
            'guard.enabled' => $r->bool('guard_enabled') ? 1 : 0,
            'guard.hour_limit' => max(1, min(100000, $r->int('guard_hour', 200))),
            'guard.day_limit' => max(1, min(1000000, $r->int('guard_day', 1000))),
            'guard.burst' => max(5, min(100000, $r->int('guard_burst', 80))),
            'guard.burst_minutes' => max(1, min(120, $r->int('guard_burst_minutes', 10))),
            'guard.on_burst' => $r->str('guard_on_burst') === 'lock' ? 'lock' : 'block_send',
            'guard.new_login_mail' => $r->bool('guard_login_mail') ? 1 : 0,
            'guard.alert_admins_all' => $r->bool('guard_admins_all') ? 1 : 0,
            'security.cloudflare' => $r->bool('cloudflare') ? 1 : 0,
        ];
        // Proxies in front of the server: addresses or ranges only.
        $proxies = [];
        foreach (preg_split('/[\s,;]+/', $r->str('trusted_proxies')) ?: [] as $c) {
            [$net, $bits] = array_pad(explode('/', $c, 2), 2, null);
            if ($c !== '' && filter_var($net, FILTER_VALIDATE_IP) && ($bits === null || (ctype_digit($bits) && (int) $bits <= (str_contains($net, ':') ? 128 : 32)))) {
                $proxies[] = $c;
            } elseif ($c !== '') {
                return $this->back('/admin/security', 'danger', t('admin.proxies_bad', ['value' => $c]));
            }
        }
        $values['security.trusted_proxies'] = implode(', ', $proxies);
        if ($values['security.allowed_ips_admin'] !== '') {
            // The address of this request as the new proxy settings will see it.
            $before = ['security.cloudflare' => Settings::get('security.cloudflare', 1), 'security.trusted_proxies' => Settings::get('security.trusted_proxies', '')];
            Settings::setMany(['security.cloudflare' => $values['security.cloudflare'], 'security.trusted_proxies' => $values['security.trusted_proxies']]);
            $ip = \M4W\Core\Proxies::client($this->req->remoteAddr(), $this->req->header('X-Forwarded-For'), $this->req->header('CF-Connecting-IP'));
            $ok = false;
            foreach (preg_split('/[\s,;]+/', $values['security.allowed_ips_admin']) ?: [] as $c) {
                if ($c !== '' && \M4W\Core\App::ipInCidr($ip, $c)) {
                    $ok = true;
                }
            }
            if (!$ok) {
                Settings::setMany($before);
                return $this->back('/admin/security', 'danger', t('admin.ip_lockout'));
            }
        }
        Settings::setMany($values);
        \M4W\Service\Senders::reset();
        $key = trim($r->str('safebrowsing_key'));
        if ($key !== '' && preg_match('/^[A-Za-z0-9_\-]{20,100}$/', $key)) {
            \M4W\Service\LinkGuard::setApiKey($key);
        } elseif ($r->bool('safebrowsing_clear')) {
            \M4W\Service\LinkGuard::setApiKey('');
        }
        if ($r->bool('urlhaus_update')) {
            try {
                $n = \M4W\Service\LinkGuard::updateFeed();
                return $this->back('/admin/security', 'success', t('admin.links_urlhaus_ok', ['n' => number_format($n, 0, ',', ' ')]));
            } catch (\Throwable $e) {
                return $this->back('/admin/security', 'danger', t('admin.disp_update_fail', ['error' => $e->getMessage()]));
            }
        }
        Audit::log('admin.security_saved');
        if ($r->bool('disposable_update')) {
            try {
                $n = \M4W\Service\Senders::update();
                return $this->back('/admin/security', 'success', t('admin.disp_update_ok', ['n' => number_format($n, 0, ',', ' ')]));
            } catch (\Throwable $e) {
                return $this->back('/admin/security', 'danger', t('admin.disp_update_fail', ['error' => $e->getMessage()]));
            }
        }
        return $this->back('/admin/security', 'success', t('settings.saved'));
    }

    /** One valid domain per line, lower case. */
    private static function domainList(string $raw): string
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', mb_strtolower($raw)) ?: [] as $d) {
            $d = trim($d, " .@\t");
            if (preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $d)) {
                $out[$d] = true;
            }
        }
        return implode("\n", array_keys($out));
    }

    // ---- Logs ----------------------------------------------------------------

    public function logs(): Response
    {
        $tab = (string) ($this->req->query['tab'] ?? 'mail');
        $q = trim((string) ($this->req->query['q'] ?? ''));
        $p = $q !== '' ? ['q' => '%' . mb_strtolower($q) . '%'] : [];
        $mail = DB::all('SELECT * FROM mail_log' . ($q !== '' ? ' WHERE LOWER(sender) LIKE :q OR LOWER(recipients) LIKE :q OR LOWER(subject) LIKE :q' : '') . ' ORDER BY id DESC LIMIT 200', $p);
        $audit = DB::all('SELECT a.*, u.email FROM audit_log a LEFT JOIN users u ON u.id = a.user_id' . ($q !== '' ? ' WHERE LOWER(a.action) LIKE :q OR LOWER(a.target) LIKE :q OR LOWER(u.email) LIKE :q OR a.ip LIKE :q' : '') . ' ORDER BY a.id DESC LIMIT 200', $p);
        $queue = DB::all('SELECT * FROM mail_queue ORDER BY id DESC LIMIT 200');
        $bounces = \M4W\Service\HardBounces::all($tab === 'bounces' ? $q : '');
        return $this->page('logs', compact('tab', 'mail', 'audit', 'queue', 'q', 'bounces'));
    }

    /** An address refused for good that exists again: it may be written to again. */
    public function removeBounce(): Response
    {
        $email = \M4W\Service\HardBounces::remove($this->req->int('id'));
        return $this->back('/admin/logs?tab=bounces', 'success', $email ? t('bounce.removed', ['email' => $email]) : t('settings.saved'));
    }

    public function addBounce(): Response
    {
        $ok = \M4W\Service\HardBounces::addByHand($this->req->str('email'), $this->req->str('note'));
        return $this->back('/admin/logs?tab=bounces', $ok ? 'success' : 'danger', $ok ? t('settings.saved') : t('compose.invalid_recipient', ['email' => $this->req->str('email')]));
    }

    public function retryQueue(): Response
    {
        // A message set aside (it stopped the worker) is given its chances again.
        DB::run("UPDATE mail_queue SET status = 'pending', next_attempt_at = 0, crashes = 0 WHERE id = :id AND status IN ('failed','pending','parked')", ['id' => $this->req->int('id')]);
        Transport::processQueue(10);
        return $this->back('/admin/logs?tab=queue', 'success', t('admin.queue_retried'));
    }

    public function deleteQueue(): Response
    {
        $row = DB::one('SELECT * FROM mail_queue WHERE id = :id', ['id' => $this->req->int('id')]);
        if ($row) {
            @unlink(storage_path($row['raw_path']));
            DB::delete('mail_queue', 'id = :id', ['id' => $row['id']]);
        }
        return $this->back('/admin/logs?tab=queue', 'success', t('settings.saved'));
    }
}
