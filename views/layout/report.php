<?php
/**
 * Strona raportu dla klienta agencji: wygląd agencji zamiast wyglądu platformy, bez sesji, plików cookie i skryptów.
 * Drukuje się na A4 (przeglądarka: Drukuj, Zapisz jako PDF).
 * @var string $content
 */
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? 'Raport') ?></title>
<link rel="stylesheet" href="<?= e(asset('/assets/app.css')) ?>">
<style>
body { background: #fff; }
.rep-wrap { max-width: 860px; margin: 0 auto; padding: 28px 18px 40px; }
.rep-print { text-align: right; margin: 0 0 12px; font-size: 13px; }
@media print { .rep-print { display: none; } .rep-wrap { padding: 0; max-width: none; } @page { size: A4; margin: 16mm 14mm; } }
</style>
</head>
<body>
<main class="rep-wrap" id="tresc">
  <p class="rep-print muted">Wersja do druku: w przeglądarce wybierz Drukuj i Zapisz jako PDF.</p>
  <?= $content ?>
</main>
</body>
</html>
