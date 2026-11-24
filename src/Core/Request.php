<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    public array $params = [];

    /** Surowa treść zapytania. W testach ustawiana wprost. */
    public ?string $rawBody = null;

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

    public function body(): string
    {
        return $this->rawBody ??= (string) file_get_contents('php://input');
    }

    /** Treść zapytania jako JSON (tablica); pusta tablica, gdy to nie jest poprawny obiekt JSON. */
    public function json(): array
    {
        $j = json_decode($this->body(), true);
        return is_array($j) ? $j : [];
    }

    public function header(string $name): string
    {
        return trim((string) ($this->server['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? ''));
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
        // Za proxy hostingu (Render) adres klienta jest pierwszy w X-Forwarded-For: ustawia go hosting, nie odwiedzający.
        // Na innym hostingu wskaż nagłówek, który ustawia tamtejsze proxy (CLIENT_IP_HEADER, np. CF-Connecting-IP),
        // albo wpisz REMOTE_ADDR, gdy przed aplikacją nie ma proxy. Inaczej limity prób dałoby się obejść zmyślonym nagłówkiem.
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '');
        $header = strtoupper(str_replace('-', '_', trim((string) Config::get('CLIENT_IP_HEADER'))));
        if ($header === 'REMOTE_ADDR') {
            return $remote;
        }
        $raw = (string) ($this->server['HTTP_' . ($header !== '' ? $header : 'X_FORWARDED_FOR')] ?? '');
        $ip = trim(explode(',', $raw)[0]);
        // Wartość, która nie jest adresem IP, nie trafia do limitów ani do dziennika.
        return $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : $remote;
    }

    public function isHttps(): bool
    {
        $proto = strtolower(explode(',', (string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]);
        return $proto === 'https' || (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off');
    }

    public function baseUrl(): string
    {
        // APP_URL jest opcjonalny: na Render adres usługi jest w RENDER_EXTERNAL_URL, a w ostateczności bierzemy go z zapytania.
        $configured = rtrim((string) (Config::get('APP_URL') ?: Config::get('RENDER_EXTERNAL_URL', '')), '/');
        if ($configured !== '') {
            return $configured;
        }
        $host = preg_replace('/[^a-z0-9.:\-\[\]]/i', '', (string) ($this->server['HTTP_HOST'] ?? 'localhost'));
        return ($this->isHttps() ? 'https' : 'http') . '://' . $host;
    }
}
