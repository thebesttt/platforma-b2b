<?php
use App\Modules\Offers\Offers;
$e = $item ?? ['id' => 0, 'kind' => $kind, 'title' => '', 'body' => '', 'meta_json' => null, 'valid_until' => null];
$m = json_in($e['meta_json']);
$grouped = [];
foreach ($items as $i) { $grouped[$i['kind']][] = $i; }
?>
<header class="page-head">
  <p class="crumbs"><a href="/oferty">Oferty i wnioski</a> / Biblioteka firmy</p>
  <h1>Biblioteka firmy</h1>
  <p class="muted small">Fakty o firmie, z których AI składa oferty, wnioski i wiadomości. AI nie dopisze referencji ani uprawnień, których tu nie ma.</p>
</header>
<div class="cols">
  <section class="main stack">
    <?php if (!$items): ?><div class="card empty"><h3>Biblioteka jest pusta</h3><p>Dodaj pierwszą referencję po prawej stronie.</p></div><?php endif ?>
    <?php foreach (Offers::LIBRARY_KINDS as $k => $label): if (empty($grouped[$k])) continue; ?>
      <div class="card flush">
        <div class="card-head"><h2><?= e($label) ?></h2><span class="muted xs"><?= count($grouped[$k]) ?></span></div>
        <ul class="list-plain card-body">
          <?php foreach ($grouped[$k] as $i): $im = json_in($i['meta_json']); ?>
            <li class="between" style="align-items:flex-start">
              <div style="min-width:0;flex:1 1 300px">
                <a class="t-title" href="/biblioteka/<?= (int) $i['id'] ?>"><?= e($i['title']) ?></a>
                <div class="t-sub"><?= e(implode(' · ', array_filter([$im['client'] ?? null, isset($im['value']) ? money_pl($im['value']) : null, $im['finished'] ?? null, $im['role'] ?? null]))) ?></div>
                <?php if ($i['body']): ?><div class="small" style="margin-top:4px"><?= e(truncate((string) $i['body'], 220)) ?></div><?php endif ?>
              </div>
              <?php if ($i['valid_until']): $exp = strtotime($i['valid_until']) < time(); ?><span class="chip <?= $exp ? 'high' : 'low' ?>"><?= $exp ? 'wygasło' : 'ważne do' ?> <?= e(date('d.m.Y', strtotime($i['valid_until']))) ?></span><?php endif ?>
            </li>
          <?php endforeach ?>
        </ul>
      </div>
    <?php endforeach ?>
  </section>
  <section class="card side" aria-labelledby="t-form">
    <h2 id="t-form" style="margin-bottom:12px"><?= $e['id'] ? 'Edycja' : 'Dodaj do biblioteki' ?></h2>
    <?php if (!$e['id']): ?>
      <nav class="filters" aria-label="Rodzaj" style="margin-bottom:12px"><?php foreach (Offers::LIBRARY_KINDS as $k => $label): ?><a href="/biblioteka?rodzaj=<?= e($k) ?>"<?= $kind === $k ? ' class="on"' : '' ?>><?= e(explode(' (', $label)[0]) ?></a><?php endforeach ?></nav>
    <?php endif ?>
    <form method="post" action="<?= $e['id'] ? '/biblioteka/' . (int) $e['id'] : '/biblioteka' ?>" class="stack-sm">
      <?= csrf_field() ?>
      <input type="hidden" name="kind" value="<?= e($e['kind']) ?>">
      <div class="field"><label for="l-title"><?= ['reference' => 'Nazwa zamówienia', 'person' => 'Imię, nazwisko i funkcja', 'certificate' => 'Nazwa certyfikatu', 'insurance' => 'Polisa', 'text' => 'Tytuł opisu'][$e['kind']] ?? 'Tytuł' ?></label><input id="l-title" name="title" type="text" required maxlength="200" value="<?= e($e['title']) ?>"></div>
      <?php if ($e['kind'] === 'reference'): ?>
        <div class="field"><label for="m-client">Zamawiający</label><input id="m-client" name="meta_client" type="text" value="<?= e($m['client'] ?? '') ?>"></div>
        <div class="form-grid" style="gap:12px">
          <div class="field"><label for="m-value">Wartość brutto (zł)</label><input id="m-value" name="meta_value" type="text" inputmode="decimal" value="<?= isset($m['value']) ? e(number_format((float) $m['value'], 2, ',', '')) : '' ?>"></div>
          <div class="field"><label for="m-fin">Zakończenie</label><input id="m-fin" name="meta_finished" type="text" placeholder="np. 09.2025" value="<?= e($m['finished'] ?? '') ?>"></div>
        </div>
      <?php elseif ($e['kind'] === 'person'): ?>
        <div class="field"><label for="m-role">Rola w zamówieniach</label><input id="m-role" name="meta_role" type="text" placeholder="np. kierownik budowy" value="<?= e($m['role'] ?? '') ?>"></div>
        <div class="field"><label for="m-q">Uprawnienia</label><input id="m-q" name="meta_qualifications" type="text" placeholder="np. drogowe bez ograniczeń, nr …" value="<?= e($m['qualifications'] ?? '') ?>"></div>
      <?php endif ?>
      <div class="field"><label for="l-body"><?= $e['kind'] === 'text' ? 'Treść' : 'Opis' ?></label><textarea id="l-body" name="body" rows="6"><?= e($e['body']) ?></textarea></div>
      <?php if (in_array($e['kind'], ['certificate', 'insurance', 'person'], true)): ?>
        <div class="field"><label for="l-valid">Ważne do</label><input id="l-valid" name="valid_until" type="date" value="<?= e($e['valid_until'] ?? '') ?>"></div>
      <?php endif ?>
      <div class="row-flex"><button class="btn" type="submit">Zapisz</button><?php if ($e['id']): ?><a class="btn ghost" href="/biblioteka">Anuluj</a><?php endif ?></div>
    </form>
    <?php if ($e['id']): ?><form method="post" action="/biblioteka/<?= (int) $e['id'] ?>/usun" data-confirm="Usunąć „<?= e($e['title']) ?>” z biblioteki?" style="margin-top:12px"><?= csrf_field() ?><button class="link-btn small" type="submit">Usuń z biblioteki</button></form><?php endif ?>
  </section>
</div>
