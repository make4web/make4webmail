<?php
declare(strict_types=1);

namespace M4W\Core;

final class Request
{
    public array $params = [];
    private ?array $json = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $files,
        public readonly array $server,
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $base = self::basePath();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        if (str_starts_with($path, '/index.php')) {
            $path = substr($path, 10) ?: '/';
        }
        $path = '/' . trim(rawurldecode($path), '/');
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            $_POST,
            $_FILES,
            $_SERVER
        );
    }

    /** Sub-directory in which the app is served (e.g. "/webmail"), without trailing slash. */
    public static function basePath(): string
    {
        static $base = null;
        if ($base === null) {
            $configured = (string) Config::get('base_url', '');
            if ($configured !== '') {
                $base = rtrim((string) parse_url($configured, PHP_URL_PATH), '/');
            } else {
                $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
                $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
            }
        }
        return $base;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $json = $this->json();
        if (array_key_exists($key, $json)) {
            return $json[$key];
        }
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public function raw(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        return is_scalar($v) ? (string) $v : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->input($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function bool(string $key): bool
    {
        $v = $this->input($key, false);
        return in_array($v, [true, 1, '1', 'on', 'true', 'yes'], true);
    }

    public function arr(string $key): array
    {
        $v = $this->input($key, []);
        return is_array($v) ? $v : [];
    }

    public function json(): array
    {
        if ($this->json === null) {
            $this->json = [];
            $ct = $this->server['CONTENT_TYPE'] ?? '';
            if (str_contains($ct, 'application/json')) {
                $data = json_decode((string) file_get_contents('php://input'), true);
                $this->json = is_array($data) ? $data : [];
            }
        }
        return $this->json;
    }

    public function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return (string) ($this->server[$key] ?? '');
    }

    public function isAjax(): bool
    {
        return $this->header('X-Requested-With') === 'XMLHttpRequest'
            || str_starts_with($this->path, '/api/');
    }

    public function isSecure(): bool
    {
        if (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off') {
            return true;
        }
        if ($this->fromTrustedProxy()) {
            return strtolower($this->header('X-Forwarded-Proto')) === 'https';
        }
        return false;
    }

    private function fromTrustedProxy(): bool
    {
        $proxies = (array) Config::get('trusted_proxies', []);
        return in_array($this->server['REMOTE_ADDR'] ?? '', $proxies, true);
    }

    public function ip(): string
    {
        $ip = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        if ($this->fromTrustedProxy()) {
            $fwd = $this->header('X-Forwarded-For');
            if ($fwd !== '') {
                $first = trim(explode(',', $fwd)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) {
                    $ip = $first;
                }
            }
        }
        return $ip;
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }
}
