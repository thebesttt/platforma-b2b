<?php
/**
 * Raport audytu treści: wynik z linijką punktów, lista „Najpierw to” i 24 wskaźniki w pięciu grupach.
 * Ten sam widok służy w panelu i pod linkiem dla klienta.
 * @var array $target @var array $audit @var array $checks @var array $tasks @var array $totals @var bool $public
 */
use App\Core\View;
use App\Modules\Content\Ruleset;

$rules = Ruleset::rules();
$sources = Ruleset::sources();
$levels = Ruleset::levels();
$score = (int) $audit['score'];
$level = Ruleset::level($score);
$state = static fn (string $s): string => '<span class="state s-' . e($s) . '">' . e(Ruleset::STATES[$s]['label'] ?? $s) . '</span>';
$offPoints = 0;
foreach ($rules as $code => $rule) {
    if (in_array($checks[$code]['status'] ?? 'none', ['none', 'na'], true)) {
        $offPoints += (int) $rule['max'];
    }
}
$none = array_keys(array_filter($checks, static fn ($c) => $c['status'] === 'none'));
$na = array_keys(array_filter($checks, static fn ($c) => $c['status'] === 'na'));
?>
<section class="card" aria-labelledby="ca-wynik">
  <h2 id="ca-wynik" class="sr-only">Wynik</h2>
  <div class="ca-score">
    <div class="ca-num"><?= $score ?><small> / 100</small></div>
    <div class="ca-meta">
      <p style="margin:0 0 4px"><?= $state($level) ?></p>
      <p class="small muted" style="margin:0">Dobrze od <?= (int) $levels['good'] ?> pkt, do poprawy od <?= (int) $levels['mid'] ?> pkt. Wytyczne w wersji <?= e($audit['ruleset']) ?>, audyt z <?= e(local_dt($audit['finished_at'] ?? $audit['created_at'])) ?>.</p>
    </div>
  </div>
  <?= View::partial('partials/content_ruler', ['checks' => $checks, 'links' => true]) ?>
  <p class="small muted" style="margin:10px 0 0">Szerokość odcinka to waga wskaźnika, wypełnienie to zdobyte punkty. Czerwona ramka oznacza zero punktów, kreskowanie brak pomiaru.</p>
  <?php if ($totals['blocked']): ?>
    <div class="notice high" style="margin-top:14px" role="alert"><b>Warunek konieczny niespełniony: <?= e(implode(', ', array_map(static fn ($c) => $c . ' ' . $rules[$c]['name'], $totals['blocked']))) ?>.</b> Dopóki tak jest, wyszukiwarki nie pokażą tej strony albo jej fragmentu, a pozostałe punkty niczego nie zmienią.</div>
  <?php endif ?>
  <?php if ($offPoints > 0): ?>
    <div class="notice info" style="margin-top:14px">Wynik policzono z <?= (int) $totals['max'] ?> punktów (zdobyto <?= (int) $totals['points'] ?>).
      <?php if ($none): ?> Nie zmierzono: <?= e(implode(', ', $none)) ?>.<?php endif ?>
      <?php if ($na): ?> Nie dotyczy tego rodzaju strony: <?= e(implode(', ', $na)) ?>.<?php endif ?>
      Brak pomiaru nie odbiera punktów.</div>
  <?php endif ?>
</section>

<?php if ($tasks): ?>
<section class="card" aria-labelledby="ca-zadania">
  <h2 id="ca-zadania" style="margin-bottom:4px">Najpierw to</h2>
  <p class="small muted" style="margin:0 0 8px">Kolejność: warunki konieczne, dostęp dla botów, potem zadania z największą liczbą punktów do zdobycia.</p>
  <ol class="ca-tasks">
    <?php foreach ($tasks as $t): ?>
      <li>
        <div class="between" style="align-items:baseline;gap:12px"><b><a href="#w-<?= e($t['code']) ?>"><?= e($t['code']) ?> <?= e($t['name']) ?></a></b><span class="chip info">+<?= (int) $t['gain'] ?> pkt</span></div>
        <p class="small" style="margin:4px 0 2px"><?= e($t['fix']) ?></p>
        <p class="xs muted" style="margin:0">Teraz: <?= e(mb_strtolower(mb_substr($t['value'], 0, 1)) . mb_substr($t['value'], 1)) ?>. Zaliczone, gdy: <?= e(mb_strtolower(mb_substr($t['good'], 0, 1)) . mb_substr($t['good'], 1)) ?></p>
      </li>
    <?php endforeach ?>
  </ol>
</section>
<?php endif ?>

<?php foreach (Ruleset::groups() as $g): $gt = $totals['groups'][$g['id']]; ?>
<section class="card flush" aria-labelledby="ca-g-<?= e($g['id']) ?>">
  <div class="card-head" style="align-items:baseline">
    <div><h2 id="ca-g-<?= e($g['id']) ?>"><?= e($g['id']) ?>. <?= e($g['name']) ?></h2><p class="small muted" style="margin:4px 0 0"><?= e($g['lead']) ?></p></div>
    <span class="nowrap"><b><?= (int) $gt['points'] ?></b> <span class="muted">z <?= (int) $gt['max'] ?> pkt</span></span>
  </div>
  <?php foreach ($rules as $code => $rule): if ($rule['group'] !== $g['id']) { continue; } $c = $checks[$code] ?? ['status' => 'none', 'points' => 0, 'max' => 0, 'value' => 'Brak danych', 'evidence' => '']; $measured = !in_array($c['status'], ['none', 'na'], true); ?>
    <details class="ca-check" id="w-<?= e($code) ?>">
      <summary>
        <span class="ca-code"><?= e($code) ?></span>
        <span class="ca-main"><span class="ca-name"><?= e($rule['name']) ?><?php if (!empty($rule['required'])): ?> <span class="chip neutral">konieczny</span><?php endif ?><?php if (!empty($rule['ai'])): ?> <span class="chip neutral">ocena AI z cytatem</span><?php endif ?></span><span class="ca-val"><?= e($c['value']) ?></span></span>
        <?= $state($c['status']) ?>
        <span class="ca-pts"><?php if ($measured): ?><b><?= (int) $c['points'] ?></b> z <?= (int) $c['max'] ?><?php else: ?><span class="muted">-</span><?php endif ?></span>
      </summary>
      <div class="ca-body">
        <p><b>Co mierzymy.</b> <?= e($rule['what']) ?> <?= e($rule['how']) ?></p>
        <?php if ($c['evidence'] !== ''): ?><p><b>Dowód.</b> <?= e($c['evidence']) ?><?php if (!empty($c['reused'])): ?> Treść i pytania nie zmieniły się od poprzedniego audytu, więc to ta sama ocena AI.<?php endif ?></p><?php endif ?>
        <?php if (!empty($c['answers'])): ?>
          <div class="table-wrap"><table>
            <thead><tr><th scope="col">Pytanie poboczne</th><th scope="col">Odpowiedź na stronie</th></tr></thead>
            <tbody><?php foreach ($c['answers'] as [$q, $ok, $quote]): ?><tr><td><?= e($q) ?></td><td><?= $ok ? '<span class="state s-good">Jest</span><div class="small muted" style="margin-top:4px">„' . e($quote) . '”</div>' : '<span class="state s-bad">Brak</span>' ?></td></tr><?php endforeach ?></tbody>
          </table></div>
        <?php endif ?>
        <?php if (in_array($c['status'], ['mid', 'bad'], true)): ?><p><b>Co zrobić.</b> <?= e($rule['fix']) ?></p><?php endif ?>
        <div class="ca-th">
          <?php foreach (['good' => 'Dobrze: ' . $rule['max'] . ' pkt', 'mid' => 'Do poprawy: ' . $rule['partial'] . ' pkt', 'bad' => 'Źle: 0 pkt'] as $k => $head): ?>
            <div class="<?= $c['status'] === $k ? 'now' : '' ?>"><p class="xs" style="margin:0 0 2px"><b><?= e($head) ?></b><?= $c['status'] === $k ? ' · teraz' : '' ?></p><p class="small" style="margin:0"><?= e($rule[$k]) ?></p></div>
          <?php endforeach ?>
        </div>
        <?php if (!empty($rule['note'])): ?><p class="small muted" style="margin:10px 0 0"><?= e($rule['note']) ?></p><?php endif ?>
        <p class="xs muted" style="margin:8px 0 0">Podstawa: <?php foreach ($rule['basis'] as $i => $b): $src = $sources[$b]; ?><?= $i ? ', ' : '' ?><a href="<?= e(safe_url($src['url'])) ?>" target="_blank" rel="noopener noreferrer"><?= e($src['short']) ?></a><?php endforeach ?>.<?php if (!empty($rule['convention'])): ?> <?= e($rule['convention']) ?><?php endif ?></p>
      </div>
    </details>
  <?php endforeach ?>
</section>
<?php endforeach ?>
