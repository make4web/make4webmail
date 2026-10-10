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
        // Validate DB connection before writing the config.
        Database::reset();
        Database::connect($cfg['db']);
        Config::write($cfg);
        Database::reset();
        foreach (['data', 'mail', 'tmp', 'uploads/brand', 'logs', 'sessions', 'queue'] as $dir) {
            $p = Config::storagePath($dir);
            if (!is_dir($p)) {
                mkdir($p, 0750, true);
            }
        }
        Migrator::migrate();
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
