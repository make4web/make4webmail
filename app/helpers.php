<?php
declare(strict_types=1);

use M4W\Core\Config;
use M4W\Core\I18n;
use M4W\Core\Request;

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

function t(string $key, array $vars = []): string
{
    return I18n::t($key, $vars);
}

/** Escaped translation, shortcut for templates. */
function te(string $key, array $vars = []): string
{
    return e(I18n::t($key, $vars));
}

function url(string $path = '/', array $query = []): string
{
    $base = Request::basePath();
    $u = $base . '/' . ltrim($path, '/');
    if ($query) {
        $u .= '?' . http_build_query($query);
    }
    return $u === '' ? '/' : $u;
}

function asset(string $path): string
{
    $file = M4W_ROOT . '/public/assets/' . ltrim($path, '/');
    $v = is_file($file) ? substr(md5((string) filemtime($file)), 0, 8) : M4W_VERSION;
    return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

function csrf_field(): string
{
    return M4W\Core\Csrf::field();
}

function setting(string $key, mixed $default = null): mixed
{
    return M4W\Core\Settings::get($key, $default);
}

function format_bytes(int|float $bytes, int $precision = 1): string
{
    $units = ['o', 'Ko', 'Mo', 'Go', 'To'];
    if (I18n::language() === 'en') {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    }
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    $n = (string) round($bytes, $i === 0 ? 0 : $precision);
    return (I18n::language() === 'fr' ? str_replace('.', ',', $n) : $n) . ' ' . $units[$i];
}

function format_datetime(int $ts, bool $withTime = true): string
{
    if ($ts <= 0) {
        return '—';
    }
    $fmt = I18n::language() === 'fr' ? ($withTime ? 'd/m/Y H:i' : 'd/m/Y') : ($withTime ? 'Y-m-d H:i' : 'Y-m-d');
    return date($fmt, $ts);
}

function initials(string $name): string
{
    $name = trim(preg_replace('/[<"].*$/u', '', $name) ?? $name);
    $parts = preg_split('/[\s._@-]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
    $i = mb_strtoupper(mb_substr($parts[0], 0, 1));
    if (count($parts) > 1) {
        $i .= mb_strtoupper(mb_substr($parts[1], 0, 1));
    }
    return $i;
}

function avatar_color(string $seed): string
{
    $palette = ['#1d4ed8', '#6d28d9', '#be185d', '#b91c1c', '#c2410c', '#a16207', '#15803d', '#0f766e', '#0e7490', '#4338ca'];
    return $palette[abs(crc32(mb_strtolower($seed))) % count($palette)];
}

function storage_path(string $sub = ''): string
{
    return Config::storagePath($sub);
}

function is_valid_email(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) && strlen($email) <= 190;
}

function str_limit(string $s, int $n): string
{
    return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s;
}

function audit_label(string $action): string
{
    $key = 'audit.' . $action;
    $s = t($key);
    return $s === $key ? $action : $s;
}
