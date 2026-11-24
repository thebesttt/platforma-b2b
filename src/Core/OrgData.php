<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Dane firmy na żądanie: komplet do pobrania i trwałe usunięcie konta firmy.
 *
 * Listę tabel bierzemy z samej bazy (każda tabela z kolumną org_id), więc nowe moduły trafiają tu bez dopisywania.
 * W eksporcie nie ma haseł, kluczy logowania dwuetapowego ani zaszyfrowanych sekretów.
 */
final class OrgData
{
    /** Kolumny, które nie opuszczają bazy. */
    private const HIDDEN = ['password_hash', 'totp_secret', 'recovery_json', 'value_enc', 'token_hash', 'session_epoch'];

    private const MAX_ROWS = 50_000;

    /** @return string[] tabele z kolumną org_id */
    public static function tables(): array
    {
        $db = Db::get();
        $out = [];
        if ($db->driver() === 'pgsql') {
            foreach ($db->all("SELECT table_name FROM information_schema.columns WHERE table_schema = current_schema() AND column_name = 'org_id' ORDER BY table_name") as $r) {
                $out[] = (string) $r['table_name'];
            }
            return $out;
        }
        foreach ($db->all("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $t) {
            foreach ($db->all('PRAGMA table_info(' . preg_replace('/[^a-z0-9_]/i', '', (string) $t['name']) . ')') as $c) {
                if ($c['name'] === 'org_id') {
                    $out[] = (string) $t['name'];
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Paczka ZIP z danymi firmy: jeden plik JSON na tabelę.
     * @return array{zip: string, tables: int, rows: int}
     */
    public static function export(int $orgId): array
    {
        $db = Db::get();
        $clean = static function (array $rows): array {
            return array_map(static fn (array $r): array => array_diff_key($r, array_flip(self::HIDDEN)), $rows);
        };
        $files = ['firma.json' => $clean($db->all('SELECT * FROM organizations WHERE id = ?', [$orgId]))];
        $rows = 0;
        foreach (self::tables() as $table) {
            $data = $clean($db->all("SELECT * FROM $table WHERE org_id = ? ORDER BY 1 LIMIT " . self::MAX_ROWS, [$orgId]));
            if ($data) {
                $files[$table . '.json'] = $data;
                $rows += count($data);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'eksport');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('CZYTAJ.txt', "Dane firmy z panelu " . Config::get('APP_NAME') . ", stan na " . now_utc() . " UTC.\n"
            . "Każdy plik to jedna tabela w formacie JSON. Brakuje w nich haseł, kluczy logowania dwuetapowego i zaszyfrowanych tokenów połączeń.\n"
            . "Publiczne dane o przetargach i firmach z rejestrów nie są danymi tej firmy, więc ich tu nie ma.\n");
        foreach ($files as $name => $data) {
            $zip->addFromString($name, (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
        }
        $zip->close();
        $body = (string) file_get_contents($path);
        @unlink($path);
        return ['zip' => $body, 'tables' => count($files), 'rows' => $rows];
    }

    /** Usuwa firmę ze wszystkimi danymi i kontami. Nie da się tego cofnąć. */
    public static function delete(int $orgId): void
    {
        $db = Db::get();
        $tables = self::tables();
        $db->transaction(static function (Db $db) use ($orgId, $tables): void {
            // Najpierw tabele zależne. Konta zostają na koniec, bo wskazują na nie inne wiersze firmy.
            foreach (array_diff($tables, ['users']) as $table) {
                $db->run("DELETE FROM $table WHERE org_id = ?", [$orgId]);
            }
            $db->run('DELETE FROM users WHERE org_id = ?', [$orgId]);
            $db->run('DELETE FROM organizations WHERE id = ?', [$orgId]);
        });
    }
}
