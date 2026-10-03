<?php
declare(strict_types=1);

namespace M4W\Controller;

use M4W\Core\Response;
use M4W\Core\Settings;
use M4W\Service\Branding;

final class AssetController extends Controller
{
    public function theme(): Response
    {
        $css = \M4W\Core\Config::installed() ? Branding::css() : '';
        $etag = '"' . md5($css) . '"';
        if (($this->req->server['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            return new Response('', 304, ['ETag' => $etag, 'Cache-Control' => 'public, max-age=300']);
        }
        return new Response($css, 200, [
            'Content-Type' => 'text/css; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
            'ETag' => $etag,
        ]);
    }

    public function brand(string $file): Response
    {
        $file = basename($file);
        $path = storage_path('uploads/brand/' . $file);
        if (!preg_match('/^[a-z]+-[0-9a-f]{12}\.(png|jpg|gif|webp|ico)$/', $file) || !is_file($path)) {
            return new Response('', 404);
        }
        $mime = match (pathinfo($file, PATHINFO_EXTENSION)) {
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', default => 'image/x-icon',
        };
        $r = Response::file($path, $mime, $file, true);
        $r->header('Cache-Control', 'public, max-age=604800, immutable');
        $r->header('Cross-Origin-Resource-Policy', 'cross-origin');
        return $r;
    }

    public function manifest(): Response
    {
        $name = (string) Settings::get('brand.name', 'Make4Web Mail');
        $icon = Branding::logoUrl() ?: url('assets/img/icon.svg');
        return new Response(json_encode([
            'name' => $name,
            'short_name' => mb_substr($name, 0, 12),
            'start_url' => url('mail'),
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => (string) Settings::get('brand.primary', '#2563eb'),
            'icons' => [['src' => $icon, 'sizes' => 'any']],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 200, ['Content-Type' => 'application/manifest+json']);
    }
}
