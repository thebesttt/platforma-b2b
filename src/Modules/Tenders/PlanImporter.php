<?php

declare(strict_types=1);

namespace App\Modules\Tenders;

use App\Core\Kv;

/**
 * Import planów zamówień: plany postępowań z BZP i wstępne ogłoszenia informacyjne z TED, potem dopasowanie do profili.
 *
 * Pierwszy import zaczyna od 1 stycznia bieżącego roku, bo większość planów pojawia się w styczniu i lutym (art. 23 Pzp:
 * 30 dni od przyjęcia budżetu). Treść każdego planu pobiera się osobnym zapytaniem, więc jedno uruchomienie ma limit planów,
 * a reszta czeka na kolejne. Plan, którego treści nie udało się pobrać, trafia na listę do ponowienia.
 */
final class PlanImporter
{
    private const RETRY_KEY = 'import:plans:retry';

    /**
     * @param int $maxPlans ile treści planów wolno pobrać w tym uruchomieniu
     * @return array{plans: int, items: int, ted: int, matches: int, waiting: bool, errors: string[]}
     */
    public static function run(?string $sinceUtc = null, int $maxPlans = 400, int $maxPages = 60): array
    {
        $res = ['plans' => 0, 'items' => 0, 'ted' => 0, 'matches' => 0, 'waiting' => false, 'errors' => []];
        $started = now_utc();
        $bzp = new BzpClient();
        $budget = $maxPlans;
        $failed = 0;

        // Najpierw plany, których treści nie udało się pobrać poprzednio.
        $retry = is_array($r = Kv::get(self::RETRY_KEY)) ? $r : [];
        $still = [];
        $tried = [];
        foreach ($retry as $plan) {
            if (!is_array($plan) || empty($plan['notice_number'])) {
                continue;
            }
            $tried[(string) $plan['notice_number']] = true;
            if ($budget <= 0) {
                $still[] = $plan;
                continue;
            }
            $budget--;
            $done = self::fetchAndSave($bzp, $plan, $res);
            if (!$done && (int) ($plan['tries'] ?? 0) < 5) {
                $still[] = array_merge($plan, ['tries' => (int) ($plan['tries'] ?? 0) + 1]);
            }
        }

        $from = $sinceUtc ?? (string) (Kv::get('import:plans:bzp:until') ?: gmdate('Y') . '-01-01 00:00:00');
        $next = null;
        try {
            $plans = $bzp->plansSince($from, $maxPages);
            usort($plans, static fn ($a, $b) => strcmp((string) $a['published_at'], (string) $b['published_at']));
            foreach ($plans as $plan) {
                if (isset($tried[$plan['notice_number']])) {
                    continue; // ponowione wyżej w tym samym uruchomieniu
                }
                $known = \App\Core\Db::get()->one('SELECT version, items_count FROM procurement_plans WHERE source = ? AND plan_key = ?', ['bzp', $plan['plan_key']]);
                if ($known && (int) $known['version'] >= (int) $plan['version'] && (int) $known['items_count'] > 0) {
                    continue;
                }
                if ($budget <= 0) {
                    // Limit tego uruchomienia: następne zaczyna od tego planu.
                    $next = gmdate('Y-m-d H:i:s', strtotime($plan['published_at'] . ' UTC') - 1);
                    $res['waiting'] = true;
                    break;
                }
                $budget--;
                if (!self::fetchAndSave($bzp, $plan, $res)) {
                    $failed++;
                    $still[] = $plan + ['tries' => 1];
                }
            }
            if ($bzp->failedRequests > 0) {
                $res['errors'][] = 'BZP: serwer nie odpowiedział na ' . $bzp->failedRequests . ' ' . plural($bzp->failedRequests, 'zapytanie', 'zapytania', 'zapytań') . ' przy liście planów - dokończymy przy następnym imporcie.';
            }
            if ($bzp->continueFrom !== null && ($next === null || $bzp->continueFrom < $next)) {
                $next = $bzp->continueFrom;
                $res['waiting'] = true;
            }
            Kv::set('import:plans:bzp:until', $next ?? gmdate('Y-m-d H:i:s', strtotime($started . ' UTC') - 600));
        } catch (\Throwable $e) {
            $res['errors'][] = 'BZP plany: ' . $e->getMessage();
        }
        if ($failed > 0) {
            $res['errors'][] = 'Nie udało się pobrać treści ' . $failed . ' ' . plural($failed, 'planu', 'planów', 'planów') . ' - spróbujemy ponownie przy następnym imporcie.';
        }
        Kv::set(self::RETRY_KEY, array_slice($still, 0, 200));

        $tedFrom = $sinceUtc ? substr($sinceUtc, 0, 10) : (string) (Kv::get('import:plans:ted:until') ?: gmdate('Y') . '-01-01');
        try {
            foreach ((new TedClient())->plansSince($tedFrom) as $p) {
                $saved = Plans::save($p['plan'], $p['items']);
                if ($saved['changed']) {
                    $res['ted']++;
                    $res['items'] += $saved['items'];
                }
            }
            Kv::set('import:plans:ted:until', gmdate('Y-m-d', time() - 86400));
        } catch (\Throwable $e) {
            $res['errors'][] = 'TED zapowiedzi: ' . $e->getMessage();
        }

        try {
            $res['matches'] = PlanMatcher::run();
        } catch (\Throwable $e) {
            $res['errors'][] = 'Dopasowanie planów do profili: ' . $e->getMessage();
        }
        Kv::set('import:plans:last', $res + ['at' => now_utc()]);
        return $res;
    }

    /** Pobiera treść planu i zapisuje pozycje. False = treści nie udało się pobrać. */
    private static function fetchAndSave(BzpClient $bzp, array $plan, array &$res): bool
    {
        $html = $bzp->planHtml((string) $plan['notice_number']);
        if ($html === null) {
            return false;
        }
        $parsed = PlanParser::parse($html);
        $plan['year'] = $parsed['year'] ?? $plan['year'] ?? null;
        $plan['version'] = max((int) $plan['version'], (int) ($parsed['version'] ?? 0));
        $plan['buyer_nip'] ??= $parsed['buyer_nip'];
        unset($plan['tries']);
        $saved = Plans::save($plan, $parsed['items']);
        if ($saved['changed']) {
            $res['plans']++;
            $res['items'] += $saved['items'];
        }
        if (BzpClient::$pauseUs > 0) {
            usleep(BzpClient::$pauseUs);
        }
        return true;
    }
}
