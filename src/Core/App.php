<?php

declare(strict_types=1);

namespace App\Core;

final class App
{
    /** Bieżące zapytanie - dla widoków (aktywna pozycja menu). */
    public static ?Request $request = null;

    public static function router(): Router
    {
        $router = new Router();
        (require APP_ROOT . '/src/routes.php')($router);
        return $router;
    }

    public static function run(): void
    {
        $request = Request::fromGlobals();
        self::handle($request)->send();
    }

    public static function handle(Request $request): Response
    {
        self::$request = $request;
        try {
            if ($request->path === '/zdrowie') {
                Db::get()->val('SELECT 1');
                return Response::text('ok');
            }
            // Widżet na stronie klienta, strona wypisania z wiadomości i strona zgody na ofertę działają bez sesji i bez plików cookie.
            if (!str_starts_with($request->path, '/w/') && !str_starts_with($request->path, '/wypisz/') && !str_starts_with($request->path, '/z/')) {
                Session::start($request);
            }
            [$route, $otherMethod] = self::router()->match($request->method, $request->path);
            if (!$route) {
                return $otherMethod
                    ? Response::html(View::render('errors/message', ['title' => 'Niedozwolona metoda', 'message' => 'Ta strona nie obsługuje tego rodzaju zapytania.'], 'public'), 405)
                    : Response::html(View::render('errors/message', ['title' => 'Nie ma takiej strony', 'message' => 'Sprawdź adres albo wróć na pulpit.'], Auth::check() ? 'app' : 'public'), 404);
            }
            $request->params = $route['params'];
            $opts = $route['opts'];
            if (($opts['auth'] ?? false) && !Auth::check()) {
                Session::flash('info', 'Zaloguj się, aby kontynuować.');
                return Response::redirect('/logowanie?next=' . rawurlencode($request->path));
            }
            // Gdy przesłane pliki przekraczają limit serwera, PHP odrzuca całe zapytanie (puste pola formularza).
            if ($request->method === 'POST' && !$request->post && (int) ($request->server['CONTENT_LENGTH'] ?? 0) > 0
                && str_starts_with((string) ($request->server['CONTENT_TYPE'] ?? ''), 'multipart/form-data')) {
                return Response::html(View::render('errors/message', ['title' => 'Pliki są za duże', 'message' => 'Łączny rozmiar przesłanych plików przekracza limit serwera. Wgraj mniej plików naraz albo mniejsze pliki.'], Auth::check() ? 'app' : 'public'), 413);
            }
            // Kod 403, nie 419: Apache zamienia niestandardowe kody odpowiedzi na błąd 500.
            if ($request->method === 'POST' && ($opts['csrf'] ?? true) && !Csrf::check($request)) {
                return Response::html(View::render('errors/message', ['title' => 'Formularz wygasł', 'message' => 'Odśwież stronę i spróbuj ponownie.'], Auth::check() ? 'app' : 'public'), 403);
            }
            $handler = $route['handler'];
            if (is_array($handler)) {
                [$class, $method] = $handler;
                $result = (new $class())->$method($request);
            } else {
                $result = $handler($request);
            }
            return $result instanceof Response ? $result : Response::html((string) $result);
        } catch (\Throwable $e) {
            error_log('[app] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $msg = Config::isDev() ? $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')' : 'Coś poszło nie tak. Spróbuj ponownie za chwilę.';
            try {
                return Response::html(View::render('errors/message', ['title' => 'Błąd', 'message' => $msg], 'public'), 500);
            } catch (\Throwable) {
                return Response::text($msg, 500);
            }
        }
    }
}
