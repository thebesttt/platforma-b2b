<?php
use App\Core\View;

$base = '/audyt/widocznosc/' . (int) $site['id'];
echo View::partial('partials/seo_nav', ['site' => $site, 'tab' => $tab, 'sites' => $sites, 'heading' => 'Konkurencja',
    'lead' => 'Co konkurenci publikują i jakich tematów brakuje na ' . $site['host'] . '. Z publicznych map witryn, bez płatnych narzędzi.']);
$rows = array_merge($s['own']['total'] || $rivals ? [$s['own'] + ['own' => true]] : [], $s['rivals']);
$state = static function (array $r): string {
    if (!$r['sync']) {
        return '<span class="muted">czeka na pobranie</span>';
    }
    return $r['sync']['ok'] ? '<span class="state s-good">pobrana ' . e(local_dt((string) $r['sync']['at'], 'd.m.Y')) . '</span>' : '<span class="state s-bad">brak mapy</span>';
};
?>
<section class="card" aria-labelledby="sr-form">
  <h2 id="sr-form" style="margin-bottom:6px"><?= $rivals ? 'Konkurenci' : 'Wpisz konkurentów' ?></h2>
  <p class="small muted" style="margin:0 0 12px">Do <?= App\Modules\Seo\Sites::RIVALS_LIMIT ?> domen, po jednej w wierszu. Panel pobierze ich mapy witryn i mapę Twojej witryny, a potem sprawdza je codziennie.</p>
  <form method="post" action="<?= e($base) ?>/konkurencja">
    <?= csrf_field() ?>
    <div class="field">
      <label for="sr-rivals">Domeny konkurentów</label>
      <textarea id="sr-rivals" name="rivals" rows="4" style="min-height:96px" maxlength="2000" placeholder="konkurent-pierwszy.pl&#10;konkurent-drugi.pl"><?= e(implode("\n", $rivals)) ?></textarea>
      <?php if ($suggest): ?><span class="hint">Jako konkurentów sprawdzałeś już audytem: <?= e(implode(', ', $suggest)) ?>.</span><?php endif ?>
    </div>
    <div style="margin-top:12px"><button class="btn" type="submit" data-busy="Pobieram mapy witryn…"><?= $rivals ? 'Zapisz i odśwież' : 'Zapisz i sprawdź' ?></button></div>
  </form>
</section>

<?php if ($rows): ?>
<section class="card flush" aria-labelledby="sr-sum">
  <div class="card-head"><h2 id="sr-sum">Ile publikują</h2></div>
  <div class="table-wrap"><table>
    <thead><tr><th scope="col">Witryna</th><th scope="col" class="n">Stron w mapie</th><th scope="col" class="n">Nowe, 30 dni</th><th scope="col" class="n">Zmienione, 30 dni</th><th scope="col">Audyt techniczny</th><th scope="col">Mapa witryny</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $sc = $scores[$r['host']] ?? null; $baselineOnly = $r['since'] && strtotime((string) $r['since'] . ' UTC') > time() - 86400; ?>
      <tr>
        <td><b><?= e($r['host']) ?></b><?= !empty($r['own']) ? ' <span class="chip info">Twoja witryna</span>' : '' ?></td>
        <td class="n"><?= $r['total'] ? num_pl($r['total']) : '<span class="muted">-</span>' ?></td>
        <td class="n"><?= !$r['total'] ? '<span class="muted">-</span>' : ($baselineOnly ? '<span class="muted">od jutra</span>' : num_pl($r['added'])) ?></td>
        <td class="n"><?= !$r['total'] ? '<span class="muted">-</span>' : ($r['dated'] ? num_pl($r['changed']) : '<span class="muted">mapa bez dat</span>') ?></td>
        <td><?php if ($sc): ?><a href="/audyt/<?= (int) $sc['id'] ?>"><?= (int) $sc['score'] ?> na 100</a><?php else: ?><form method="post" action="/audyt" style="display:inline"><?= csrf_field() ?><input type="hidden" name="url" value="<?= e($r['host']) ?>"><input type="hidden" name="label" value="<?= !empty($r['own']) ? 'Moja strona' : 'Konkurent' ?>"><button class="link-btn small" type="submit" data-busy="Sprawdzam…">Sprawdź</button></form><?php endif ?></td>
        <td class="small"><?= $state($r) ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table></div>
  <p class="sec-note" style="padding-top:12px">„Nowe” to strony, które pojawiły się w mapie po pierwszym pobraniu, więc liczymy je od następnego dnia. „Zmienione” bierzemy z daty podanej w mapie. Wyniki audytów porównasz w zakładce <a href="/audyt/porownanie">Porównanie z konkurencją</a>.</p>
</section>
<?php endif ?>

<?php if ($s['recent']): ?>
<section class="card flush" aria-labelledby="sr-new">
  <div class="card-head"><h2 id="sr-new">Co ostatnio opublikowali</h2><span class="chip neutral"><?= count($s['recent']) ?></span></div>
  <div class="table-wrap"><table>
    <thead><tr><th scope="col">Strona</th><th scope="col">Konkurent</th><th scope="col">Co się stało</th><th scope="col" class="n">Data</th></tr></thead>
    <tbody>
    <?php foreach ($s['recent'] as $p): ?>
      <tr><td class="cell-url"><a href="<?= e(safe_url($p['url'])) ?>" target="_blank" rel="noopener noreferrer"><?= e($p['path']) ?></a></td><td class="small"><?= e($p['host']) ?></td><td><?= $p['new'] ? '<span class="chip info">nowa strona</span>' : '<span class="chip neutral">zmieniona</span>' ?></td><td class="n small muted"><?= e(date('d.m.Y', strtotime((string) $p['date']))) ?></td></tr>
    <?php endforeach ?>
    </tbody>
  </table></div>
</section>
<?php endif ?>

<?php if ($rivals): ?>
<section class="card flush" aria-labelledby="sr-gap">
  <div class="card-head"><h2 id="sr-gap">Tematy, których nie masz</h2><?php if ($s['gaps'] && $s['gaps']['topics']): ?><span class="chip neutral"><?= count($s['gaps']['topics']) ?></span><?php endif ?></div>
  <?php if ($s['gaps'] === null): ?>
    <p class="sec-note">Żeby porównać tematy, panel potrzebuje Twoich słów: mapy Twojej witryny albo danych z Search Console. Kliknij „Zapisz i odśwież” albo <a href="<?= e($base) ?>">podłącz Search Console</a>.</p>
  <?php elseif (!$s['gaps']['topics']): ?>
    <p class="sec-note">Nie znaleźliśmy słów, które wracają w adresach konkurentów, a nie ma ich u Ciebie.</p>
  <?php else: ?>
    <p class="sec-note">Słowa z adresów stron konkurentów, których nie ma ani w Twoich adresach, ani w zapytaniach z Search Console. Wyżej te, które ma więcej niż jeden konkurent.</p>
    <div class="table-wrap"><table>
      <thead><tr><th scope="col">Temat</th><th scope="col" class="n">Stron</th><th scope="col">U kogo</th><th scope="col">Przykłady</th></tr></thead>
      <tbody>
      <?php foreach ($s['gaps']['topics'] as $t): ?>
        <tr><td><b><?= e($t['word']) ?></b></td><td class="n"><?= num_pl($t['pages']) ?></td><td class="small"><?= e(implode(', ', $t['hosts'])) ?></td>
          <td class="cell-url small"><?php foreach ($t['examples'] as $i => $ex): ?><?= $i ? '<br>' : '' ?><a href="<?= e(safe_url($ex['url'])) ?>" target="_blank" rel="noopener noreferrer"><?= e($ex['path']) ?></a><?php endforeach ?></td></tr>
      <?php endforeach ?>
      </tbody>
    </table></div>
    <p class="sec-note" style="padding-top:12px">To podpowiedź, nie wyrok: porównujemy słowa z adresów, bez polskich znaków i odmiany. Temat może być u Ciebie opisany pod innym adresem.</p>
  <?php endif ?>
</section>
<?php endif ?>

<details class="card">
  <summary><b>Czego tu nie ma</b></summary>
  <ul class="law" style="margin-top:12px">
    <li>Pozycji konkurentów w Google i ich ruchu. Search Console pokazuje tylko własną witrynę, a cudze pozycje są w płatnych narzędziach (np. Senuto, Semstorm, Ahrefs).</li>
    <li>Treści stron konkurentów. Panel czyta tylko mapę witryny, czyli listę adresów, którą witryna sama podaje robotom.</li>
    <li>Tego, kogo cytuje AI. Do tego służy test cytowań, który jest w planie.</li>
  </ul>
</details>
