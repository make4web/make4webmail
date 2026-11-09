<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Database as DB;

/**
 * Profile photos: chosen by each user (Profile & preferences), seen by the users of the same
 * webmail wherever initials stood. Re-encoded by the server (a square JPEG, no metadata) and
 * served only to signed-in users.
 */
final class Avatars
{
    public const SIZE = 256;

    public static function file(int $uid): string
    {
        return storage_path('avatars/' . $uid . '.jpg');
    }

    /** A picture sent by the user: cropped to a square, resized, kept. */
    public static function save(int $uid, string $path): void
    {
        $data = (string) @file_get_contents($path);
        $info = @getimagesizefromstring($data);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true) || $info[0] * $info[1] > 40_000_000) {
            throw new \RuntimeException(t('avatar.bad'));
        }
        $im = function_exists('imagecreatefromstring') ? @imagecreatefromstring($data) : false;
        if (!$im) {
            throw new \RuntimeException(t('avatar.bad'));
        }
        // Phones store the turn in EXIF: applied before the metadata goes.
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $o = (int) (@exif_read_data($path)['Orientation'] ?? 1);
            $im = match ($o) { 3 => imagerotate($im, 180, 0), 6 => imagerotate($im, -90, 0), 8 => imagerotate($im, 90, 0), default => $im };
        }
        [$w, $h] = [imagesx($im), imagesy($im)];
        $side = min($w, $h);
        $out = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255)); // transparent PNG: on white
        imagecopyresampled($out, $im, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), self::SIZE, self::SIZE, $side, $side);
        if (!is_dir(dirname(self::file($uid)))) {
            mkdir(dirname(self::file($uid)), 0750, true);
        }
        imagejpeg($out, self::file($uid), 86);
        DB::update('users', ['avatar_at' => time()], 'id = :id', ['id' => $uid]);
    }

    public static function delete(int $uid): void
    {
        @unlink(self::file($uid));
        DB::update('users', ['avatar_at' => 0], 'id = :id', ['id' => $uid]);
    }

    /** @return array<string,string> address (lower case) => URL of the photo, for the users who have one */
    public static function map(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            try {
                foreach (DB::all("SELECT id, email, avatar_at FROM users WHERE avatar_at > 0 AND status = 'active'") as $r) {
                    $map[mb_strtolower((string) $r['email'])] = url('api/avatar/' . (int) $r['id'], ['v' => (int) $r['avatar_at']]);
                }
            } catch (\Throwable) {
                // Before the migration: initials.
            }
        }
        return $map;
    }

    public static function urlFor(string $email): string
    {
        return self::map()[mb_strtolower(trim($email))] ?? '';
    }
}
