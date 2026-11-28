<?php

declare(strict_types=1);

namespace App\Modules\Seo;

use App\Core\Brand;
use App\Core\Db;
use App\Core\Holidays;
use App\Modules\Notify\Notices;

/**
 * Raport miesięczny dla klienta agencji: jedna strona z wynikami witryny za miesiąc kalendarzowy.
 *
 * Liczby zapisujemy w chwili tworzenia raportu (data_json), więc raport udostępniony klientowi się nie zmienia, gdy
 * dane w panelu się postarzeją. Agencja dopisuje, co zrobiła w miesiącu i co planuje dalej: tej części nie pisze program.
 * Raport ma logo, kolor i stopkę agencji z ustawień firmy. Klient otwiera go linkiem bez logowania i może wydrukować do PDF.
 */
final class MonthlyReport
{
    public const MONTHS = [1 => 'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];
    public const MONTHS_GEN = [1 => 'stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
    public const MONTHS_LOC = [1 => 'w styczniu', 'w lutym', 'w marcu', 'w kwietniu', 'w maju', 'w czerwcu', 'w lipcu', 'w sierpniu', 'we wrześniu', 'w październiku', 'w listopadzie', 'w grudniu'];
    /** Od tego dnia miesiąca zadanie dzienne przygotowuje szkic za poprzedni miesiąc (Search Console podaje dane z 3-dniowym opóźnieniem). */
    public const AUTO_FROM_DAY = 4;
    /** Szkic powstaje sam, gdy Search Console ma co najmniej tyle dni z miesiąca. */
    public const AUTO_MIN_DAYS = 20;
    public const LOGO_MAX = 200_000;

    public static function label(string $month): string
    {
        [$y, $m] = array_map('intval', explode('-', $month));
        return self::MONTHS[$m] . ' ' . $y;
    }

    public static function previousMonth(?string $today = null): string
    {
        $today ??= Tracking::today();
        return gmdate('Y-m', strtotime(substr($today, 0, 7) . '-01 UTC') - 86400);
    }

    /** @return array{0: string, 1: string} pierwszy i ostatni dzień miesiąca */
    public static function range(string $month): array
    {
        $from = $month . '-01';
        return [$from, gmdate('Y-m-t', strtotime($from . ' UTC'))];
    }

    /** Wygląd raportu z ustawień firmy: logo (obraz jako data URI), kolor akcentu i stopka z kontaktem. */
    public static function brand(?array $org): array
    {
        $b = (array) (Brand::settings($org)['report'] ?? []);
        $color = (string) ($b['color'] ?? '');
        return [
            'name' => (string) ($org['name'] ?? ''),
            'logo' => is_string($b['logo'] ?? null) && preg_match('#^data:image/(png|jpeg|webp|svg\+xml);base64,[A-Za-z0-9+/=]+$#', $b['logo']) ? $b['logo'] : null,
            'color' => preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : '#2347C5',
            'footer' => (string) ($b['footer'] ?? ''),
        ];
    }

    /**
     * Zapis wyglądu. Logo: PNG, JPEG, WebP albo SVG do 200 KB. SVG pokazujemy tylko jako obraz (znacznik img), więc jego skrypty się nie wykonają.
     * @return string[] błędy
     */
    public static function saveBrand(int $orgId, array $in, ?array $file): array
    {
        $org = Db::get()->one('SELECT * FROM organizations WHERE id = ?', [$orgId]);
        $cur = (array) (Brand::settings($org)['report'] ?? []);
        $errors = [];
        $color = trim((string) ($in['color'] ?? ''));
        if ($color !== '' && !preg_match('/^#[0-9a-f]{6}$/i', $color)) {
            $errors[] = 'Kolor podaj w zapisie #RRGGBB, np. #D4A94A.';
        }
        $next = ['color' => $color !== '' ? strtoupper($color) : ($cur['color'] ?? null), 'footer' => mb_substr(trim((string) ($in['footer'] ?? '')), 0, 200), 'logo' => $cur['logo'] ?? null];
        if (!empty($in['remove_logo'])) {
            $next['logo'] = null;
        }
        if ($file && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $bytes = (string) file_get_contents((string) $file['tmp_name']);
            $type = self::imageType($bytes);
            if ($type === null) {
                $errors[] = 'Logo musi być plikiem PNG, JPEG, WebP albo SVG.';
            } elseif (strlen($bytes) > self::LOGO_MAX) {
                $errors[] = 'Logo może mieć najwyżej 200 KB.';
            } else {
                $next['logo'] = 'data:image/' . $type . ';base64,' . base64_encode($bytes);
            }
        }
        if (!$errors) {
            Brand::save($orgId, ['report' => $next]);
        }
        return $errors;
    }

    private static function imageType(string $b): ?string
    {
        return match (true) {
            str_starts_with($b, "\x89PNG\r\n\x1a\n") => 'png',
            str_starts_with($b, "\xFF\xD8\xFF") => 'jpeg',
            str_starts_with($b, 'RIFF') && substr($b, 8, 4) === 'WEBP' => 'webp',
            (bool) preg_match('/^\s*(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*<svg[\s>]/s', $b) => 'svg+xml',
            default => null,
        };
    }

    /**
     * Liczby do raportu za miesiąc. Wszystko z danych, które panel już ma: Search Console, odpowiedzi AI, Analytics,
     * Cloudflare, audyty i zapytania ze strony. Brak źródła to null, a raport pomija jego część.
     */
    public static function build(array $site, string $month): array
    {
        [$from, $to] = self::range($month);
        $siteId = (int) $site['id'];
        $orgId = (int) $site['org_id'];
        $daily = Stats::daily($siteId, 'gsc', 'date', 500);
        $sum = static function (array $rows, string $a, string $b): ?array {
            $in = array_values(array_filter($rows, static fn (array $r): bool => $r['day'] >= $a && $r['day'] <= $b));
            if (!$in) {
                return null;
            }
            $clicks = (int) array_sum(array_column($in, 'clicks'));
            $impr = (int) array_sum(array_column($in, 'impressions'));
            $wpos = 0.0;
            $wimp = 0;
            foreach ($in as $r) {
                if ($r['position'] !== null && $r['impressions'] > 0) {
                    $wpos += (float) $r['position'] * $r['impressions'];
                    $wimp += $r['impressions'];
                }
            }
            return ['clicks' => $clicks, 'impressions' => $impr, 'ctr' => $impr > 0 ? $clicks / $impr : null, 'position' => $wimp > 0 ? round($wpos / $wimp, 1) : null,
                'days' => count($in), 'of' => Stats::days($a, $b)];
        };
        $gsc = $sum($daily, $from, $to);
        if ($gsc) {
            [$pf, $pt] = self::range(gmdate('Y-m', strtotime($from . ' UTC') - 86400));
            $yf = (string) ((int) substr($from, 0, 4) - 1) . substr($from, 4);
            $yt = gmdate('Y-m-t', strtotime($yf . ' UTC'));
            $prev = $sum($daily, $pf, $pt);
            $yoy = $sum($daily, $yf, $yt);
            // Porównanie tylko z prawie pełnym miesiącem: inaczej spadek byłby skutkiem braku danych.
            $gsc['prev'] = $prev && $prev['days'] >= 0.9 * $prev['of'] ? $prev : null;
            $gsc['yoy'] = $yoy && $yoy['days'] >= 0.9 * $yoy['of'] ? $yoy : null;
            $gsc['daily'] = array_values(array_map(static fn (array $r): array => [(string) $r['day'], (int) $r['clicks']], array_filter($daily, static fn (array $r): bool => $r['day'] >= $from && $r['day'] <= $to)));
        }
        return [
            'month' => $month, 'label' => self::label($month), 'from' => $from, 'to' => $to, 'host' => (string) $site['host'], 'built_at' => now_utc(),
            'gsc' => $gsc,
            'holidays' => Holidays::between($from, $to),
            'queries' => self::top($siteId, 'query', 10),
            'pages' => self::top($siteId, 'page', 5),
            'ai' => self::ai($site, $from, $to),
            'tracking' => self::tracking($site, $from, $to),
            'audits' => self::audits($orgId, (string) $site['host']),
            'leads' => self::leads($orgId, (string) $site['host'], $from, $to),
        ];
    }

    /** Najważniejsze zapytania albo strony z ostatniego okresu w panelu (28 dni), ze zmianą wobec poprzedniego okresu. */
    private static function top(int $siteId, string $dim, int $limit): ?array
    {
        [$cur, $prev] = Stats::pair($siteId, 'gsc', $dim);
        if (!$cur) {
            return null;
        }
        $was = [];
        foreach (Stats::rows($siteId, 'gsc', $dim, $prev, 'clicks DESC, impressions DESC') as $r) {
            $was[$r['k']] = $r;
        }
        $rows = [];
        foreach (array_slice(Stats::rows($siteId, 'gsc', $dim, $cur, 'clicks DESC, impressions DESC'), 0, $limit) as $r) {
            $rows[] = ['k' => (string) $r['k'], 'clicks' => (int) $r['clicks'], 'impressions' => (int) $r['impressions'], 'position' => $r['position'],
                'clicks_prev' => isset($was[$r['k']]) ? (int) $was[$r['k']]['clicks'] : null, 'position_prev' => $was[$r['k']]['position'] ?? null];
        }
        return ['start' => $cur['start'], 'end' => $cur['end'], 'compared' => $prev !== null, 'rows' => $rows];
    }

    private static function ai(array $site, string $from, string $to): array
    {
        $siteId = (int) $site['id'];
        $inMonth = static fn (array $rows): array => array_values(array_filter($rows, static fn (array $r): bool => $r['day'] >= $from && $r['day'] <= $to));
        $overviews = $inMonth(Stats::daily($siteId, 'gsc_ai', 'date', 500));
        $bots = $inMonth(Stats::daily($siteId, 'cf_bot', 'bot', 500));
        [$cur, $prev] = Stats::pair($siteId, 'ga4_ai', 'source');
        $sessions = static fn (?array $p): ?int => $p ? (int) array_sum(array_column(Stats::rows($siteId, 'ga4_ai', 'source', $p, 'value DESC'), 'value')) : null;
        return [
            'overviews' => $overviews ? ['impressions' => (int) array_sum(array_column($overviews, 'impressions')), 'days' => count($overviews)] : null,
            'assistants' => $cur ? ['sessions' => $sessions($cur), 'prev' => $sessions($prev), 'start' => $cur['start'], 'end' => $cur['end'],
                'top' => array_slice(array_map(static fn (array $r): array => [(string) $r['k'], (int) $r['value']], Stats::rows($siteId, 'ga4_ai', 'source', $cur, 'value DESC')), 0, 4)] : null,
            'bots' => $bots ? ['requests' => (int) array_sum(array_column($bots, 'impressions')), 'days' => count(array_unique(array_column($bots, 'day')))] : null,
        ];
    }

    private static function tracking(array $site, string $from, string $to): ?array
    {
        $state = Tracking::state($site);
        $incidents = Db::get()->all("SELECT title, level, created_at FROM notices WHERE site_id = ? AND kind = 'tracking' AND created_at >= ? AND created_at < ? ORDER BY id",
            [(int) $site['id'], $from . ' 00:00:00', gmdate('Y-m-d', strtotime($to . ' UTC') + 86400) . ' 00:00:00']);
        if (!$state && !$incidents) {
            return null;
        }
        return ['status' => (string) ($state['status'] ?? ''), 'incidents' => array_map(static fn (array $n): array => ['title' => (string) $n['title'], 'level' => (string) $n['level'], 'at' => (string) $n['created_at']], $incidents)];
    }

    /** Audyt AI stron tej domeny (ostatni wynik i poprzedni) oraz ostatni audyt techniczny. */
    private static function audits(int $orgId, string $host): array
    {
        $db = Db::get();
        $hosts = [$host, 'www.' . $host];
        $content = [];
        foreach ($db->all("SELECT t.id, t.url FROM content_targets t WHERE t.org_id = ? AND t.host IN (?, ?) ORDER BY t.id LIMIT 30", [$orgId, ...$hosts]) as $t) {
            $runs = $db->all("SELECT score, finished_at FROM content_audits WHERE target_id = ? AND status = 'done' AND score IS NOT NULL ORDER BY id DESC LIMIT 2", [(int) $t['id']]);
            if ($runs) {
                $content[] = ['url' => (string) $t['url'], 'score' => (int) $runs[0]['score'], 'prev' => isset($runs[1]) ? (int) $runs[1]['score'] : null, 'at' => (string) $runs[0]['finished_at']];
            }
        }
        usort($content, static fn (array $a, array $b): int => $a['score'] <=> $b['score']);
        $tech = $db->all('SELECT url, score, created_at FROM site_audits WHERE org_id = ? AND host IN (?, ?) ORDER BY id DESC LIMIT 2', [$orgId, ...$hosts]);
        return [
            'content' => array_slice($content, 0, 8),
            'tech' => $tech ? ['url' => (string) $tech[0]['url'], 'score' => (int) $tech[0]['score'], 'prev' => isset($tech[1]) ? (int) $tech[1]['score'] : null, 'at' => (string) $tech[0]['created_at']] : null,
        ];
    }

    /** Zapytania z widżetu na tej domenie w miesiącu. Null, gdy widżet nie działa na tej stronie. */
    private static function leads(int $orgId, string $host, string $from, string $to): ?array
    {
        $db = Db::get();
        $widgets = [];
        foreach ($db->all('SELECT id, origins FROM inbound_widgets WHERE org_id = ?', [$orgId]) as $w) {
            foreach (preg_split('/\s+/', (string) $w['origins']) ?: [] as $o) {
                $h = Sites::host($o);
                if ($h !== null && ($h === $host || $h === 'www.' . $host)) {
                    $widgets[] = (int) $w['id'];
                }
            }
        }
        if (!$widgets) {
            return null;
        }
        $marks = implode(', ', array_fill(0, count($widgets), '?'));
        $end = gmdate('Y-m-d', strtotime($to . ' UTC') + 86400) . ' 00:00:00';
        $count = static fn (string $a, string $b): int => (int) $db->val("SELECT COUNT(*) FROM inbound_leads WHERE org_id = ? AND widget_id IN ($marks) AND created_at >= ? AND created_at < ?", [$orgId, ...$widgets, $a, $b]);
        [$pf] = self::range(gmdate('Y-m', strtotime($from . ' UTC') - 86400));
        return ['count' => $count($from . ' 00:00:00', $end), 'prev' => $count($pf . ' 00:00:00', $from . ' 00:00:00'),
            'hot' => (int) $db->val("SELECT COUNT(*) FROM inbound_leads WHERE org_id = ? AND widget_id IN ($marks) AND created_at >= ? AND created_at < ? AND level = 'hot'", [$orgId, ...$widgets, $from . ' 00:00:00', $end])];
    }

    /** @return array{ok: bool, id?: int, error?: string} */
    public static function create(array $site, string $month, ?int $userId): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) || $month > substr(Tracking::today(), 0, 7)) {
            return ['ok' => false, 'error' => 'Wybierz miesiąc, który już się zaczął.'];
        }
        $db = Db::get();
        if ($id = $db->val('SELECT id FROM seo_reports WHERE site_id = ? AND month = ?', [(int) $site['id'], $month])) {
            return ['ok' => true, 'id' => (int) $id];
        }
        $id = $db->insert('seo_reports', [
            'org_id' => (int) $site['org_id'], 'site_id' => (int) $site['id'], 'month' => $month, 'data_json' => json_out(self::build($site, $month)),
            'created_by' => $userId, 'created_at' => now_utc(), 'updated_at' => now_utc(),
        ]);
        return ['ok' => true, 'id' => $id];
    }

    public static function find(int $orgId, int $siteId, int $id): ?array
    {
        return Db::get()->one('SELECT * FROM seo_reports WHERE org_id = ? AND site_id = ? AND id = ?', [$orgId, $siteId, $id]);
    }

    /** @return array<int, array<string, mixed>> */
    public static function forSite(int $orgId, int $siteId): array
    {
        return Db::get()->all('SELECT id, month, token, shared_at, created_at, updated_at, created_by FROM seo_reports WHERE org_id = ? AND site_id = ? ORDER BY month DESC', [$orgId, $siteId]);
    }

    public static function byToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
            return null;
        }
        return Db::get()->one('SELECT * FROM seo_reports WHERE token = ?', [$token]);
    }

    public static function share(array $report): string
    {
        $token = (string) ($report['token'] ?? '');
        if ($token === '') {
            $token = bin2hex(random_bytes(20));
            Db::get()->update('seo_reports', ['token' => $token, 'shared_at' => now_utc(), 'updated_at' => now_utc()], 'id = ?', [(int) $report['id']]);
        }
        return $token;
    }

    /**
     * Zadanie dzienne: od 4. dnia miesiąca szkic za poprzedni miesiąc dla witryn z danymi dziennymi z Search Console.
     * Agencja dostaje powiadomienie, dopisuje notatki i sama udostępnia link. Bez notatek raport nie wychodzi do klienta.
     * @return array{created: int}
     */
    public static function daily(?string $today = null): array
    {
        $today ??= Tracking::today();
        $out = ['created' => 0];
        if ((int) substr($today, 8, 2) < self::AUTO_FROM_DAY) {
            return $out;
        }
        $month = self::previousMonth($today);
        [$from, $to] = self::range($month);
        $db = Db::get();
        $rows = $db->all(
            "SELECT s.* FROM seo_sites s WHERE NOT EXISTS (SELECT 1 FROM seo_reports r WHERE r.site_id = s.id AND r.month = ?)
               AND (SELECT COUNT(*) FROM seo_stats x WHERE x.site_id = s.id AND x.source = 'gsc' AND x.dim = 'date' AND x.period_start >= ? AND x.period_start <= ?) >= ?
             ORDER BY s.id LIMIT 100",
            [$month, $from, $to, self::AUTO_MIN_DAYS]
        );
        foreach ($rows as $site) {
            $org = $db->one('SELECT * FROM organizations WHERE id = ?', [(int) $site['org_id']]);
            if (!$org || !Brand::has($org, 'audyt')) {
                continue;
            }
            $res = self::create($site, $month, null);
            if ($res['ok']) {
                $out['created']++;
                Notices::add((int) $site['org_id'], 'report', 'info', 'Raport za ' . self::label($month) . ' gotowy do przejrzenia: ' . $site['host'],
                    'Dopisz, co zrobiliście w tym miesiącu i co planujecie dalej, a potem udostępnij klientowi link.',
                    '/audyt/widocznosc/' . (int) $site['id'] . '/raporty/' . (int) $res['id'], (int) $site['id']);
            }
        }
        return $out;
    }
}
