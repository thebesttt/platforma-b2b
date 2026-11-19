<?php
use App\Modules\Audit\Audit;
$issues = $v['issues'] ?? [];
$passed = $v['passed'] ?? [];
?>
<header class="between page-head">
  <div class="stack-sm">
    <p class="crumbs"><a href="/audyt">Audyt</a> / <?= e($row['host']) ?></p>
    <div class="row-flex"><?= App\Core\View::partial('partials/audit_score', ['v' => $v]) ?><span class="muted small">na 100 punktów · <?= e(local_dt($row['created_at'], 'd.m.Y H:i')) ?><?= !empty($v['psi_done']) ? ' · z pomiarem PageSpeed' : '' ?></span></div>
    <h1><?= e($row['host']) ?></h1>
    <p class="muted small" style="margin:0"><a href="<?= e(safe_url($v['final_url'] ?? $row['url'])) ?>" target="_blank" rel="noopener nofollow"><?= e($v['final_url'] ?? $row['url']) ?></a></p>
  </div>
  <form method="post" action="/audyt" class="no-print"><?= csrf_field() ?><input type="hidden" name="url" value="<?= e($row['url']) ?>"><input type="hidden" name="label" value="<?= e($v['label'] ?? '') ?>"><button class="btn ghost" type="submit" data-busy="Sprawdzam…">Sprawdź ponownie</button></form>
</header>

<section class="grid-stats" aria-label="Kategorie">
  <?php foreach ($v['categories'] ?? [] as $cat): ?>
    <div class="card"><div class="stat-label"><?= e($cat['label']) ?></div><div class="stat-value"><?= (int) $cat['score'] ?></div><div class="bar" style="margin-top:8px"><span style="width:<?= (int) $cat['score'] ?>%;background:<?= $cat['score'] >= 85 ? '#12B76A' : ($cat['score'] >= 60 ? '#F79009' : '#F04438') ?>"></span></div></div>
  <?php endforeach ?>
</section>

<div class="cols">
  <section class="card main" aria-labelledby="t-is">
    <h2 id="t-is" style="margin-bottom:8px">Do poprawy (<?= count($issues) ?>)</h2>
    <?php if ($issues): ?>
      <?php foreach ($issues as $f): $sev = Audit::SEVERITY[$f['sev']] ?? Audit::SEVERITY['info']; ?>
        <div class="sig">
          <span class="chip <?= $sev['chip'] ?>"><?= e($sev['label']) ?></span>
          <div>
            <p style="font-weight:600"><?= e($f['title']) ?></p>
            <?php if (!empty($f['detail'])): ?><p class="small"><?= e($f['detail']) ?></p><?php endif ?>
            <?php if (!empty($f['fix'])): ?><p class="small" style="margin-top:4px"><strong>Jak poprawić:</strong> <?= e($f['fix']) ?></p><?php endif ?>
          </div>
        </div>
      <?php endforeach ?>
    <?php else: ?><p class="muted small">Nie znaleźliśmy problemów.</p><?php endif ?>
  </section>
  <div class="side stack">
    <?php if (!empty($v['psi'])): $psi = $v['psi']; ?>
    <section class="card"><h2 style="margin-bottom:8px">Google PageSpeed (telefon)</h2>
      <dl class="facts">
        <?php foreach (['perf' => 'Wydajność', 'seo' => 'SEO', 'lcp' => 'LCP', 'cls' => 'CLS', 'tbt' => 'TBT'] as $k => $label): if (!isset($psi[$k])) continue; ?><dt><?= e($label) ?></dt><dd><?= e(is_array($psi[$k]) ? json_out($psi[$k]) : (string) $psi[$k]) ?></dd><?php endforeach ?>
      </dl>
    </section>
    <?php endif ?>
    <section class="card"><h2 style="margin-bottom:8px">W porządku (<?= count($passed) ?>)</h2>
      <ul class="list-plain"><?php foreach ($passed as $f): ?><li class="small"><span class="chip low">ok</span> <?= e($f['title']) ?></li><?php endforeach ?></ul>
    </section>
    <?php if (count($history) > 1): ?>
    <section class="card"><h2 style="margin-bottom:8px">Historia wyników</h2>
      <ul class="list-plain"><?php foreach ($history as $h): ?><li class="small between"><a href="/audyt/<?= (int) $h['id'] ?>"><?= e(local_dt($h['created_at'], 'd.m.Y H:i')) ?></a><strong><?= (int) $h['score'] ?></strong></li><?php endforeach ?></ul>
    </section>
    <?php endif ?>
  </div>
</div>
