<?php
declare(strict_types=1);

namespace M4W\Controller;

use M4W\Core\Auth;
use M4W\Core\Config;
use M4W\Core\Csrf;
use M4W\Core\I18n;
use M4W\Core\Response;
use M4W\Core\View;
use M4W\Service\Installer;
use M4W\Service\Users;

final class InstallController extends Controller
{
    public function form(array $errors = [], array $old = []): Response
    {
        if (Config::installed()) {
            return Response::redirect('/');
        }
        return Response::html(View::render('install/form', [
            'checks' => Installer::requirements(),
            'errors' => $errors,
            'old'    => $old,
        ], 'layouts/blank'));
    }

    public function install(): Response
    {
        if (Config::installed()) {
            return Response::redirect('/');
        }
        if (!Csrf::verify($this->req)) {
            return $this->form([t('error.csrf')]);
        }
        $r = $this->req;
        $old = $r->post;
        unset($old['admin_password'], $old['admin_password2'], $old['db_pass']);
        $errors = [];
        foreach (Installer::requirements() as $name => $ok) {
            if (!$ok && !str_contains($name, 'pdo_sqlite')) {
                $errors[] = t('install.requirement', ['name' => $name]);
            }
        }
        $lang = isset(I18n::LANGUAGES[$r->str('lang')]) ? $r->str('lang') : 'fr';
        $domain = mb_strtolower($r->str('domain'));
        $email = mb_strtolower($r->str('admin_email'));
        $pw = $r->raw('admin_password');
        if (!preg_match('/^(?=.{3,190}$)([a-z0-9-]+\.)+[a-z]{2,}$/i', $domain)) {
            $errors[] = t('install.bad_domain');
        }
        if (!is_valid_email($email) || !str_ends_with($email, '@' . $domain)) {
            $errors[] = t('install.bad_email');
        }
        if (mb_strlen($pw) < 10) {
            $errors[] = t('pw.too_short', ['n' => 10]);
        }
        if ($pw !== $r->raw('admin_password2')) {
            $errors[] = t('pw.mismatch');
        }
        $driver = $r->str('db_driver') === 'mysql' ? 'mysql' : 'sqlite';
        $db = $driver === 'mysql'
            ? ['driver' => 'mysql', 'host' => $r->str('db_host', '127.0.0.1'), 'port' => $r->int('db_port', 3306),
               'name' => $r->str('db_name', 'm4w'), 'user' => $r->str('db_user'), 'pass' => $r->raw('db_pass')]
            : ['driver' => 'sqlite', 'path' => Config::storagePath('data/m4w.sqlite')];
        if ($errors) {
            return $this->form($errors, $old);
        }
        try {
            Installer::install([
                'lang' => $lang, 'brand' => $r->str('brand'), 'domain' => $domain, 'admin_email' => $email,
                'admin_password' => $pw, 'admin_name' => $r->str('admin_name') ?: 'Administrateur', 'db' => $db,
                'timezone' => in_array($r->str('timezone'), timezone_identifiers_list(), true) ? $r->str('timezone') : 'Europe/Paris',
            ]);
        } catch (\Throwable $e) {
            @unlink(Config::path());
            Config::load();
            return $this->form([t('install.failed') . ' ' . $e->getMessage()], $old);
        }
        $user = Users::findByEmail($email);
        Auth::login($user, $this->req);
        return Response::redirect('/admin');
    }
}
