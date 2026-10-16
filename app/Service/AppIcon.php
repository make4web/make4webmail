<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Settings;

/**
 * Icon of the installed app (home screen, desktop): the client's logo, with an envelope in the
 * primary colour over its bottom right corner.
 *  - Android / desktop icons: transparent, the logo as big as it goes, the envelope in the corner
 *    with a transparent cut around it.
 *  - iOS and Android "maskable" ones must be opaque: dark primary colour, light background and the
 *    normal logo; light primary colour, dark background and the logo for dark backgrounds (when
 *    there is one, otherwise light background).
 *  - Made with GD at four times the size then reduced, for smooth edges; kept in storage until
 *    the logo or the colour changes. Without a logo (or GD), the default icons stay.
 */
final class AppIcon
{
    /** Bump when the drawing changes, so installed apps get the new one. */
    private const VERSION = 3;

    /** variant => [size, shape] — "clear": transparent, no background (Android "any", desktop);
     *  "full": square, the system cuts it (iOS); "safe": square, content in the 80 % safe circle (maskable). */
    public const VARIANTS = [
        'apple' => [180, 'full'],
        '192' => [192, 'clear'],
        '512' => [512, 'clear'],
        'maskable' => [512, 'safe'],
    ];

    private const DARK_BG = '#0f172a';

    /** URL of an icon, the generated one when possible, else the default one. */
    public static function url(string $variant): string
    {
        $key = self::key();
        return $key === null ? self::defaultUrl($variant) : url('app-icon/' . $variant . '.png') . '?v=' . $key;
    }

    /** The default icon (Make4WebMail envelope). */
    public static function defaultUrl(string $variant): string
    {
        return asset(['apple' => 'img/apple-touch-icon.png', '192' => 'img/icon-192.png', '512' => 'img/icon-512.png', 'maskable' => 'img/icon-maskable-512.png'][$variant] ?? 'img/icon-512.png');
    }

    /** Whether the icons are made from the logo. */
    public static function custom(): bool
    {
        return self::key() !== null;
    }

    /** Version of the current icons (logo files, colour, drawing), null when there is no logo to use. */
    private static function key(): ?string
    {
        $logo = (string) Settings::get('brand.logo', '');
        if ($logo === '' || !function_exists('imagecreatefromstring') || str_ends_with($logo, '.ico')) {
            return null;
        }
        return substr(md5(self::VERSION . '|' . $logo . '|' . Settings::get('brand.logo_dark', '') . '|' . self::primary()), 0, 12);
    }

    private static function primary(): string
    {
        $v = (string) Settings::get('brand.primary', '#2563eb');
        return preg_match('/^#[0-9a-f]{6}$/i', $v) ? strtolower($v) : '#2563eb';
    }

    /** PNG of a variant (cached), null when it cannot be made. */
    public static function png(string $variant): ?string
    {
        $key = self::key();
        if ($key === null || !isset(self::VARIANTS[$variant])) {
            return null;
        }
        $dir = storage_path('uploads/brand/appicon');
        $path = $dir . '/' . $key . '-' . $variant . '.png';
        if (is_file($path)) {
            return (string) file_get_contents($path);
        }
        try {
            $png = self::render($variant);
        } catch (\Throwable $e) {
            error_log('[m4w] app icon: ' . $e->getMessage());
            return null;
        }
        if ($png === null) {
            return null;
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        foreach (glob($dir . '/*-' . $variant . '.png') ?: [] as $old) {
            @unlink($old); // icons of an earlier logo or colour
        }
        @file_put_contents($path . '.tmp', $png) && @rename($path . '.tmp', $path);
        return $png;
    }

    private static function render(string $variant): ?string
    {
        [$size, $shape] = self::VARIANTS[$variant];
        $primary = self::primary();
        $logoFile = (string) Settings::get('brand.logo', '');
        $darkFile = (string) Settings::get('brand.logo_dark', '');
        // White envelope on a dark primary: light background. Dark envelope on a light primary:
        // dark background, if a logo is made for it (a normal logo may vanish on dark).
        $clear = $shape === 'clear';
        $darkBg = !$clear && Branding::contrast($primary) !== '#ffffff' && $darkFile !== '';
        $logo = self::load($darkBg ? $darkFile : $logoFile);
        if (!$logo) {
            return null;
        }

        $S = $size * 4; // drawn bigger, then reduced: smooth edges
        $im = imagecreatetruecolor($S, $S);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagealphablending($im, true);
        [$br, $bg, $bb] = self::rgb($darkBg ? self::DARK_BG : '#ffffff');
        $bgCol = imagecolorallocate($im, $br, $bg, $bb);
        if (!$clear) {
            imagefilledrectangle($im, 0, 0, $S - 1, $S - 1, $bgCol);
        }

        // Content area: the whole icon less a margin; the maskable one keeps to the safe circle.
        $inset = ['clear' => 0.01, 'full' => 0.07, 'safe' => 0.16][$shape];
        $x0 = (int) ($S * $inset);
        $w = $S - 2 * $x0;

        // Logo: top left, leaving the bottom right corner to the envelope.
        $lw = imagesx($logo);
        $lh = imagesy($logo);
        // Square-ish logo: most of the area, the envelope overlaps its corner. Wide logo (with its
        // name): the whole width, a bit above the middle, the envelope below it.
        $wide = $lw / $lh > 1.6;
        $boxW = $wide ? $w : $w * ($clear ? 0.86 : 0.8);
        $boxH = $wide ? $w * 0.5 : $boxW;
        $scale = min($boxW / $lw, $boxH / $lh);
        $dw = max(1, (int) round($lw * $scale));
        $dh = max(1, (int) round($lh * $scale));
        $dx = (int) ($x0 + ($boxW - $dw) / 2);
        $dy = $wide ? (int) ($x0 + $w * 0.30 - $dh / 2) : (int) ($x0 + ($boxH - $dh) / 2);
        imagecopyresampled($im, $logo, $dx, $dy, 0, 0, $dw, $dh, $lw, $lh);

        // Envelope badge, in the bottom right corner, with a ring of the background (or a
        // transparent cut) around it.
        $b = (int) ($w * ($clear ? 0.42 : 0.44));
        $bx = $x0 + $w - $b;
        $by = $x0 + $w - $b;
        $ring = (int) ($b * 0.07);
        if ($clear) {
            imagealphablending($im, false);
            self::roundRect($im, $bx - $ring, $by - $ring, $b + 2 * $ring, $b + 2 * $ring, (int) ($b * 0.30) + $ring, imagecolorallocatealpha($im, 0, 0, 0, 127));
            imagealphablending($im, true);
        } else {
            self::roundRect($im, $bx - $ring, $by - $ring, $b + 2 * $ring, $b + 2 * $ring, (int) ($b * 0.30) + $ring, $bgCol);
        }
        [$pr, $pg, $pb] = self::rgb($primary);
        self::roundRect($im, $bx, $by, $b, $b, (int) ($b * 0.30), imagecolorallocate($im, $pr, $pg, $pb));
        [$fr, $fg, $fb] = self::rgb(Branding::contrast($primary));
        self::envelope($im, $bx, $by, $b, imagecolorallocate($im, $fr, $fg, $fb));

        $out = imagecreatetruecolor($size, $size);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $im, 0, 0, 0, 0, $size, $size, $S, $S);
        ob_start();
        imagepng($out, null, 9);
        return (string) ob_get_clean();
    }

    /** A brand image, with its empty margins (transparent or white) cut off. */
    private static function load(string $file): ?\GdImage
    {
        $path = storage_path('uploads/brand/' . basename($file));
        if ($file === '' || !is_file($path) || filesize($path) > 5_000_000) {
            return null;
        }
        $info = @getimagesize($path);
        if (!$info || $info[0] * $info[1] > 25_000_000) {
            return null; // not an image, or too big to open safely
        }
        $im = @imagecreatefromstring((string) file_get_contents($path));
        if (!$im) {
            return null;
        }
        if (!imageistruecolor($im)) {
            imagepalettetotruecolor($im);
        }
        imagealphablending($im, false);
        imagesavealpha($im, true);
        return self::trim($im);
    }

    /**
     * Cut off the empty margins (transparent or near white) — imagecropauto() leaves transparent
     * ones. Measured on a copy of at most 256 px, so a big logo costs little.
     */
    private static function trim(\GdImage $im): \GdImage
    {
        $w = imagesx($im);
        $h = imagesy($im);
        $k = max(1, max($w, $h) / 256);
        $sw = max(1, (int) round($w / $k));
        $sh = max(1, (int) round($h / $k));
        $small = imagecreatetruecolor($sw, $sh);
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagecopyresampled($small, $im, 0, 0, 0, 0, $sw, $sh, $w, $h);
        $x1 = $sw;
        $y1 = $sh;
        $x2 = -1;
        $y2 = -1;
        for ($y = 0; $y < $sh; $y++) {
            for ($x = 0; $x < $sw; $x++) {
                $c = imagecolorat($small, $x, $y);
                $empty = (($c >> 24) & 127) > 110 || ((($c >> 16) & 255) > 245 && (($c >> 8) & 255) > 245 && ($c & 255) > 245);
                if (!$empty) {
                    $x1 = min($x1, $x);
                    $x2 = max($x2, $x);
                    $y1 = min($y1, $y);
                    $y2 = max($y2, $y);
                }
            }
        }
        if ($x2 < 0) {
            return $im; // nothing visible: keep it as it is
        }
        $cx = max(0, (int) floor(($x1 - 1) * $k));
        $cy = max(0, (int) floor(($y1 - 1) * $k));
        $cw = min($w, (int) ceil(($x2 + 2) * $k)) - $cx;
        $ch = min($h, (int) ceil(($y2 + 2) * $k)) - $cy;
        if ($cw < 4 || $ch < 4 || ($cw >= $w && $ch >= $h)) {
            return $im;
        }
        $c = imagecrop($im, ['x' => $cx, 'y' => $cy, 'width' => $cw, 'height' => $ch]);
        if ($c instanceof \GdImage) {
            imagealphablending($c, false);
            imagesavealpha($c, true);
            return $c;
        }
        return $im;
    }

    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private static function roundRect(\GdImage $im, int $x, int $y, int $w, int $h, int $r, int $col): void
    {
        $r = max(0, min($r, intdiv(min($w, $h), 2)));
        imagefilledrectangle($im, $x + $r, $y, $x + $w - $r - 1, $y + $h - 1, $col);
        imagefilledrectangle($im, $x, $y + $r, $x + $w - 1, $y + $h - $r - 1, $col);
        foreach ([[$x + $r, $y + $r], [$x + $w - $r - 1, $y + $r], [$x + $r, $y + $h - $r - 1], [$x + $w - $r - 1, $y + $h - $r - 1]] as [$cx, $cy]) {
            imagefilledellipse($im, $cx, $cy, 2 * $r, 2 * $r, $col);
        }
    }

    /** Outline envelope (as the default icon) inside a badge of side $b. */
    private static function envelope(\GdImage $im, int $bx, int $by, int $b, int $col): void
    {
        $t = max(2, (int) ($b * 0.075)); // stroke
        $ew = (int) ($b * 0.58);
        $eh = (int) ($b * 0.42);
        $ex = $bx + intdiv($b - $ew, 2);
        $ey = $by + intdiv($b - $eh, 2);
        $r = (int) ($eh * 0.16);
        // Frame: outer rounded rectangle, the inside cut out again in the badge colour.
        $inner = imagecolorat($im, $bx + intdiv($b, 2), $by + 2);
        self::roundRect($im, $ex, $ey, $ew, $eh, $r, $col);
        self::roundRect($im, $ex + $t, $ey + $t, $ew - 2 * $t, $eh - 2 * $t, max(0, $r - $t), $inner);
        // Flap: two thick lines meeting in the middle, round ends.
        $ax = $ex + (int) ($t * 1.1);
        $ay = $ey + (int) ($t * 1.1);
        $mx = $ex + intdiv($ew, 2);
        $my = $ey + (int) ($eh * 0.56);
        $cx = $ex + $ew - (int) ($t * 1.1);
        self::thickLine($im, $ax, $ay, $mx, $my, $t, $col);
        self::thickLine($im, $mx, $my, $cx, $ay, $t, $col);
    }

    private static function thickLine(\GdImage $im, int $x1, int $y1, int $x2, int $y2, int $t, int $col): void
    {
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        $len = max(1.0, sqrt($dx * $dx + $dy * $dy));
        $nx = -$dy / $len * $t / 2;
        $ny = $dx / $len * $t / 2;
        imagefilledpolygon($im, [
            (int) round($x1 + $nx), (int) round($y1 + $ny), (int) round($x2 + $nx), (int) round($y2 + $ny),
            (int) round($x2 - $nx), (int) round($y2 - $ny), (int) round($x1 - $nx), (int) round($y1 - $ny),
        ], $col);
        imagefilledellipse($im, $x1, $y1, $t, $t, $col);
        imagefilledellipse($im, $x2, $y2, $t, $t, $col);
    }
}
