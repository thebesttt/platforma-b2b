<?php
use App\Modules\Tenders\Regions;
use App\Modules\Tenders\Results;
use App\Modules\Tenders\Tenders;
/**
 * Wiersze wyników przetargów (jedna pozycja na część zamówienia).
 * @var array $rows @var array $cards identyfikator postępowania => id przetargu w naszej bazie
 * @var bool $withBuyer @var bool $showPart numer części także przy pierwszej
 * @var bool $withTitle fałsz na karcie przetargu: tytuł jest w nagłówku strony, więc wiersz nazywa tylko część
 */
$withTitle ??= true;
$cards ??= [];
$withBuyer ??= true;
$showPart ??= false;
// Ogłoszenie z kilkoma częściami: numer pokazujemy przy każdej, także pierwszej.
$multi = [];
foreach ($rows as $x) {
    if ((int) $x['part'] > 1) {
        $multi[(string) $x['external_id']] = true;
    }
}
?>
<div class="table-wrap">
  <table class="res-table">
    <thead><tr><th scope="col" class="col-date">Ogłoszono</th><th scope="col"><?= !$withTitle ? 'Zakres' : 'Przedmiot' . ($withBuyer ? ' i zamawiający' : '') ?></th><th scope="col">Wykonawca</th><th scope="col" class="n">Wartość umowy</th><th scope="col" class="n">Oferty</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $x):
        $det = json_in($x['details_json'] ?? null);
        $card = $cards[(string) ($x['ocid'] ?? '')] ?? null;
        $value = $x['contract_value'] ?? null;
        $offer = $x['price_winner'] ?? null; ?>
      <tr>
        <td class="nowrap col-date"><?= e(local_date($x['published_at'])) ?></td>
        <td>
          <?php if (!$withTitle): ?><span class="t-title"><?= $showPart || isset($multi[(string) $x['external_id']]) ? 'Część ' . (int) $x['part'] : 'Całe zamówienie' ?></span>
          <?php elseif ($card): ?><a class="t-title" href="/przetargi/<?= (int) $card ?>"><?= e(truncate((string) $x['title'], 150)) ?></a>
          <?php elseif ($x['url']): ?><a class="t-title" href="<?= e(safe_url($x['url'])) ?>" target="_blank" rel="noopener"><?= e(truncate((string) $x['title'], 150)) ?></a>
          <?php else: ?><span class="t-title"><?= e(truncate((string) $x['title'], 150)) ?></span><?php endif ?>
          <div class="t-sub"><span class="only-narrow"><?= e(local_date($x['published_at'])) ?> · </span><?php if ($withBuyer): ?><?= e($x['buyer_name']) ?><?= $x['voivodeship'] ? ' · ' . e(Regions::name($x['voivodeship'])) : '' ?> · <?php endif ?><?= $withTitle && ($showPart || isset($multi[(string) $x['external_id']])) ? 'część ' . (int) $x['part'] . ' · ' : '' ?><?= $x['order_type'] ? e(Tenders::orderTypeLabel($x['order_type'])) . ' · ' : '' ?><?= $x['cpv_main'] ? 'CPV ' . e($x['cpv_main']) : '' ?></div>
        </td>
        <td>
          <?php if ($x['result'] === 'contract' && $x['winner_name']): ?>
            <?php if ($x['winner_nip']): ?><a href="/kontrahenci/<?= e($x['winner_nip']) ?>"><?= e(truncate((string) $x['winner_name'], 90)) ?></a><?php else: ?><?= e(truncate((string) $x['winner_name'], 90)) ?><?php endif ?>
            <div class="t-sub"><?= e((string) $x['winner_city']) ?><?= !empty($det['consortium']) ? ($x['winner_city'] ? ' · ' : '') . 'lider konsorcjum' : '' ?><?= !empty($det['size']) ? ' · ' . e(mb_strtolower((string) $det['size'])) : '' ?></div>
          <?php else: ?>
            <span class="chip neutral"><?= e(Results::LABELS[$x['result']] ?? 'Brak wyniku') ?></span>
            <?php if (!empty($det['cancel_basis'])): ?><div class="t-sub"><?= e($det['cancel_basis']) ?></div><?php endif ?>
          <?php endif ?>
        </td>
        <td class="n">
          <?php if ($value): ?><?= e(money_pl($value, (string) ($x['currency'] ?: 'PLN') === 'PLN' ? 'zł' : (string) $x['currency'])) ?>
          <?php elseif ($offer): ?><?= e(money_pl($offer)) ?><div class="t-sub">cena oferty</div>
          <?php else: ?><span class="muted">-</span><?php endif ?>
          <?php if ($x['est_value'] && ($value || $offer)): ?><div class="t-sub">szacowano <?= e(short_money($x['est_value'])) ?> netto</div><?php endif ?>
        </td>
        <td class="n">
          <?php if ($x['offers'] !== null && $x['offers'] !== ''): ?><?= (int) $x['offers'] ?>
            <?php if ((int) $x['offers'] > 1 && $x['price_lowest'] && $x['price_highest'] && short_money($x['price_lowest']) !== short_money($x['price_highest'])): ?><div class="t-sub nowrap"><?= e(short_money($x['price_lowest'])) ?> - <?= e(short_money($x['price_highest'])) ?></div><?php endif ?>
          <?php else: ?><span class="muted">-</span><?php endif ?>
        </td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</div>
