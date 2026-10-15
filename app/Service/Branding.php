<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Settings;

/**
 * Visual customisation: generates the CSS custom properties layered on top of Bootstrap.
 */
final class Branding
{
    public static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            $hex = '2563eb';
        }
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    public static function shade(string $hex, float $pct): string
    {
        [$r, $g, $b] = self::hexToRgb($hex);
        $f = static fn($c) => (int) max(0, min(255, $pct < 0 ? $c * (1 + $pct) : $c + (255 - $c) * $pct));
        return sprintf('#%02x%02x%02x', $f($r), $f($g), $f($b));
    }

    /** Readable foreground (white/black) for a background color (WCAG relative luminance). */
    public static function contrast(string $hex): string
    {
        [$r, $g, $b] = array_map(static function ($c) {
            $c /= 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::hexToRgb($hex));
        $l = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
        return $l > 0.45 ? '#0f172a' : '#ffffff';
    }

    public static function css(): string
    {
        $p = self::color('brand.primary', '#2563eb');
        $a = self::color('brand.accent', '#0ea5e9');
        [$r, $g, $b] = self::hexToRgb($p);
        [$ar, $ag, $ab] = self::hexToRgb($a);
        $radius = max(0, min(24, (int) Settings::get('brand.radius', 10)));
        $font = Settings::get('brand.font', 'inter') === 'system'
            ? 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'
            : '"Inter Variable", system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif';
        $sidebar = (string) Settings::get('brand.sidebar', 'light');
        $css = ":root{\n"
            . "--m4w-primary:$p;--m4w-primary-rgb:$r,$g,$b;--m4w-primary-hover:" . self::shade($p, -0.12) . ";"
            . "--m4w-primary-active:" . self::shade($p, -0.2) . ";--m4w-primary-soft:rgba($r,$g,$b,.10);--m4w-primary-softer:rgba($r,$g,$b,.06);"
            . "--m4w-on-primary:" . self::contrast($p) . ";--m4w-accent:$a;--m4w-accent-rgb:$ar,$ag,$ab;"
            . "--m4w-radius:{$radius}px;--m4w-radius-sm:" . max(0, $radius - 4) . "px;--m4w-radius-lg:" . ($radius ? $radius + 6 : 0) . "px;"
            . "--m4w-radius-xs:" . (int) round($radius * .3) . "px;--m4w-radius-pill:" . ($radius ? '999px' : '0') . ";--m4w-radius-round:" . ($radius ? '50%' : '0') . ";"
            . "--m4w-font:$font;"
            . "--bs-primary:$p;--bs-primary-rgb:$r,$g,$b;--bs-link-color:$p;--bs-link-color-rgb:$r,$g,$b;--bs-link-hover-color:" . self::shade($p, -0.15) . ";"
            . "--bs-border-radius:{$radius}px;--bs-border-radius-sm:" . max(0, $radius - 4) . "px;--bs-border-radius-lg:" . ($radius ? $radius + 4 : 0) . "px;"
            . "--bs-border-radius-xl:" . ($radius ? $radius + 6 : 0) . "px;--bs-border-radius-xxl:" . ($radius ? $radius * 2 : 0) . "px;--bs-border-radius-pill:" . ($radius ? '50rem' : '0') . ";"
            . "--bs-focus-ring-color:rgba($r,$g,$b,.25);--bs-body-font-family:$font;"
            . "}\n";
        $css .= self::chromeCss($sidebar, $p, $a, [$r, $g, $b]);
        $custom = (string) Settings::get('brand.custom_css', '');
        if ($custom !== '') {
            $css .= "/* custom */\n" . str_ireplace(['</style', '<script', 'javascript:', 'expression('], '', $custom) . "\n";
        }
        return $css;
    }

    public const SIDEBAR_STYLES = ['light', 'dark', 'brand', 'accent', 'custom', 'image'];

    /** Side menu (and header) colours: a colour of the brand, one of one's own, or a picture. */
    private static function chromeCss(string $style, string $p, string $a, array $rgb): string
    {
        [$r, $g, $b] = $rgb;
        if ($style === 'dark') {
            return ":root{--m4w-sidebar-bg:#0f172a;--m4w-sidebar-fg:#e2e8f0;--m4w-sidebar-hover:rgba(255,255,255,.07);--m4w-sidebar-active:rgba($r,$g,$b,.35);--m4w-sidebar-muted:#94a3b8}\n";
        }
        $bg = self::chromeColor($style);
        if ($bg === '') {
            return '';
        }
        $light = self::contrast($bg) === '#ffffff'; // light text on it
        $vars = "--m4w-sidebar-bg:$bg;--m4w-sidebar-fg:" . ($light ? '#ffffff' : '#0f172a') . ';'
            . '--m4w-sidebar-hover:' . ($light ? 'rgba(0,0,0,.12)' : 'rgba(0,0,0,.06)') . ';--m4w-sidebar-active:' . ($light ? 'rgba(0,0,0,.22)' : 'rgba(0,0,0,.1)') . ';'
            . '--m4w-sidebar-muted:' . ($light ? 'rgba(255,255,255,.88)' : 'rgba(15,23,42,.75)') . ';';
        $file = (string) Settings::get('brand.sidebar_bg', '');
        if ($style === 'image' && $file !== '' && preg_match('/^[\w.\-]+$/', $file)) {
            // A veil keeps the text readable whatever the picture; fixed, so header and menu join up.
            $veil = $light ? 'rgba(15,23,42,.35)' : 'rgba(255,255,255,.45)';
            $size = Settings::get('brand.sidebar_bg_fit', 'cover') === 'tile' ? 'auto' : 'cover';
            $vars .= "--m4w-chrome-bg:linear-gradient($veil,$veil),url('" . url('brand/' . $file) . "') center/$size " . ($size === 'auto' ? 'repeat' : 'no-repeat') . " fixed,$bg;";
        }
        return ":root{{$vars}}\n";
    }

    /** Background colour of the side menu for a style ('' for the light one). */
    private static function chromeColor(string $style): string
    {
        return match ($style) {
            'dark' => '#0f172a',
            'brand' => self::color('brand.primary', '#2563eb'),
            'accent' => self::color('brand.accent', '#0ea5e9'),
            'custom' => self::color('brand.sidebar_color', '#0f766e'),
            'image' => self::color('brand.sidebar_bg_avg', '#334155'),
            default => '',
        };
    }

    /** Classes of <body> for the side menu style: coloured, light text on it, carried over to the header. */
    public static function bodyClasses(): string
    {
        $style = (string) Settings::get('brand.sidebar', 'light');
        $bg = in_array($style, self::SIDEBAR_STYLES, true) ? self::chromeColor($style) : '';
        if ($bg === '') {
            return '';
        }
        return 'has-chrome' . (self::contrast($bg) === '#ffffff' ? ' chrome-on-dark' : '') . ((int) Settings::get('brand.sidebar_header', 1) ? ' chrome-header' : '');
    }

    /** Average colour of a picture (GD), to pick the text colour that goes on it. */
    public static function averageColor(string $path): string
    {
        $data = @file_get_contents($path);
        $im = $data !== false && function_exists('imagecreatefromstring') ? @imagecreatefromstring($data) : false;
        if (!$im) {
            return '#334155';
        }
        $px = imagecreatetruecolor(1, 1);
        imagecopyresampled($px, $im, 0, 0, 0, 0, 1, 1, imagesx($im), imagesy($im));
        $c = imagecolorat($px, 0, 0);
        return sprintf('#%02x%02x%02x', ($c >> 16) & 255, ($c >> 8) & 255, $c & 255);
    }

    private static function color(string $key, string $default): string
    {
        $v = (string) Settings::get($key, $default);
        return preg_match('/^#[0-9a-f]{6}$/i', $v) ? strtolower($v) : $default;
    }

    public static function logoUrl(bool $dark = false): string
    {
        $f = (string) Settings::get($dark ? 'brand.logo_dark' : 'brand.logo', '');
        if ($dark && $f === '') {
            $f = (string) Settings::get('brand.logo', '');
        }
        return $f !== '' ? url('brand/' . $f) : '';
    }

    /** Validate and store an uploaded image (logo, favicon, background). Returns stored file name. */
    public static function storeImage(array $file, string $prefix, int $maxBytes = 3_000_000): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new \InvalidArgumentException(t('upload.failed'));
        }
        if ($file['size'] > $maxBytes) {
            throw new \InvalidArgumentException(t('upload.too_big'));
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $ext = match ($mime) {
            'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp',
            'image/x-icon', 'image/vnd.microsoft.icon' => 'ico',
            default => null,
        };
        if ($ext === null || ($ext !== 'ico' && @getimagesize($file['tmp_name']) === false)) {
            throw new \InvalidArgumentException(t('upload.not_image'));
        }
        $dir = storage_path('uploads/brand');
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $name = $prefix . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
        move_uploaded_file($file['tmp_name'], $dir . '/' . $name);
        return $name;
    }
}
