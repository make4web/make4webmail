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
        // Calendar and contacts sync for phones: its own protocol, no session or CSRF token.
        if (\M4W\Dav\Server::handles($req->path)) {
            try {
                \M4W\Dav\Server::run($req);
            } catch (\Throwable $e) {
                error_log('[m4w] dav: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                http_response_code(500);
            }
            return;
        }
        try {
            $res = ($problem = self::startupProblem()) !== null ? self::startupPage($req, $problem) : self::handle($req);
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

    /** @var callable[] work to do once the response is sent (see later()) */
    private static array $later = [];

    /**
     * Work done right after the response, the person not waiting for it (e.g. the provider
     * mailbox told at once of what was just done here). Only where the response can be
     * detached (PHP-FPM / LiteSpeed); elsewhere the scheduled task does it a little later.
     */
    public static function later(callable $fn): void
    {
        self::$later[] = $fn;
    }

    private static function detach(): void
    {
        Session::close();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            litespeed_finish_request();
        }
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
        if (self::$later) {
            self::detach();
            foreach (self::$later as $fn) {
                try {
                    $fn();
                } catch (\Throwable $e) {
                    error_log('[m4w] after response: ' . $e->getMessage());
                }
            }
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
            if (!self::$later) {
                self::detach();
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
        View::share('sharedBox', $user ? \M4W\Service\SharedMailboxes::banner($user) : null);
        View::share('req', $req);
        // Remember the public address for links in scheduled messages; only from administrators,
        // so a forged Host header from anyone else can never end up in an e-mail.
        if ($user && is_admin($user) && $req->method === 'GET' && !$req->isAjax()) {
            $host = (string) ($req->server['HTTP_HOST'] ?? '');
            if (preg_match('/^[a-z0-9.-]+(:\d+)?$/i', $host)) {
                $pub = ($req->isSecure() ? 'https' : 'http') . '://' . strtolower($host) . Request::basePath();
                if ($pub !== Settings::get('system.public_url', '')) {
                    Settings::set('system.public_url', $pub);
                }
            }
        }

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
            if ($access === 'admin' || $access === 'superadmin' || $access === 'accounts') {
                if (!is_admin($user) || ($access === 'superadmin' && !is_superadmin($user)) || ($access === 'accounts' && !can_manage_users($user))) {
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

        $call = static function () use ($handler, $req, $params): Response {
            if (is_array($handler)) {
                [$class, $method] = $handler;
                $controller = new $class($req);
                return $controller->$method(...array_values($params));
            }
            return $handler($req, ...array_values($params));
        };
        // Work done offline and replayed: checked, run once, in order (see Offline::guard).
        $stamp = $req->header('X-M4W-Offline');
        if ($stamp !== '' && $user && $req->method === 'POST') {
            return \M4W\Service\Offline::guard(\M4W\Service\Delegation::mailbox($user), $req->path, $stamp, $call);
        }
        return $call();
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
        $admin = ['auth' => 'admin'];           // the customer's administrator (and the super administrator)
        $super = ['auth' => 'superadmin'];      // configuring the application itself
        $accounts = ['auth' => 'accounts'];     // the accounts: super administrator, administrators when allowed
        $r->get('/install', [Controller\InstallController::class, 'form'], $pub);
        $r->post('/install', [Controller\InstallController::class, 'install'], $pub);
        $r->get('/theme.css', [Controller\AssetController::class, 'theme'], $pub);
        $r->get('/brand/{file}', [Controller\AssetController::class, 'brand'], $pub);
        $r->get('/manifest.webmanifest', [Controller\AssetController::class, 'manifest'], $pub);
        $r->get('/app-icon/{variant}', [Controller\AssetController::class, 'appIcon'], $pub);

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
        $r->get('/api/bimi/{domain}', [Controller\MailController::class, 'bimi']);
        $r->get('/go', [Controller\LinkController::class, 'go']);
        $r->get('/api/messages/{id}/part/{part}', [Controller\MailController::class, 'part']);
        $r->get('/api/messages/{id}/part/{part}/preview', [Controller\MailController::class, 'partPreview']);
        $r->get('/api/messages/{id}/source', [Controller\MailController::class, 'source']);
        $r->get('/api/messages/{id}/download', [Controller\MailController::class, 'download']);
        $r->get('/api/messages/{id}/print', [Controller\MailController::class, 'printView']);
        $r->post('/api/messages/{id}/unsubscribe', [Controller\MailController::class, 'unsubscribe']);
        $r->post('/api/messages/{id}/phishing', [Controller\MailController::class, 'phishing']);
        $r->get('/api/poll', [Controller\MailController::class, 'poll']);
        $r->get('/api/unread', [Controller\MailController::class, 'unread']);
        $r->get('/api/ping', [Controller\MailController::class, 'ping']);
        $r->get('/api/stamp', [Controller\MailController::class, 'stamp']);
        $r->post('/api/offline/key', [Controller\OfflineController::class, 'key']);
        $r->get('/api/offline/manifest', [Controller\OfflineController::class, 'manifest']);
        $r->post('/api/offline/forget', [Controller\OfflineController::class, 'forget']);
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

        // Push notifications
        $r->get('/api/push/key', [Controller\PushController::class, 'key']);
        $r->post('/api/push/subscribe', [Controller\PushController::class, 'subscribe']);
        $r->post('/api/push/renew', [Controller\PushController::class, 'renew']);
        $r->post('/api/push/unsubscribe', [Controller\PushController::class, 'unsubscribe']);
        $r->post('/api/push/test', [Controller\PushController::class, 'test']);
        $r->post('/api/push/test-all', [Controller\PushController::class, 'testAll']);
        $r->get('/api/push/test-all/{id}', [Controller\PushController::class, 'testStatus']);
        $r->post('/api/push/ack', [Controller\PushController::class, 'ack'], ['auth' => 'public', 'nocsrf' => true]);

        // User guide
        $r->get('/help', [Controller\HelpController::class, 'index']);

        // Mailbox delegation
        $r->post('/delegation/open', [Controller\DelegationController::class, 'open']);
        $r->post('/delegation/close', [Controller\DelegationController::class, 'close']);

        // Shared file space
        $r->get('/files', [Controller\FilesController::class, 'index']);
        $r->get('/files/file/{id}', [Controller\FilesController::class, 'download']);
        $r->get('/api/messages/{id}/img/{asset}', [Controller\MailController::class, 'image']);
        $r->get('/files/edit/{id}', [Controller\OfficeController::class, 'editor']);
        $r->get('/files/sheet/{id}', [Controller\SheetController::class, 'page']);
        $r->get('/files/doc/{id}', [Controller\DocController::class, 'page']);
        $r->get('/api/docs/{id}', [Controller\DocController::class, 'data']);
        $r->post('/api/docs/{id}/take', [Controller\DocController::class, 'take']);
        $r->post('/api/docs/{id}/release', [Controller\DocController::class, 'release']);
        $r->post('/api/docs/{id}/draft', [Controller\DocController::class, 'draft']);
        $r->get('/api/docs/{id}/poll', [Controller\DocController::class, 'poll']);
        $r->post('/api/docs/{id}/save', [Controller\DocController::class, 'save']);
        $r->get('/api/sheets/{id}', [Controller\SheetController::class, 'data']);
        $r->post('/api/sheets/{id}/ops', [Controller\SheetController::class, 'ops']);
        $r->get('/api/sheets/{id}/poll', [Controller\SheetController::class, 'poll']);
        $r->post('/api/sheets/{id}/save', [Controller\SheetController::class, 'save']);
        $r->post('/api/sheets/{id}/leave', [Controller\SheetController::class, 'leave']);
        $r->get('/api/files/file/{id}/text', [Controller\OfficeController::class, 'text']);
        $r->post('/api/files/file/{id}/text', [Controller\OfficeController::class, 'saveText']);
        // WOPI host, called by the office server: authenticated by access token, no session, no CSRF.
        $wopi = ['auth' => 'public', 'nocsrf' => true];
        $r->get('/wopi/files/{id}', [Controller\OfficeController::class, 'checkFileInfo'], $wopi);
        $r->post('/wopi/files/{id}', [Controller\OfficeController::class, 'fileOperation'], $wopi);
        $r->get('/wopi/files/{id}/contents', [Controller\OfficeController::class, 'getFile'], $wopi);
        $r->post('/wopi/files/{id}/contents', [Controller\OfficeController::class, 'putFile'], $wopi);
        $r->get('/files/file/{id}/preview', [Controller\FilesController::class, 'preview']);
        $r->get('/files/folder/{id}/zip', [Controller\FilesController::class, 'zip']);
        $r->get('/api/files/roots', [Controller\FilesController::class, 'roots']);
        $r->get('/api/files/person/{id}', [Controller\FilesController::class, 'personOf']);
        $r->get('/api/files/tree', [Controller\FilesController::class, 'tree']);
        $r->get('/api/files/search', [Controller\FilesController::class, 'search']);
        $r->get('/api/files/trash', [Controller\FilesController::class, 'trash']);
        $r->get('/api/files/principals', [Controller\FilesController::class, 'principals']);
        $r->get('/api/files/folder/{id}', [Controller\FilesController::class, 'folder']);
        $r->get('/api/files/folder/{id}/acl', [Controller\FilesController::class, 'acl']);
        $r->post('/api/files/folder/{id}/acl', [Controller\FilesController::class, 'saveAcl']);
        $r->post('/api/files/space/{id}', [Controller\FilesController::class, 'updateSpace']);
        $r->get('/api/files/file/{id}/expiry', [Controller\FilesController::class, 'expiry']);
        $r->post('/api/files/file/{id}/expiry', [Controller\FilesController::class, 'saveExpiry']);
        $r->get('/api/files/file/{id}/acl', [Controller\FilesController::class, 'fileAcl']);
        $r->post('/api/files/file/{id}/acl', [Controller\FilesController::class, 'saveFileAcl']);
        $r->get('/api/files/file/{id}/versions', [Controller\FilesController::class, 'versions']);
        $r->post('/api/files/file/{id}/restore-version', [Controller\FilesController::class, 'restoreVersion']);
        $r->post('/api/files/folders', [Controller\FilesController::class, 'createFolder']);
        $r->post('/api/files/spaces', [Controller\FilesController::class, 'createSpace']);
        $r->post('/api/files/upload', [Controller\FilesController::class, 'upload']);
        $r->post('/api/files/rename', [Controller\FilesController::class, 'rename']);
        $r->post('/api/files/move', [Controller\FilesController::class, 'move']);
        $r->post('/api/files/compress', [Controller\FilesController::class, 'compress']);
        $r->post('/api/files/copy', [Controller\FilesController::class, 'copy']);
        $r->post('/api/files/delete', [Controller\FilesController::class, 'delete']);
        $r->post('/api/files/restore', [Controller\FilesController::class, 'restore']);
        $r->post('/api/files/purge', [Controller\FilesController::class, 'purge']);
        $r->post('/api/files/attach', [Controller\FilesController::class, 'attach']);
        $r->post('/api/messages/{id}/save-to-files', [Controller\FilesController::class, 'saveAttachment']);
        $r->get('/admin/deleted', [Controller\RetentionController::class, 'index'], $admin);
        $r->get('/admin/deleted/{id}', [Controller\RetentionController::class, 'show'], $admin);
        $r->get('/admin/deleted/{id}/body', [Controller\RetentionController::class, 'body'], $admin);
        $r->get('/admin/deleted/{id}/eml', [Controller\RetentionController::class, 'eml'], $admin);
        $r->post('/admin/deleted/restore', [Controller\RetentionController::class, 'restore'], $admin);
        $r->post('/admin/deleted/purge', [Controller\RetentionController::class, 'purge'], $admin);
        $r->post('/admin/deleted/settings', [Controller\RetentionController::class, 'settings'], $admin);
        $r->get('/admin/files', [Controller\AdminController::class, 'files'], $super);
        $r->post('/admin/files', [Controller\AdminController::class, 'saveFiles'], $super);
        $r->post('/admin/files/office-test', [Controller\AdminController::class, 'officeTest'], $super);
        $r->post('/admin/files/backup', [Controller\AdminController::class, 'saveBackup'], $super);
        $r->post('/admin/files/backup/test', [Controller\AdminController::class, 'testBackup'], $super);
        $r->get('/admin/files/archive/{id}', [Controller\AdminController::class, 'archivedFile'], $super);
        $r->post('/admin/files/archive/{id}', [Controller\AdminController::class, 'archivedFile'], $super);

        // Calendar
        $r->get('/calendar', [Controller\CalendarController::class, 'index']);
        $r->get('/calendar/export/{id}', [Controller\CalendarController::class, 'export']);
        $r->get('/api/calendar/events', [Controller\CalendarController::class, 'events']);
        $r->get('/api/calendar/event', [Controller\CalendarController::class, 'event']);
        $r->post('/api/calendar/event', [Controller\CalendarController::class, 'save']);
        $r->post('/api/calendar/event/move', [Controller\CalendarController::class, 'move']);
        $r->post('/api/calendar/event/delete', [Controller\CalendarController::class, 'delete']);
        $r->post('/api/calendar/event/partstat', [Controller\CalendarController::class, 'partstat']);
        $r->get('/api/calendar/busy', [Controller\CalendarController::class, 'busy']);
        $r->get('/api/calendar/rooms', [Controller\CalendarController::class, 'roomsAvailable']);
        $r->get('/calendar/rooms', [Controller\CalendarController::class, 'roomsPage']);
        $r->post('/calendar/rooms/booking/{id}', [Controller\CalendarController::class, 'decide']);
        $r->post('/calendar/rooms/{id}/config', [Controller\CalendarController::class, 'roomConfig']);
        $r->post('/api/calendar/calendars', [Controller\CalendarController::class, 'createCalendar']);
        $r->post('/api/calendar/calendars/{id}', [Controller\CalendarController::class, 'updateCalendar']);
        $r->post('/api/calendar/calendars/{id}/delete', [Controller\CalendarController::class, 'deleteCalendar']);
        $r->post('/api/calendar/import', [Controller\CalendarController::class, 'import']);
        $r->post('/api/calendar/respond', [Controller\CalendarController::class, 'respond']);
        $r->get('/settings/sync', [Controller\CalendarController::class, 'sync']);
        $r->post('/settings/sync/create', [Controller\CalendarController::class, 'createPassword']);
        $r->post('/settings/sync/meetings', [Controller\CalendarController::class, 'saveMeetingLinks']);
        $r->post('/settings/sync/revoke', [Controller\CalendarController::class, 'revokePassword']);
        $r->get('/settings/sync/profile', [Controller\CalendarController::class, 'mobileconfig']);

        // User settings
        $r->get('/settings', [Controller\SettingsController::class, 'general']);
        $r->post('/settings', [Controller\SettingsController::class, 'saveGeneral']);
        $r->post('/settings/notifications', [Controller\SettingsController::class, 'saveNotifications']);
        $r->post('/settings/sound', [Controller\SettingsController::class, 'uploadSound']);
        $r->post('/settings/sound/delete', [Controller\SettingsController::class, 'deleteSound']);
        $r->get('/settings/sound/{id}', [Controller\SettingsController::class, 'sound']);
        $r->get('/settings/rules', [Controller\SettingsController::class, 'rules']);
        $r->post('/settings/rules/save', [Controller\SettingsController::class, 'saveRule']);
        $r->post('/settings/rules/delete', [Controller\SettingsController::class, 'deleteRule']);
        $r->post('/settings/rules/toggle', [Controller\SettingsController::class, 'toggleRule']);
        $r->post('/settings/rules/order', [Controller\SettingsController::class, 'orderRules']);
        $r->post('/settings/rules/run', [Controller\SettingsController::class, 'runRule']);
        $r->post('/settings/rules/trusted/remove', [Controller\SettingsController::class, 'removeTrusted']);
        $r->get('/settings/vacation', [Controller\SettingsController::class, 'vacation']);
        $r->post('/settings/vacation', [Controller\SettingsController::class, 'saveVacation']);
        $r->get('/settings/forwarding', [Controller\SettingsController::class, 'forwarding']);
        $r->post('/settings/forwarding', [Controller\SettingsController::class, 'saveForwarding']);
        $r->get('/settings/signature', [Controller\SettingsController::class, 'signature']);
        $r->post('/settings/signature', [Controller\SettingsController::class, 'saveSignature']);
        $r->get('/settings/security', [Controller\SettingsController::class, 'security']);
        $r->post('/settings/security/places/forget', [Controller\SettingsController::class, 'forgetPlace']);
        $r->post('/settings/security/password', [Controller\SettingsController::class, 'changePassword']);
        $r->post('/settings/security/2fa/start', [Controller\SettingsController::class, 'start2fa']);
        $r->post('/settings/security/2fa/enable', [Controller\SettingsController::class, 'enable2fa']);
        $r->post('/settings/security/2fa/disable', [Controller\SettingsController::class, 'disable2fa']);
        $r->post('/settings/security/sessions/revoke', [Controller\SettingsController::class, 'revokeSessions']);
        $r->get('/settings/accounts', [Controller\SettingsController::class, 'accounts']);
        $r->get('/settings/mailboxes', [Controller\SettingsController::class, 'mailboxes']);
        $r->post('/settings/mailboxes/add', [Controller\SettingsController::class, 'addMailbox']);
        $r->post('/settings/mailboxes/remove', [Controller\SettingsController::class, 'removeMailbox']);
        $r->post('/mailbox/switch', [Controller\SettingsController::class, 'switchMailbox']);
        $r->post('/settings/accounts/save', [Controller\SettingsController::class, 'saveAccount']);
        $r->post('/settings/accounts/delete', [Controller\SettingsController::class, 'deleteAccount']);
        $r->post('/settings/accounts/run', [Controller\SettingsController::class, 'runAccount']);
        $r->get('/settings/folders', [Controller\SettingsController::class, 'folders']);

        // Administration
        $r->get('/admin', [Controller\AdminController::class, 'dashboard'], $admin);
        $r->get('/admin/users', [Controller\AdminController::class, 'users'], $accounts);
        $r->get('/admin/users/new', [Controller\AdminController::class, 'userForm'], $accounts);
        $r->get('/admin/users/{id}', [Controller\AdminController::class, 'userForm'], $accounts);
        $r->post('/admin/users/save', [Controller\AdminController::class, 'saveUser'], $accounts);
        $r->post('/admin/users/delete', [Controller\AdminController::class, 'deleteUser'], $accounts);
        $r->post('/admin/users/bulk', [Controller\AdminController::class, 'bulkUsers'], $accounts);
        $r->post('/admin/users/import', [Controller\AdminController::class, 'importUsers'], $accounts);
        $r->post('/admin/users/{id}/remote/test', [Controller\AdminController::class, 'testRemote'], $accounts);
        $r->post('/admin/users/{id}/remote/sync', [Controller\AdminController::class, 'syncRemote'], $accounts);
        $r->post('/admin/users/{id}/remote/restore', [Controller\AdminController::class, 'restoreRemote'], $accounts);
        $r->post('/admin/users/reset2fa', [Controller\AdminController::class, 'reset2fa'], $accounts);
        $r->get('/admin/domains', [Controller\AdminController::class, 'domains'], $super);
        $r->post('/admin/domains/save', [Controller\AdminController::class, 'saveDomain'], $super);
        $r->post('/admin/domains/delete', [Controller\AdminController::class, 'deleteDomain'], $super);
        $r->get('/admin/domains/{id}/health', [Controller\AdminController::class, 'domainHealth'], $super);
        $r->post('/admin/domains/{id}/health', [Controller\AdminController::class, 'domainHealth'], $super);
        $r->post('/admin/aliases/save', [Controller\AdminController::class, 'saveAlias'], $accounts);
        $r->post('/admin/aliases/delete', [Controller\AdminController::class, 'deleteAlias'], $accounts);
        $r->get('/admin/signatures', [Controller\AdminController::class, 'signatures'], $admin);
        $r->get('/admin/signatures/new', [Controller\AdminController::class, 'signatureForm'], $admin);
        $r->get('/admin/signatures/{id}', [Controller\AdminController::class, 'signatureForm'], $admin);
        $r->post('/admin/signatures/save', [Controller\AdminController::class, 'saveSignature'], $admin);
        $r->post('/admin/signatures/delete', [Controller\AdminController::class, 'deleteSignature'], $admin);
        $r->post('/admin/signatures/preview', [Controller\AdminController::class, 'previewSignature'], $admin);
        $r->post('/admin/signatures/logo', [Controller\AdminController::class, 'signatureLogo'], $admin);
        $r->post('/admin/signatures/{id}/activate', [Controller\AdminController::class, 'activateSignature'], $admin);
        $r->get('/admin/update', [Controller\AdminController::class, 'update'], $super);
        $r->post('/admin/update/{action}', [Controller\AdminController::class, 'updateAction'], $super);
        $r->get('/admin/branding', [Controller\AdminController::class, 'branding'], $super);
        $r->post('/admin/branding', [Controller\AdminController::class, 'saveBranding'], $super);
        $r->get('/admin/mail', [Controller\AdminController::class, 'mail'], $super);
        $r->post('/admin/mail', [Controller\AdminController::class, 'saveMail'], $super);
        $r->post('/admin/mail/test', [Controller\AdminController::class, 'testMail'], $super);
        $r->post('/admin/mail/dkim', [Controller\AdminController::class, 'generateDkim'], $super);
        $r->post('/admin/mail/collector/test', [Controller\AdminController::class, 'testCollector'], $super);
        $r->post('/admin/mail/collector/sync', [Controller\AdminController::class, 'syncCollector'], $super);
        $r->get('/admin/mail/collector/stats', [Controller\AdminController::class, 'collectorStats'], $admin); // the gauge of the dashboard
        $r->get('/admin/spam', [Controller\AdminController::class, 'spam'], $super);
        $r->post('/admin/spam', [Controller\AdminController::class, 'saveSpam'], $super);
        $r->post('/admin/spam/test', [Controller\AdminController::class, 'testSpam'], $super);
        $r->post('/admin/spam/reported', [Controller\AdminController::class, 'forgetReported'], $super);
        $r->get('/admin/meetings', [Controller\AdminController::class, 'meetings'], $super);
        $r->post('/admin/meetings', [Controller\AdminController::class, 'saveMeetings'], $super);
        $r->post('/admin/meetings/test', [Controller\AdminController::class, 'testMeeting'], $super);
        $r->get('/admin/health', [Controller\AdminController::class, 'health'], $admin);
        $r->post('/admin/health', [Controller\AdminController::class, 'saveHealth'], $admin);
        $r->get('/admin/security', [Controller\AdminController::class, 'security'], $super);
        $r->post('/admin/security', [Controller\AdminController::class, 'saveSecurity'], $super);
        $r->post('/admin/security/guard', [Controller\AdminController::class, 'guardAction'], $super);
        $r->get('/admin/logs', [Controller\AdminController::class, 'logs'], $admin);
        $r->post('/admin/queue/retry', [Controller\AdminController::class, 'retryQueue'], $admin);
        $r->post('/admin/queue/delete', [Controller\AdminController::class, 'deleteQueue'], $admin);
        $r->post('/admin/bounces/remove', [Controller\AdminController::class, 'removeBounce'], $admin);
        $r->post('/admin/bounces/add', [Controller\AdminController::class, 'addBounce'], $admin);
    }

    /**
     * What prevents the application from running at all, said plainly instead of a bare error
     * 500: files the web server cannot write (an installation copied as another user: the
     * error log itself is then out of reach, the reason went to the web server's own log), or
     * a database that does not answer.
     */
    public static function startupProblem(): ?string
    {
        if (!Config::installed()) {
            return null;
        }
        $paths = [Config::storagePath(), Config::storagePath('logs'), Config::storagePath('tmp'), Config::storagePath('mail'), Config::storagePath('sessions')];
        $db = (array) Config::get('db', []);
        if (($db['driver'] ?? 'sqlite') === 'sqlite') {
            $file = (string) ($db['path'] ?? Config::storagePath('data/m4w.sqlite'));
            $paths[] = dirname($file);
            if (!is_file($file)) {
                return t('startup.db_file_missing', ['path' => $file]);
            }
            $paths[] = $file;
        }
        $denied = array_values(array_filter(array_unique($paths), static fn($p) => file_exists($p) && !is_writable($p)));
        if ($denied) {
            $who = function_exists('posix_geteuid') && ($pw = @posix_getpwuid(posix_geteuid())) ? (string) $pw['name'] : 'www-data';
            error_log('[m4w] not writable by ' . $who . ': ' . implode(', ', $denied));
            return t('startup.not_writable', ['user' => $who, 'paths' => implode(', ', $denied), 'root' => M4W_ROOT]);
        }
        try {
            Database::pdo();
        } catch (\PDOException $e) {
            error_log('[m4w] database unreachable: ' . $e->getMessage());
            return t('startup.db_unreachable', ['host' => (string) ($db['host'] ?? ''), 'error' => (string) preg_replace('/^SQLSTATE\[\w+\]\s*/', '', $e->getMessage())]);
        }
        return null;
    }

    private static function startupPage(Request $req, string $problem): Response
    {
        return self::errorResponse($req, 503, $problem);
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
                // Pages that ask for it (the document editors) may be framed by the application itself.
                $res->getHeader('X-Frame-Options') === 'SAMEORIGIN' ? "frame-ancestors 'self'" : "frame-ancestors 'none'",
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
        // A proxy must not rewrite the pages: Cloudflare otherwise hides every e-mail address
        // ("[email protected]", to be decoded by a script of its own that the CSP refuses) and
        // adds its own scripts.
        if (!str_contains((string) $res->getHeader('Cache-Control'), 'no-transform')) {
            $res->header('Cache-Control', $res->getHeader('Cache-Control') . ', no-transform');
        }
    }
}
