<?php
use App\Modules\Tenders\Regions;
use App\Modules\Tenders\Tenders;
/** @var array $rows @var bool $withScore @var array<int, int> $offers przetarg => szkic oferty firmy */
$offers ??= [];
?>
<div class="table-wrap">
  <table>
    <thead><tr><?php if ($withScore): ?><th scope="col">Ocena</th><?php endif ?><th scope="col">Przedmiot i zamawiający</th><th scope="col" class="n">Wartość szac.</th><th scope="col">Składanie ofert</th><th scope="col">Płatnik</th><th scope="col">Oferta</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $t): $days = days_until($t['deadline_at']); ?>
      <tr>
        <?php if ($withScore): ?><td><span class="score <?= score_class((int) $t['score']) ?>"><?= (int) $t['score'] ?></span></td><?php endif ?>
        <td>
          <a class="t-title" href="/przetargi/<?= (int) $t['id'] ?>"><?= e(truncate((string) $t['title'], 160)) ?></a>
          <?php if (($t['status'] ?? '') === 'new'): ?> <span class="chip info">nowy</span><?php elseif (($t['status'] ?? '') === 'saved'): ?> <span class="chip low">zapisany</span><?php endif ?>
          <div class="t-sub"><?= e($t['buyer_name']) ?><?= $t['voivodeship'] ? ' · ' . e(Regions::name($t['voivodeship'])) : '' ?> · <?= e(strtoupper((string) $t['source'])) ?><?= $t['order_type'] ? ' · ' . e(Tenders::orderTypeLabel($t['order_type'])) : '' ?></div>
          <?php if (!empty($t['reasons_json'])): $reasons = json_in($t['reasons_json']); if ($reasons): ?><div class="t-sub">Dlaczego: <?= e(implode(' · ', array_slice($reasons, 0, 4))) ?></div><?php endif; endif ?>
        </td>
        <td class="n"><?= $t['value_estimated'] ? e(short_money($t['value_estimated'])) : '<span class="muted">-</span>' ?></td>
        <td class="nowrap"><?php if ($t['deadline_at']): ?><?= e(local_dt($t['deadline_at'], 'd.m.Y, H:i')) ?><div class="t-sub"><?= $days !== null && $days < 3 ? '<span class="chip high">za ' . max(0, $days) . ' ' . plural(max(0, $days), 'dzień', 'dni', 'dni') . '</span>' : 'za ' . (int) $days . ' ' . plural((int) $days, 'dzień', 'dni', 'dni') ?></div><?php else: ?><span class="muted">-</span><?php endif ?></td>
        <td><?= $t['buyer_risk'] !== null ? risk_chip((int) $t['buyer_risk']) : ($t['buyer_nip'] ? '<a class="chip neutral" href="/kontrahenci/' . e($t['buyer_nip']) . '">Sprawdź</a>' : '<span class="chip neutral">Brak NIP</span>') ?></td>
        <td class="nowrap"><?php if (isset($offers[(int) $t['id']])): ?><a class="chip low" href="/oferty/<?= (int) $offers[(int) $t['id']] ?>">w ofertach</a><?php else: ?><form method="post" action="/przetargi/<?= (int) $t['id'] ?>/do-ofert"><?= csrf_field() ?><button class="btn ghost sm" type="submit" data-busy="Dodaję…">Do ofert</button></form><?php endif ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</div>
