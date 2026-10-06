<?php
declare(strict_types=1);

namespace M4W\Core;

use M4W\Controller;

final class App
{
    private static ?Request $request = null;

    public static function request(): ?Request
    {
        return self::$request;
    }

    public static function run(): void
    {
        $req = Request::fromGlobals();
        self::$request = $req;
        try {
            $res = self::handle($req);
        } catch (HttpException $e) {
            $res = self::errorResponse($req, $e->status, $e->getMessage());
        } catch (\Throwable $e) {
            error_log('[m4w] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
            $msg = Config::get('debug') ? $e->getMessage() : t('error.generic');
            $res = self::errorResponse($req, 500, $msg);
        }
        self::securityHeaders($req, $res);
        $res->send();
        self::afterResponse();
    }

    /**
     * Autonomy without cron: once the response is flushed, process due outgoing
     * mail (forwards, auto-replies, retries) if the scheduler has not run recently.
     */
    private static function afterResponse(): void
    {
        // Only when the response can be detached from the worker (PHP-FPM / LiteSpeed);
        // otherwise slow remote MX servers would hold the client connection open.
        $canDetach = function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
        if (!Config::installed() || !$canDetach) {
            return;
        }
        try {
            if ((int) Settings::get('system.cron_last', 0) > time() - 120) {
                return;
            }
            $due = (int) Database::value("SELECT COUNT(*) FROM mail_queue WHERE status = 'pending' AND next_attempt_at <= :n", ['n' => time()]);
            $scheduled = (int) Database::value('SELECT COUNT(*) FROM messages WHERE is_draft = 1 AND scheduled_at > 0 AND scheduled_at <= :n', ['n' => time()]);
            if ($due === 0 && $scheduled === 0) {
                return;
            }
            Session::close();
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                litespeed_finish_request();
            }
            \M4W\Service\Composer::processScheduled(10);
            \M4W\Service\Transport::processQueue(10);
        } catch (\Throwable $e) {
            error_log('[m4w] queue: ' . $e->getMessage());
        }
    }

    private static function handle(Request $req): Response
    {
        $router = new Router();
        self::routes($router);

        // Static-ish public endpoints that don't need a session.
        if (!Config::installed()) {
            if (!in_array($req->path, ['/install', '/theme.css'], true)) {
                return Response::redirect('/install');
            }
        }
        if (Config::installed()) {
            \M4W\Database\Migrator::migrate();
        }
        Session::start($req);
        $user = Config::installed() ? Auth::user() : null;
        I18n::setLanguage($user['language'] ?? (Session::get('lang') ?: I18n::detect($req->header('Accept-Language'))));
        View::share('currentUser', $user);
        View::share('delegation', $user ? \M4W\Service\Delegation::banner($user) : null);
        View::share('req', $req);

        $match = $router->match($req);
        if ($match === null) {
            throw new HttpException(404, t('error.not_found'));
        }
        if ($match === false) {
            throw new HttpException(405, 'Method not allowed');
        }
        [$handler, $params, $opts] = $match;
        $req->params = $params;

        $access = $opts['auth'] ?? 'user';
        if ($access !== 'public') {
            if (!$user) {
                if ($req->isAjax()) {
                    return Response::json(['ok' => false, 'error' => t('auth.session_expired'), 'login' => true], 401);
                }
                Session::set('intended', $req->path);
                return Response::redirect('/login');
            }
            if ($user['must_change_password'] && !in_array($req->path, ['/password/change', '/logout'], true)) {
                return $req->isAjax() ? Response::json(['ok' => false, 'error' => t('auth.must_change')], 403) : Response::redirect('/password/change');
            }
            if ($access === 'admin') {
                if ($user['role'] !== 'admin') {
                    throw new HttpException(403, t('error.forbidden'));
                }
                self::checkAdminIp($req);
                if ((int) Settings::get('security.enforce_2fa_admin', 0) && !$user['totp_enabled'] && $req->path !== '/settings/security') {
                    Session::flash('warning', t('admin.need_2fa'));
                    return Response::redirect('/settings/security');
                }
            }
        }
        if (!in_array($req->method, ['GET', 'HEAD'], true) && !($opts['nocsrf'] ?? false) && !Csrf::verify($req)) {
            if ($req->isAjax()) {
                return Response::json(['ok' => false, 'error' => t('error.csrf')], 419);
            }
            Session::flash('danger', t('error.csrf'));
            return Response::redirect($req->path);
        }

        if (is_array($handler)) {
            [$class, $method] = $handler;
            $controller = new $class($req);
            return $controller->$method(...array_values($params));
        }
        return $handler($req, ...array_values($params));
    }

    private static function checkAdminIp(Request $req): void
    {
        $list = trim((string) Settings::get('security.allowed_ips_admin', ''));
        if ($list === '') {
            return;
        }
        $ip = $req->ip();
        foreach (preg_split('/[\s,;]+/', $list) ?: [] as $cidr) {
            if ($cidr !== '' && self::ipInCidr($ip, $cidr)) {
                return;
            }
        }
        throw new HttpException(403, t('error.ip_forbidden'));
    }

    public static function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }
        [$net, $bits] = explode('/', $cidr, 2);
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $rem = $bits % 8;
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        if ($rem === 0) {
            return true;
        }
        $mask = chr((0xff << (8 - $rem)) & 0xff);
        return (($ipBin[$bytes] & $mask) === ($netBin[$bytes] & $mask));
    }

    private static function routes(Router $r): void
    {
        $pub = ['auth' => 'public'];
        $admin = ['auth' => 'admin'];
        $r->get('/install', [Controller\InstallController::class, 'form'], $pub);
        $r->post('/install', [Controller\InstallController::class, 'install'], $pub);
        $r->get('/theme.css', [Controller\AssetController::class, 'theme'], $pub);
        $r->get('/brand/{file}', [Controller\AssetController::class, 'brand'], $pub);
        $r->get('/manifest.webmanifest', [Controller\AssetController::class, 'manifest'], $pub);

        $r->get('/', [Controller\AuthController::class, 'home'], $pub);
        $r->get('/login', [Controller\AuthController::class, 'loginForm'], $pub);
        $r->post('/login', [Controller\AuthController::class, 'login'], $pub);
        $r->get('/login/2fa', [Controller\AuthController::class, 'twoFactorForm'], $pub);
        $r->post('/login/2fa', [Controller\AuthController::class, 'twoFactor'], $pub);
        $r->post('/logout', [Controller\AuthController::class, 'logout']);
        $r->get('/password/change', [Controller\AuthController::class, 'changeForm']);
        $r->post('/password/change', [Controller\AuthController::class, 'change']);
        $r->get('/lang/{code}', [Controller\AuthController::class, 'lang'], $pub);

        // Webmail (SPA shell + JSON API)
        $r->get('/mail', [Controller\MailController::class, 'shell']);
        $r->get('/api/bootstrap', [Controller\MailController::class, 'bootstrap']);
        $r->get('/api/folders', [Controller\MailController::class, 'folders']);
        $r->post('/api/folders', [Controller\MailController::class, 'createFolder']);
        $r->post('/api/folders/{id}/update', [Controller\MailController::class, 'updateFolder']);
        $r->post('/api/folders/{id}/delete', [Controller\MailController::class, 'deleteFolder']);
        $r->post('/api/folders/{id}/empty', [Controller\MailController::class, 'emptyFolder']);
        $r->post('/api/folders/{id}/read-all', [Controller\MailController::class, 'readAll']);
        $r->get('/api/messages', [Controller\MailController::class, 'list']);
        $r->post('/api/messages/action', [Controller\MailController::class, 'action']);
        $r->get('/api/messages/{id}', [Controller\MailController::class, 'show']);
        $r->get('/api/messages/{id}/body', [Controller\MailController::class, 'body']);
        $r->get('/api/messages/{id}/part/{part}', [Controller\MailController::class, 'part']);
        $r->get('/api/messages/{id}/source', [Controller\MailController::class, 'source']);
        $r->get('/api/messages/{id}/download', [Controller\MailController::class, 'download']);
        $r->get('/api/messages/{id}/print', [Controller\MailController::class, 'printView']);
        $r->post('/api/messages/{id}/unsubscribe', [Controller\MailController::class, 'unsubscribe']);
        $r->get('/api/poll', [Controller\MailController::class, 'poll']);
        $r->post('/api/prefs', [Controller\MailController::class, 'prefs']);

        $r->get('/api/compose', [Controller\ComposeController::class, 'prefill']);
        $r->post('/api/compose/send', [Controller\ComposeController::class, 'send']);
        $r->post('/api/compose/draft', [Controller\ComposeController::class, 'draft']);
        $r->post('/api/compose/schedule', [Controller\ComposeController::class, 'schedule']);
        $r->get('/api/messages/{id}/zip', [Controller\MailController::class, 'zip']);
        $r->post('/api/upload', [Controller\ComposeController::class, 'upload']);
        $r->get('/api/upload/{token}', [Controller\ComposeController::class, 'uploadPreview']);
        $r->get('/api/contacts/suggest', [Controller\ComposeController::class, 'suggest']);
        $r->get('/api/signature/preview', [Controller\ComposeController::class, 'signaturePreview']);

        // Contacts
        $r->get('/contacts', [Controller\ContactsController::class, 'index']);
        $r->post('/contacts/save', [Controller\ContactsController::class, 'save']);
        $r->post('/contacts/delete', [Controller\ContactsController::class, 'delete']);
        $r->post('/contacts/import', [Controller\ContactsController::class, 'import']);
        $r->get('/contacts/export', [Controller\ContactsController::class, 'export']);

        // Mailbox delegation
        $r->post('/delegation/open', [Controller\DelegationController::class, 'open']);
        $r->post('/delegation/close', [Controller\DelegationController::class, 'close']);

        // Shared file space
        $r->get('/files', [Controller\FilesController::class, 'index']);
        $r->get('/files/file/{id}', [Controller\FilesController::class, 'download']);
        $r->get('/files/folder/{id}/zip', [Controller\FilesController::class, 'zip']);
        $r->get('/api/files/roots', [Controller\FilesController::class, 'roots']);
        $r->get('/api/files/tree', [Controller\FilesController::class, 'tree']);
        $r->get('/api/files/search', [Controller\FilesController::class, 'search']);
        $r->get('/api/files/trash', [Controller\FilesController::class, 'trash']);
        $r->get('/api/files/principals', [Controller\FilesController::class, 'principals']);
        $r->get('/api/files/folder/{id}', [Controller\FilesController::class, 'folder']);
        $r->get('/api/files/folder/{id}/acl', [Controller\FilesController::class, 'acl']);
        $r->post('/api/files/folder/{id}/acl', [Controller\FilesController::class, 'saveAcl']);
        $r->post('/api/files/space/{id}', [Controller\FilesController::class, 'updateSpace']);
        $r->get('/api/files/file/{id}/versions', [Controller\FilesController::class, 'versions']);
        $r->post('/api/files/file/{id}/restore-version', [Controller\FilesController::class, 'restoreVersion']);
        $r->post('/api/files/folders', [Controller\FilesController::class, 'createFolder']);
        $r->post('/api/files/spaces', [Controller\FilesController::class, 'createSpace']);
        $r->post('/api/files/upload', [Controller\FilesController::class, 'upload']);
        $r->post('/api/files/rename', [Controller\FilesController::class, 'rename']);
        $r->post('/api/files/move', [Controller\FilesController::class, 'move']);
        $r->post('/api/files/copy', [Controller\FilesController::class, 'copy']);
        $r->post('/api/files/delete', [Controller\FilesController::class, 'delete']);
        $r->post('/api/files/restore', [Controller\FilesController::class, 'restore']);
        $r->post('/api/files/purge', [Controller\FilesController::class, 'purge']);
        $r->post('/api/files/attach', [Controller\FilesController::class, 'attach']);
        $r->post('/api/messages/{id}/save-to-files', [Controller\FilesController::class, 'saveAttachment']);
        $r->get('/admin/files', [Controller\AdminController::class, 'files'], $admin);
        $r->post('/admin/files', [Controller\AdminController::class, 'saveFiles'], $admin);

        // User settings
        $r->get('/settings', [Controller\SettingsController::class, 'general']);
        $r->post('/settings', [Controller\SettingsController::class, 'saveGeneral']);
        $r->get('/settings/rules', [Controller\SettingsController::class, 'rules']);
        $r->post('/settings/rules/save', [Controller\SettingsController::class, 'saveRule']);
        $r->post('/settings/rules/delete', [Controller\SettingsController::class, 'deleteRule']);
        $r->post('/settings/rules/toggle', [Controller\SettingsController::class, 'toggleRule']);
        $r->post('/settings/rules/order', [Controller\SettingsController::class, 'orderRules']);
        $r->post('/settings/rules/run', [Controller\SettingsController::class, 'runRule']);
        $r->get('/settings/vacation', [Controller\SettingsController::class, 'vacation']);
        $r->post('/settings/vacation', [Controller\SettingsController::class, 'saveVacation']);
        $r->get('/settings/forwarding', [Controller\SettingsController::class, 'forwarding']);
        $r->post('/settings/forwarding', [Controller\SettingsController::class, 'saveForwarding']);
        $r->get('/settings/signature', [Controller\SettingsController::class, 'signature']);
        $r->post('/settings/signature', [Controller\SettingsController::class, 'saveSignature']);
        $r->get('/settings/security', [Controller\SettingsController::class, 'security']);
        $r->post('/settings/security/password', [Controller\SettingsController::class, 'changePassword']);
        $r->post('/settings/security/2fa/start', [Controller\SettingsController::class, 'start2fa']);
        $r->post('/settings/security/2fa/enable', [Controller\SettingsController::class, 'enable2fa']);
        $r->post('/settings/security/2fa/disable', [Controller\SettingsController::class, 'disable2fa']);
        $r->post('/settings/security/sessions/revoke', [Controller\SettingsController::class, 'revokeSessions']);
        $r->get('/settings/accounts', [Controller\SettingsController::class, 'accounts']);
        $r->post('/settings/accounts/save', [Controller\SettingsController::class, 'saveAccount']);
        $r->post('/settings/accounts/delete', [Controller\SettingsController::class, 'deleteAccount']);
        $r->post('/settings/accounts/run', [Controller\SettingsController::class, 'runAccount']);
        $r->get('/settings/folders', [Controller\SettingsController::class, 'folders']);

        // Administration
        $r->get('/admin', [Controller\AdminController::class, 'dashboard'], $admin);
        $r->get('/admin/users', [Controller\AdminController::class, 'users'], $admin);
        $r->get('/admin/users/new', [Controller\AdminController::class, 'userForm'], $admin);
        $r->get('/admin/users/{id}', [Controller\AdminController::class, 'userForm'], $admin);
        $r->post('/admin/users/save', [Controller\AdminController::class, 'saveUser'], $admin);
        $r->post('/admin/users/delete', [Controller\AdminController::class, 'deleteUser'], $admin);
        $r->post('/admin/users/bulk', [Controller\AdminController::class, 'bulkUsers'], $admin);
        $r->post('/admin/users/import', [Controller\AdminController::class, 'importUsers'], $admin);
        $r->post('/admin/users/reset2fa', [Controller\AdminController::class, 'reset2fa'], $admin);
        $r->get('/admin/domains', [Controller\AdminController::class, 'domains'], $admin);
        $r->post('/admin/domains/save', [Controller\AdminController::class, 'saveDomain'], $admin);
        $r->post('/admin/domains/delete', [Controller\AdminController::class, 'deleteDomain'], $admin);
        $r->post('/admin/aliases/save', [Controller\AdminController::class, 'saveAlias'], $admin);
        $r->post('/admin/aliases/delete', [Controller\AdminController::class, 'deleteAlias'], $admin);
        $r->get('/admin/signatures', [Controller\AdminController::class, 'signatures'], $admin);
        $r->get('/admin/signatures/new', [Controller\AdminController::class, 'signatureForm'], $admin);
        $r->get('/admin/signatures/{id}', [Controller\AdminController::class, 'signatureForm'], $admin);
        $r->post('/admin/signatures/save', [Controller\AdminController::class, 'saveSignature'], $admin);
        $r->post('/admin/signatures/delete', [Controller\AdminController::class, 'deleteSignature'], $admin);
        $r->post('/admin/signatures/preview', [Controller\AdminController::class, 'previewSignature'], $admin);
        $r->post('/admin/signatures/assign', [Controller\AdminController::class, 'assignSignature'], $admin);
        $r->get('/admin/branding', [Controller\AdminController::class, 'branding'], $admin);
        $r->post('/admin/branding', [Controller\AdminController::class, 'saveBranding'], $admin);
        $r->get('/admin/mail', [Controller\AdminController::class, 'mail'], $admin);
        $r->post('/admin/mail', [Controller\AdminController::class, 'saveMail'], $admin);
        $r->post('/admin/mail/test', [Controller\AdminController::class, 'testMail'], $admin);
        $r->post('/admin/mail/dkim', [Controller\AdminController::class, 'generateDkim'], $admin);
        $r->get('/admin/security', [Controller\AdminController::class, 'security'], $admin);
        $r->post('/admin/security', [Controller\AdminController::class, 'saveSecurity'], $admin);
        $r->get('/admin/logs', [Controller\AdminController::class, 'logs'], $admin);
        $r->post('/admin/queue/retry', [Controller\AdminController::class, 'retryQueue'], $admin);
        $r->post('/admin/queue/delete', [Controller\AdminController::class, 'deleteQueue'], $admin);
    }

    private static function errorResponse(Request $req, int $status, string $message): Response
    {
        if ($req->isAjax()) {
            return Response::json(['ok' => false, 'error' => $message], $status);
        }
        try {
            return Response::html(View::render('errors/error', ['status' => $status, 'message' => $message], 'layouts/blank'), $status);
        } catch (\Throwable) {
            return Response::html('<h1>' . $status . '</h1><p>' . e($message) . '</p>', $status);
        }
    }

    private static function securityHeaders(Request $req, Response $res): void
    {
        $res->header('X-Content-Type-Options', 'nosniff');
        $res->header('Referrer-Policy', 'no-referrer');
        $res->header('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $res->header('Cross-Origin-Opener-Policy', 'same-origin');
        $res->header('X-Permitted-Cross-Domain-Policies', 'none');
        if ($res->getHeader('Cross-Origin-Resource-Policy') === null) {
            $res->header('Cross-Origin-Resource-Policy', 'same-origin');
        }
        if ($res->getHeader('Content-Security-Policy') === null) {
            $res->header('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: blob:",
                "font-src 'self' data:",
                "connect-src 'self'",
                "frame-src 'self'",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
            ]));
        }
        if ($res->getHeader('X-Frame-Options') === null) {
            $res->header('X-Frame-Options', 'DENY');
        }
        if ($req->isSecure()) {
            $res->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        if ($res->getHeader('Cache-Control') === null) {
            $res->header('Cache-Control', 'no-store, private');
        }
    }
}
