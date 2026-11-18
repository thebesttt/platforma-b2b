<?php

declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    protected function view(string $template, array $data = [], string $layout = 'app'): Response
    {
        return Response::html(View::render($template, $data, $layout));
    }

    protected function redirect(string $to, ?string $flash = null, string $type = 'success'): Response
    {
        if ($flash !== null) {
            Session::flash($type, $flash);
        }
        return Response::redirect($to);
    }

    protected function back(Request $r, string $fallback, ?string $flash = null, string $type = 'success'): Response
    {
        $ref = (string) ($r->server['HTTP_REFERER'] ?? '');
        $path = parse_url($ref, PHP_URL_PATH);
        $query = parse_url($ref, PHP_URL_QUERY);
        $to = (is_string($path) && str_starts_with($path, '/') && !str_starts_with($path, '//')) ? $path . ($query ? '?' . $query : '') : $fallback;
        return $this->redirect($to, $flash, $type);
    }

    protected function notFound(string $what = 'Nie znaleziono'): Response
    {
        return Response::html(View::render('errors/message', ['title' => $what, 'message' => 'Element nie istnieje albo nie należy do Twojej firmy.']), 404);
    }

    protected function db(): Db
    {
        return Db::get();
    }

    protected function orgId(): int
    {
        return Auth::orgId();
    }
}
