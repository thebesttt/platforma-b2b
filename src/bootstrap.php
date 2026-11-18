<?php
/**
 * Start aplikacji: autoloader, konfiguracja ze zmiennych środowiskowych, obsługa błędów.
 * Bez zewnętrznych zależności: wystarczy PHP 8.2+ z pdo_pgsql/pdo_sqlite, curl, mbstring, zip.
 */

declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
const APP_VERSION = '0.1.0';

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'App\\', 4) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Lokalny plik .env (tylko do pracy na komputerze; na serwerze zmienne ustawia hosting).
$envFile = APP_ROOT . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if (getenv($k) === false) {
            putenv($k . '=' . trim($v, "\"'"));
        }
    }
}

date_default_timezone_set('Europe/Warsaw');
mb_internal_encoding('UTF-8');
ini_set('default_charset', 'UTF-8');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__ . '/helpers.php';
