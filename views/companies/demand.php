<header class="between page-head no-print">
  <div>
    <p class="crumbs"><a href="/kontrahenci">Kontrahenci</a> / <a href="/kontrahenci/<?= e($c['nip']) ?>#naleznosci"><?= e($c['name']) ?></a> / Wezwanie do zapłaty</p>
    <h1>Wezwanie do zapłaty</h1>
    <p class="muted small">Sprawdź treść, pobierz plik Word, podpisz i wyślij listem poleconym albo e-mailem, jeśli umowa na to pozwala.</p>
  </div>
  <div class="row-flex">
    <a class="btn" href="/naleznosci/<?= (int) $rec['id'] ?>/wezwanie?<?= e(http_build_query(['format' => 'docx', 'bureau' => $rec['demand_bureau'] ?? ''])) ?>">Pobierz .docx</a>
    <button class="btn ghost" type="button" data-print>Drukuj</button>
  </div>
</header>
<?php if (empty($rec['demand_bureau'])): ?>
  <div class="notice mid no-print">Wezwanie nie zawiera ostrzeżenia o przekazaniu danych do biura informacji gospodarczej. Bez niego nie wpiszesz długu do BIG. <a href="/kontrahenci/<?= e($c['nip']) ?>#naleznosci">Podaj nazwę biura</a>.</div>
<?php endif ?>
<section class="card doc-preview">
  <?php foreach (explode("\n", $letter['body']) as $line): ?>
    <?php if (trim($line) === ''): ?><br><?php elseif ($line === 'WEZWANIE DO ZAPŁATY'): ?><h2 style="text-align:center;margin:8px 0 16px"><?= e($line) ?></h2><?php else: ?><p><?= e($line) ?></p><?php endif ?>
  <?php endforeach ?>
</section>
<form method="post" action="/naleznosci/<?= (int) $rec['id'] ?>" class="card row-flex no-print">
  <?= csrf_field() ?><input type="hidden" name="action" value="demand"><input type="hidden" name="demand_bureau" value="<?= e($rec['demand_bureau'] ?? '') ?>">
  <label class="small" for="dsent">Wysłałem to wezwanie dnia</label>
  <input id="dsent" type="date" name="demand_sent_at" value="<?= e(date('Y-m-d')) ?>" style="max-width:200px">
  <button class="btn ghost" type="submit"<?= empty($rec['demand_bureau']) ? ' disabled' : '' ?>>Zapisz datę wysłania</button>
</form>
