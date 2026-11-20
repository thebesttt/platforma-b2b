<?php
use App\Modules\Tenders\Regions;
use App\Modules\Tenders\Results;
/**
 * @var array $rows @var int $total @var int $page @var array $cards @var array $ranking @var array $totals @var array $coverage
 * @var array $profiles @var int $profileId @var int $days @var string $q @var string $region @var string $cpv
 */
$query = array_filter(['profil' => (string) $profileId, 'dni' => $days !== 90 ? $days : null, 'q' => $q ?: null, 'woj' => $region ?: null, 'cpv' => $cpv ?: null], static fn ($v) => $v !== null);
$decided = $totals['contract'] + $totals['cancelled'] + $totals['unresolved'];
?>
<header class="between page-head">
  <div>
    <p class="crumbs"><a href="/przetargi">Przetargi</a> / Wyniki</p>
    <h1>Kto wygrywa i za ile</h1>
    <p class="muted small">
      <?php if ($coverage['count']): ?>W bazie: <?= $coverage['count'] ?> <?= plural($coverage['count'], 'rozstrzygnięcie', 'rozstrzygnięcia', 'rozstrzygnięć') ?> z BZP od <?= e(local_date($coverage['first_at'])) ?>. Jedna pozycja to jedna część zamówienia.
      <?php else: ?>Wyniki pobieramy z BZP raz dziennie, razem z nowymi ogłoszeniami.<?php endif ?>
    </p>
  </div>
  <a class="btn ghost" href="/przetargi">Dopasowane przetargi</a>
</header>

<section class="card">
  <form method="get" action="/przetargi/wyniki" class="form-grid">
    <div class="field"><label for="w-profil">Branża i region</label>
      <select id="w-profil" name="profil">
        <option value="0">Wszystkie branże</option>
        <?php foreach ($profiles as $p): ?><option value="<?= (int) $p['id'] ?>"<?= $profileId === (int) $p['id'] ? ' selected' : '' ?>>Profil: <?= e($p['name']) ?></option><?php endforeach ?>
      </select></div>
    <div class="field"><label for="w-dni">Okres</label>
      <select id="w-dni" name="dni"><?php foreach (Results::PERIODS as $n => $label): ?><option value="<?= $n ?>"<?= $days === $n ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></div>
    <div class="field" style="grid-column:1/-1"><label for="w-q">Szukane słowa</label><input id="w-q" type="search" name="q" value="<?= e($q) ?>" placeholder="przedmiot, zamawiający albo wykonawca"></div>
    <div class="field"><label for="w-woj">Województwo zamawiającego</label><select id="w-woj" name="woj"><option value="">Cała Polska</option><?php foreach (Regions::ALL as $slug => $r): ?><option value="<?= e($slug) ?>"<?= $region === $slug ? ' selected' : '' ?>><?= e($r[0]) ?></option><?php endforeach ?></select></div>
    <div class="field"><label for="w-cpv">Kod CPV</label><input id="w-cpv" type="text" name="cpv" inputmode="numeric" value="<?= e($cpv) ?>" placeholder="np. 45233"></div>
    <div class="field" style="justify-content:flex-end"><button class="btn" type="submit">Pokaż wyniki</button></div>
  </form>
</section>

<?php if (!$coverage['count']): ?>
  <div class="card empty"><h3>Nie ma jeszcze wyników</h3><p>Pierwszy import obejmie ostatnie <?= Results::FIRST_DAYS ?> dni. Kolejne dni dochodzą codziennie, więc zestawienie zwycięzców jest tym pełniejsze, im dłużej działa panel.</p></div>
<?php elseif (!$decided): ?>
  <div class="card empty"><h3>Brak rozstrzygnięć przy tych filtrach</h3><p>Wydłuż okres albo wybierz „Wszystkie branże”.</p></div>
<?php else: ?>
<section class="grid-stats" aria-label="Podsumowanie wyników">
  <div class="card"><div class="stat-label">Zawarte umowy</div><div class="stat-value"><?= $totals['contract'] ?></div><div class="stat-note">części zamówień</div></div>
  <div class="card"><div class="stat-label">Unieważnione</div><div class="stat-value"><?= $totals['cancelled'] ?></div><div class="stat-note"><?= round(100 * $totals['cancelled'] / $decided) ?>% części</div></div>
  <div class="card"><div class="stat-label">Mediana liczby ofert</div><div class="stat-value"><?= $totals['median_offers'] !== null ? e(str_replace('.', ',', (string) $totals['median_offers'])) : '-' ?></div><div class="stat-note">z <?= $totals['with_details'] ?> <?= plural($totals['with_details'], 'odczytanego ogłoszenia', 'odczytanych ogłoszeń', 'odczytanych ogłoszeń') ?></div></div>
</section>

<?php if ($ranking): ?>
<section class="card flush" aria-labelledby="w-rank">
  <div class="card-head"><h2 id="w-rank">Najczęściej wygrywają</h2><span class="muted xs">Według liczby umów w wybranym okresie</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th scope="col">Wykonawca</th><th scope="col" class="n">Umowy</th><th scope="col" class="n">Suma umów</th><th scope="col" class="n">Średnio ofert</th><th scope="col">Ostatnia wygrana</th></tr></thead>
      <tbody>
      <?php foreach ($ranking as $w): ?>
        <tr>
          <td><?php if ($w['winner_nip']): ?><a class="t-title" href="/kontrahenci/<?= e($w['winner_nip']) ?>"><?= e(truncate((string) $w['winner_name'], 90)) ?></a><?php else: ?><span class="t-title"><?= e(truncate((string) $w['winner_name'], 90)) ?></span><?php endif ?>
            <div class="t-sub"><?= e((string) $w['winner_city']) ?><?= $w['winner_nip'] ? ($w['winner_city'] ? ' · ' : '') . 'NIP ' . e($w['winner_nip']) : '' ?></div></td>
          <td class="n"><?= (int) $w['wins'] ?></td>
          <td class="n"><?= (int) $w['valued'] ? e(short_money($w['total'])) : '<span class="muted">-</span>' ?><?php if ((int) $w['valued'] && (int) $w['valued'] < (int) $w['wins']): ?><div class="t-sub">z <?= (int) $w['valued'] ?> umów</div><?php endif ?></td>
          <td class="n"><?= $w['avg_offers'] !== null ? e(str_replace('.', ',', (string) round((float) $w['avg_offers'], 1))) : '<span class="muted">-</span>' ?></td>
          <td class="nowrap"><?= e(local_date($w['last_at'])) ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif ?>

<section class="card flush" aria-labelledby="w-list">
  <div class="card-head"><h2 id="w-list"><?= $total ?> <?= plural($total, 'rozstrzygnięcie', 'rozstrzygnięcia', 'rozstrzygnięć') ?></h2><span class="muted xs">Kwoty brutto z ogłoszeń o wyniku. Kreska: treść ogłoszenia czeka na odczyt.</span></div>
  <?= App\Core\View::partial('partials/result_rows', ['rows' => $rows, 'cards' => $cards]) ?>
  <?= App\Core\View::partial('partials/pager', ['page' => $page, 'total' => $total, 'per' => Results::PER_PAGE, 'query' => $query]) ?>
</section>
<?php endif ?>
