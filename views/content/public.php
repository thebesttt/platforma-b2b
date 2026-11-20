<?php
use App\Core\View;
use App\Modules\Content\Ruleset;

$types = Ruleset::pageTypes();
$diff = $first ? (int) $audit['score'] - (int) $first['score'] : null;
?>
<header class="page-head">
  <p class="crumbs">Raport audytu treści<?= $agency !== '' ? ' · przygotowała firma ' . e($agency) : '' ?></p>
  <h1><?= e(truncate((string) ($audit['title'] ?? $target['host']), 110)) ?></h1>
  <p class="small muted" style="margin:6px 0 0"><?= e(truncate((string) $target['url'], 100)) ?> · fraza „<?= e($target['phrase']) ?>” · <?= e(mb_strtolower($types[$target['page_type']] ?? '')) ?></p>
</header>

<?php if ($first && $diff !== null): ?>
<section class="card" aria-labelledby="pub-cmp">
  <h2 id="pub-cmp" style="margin-bottom:10px">Od pierwszego audytu</h2>
  <div class="ca-cmp">
    <div><p class="small muted" style="margin:0"><?= e(local_dt($first['finished_at'], 'd.m.Y')) ?><?= $first['label'] ? ' · ' . e($first['label']) : '' ?></p><div class="ca-num sm"><?= (int) $first['score'] ?><small> / 100</small></div><?= View::partial('partials/content_ruler', ['checks' => $firstChecks, 'links' => false]) ?></div>
    <div><p class="small muted" style="margin:0"><?= e(local_dt($audit['finished_at'], 'd.m.Y')) ?><?= $audit['label'] ? ' · ' . e($audit['label']) : '' ?></p><div class="ca-num sm"><?= (int) $audit['score'] ?><small> / 100</small> <span class="chip <?= $diff > 0 ? 'low' : ($diff < 0 ? 'high' : 'neutral') ?>"><?= $diff > 0 ? '+' . $diff : $diff ?> pkt</span></div><?= View::partial('partials/content_ruler', ['checks' => $checks, 'links' => false]) ?></div>
  </div>
</section>
<?php endif ?>

<?= View::partial('partials/content_report', ['target' => $target, 'audit' => $audit, 'checks' => $checks, 'tasks' => $tasks, 'totals' => $totals, 'public' => true]) ?>

<section class="card">
  <h2 style="margin-bottom:8px">Jak czytać ten raport</h2>
  <p class="small" style="margin:0 0 8px">Każdy wskaźnik ma jawny próg i podstawę w dokumentacji wyszukiwarek. Kliknij wskaźnik, żeby zobaczyć, co zmierzyliśmy, dowód i trzy progi. Punkty są za spełnienie warunków, a nie za pozycję w wynikach.</p>
  <p class="small muted" style="margin:0">Raport nie obiecuje pozycji w Google ani cytowań w odpowiedziach AI. Żadne zewnętrzne narzędzie nie ma dostępu do systemów rankingowych wyszukiwarek.</p>
</section>
