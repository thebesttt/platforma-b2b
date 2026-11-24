<?php
use App\Modules\Tenders\Regions;
$query = array_filter(['q' => $q ?: null, 'woj' => $region ?: null, 'rodzaj' => $type ?: null, 'cpv' => $cpv ?: null]);
?>
<header class="page-head">
  <p class="crumbs"><a href="/przetargi">Przetargi</a> / Wyszukiwarka</p>
  <h1>Wszystkie ogłoszenia</h1>
  <p class="muted small">W bazie: <?= $count ?> <?= plural($count, 'ogłoszenie', 'ogłoszenia', 'ogłoszeń') ?> z BZP i TED. Pokazujemy tylko te z otwartym terminem.</p>
</header>
<section class="card">
  <form method="get" action="/przetargi/szukaj" class="form-grid">
    <div class="field" style="grid-column:1/-1"><label for="s-q">Szukane słowa</label><input id="s-q" type="search" name="q" value="<?= e($q) ?>" placeholder="np. remont drogi, dostawa komputerów"></div>
    <div class="field"><label for="s-woj">Województwo</label><select id="s-woj" name="woj"><option value="">Cała Polska</option><?php foreach (Regions::ALL as $slug => $r): ?><option value="<?= e($slug) ?>"<?= $region === $slug ? ' selected' : '' ?>><?= e($r[0]) ?></option><?php endforeach ?></select></div>
    <div class="field"><label for="s-rodzaj">Rodzaj zamówienia</label><select id="s-rodzaj" name="rodzaj"><option value="">Wszystkie</option><option value="works"<?= $type === 'works' ? ' selected' : '' ?>>Roboty budowlane</option><option value="services"<?= $type === 'services' ? ' selected' : '' ?>>Usługi</option><option value="supplies"<?= $type === 'supplies' ? ' selected' : '' ?>>Dostawy</option></select></div>
    <div class="field"><label for="s-cpv">Kod CPV</label><input id="s-cpv" type="text" name="cpv" inputmode="numeric" value="<?= e($cpv) ?>" placeholder="np. 45233"></div>
    <div class="field" style="justify-content:flex-end"><button class="btn" type="submit">Szukaj</button></div>
  </form>
</section>
<section class="card flush">
  <div class="card-head"><h2><?= $total ?> <?= plural($total, 'wynik', 'wyniki', 'wyników') ?></h2></div>
  <?php if ($rows): ?>
    <?= App\Core\View::partial('partials/tender_table', ['rows' => $rows, 'offers' => App\Modules\Offers\Offers::byTender(App\Core\Auth::orgId()), 'withScore' => false]) ?>
    <?= App\Core\View::partial('partials/pager', ['page' => $page, 'total' => $total, 'per' => App\Http\TenderController::PER_PAGE, 'query' => $query]) ?>
  <?php else: ?>
    <div class="empty"><h3>Nic nie znaleźliśmy</h3><p>Spróbuj krótszego słowa albo usuń filtr województwa.</p></div>
  <?php endif ?>
</section>
