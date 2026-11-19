<?php use App\Modules\Companies\Uokik; ?>
<header class="page-head">
  <p class="crumbs"><a href="/ustawienia">Ustawienia</a> / Decyzje UOKiK</p>
  <h1>Decyzje UOKiK o zatorach płatniczych</h1>
  <p class="muted small">W bazie: <?= (int) $stats['n'] ?> <?= plural((int) $stats['n'], 'decyzja', 'decyzje', 'decyzji') ?><?= $stats['latest'] ? ', najnowsza z ' . e(date('d.m.Y', strtotime($stats['latest']))) : '' ?>. Ostatnia aktualizacja: <?= $last ? e(local_dt($last)) : 'brak' ?>.</p>
</header>
<div class="notice mid">Rejestr decyzji UOKiK nie przyjmuje połączeń z części serwerów poza Polską. Jeśli automatyczna aktualizacja nie działa, wgraj listy ręcznie - zajmuje to minutę raz w tygodniu.</div>
<section class="card stack-sm">
  <h2>Jak wgrać listę</h2>
  <ol class="law">
    <li>Otwórz w przeglądarce listy decyzji z kategorii „Zatory płatnicze” (każdy link to jeden rok, od najnowszego):
      <?php for ($i = 1; $i <= 6; $i++): ?> <a href="<?= e(Uokik::listUrl($i)) ?>" target="_blank" rel="noopener">rok <?= $i ?></a><?= $i < 6 ? ',' : '' ?><?php endfor ?></li>
    <li>Zapisz każdą stronę skrótem Ctrl+S (Cmd+S na Macu) jako „Strona internetowa, tylko HTML”.</li>
    <li>Wgraj zapisane pliki poniżej. Duplikaty pomijamy automatycznie.</li>
  </ol>
  <form method="post" action="/ustawienia/uokik" enctype="multipart/form-data" class="stack-sm">
    <?= csrf_field() ?>
    <label class="sr-only" for="pages">Pliki HTML</label>
    <input id="pages" type="file" name="pages[]" accept=".html,.htm" multiple required>
    <div><button class="btn" type="submit">Wgraj listy decyzji</button></div>
  </form>
</section>
