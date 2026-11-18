<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    public array $params = [];

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
        $path = rawurldecode(parse_url($uri, PHP_URL_PATH) ?: '/');
        $path = '/' . trim($path, '/');
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            $_POST,
            $_FILES,
            $_SERVER
        );
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->input($key);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function param(string $key): string
    {
        return (string) ($this->params[$key] ?? '');
    }

    public function ip(): string
    {
        // Za proxy hostingu (Render) adres klienta jest pierwszy w X-Forwarded-For.
        $xff = $this->server['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff !== '') {
            return trim(explode(',', $xff)[0]);
        }
        return (string) ($this->server['REMOTE_ADDR'] ?? '');
    }

    public function isHttps(): bool
    {
        $proto = strtolower(explode(',', (string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]);
        return $proto === 'https' || (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off');
    }

    public function baseUrl(): string
    {
        $configured = rtrim((string) Config::get('APP_URL'), '/');
        if ($configured !== '') {
            return $configured;
        }
        $host = preg_replace('/[^a-z0-9.:\-\[\]]/i', '', (string) ($this->server['HTTP_HOST'] ?? 'localhost'));
        return ($this->isHttps() ? 'https' : 'http') . '://' . $host;
    }
}
