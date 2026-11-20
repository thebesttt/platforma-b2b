<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Wygląd i zestaw modułów. Oba są ustawieniem firmy (organizations.settings_json), a domyślny wygląd podaje
 * zmienna APP_THEME. Dzięki temu ten sam silnik działa pod marką PerfectData i pod własnym wyglądem platformy.
 *
 * Wyłączenie modułu ukrywa go w menu. To ustawienie wygody, nie zabezpieczenie: dane chroni izolacja po org_id.
 */
final class Brand
{
    public const THEMES = ['default' => 'Standardowy (jasny)', 'perfectdata' => 'PerfectData (granat i złoto)'];

    /** Kolejność = kolejność w menu. */
    public const MODULES = [
        'zapytania' => 'Zapytania ze strony',
        'klienci' => 'Klienci i wiadomości',
        'audyt' => 'Audyt i reklamy',
        'przetargi' => 'Przetargi',
        'oferty' => 'Oferty i wnioski',
        'kontrahenci' => 'Kontrahenci',
    ];

    public static function settings(?array $org): array
    {
        return $org ? json_in($org['settings_json'] ?? null) : [];
    }

    public static function theme(?array $org = null): string
    {
        $t = (string) (self::settings($org)['theme'] ?? '');
        if (!isset(self::THEMES[$t])) {
            $t = (string) Config::get('APP_THEME');
        }
        return isset(self::THEMES[$t]) ? $t : 'default';
    }

    /** @return string[] klucze włączonych modułów */
    public static function modules(?array $org): array
    {
        $m = self::settings($org)['modules'] ?? null;
        if (!is_array($m)) {
            return array_keys(self::MODULES);
        }
        return array_values(array_intersect(array_keys(self::MODULES), array_map('strval', $m)));
    }

    public static function has(?array $org, string $module): bool
    {
        return in_array($module, self::modules($org), true);
    }

    /** Plik logo wgrany do public/assets/brand/ zastępuje logo tekstowe. */
    public static function logo(): ?string
    {
        foreach (['logo.svg', 'logo.png', 'logo.webp'] as $f) {
            if (is_file(APP_ROOT . '/public/assets/brand/' . $f)) {
                return asset('/assets/brand/' . $f);
            }
        }
        return null;
    }

    public static function save(int $orgId, array $changes): void
    {
        $db = Db::get();
        $org = $db->one('SELECT settings_json FROM organizations WHERE id = ?', [$orgId]);
        $db->update('organizations', ['settings_json' => json_out($changes + self::settings($org))], 'id = ?', [$orgId]);
    }
}
