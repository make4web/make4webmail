<?php
declare(strict_types=1);

/**
 * Autoloader of the vendored PHP libraries (no Composer at run time). Only their sources
 * and licences are kept; see README "Licences tierces".
 *   phpoffice/phpspreadsheet 5.8.1 (MIT), phpoffice/phpword 1.4.0 (LGPL-3.0, unmodified),
 *   phpoffice/math 0.3.0 (MIT), markbaker/matrix 3.0.1 + complex 3.0.2 (MIT),
 *   maennchen/zipstream-php 3.1.1 (MIT), composer/pcre 3.4.0 (MIT), psr/simple-cache 3.0.0 (MIT)
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
