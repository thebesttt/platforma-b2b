<?php
use App\Core\View;
use App\Modules\Seo\MonthlyReport;

/** @var array $site @var array $reports @var string[] $months @var string $default @var array $brand */
$base = '/audyt/widocznosc/' . (int) $site['id'];
$have = array_column($reports, null, 'month');
echo View::partial('partials/seo_nav', ['site' => $site, 'tab' => $tab, 'sites' => $sites, 'heading' => 'Raporty dla klienta',
    'lead' => 'Raz w miesiącu jedna strona z wynikami ' . $site['host'] . ': Google, odpowiedzi AI, pomiar, audyty i to, co zrobiliście. Klient otwiera ją linkiem bez logowania.']);
?>
<section class="card" aria-labelledby="rp-new">
  <h2 id="rp-new" style="margin-bottom:6px">Nowy raport</h2>
  <p class="small muted" style="margin:0 0 12px">Szkic za poprzedni miesiąc powstaje sam 4. dnia miesiąca, gdy Search Console ma już komplet dni, i pojawia się w powiadomieniach. Możesz też przygotować go teraz.</p>
  <form method="post" action="<?= e($base) ?>/raporty" class="searchbar">
    <?= csrf_field() ?>
    <label class="sr-only" for="rp-month">Miesiąc</label>
    <select id="rp-month" name="miesiac">
      <?php foreach ($months as $m): ?><option value="<?= e($m) ?>"<?= $m === $default ? ' selected' : '' ?>><?= e(ucfirst(MonthlyReport::label($m))) ?><?= isset($have[$m]) ? ' (jest)' : '' ?></option><?php endforeach ?>
    </select>
    <button class="btn" type="submit" data-busy="Przygotowuję…">Przygotuj raport</button>
  </form>
</section>

<section class="card flush" aria-labelledby="rp-list">
  <div class="card-head"><h2 id="rp-list">Raporty</h2></div>
  <?php if (!$reports): ?>
    <p class="muted small" style="padding:0 20px 18px">Nie ma jeszcze raportów dla tej witryny.</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th scope="col">Miesiąc</th><th scope="col">Stan</th><th scope="col">Ostatnia zmiana</th></tr></thead>
    <tbody>
    <?php foreach ($reports as $rp): ?>
      <tr><td><a href="<?= e($base) ?>/raporty/<?= (int) $rp['id'] ?>"><b><?= e(ucfirst(MonthlyReport::label((string) $rp['month']))) ?></b></a></td>
        <td><?= $rp['token'] ? '<span class="chip low">udostępniony klientowi</span>' : '<span class="chip neutral">szkic</span>' ?><?= $rp['created_by'] === null ? ' <span class="small muted">przygotowany automatycznie</span>' : '' ?></td>
        <td class="small"><?= e(local_dt((string) $rp['updated_at'])) ?></td></tr>
    <?php endforeach ?>
    </tbody>
  </table></div>
  <?php endif ?>
</section>

<section class="card" id="wyglad" aria-labelledby="rp-brand">
  <h2 id="rp-brand" style="margin-bottom:6px">Wygląd raportów</h2>
  <p class="small muted" style="margin:0 0 12px">Logo, kolor i stopka agencji. Ustawienie dotyczy wszystkich raportów firmy. Bez logo raport pokazuje nazwę firmy: <?= e($brand['name']) ?>.</p>
  <form method="post" action="<?= e($base) ?>/raporty/wyglad" enctype="multipart/form-data" class="form-grid">
    <?= csrf_field() ?>
    <div class="field"><label for="rp-logo">Logo (PNG, JPEG, WebP albo SVG, do 200 KB)</label><input id="rp-logo" type="file" name="logo" accept=".png,.jpg,.jpeg,.webp,.svg,image/png,image/jpeg,image/webp,image/svg+xml"></div>
    <div class="field"><label for="rp-color">Kolor akcentu</label><input id="rp-color" type="color" name="color" value="<?= e($brand['color']) ?>"></div>
    <div class="field" style="flex-basis:100%"><label for="rp-foot">Stopka (np. kontakt do opiekuna)</label><input id="rp-foot" type="text" name="footer" maxlength="200" value="<?= e($brand['footer']) ?>" placeholder="Opiekun: Anna Nowak, tel. 600 000 000"></div>
    <?php if ($brand['logo']): ?><div class="field"><span class="small">Obecne logo:</span><img src="<?= e($brand['logo']) ?>" alt="Logo w raportach" style="max-height:40px;max-width:180px"><label class="small"><input type="checkbox" name="usun_logo" value="1"> usuń logo</label></div><?php endif ?>
    <div class="field" style="justify-content:flex-end"><button class="btn ghost" type="submit">Zapisz wygląd</button></div>
  </form>
</section>
