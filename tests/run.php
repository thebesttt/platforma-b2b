<?php
/**
 * Testy bez zewnętrznych bibliotek: php tests/run.php [filtr]
 * Każdy plik tests/unit/*Test.php zwraca tablicę 'nazwa testu' => function () {...}.
 * Baza: SQLite w pamięci, odpowiedzi API z tests/fixtures/http (bez dostępu do sieci).
 */

declare(strict_types=1);

putenv('APP_ENV=test');
// TEST_DATABASE_URL=postgres://… uruchamia te same testy na PostgreSQL (schemat czyszczony przed każdym testem).
$testDb = getenv('TEST_DATABASE_URL') ?: 'sqlite::memory:';
putenv('DATABASE_URL=' . $testDb);
putenv('HTTP_FIXTURES=' . __DIR__ . '/fixtures/http');
putenv('ANTHROPIC_API_KEY=test-key');
putenv('CRON_TOKEN=test-cron-token');
putenv('APP_NAME=Testowa');

require __DIR__ . '/../src/bootstrap.php';

final class AssertionFailed extends Exception
{
}

function ok(bool $cond, string $msg = 'warunek niespełniony'): void
{
    if (!$cond) {
        throw new AssertionFailed($msg);
    }
}

function eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(($msg ? $msg . ': ' : '') . 'oczekiwano ' . var_export($expected, true) . ', jest ' . var_export($actual, true));
    }
}

function contains(string $needle, string $haystack, string $msg = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new AssertionFailed(($msg ? $msg . ': ' : '') . 'brak „' . $needle . '” w tekście: ' . mb_substr($haystack, 0, 300));
    }
}

/** Świeża baza w pamięci z migracjami. */
function fresh_db(): App\Core\Db
{
    $url = (string) getenv('DATABASE_URL');
    $db = App\Core\Db::useUrl($url);
    if ($db->driver() === 'pgsql') {
        $db->pdo()->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
    }
    (new App\Core\Migrator($db))->migrate();
    $_SESSION = [];
    App\Core\Auth::logout();
    return $db;
}

/** Firma z właścicielem; zwraca [orgId, userId]. */
function make_org(string $email = 'wlasciciel@example.com', string $company = 'Firma Testowa sp. z o.o.'): array
{
    $res = App\Core\Auth::register($company, '1234563218', 'Jan Test', $email, 'haslo-testowe-1');
    ok($res['ok'], 'rejestracja: ' . ($res['error'] ?? ''));
    $u = App\Core\Db::get()->one('SELECT * FROM users WHERE id = ?', [$res['user_id']]);
    return [(int) $u['org_id'], (int) $u['id']];
}

/**
 * Import ogłoszeń z fixture'ów. Terminy przesuwamy w przyszłość, żeby testy nie zależały od daty uruchomienia.
 * @return array wynik importu
 */
function import_fixtures(): array
{
    $res = App\Modules\Tenders\Importer::run('2026-11-15 00:00:00');
    App\Core\Db::get()->run('UPDATE tenders SET deadline_at = ?', [gmdate('Y-m-d 09:00:00', time() + 14 * 86400)]);
    return $res;
}

/** Zapytanie HTTP do aplikacji bez serwera. */
function http(string $method, string $path, array $post = [], array $query = [], array $files = []): App\Core\Response
{
    if ($method === 'POST' && !array_key_exists('_csrf', $post)) {
        $post['_csrf'] = App\Core\Csrf::token();
    }
    $r = new App\Core\Request($method, $path, $query, $post, $files, ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost']);
    return App\Core\App::handle($r);
}

$filter = $argv[1] ?? '';
$files = glob(__DIR__ . '/unit/*Test.php') ?: [];
sort($files);
$passed = 0;
$failed = [];
foreach ($files as $file) {
    $tests = require $file;
    foreach ($tests as $name => $fn) {
        $full = basename($file, '.php') . ' › ' . $name;
        if ($filter !== '' && !str_contains(mb_strtolower($full), mb_strtolower($filter))) {
            continue;
        }
        try {
            fresh_db();
            $fn();
            $passed++;
            echo "  ✓ $full\n";
        } catch (Throwable $e) {
            $failed[] = $full;
            echo "  ✗ $full\n    " . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
        }
    }
}
echo "\nBaza: " . App\Core\Db::get()->driver() . "\n" . $passed . ' zaliczonych, ' . count($failed) . " niezaliczonych\n";
exit($failed ? 1 : 0);
