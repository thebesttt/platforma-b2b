<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Trasy w postaci '/przetargi/{id}'. Parametry trafiają do $request->params.
 * Opcje trasy: 'auth' => true (wymaga logowania), 'csrf' => false (wyłącza sprawdzanie tokena dla POST).
 */
final class Router
{
    private array $routes = [];

    public function get(string $pattern, callable|array $handler, array $opts = []): void
    {
        $this->add('GET', $pattern, $handler, $opts);
    }

    public function post(string $pattern, callable|array $handler, array $opts = []): void
    {
        $this->add('POST', $pattern, $handler, $opts);
    }

    public function add(string $method, string $pattern, callable|array $handler, array $opts = []): void
    {
        $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[^/]+)', rtrim($pattern, '/') ?: '/') . '$#u';
        $this->routes[] = compact('method', 'pattern', 'regex', 'handler', 'opts');
    }

    /** @return array{0: ?array, 1: bool} trasa i informacja, czy ścieżka istnieje dla innej metody */
    public function match(string $method, string $path): array
    {
        $other = false;
        foreach ($this->routes as $r) {
            if (preg_match($r['regex'], $path, $m)) {
                if ($r['method'] !== $method && !($method === 'HEAD' && $r['method'] === 'GET')) {
                    $other = true;
                    continue;
                }
                $r['params'] = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                return [$r, false];
            }
        }
        return [null, $other];
    }
}
