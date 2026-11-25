<?php
use App\Modules\Outreach\EuProjects;
use App\Modules\Tenders\Regions;
/** @var array $rows @var int $total @var int $page @var array $f @var array<string, bool> $known @var array $coverage */
$query = array_filter(['q' => $f['q'] ?: null, 'woj' => $f['woj'], 'stan' => $f['stan'] !== 'active' ? $f['stan'] : null, 'min' => $f['min'] ?: null], static fn ($v) => $v !== null);
$query['woj'] = $f['woj']; // pusty wybór („cała Polska”) też zapamiętujemy, żeby nie wracał region z kryteriów
?>
<header class="between page-head">
  <div>
    <p class="crumbs"><a href="/klienci">Klienci</a> / <a href="/klienci/potencjalni">Potencjalni klienci</a> / Firmy z dotacjami UE</p>
    <h1>Firmy z dotacjami UE</h1>
    <p class="muted small" style="max-width:70ch">Spółki, które realizują projekty z funduszy europejskich 2021-2027. Firma w trakcie projektu ma budżet na zakupy, a większe zakupy w projekcie zwykle ogłasza w Bazie Konkurencyjności. Dane z listy projektów na funduszeeuropejskie.gov.pl, aktualizowanej co miesiąc.</p>
  </div>
  <div class="row-flex">
    <a class="btn ghost" href="/klienci/potencjalni">Potencjalni klienci</a>
    <a class="btn ghost" href="<?= e(EuProjects::COMPETITION_URL) ?>" target="_blank" rel="noopener">Baza Konkurencyjności</a>
  </div>
</header>

<?php if ($coverage['projects'] === 0): ?>
  <div class="notice info" style="margin-bottom:16px"><p><b>Listy projektów jeszcze nie pobraliśmy.</b> Pobiera ją codzienne zadanie, kiedy na stronie funduszy pojawi się nowy plik (raz w miesiącu). Plik ma ok. 150 MB, więc import trwa kilka minut.</p></div>
<?php endif ?>

<section class="card">
  <form method="get" action="/klienci/dotacje-ue" class="form-grid">
    <div class="field" style="grid-column:1/-1"><label for="eu-q">Czego szukasz w projektach</label><input id="eu-q" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="np. cyfryzacja, sklep internetowy, automatyzacja, fotowoltaika"></div>
    <div class="field"><label for="eu-woj">Województwo</label><select id="eu-woj" name="woj"><option value="">Cała Polska</option><?php foreach (Regions::ALL as $slug => $r): ?><option value="<?= e($slug) ?>"<?= $f['woj'] === $slug ? ' selected' : '' ?>><?= e($r[0]) ?></option><?php endforeach ?></select></div>
    <div class="field"><label for="eu-stan">Projekty</label><select id="eu-stan" name="stan">
      <option value="active">W trakcie</option>
      <option value="new"<?= $f['stan'] === 'new' ? ' selected' : '' ?>>Rozpoczęte w ostatnich 6 miesiącach</option>
      <option value="all"<?= $f['stan'] === 'all' ? ' selected' : '' ?>>Także zakończone niedawno</option>
    </select></div>
    <div class="field"><label for="eu-min">Wartość projektu</label><select id="eu-min" name="min">
      <option value="">Dowolna</option>
      <?php foreach ([100000 => 'od 100 tys. zł', 500000 => 'od 500 tys. zł', 1000000 => 'od 1 mln zł', 5000000 => 'od 5 mln zł'] as $v => $label): ?><option value="<?= $v ?>"<?= (int) $f['min'] === $v ? ' selected' : '' ?>><?= $label ?></option><?php endforeach ?>
    </select></div>
    <div class="field" style="justify-content:flex-end"><button class="btn" type="submit">Szukaj</button></div>
  </form>
</section>

<section class="card flush">
  <form method="post" action="/klienci/dotacje-ue/dodaj">
    <?= csrf_field() ?>
    <div class="card-head" style="flex-wrap:wrap;gap:12px">
      <h2><?= $total ?> <?= plural($total, 'projekt', 'projekty', 'projektów') ?></h2>
      <?php if ($rows): ?><button class="btn" type="submit">Dodaj zaznaczone do potencjalnych klientów</button><?php endif ?>
    </div>
    <?php if ($rows): ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th scope="col"><span class="sr-only">Zaznacz</span></th><th scope="col">Firma i projekt</th><th scope="col" class="n">Wartość</th><th scope="col">Realizacja</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $p): $in = isset($known[$p['beneficiary_key']]); $regions = array_filter(explode(' ', (string) $p['voivodeships'])); ?>
          <tr>
            <td><?php if ($in): ?><span class="chip low">na liście</span><?php else: ?><label class="sr-only" for="eu-<?= (int) $p['id'] ?>">Dodaj <?= e($p['beneficiary']) ?></label><input id="eu-<?= (int) $p['id'] ?>" type="checkbox" name="ids[]" value="<?= (int) $p['id'] ?>"><?php endif ?></td>
            <td>
              <b><?= e($p['beneficiary']) ?></b>
              <div><?= e(truncate((string) $p['title'], 180)) ?></div>
              <div class="t-sub"><?= e(truncate((string) ($p['program'] ?? ''), 90)) ?><?= $regions ? ' · ' . e(implode(', ', array_map([Regions::class, 'name'], $regions))) : ((string) $p['place'] !== '' ? ' · ' . e(truncate((string) $p['place'], 40)) : '') ?></div>
              <?php if (!empty($p['summary'])): ?><details class="t-sub"><summary>Opis projektu</summary><p><?= e((string) $p['summary']) ?></p></details><?php endif ?>
            </td>
            <td class="n nowrap"><?= $p['value_total'] !== null ? e(short_money($p['value_total'])) : '<span class="muted">-</span>' ?><?php if ($p['eu_amount'] !== null): ?><div class="t-sub">UE: <?= e(short_money($p['eu_amount'])) ?></div><?php endif ?></td>
            <td class="nowrap"><?= $p['start_date'] ? e(date('m.Y', strtotime((string) $p['start_date']))) : '?' ?> - <?= $p['end_date'] ? e(date('m.Y', strtotime((string) $p['end_date']))) : '?' ?></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
      <div class="empty"><h3>Brak projektów w tym widoku</h3><p>Usuń część słów albo filtr województwa. Szukamy w tytułach, opisach i działaniach programów.</p></div>
    <?php endif ?>
  </form>
  <?= App\Core\View::partial('partials/pager', ['page' => $page, 'total' => $total, 'per' => EuProjects::PER_PAGE, 'query' => $query]) ?>
</section>

<section class="card">
  <h2>Jak z tego korzystać</h2>
  <p class="muted" style="max-width:70ch">Zaznacz firmy, które pasują do Twojej oferty, i dodaj je do potencjalnych klientów. Lista projektów nie podaje NIP-u, więc NIP i stronę firmy dopiszesz na jej karcie. Panel nie zbiera adresów e-mail z tej listy: do firmy możesz napisać list albo odpowiedzieć na jej zapytanie ofertowe w Bazie Konkurencyjności.</p>
  <p class="muted small">W bazie: <?= (int) $coverage['projects'] ?> <?= plural((int) $coverage['projects'], 'projekt', 'projekty', 'projektów') ?> <?= (int) $coverage['firms'] ?> <?= plural((int) $coverage['firms'], 'firmy', 'firm', 'firm') ?><?= $coverage['at'] !== '' ? '. Ostatni import: ' . e(local_dt($coverage['at'], 'd.m.Y')) : '' ?>.</p>
</section>
