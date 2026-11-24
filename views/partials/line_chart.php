<?php
/**
 * Wykres liniowy jednej serii. Linia jest w SVG rozciąganym do szerokości, a podpisy i punkty to zwykłe elementy HTML,
 * więc tekst nie maleje na telefonie. Liczby są też w tabeli pod wykresem.
 *
 * @var array<string, float|int> $points etykieta => wartość, od najstarszej
 * @var string $name  nazwa serii, np. „Kliknięcia”
 * @var string $unit  dopisek w dymku, np. „kliknięć”
 * @var string $prefix początek etykiety w dymku, np. „Tydzień od ”
 */
$labels = array_keys($points);
$values = array_map('floatval', array_values($points));
$n = count($values);
if ($n < 2) {
    return;
}
$max = max($values);
// Górna granica osi: „okrągła” liczba tuż nad największą wartością.
$mag = $max > 0 ? 10 ** floor(log10($max)) : 1;
$top = $max > 0 ? ceil($max / ($mag / 2)) * ($mag / 2) : 1;
$x = static fn (int $i): float => $n === 1 ? 50.0 : round(100 * $i / ($n - 1), 3);
$y = static fn (float $v): float => round(100 - 100 * $v / $top, 3);
$poly = implode(' ', array_map(static fn (int $i): string => $x($i) . ',' . $y($values[$i]), array_keys($values)));
$fmt = static fn (float $v): string => num_pl($v, 0);
$short = static fn (string $l): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $l) ? date('d.m', strtotime($l)) : $l;
$last = $n - 1;
$marks = array_values(array_unique([0, intdiv($last, 2), $last]));
?>
<div class="chart" data-chart role="img" aria-label="<?= e($name . ': od ' . $fmt($values[0]) . ' (' . $short((string) $labels[0]) . ') do ' . $fmt($values[$last]) . ' (' . $short((string) $labels[$last]) . '), najwięcej ' . $fmt($max)) ?>">
  <div class="chart-body">
    <div class="chart-y" aria-hidden="true"><span style="top:0"><?= e($fmt($top)) ?></span><span style="top:50%"><?= e($fmt($top / 2)) ?></span><span style="top:100%">0</span></div>
    <div class="chart-plot" tabindex="0" aria-label="<?= e($name) ?>: strzałkami w lewo i w prawo przechodzisz między punktami">
      <svg viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true" focusable="false">
        <line class="g" x1="0" x2="100" y1="0" y2="0"/><line class="g" x1="0" x2="100" y1="50" y2="50"/><line class="g base" x1="0" x2="100" y1="100" y2="100"/>
        <polyline class="series" points="<?= e($poly) ?>"/>
      </svg>
      <i class="chart-cross" hidden></i><i class="chart-dot" hidden></i>
      <i class="chart-end" style="left:<?= $x($last) ?>%;top:<?= $y($values[$last]) ?>%"></i>
      <span class="chart-endlabel" style="top:<?= $y($values[$last]) ?>%"><?= e($fmt($values[$last])) ?></span>
      <?php foreach ($values as $i => $v): ?><span class="chart-hit" style="left:<?= max(0, $x($i) - 50 / max(1, $n - 1)) ?>%;width:<?= round(100 / max(1, $n - 1), 3) ?>%" data-x="<?= $x($i) ?>" data-y="<?= $y($v) ?>" data-tip="<?= e(($prefix ?? '') . $short((string) $labels[$i]) . ': ' . $fmt($v) . (($unit ?? '') !== '' ? ' ' . $unit : '')) ?>"></span><?php endforeach ?>
      <div class="chart-tip" hidden></div>
    </div>
  </div>
  <div class="chart-x" aria-hidden="true"><?php foreach ($marks as $i): ?><span><?= e($short((string) $labels[$i])) ?></span><?php endforeach ?></div>
  <details class="chart-data"><summary>Pokaż liczby</summary>
    <div class="table-wrap"><table><thead><tr><th scope="col"><?= e(trim($prefix ?? '') !== '' ? trim($prefix) : 'Okres') ?></th><th scope="col" class="n"><?= e($name) ?></th></tr></thead><tbody>
      <?php foreach ($values as $i => $v): ?><tr><td><?= e($short((string) $labels[$i])) ?></td><td class="n"><?= e($fmt($v)) ?></td></tr><?php endforeach ?>
    </tbody></table></div>
  </details>
</div>
