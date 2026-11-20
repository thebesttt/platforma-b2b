<?php
use App\Core\View;
use App\Modules\Content\Ruleset;

$rules = Ruleset::rules();
$sources = Ruleset::sources();
$levels = Ruleset::levels();
$types = Ruleset::pageTypes();
$conventions = array_keys(array_filter($rules, static fn ($r) => !empty($r['convention'])));
?>
<header class="page-head">
  <p class="crumbs"><a href="/audyt/tresc">Audyt AI</a> / Wytyczne</p>
  <h1>Wytyczne wskaźników</h1>
  <p class="muted small">Wersja <?= e(Ruleset::version()) ?>. <?= count($rules) ?> wskaźniki, <?= array_sum(array_column($rules, 'max')) ?> punktów. Z tego samego pliku liczy punkty silnik audytu, więc próg na tej stronie jest zawsze progiem w raporcie.</p>
</header>
<?= View::partial('partials/audit_nav', ['on' => 'wytyczne']) ?>

<section class="card" aria-labelledby="g-zasady">
  <h2 id="g-zasady" style="margin-bottom:8px">Zasady punktacji</h2>
  <ul class="ca-rules">
    <li><b>Punkty tylko za to, co ma podstawę.</b> Każdy wskaźnik odwołuje się do dokumentacji Google, Microsoftu, dostawców botów AI albo do opublikowanego badania. Praca zgodna z wytycznymi jest jednocześnie dobrym SEO i dobrym wynikiem w narzędziu.</li>
    <li><b>Trzy stany.</b> „Dobrze” daje komplet punktów wskaźnika, „Do poprawy” część, „Źle” zero. Wynik strony: dobrze od <?= (int) $levels['good'] ?> pkt, do poprawy od <?= (int) $levels['mid'] ?> pkt.</li>
    <li><b>Warunki konieczne.</b> <?= e(implode(', ', array_keys(array_filter($rules, static fn ($r) => !empty($r['required']))))) ?>: gdy któryś ma stan „Źle”, raport pokazuje to nad wynikiem, bo wyszukiwarka nie pokaże strony albo jej fragmentu.</li>
    <li><b>Brak pomiaru nie odbiera punktów.</b> Gdy wskaźnika nie da się zmierzyć (usługa nie odpowiada albo nie jest podłączona) albo nie dotyczy danego rodzaju strony, raport to pokazuje, a wynik liczymy proporcjonalnie z pozostałych punktów.</li>
    <li><b>Powtarzalność.</b> <?= count($rules) - count(array_filter($rules, static fn ($r) => !empty($r['ai']))) ?> wskaźniki liczy program: ta sama strona daje ten sam wynik. Dwa wskaźniki ocenia model AI. Musi wskazać zdanie ze strony, a program sprawdza, czy to zdanie naprawdę tam jest. Gdy treść się nie zmieniła, bierzemy poprzednią ocenę.</li>
    <li><b>Wersje.</b> Każdy audyt zapisuje wersję wytycznych. Zmiana progu to nowa wersja, a stare audyty zostają przy swojej.</li>
    <li><b>Czego wynik nie obiecuje.</b> Pozycji w Google ani cytowań w odpowiedziach AI. Żadne zewnętrzne narzędzie nie ma dostępu do systemów rankingowych wyszukiwarek.</li>
  </ul>
  <p class="small muted" style="margin:12px 0 0">Rodzaje stron: <?= e(mb_strtolower(implode(', ', $types))) ?>. Progi umowne (do przeglądu przez zespół SEO): <?= e(implode(', ', $conventions)) ?>.</p>
</section>

<?php foreach (Ruleset::groups() as $g): $inGroup = array_filter($rules, static fn ($r) => $r['group'] === $g['id']); ?>
<section class="card flush" aria-labelledby="g-<?= e($g['id']) ?>">
  <div class="card-head" style="align-items:baseline">
    <div><h2 id="g-<?= e($g['id']) ?>"><?= e($g['id']) ?>. <?= e($g['name']) ?></h2><p class="small muted" style="margin:4px 0 0"><?= e($g['lead']) ?></p></div>
    <span class="nowrap"><b><?= array_sum(array_column($inGroup, 'max')) ?></b> <span class="muted">pkt</span></span>
  </div>
  <div class="table-wrap"><table class="ca-guide">
    <thead><tr><th scope="col">Wskaźnik</th><th scope="col">Dobrze</th><th scope="col">Do poprawy</th><th scope="col">Źle</th></tr></thead>
    <tbody>
    <?php foreach ($inGroup as $code => $r): ?>
      <tr id="<?= e($code) ?>">
        <td style="min-width:240px"><b><?= e($code) ?> <?= e($r['name']) ?></b><?php if (!empty($r['required'])): ?> <span class="chip neutral">konieczny</span><?php endif ?><?php if (!empty($r['ai'])): ?> <span class="chip neutral">ocena AI</span><?php endif ?>
          <div class="small muted" style="margin-top:4px"><?= e($r['how']) ?></div>
          <?php if (!empty($r['note'])): ?><div class="small muted" style="margin-top:4px"><?= e($r['note']) ?></div><?php endif ?>
          <div class="xs muted" style="margin-top:6px">Podstawa: <?php foreach ($r['basis'] as $i => $b): ?><?= $i ? ', ' : '' ?><a href="<?= e(safe_url($sources[$b]['url'])) ?>" target="_blank" rel="noopener noreferrer"><?= e($sources[$b]['short']) ?></a><?php endforeach ?>.<?php if (!empty($r['convention'])): ?> <?= e($r['convention']) ?><?php endif ?></div></td>
        <td style="min-width:180px" class="small"><b><?= (int) $r['max'] ?> pkt</b><br><?= e($r['good']) ?></td>
        <td style="min-width:180px" class="small"><b><?= (int) $r['partial'] ?> pkt</b><br><?= e($r['mid']) ?></td>
        <td style="min-width:180px" class="small"><b>0 pkt</b><br><?= e($r['bad']) ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table></div>
</section>
<?php endforeach ?>

<section class="card" aria-labelledby="g-bez">
  <h2 id="g-bez" style="margin-bottom:8px">Czego nie punktujemy i dlaczego</h2>
  <?php foreach (Ruleset::unscored() as $u): ?>
    <div class="kv-row"><div><p class="v"><b><?= e($u['name']) ?></b></p><p class="small muted" style="margin:4px 0 0"><?= e($u['why']) ?> Podstawa: <?php foreach ($u['basis'] as $i => $b): ?><?= $i ? ', ' : '' ?><a href="<?= e(safe_url($sources[$b]['url'])) ?>" target="_blank" rel="noopener noreferrer"><?= e($sources[$b]['short']) ?></a><?php endforeach ?>.</p></div></div>
  <?php endforeach ?>
</section>

<section class="card" aria-labelledby="g-src">
  <h2 id="g-src" style="margin-bottom:8px">Źródła</h2>
  <ul class="list-plain small"><?php foreach ($sources as $s): ?><li><a href="<?= e(safe_url($s['url'])) ?>" target="_blank" rel="noopener noreferrer"><?= e($s['label']) ?></a></li><?php endforeach ?></ul>
</section>
