<?php
/**
 * Raport z audytu strony dla firmy z listy potencjalnych klientów. Publiczny adres z losowym tokenem.
 * @var array $row @var array $v @var string $agency @var string $consentUrl adres strony zgody albo pusty
 */
use App\Modules\Audit\Audit;

$issues = $v['issues'] ?? [];
$passed = $v['passed'] ?? [];
?>
<header class="page-head stack-sm">
  <p class="crumbs">Raport z audytu strony<?= $agency !== '' ? ', przygotowała firma ' . e($agency) : '' ?></p>
  <h1><?= e($row['host']) ?></h1>
  <div class="row-flex"><?= App\Core\View::partial('partials/audit_score', ['v' => $v]) ?><span class="muted small">na 100 punktów, sprawdzone <?= e(local_dt($row['created_at'], 'd.m.Y')) ?></span></div>
</header>

<section class="grid-stats" aria-label="Wynik w kategoriach">
  <?php foreach ($v['categories'] ?? [] as $cat): ?>
    <div class="card"><div class="stat-label"><?= e($cat['label']) ?></div><div class="stat-value"><?= (int) $cat['score'] ?></div><div class="bar" style="margin-top:8px"><span style="width:<?= (int) $cat['score'] ?>%;background:<?= $cat['score'] >= 85 ? '#12B76A' : ($cat['score'] >= 60 ? '#F79009' : '#F04438') ?>"></span></div></div>
  <?php endforeach ?>
</section>

<section class="card" aria-labelledby="pr-issues">
  <h2 id="pr-issues" style="margin-bottom:8px">Do poprawy (<?= count($issues) ?>)</h2>
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
  <?php else: ?><p class="muted small" style="margin:0">Nie znaleźliśmy rzeczy do poprawy.</p><?php endif ?>
</section>

<?php if ($passed): ?>
<section class="card" aria-labelledby="pr-ok">
  <h2 id="pr-ok" style="margin-bottom:8px">W porządku (<?= count($passed) ?>)</h2>
  <ul class="list-plain"><?php foreach ($passed as $f): ?><li class="small"><span class="chip low">ok</span> <?= e($f['title']) ?></li><?php endforeach ?></ul>
</section>
<?php endif ?>

<section class="card stack-sm" aria-labelledby="pr-how">
  <h2 id="pr-how">Jak czytać ten raport</h2>
  <p class="small" style="margin:0">Sprawdziliśmy tylko to, co widać z zewnątrz, tak jak widzi stronę każdy odwiedzający: szyfrowanie i nagłówki, szybkość, podstawy pozycjonowania, pomiar ruchu i zgodność z RODO. Nie logowaliśmy się do strony i niczego na niej nie zmieniliśmy.</p>
  <?php if ($consentUrl !== ''): ?>
    <p class="small" style="margin:0">Chcą Państwo dostać ofertę na te poprawki? Wystarczy zaznaczyć zgodę na stronie, z której przyszedł raport.</p>
    <div><a class="btn sm" href="<?= e($consentUrl) ?>">Poproś o ofertę</a></div>
  <?php elseif ($agency !== ''): ?>
    <p class="small muted" style="margin:0">Raport przygotowała firma <?= e($agency) ?>.</p>
  <?php endif ?>
</section>
