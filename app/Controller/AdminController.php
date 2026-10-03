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
        $checks = [
            'smtp'  => trim((string) Settings::get('smtp.host', '')) !== '',
            'dkim'  => Settings::get('smtp.dkim_selector', '') !== '' && Settings::get('smtp.dkim_private', '') !== '',
            'https' => $this->req->isSecure(),
            'logo'  => Settings::get('brand.logo', '') !== '',
            'cron'  => (int) Settings::get('system.cron_last', 0) > time() - 900,
            'twofa' => (bool) DB::value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND totp_enabled = 1"),
        ];
        $recent = DB::all('SELECT a.*, u.email FROM audit_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 8');
        $top = DB::all('SELECT id, email, display_name, used_bytes, quota_mb FROM users ORDER BY used_bytes DESC LIMIT 5');
        return $this->page('dashboard', compact('stats', 'days', 'checks', 'recent', 'top'));
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
            $where .= " AND u.role = 'admin'";
        }
        $users = DB::all("SELECT u.*, t.name AS template_name FROM users u LEFT JOIN signature_templates t ON t.id = u.signature_template_id WHERE $where ORDER BY u.email", $p);
        return $this->page('users', ['users' => $users, 'q' => $q, 'status' => $status, 'templates' => Signatures::all()]);
    }

    public function userForm(?string $id = null): Response
    {
        $user = $id ? Users::find((int) $id) : null;
        if ($id && !$user) {
            throw new HttpException(404, t('error.not_found'));
        }
        $aliases = $user ? DB::all('SELECT * FROM aliases WHERE user_id = :u ORDER BY address', ['u' => $user['id']]) : [];
        return $this->page('user_form', [
            'user' => $user,
            'aliases' => $aliases,
            'templates' => Signatures::all(),
            'domains' => DB::all('SELECT * FROM domains WHERE active = 1 ORDER BY name'),
            'languages' => I18n::LANGUAGES,
            'departments' => array_column(DB::all("SELECT DISTINCT department FROM users WHERE department <> '' ORDER BY department"), 'department'),
            'section' => 'users',
        ]);
    }

    public function saveUser(): Response
    {
        $r = $this->req;
        $id = $r->int('id');
        $data = [
            'display_name' => $r->str('display_name'), 'first_name' => $r->str('first_name'), 'last_name' => $r->str('last_name'),
            'job_title' => $r->str('job_title'), 'department' => $r->str('department'), 'phone' => $r->str('phone'),
            'mobile' => $r->str('mobile'), 'role' => $r->str('role') === 'admin' ? 'admin' : 'user',
            'status' => $r->str('status') === 'disabled' ? 'disabled' : 'active', 'quota_mb' => max(0, $r->int('quota_mb', 2048)),
            'signature_template_id' => $r->int('signature_template_id') ?: null,
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
            if ($id === $this->uid() && ($data['role'] !== 'admin' || $data['status'] !== 'active')) {
                return $this->back($redirect, 'danger', t('admin.cannot_demote_self'));
            }
            if ($pw !== '') {
                $data['password'] = $pw;
            }
            Users::update($id, $data);
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
        if ($r->bool('send_welcome')) {
            \M4W\Service\Installer::welcome($newId);
        }
        return $this->back('/admin/users', 'success', t('admin.user_created', ['email' => $email]));
    }

    public function deleteUser(): Response
    {
        $id = $this->req->int('id');
        if ($id === $this->uid()) {
            return $this->back('/admin/users', 'danger', t('admin.cannot_delete_self'));
        }
        $u = Users::find($id);
        if ($u) {
            Users::delete($id);
            Audit::log('admin.user_deleted', $u['email']);
        }
        return $this->back('/admin/users', 'success', t('admin.user_deleted'));
    }

    public function bulkUsers(): Response
    {
        $ids = array_filter(array_map('intval', $this->req->arr('ids')), fn($i) => $i !== $this->uid());
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
                case 'template':
                    Users::update($id, ['signature_template_id' => $this->req->int('template_id') ?: null]);
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
            Users::create([
                'email' => $email, 'first_name' => $c[1] ?? '', 'last_name' => $c[2] ?? '', 'job_title' => $c[3] ?? '',
                'department' => $c[4] ?? '', 'phone' => $c[5] ?? '', 'password' => $pw, 'must_change_password' => 1,
                'display_name' => trim(($c[1] ?? '') . ' ' . ($c[2] ?? '')),
            ]);
            $n++;
        }
        Audit::log('admin.users_imported', (string) $n);
        $msg = t('admin.imported', ['n' => $n]) . ($errors ? ' ' . t('admin.import_skipped', ['list' => implode(', ', array_slice($errors, 0, 10))]) : '');
        return $this->back('/admin/users', $errors ? 'warning' : 'success', $msg);
    }

    public function reset2fa(): Response
    {
        $id = $this->req->int('id');
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
        if (!Users::find($uid)) {
            return $this->back($back, 'danger', t('error.not_found'));
        }
        DB::insert('aliases', ['address' => $addr, 'user_id' => $uid, 'created_at' => time()]);
        Audit::log('admin.alias_created', $addr);
        return $this->back($back, 'success', t('settings.saved'));
    }

    public function deleteAlias(): Response
    {
        $a = DB::one('SELECT * FROM aliases WHERE id = :id', ['id' => $this->req->int('id')]);
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
            $t['preview'] = Signatures::renderTemplate((string) $t['html'], $sample);
        }
        return $this->page('signatures', ['templates' => $templates, 'departments' => array_column(DB::all("SELECT DISTINCT department FROM users WHERE department <> '' ORDER BY department"), 'department')]);
    }

    public function signatureForm(?string $id = null): Response
    {
        $tpl = $id ? Signatures::find((int) $id) : ['id' => 0, 'name' => '', 'description' => '', 'html' => Signatures::defaultTemplate(), 'is_default' => 0, 'apply_on_reply' => 1];
        if (!$tpl) {
            throw new HttpException(404);
        }
        $users = DB::all("SELECT id, email, display_name FROM users WHERE status = 'active' ORDER BY display_name LIMIT 500");
        return $this->page('signature_form', ['tpl' => $tpl, 'variables' => Signatures::VARIABLES, 'users' => $users, 'section' => 'signatures']);
    }

    public function saveSignature(): Response
    {
        $id = $this->req->int('id') ?: null;
        $id = Signatures::save([
            'name' => $this->req->str('name'), 'description' => $this->req->str('description'), 'html' => $this->req->raw('html'),
            'is_default' => $this->req->bool('is_default'), 'apply_on_reply' => $this->req->bool('apply_on_reply'),
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
        $html = Signatures::renderTemplate(Signatures::cleanTemplate($this->req->raw('html')), $user);
        return $this->ok(['html' => $html]);
    }

    public function assignSignature(): Response
    {
        $tpl = $this->req->int('template_id') ?: null;
        $dept = $this->req->str('department');
        if ($dept === '*') {
            $n = DB::run('UPDATE users SET signature_template_id = :t', ['t' => $tpl])->rowCount();
        } else {
            $n = DB::run('UPDATE users SET signature_template_id = :t WHERE department = :d', ['t' => $tpl, 'd' => $dept])->rowCount();
        }
        Audit::log('admin.signature_assigned', $dept, ['template' => $tpl]);
        return $this->back('/admin/signatures', 'success', t('admin.bulk_done', ['n' => $n]));
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
            'brand.name' => mb_substr($r->str('name'), 0, 100) ?: 'Make4Web Mail',
            'brand.tagline' => mb_substr($r->str('tagline'), 0, 200),
            'brand.company' => mb_substr($r->str('company'), 0, 190),
            'brand.website' => preg_match('#^https?://#i', $r->str('website')) ? mb_substr($r->str('website'), 0, 190) : '',
            'brand.address' => mb_substr($r->str('address'), 0, 255),
            'brand.primary' => $color($r->str('primary'), '#2563eb'),
            'brand.accent' => $color($r->str('accent'), '#0ea5e9'),
            'brand.sidebar' => in_array($r->str('sidebar'), ['light', 'dark', 'brand'], true) ? $r->str('sidebar') : 'light',
            'brand.radius' => max(0, min(24, $r->int('radius', 10))),
            'brand.font' => $r->str('font') === 'system' ? 'system' : 'inter',
            'brand.login_message' => mb_substr($r->str('login_message'), 0, 500),
            'brand.footer' => mb_substr($r->str('footer'), 0, 300),
            'brand.custom_css' => mb_substr($r->raw('custom_css'), 0, 20000),
        ];
        try {
            foreach (['logo' => 'logo', 'logo_dark' => 'logodark', 'favicon' => 'favicon', 'login_bg' => 'loginbg'] as $field => $prefix) {
                if ($r->bool('remove_' . $field)) {
                    $values['brand.' . $field] = '';
                }
                $f = $r->files[$field] ?? null;
                if (is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $values['brand.' . $field] = Branding::storeImage($f, $prefix, $field === 'login_bg' ? 6_000_000 : 2_000_000);
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
        return $this->page('mail', ['dkimPublic' => $dkimPublic, 'domains' => $domains, 'hasPassword' => Settings::get('smtp.password', '') !== '']);
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
        Settings::setMany($values);
        if ($r->raw('password') !== '') {
            Settings::setSecret('smtp.password', $r->raw('password'));
        } elseif ($r->bool('clear_password')) {
            Settings::set('smtp.password', '');
        }
        Audit::log('admin.mail_saved', $values['smtp.host']);
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
        return $this->ok(['message' => t('admin.test_ok', ['email' => $to])]);
    }

    // ---- Security ------------------------------------------------------------

    public function security(): Response
    {
        $blocked = DB::all('SELECT ip, COUNT(*) AS n, MAX(created_at) AS last FROM login_attempts WHERE success = 0 AND created_at > :t GROUP BY ip ORDER BY n DESC LIMIT 20', ['t' => time() - 86400]);
        return $this->page('security', ['blocked' => $blocked]);
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
            'security.session_idle' => max(5, min(1440, $r->int('session_idle', 60))),
            'security.session_absolute' => max(30, min(43200, $r->int('session_absolute', 720))),
            'security.enforce_2fa_admin' => $r->bool('enforce_2fa_admin') ? 1 : 0,
            'security.allow_external_forward' => $r->bool('allow_external_forward') ? 1 : 0,
            'security.forward_whitelist' => mb_strtolower($r->str('forward_whitelist')),
            'security.block_remote_images' => $r->bool('block_remote_images') ? 1 : 0,
            'security.max_attachment_mb' => max(1, min(100, $r->int('max_attachment_mb', 25))),
            'security.allowed_ips_admin' => $r->str('allowed_ips_admin'),
            'features.user_signature' => $r->bool('user_signature') ? 1 : 0,
            'features.fetch_accounts' => $r->bool('fetch_accounts') ? 1 : 0,
            'features.undo_send_seconds' => max(0, min(30, $r->int('undo_send_seconds', 5))),
            'features.default_language' => isset(I18n::LANGUAGES[$r->str('default_language')]) ? $r->str('default_language') : 'fr',
        ];
        if ($values['security.allowed_ips_admin'] !== '') {
            $ok = false;
            foreach (preg_split('/[\s,;]+/', $values['security.allowed_ips_admin']) ?: [] as $c) {
                if ($c !== '' && \M4W\Core\App::ipInCidr($this->req->ip(), $c)) {
                    $ok = true;
                }
            }
            if (!$ok) {
                return $this->back('/admin/security', 'danger', t('admin.ip_lockout'));
            }
        }
        Settings::setMany($values);
        Audit::log('admin.security_saved');
        return $this->back('/admin/security', 'success', t('settings.saved'));
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
        return $this->page('logs', compact('tab', 'mail', 'audit', 'queue', 'q'));
    }

    public function retryQueue(): Response
    {
        DB::run("UPDATE mail_queue SET status = 'pending', next_attempt_at = 0 WHERE id = :id AND status IN ('failed','pending')", ['id' => $this->req->int('id')]);
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
