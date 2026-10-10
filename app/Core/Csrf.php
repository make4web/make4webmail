<?php
declare(strict_types=1);

namespace M4W\Core;

final class Csrf
{
    public static function token(): string
    {
        $t = Session::get('_csrf');
        if (!is_string($t) || strlen($t) < 32) {
            $t = Crypto::token(32);
            Session::set('_csrf', $t);
        }
        return $t;
    }

    public static function verify(Request $req): bool
    {
        $sent = $req->header('X-CSRF-Token');
        if ($sent === '') {
            $sent = (string) ($req->post['_csrf'] ?? ($req->json()['_csrf'] ?? ''));
        }
        $expected = Session::get('_csrf');
        return is_string($expected) && $expected !== '' && hash_equals($expected, $sent);
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }
}
