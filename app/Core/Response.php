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
