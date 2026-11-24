<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\AuditLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Kv;
use App\Core\Request;
use App\Core\Response;
use App\Core\Secret;
use App\Core\Session;
use App\Modules\Audit\Audit;
use App\Modules\Seo\AiSources;
use App\Modules\Seo\CloudflareBots;
use App\Modules\Seo\Google;
use App\Modules\Seo\GoogleOAuth;
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
            'd' => $data, 'daily' => $daily, 'recent' => Insights::recent($daily),
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
        $search = false;
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
                $parts[] = $n . ' ' . plural((int) $n, ...GscFile::DIMS[$dim]);
            }
            $done[] = ($res['report'] === 'ai' ? 'Raport o generatywnej AI' : 'Skuteczność w wyszukiwarce') . ', ' . Sync::range($res['start'], $res['end']) . ': ' . implode(', ', $parts) . '.';
            $search = $search || $res['report'] !== 'ai';
            AuditLog::add('seo.import', 'seo_site', (int) $site['id'], ['report' => $res['report'], 'start' => $res['start'], 'end' => $res['end']], $r->ip());
        }
        if ($errors) {
            Session::flash('error', implode(' ', $errors));
        }
        if (!$done) {
            return $this->redirect($base . '/dane');
        }
        // Po raporcie „Skuteczność” pokazujemy ekran Google. Gdy wgrano sam raport o odpowiedziach AI, jego ekran.
        return $this->redirect($base . ($search ? '' : '/ai'), 'Wczytane: ' . implode(' ', $done));
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
        if (!($source === 'google' ? Sync::googleOn($this->orgId()) : Sync::cloudflareOn($this->orgId()))) {
            return $this->redirect($base . '/dane', $source === 'google'
                ? 'Najpierw połącz konto Google w tej zakładce. Możesz też wgrać plik z eksportu: działa tak samo.'
                : 'Najpierw dodaj token Cloudflare w tej zakładce.', 'error');
        }
        if (Kv::hit('seo-sync:' . $this->orgId() . ':' . gmdate('YmdH'), 3600) > self::HOURLY) {
            return $this->redirect($base . '/dane', 'Za dużo pobrań w tej godzinie. Spróbuj później.', 'error');
        }
        $res = $source === 'google' ? Sync::google($site) : Sync::cloudflare($site);
        AuditLog::add('seo.sync', 'seo_site', (int) $site['id'], ['source' => $source, 'ok' => $res['ok']], $r->ip());
        if (!$res['ok']) {
            if ($res['messages']) {
                Session::flash('success', implode(' ', $res['messages']));
            }
            return $this->redirect($base . '/dane', implode(' ', $res['errors']), 'error');
        }
        return $this->redirect($base . ($source === 'google' ? '' : '/ai'), implode(' ', $res['messages']));
    }

    /** Początek łączenia konta Google firmy: przekierowanie na ekran zgody Google. Zwykły link, bo formularz nie może prowadzić poza panel. */
    public function googleConnect(Request $r): Response
    {
        if (!($site = Sites::find($this->orgId(), $r->int('witryna')))) {
            return $this->notFound('Nie ma takiej witryny');
        }
        $back = '/audyt/widocznosc/' . (int) $site['id'] . '/dane';
        if (!GoogleOAuth::enabled()) {
            return $this->redirect($back, 'Łączenie konta Google nie jest włączone w tym panelu. Wgraj plik z eksportu: działa tak samo.', 'error');
        }
        if (!Auth::isOwner()) {
            return $this->redirect($back, 'Konto Google łączy właściciel konta firmy.', 'error');
        }
        return Response::redirect(GoogleOAuth::start($r->baseUrl(), $this->orgId(), (int) $site['id']));
    }

    /** Powrót z ekranu zgody Google: zapisujemy stały dostęp firmy i od razu pobieramy dane witryny, od której zaczęło się łączenie. */
    public function googleReturn(Request $r): Response
    {
        if (!GoogleOAuth::enabled() || !Auth::isOwner()) {
            return $this->redirect('/audyt/widocznosc?lista=1', 'Łączenie konta Google nie jest dostępne.', 'error');
        }
        // Użytkownik kliknął „Anuluj” na ekranie Google: kodu nie ma, a znacznik z sesji i tak trzeba zużyć.
        $code = $r->str('error') !== '' ? '' : mb_substr($r->str('code'), 0, 2000);
        $user = Auth::user() ?? [];
        $res = GoogleOAuth::finish($this->orgId(), Auth::id(), (string) ($user['name'] ?? $user['email'] ?? ''), mb_substr($r->str('state'), 0, 200), $code, $r->baseUrl());
        $site = $res['site'] ? Sites::find($this->orgId(), $res['site']) : null;
        $base = $site ? '/audyt/widocznosc/' . (int) $site['id'] : '/audyt/widocznosc?lista=1';
        if (!$res['ok']) {
            return $this->redirect($site ? $base . '/dane' : $base, $r->str('error') === 'access_denied' ? 'Łączenie przerwane na ekranie Google. Nic się nie zmieniło.' : (string) $res['error'], 'error');
        }
        AuditLog::add('seo.google_connected', 'organization', $this->orgId(), ['analytics' => $res['analytics'] ?? false], $r->ip());
        $note = 'Konto Google połączone.' . (empty($res['analytics']) ? ' Bez dostępu do Analytics panel nie pokaże wejść z asystentów AI: możesz połączyć konto jeszcze raz i zaznaczyć tę zgodę.' : '');
        if (!$site) {
            return $this->redirect($base, $note);
        }
        $sync = Sync::google($site);
        if (!$sync['ok']) {
            Session::flash('success', $note);
            return $this->redirect($base . '/dane', implode(' ', $sync['errors']), 'error');
        }
        return $this->redirect($base, $note . ' ' . implode(' ', $sync['messages']));
    }

    /** Odłączenie konta Google firmy: cofamy dostęp po stronie Google i usuwamy go z panelu. Wczytane dane zostają. */
    public function googleDisconnect(Request $r): Response
    {
        $site = Sites::find($this->orgId(), $r->int('witryna'));
        $back = $site ? '/audyt/widocznosc/' . (int) $site['id'] . '/dane' : '/audyt/widocznosc?lista=1';
        if (!Auth::isOwner()) {
            return $this->redirect($back, 'Konto Google odłącza właściciel konta firmy.', 'error');
        }
        if (GoogleOAuth::connected($this->orgId())) {
            GoogleOAuth::disconnect($this->orgId());
            Sites::clearSync($this->orgId(), ['google', 'ga4']);
            AuditLog::add('seo.google_disconnected', 'organization', $this->orgId(), [], $r->ip());
        }
        return $this->redirect($back, 'Konto Google odłączone. Dane wczytane do tej pory zostają w panelu.');
    }

    /** Własny token Cloudflare firmy: zapis (zaszyfrowany) z pierwszym pobraniem danych albo usunięcie. */
    public function cloudflareToken(Request $r): Response
    {
        if (!($site = Sites::find($this->orgId(), $r->int('witryna')))) {
            return $this->notFound('Nie ma takiej witryny');
        }
        $base = '/audyt/widocznosc/' . (int) $site['id'];
        if (!Auth::isOwner()) {
            return $this->redirect($base . '/dane#cloudflare', 'Token Cloudflare ustawia właściciel konta firmy.', 'error');
        }
        if ($r->str('usun') === '1') {
            Secret::put($this->orgId(), CloudflareBots::SECRET, '');
            if (!Sync::cloudflareOn($this->orgId())) {
                Sites::clearSync($this->orgId(), ['cloudflare']);
            }
            AuditLog::add('seo.cloudflare_token_removed', 'organization', $this->orgId(), [], $r->ip());
            return $this->redirect($base . '/dane#cloudflare', 'Token Cloudflare usunięty z panelu. Dane wczytane do tej pory zostają.');
        }
        if (!Secret::ready()) {
            return $this->redirect($base . '/dane#cloudflare', 'Panel nie może jeszcze bezpiecznie przechowywać tokenów. To ustawia administrator panelu (zmienna APP_SECRET).', 'error');
        }
        $token = (string) $r->input('token', '');
        $token = is_string($token) ? trim($token) : '';
        if (!CloudflareBots::looksLikeToken($token)) {
            return $this->redirect($base . '/dane#cloudflare', 'To nie wygląda na token API Cloudflare. Skopiuj cały token zaraz po utworzeniu: Cloudflare pokazuje go tylko raz.', 'error');
        }
        if (Kv::hit('seo-sync:' . $this->orgId() . ':' . gmdate('YmdH'), 3600) > self::HOURLY) {
            return $this->redirect($base . '/dane#cloudflare', 'Za dużo pobrań w tej godzinie. Spróbuj później.', 'error');
        }
        // W panelu pokazujemy tylko końcówkę, żeby dało się rozpoznać, który token jest wpisany.
        Secret::put($this->orgId(), CloudflareBots::SECRET, $token, ['label' => '…' . substr($token, -4)], Auth::id());
        AuditLog::add('seo.cloudflare_token_saved', 'organization', $this->orgId(), [], $r->ip());
        $res = Sync::cloudflare($site);
        return $res['ok']
            ? $this->redirect($base . '/ai', 'Token zapisany. ' . implode(' ', $res['messages']))
            : $this->redirect($base . '/dane#cloudflare', 'Token zapisany, ale pobranie się nie udało. ' . implode(' ', $res['errors']), 'error');
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
            Session::flash('error', implode(' ', $errors));
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
        $org = $this->orgId();
        $mode = Google::mode($org);
        return [
            'site' => $site, 'tab' => $tab, 'sites' => Sites::all($org),
            // Każda firma ma własne połączenia: konto Google i token Cloudflare. Klucze platformy działają tylko w firmie administratora.
            'googleOn' => $mode !== null, 'googleEmail' => Google::email(), 'cfOn' => Sync::cloudflareOn($org),
            'conn' => [
                'mode' => $mode, 'oauth' => GoogleOAuth::enabled(), 'google' => $mode === 'oauth' ? GoogleOAuth::info($org) : null,
                'cloudflare' => Secret::ready() ? Secret::meta($org, CloudflareBots::SECRET) : null, 'secrets' => Secret::ready(),
                'owner' => Auth::isOwner(), 'admin' => Auth::isAdmin(), 'email' => Google::email(),
            ],
            'sync' => ['google' => Sites::lastSync($site, 'google'), 'ga4' => Sites::lastSync($site, 'ga4'), 'cloudflare' => Sites::lastSync($site, 'cloudflare')],
            'has' => ['gsc' => Stats::has($id, 'gsc'), 'gsc_ai' => Stats::has($id, 'gsc_ai'), 'bing_ai' => Stats::has($id, 'bing_ai'),
                'ga4_ai' => Stats::has($id, 'ga4_ai'), 'cf_bot' => Stats::has($id, 'cf_bot')],
        ];
    }
}
