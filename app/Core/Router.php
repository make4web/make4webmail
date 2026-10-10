<?php
declare(strict_types=1);

namespace M4W\Core;

final class Router
{
    /** @var array<int, array{0:string,1:string,2:callable|array,3:array}> */
    private array $routes = [];

    public function add(string $methods, string $pattern, callable|array $handler, array $opts = []): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        foreach (explode('|', $methods) as $m) {
            $this->routes[] = [strtoupper($m), $regex, $handler, $opts];
        }
    }

    public function get(string $p, callable|array $h, array $o = []): void
    {
        $this->add('GET', $p, $h, $o);
    }

    public function post(string $p, callable|array $h, array $o = []): void
    {
        $this->add('POST', $p, $h, $o);
    }

    /** @return array{0:callable|array,1:array,2:array}|null|false  false = method not allowed */
    public function match(Request $req): array|null|false
    {
        $pathMatched = false;
        foreach ($this->routes as [$method, $regex, $handler, $opts]) {
            if (preg_match($regex, $req->path, $m)) {
                $pathMatched = true;
                if ($method === $req->method || ($method === 'GET' && $req->method === 'HEAD')) {
                    $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                    return [$handler, $params, $opts];
                }
            }
        }
        return $pathMatched ? false : null;
    }
}
