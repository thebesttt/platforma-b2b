<?php
use App\Core\View;
use App\Modules\Seo\AiSources;
use App\Modules\Seo\Insights;
use App\Modules\Seo\Sync;

/** @var ?array $d */
$base = '/audyt/widocznosc/' . (int) $site['id'];
$pos = static fn (?float $p): string => $p === null ? '-' : num_pl($p, 1);
$path = static fn (string $url): string => AiSources::path($url, (string) $site['host']);
$gsearch = static fn (string $q): string => 'https://www.google.com/search?q=' . rawurlencode($q);
echo View::partial('partials/seo_nav', ['site' => $site, 'tab' => $tab, 'sites' => $sites, 'heading' => 'Widoczność w Google',
    'lead' => 'Dane z Search Console witryny ' . $site['host'] . ': co działa, co spada i co poprawić najpierw.']);
?>
<?php if (!$d): ?>
<section class="card" aria-labelledby="sg-start">
  <h2 id="sg-start" style="margin-bottom:6px">Podłącz dane z Search Console</h2>
  <p class="small muted" style="margin:0 0 18px">Są dwie drogi. Plik działa od razu i nie wymaga niczego poza dostępem do Search Console.</p>
  <?= View::partial('partials/seo_start', ['site' => $site, 'conn' => $conn, 'googleEmail' => $googleEmail, 'syncGoogle' => $sync['google'], 'ai' => false]) ?>
</section>
<?php else:
    $t = $d['totals'];
    $pt = $d['prevTotals'];
    $scale = $d['scale'];
    $was = static fn (string $f) => $pt ? $pt[$f] * ($f === 'clicks' || $f === 'impressions' ? $scale : 1) : null;
?>
<p class="small muted" style="margin:0">
  Okres <b><?= e(Sync::range($d['cur']['start'], $d['cur']['end'])) ?></b> (<?= (int) $d['cur']['days'] ?> dni).
  <?php if ($d['prev']): ?>Porównanie z okresem <?= e(Sync::range($d['prev']['start'], $d['prev']['end'])) ?><?= abs($scale - 1) > 0.02 ? ', przeliczonym na tę samą liczbę dni' : '' ?>.
  <?php elseif ($recent): ?>Ostatnie 28 dni (<?= e(Sync::range($recent['from'], $recent['to'])) ?>): <b><?= num_pl($recent['clicks']) ?></b> <?= plural($recent['clicks'], 'kliknięcie', 'kliknięcia', 'kliknięć') ?> <?= delta_html($recent['clicks'], $recent['was']) ?> i <b><?= num_pl($recent['impressions']) ?></b> <?= plural($recent['impressions'], 'wyświetlenie', 'wyświetlenia', 'wyświetleń') ?> <?= delta_html($recent['impressions'], $recent['wasImpressions']) ?> wobec 28 dni wcześniej. Spadki i wzrosty pojedynczych zapytań pokaże <a href="<?= e($base) ?>/dane">eksport wcześniejszego okresu albo stałe połączenie</a>.
  <?php else: ?>Nie ma wcześniejszego okresu, więc panel nie pokaże jeszcze zmian. <a href="<?= e($base) ?>/dane">Wgraj eksport wcześniejszego okresu</a> albo połącz konto.<?php endif ?>
  <?php if ($sync['google'] && $sync['google']['ok']): ?>Dane pobrane <?= e(local_dt((string) $sync['google']['at'])) ?>.<?php endif ?>
</p>

<section class="grid-stats" aria-label="Wynik okresu">
  <div class="card"><div class="stat-label">Kliknięcia</div><div class="stat-value"><?= num_pl($t['clicks']) ?></div><div class="stat-note"><?= delta_html($t['clicks'], $was('clicks')) ?: 'wejścia z wyników Google' ?></div></div>
  <div class="card"><div class="stat-label">Wyświetlenia</div><div class="stat-value"><?= num_pl($t['impressions']) ?></div><div class="stat-note"><?= delta_html($t['impressions'], $was('impressions')) ?: 'tyle razy ktoś zobaczył link' ?></div></div>
  <div class="card"><div class="stat-label">Klikalność</div><div class="stat-value"><?= pct_pl($t['ctr']) ?></div><div class="stat-note"><?= $pt ? delta_html($t['ctr'], $pt['ctr']) : 'kliknięcia do wyświetleń' ?></div></div>
  <div class="card"><div class="stat-label">Średnia pozycja</div><div class="stat-value"><?= $pos($t['position']) ?></div><div class="stat-note"><?= $pt && $t['position'] !== null && $pt['position'] !== null ? delta_html($t['position'], $pt['position'], true, true) . ' pozycji' : 'niżej znaczy lepiej' ?></div></div>
</section>
<?php if ($d['totalsPartial']): ?>
  <p class="notice info">Sumy pochodzą z wierszy widocznych w pliku, więc są niższe niż w Search Console (Google ukrywa rzadkie zapytania). Pełne sumy da eksport całej paczki ZIP albo stałe połączenie.</p>
<?php endif ?>

<?php if (count($weeklyClicks) >= 3): ?>
<div class="cols">
  <section class="card main" style="flex-basis:340px" aria-labelledby="sg-ch1">
    <h2 id="sg-ch1">Kliknięcia tygodniami</h2>
    <?= View::partial('partials/line_chart', ['points' => $weeklyClicks, 'name' => 'Kliknięcia', 'unit' => 'kliknięć', 'prefix' => 'Tydzień od ']) ?>
  </section>
  <section class="card main" style="flex-basis:340px" aria-labelledby="sg-ch2">
    <h2 id="sg-ch2">Wyświetlenia tygodniami</h2>
    <?= View::partial('partials/line_chart', ['points' => $weeklyImpressions, 'name' => 'Wyświetlenia', 'unit' => 'wyświetleń', 'prefix' => 'Tydzień od ']) ?>
  </section>
</div>
<?php endif ?>

<section class="card flush" aria-labelledby="sg-near">
  <div class="card-head"><h2 id="sg-near">Tuż za czołówką</h2><span class="chip neutral"><?= count($d['near']) ?></span></div>
  <p class="sec-note">Zapytania na pozycjach 4-15, które ludzie często widzą. Tu najłatwiej o dodatkowe kliknięcia: wystarczy rozbudować stronę albo podlinkować ją z innych.</p>
  <?php if ($d['near']): ?>
    <div class="table-wrap"><table>
      <thead><tr><th scope="col">Zapytanie</th><th scope="col" class="n">Do zyskania</th><th scope="col" class="n">Pozycja</th><th scope="col" class="n">Wyświetlenia</th><th scope="col" class="n">Kliknięcia</th></tr></thead>
      <tbody>
      <?php foreach ($d['near'] as $q): ?>
        <tr><td><a href="<?= e($gsearch($q['k'])) ?>" target="_blank" rel="noopener noreferrer"><?= e($q['k']) ?></a></td><td class="n"><b>ok. <?= num_pl($q['gain']) ?></b></td><td class="n"><?= $pos($q['position']) ?></td><td class="n"><?= num_pl($q['impressions']) ?></td><td class="n"><?= num_pl($q['clicks']) ?></td></tr>
      <?php endforeach ?>
      </tbody>
    </table></div>
    <p class="sec-note" style="padding-top:12px">„Do zyskania” to szacunek kliknięć w tym okresie, gdyby zapytanie weszło do pierwszej trójki. Liczymy go z klikalności <?= pct_pl($d['reference']['top']) ?>: <?= $d['reference']['topOwn'] ? 'tyle mają Twoje zapytania z pozycji 1-3' : 'to wartość umowna, bo Twoich zapytań z pozycji 1-3 jest za mało na własną średnią' ?>.</p>
  <?php else: ?>
    <p class="sec-note">Nie ma takich zapytań z co najmniej <?= (int) $d['min'] ?> wyświetleniami w tym okresie.</p>
  <?php endif ?>
</section>

<section class="card flush" aria-labelledby="sg-weak">
  <div class="card-head"><h2 id="sg-weak">Wysoko, ale mało kliknięć</h2><span class="chip neutral"><?= count($d['weak']) ?></span></div>
  <p class="sec-note">Zapytania z pierwszej piątki, w które ludzie klikają ponad dwa razy rzadziej niż zwykle na tej pozycji. Najczęściej winny jest tytuł albo opis strony w wynikach. Bywa też, że odpowiedź pojawia się nad wynikami.</p>
  <?php if ($d['weak']): ?>
    <div class="table-wrap"><table>
      <thead><tr><th scope="col">Zapytanie</th><th scope="col" class="n">Klikalność</th><th scope="col" class="n">Zwykle na tej pozycji</th><th scope="col" class="n">Pozycja</th><th scope="col" class="n">Wyświetlenia</th></tr></thead>
      <tbody>
      <?php foreach ($d['weak'] as $q): ?>
        <tr><td><a href="<?= e($gsearch($q['k'])) ?>" target="_blank" rel="noopener noreferrer"><?= e($q['k']) ?></a></td><td class="n"><b><?= pct_pl($q['ctr']) ?></b></td><td class="n"><?= pct_pl($q['expected']) ?><?= $q['own'] ? '' : ' <span class="muted">(umownie)</span>' ?></td><td class="n"><?= $pos($q['position']) ?></td><td class="n"><?= num_pl($q['impressions']) ?></td></tr>
      <?php endforeach ?>
      </tbody>
    </table></div>
  <?php else: ?>
    <p class="sec-note">Nie ma takich zapytań. Tytuły i opisy stron z pierwszej piątki zbierają kliknięcia jak należy.</p>
  <?php endif ?>
</section>

<?php if ($d['movers']): ?>
<div class="cols">
  <?php foreach (['down' => ['Największe spadki', 'Zapytania, które straciły najwięcej kliknięć.'], 'up' => ['Największe wzrosty', 'Zapytania, które zyskały najwięcej kliknięć.']] as $dir => [$title, $note]): ?>
  <section class="card flush main" style="flex-basis:340px" aria-labelledby="sg-<?= $dir ?>">
    <div class="card-head"><h2 id="sg-<?= $dir ?>"><?= $title ?></h2></div>
    <p class="sec-note"><?= $note ?></p>
    <?php if ($d['movers'][$dir]): ?>
      <div class="table-wrap"><table>
        <thead><tr><th scope="col">Zapytanie</th><th scope="col" class="n">Kliknięcia</th><th scope="col" class="n">Było</th><th scope="col" class="n">Pozycja</th></tr></thead>
        <tbody>
        <?php foreach ($d['movers'][$dir] as $m): ?>
          <tr><td><?= e($m['k']) ?><?= $m['gone'] ? ' <span class="chip neutral">zniknęło</span>' : '' ?></td><td class="n"><b><?= num_pl($m['clicks']) ?></b></td><td class="n"><?= num_pl($m['was']) ?></td>
            <td class="n"><?= $pos($m['position']) ?><?php if ($m['position'] !== null && $m['wasPosition'] !== null && abs($m['position'] - $m['wasPosition']) >= 0.5): ?> <span class="small muted">(było <?= $pos($m['wasPosition']) ?>)</span><?php endif ?></td></tr>
        <?php endforeach ?>
        </tbody>
      </table></div>
    <?php else: ?>
      <p class="sec-note">Brak zmian o co najmniej 3 kliknięcia i 20%.</p>
    <?php endif ?>
  </section>
  <?php endforeach ?>
</div>
<?php if ($d['pageMovers'] && $d['pageMovers']['down']): ?>
<section class="card flush" aria-labelledby="sg-pdown">
  <div class="card-head"><h2 id="sg-pdown">Strony, które tracą</h2></div>
  <div class="table-wrap"><table>
    <thead><tr><th scope="col">Strona</th><th scope="col" class="n">Kliknięcia</th><th scope="col" class="n">Było</th><th scope="col" class="n">Wyświetlenia</th><th scope="col" class="n">Było</th></tr></thead>
    <tbody>
    <?php foreach ($d['pageMovers']['down'] as $m): ?>
      <tr><td class="cell-url"><a href="<?= e(safe_url($m['k'])) ?>" target="_blank" rel="noopener noreferrer"><?= e($path($m['k'])) ?></a></td><td class="n"><b><?= num_pl($m['clicks']) ?></b></td><td class="n"><?= num_pl($m['was']) ?></td><td class="n"><?= num_pl($m['impressions']) ?></td><td class="n"><?= num_pl($m['wasImpressions']) ?></td></tr>
    <?php endforeach ?>
    </tbody>
  </table></div>
  <p class="sec-note" style="padding-top:12px">Kliknięcia spadły, a wyświetlenia nie? Zobacz zakładkę <a href="<?= e($base) ?>/ai">Odpowiedzi AI</a>: tak wygląda zapytanie, nad którego wynikami pojawiła się odpowiedź AI.</p>
</section>
<?php endif ?>
<?php endif ?>

<section class="card flush" aria-labelledby="sg-can">
  <div class="card-head"><h2 id="sg-can">Dwie strony na jedno zapytanie</h2><?php if ($d['hasQueryPage']): ?><span class="chip neutral"><?= count($d['cannibal']) ?></span><?php endif ?></div>
  <?php if (!$d['hasQueryPage']): ?>
    <p class="sec-note">To zestawienie wymaga stałego połączenia: plik z eksportu nie mówi, która strona pokazała się na które zapytanie. <a href="<?= e($base) ?>/dane">Połącz konto</a>, a panel sprawdzi to sam.</p>
  <?php elseif (!$d['cannibal']): ?>
    <p class="sec-note">Nie ma zapytań, przy których dwie Twoje strony dzielą się wyświetleniami.</p>
  <?php else: ?>
    <p class="sec-note">Przy tych zapytaniach Google pokazuje raz jedną, raz drugą stronę. Zwykle lepiej połączyć treść w jedną stronę albo wyraźnie rozdzielić tematy.</p>
    <div class="table-wrap"><table>
      <thead><tr><th scope="col">Zapytanie</th><th scope="col">Strony</th><th scope="col" class="n">Wyświetlenia</th><th scope="col" class="n">Pozycja</th></tr></thead>
      <tbody>
      <?php foreach ($d['cannibal'] as $c): foreach ($c['pages'] as $i => $p): ?>
        <tr><?php if ($i === 0): ?><td rowspan="<?= count($c['pages']) ?>"><b><?= e($c['k']) ?></b></td><?php endif ?>
          <td class="cell-url"><a href="<?= e(safe_url($p['k2'])) ?>" target="_blank" rel="noopener noreferrer"><?= e($path($p['k2'])) ?></a></td><td class="n"><?= num_pl($p['impressions']) ?></td><td class="n"><?= $pos($p['position']) ?></td></tr>
      <?php endforeach; endforeach ?>
      </tbody>
    </table></div>
  <?php endif ?>
</section>

<?php $b = $d['brand']; $sumC = max(1, $b['brand']['clicks'] + $b['other']['clicks'] + $b['hidden']['clicks']); ?>
<div class="cols">
  <section class="card main" style="flex-basis:340px" aria-labelledby="sg-brand">
    <h2 id="sg-brand" style="margin-bottom:6px">Skąd są kliknięcia</h2>
    <p class="small muted" style="margin:0 0 14px">Kto szuka Twojej nazwy, już Cię zna. Nowych klientów przyprowadzają pozostałe zapytania.</p>
    <div class="share" role="img" aria-label="Kliknięcia: z nazwą marki <?= num_pl($b['brand']['clicks']) ?>, pozostałe <?= num_pl($b['other']['clicks']) ?>, ukryte przez Google <?= num_pl($b['hidden']['clicks']) ?>">
      <i class="s1" style="width:<?= round(100 * $b['brand']['clicks'] / $sumC, 1) ?>%"></i><i class="s2" style="width:<?= round(100 * $b['other']['clicks'] / $sumC, 1) ?>%"></i><i class="s3" style="width:<?= round(100 * $b['hidden']['clicks'] / $sumC, 1) ?>%"></i>
    </div>
    <ul class="share-key">
      <li>Z nazwą marki: <b><?= num_pl($b['brand']['clicks']) ?></b> (<?= pct_pl($b['brand']['clicks'] / $sumC, 0) ?>)</li>
      <li class="k2">Pozostałe: <b><?= num_pl($b['other']['clicks']) ?></b> (<?= pct_pl($b['other']['clicks'] / $sumC, 0) ?>)</li>
      <li class="k3">Ukryte przez Google: <b><?= num_pl($b['hidden']['clicks']) ?></b> (<?= pct_pl($b['hidden']['clicks'] / $sumC, 0) ?>)</li>
    </ul>
    <p class="small muted" style="margin:12px 0 0">Za zapytania z marką uznajemy te ze słowem: <?= e(implode(', ', App\Modules\Seo\Sites::brandTerms($site))) ?>. Zmienisz to w <a href="<?= e($base) ?>/dane#ustawienia">ustawieniach</a>. Rzadkich zapytań Google nie pokazuje, stąd część ukryta.</p>
  </section>
  <?php if ($d['devices']): $devSum = max(1, array_sum(array_column($d['devices'], 'clicks'))); $devNames = ['MOBILE' => 'Telefon', 'DESKTOP' => 'Komputer', 'TABLET' => 'Tablet']; ?>
  <section class="card side" aria-labelledby="sg-dev">
    <h2 id="sg-dev" style="margin-bottom:8px">Urządzenia</h2>
    <?php foreach ($d['devices'] as $dev): ?>
      <div class="kv-row"><div><p class="k"><?= e($devNames[strtoupper($dev['k'])] ?? $dev['k']) ?>, pozycja <?= $pos($dev['position']) ?></p><div class="bar" style="margin-top:6px"><span style="width:<?= round(100 * $dev['clicks'] / $devSum) ?>%"></span></div></div><b><?= num_pl($dev['clicks']) ?></b></div>
    <?php endforeach ?>
  </section>
  <?php endif ?>
</div>

<div class="cols">
  <section class="card flush main" style="flex-basis:340px" aria-labelledby="sg-tq">
    <div class="card-head"><h2 id="sg-tq">Najczęściej klikane zapytania</h2></div>
    <div class="table-wrap"><table>
      <thead><tr><th scope="col">Zapytanie</th><th scope="col" class="n">Kliknięcia</th><th scope="col" class="n">Wyświetlenia</th><th scope="col" class="n">Pozycja</th></tr></thead>
      <tbody><?php foreach ($d['topQueries'] as $q): ?><tr><td><?= e($q['k']) ?><?= $q['brand'] ? ' <span class="chip neutral">marka</span>' : '' ?><?= Insights::isQuestion($q['k']) ? ' <span class="chip info">pytanie</span>' : '' ?></td><td class="n"><b><?= num_pl($q['clicks']) ?></b></td><td class="n"><?= num_pl($q['impressions']) ?></td><td class="n"><?= $pos($q['position']) ?></td></tr><?php endforeach ?></tbody>
    </table></div>
  </section>
  <section class="card flush main" style="flex-basis:340px" aria-labelledby="sg-tp">
    <div class="card-head"><h2 id="sg-tp">Najczęściej klikane strony</h2></div>
    <?php if ($d['topPages']): ?>
    <div class="table-wrap"><table>
      <thead><tr><th scope="col">Strona</th><th scope="col" class="n">Kliknięcia</th><th scope="col" class="n">Wyświetlenia</th></tr></thead>
      <tbody><?php foreach ($d['topPages'] as $p): ?><tr><td class="cell-url"><a href="<?= e(safe_url($p['k'])) ?>" target="_blank" rel="noopener noreferrer"><?= e($path($p['k'])) ?></a></td><td class="n"><b><?= num_pl($p['clicks']) ?></b></td><td class="n"><?= num_pl($p['impressions']) ?></td></tr><?php endforeach ?></tbody>
    </table></div>
    <?php else: ?><p class="sec-note">W pliku nie było tabeli stron.</p><?php endif ?>
  </section>
</div>

<details class="card">
  <summary><b>Skąd te liczby</b></summary>
  <ul class="law" style="margin-top:12px">
    <li>Na listy trafiają zapytania z co najmniej <?= (int) $d['min'] ?> wyświetleniami w okresie (30 na 28 dni). Zapytania z nazwą marki pomijamy.</li>
    <li>„Tuż za czołówką”: średnia pozycja od 4 do 15.</li>
    <li>„Wysoko, ale mało kliknięć”: pozycja 1-5 i klikalność niższa niż połowa odniesienia. Odniesienie to średnia Twoich zapytań na tej pozycji, jeśli jest ich co najmniej 5 i mają razem 300 wyświetleń. Inaczej wartości umowne: pozycja 1: 25%, 2: 13%, 3: 9%, 4-5: 5%.</li>
    <li>Spadki i wzrosty: zmiana o co najmniej 3 kliknięcia i 20%.</li>
    <li>„Dwie strony na jedno zapytanie”: każda ze stron ma co najmniej 20% wyświetleń zapytania.</li>
    <li>Search Console liczy w wynikach także linki pokazane w odpowiedziach AI Google. W tym raporcie nie da się ich oddzielić, osobny raport jest w zakładce <a href="<?= e($base) ?>/ai">Odpowiedzi AI</a>.</li>
  </ul>
</details>
<?php endif ?>
