<?php
declare(strict_types=1);

namespace M4W\Core;

final class Response
{
    private array $headers = [];

    public function __construct(
        public string $body = '',
        public int $status = 200,
        array $headers = [],
        private ?\Closure $stream = null,
    ) {
        foreach ($headers as $k => $v) {
            $this->headers[$k] = $v;
        }
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']
        );
    }

    public static function redirect(string $to, int $status = 302): self
    {
        return new self('', $status, ['Location' => str_starts_with($to, 'http') ? $to : url($to)]);
    }

    public static function file(string $path, string $mime, string $filename, bool $inline = false): self
    {
        $r = new self('', 200, [
            'Content-Type'        => $mime,
            'Content-Length'      => (string) filesize($path),
            'Content-Disposition' => self::disposition($inline ? 'inline' : 'attachment', $filename),
            'Cache-Control'       => 'private, max-age=0, no-store',
        ], static function () use ($path) {
            readfile($path);
        });
        return $r;
    }

    public static function download(string $content, string $mime, string $filename, bool $inline = false): self
    {
        return new self($content, 200, [
            'Content-Type'        => $mime,
            'Content-Length'      => (string) strlen($content),
            'Content-Disposition' => self::disposition($inline ? 'inline' : 'attachment', $filename),
            'Cache-Control'       => 'private, max-age=0, no-store',
        ]);
    }

    public static function disposition(string $type, string $filename): string
    {
        $fallback = preg_replace('/[^\x20-\x7e]|["\\\\]/', '_', $filename) ?: 'file';
        return sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $type, $fallback, rawurlencode($filename));
    }

    /**
     * Policy for a file handed out by the application: nothing in it may run script or be
     * framed elsewhere. A PDF shown in the page cannot be sandboxed (the browser's PDF viewer
     * refuses to start in a sandbox), so it is only kept off any other origin.
     */
    public static function fileCsp(string $mime): string
    {
        if ($mime === 'application/pdf') {
            return "default-src 'none'; object-src 'self'; frame-ancestors 'self'";
        }
        return "default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'; sandbox";
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            // The version on the server: an open app (often for days on a phone) sees it changed.
            header('X-M4W-Version: ' . M4W_VERSION);
            http_response_code($this->status);
            foreach ($this->headers as $k => $v) {
                header($k . ': ' . $v);
            }
        }
        if ($this->stream) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            ($this->stream)();
        } else {
            echo $this->body;
        }
    }
}
