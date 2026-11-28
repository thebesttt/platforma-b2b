<?php
use App\Core\View;
use App\Modules\Seo\MonthlyReport;

/** @var array $site @var array $rep @var array $mr @var array $brand @var ?string $link */
$base = '/audyt/widocznosc/' . (int) $site['id'] . '/raporty/' . (int) $rep['id'];
echo View::partial('partials/seo_nav', ['site' => $site, 'tab' => $tab, 'sites' => $sites, 'heading' => 'Raport za ' . MonthlyReport::label((string) $rep['month']),
    'lead' => 'Dopisz, co zrobiliście, sprawdź liczby i udostępnij klientowi link. Liczby są zapisane w chwili przygotowania raportu.']);
?>
<section class="card" id="notatki" aria-labelledby="rp-notes">
  <h2 id="rp-notes" style="margin-bottom:6px">Notatki agencji</h2>
  <p class="small muted" style="margin:0 0 12px">Tę część piszecie sami: program nie wie, co zrobiliście. Klient zobaczy ją pod wynikami w Google.</p>
  <form method="post" action="<?= e($base) ?>" class="stack">
    <?= csrf_field() ?>
    <div class="field"><label for="rp-done">Co zrobiliśmy w tym miesiącu</label><textarea id="rp-done" name="zrobione" rows="5" maxlength="6000" placeholder="Np. poprawiliśmy tytuły 12 stron produktów, dodaliśmy sekcję pytań na stronie usług, naprawiliśmy przekierowania po zmianie adresów."><?= e((string) $rep['done_text']) ?></textarea></div>
    <div class="field"><label for="rp-next">Plan na następny miesiąc</label><textarea id="rp-next" name="plan" rows="4" maxlength="6000"><?= e((string) $rep['next_text']) ?></textarea></div>
    <div class="row-flex" style="gap:8px;flex-wrap:wrap">
      <button class="btn ghost" type="submit" name="akcja" value="zapisz">Zapisz notatki</button>
      <?php if (!$link): ?><button class="btn" type="submit" name="akcja" value="udostepnij">Zapisz i udostępnij klientowi</button><?php endif ?>
    </div>
  </form>
</section>

<section class="card" aria-labelledby="rp-share">
  <h2 id="rp-share" style="margin-bottom:6px">Link dla klienta</h2>
  <?php if ($link): ?>
    <p style="margin:0 0 8px"><code class="copy wrap" title="Kliknij, żeby skopiować"><?= e($link) ?></code></p>
    <p class="small muted" style="margin:0 0 12px">Udostępniony <?= e(local_dt((string) $rep['shared_at'])) ?>. Strona nie zapisuje plików cookie, nie jest widoczna w wyszukiwarkach i drukuje się do PDF. Zmiany w notatkach klient zobaczy od razu.</p>
    <form method="post" action="<?= e($base) ?>"><?= csrf_field() ?><button class="btn ghost sm" type="submit" name="akcja" value="wylacz">Wyłącz link</button></form>
  <?php else: ?>
    <p class="small muted" style="margin:0">Raport jest szkicem. Link powstanie po kliknięciu „Zapisz i udostępnij klientowi”.</p>
  <?php endif ?>
  <div class="row-flex" style="gap:8px;margin-top:12px;flex-wrap:wrap">
    <form method="post" action="<?= e($base) ?>"><?= csrf_field() ?><button class="btn ghost sm" type="submit" name="akcja" value="odswiez" data-busy="Odświeżam…">Odśwież liczby</button></form>
    <form method="post" action="<?= e($base) ?>/usun" data-confirm="Usunąć raport? Link dla klienta przestanie działać."><?= csrf_field() ?><button class="btn ghost sm" type="submit">Usuń raport</button></form>
  </div>
  <p class="sec-note" style="margin-top:8px">Liczby zapisano <?= e(local_dt((string) ($mr['built_at'] ?? $rep['created_at']))) ?>. Odświeżenie bierze aktualne dane z panelu.</p>
</section>

<section class="card" aria-labelledby="rp-prev">
  <h2 id="rp-prev" class="sr-only">Podgląd raportu</h2>
  <p class="small muted" style="margin:0 0 12px">Podgląd: tak raport zobaczy klient.</p>
  <?= View::partial('partials/monthly_report', ['rep' => $rep, 'mr' => $mr, 'brand' => $brand, 'heading' => 'h2']) ?>
</section>
