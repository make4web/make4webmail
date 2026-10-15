<?php
declare(strict_types=1);

namespace M4W\Dav;

use M4W\Core\Config;
use M4W\Core\Request;
use Sabre\CalDAV;
use Sabre\CardDAV;
use Sabre\DAV;
use Sabre\DAVACL;

/**
 * CalDAV / CardDAV for phones and desktop programs, at /dav/ (and /.well-known/caldav,
 * /.well-known/carddav for autodiscovery). Basic authentication with an app password over
 * HTTPS only; no session, no cookie.
 */
final class Server
{
    public static function handles(string $path): bool
    {
        return $path === '/dav' || str_starts_with($path, '/dav/') || in_array($path, ['/.well-known/caldav', '/.well-known/carddav'], true);
    }

    public static function run(Request $req): void
    {
        $raw = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        // Hosting without URL rewriting: /index.php/dav/...
        $base = Request::basePath() . (str_contains($raw, '/index.php/dav') ? '/index.php' : '') . '/dav/';
        if (str_starts_with($req->path, '/.well-known/') || ($req->path === '/dav' && !str_ends_with($raw, '/'))) {
            header('Location: ' . $base, true, 301);
            return;
        }
        if (!Config::installed() || !\M4W\Core\Settings::get('dav.enabled', 1)) {
            http_response_code(404);
            return;
        }
        if (!$req->isSecure() && !in_array($req->ip(), ['127.0.0.1', '::1'], true)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo "HTTPS required\n";
            return;
        }
        \M4W\Database\Migrator::migrate();

        // Authenticate first: every backend below works for this one person.
        $user = null;
        [$login, $pw] = self::basic();
        if ($login !== '' && $pw !== '') {
            $user = AppPasswords::check($login, $pw, $req->ip());
        }
        if (!$user) {
            http_response_code(401);
            header('WWW-Authenticate: Basic realm="Make4WebMail", charset="UTF-8"');
            header('Content-Type: text/plain; charset=utf-8');
            echo "Authentication required (use an app password)\n";
            return;
        }
        \M4W\Core\Auth::setUser($user);
        \M4W\Core\I18n::setLanguage($user['language'] ?? 'fr');

        $principals = new Principals($user);
        $tree = [
            new DAVACL\PrincipalCollection($principals, 'principals'),
            new CalDAV\CalendarRoot($principals, new CalDavBackend($user), 'principals'),
            new CardDAV\AddressBookRoot($principals, new CardDavBackend($user), 'principals'),
        ];
        $server = new DAV\Server($tree);
        $server->setBaseUri($base);
        $server->debugExceptions = (bool) Config::get('debug', false);

        $auth = new DAV\Auth\Plugin(new class ($user) extends DAV\Auth\Backend\AbstractBasic {
            public function __construct(private readonly array $user)
            {
            }

            protected function validateUserPass($username, $password)
            {
                // Already checked (with throttling) before the server was built.
                return mb_strtolower((string) $username) === mb_strtolower($this->user['email']);
            }
        });
        $server->addPlugin($auth);
        $acl = new DAVACL\Plugin();
        $acl->hideNodesFromListings = true;
        $acl->principalCollectionSet = ['principals'];
        $server->addPlugin($acl);
        $server->addPlugin(new CalDAV\Plugin());
        $server->addPlugin(new CardDAV\Plugin());
        $server->addPlugin(new AutoSchedule());
        $server->addPlugin(new DAV\Sync\Plugin());
        $server->addPlugin(new CalDAV\ICSExportPlugin());
        $server->addPlugin(new CardDAV\VCFExportPlugin());
        $server->start();
    }

    /** @return array{0:string,1:string} */
    private static function basic(): array
    {
        $u = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
        $p = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');
        if ($u === '') {
            $h = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
            if (stripos($h, 'basic ') === 0) {
                $dec = base64_decode(substr($h, 6), true) ?: '';
                [$u, $p] = array_pad(explode(':', $dec, 2), 2, '');
            }
        }
        return [$u, $p];
    }
}
