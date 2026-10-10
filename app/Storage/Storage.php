<?php
declare(strict_types=1);

namespace M4W\Storage;

use M4W\Core\Config;

/**
 * Picks the blob backend from config/config.php:
 *   'files' => ['storage' => 'database']                      (default)
 *   'files' => ['storage' => 'local', 'path' => '/srv/files']  (plain files)
 * An S3-compatible backend only needs a new BlobStore implementation registered here.
 */
final class Storage
{
    private static ?BlobStore $store = null;

    public static function store(): BlobStore
    {
        if (self::$store === null) {
            $driver = (string) Config::get('files.storage', 'database');
            self::$store = match ($driver) {
                'local' => new LocalStore((string) Config::get('files.path', Config::storagePath('files'))),
                default => new DatabaseStore(),
            };
        }
        return self::$store;
    }

    /** For tests. */
    public static function use(?BlobStore $store): void
    {
        self::$store = $store;
    }
}
