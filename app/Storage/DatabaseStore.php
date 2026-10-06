<?php
declare(strict_types=1);

namespace M4W\Storage;

use M4W\Core\Database as DB;
use PDO;

/** Blobs stored in the database, split in 1 MiB chunks (SQLite BLOB / MySQL LONGBLOB). */
final class DatabaseStore implements BlobStore
{
    public const CHUNK = 1048576;

    public function put(string $id, $stream): int
    {
        $pdo = DB::pdo();
        return (int) DB::transaction(function () use ($pdo, $id, $stream) {
            $st = $pdo->prepare('INSERT INTO fs_blob_chunks (blob_id, seq, data) VALUES (:b, :s, :d)');
            $seq = 0;
            $total = 0;
            while (!feof($stream)) {
                $buf = '';
                // fread may return less than asked on pipes/sockets: fill a full chunk.
                while (strlen($buf) < self::CHUNK && !feof($stream)) {
                    $part = fread($stream, self::CHUNK - strlen($buf));
                    if ($part === false || $part === '') {
                        break;
                    }
                    $buf .= $part;
                }
                if ($buf === '' && $seq > 0) {
                    break;
                }
                $st->bindValue(':b', $id);
                $st->bindValue(':s', $seq++, PDO::PARAM_INT);
                $st->bindValue(':d', $buf, PDO::PARAM_LOB);
                $st->execute();
                $total += strlen($buf);
                if ($buf === '') {
                    break;
                }
            }
            return $total;
        });
    }

    public function read(string $id, callable $sink): void
    {
        $seqs = DB::all('SELECT seq FROM fs_blob_chunks WHERE blob_id = :b ORDER BY seq', ['b' => $id]);
        $st = DB::pdo()->prepare('SELECT data FROM fs_blob_chunks WHERE blob_id = :b AND seq = :s');
        foreach ($seqs as $row) {
            $st->bindValue(':b', $id);
            $st->bindValue(':s', (int) $row['seq'], PDO::PARAM_INT);
            $st->execute();
            $data = $st->fetchColumn();
            $st->closeCursor();
            if (is_resource($data)) {
                $data = stream_get_contents($data);
            }
            if ($data !== false && $data !== null && $data !== '') {
                $sink((string) $data);
            }
        }
    }

    public function delete(string $id): void
    {
        DB::delete('fs_blob_chunks', 'blob_id = :b', ['b' => $id]);
    }

    public function exists(string $id): bool
    {
        return (bool) DB::value('SELECT 1 FROM fs_blob_chunks WHERE blob_id = :b LIMIT 1', ['b' => $id]);
    }

    public function name(): string
    {
        return 'database';
    }
}
