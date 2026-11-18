<?php

declare(strict_types=1);

namespace App\Modules\Audit;

use App\Core\Config;
use App\Core\Db;

if (!defined('PRESTACHECK_STANDALONE')) {
    define('PRESTACHECK_STANDALONE', 1);
}
require_once APP_ROOT . '/lib/prestacheck/class-prestacheck-scanner.php';

/**
 * Audyt strony: bezpieczeństwo, szybkość, SEO techniczne, analityka i RODO.
 * Silnik PrestaCheck (ten sam co w skanerze sklepów) – działa dla każdej strony, test PrestaShop jest pomijany.
 */
final class Audit
{
    public static function scanner(): \PrestaCheck_Scanner
    {
        $kb = json_decode((string) file_get_contents(APP_ROOT . '/lib/prestacheck/knowledge.json'), true) ?: [];
        return new \PrestaCheck_Scanner($kb, [
            'psi_key' => (string) Config::get('PSI_KEY'),
            'allow_private' => Config::isDev() && (string) Config::get('AUDIT_ALLOW_PRIVATE') === '1', // tylko testy lokalne
        ]);
    }

    /** @return array{ok: bool, id?: int, error?: string} */
    public static function run(int $orgId, int $userId, string $url, bool $pagespeed = false, ?string $label = null): array
    {
        $scanner = self::scanner();
        try {
            $result = $scanner->scan($url);
            if ($pagespeed) {
                $result = $scanner->pagespeed($result);
            }
        } catch (\PrestaCheck_Exception $e) {
            return ['ok' => false, 'error' => self::localize($e->getMessage())];
        } catch (\Throwable $e) {
            error_log('[audit] ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Nie udało się sprawdzić strony. Sprawdź adres i spróbuj ponownie.'];
        }
        if ($label !== null && $label !== '') {
            $result['label'] = $label;
        }
        $id = Db::get()->insert('site_audits', [
            'org_id' => $orgId,
            'url' => (string) ($result['final_url'] ?? $url),
            'host' => (string) $result['host'],
            'score' => (int) $result['score'],
            'result_json' => json_out($result),
            'created_by' => $userId,
            'created_at' => now_utc(),
        ]);
        return ['ok' => true, 'id' => $id];
    }

    public static function find(int $orgId, int $id): ?array
    {
        return Db::get()->one('SELECT * FROM site_audits WHERE org_id = ? AND id = ?', [$orgId, $id]);
    }

    public static function recent(int $orgId, int $limit = 30): array
    {
        return Db::get()->all('SELECT id, url, host, score, created_at, result_json FROM site_audits WHERE org_id = ? ORDER BY id DESC LIMIT ' . (int) $limit, [$orgId]);
    }

    /** Najnowszy audyt dla każdej domeny – do porównania z konkurencją. */
    public static function latestPerHost(int $orgId): array
    {
        $rows = Db::get()->all(
            'SELECT a.* FROM site_audits a JOIN (SELECT host, MAX(id) AS id FROM site_audits WHERE org_id = ? GROUP BY host) l ON l.id = a.id ORDER BY a.score DESC',
            [$orgId]
        );
        return array_map(static fn ($r) => ['row' => $r, 'view' => self::view($r)], $rows);
    }

    /** Wynik do wyświetlenia: problemy posortowane od najważniejszych, bez danych wewnętrznych. */
    public static function view(array $row): array
    {
        $result = json_in($row['result_json']);
        if (!$result) {
            return [];
        }
        $v = \PrestaCheck_Scanner::public_view($result, true, 999);
        foreach (['issues', 'passed'] as $list) {
            foreach ($v[$list] as &$f) {
                foreach (['title', 'detail', 'fix'] as $k) {
                    if (!empty($f[$k]) && is_string($f[$k])) {
                        $f[$k] = self::localize($f[$k]);
                    }
                }
            }
            unset($f);
        }
        $v['label'] = $result['label'] ?? null;
        // Kategoria „Wersja platformy” dotyczy tylko sklepów PrestaShop.
        if (empty($v['prestashop']['is'])) {
            unset($v['categories']['platform']);
        }
        return $v;
    }

    /**
     * Silnik powstał dla sklepów internetowych – w platformie B2B mówimy o „stronie”.
     * Najpierw całe zwroty (zgodność rodzaju), potem odmiana słowa „sklep”.
     */
    public static function localize(string $s): string
    {
        static $phrases = [
            'Sklep zablokował' => 'Strona zablokowała', 'Sklep odpowiedział' => 'Strona odpowiedziała', 'sklep był' => 'strona była',
            'Sklep wielojęzyczny' => 'Strona wielojęzyczna', 'wolny sklep' => 'wolna strona', 'szybszy sklep' => 'szybsza strona',
            'cały sklep' => 'całą stronę', 'całego sklepu' => 'całej strony', 'całym sklepie' => 'całej stronie', 'W sklepie' => 'Na stronie',
            'w sklepie' => 'na stronie', 'publicznym sklepem internetowym' => 'publiczną stroną internetową', 'adresem sklep' => 'adresem strona',
            'Takie sklepy są' => 'Takie strony są', 'Sklepy z' => 'Strony z', 'dwa sklepy' => 'dwie strony', 'tylko sklepy' => 'tylko strony',
            'Większość ruchu w e-commerce jest mobilna' => 'Większość ruchu w internecie jest mobilna', 'z pełnym śledzeniem e-commerce' => 'ze śledzeniem konwersji',
            'kupujących' => 'klientów', 'porzucają koszyk' => 'rezygnują z kontaktu', 'nowe produkty i kategorie' => 'nowe podstrony',
        ];
        static $words = [
            'sklepach' => 'stronach', 'sklepów' => 'stron', 'sklepami' => 'stronami', 'sklepem' => 'stroną', 'sklepie' => 'stronie',
            'sklepowi' => 'stronie', 'sklepu' => 'strony', 'sklepy' => 'strony', 'Sklep' => 'Strona', 'sklep' => 'strona',
        ];
        $s = strtr($s, $phrases);
        return (string) preg_replace_callback('/\b(' . implode('|', array_keys($words)) . ')\b/u', static fn ($m) => $words[$m[1]], $s);
    }

    public const SEVERITY = [
        'critical' => ['label' => 'Krytyczne', 'chip' => 'high'],
        'warning' => ['label' => 'Do poprawy', 'chip' => 'mid'],
        'info' => ['label' => 'Wskazówka', 'chip' => 'info'],
        'ok' => ['label' => 'W porządku', 'chip' => 'low'],
    ];
}
