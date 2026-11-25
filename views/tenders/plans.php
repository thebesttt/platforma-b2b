<?php
use App\Modules\Tenders\Plans;
use App\Modules\Tenders\Regions;
/** @var array $rows @var int $total @var int $page @var string $mode @var string $when @var string $q @var array $profiles @var array $coverage */
/** @var string $status @var int $profileId @var string $region @var string $cat */
$year = (int) gmdate('Y');
$nowQuarter = intdiv((int) gmdate('n') - 1, 3) + 1;
$query = array_filter([
    'widok' => $mode === 'all' && $profiles ? 'wszystkie' : null,
    'kiedy' => $when === 'all' ? 'caly-rok' : null,
    'q' => $q ?: null,
    'profil' => $profileId ?: null,
    'woj' => $region ?: null,
    'rodzaj' => $cat ?: null,
]);
$tabs = ['active' => 'Do przejrzenia', 'new' => 'Nowe', 'saved' => 'Zapisane', 'dismissed' => 'Ukryte'];
$last = is_array($coverage['last'] ?? null) ? $coverage['last'] : null;
?>
<header class="between page-head">
  <div>
    <p class="crumbs"><a href="/przetargi">Przetargi</a> / Plany zamówień</p>
    <h1>Plany zamówień</h1>
    <p class="muted small" style="max-width:68ch">Zamówienia, które zamawiający zapowiedzieli w planach postępowań w BZP i we wstępnych ogłoszeniach w TED. Przetargu jeszcze nie ma, więc jest czas, żeby przygotować ofertę i referencje.</p>
  </div>
  <div class="row-flex">
    <a class="btn ghost" href="/przetargi">Dopasowane przetargi</a>
    <a class="btn ghost" href="/profile">Profile (<?= count($profiles) ?>)</a>
  </div>
</header>

<?php if ($coverage['plans'] === 0): ?>
  <div class="notice info" style="margin-bottom:16px"><p><b>Plany pobieramy raz dziennie.</b> Pierwszy import dociąga plany od 1 stycznia w kilku porcjach, więc lista zapełni się w ciągu paru dni. Większość planów zamawiający publikują w styczniu i lutym.</p></div>
<?php endif ?>

<?php if ($profiles): ?>
<nav class="filters" aria-label="Widok planów">
  <a href="/przetargi/plany"<?= $mode === 'matches' ? ' class="on" aria-current="page"' : '' ?>>Dopasowane do profili</a>
  <a href="/przetargi/plany?widok=wszystkie"<?= $mode === 'all' ? ' class="on" aria-current="page"' : '' ?>>Wszystkie plany</a>
</nav>
<?php else: ?>
  <div class="notice info" style="margin-bottom:16px"><p>Ustaw <a href="/profile/nowy">profil przetargowy</a> ze słowami kluczowymi i województwami, a panel sam wybierze pozycje planów, które pasują do Twojej firmy. Plany nie mają kodów CPV, więc liczą się słowa kluczowe.</p></div>
<?php endif ?>

<section class="card flush">
  <div class="card-head" style="align-items:flex-start;flex-wrap:wrap;gap:12px">
    <?php if ($mode === 'matches'): ?>
      <nav class="filters" aria-label="Status">
        <?php foreach ($tabs as $k => $label): ?><a href="?<?= e(http_build_query(array_diff_key($query, ['status' => 1]) + ($k === 'active' ? [] : ['status' => $k]))) ?>"<?= $status === $k ? ' class="on"' : '' ?>><?= e($label) ?></a><?php endforeach ?>
      </nav>
    <?php else: ?>
      <h2><?= $total ?> <?= plural($total, 'pozycja', 'pozycje', 'pozycji') ?></h2>
    <?php endif ?>
    <form class="searchbar" method="get" action="/przetargi/plany" style="flex:1 1 360px;justify-content:flex-end;flex-wrap:wrap">
      <?php if ($mode === 'all' && $profiles): ?><input type="hidden" name="widok" value="wszystkie"><?php endif ?>
      <?php if ($mode === 'matches' && $status !== 'active'): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif ?>
      <?php if ($mode === 'matches' && count($profiles) > 1): ?>
        <label class="sr-only" for="pl-profil">Profil</label>
        <select id="pl-profil" name="profil" style="flex:0 1 190px">
          <option value="">Wszystkie profile</option>
          <?php foreach ($profiles as $p): ?><option value="<?= (int) $p['id'] ?>"<?= $profileId === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach ?>
        </select>
      <?php endif ?>
      <?php if ($mode === 'all'): ?>
        <label class="sr-only" for="pl-woj">Województwo</label>
        <select id="pl-woj" name="woj" style="flex:0 1 180px"><option value="">Cała Polska</option><?php foreach (Regions::ALL as $slug => $name): ?><option value="<?= e($slug) ?>"<?= $region === $slug ? ' selected' : '' ?>><?= e($name[0]) ?></option><?php endforeach ?></select>
        <label class="sr-only" for="pl-rodzaj">Rodzaj zamówienia</label>
        <select id="pl-rodzaj" name="rodzaj" style="flex:0 1 160px"><option value="">Wszystkie rodzaje</option><?php foreach (Plans::CATEGORIES as $k => $label): ?><option value="<?= $k ?>"<?= $cat === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select>
      <?php endif ?>
      <label class="sr-only" for="pl-kiedy">Termin</label>
      <select id="pl-kiedy" name="kiedy" style="flex:0 1 190px">
        <option value="">Od bieżącego kwartału</option>
        <option value="caly-rok"<?= $when === 'all' ? ' selected' : '' ?>>Cały rok</option>
      </select>
      <label class="sr-only" for="pl-q">Szukaj w planach</label>
      <input id="pl-q" type="search" name="q" value="<?= e($q) ?>" placeholder="np. remont drogi, oprogramowanie">
      <button class="btn ghost" type="submit">Filtruj</button>
    </form>
  </div>
  <?php if ($rows): ?>
  <div class="table-wrap">
    <table>
      <thead><tr><?php if ($mode === 'matches'): ?><th scope="col">Ocena</th><?php endif ?><th scope="col">Przedmiot i zamawiający</th><th scope="col" class="n">Wartość</th><th scope="col">Wszczęcie</th><th scope="col"><span class="sr-only">Działania</span></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $it):
          $past = (int) ($it['year'] ?? 0) === $year && !empty($it['quarter']) && (int) $it['quarter'] < $nowQuarter;
          $reasons = json_in($it['reasons_json'] ?? null);
      ?>
        <tr>
          <?php if ($mode === 'matches'): ?><td><span class="score <?= score_class((int) $it['score']) ?>"><?= (int) $it['score'] ?></span></td><?php endif ?>
          <td>
            <a class="t-title" href="<?= e($it['url']) ?>" target="_blank" rel="noopener"><?= e(truncate((string) $it['subject'], 200)) ?></a>
            <?php if (($it['match_status'] ?? '') === 'saved'): ?> <span class="chip low">zapisana</span><?php elseif (($it['match_status'] ?? '') === 'new'): ?> <span class="chip info">nowa</span><?php endif ?>
            <?php if ((int) ($it['sme'] ?? 0) === 1): ?> <span class="chip neutral">dla MŚP</span><?php endif ?>
            <?php if (($it['section'] ?? '') === 'above'): ?> <span class="chip neutral">od progów UE</span><?php endif ?>
            <div class="t-sub"><?= e((string) $it['buyer_name']) ?><?= $it['buyer_city'] ? ', ' . e($it['buyer_city']) : '' ?><?= $it['voivodeship'] ? ' · ' . e(Regions::name($it['voivodeship'])) : '' ?> · <?= $it['source'] === 'ted' ? 'zapowiedź w TED' : 'plan w BZP, poz. ' . e($it['position']) ?><?= isset(Plans::CATEGORIES[$it['category'] ?? '']) ? ' · ' . e(mb_strtolower(Plans::CATEGORIES[$it['category']])) : '' ?></div>
            <?php if (!empty($it['mode'])): ?><div class="t-sub"><?= e(truncate((string) $it['mode'], 120)) ?></div><?php endif ?>
            <?php if ($reasons): ?><div class="t-sub">Dlaczego: <?= e(implode(' · ', array_slice($reasons, 0, 4))) ?></div><?php endif ?>
          </td>
          <td class="n nowrap"><?= $it['value_estimated'] !== null && $it['value_estimated'] !== '' ? e(short_money($it['value_estimated'])) . (($it['currency'] ?? 'PLN') !== 'PLN' && $it['currency'] ? ' ' . e($it['currency']) : '') : '<span class="muted">-</span>' ?></td>
          <td class="nowrap"><?= e(Plans::termLabel($it)) ?><?php if ($past): ?><div class="t-sub"><span class="chip mid">termin minął</span></div><?php endif ?></td>
          <td class="nowrap">
            <?php if ($mode === 'matches'): ?>
              <?php foreach (($it['match_status'] ?? '') === 'dismissed' ? ['new' => 'Przywróć'] : (($it['match_status'] ?? '') === 'saved' ? ['dismissed' => 'Ukryj'] : ['saved' => 'Zapisz', 'dismissed' => 'Ukryj']) as $st => $label): ?>
                <form method="post" action="/przetargi/plany/<?= (int) $it['id'] ?>/status" style="display:inline"><?= csrf_field() ?><input type="hidden" name="status" value="<?= $st ?>"><button class="btn <?= $st === 'saved' ? '' : 'ghost ' ?>sm" type="submit"><?= $label ?></button></form>
              <?php endforeach ?>
            <?php endif ?>
            <?php if (!empty($it['buyer_nip'])): ?><a class="btn ghost sm" href="/kontrahenci/<?= e($it['buyer_nip']) ?>">Zamawiający</a><?php endif ?>
          </td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
  <?= App\Core\View::partial('partials/pager', ['page' => $page, 'total' => $total, 'per' => Plans::PER_PAGE, 'query' => $query + ($mode === 'matches' && $status !== 'active' ? ['status' => $status] : [])]) ?>
  <?php else: ?>
    <div class="empty">
      <?php if ($mode === 'matches'): ?>
        <h3>Brak pozycji planów w tym widoku</h3><p>Dodaj do profilu słowa, którymi zamawiający opisują Twoje usługi (np. „remont”, „oprogramowanie”, „sprzątanie”), albo przejdź do <a href="/przetargi/plany?widok=wszystkie">wszystkich planów</a>.</p>
      <?php else: ?>
        <h3>Nic nie znaleźliśmy</h3><p>Spróbuj krótszego słowa, wybierz „Cały rok” albo usuń filtr województwa.</p>
      <?php endif ?>
    </div>
  <?php endif ?>
</section>

<section class="card">
  <h2>Co zrobić z pozycją z planu</h2>
  <p class="muted" style="max-width:70ch">Zapisz ją i wróć przed terminem wszczęcia. Sprawdź zamawiającego i jego wcześniejsze przetargi w zakładce „Wyniki przetargów”. Zamawiający często robi przed przetargiem rozeznanie rynku i prosi o wycenę orientacyjną, więc warto zawczasu przygotować referencje i opis podobnych realizacji. Plan nie zobowiązuje zamawiającego do ogłoszenia przetargu.</p>
  <p class="muted small">W bazie: <?= (int) $coverage['plans'] ?> <?= plural((int) $coverage['plans'], 'plan', 'plany', 'planów') ?> i <?= (int) $coverage['items'] ?> <?= plural((int) $coverage['items'], 'pozycja', 'pozycje', 'pozycji') ?> na <?= $year ?> rok<?= $last && !empty($last['at']) ? '. Ostatni import: ' . e(local_dt((string) $last['at'], 'd.m.Y H:i')) : '' ?>.</p>
</section>
