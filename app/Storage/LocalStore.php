<?php
declare(strict_types=1);

namespace M4W\Storage;

/** Blobs as plain files under storage/files (or another directory), outside the web root. */
final class LocalStore implements BlobStore
{
    public function __construct(private string $dir)
    {
    }

    private function path(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{32,64}$/', $id)) {
            throw new \InvalidArgumentException('Invalid blob id');
        }
        return rtrim($this->dir, '/') . '/' . substr($id, 0, 2) . '/' . $id;
    }

    public function put(string $id, $stream): int
    {
        $path = $this->path($id);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0750, true);
        }
        $out = fopen($path, 'xb');
        if (!$out) {
            throw new \RuntimeException('Cannot write blob');
        }
        $n = stream_copy_to_stream($stream, $out);
        fclose($out);
        return (int) $n;
    }

    public function read(string $id, callable $sink): void
    {
        $in = @fopen($this->path($id), 'rb');
        if (!$in) {
            return;
        }
        while (!feof($in)) {
            $buf = fread($in, 1048576);
            if ($buf === false || $buf === '') {
                break;
            }
            $sink($buf);
        }
        fclose($in);
    }

    public function delete(string $id): void
    {
        @unlink($this->path($id));
    }

    public function exists(string $id): bool
    {
        return is_file($this->path($id));
    }

    public function name(): string
    {
        return 'local';
    }
}
