<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\AuditLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Kv;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Audit\Audit;
use App\Modules\Seo\AiSources;
use App\Modules\Seo\CloudflareBots;
use App\Modules\Seo\Google;
use App\Modules\Seo\GscFile;
use App\Modules\Seo\Insights;
use App\Modules\Seo\Rivals;
use App\Modules\Seo\Sites;
use App\Modules\Seo\Stats;
use App\Modules\Seo\Sync;

/**
 * Widoczność witryny: dane z Search Console, sygnały z odpowiedzi AI i konkurencja.
 *
 * Start ma być prosty: użytkownik wpisuje adres witryny, a potem albo wgrywa paczkę z przycisku „Eksportuj” w Search Console,
 * albo dodaje jeden adres e-mail jako użytkownika i klika „Połącz”. Identyfikatory usług panel wykrywa sam.
 */
final class SeoController extends Controller
{
    /** Pobrań z zewnętrznych usług na firmę w ciągu godziny. */
    private const HOURLY = 30;

    public function index(Request $r): Response
    {
        $sites = Sites::all($this->orgId());
        if (count($sites) === 1 && $r->str('lista') !== '1') {
            return $this->redirect('/audyt/widocznosc/' . (int) $sites[0]['id']);
        }
        $own = Sites::host((string) (Auth::org()['website'] ?? '')) ?? '';
        return $this->view('seo/index', ['sites' => $sites, 'own' => $own, 'title' => 'Widoczność']);
    }

    public function add(Request $r): Response
    {
        $res = Sites::add($this->orgId(), $r->str('host'));
        if (!$res['ok']) {
            return $this->redirect('/audyt/widocznosc?lista=1', $res['error'], 'error');
        }
        AuditLog::add('seo.site_added', 'seo_site', $res['id'], [], $r->ip());
        return $this->redirect('/audyt/widocznosc/' . $res['id']);
    }

    public function google(Request $r): Response
    {
        if (!($site = $this->site($r))) {
            return $this->notFound('Nie ma takiej witryny');
        }
        $data = Insights::google($site);
        $daily = Stats::daily((int) $site['id'], 'gsc', 'date');
        return $this->view('seo/google', $this->common($site, 'google') + [
            'd' => $data, 'daily' => $daily,
            'weeklyClicks' => Insights::weekly($daily, 'clicks'), 'weeklyImpressions' => Insights::weekly($daily, 'impressions'),
            'title' => 'Widoczność w Google: ' . $site['host'],
        ]);
    }

    public function ai(Request $r): Response
    {
        if (!($site = $this->site($r))) {
            return $this->notFound('Nie ma takiej witryny');
        }
        $ai = AiSources::overview($site);
        return $this->view('seo/ai', $this->common($site, 'ai') + [
            'ai' => $ai, 'gsc' => Insights::aiFromGsc($site), 'bots' => CloudflareBots::summary($site),
            'aiWeekly' => Insights::weekly($ai['google']['daily'], 'impressions'),
            'title' => 'Widoczność w AI: ' . $site['host'],
        ]);
    }

    public function rivals(Request $r): Response
    {
        if (!($site = $this->site($r))) {
            return $this->notFound('Nie ma takiej witryny');
        }
        // Podpowiedź: domeny, które firma sprawdziła już audytem jako konkurentów.
        $suggest = [];
        $have = array_merge(Sites::rivals($site), [(string) $site['host']]);
        foreach (Audit::latestPerHost($this->orgId()) as $a) {
            $host = Sites::host((string) ($a['row']['host'] ?? ''));
            if ($host !== null && !in_array($host, $have, true) && (($a['view']['label'] ?? '') === 'Konkurent')) {
                $suggest[$host] = $host;
            }
        }
        $scores = [];
        foreach (Audit::latestPerHost($this->orgId()) as $a) {
            $host = Sites::host((string) ($a['row']['host'] ?? ''));
            if ($host !== null) {
                $scores[$host] = ['id' => (int) $a['row']['id'], 'score' => (int) ($a['row']['score'] ?? 0)];
            }
        }
        return $this->view('seo/rivals', $this->common($site, 'konkurencja') + [
            's' => Rivals::summary($site), 'rivals' => Sites::rivals($site), 'suggest' => array_slice(array_values($suggest), 0, Sites::RIVALS_LIMIT),
            'scores' => $scores, 'title' => 'Konkurencja: ' . $site['host'],
        ]);
    }

    public function data(Request $r): Response
    {
        if (!($site = $this->site($r))) {
            return $this->notFound('Nie ma takiej witryny');
        }
        $id = (int) $site['id'];
        $periods = [];
        foreach (['gsc' => ['total', 'query'], 'gsc_ai' => ['total', 'page'], 'bing_ai' => ['total', 'page', 'query'], 'ga4_ai' => ['source']] as $source => $dims) {
            foreach ($dims as $dim) {
                if ($p = Stats::periods($id, $source, $dim)) {
                    $periods[$source] = $p;
                    break;
                }
            }
        }
        return $this->view('seo/data', $this->common($site, 'dane') + [
            'periods' => $periods, 'title' => 'Dane i połączenia: ' . $site['host'],
        ]);
    }

    /** Pliki z eksportu Search Console: jeden albo kilka naraz, wgrane tak, jak zostały pobrane. */
    public function import(Request $r): Response
    {
        if (!($site = $this->site($r))) {
            return $this->notFound('Nie ma takiej witryny');
        }
        $base = '/audyt/widocznosc/' . (int) $site['id'];
        $files = TenderController::normalizeFiles($r->files['files'] ?? null);
        if (!$files) {
            return $this->redirect($base . '/dane', 'Wybierz plik z eksportu Search Console (paczka ZIP albo CSV).', 'error');
        }
        $from = form_date($r->str('od'));
        $to = form_date($r->str('do'));
        $done = [];
        $errors = [];
        $target = '';
        foreach (array_slice($files, 0, 6) as $f) {
            $name = mb_substr((string) $f['name'], 0, 80);
            if ((int) $f['error'] !== UPLOAD_ERR_OK || (PHP_SAPI !== 'cli' && !is_uploaded_file((string) $f['tmp_name']))) {
                $errors[] = $name . ': plik nie dotarł w całości. Spróbuj ponownie.';
                continue;
            }
            $read = GscFile::read((string) $f['tmp_name'], (string) $f['name']);
            $res = $read['ok'] ? GscFile::store($site, $read['sets'], $from, $to) : $read;
            if (!$res['ok']) {
                $errors[] = $name . ': ' . $res['error'];
                continue;
            }
            $parts = [];
            foreach ($res['saved'] as $dim => $n) {
                $parts[] = $n . ' ' . (GscFile::DIMS[$dim] ?? $dim);
            }
            $done[] = ($res['report'] === 'ai' ? 'Funkcje generatywnej AI' : 'Skuteczność w wyszukiwarce') . ', ' . Sync::range($res['start'], $res['end']) . ': ' . implode(', ', $parts) . '.';
            $target = $target ?: ($res['report'] === 'ai' ? '/ai' : '');
            AuditLog::add('seo.import', 'seo_site', (int) $site['id'], ['report' => $res['report'], 'start' => $res['start'], 'end' => $res['end']], $r->ip());
        }
        if ($errors) {
            \App\Core\Session::flash('error', implode(' ', $errors));
        }
        if (!$done) {
            return $this->redirect($base . '/dane');
        }
        return $this->redirect($base . $target, 'Wczytane: ' . implode(' ', $done));
    }

    /** Dane wklejone z raportów bez API (Google: funkcje AI, Bing: AI Performance) albo z Google Analytics. */
    public function paste(Request $r): Response
    {
        if (!($site = $this->site($r))) {
            return $this->notFound('Nie ma takiej witryny');
        }
        $base = '/audyt/widocznosc/' . (int) $site['id'];
        $total = trim($r->str('suma')) === '' ? null : GscFile::int($r->str('suma'));
        $res = AiSources::import($site, $r->str('rodzaj'), mb_substr($r->str('dane'), 0, 200_000), (string) form_date($r->str('od')), (string) form_date($r->str('do')), $total);
        if (!$res['ok']) {
            return $this->redirect($base . '/dane#wklej', $res['error'], 'error');
        }
        AuditLog::add('seo.paste', 'seo_site', (int) $site['id'], ['kind' => $r->str('rodzaj')], $r->ip());
        return $this->redirect($base . '/ai', 'Zapisane: ' . $res['saved'] . ' ' . plural((int) $res['saved'], 'wiersz', 'wiersze', 'wierszy')
            . ($res['skipped'] ? '. Pominięte źródła spoza listy asystentów AI: ' . $res['skipped'] : '') . '.');
    }

    public function settings(Request $r): Response
    {
        if (!($site = $this->site($r))) {
            return $this->notFound('Nie ma takiej witryny');
        }
        $errors = Sites::saveSettings($site, ['brand_terms' => $r->str('brand_terms'), 'gsc_property' => $r->str('gsc_property'),
            'ga4_property' => $r->str('ga4_property'), 'cf_zone' => $r->str('cf_zone')]);
        $to = '/audyt/widocznosc/' . (int) $site['id'] . '/dane#ustawienia';
        return $errors ? $this->redirect($to, implode(' ', $errors), 'error') : $this->redirect($to, 'Ustawienia zapisane.');
    }

    /** Połączenie i pobranie danych: Google (Search Console i Analytics) albo Cloudflare. */
    public function sync(Request $r): Response
    {
        if (!($site = $this->site($r))) {
            return $this->notFound('Nie ma takiej witryny');
        }
        $base = '/audyt/widocznosc/' . (int) $site['id'];
        $source = $r->str('zrodlo');
        if (!in_array($source, ['google', 'cloudflare'], true)) {
            return $this->redirect($base . '/dane');
        }
        if (Kv::hit('seo-sync:' . $this->orgId() . ':' . gmdate('YmdH'), 3600) > self::HOURLY) {
            return $this->redirect($base . '/dane', 'Za dużo pobrań w tej godzinie. Spróbuj później.', 'error');
        }
        $res = $source === 'google' ? Sync::google($site) : Sync::cloudflare($site);
        AuditLog::add('seo.sync', 'seo_site', (int) $site['id'], ['source' => $source, 'ok' => $res['ok']], $r->ip());
        if (!$res['ok']) {
            if ($res['messages']) {
                \App\Core\Session::flash('success', implode(' ', $res['messages']));
            }
            return $this->redirect($base . '/dane', implode(' ', $res['errors']), 'error');
        }
        return $this->redirect($base . ($source === 'google' ? '' : '/ai'), implode(' ', $res['messages']));
    }

    /** Lista konkurentów i pobranie map witryn (własnej i konkurentów). */
    public function saveRivals(Request $r): Response
    {
        if (!($site = $this->site($r))) {
            return $this->notFound('Nie ma takiej witryny');
        }
        $base = '/audyt/widocznosc/' . (int) $site['id'] . '/konkurencja';
        if (Kv::hit('seo-sync:' . $this->orgId() . ':' . gmdate('YmdH'), 3600) > self::HOURLY) {
            return $this->redirect($base, 'Za dużo pobrań w tej godzinie. Spróbuj później.', 'error');
        }
        $skipped = [];
        if ($r->input('rivals') !== null) {
            $saved = Sites::saveRivals($site, mb_substr($r->str('rivals'), 0, 2000));
            $skipped = $saved['skipped'];
            $site = Sites::find($this->orgId(), (int) $site['id']) ?? $site;
        }
        $ok = [];
        $errors = [];
        foreach (Rivals::refreshAll($site, true) as $host => $res) {
            if ($res['ok']) {
                $ok[] = $host . ' (' . (int) ($res['total'] ?? 0) . ')';
            } else {
                $errors[] = (string) $res['error'];
            }
        }
        if ($skipped) {
            $errors[] = 'Pominięte: ' . implode(', ', $skipped) . ' (to nie domena, to Twoja witryna albo ponad ' . Sites::RIVALS_LIMIT . ' konkurentów).';
        }
        if ($errors) {
            \App\Core\Session::flash('error', implode(' ', $errors));
        }
        return $this->redirect($base, $ok ? 'Mapy witryn pobrane: ' . implode(', ', $ok) . '.' : null);
    }

    public function delete(Request $r): Response
    {
        if (!($site = $this->site($r))) {
            return $this->notFound('Nie ma takiej witryny');
        }
        if ($r->str('potwierdzam') !== '1') {
            return $this->redirect('/audyt/widocznosc/' . (int) $site['id'] . '/dane#ustawienia', 'Zaznacz potwierdzenie, żeby usunąć witrynę.', 'error');
        }
        Sites::delete($this->orgId(), (int) $site['id']);
        AuditLog::add('seo.site_deleted', 'seo_site', (int) $site['id'], [], $r->ip());
        return $this->redirect('/audyt/widocznosc?lista=1', 'Witryna ' . $site['host'] . ' i jej dane zostały usunięte.');
    }

    private function site(Request $r): ?array
    {
        return Sites::find($this->orgId(), (int) $r->param('id'));
    }

    /** Dane wspólne dla zakładek witryny. */
    private function common(array $site, string $tab): array
    {
        $id = (int) $site['id'];
        return [
            'site' => $site, 'tab' => $tab, 'sites' => Sites::all($this->orgId()),
            'googleOn' => Google::enabled(), 'googleEmail' => Google::email(), 'cfOn' => CloudflareBots::enabled(),
            'sync' => ['google' => Sites::lastSync($site, 'google'), 'ga4' => Sites::lastSync($site, 'ga4'), 'cloudflare' => Sites::lastSync($site, 'cloudflare')],
            'has' => ['gsc' => Stats::has($id, 'gsc'), 'gsc_ai' => Stats::has($id, 'gsc_ai'), 'bing_ai' => Stats::has($id, 'bing_ai'),
                'ga4_ai' => Stats::has($id, 'ga4_ai'), 'cf_bot' => Stats::has($id, 'cf_bot')],
        ];
    }
}
