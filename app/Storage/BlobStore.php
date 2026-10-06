<?php
declare(strict_types=1);

namespace M4W\Storage;

/**
 * Content-addressed-by-id storage for file contents. Metadata (names, folders,
 * rights) always stays in the database; only the bytes go through this interface,
 * so another backend (S3, Swift, local disk…) only has to implement these methods.
 */
interface BlobStore
{
    /** Store the content of a readable stream under $id. Returns the number of bytes written. */
    public function put(string $id, $stream): int;

    /** Send the content to $sink chunk by chunk (memory stays bounded). */
    public function read(string $id, callable $sink): void;

    public function delete(string $id): void;

    public function exists(string $id): bool;

    /** Short identifier shown in the administration ("database", "local"…). */
    public function name(): string;
}
