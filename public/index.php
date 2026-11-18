<?php

declare(strict_types=1);

// Wbudowany serwer PHP (php -S) sam serwuje pliki statyczne z public/.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($file !== __DIR__ . '/' && is_file($file)) {
        return false;
    }
}

require __DIR__ . '/../src/bootstrap.php';

App\Core\App::run();
