<?php
use App\Core\View;
?>
<header class="page-head">
  <p class="crumbs"><a href="/zapytania">Zapytania ze strony</a> / Podgląd</p>
  <h1>Podgląd widżetu</h1>
  <p class="muted small">To ten sam widżet, który zobaczą odwiedzający. Rozmowy z podglądu nie liczą się do wyników.</p>
</header>
<?= View::partial('partials/inbound_nav', ['on' => 'podglad']) ?>

<?php if ((int) $w['active'] !== 1): ?>
  <div class="notice mid">Widżet jest wyłączony, więc podgląd jest pusty. Włącz go w <a href="/zapytania/ustawienia">ustawieniach</a>.</div>
<?php endif ?>

<div class="cols">
  <section class="card main stack-sm" aria-labelledby="p-h">
    <h2 id="p-h"><?= $inline ? 'Formularz w treści strony' : 'Przycisk w rogu strony' ?></h2>
    <?php if ($inline): ?>
      <p class="small muted" style="margin:0">Tak wygląda widżet wstawiony w treść przez <code>&lt;div data-pd-widget&gt;&lt;/div&gt;</code>.</p>
      <div data-pd-widget></div>
    <?php else: ?>
      <p style="margin:0">Kliknij przycisk w prawym dolnym rogu ekranu i przejdź pytania tak, jak zrobi to klient.</p>
    <?php endif ?>
    <p class="small" style="margin:0"><?php if ($inline): ?><a href="/zapytania/podglad">Pokaż przycisk w rogu</a><?php else: ?><a href="/zapytania/podglad?tryb=w-tresci">Pokaż formularz w treści strony</a><?php endif ?></p>
  </section>
  <section class="card side" aria-labelledby="p-i">
    <h2 id="p-i" style="margin-bottom:8px">Co się stanie po wysłaniu</h2>
    <p class="small" style="margin:0 0 8px">Zapytanie wysłane z podglądu trafi na <a href="/zapytania">listę zapytań</a> jak prawdziwe, razem z powiadomieniem e-mail. Usuniesz je przyciskiem na karcie zapytania.</p>
    <p class="small muted" style="margin:0">Formularz wysłany w mniej niż 2 sekundy od otwarcia uznajemy za automat i pomijamy.</p>
  </section>
</div>
<script async src="/w/<?= e($w['public_id']) ?>.js"></script>
