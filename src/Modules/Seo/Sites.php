<?php

declare(strict_types=1);

namespace App\Modules\Seo;

use App\Core\Db;

/**
 * Witryny, których widoczność firma śledzi: własna strona albo strony klientów agencji.
 * Przy witrynie trzymamy nazwy marki (do podziału zapytań), identyfikatory źródeł danych i listę konkurentów.
 */
final class Sites
{
    public const LIMIT = 20;

    public const RIVALS_LIMIT = 5;

    /** Domena bez protokołu, „www.” i ścieżki. Null, gdy to nie jest domena. */
    public static function host(string $input): ?string
    {
        $h = mb_strtolower(trim($input));
        $h = (string) preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $h);
        $h = (string) preg_replace('#[/?\#].*$#', '', $h);
        $h = (string) preg_replace('#:\d+$#', '', $h);
        $h = (string) preg_replace('#^www\.#', '', $h);
        if (strlen($h) > 120 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $h)) {
            return null;
        }
        return $h;
    }

    /** @return array<int, array<string, mixed>> */
    public static function all(int $orgId): array
    {
        return Db::get()->all('SELECT * FROM seo_sites WHERE org_id = ? ORDER BY host', [$orgId]);
    }

    public static function find(int $orgId, int $id): ?array
    {
        return $id > 0 ? Db::get()->one('SELECT * FROM seo_sites WHERE id = ? AND org_id = ?', [$id, $orgId]) : null;
    }

    /** @return array{ok: bool, id?: int, error?: string} */
    public static function add(int $orgId, string $input, string $brand = ''): array
    {
        $host = self::host($input);
        if ($host === null) {
            return ['ok' => false, 'error' => 'Podaj adres witryny, np. twojafirma.pl.'];
        }
        $db = Db::get();
        $existing = $db->val('SELECT id FROM seo_sites WHERE org_id = ? AND host = ?', [$orgId, $host]);
        if ($existing) {
            return ['ok' => true, 'id' => (int) $existing];
        }
        $count = (int) $db->val('SELECT COUNT(*) FROM seo_sites WHERE org_id = ?', [$orgId]);
        $org = $db->one('SELECT id, plan FROM organizations WHERE id = ?', [$orgId]);
        $planLimit = \App\Core\Plans::limit($org, 'sites');
        if ($planLimit !== null && $count >= $planLimit) {
            return ['ok' => false, 'error' => 'Pakiet ' . \App\Core\Plans::name($org) . ' obejmuje ' . $planLimit . ' ' . plural($planLimit, 'witrynę', 'witryny', 'witryn') . ' w zakładce Widoczność. Usuń którąś albo zmień pakiet.'];
        }
        if ($count >= self::LIMIT) {
            return ['ok' => false, 'error' => 'Możesz śledzić najwyżej ' . self::LIMIT . ' witryn. Usuń którąś, żeby dodać nową.'];
        }
        $id = $db->insert('seo_sites', [
            'org_id' => $orgId, 'host' => $host, 'brand_terms' => self::cleanTerms($brand), 'created_at' => now_utc(),
        ]);
        return ['ok' => true, 'id' => $id];
    }

    public static function delete(int $orgId, int $id): void
    {
        $db = Db::get();
        $db->transaction(static function (Db $db) use ($orgId, $id): void {
            $db->run('DELETE FROM seo_stats WHERE site_id = ? AND org_id = ?', [$id, $orgId]);
            $db->run('DELETE FROM seo_rival_pages WHERE site_id = ? AND org_id = ?', [$id, $orgId]);
            $db->run('DELETE FROM seo_sites WHERE id = ? AND org_id = ?', [$id, $orgId]);
        });
    }

    /**
     * Ustawienia witryny: nazwy marki i identyfikatory źródeł. Zwraca listę błędów (pusta = zapisane).
     * @return string[]
     */
    public static function saveSettings(array $site, array $in): array
    {
        $errors = [];
        $data = ['brand_terms' => self::cleanTerms((string) ($in['brand_terms'] ?? ''))];
        $gsc = trim((string) ($in['gsc_property'] ?? ''));
        if ($gsc !== '' && !preg_match('#^(sc-domain:[a-z0-9.\-]{3,120}|https?://[a-z0-9.\-]{3,120}(/[^\s]{0,200})?)$#i', $gsc)) {
            $errors[] = 'Usługa Search Console ma postać sc-domain:firma.pl albo https://firma.pl/.';
        } else {
            $data['gsc_property'] = $gsc;
        }
        $ga4 = preg_replace('/^properties\//', '', trim((string) ($in['ga4_property'] ?? '')));
        if ($ga4 !== '' && !preg_match('/^\d{5,15}$/', (string) $ga4)) {
            $errors[] = 'Identyfikator usługi GA4 to sama liczba, np. 123456789 (nie identyfikator pomiaru G-...).';
        } else {
            $data['ga4_property'] = (string) $ga4;
        }
        $zone = mb_strtolower(trim((string) ($in['cf_zone'] ?? '')));
        if ($zone !== '' && !preg_match('/^[a-f0-9]{32}$/', $zone)) {
            $errors[] = 'Identyfikator strefy Cloudflare ma 32 znaki (cyfry i litery a-f).';
        } else {
            $data['cf_zone'] = $zone;
        }
        Db::get()->update('seo_sites', $data, 'id = ? AND org_id = ?', [(int) $site['id'], (int) $site['org_id']]);
        return $errors;
    }

    /** Nazwy marki: z ustawień, a gdy ich nie ma, z pierwszego członu domeny (firma-xyz.pl → „firma-xyz”, „firma xyz”, „firmaxyz”). */
    public static function brandTerms(array $site): array
    {
        $terms = array_values(array_filter(array_map('trim', explode(',', (string) ($site['brand_terms'] ?? '')))));
        if (!$terms) {
            $label = explode('.', (string) $site['host'])[0];
            $terms = [$label];
            if (str_contains($label, '-')) {
                $terms[] = str_replace('-', ' ', $label);
            }
        }
        $out = [];
        foreach ($terms as $t) {
            $n = self::fold($t);
            if (mb_strlen($n) >= 3) {
                $out[$n] = true;
                $out[str_replace([' ', '-'], '', $n)] = true;
            }
        }
        return array_keys($out);
    }

    /** Czy zapytanie zawiera nazwę marki (bez wielkości liter, polskich znaków i odstępów w nazwie). */
    public static function isBrand(string $query, array $terms): bool
    {
        $q = self::fold($query);
        $tight = str_replace([' ', '-'], '', $q);
        foreach ($terms as $t) {
            if ($t !== '' && (str_contains($q, $t) || str_contains($tight, $t))) {
                return true;
            }
        }
        return false;
    }

    /** @return string[] domeny konkurentów */
    public static function rivals(array $site): array
    {
        return array_values(array_filter(array_map('strval', json_in((string) ($site['rivals_json'] ?? '')))));
    }

    /**
     * Zapisuje listę konkurentów z pola tekstowego (po jednej domenie w wierszu albo po przecinku).
     * @return array{saved: string[], skipped: string[]}
     */
    public static function saveRivals(array $site, string $text): array
    {
        $saved = [];
        $skipped = [];
        foreach (preg_split('/[\s,;]+/u', $text) ?: [] as $raw) {
            if (trim($raw) === '') {
                continue;
            }
            $h = self::host($raw);
            if ($h === null || $h === $site['host'] || count($saved) >= self::RIVALS_LIMIT) {
                $skipped[] = mb_substr(trim($raw), 0, 80);
                continue;
            }
            $saved[$h] = $h;
        }
        $saved = array_values($saved);
        $db = Db::get();
        $db->update('seo_sites', ['rivals_json' => json_out($saved)], 'id = ? AND org_id = ?', [(int) $site['id'], (int) $site['org_id']]);
        // Strony konkurenta zdjętego z listy nie są już potrzebne.
        $keep = array_merge($saved, [(string) $site['host']]);
        $db->run(
            'DELETE FROM seo_rival_pages WHERE site_id = ? AND host NOT IN (' . implode(', ', array_fill(0, count($keep), '?')) . ')',
            [(int) $site['id'], ...$keep]
        );
        return ['saved' => $saved, 'skipped' => $skipped];
    }

    /** Kiedy i z jakim skutkiem pobrano dane ze źródła (google, ga4, cloudflare, rivals). */
    public static function markSync(array $site, string $source, bool $ok, string $note = ''): void
    {
        // Stan czytamy z bazy, a nie z przekazanego wiersza: w jednym zapytaniu zapisuje się kilka źródeł po kolei.
        $db = Db::get();
        $all = json_in((string) $db->val('SELECT synced_json FROM seo_sites WHERE id = ?', [(int) $site['id']]));
        $all[$source] = ['at' => now_utc(), 'ok' => $ok, 'note' => mb_substr($note, 0, 300)];
        // Stan map witryn domen zdjętych z listy konkurentów nie jest już potrzebny.
        $hosts = array_merge([(string) $site['host']], json_in((string) $db->val('SELECT rivals_json FROM seo_sites WHERE id = ?', [(int) $site['id']])));
        foreach (array_keys($all) as $key) {
            if (str_starts_with((string) $key, 'map:') && !in_array(substr((string) $key, 4), $hosts, true)) {
                unset($all[$key]);
            }
        }
        $db->update('seo_sites', ['synced_json' => json_out($all)], 'id = ?', [(int) $site['id']]);
    }

    /** Po odłączeniu źródła: usuwa jego stan ze wszystkich witryn firmy, żeby panel nie pokazywał połączenia, którego już nie ma. */
    public static function clearSync(int $orgId, array $sources): void
    {
        $db = Db::get();
        foreach ($db->all('SELECT id, synced_json FROM seo_sites WHERE org_id = ?', [$orgId]) as $row) {
            $all = array_diff_key(json_in((string) $row['synced_json']), array_flip($sources));
            $db->update('seo_sites', ['synced_json' => json_out($all)], 'id = ?', [(int) $row['id']]);
        }
    }

    public static function lastSync(array $site, string $source): ?array
    {
        $all = json_in((string) ($site['synced_json'] ?? ''));
        return is_array($all[$source] ?? null) ? $all[$source] : null;
    }

    /** Tekst do porównań: małe litery, bez polskich znaków. */
    public static function fold(string $s): string
    {
        $s = mb_strtolower(trim($s));
        return strtr($s, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
    }

    private static function cleanTerms(string $s): string
    {
        $terms = [];
        foreach (explode(',', $s) as $t) {
            $t = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $t)), 0, 40);
            if (mb_strlen($t) >= 3) {
                $terms[mb_strtolower($t)] = $t;
            }
        }
        return implode(', ', array_slice(array_values($terms), 0, 8));
    }
}
