<?php
$tabs = ['active' => 'Do przejrzenia', 'new' => 'Nowe', 'saved' => 'Zapisane', 'dismissed' => 'Ukryte', 'all' => 'Wszystkie'];
$base = array_filter(['profil' => $profileId ?: null, 'q' => $q ?: null]);
?>
<header class="between page-head">
  <div>
    <p class="crumbs">Przetargi</p>
    <h1>Dopasowane przetargi</h1>
    <p class="muted small"><?= $total ?> <?= plural($total, 'ogłoszenie', 'ogłoszenia', 'ogłoszeń') ?> z otwartym terminem składania ofert</p>
  </div>
  <div class="row-flex">
    <a class="btn ghost" href="/przetargi/szukaj">Przeszukaj wszystkie ogłoszenia</a>
    <a class="btn ghost" href="/przetargi/wyniki">Wyniki przetargów</a>
    <a class="btn ghost" href="/profile">Profile (<?= count($profiles) ?>)</a>
  </div>
</header>

<?php if (!$profiles): ?>
  <div class="card empty"><h3>Najpierw ustaw profil przetargowy</h3><p>Podaj kody CPV albo słowa kluczowe i województwa. Codziennie dobierzemy pasujące ogłoszenia z BZP i TED.</p><a class="btn" href="/profile/nowy">Ustaw profil</a></div>
<?php else: ?>
<section class="card flush">
  <div class="card-head" style="align-items:flex-start">
    <nav class="filters" aria-label="Status">
      <?php foreach ($tabs as $k => $label): ?><a href="?<?= e(http_build_query($base + ($k === 'active' ? [] : ['status' => $k]))) ?>"<?= $status === $k ? ' class="on"' : '' ?>><?= e($label) ?></a><?php endforeach ?>
    </nav>
    <form class="searchbar" method="get" action="/przetargi" style="flex:1 1 320px;justify-content:flex-end">
      <?php if ($status !== 'active'): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif ?>
      <?php if (count($profiles) > 1): ?>
        <label class="sr-only" for="f-profil">Profil</label>
        <select id="f-profil" name="profil" style="flex:0 1 200px">
          <option value="">Wszystkie profile</option>
          <?php foreach ($profiles as $p): ?><option value="<?= (int) $p['id'] ?>"<?= $profileId === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach ?>
        </select>
      <?php endif ?>
      <label class="sr-only" for="f-q">Szukaj w dopasowaniach</label>
      <input id="f-q" type="search" name="q" value="<?= e($q) ?>" placeholder="Szukaj w tytule lub zamawiającym">
      <button class="btn ghost" type="submit">Filtruj</button>
    </form>
  </div>
  <?php if ($rows): ?>
    <?= App\Core\View::partial('partials/tender_table', ['rows' => $rows, 'offers' => App\Modules\Offers\Offers::byTender(App\Core\Auth::orgId()), 'withScore' => true]) ?>
    <?= App\Core\View::partial('partials/pager', ['page' => $page, 'total' => $total, 'per' => App\Http\TenderController::PER_PAGE, 'query' => $base + ($status !== 'active' ? ['status' => $status] : [])]) ?>
  <?php else: ?>
    <div class="empty"><h3>Brak ogłoszeń w tym widoku</h3><p>Zmień filtr albo poszerz profil o kolejne kody CPV i słowa kluczowe.</p></div>
  <?php endif ?>
</section>
<?php endif ?>
