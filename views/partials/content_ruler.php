<?php
/**
 * Linijka 100 punktów: 24 odcinki w 5 grupach. Szerokość odcinka to waga wskaźnika, wypełnienie to zdobyte punkty.
 * @var array $checks wyniki wskaźników
 * @var bool $links czy odcinki prowadzą do wskaźników na tej samej stronie
 */
use App\Modules\Content\Ruleset;

$rules = Ruleset::rules();
$byGroup = [];
foreach ($rules as $code => $rule) {
    $byGroup[$rule['group']][] = $code;
}
$tot = Ruleset::totals($checks);
?>
<div class="ruler">
  <?php foreach (Ruleset::groups() as $g): $gt = $tot['groups'][$g['id']]; ?>
    <div class="rg" style="flex:<?= (int) $gt['full'] ?> 1 0">
      <div class="segs">
        <?php foreach ($byGroup[$g['id']] ?? [] as $code): $rule = $rules[$code]; $c = $checks[$code] ?? ['status' => 'none', 'points' => 0, 'max' => 0];
            $off = in_array($c['status'], ['none', 'na'], true);
            $label = $code . ' ' . $rule['name'] . ': ' . ($off ? mb_strtolower(Ruleset::STATES[$c['status']]['label']) : (int) $c['points'] . ' z ' . (int) $c['max'] . ' pkt');
            $fill = !$off && $c['max'] > 0 ? round(100 * $c['points'] / $c['max']) : 0; ?>
          <<?= !empty($links) ? 'a href="#w-' . e($code) . '"' : 'span' ?> class="seg<?= $off ? ' off' : ($c['status'] === 'bad' ? ' zero' : '') ?>" style="flex:<?= (int) $rule['max'] ?> 1 0" title="<?= e($label) ?>"><i style="width:<?= $fill ?>%"></i><span class="sr-only"><?= e($label) ?></span></<?= !empty($links) ? 'a' : 'span' ?>>
        <?php endforeach ?>
      </div>
      <div class="rg-label" title="<?= e($g['id'] . '. ' . $g['name']) ?>"><?= e($g['id']) ?> <span><?= e($g['short']) ?></span> <b><?= (int) $gt['points'] ?></b>/<?= (int) $gt['max'] ?></div>
    </div>
  <?php endforeach ?>
</div>
