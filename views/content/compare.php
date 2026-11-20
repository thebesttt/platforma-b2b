<?php
use App\Core\View;
use App\Modules\Content\Ruleset;

$rules = Ruleset::rules();
$state = static fn (string $s): string => '<span class="state s-' . e($s) . '">' . e(Ruleset::STATES[$s]['label'] ?? $s) . '</span>';
$name = static fn (array $a): string => local_dt($a['finished_at'], 'd.m.Y') . ($a['label'] ? ' · ' . $a['label'] : '');
?>
<header class="page-head">
  <p class="crumbs"><a href="/audyt/tresc">Audyt AI</a> / <a href="/audyt/tresc/<?= (int) $target['id'] ?>"><?= e($target['host']) ?></a> / Przed i po</p>
  <h1>Przed i po</h1>
  <p class="small muted" style="margin:6px 0 0"><?= e(truncate((string) $target['url'], 90)) ?> · fraza „<?= e($target['phrase']) ?>”</p>
</header>
<?= View::partial('partials/audit_nav', ['on' => 'ai']) ?>

<?php if (!$a || !$b || (int) $a['id'] === (int) $b['id']): ?>
  <div class="card empty"><h3>Do porównania potrzeba dwóch audytów</h3><p>Ta strona ma na razie <?= count($audits) ?> <?= plural(count($audits), 'audyt', 'audyty', 'audytów') ?>. Wykonaj prace i sprawdź stronę ponownie, a tu zobaczysz różnicę.</p><a class="btn" href="/audyt/tresc/<?= (int) $target['id'] ?>">Wróć do raportu</a></div>
<?php else: $diff = (int) $b['score'] - (int) $a['score']; ?>
  <form method="get" action="/audyt/tresc/<?= (int) $target['id'] ?>/porownanie" class="card form-grid">
    <div class="field"><label for="przed">Przed</label><select id="przed" name="przed"><?php foreach ($audits as $x): ?><option value="<?= (int) $x['id'] ?>"<?= (int) $x['id'] === (int) $a['id'] ? ' selected' : '' ?>><?= e($name($x)) ?> · <?= (int) $x['score'] ?> pkt</option><?php endforeach ?></select></div>
    <div class="field"><label for="po">Po</label><select id="po" name="po"><?php foreach ($audits as $x): ?><option value="<?= (int) $x['id'] ?>"<?= (int) $x['id'] === (int) $b['id'] ? ' selected' : '' ?>><?= e($name($x)) ?> · <?= (int) $x['score'] ?> pkt</option><?php endforeach ?></select></div>
    <div class="field" style="justify-content:flex-end"><button class="btn ghost" type="submit">Porównaj</button></div>
  </form>
  <?php if ($a['ruleset'] !== $b['ruleset']): ?>
    <div class="notice mid">Te audyty policzono według różnych wersji wytycznych (<?= e($a['ruleset']) ?> i <?= e($b['ruleset']) ?>). Część różnicy może wynikać ze zmiany progów, nie ze zmian na stronie.</div>
  <?php endif ?>
  <section class="card" aria-labelledby="cmp-w">
    <h2 id="cmp-w" class="sr-only">Wynik przed i po</h2>
    <div class="ca-cmp">
      <div><p class="small muted" style="margin:0">Przed: <?= e($name($a)) ?></p><div class="ca-num sm"><?= (int) $a['score'] ?><small> / 100</small></div><?= View::partial('partials/content_ruler', ['checks' => $ca, 'links' => false]) ?></div>
      <div><p class="small muted" style="margin:0">Po: <?= e($name($b)) ?></p><div class="ca-num sm"><?= (int) $b['score'] ?><small> / 100</small> <span class="chip <?= $diff > 0 ? 'low' : ($diff < 0 ? 'high' : 'neutral') ?>"><?= $diff > 0 ? '+' . $diff : $diff ?> pkt</span></div><?= View::partial('partials/content_ruler', ['checks' => $cb, 'links' => false]) ?></div>
    </div>
  </section>
  <section class="card flush" aria-labelledby="cmp-t">
    <div class="card-head"><h2 id="cmp-t">Co się zmieniło</h2></div>
    <div class="table-wrap"><table>
      <thead><tr><th scope="col">Wskaźnik</th><th scope="col">Przed</th><th scope="col">Po</th><th scope="col" class="n">Zmiana</th></tr></thead>
      <tbody>
      <?php $same = 0; foreach ($rules as $code => $rule): $x = $ca[$code] ?? null; $y = $cb[$code] ?? null; if (!$x || !$y) { continue; }
          if ($x['status'] === $y['status'] && $x['points'] === $y['points']) { $same++; continue; } $d = (int) $y['points'] - (int) $x['points']; ?>
        <tr>
          <td style="min-width:150px"><b><?= e($code) ?></b> <?= e($rule['name']) ?></td>
          <td style="min-width:200px"><?= $state($x['status']) ?><div class="small muted" style="margin-top:4px"><?= e($x['value']) ?></div></td>
          <td style="min-width:200px"><?= $state($y['status']) ?><div class="small muted" style="margin-top:4px"><?= e($y['value']) ?></div></td>
          <td class="n"><b><?= $d > 0 ? '+' . $d : $d ?></b> pkt</td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table></div>
    <div class="card-body"><p class="small muted" style="margin:12px 0 0">Bez zmian: <?= $same ?> <?= plural($same, 'wskaźnik', 'wskaźniki', 'wskaźników') ?>. Treść strony <?= $a['words'] !== $b['words'] ? 'zmieniła się z ' . (int) $a['words'] . ' na ' . (int) $b['words'] . ' słów' : 'ma tyle samo słów (' . (int) $b['words'] . ')' ?>.</p></div>
  </section>
<?php endif ?>
