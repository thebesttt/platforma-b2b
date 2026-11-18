<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = ['Content-Type' => 'text/html; charset=utf-8'],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status);
    }

    public static function redirect(string $to, int $status = 303): self
    {
        return new self('', $status, ['Location' => $to]);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(json_out($data), $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function download(string $body, string $filename, string $mime): self
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'plik';
        return new self($body, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . $safe . '"; filename*=UTF-8\'\'' . rawurlencode($filename),
            'Cache-Control' => 'no-store',
        ]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; form-action 'self' mailto:; frame-ancestors 'none'; base-uri 'self'",
        ];
        if (!headers_sent()) {
            foreach (array_merge($defaults, $this->headers) as $k => $v) {
                header($k . ': ' . $v);
            }
        }
        echo $this->body;
    }
}
