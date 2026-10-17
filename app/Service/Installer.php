<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Config;
use M4W\Core\Database;
use M4W\Core\Settings;
use M4W\Database\Migrator;
use M4W\Mail\MimeBuilder;

final class Installer
{
    public static function requirements(): array
    {
        $checks = [
            'PHP ≥ 8.1'      => PHP_VERSION_ID >= 80100,
            'ext-pdo'        => extension_loaded('pdo'),
            'ext-pdo_sqlite / pdo_mysql' => extension_loaded('pdo_sqlite') || extension_loaded('pdo_mysql'),
            'ext-sodium'     => extension_loaded('sodium'),
            'ext-mbstring'   => extension_loaded('mbstring'),
            'ext-openssl'    => extension_loaded('openssl'),
            'ext-dom'        => extension_loaded('dom'),
            'ext-fileinfo'   => extension_loaded('fileinfo'),
            'ext-iconv'      => extension_loaded('iconv'),
            'ext-curl'       => function_exists('curl_init'),
            'ext-gd'         => function_exists('imagecreatefromstring'),
            'ext-zip'        => class_exists(\ZipArchive::class),
            'storage/ writable' => is_writable(Config::storagePath()),
            'config/ writable'  => is_writable(dirname(Config::path())),
        ];
        return $checks;
    }

    /**
     * @param array{lang:string,brand:string,domain:string,admin_email:string,admin_password:string,admin_name:string,db:array,base_url?:string,timezone?:string} $d
     */
    public static function install(array $d): void
    {
        $cfg = [
            'debug'        => false,
            'timezone'     => $d['timezone'] ?? 'Europe/Paris',
            'storage_path' => Config::storagePath(),
            'db'           => $d['db'],
            'app_key'      => base64_encode(random_bytes(32)),
            'base_url'     => $d['base_url'] ?? '',
            'trusted_proxies' => [],
        ];
        // Validate DB connection before writing the config (with the way that answered).
        Database::reset();
        $cfg['db'] = self::reachDatabase($cfg['db']);
        Database::connect($cfg['db']);
        // Paths inside the application are not written: moved elsewhere, it keeps finding its data.
        if ($cfg['storage_path'] === M4W_ROOT . '/storage') {
            unset($cfg['storage_path']);
        }
        if (($cfg['db']['driver'] ?? '') === 'sqlite' && ($cfg['db']['path'] ?? '') === M4W_ROOT . '/storage/data/m4w.sqlite') {
            unset($cfg['db']['path']);
        }
        Config::write($cfg);
        Database::reset();
        foreach (['data', 'mail', 'tmp', 'uploads/brand', 'logs', 'sessions', 'queue'] as $dir) {
            $p = Config::storagePath($dir);
            if (!is_dir($p)) {
                mkdir($p, 0750, true);
            }
        }
        Migrator::migrate();
        Search::ensureIndex();
        Settings::flush();
        Settings::setMany([
            'brand.name' => $d['brand'] ?: 'Make4WebMail',
            'brand.company' => $d['brand'] ?: 'Make4Web',
            'features.default_language' => $d['lang'],
            'smtp.helo' => $d['domain'],
        ]);
        Database::insert('domains', ['name' => mb_strtolower($d['domain']), 'active' => 1, 'created_at' => time()]);
        $tplId = Signatures::save([
            'name' => 'Signature entreprise', 'description' => 'Modèle par défaut', 'html' => Signatures::defaultTemplate(),
            'is_default' => 1, 'apply_on_reply' => 1,
        ]);
        $parts = preg_split('/\s+/', trim($d['admin_name']), 2) ?: [''];
        $uid = Users::create([
            'email' => $d['admin_email'], 'password' => $d['admin_password'], 'display_name' => trim($d['admin_name']),
            'first_name' => $parts[0] ?? '', 'last_name' => $parts[1] ?? '', 'role' => 'admin', 'quota_mb' => 0,
            'language' => $d['lang'], 'job_title' => 'Administrateur', 'signature_template_id' => $tplId,
        ]);
        self::welcome($uid);
    }

    /** Sockets where MariaDB / MySQL usually listen when it does not over the network. */
    private const SOCKETS = ['/run/mysqld/mysqld.sock', '/var/run/mysqld/mysqld.sock', '/var/lib/mysql/mysql.sock', '/tmp/mysql.sock'];

    /**
     * The database configuration that really connects. MariaDB often listens only on its local
     * socket (skip-networking, bind-address): "127.0.0.1" is then refused while "localhost" works,
     * so the other way is tried before giving up. A missing database is created when the account
     * may. Failures are explained (and logged), not shown as the driver's raw message.
     */
    public static function reachDatabase(array $db): array
    {
        if (($db['driver'] ?? 'sqlite') !== 'mysql') {
            return $db;
        }
        $host = trim((string) ($db['host'] ?? '')) ?: '127.0.0.1';
        $db['host'] = $host;
        $tries = [$db];
        if (in_array(strtolower($host), ['127.0.0.1', 'localhost', '::1'], true)) {
            foreach (self::SOCKETS as $sock) {
                if (@file_exists($sock)) {
                    $tries[] = ['host' => 'localhost', 'socket' => $sock] + $db;
                }
            }
            $tries[] = ['host' => strtolower($host) === 'localhost' ? '127.0.0.1' : 'localhost'] + $db;
        }
        $first = null;
        foreach ($tries as $try) {
            try {
                self::pdo($try, true);
                return $try;
            } catch (\PDOException $e) {
                $code = self::errorCode($e);
                if ($code === 1049) {
                    // Reached, the database does not exist yet: created if the account may.
                    try {
                        self::pdo($try, false)->exec('CREATE DATABASE `' . str_replace('`', '', (string) $try['name']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                        return $try;
                    } catch (\PDOException) {
                        $first = $e;
                        break;
                    }
                }
                $first ??= $e;
                if (!in_array($code, [2002, 2003, 2005, 2006], true)) {
                    $first = $e; // reached, but refused for another reason: that is the answer
                    break;
                }
            }
        }
        error_log('[m4w] install: database ' . $host . ':' . (int) ($db['port'] ?? 3306) . ' — ' . $first->getMessage());
        throw new \RuntimeException(self::explain($first, $db));
    }

    private static function pdo(array $db, bool $withName): \PDO
    {
        $socket = (string) ($db['socket'] ?? '');
        $dsn = $socket !== '' ? 'mysql:unix_socket=' . $socket : 'mysql:host=' . $db['host'] . ';port=' . (int) ($db['port'] ?? 3306);
        if ($withName) {
            $dsn .= ';dbname=' . ($db['name'] ?? 'm4w');
        }
        return new \PDO($dsn . ';charset=utf8mb4', (string) ($db['user'] ?? ''), (string) ($db['pass'] ?? ''), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 5]);
    }

    private static function errorCode(\PDOException $e): int
    {
        return (int) ($e->errorInfo[1] ?? 0) ?: (preg_match('/\[(\d{4})\]/', $e->getMessage(), $m) ? (int) $m[1] : 0);
    }

    private static function explain(\PDOException $e, array $db): string
    {
        $vars = ['host' => (string) $db['host'], 'port' => (int) ($db['port'] ?? 3306), 'user' => (string) ($db['user'] ?? ''), 'name' => (string) ($db['name'] ?? '')];
        $key = match (self::errorCode($e)) {
            2002, 2003, 2006 => 'install.db_unreachable',
            2005 => 'install.db_unknown_host',
            1045 => 'install.db_denied',
            1044 => 'install.db_no_rights',
            1049 => 'install.db_missing',
            default => 'install.db_other',
        };
        return t($key, $vars) . ' (' . trim((string) preg_replace('/^SQLSTATE\[\w+\]\s*(\[\d+\])?\s*/', '', $e->getMessage())) . ')';
    }

    public static function welcome(int $uid): void
    {
        $u = Users::find($uid);
        $b = new MimeBuilder();
        $brand = (string) Settings::get('brand.name');
        $b->from = ['email' => 'postmaster@' . substr($u['email'], strpos($u['email'], '@') + 1), 'name' => $brand];
        $b->to = [['email' => $u['email'], 'name' => $u['name']]];
        $b->subject = 'Bienvenue sur ' . $brand . ' 🎉';
        $b->messageId = MimeBuilder::newMessageId('m4w.local');
        $b->html = '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#1e293b;max-width:600px">'
            . '<h2 style="color:#2563eb;margin:0 0 12px">Bienvenue, ' . e($u['name']) . ' !</h2>'
            . '<p>Votre messagerie est prête. Voici quelques points pour démarrer :</p><ul>'
            . '<li><b>Administration</b> : créez vos utilisateurs, domaines et alias.</li>'
            . '<li><b>Signature centralisée</b> : personnalisez le modèle, il est injecté automatiquement à l\'envoi.</li>'
            . '<li><b>Personnalisation</b> : logo, couleurs, page de connexion.</li>'
            . '<li><b>Serveur SMTP</b> : configurez le relais sortant (ou la remise directe MX).</li>'
            . '<li><b>Raccourcis clavier</b> : appuyez sur <kbd>?</kbd> dans la messagerie.</li></ul>'
            . '<p>Bonne messagerie !</p></div>';
        Mailbox::store($uid, (int) Folders::byRole($uid, 'inbox')['id'], $b->build());
    }
}
