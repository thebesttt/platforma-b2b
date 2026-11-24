<?php
use App\Core\View;
use App\Modules\Seo\AiSources;
use App\Modules\Seo\Sync;

$base = '/audyt/widocznosc/' . (int) $site['id'];
echo View::partial('partials/seo_nav', ['site' => $site, 'tab' => $tab, 'sites' => $sites, 'heading' => 'Dane i połączenia',
    'lead' => 'Skąd panel bierze dane o ' . $site['host'] . ' i jak dodać kolejne źródło.']);
$names = ['gsc' => 'Search Console: Skuteczność', 'gsc_ai' => 'Search Console: raport o generatywnej AI', 'bing_ai' => 'Bing: AI Performance', 'ga4_ai' => 'Google Analytics: asystenci AI'];
$line = static function (?array $sync): string {
    if (!$sync) {
        return '<span class="muted">nie połączono</span>';
    }
    return $sync['ok']
        ? '<span class="state s-good">działa</span> <span class="small muted">' . e(local_dt((string) $sync['at'])) . ', ' . e((string) $sync['note']) . '</span>'
        : '<span class="state s-bad">nie działa</span> <span class="small">' . e((string) $sync['note']) . '</span>';
};
?>
<section class="card" aria-labelledby="sd-google">
  <h2 id="sd-google" style="margin-bottom:16px">Google Search Console</h2>
  <?= View::partial('partials/seo_start', ['site' => $site, 'googleOn' => $googleOn, 'googleEmail' => $googleEmail, 'syncGoogle' => $sync['google'], 'ai' => true]) ?>
  <details style="margin-top:18px">
    <summary class="small">Plik bez tabeli dat albo pojedynczy CSV? Podaj okres</summary>
    <form method="post" action="<?= e($base) ?>/import" enctype="multipart/form-data" class="form-grid" style="margin-top:12px">
      <?= csrf_field() ?>
      <div class="field"><label for="sd-f2">Plik</label><input id="sd-f2" type="file" name="files[]" accept=".zip,.csv" required></div>
      <div class="field"><label for="sd-od">Okres od</label><input id="sd-od" type="date" name="od" required></div>
      <div class="field"><label for="sd-do">Okres do</label><input id="sd-do" type="date" name="do" required></div>
      <div class="field" style="justify-content:flex-end"><button class="btn ghost" type="submit" data-busy="Wczytuję…">Wgraj z okresem</button></div>
    </form>
  </details>
</section>

<section class="card flush" aria-labelledby="sd-state">
  <div class="card-head"><h2 id="sd-state">Co jest podłączone</h2></div>
  <div class="table-wrap"><table>
    <thead><tr><th scope="col">Źródło</th><th scope="col">Stan</th><th scope="col">Okresy w panelu</th></tr></thead>
    <tbody>
      <tr><td><b>Search Console</b><br><span class="small muted">zapytania, strony, pozycje</span></td><td><?= $has['gsc'] && !$sync['google'] ? '<span class="state s-good">dane z pliku</span>' : $line($sync['google']) ?></td>
        <td class="small"><?= isset($periods['gsc']) ? e(implode(', ', array_map(static fn (array $p): string => Sync::range($p['start'], $p['end']), array_slice($periods['gsc'], 0, 4)))) : '<span class="muted">brak</span>' ?></td></tr>
      <tr><td><b>Odpowiedzi AI Google</b><br><span class="small muted">raport o generatywnej AI, tylko z pliku</span></td><td><?= $has['gsc_ai'] ? '<span class="state s-good">dane z pliku</span>' : '<span class="muted">brak danych</span>' ?></td>
        <td class="small"><?= isset($periods['gsc_ai']) ? e(implode(', ', array_map(static fn (array $p): string => Sync::range($p['start'], $p['end']), array_slice($periods['gsc_ai'], 0, 4)))) : '<span class="muted">brak</span>' ?></td></tr>
      <tr><td><b>Copilot i Bing</b><br><span class="small muted">raport „AI Performance”, wklejany</span></td><td><?= $has['bing_ai'] ? '<span class="state s-good">dane wklejone</span>' : '<span class="muted">brak danych</span>' ?></td>
        <td class="small"><?= isset($periods['bing_ai']) ? e(implode(', ', array_map(static fn (array $p): string => Sync::range($p['start'], $p['end']), array_slice($periods['bing_ai'], 0, 4)))) : '<span class="muted">brak</span>' ?></td></tr>
      <tr><td><b>Google Analytics</b><br><span class="small muted">wejścia z asystentów AI</span></td><td><?= $has['ga4_ai'] && !$sync['ga4'] ? '<span class="state s-good">dane wklejone</span>' : $line($sync['ga4']) ?></td>
        <td class="small"><?= isset($periods['ga4_ai']) ? e(implode(', ', array_map(static fn (array $p): string => Sync::range($p['start'], $p['end']), array_slice($periods['ga4_ai'], 0, 4)))) : '<span class="muted">brak</span>' ?></td></tr>
      <tr><td><b>Cloudflare</b><br><span class="small muted">boty AI na stronie</span></td><td><?= $line($sync['cloudflare']) ?></td>
        <td><?php if ($cfOn): ?><form method="post" action="<?= e($base) ?>/pobierz"><?= csrf_field() ?><input type="hidden" name="zrodlo" value="cloudflare"><button class="btn ghost sm" type="submit" data-busy="Pobieram…"><?= $sync['cloudflare'] && $sync['cloudflare']['ok'] ? 'Pobierz teraz' : 'Połącz' ?></button></form><?php else: ?><span class="small muted">włącza administrator (CF_ANALYTICS_TOKEN)</span><?php endif ?></td></tr>
    </tbody>
  </table></div>
  <p class="sec-note" style="padding-top:12px">Połączone źródła panel odświeża raz dziennie. Trzymamy <?= App\Modules\Seo\Stats::KEEP_PERIODS ?> ostatnich okresów każdego raportu i dane dzienne z 16 miesięcy.</p>
</section>

<section class="card" id="wklej" aria-labelledby="sd-paste">
  <h2 id="sd-paste" style="margin-bottom:6px">Wklej tabelę z raportu</h2>
  <p class="small muted" style="margin:0 0 14px">Dla raportów, których nie da się pobrać automatycznie. Zaznacz tabelę w raporcie, skopiuj i wklej. Liczy się pierwsza kolumna (adres, fraza albo źródło) i pierwsza kolumna z liczbą.</p>
  <form method="post" action="<?= e($base) ?>/wklej" class="form-grid">
    <?= csrf_field() ?>
    <div class="field span-2"><label for="sd-kind">Co wklejasz</label><select id="sd-kind" name="rodzaj" required>
      <?php foreach (AiSources::KINDS as $k => $kind): ?><option value="<?= e($k) ?>"><?= e($kind[3]) ?></option><?php endforeach ?>
    </select></div>
    <div class="field"><label for="sd-p-od">Okres od</label><input id="sd-p-od" type="date" name="od" required value="<?= e(gmdate('Y-m-d', time() - 28 * 86400)) ?>"></div>
    <div class="field"><label for="sd-p-do">Okres do</label><input id="sd-p-do" type="date" name="do" required value="<?= e(gmdate('Y-m-d', time() - 86400)) ?>"></div>
    <div class="field span-2" style="grid-column:1/-1"><label for="sd-data">Tabela</label><textarea id="sd-data" name="dane" rows="6" required maxlength="200000" placeholder="https://twojafirma.pl/oferta&#9;124&#10;https://twojafirma.pl/blog/poradnik&#9;87"></textarea></div>
    <div class="field"><label for="sd-sum">Suma z nagłówka raportu</label><input id="sd-sum" type="text" name="suma" inputmode="numeric" maxlength="12" placeholder="opcjonalnie"><span class="hint">Dla Google Analytics: wszystkie sesje witryny w okresie. Wtedy panel policzy udział asystentów AI.</span></div>
    <div class="field" style="justify-content:flex-end"><button class="btn" type="submit">Zapisz</button></div>
  </form>
</section>

<section class="card" id="ustawienia" aria-labelledby="sd-set">
  <h2 id="sd-set" style="margin-bottom:6px">Ustawienia witryny</h2>
  <form method="post" action="<?= e($base) ?>/ustawienia" class="form-grid">
    <?= csrf_field() ?>
    <div class="field span-2" style="grid-column:1/-1"><label for="sd-brand">Nazwy marki</label><input id="sd-brand" type="text" name="brand_terms" maxlength="300" value="<?= e((string) $site['brand_terms']) ?>" placeholder="<?= e(implode(', ', App\Modules\Seo\Sites::brandTerms(['brand_terms' => '', 'host' => $site['host']]))) ?>">
      <span class="hint">Po przecinku. Zapytania z tymi słowami liczymy jako ruch od osób, które już Cię znają. Puste pole: nazwa z adresu witryny.</span></div>
    <details style="grid-column:1/-1">
      <summary class="small">Ustawienia zaawansowane (zwykle niepotrzebne: panel wykrywa je sam)</summary>
      <div class="form-grid" style="margin-top:12px">
        <div class="field"><label for="sd-gsc">Usługa Search Console</label><input id="sd-gsc" type="text" name="gsc_property" maxlength="200" value="<?= e((string) $site['gsc_property']) ?>" placeholder="sc-domain:<?= e($site['host']) ?>"></div>
        <div class="field"><label for="sd-ga4">Numer usługi GA4</label><input id="sd-ga4" type="text" name="ga4_property" inputmode="numeric" maxlength="20" value="<?= e((string) $site['ga4_property']) ?>" placeholder="123456789"></div>
        <div class="field"><label for="sd-cf">Zone ID w Cloudflare</label><input id="sd-cf" type="text" name="cf_zone" maxlength="32" value="<?= e((string) $site['cf_zone']) ?>" placeholder="32 znaki"></div>
      </div>
    </details>
    <div class="field" style="justify-content:flex-end"><button class="btn ghost" type="submit">Zapisz ustawienia</button></div>
  </form>
  <details style="margin-top:18px">
    <summary class="small">Usuń witrynę z panelu</summary>
    <form method="post" action="<?= e($base) ?>/usun" style="margin-top:12px">
      <?= csrf_field() ?>
      <label class="check"><input type="checkbox" name="potwierdzam" value="1" data-ack-for="sd-del"> <span>Usuń witrynę <?= e($site['host']) ?> razem z wczytanymi danymi i listą konkurentów. Tego nie da się cofnąć.</span></label>
      <button class="btn danger" id="sd-del" type="submit" disabled>Usuń witrynę</button>
    </form>
  </details>
</section>
