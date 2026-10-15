<?php
declare(strict_types=1);

/**
 * Autoloader of the vendored PHP libraries (no Composer at run time). Only their sources
 * and licences are kept; see README "Licences tierces".
 *   phpoffice/phpspreadsheet 5.8.1 (MIT), phpoffice/phpword 1.4.0 (LGPL-3.0, unmodified),
 *   phpoffice/math 0.3.0 (MIT), markbaker/matrix 3.0.1 + complex 3.0.2 (MIT),
 *   maennchen/zipstream-php 3.1.1 (MIT), composer/pcre 3.4.0 (MIT), psr/simple-cache 3.0.0 (MIT),
 *   phpseclib/phpseclib 3.0.57 (MIT, SFTP of the backups), paragonie/constant_time_encoding 3.1.3 (MIT),
 *   sabre/dav 4.7.1, vobject 4.6.1, http 5.1.13, xml 2.2.11, event 5.1.9, uri 2.3.4 (BSD-3-Clause; CalDAV/CardDAV, calendars),
 *   psr/log 3.0.2 (MIT)
 */
spl_autoload_register(static function (string $class): void {
    static $map = [
        'PhpOffice\\PhpSpreadsheet\\' => 'phpoffice/phpspreadsheet/src/PhpSpreadsheet/',
        'PhpOffice\\PhpWord\\' => 'phpoffice/phpword/src/PhpWord/',
        'PhpOffice\\Math\\' => 'phpoffice/math/src/Math/',
        'Matrix\\' => 'markbaker/matrix/classes/src/',
        'Complex\\' => 'markbaker/complex/classes/src/',
        'ZipStream\\' => 'maennchen/zipstream-php/src/',
        'Composer\\Pcre\\' => 'composer/pcre/src/',
        'Psr\\SimpleCache\\' => 'psr/simple-cache/src/',
        'phpseclib3\\' => 'phpseclib/phpseclib/phpseclib/',
        'ParagonIE\\ConstantTime\\' => 'paragonie/constant_time_encoding/src/',
        'Sabre\\VObject\\' => 'sabre/vobject/lib/',
        'Sabre\\Xml\\' => 'sabre/xml/lib/',
        'Sabre\\Uri\\' => 'sabre/uri/lib/',
        'Sabre\\HTTP\\' => 'sabre/http/lib/',
        'Sabre\\Event\\' => 'sabre/event/lib/',
        'Sabre\\' => 'sabre/dav/lib/',
        'Psr\\Log\\' => 'psr/log/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = __DIR__ . '/' . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

// Plain functions of the sabre libraries (loaded on first use of a Sabre class).
spl_autoload_register(static function (string $class): void {
    static $done = false;
    if ($done || !str_starts_with($class, 'Sabre\\')) {
        return;
    }
    $done = true;
    foreach (['sabre/uri/lib/functions.php', 'sabre/event/lib/coroutine.php', 'sabre/event/lib/Loop/functions.php', 'sabre/event/lib/Promise/functions.php',
        'sabre/xml/lib/Deserializer/functions.php', 'sabre/xml/lib/Serializer/functions.php', 'sabre/http/lib/functions.php'] as $f) {
        require_once __DIR__ . '/' . $f;
    }
}, true, true);
