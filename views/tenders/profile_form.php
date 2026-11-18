<?php
use App\Modules\Tenders\Regions;
$sel = array_filter(explode(' ', (string) $p['voivodeships']));
$types = array_filter(explode(' ', (string) $p['order_types']));
$sources = array_filter(explode(' ', (string) ($p['sources'] ?: 'bzp ted')));
$fmt = static fn ($v) => $v === null || $v === '' ? '' : number_format((float) $v, 0, ',', ' ');
?>
<header class="page-head">
  <p class="crumbs"><a href="/przetargi">Przetargi</a> / <a href="/profile">Profile</a> / <?= $p['id'] ? e($p['name']) : 'Nowy' ?></p>
  <h1><?= $p['id'] ? 'Edycja profilu' : 'Nowy profil przetargowy' ?></h1>
  <p class="muted small">Ogłoszenie trafia do dopasowań, gdy pasuje kod CPV lub słowo kluczowe i spełnia pozostałe warunki.</p>
</header>
<form method="post" action="<?= $p['id'] ? '/profile/' . (int) $p['id'] : '/profile' ?>" class="stack">
  <?= csrf_field() ?>
  <section class="card stack-sm">
    <div class="field"><label for="name">Nazwa profilu</label><input id="name" name="name" type="text" required maxlength="120" value="<?= e($p['name']) ?>" placeholder="np. Roboty drogowe – Mazowsze"></div>
    <div class="field"><label for="cpv">Kody CPV</label><input id="cpv" name="cpv" type="text" value="<?= e($p['cpv']) ?>" placeholder="np. 45233000 45112500"><span class="hint">Kilka kodów rozdziel spacją. Krótszy kod obejmuje całą grupę: 4523 to wszystkie roboty drogowe.</span></div>
    <div class="field"><label for="keywords">Słowa kluczowe</label><textarea id="keywords" name="keywords" rows="4" placeholder="np.&#10;nawierzchnia&#10;przebudowa drogi&#10;chodnik"><?= e($p['keywords']) ?></textarea><span class="hint">Po jednym w linii. Szukamy w tytule i opisie przedmiotu zamówienia. Wystarczy początek słowa: „remont” znajdzie też „remontu”.</span></div>
    <div class="field"><label for="exclude_keywords">Słowa wykluczające</label><textarea id="exclude_keywords" name="exclude_keywords" rows="2" placeholder="np. odśnieżanie"><?= e($p['exclude_keywords']) ?></textarea><span class="hint">Ogłoszenia z tymi słowami pominiemy.</span></div>
  </section>
  <section class="card stack-sm">
    <fieldset style="border:0;padding:0;margin:0">
      <legend class="label">Województwa</legend>
      <p class="hint muted small" style="margin:4px 0 8px">Nie zaznaczaj żadnego, jeśli pracujesz w całej Polsce.</p>
      <div class="form-grid" style="gap:0 16px">
        <?php foreach (Regions::ALL as $slug => $r): ?><label class="check"><input type="checkbox" name="woj[]" value="<?= e($slug) ?>"<?= in_array($slug, $sel, true) ? ' checked' : '' ?>> <?= e($r[0]) ?></label><?php endforeach ?>
      </div>
    </fieldset>
    <fieldset style="border:0;padding:0;margin:0">
      <legend class="label">Rodzaj zamówienia</legend>
      <div class="row-flex" style="gap:0 24px">
        <label class="check"><input type="checkbox" name="rodzaj[]" value="works"<?= in_array('works', $types, true) ? ' checked' : '' ?>> Roboty budowlane</label>
        <label class="check"><input type="checkbox" name="rodzaj[]" value="services"<?= in_array('services', $types, true) ? ' checked' : '' ?>> Usługi</label>
        <label class="check"><input type="checkbox" name="rodzaj[]" value="supplies"<?= in_array('supplies', $types, true) ? ' checked' : '' ?>> Dostawy</label>
      </div>
    </fieldset>
    <div class="form-grid">
      <div class="field"><label for="min_value">Wartość od (zł)</label><input id="min_value" name="min_value" type="text" inputmode="numeric" value="<?= e($fmt($p['min_value'])) ?>"></div>
      <div class="field"><label for="max_value">Wartość do (zł)</label><input id="max_value" name="max_value" type="text" inputmode="numeric" value="<?= e($fmt($p['max_value'])) ?>"><span class="hint">Wiele ogłoszeń nie podaje wartości – tych nie odrzucamy.</span></div>
    </div>
    <fieldset style="border:0;padding:0;margin:0">
      <legend class="label">Źródła</legend>
      <div class="row-flex" style="gap:0 24px">
        <label class="check"><input type="checkbox" name="zrodla[]" value="bzp"<?= in_array('bzp', $sources, true) ? ' checked' : '' ?>> Biuletyn Zamówień Publicznych (krajowe)</label>
        <label class="check"><input type="checkbox" name="zrodla[]" value="ted"<?= in_array('ted', $sources, true) ? ' checked' : '' ?>> TED (powyżej progów unijnych)</label>
      </div>
    </fieldset>
    <label class="check"><input type="checkbox" name="active" value="1"<?= $p['active'] ? ' checked' : '' ?>> Profil aktywny – dobieraj codziennie nowe ogłoszenia</label>
  </section>
  <div class="row-flex">
    <button class="btn" type="submit">Zapisz profil</button>
    <a class="btn ghost" href="/profile">Anuluj</a>
  </div>
</form>
<?php if ($p['id']): ?>
<form method="post" action="/profile/<?= (int) $p['id'] ?>/usun" data-confirm="Usunąć profil i jego dopasowania?"><?= csrf_field() ?><button class="btn danger sm" type="submit">Usuń profil</button></form>
<?php endif ?>
