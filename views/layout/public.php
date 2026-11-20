<?php
use App\Core\Auth;
use App\Core\Session;

$flash = Session::takeFlash();
$appName = (string) config('APP_NAME');
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(isset($title) ? $title . ' · ' . $appName : $appName . ' - przetargi, oferty i bezpieczni kontrahenci') ?></title>
<?php if (!empty($bare)): ?><meta name="robots" content="noindex, nofollow"><?php else: ?><meta name="description" content="Przetargi dopasowane do Twojej firmy, szkice ofert z AI, sprawdzanie kontrahentów pod kątem zatorów płatniczych i audyt strony w jednym miejscu."><?php endif ?>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('/assets/app.css')) ?>">
<?php $theme = App\Core\Brand::theme(); $logo = App\Core\Brand::logo(); if ($theme !== 'default'): ?><link rel="stylesheet" href="<?= e(asset('/assets/theme-' . $theme . '.css')) ?>"><?php endif ?>
</head>
<body>
<header class="pub-top">
  <a class="brand" href="/"><?php if ($logo): ?><img src="<?= e($logo) ?>" alt="<?= e($appName) ?>"><?php else: ?><span class="brand-mark" aria-hidden="true"><?= $theme === 'perfectdata' ? 'PD' : '' ?></span><span class="brand-name"><?= $appName === 'PerfectData' ? 'Perfect<b>Data</b>' : e($appName) ?></span><?php endif ?></a>
  <?php /* Strona dla klienta (raport pod linkiem): bez przycisków logowania i bez opisu platformy. */ if (empty($bare)): ?>
  <nav class="row-flex">
    <?php if (Auth::check()): ?>
      <a class="btn" href="/pulpit">Przejdź do pulpitu</a>
    <?php else: ?>
      <a class="btn ghost" href="/logowanie">Zaloguj się</a>
      <?php if ((string) config('SIGNUP_OPEN') === '1'): ?><a class="btn" href="/rejestracja">Załóż konto</a><?php endif ?>
    <?php endif ?>
  </nav>
  <?php endif ?>
</header>
<main class="pub-main" id="tresc">
  <div class="<?= e($wrap ?? 'pub-narrow') ?> stack">
    <?php foreach ($flash as $f): ?>
      <div class="flash <?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
    <?php endforeach ?>
    <?= $content ?>
  </div>
</main>
<footer class="pub-foot">© <?= date('Y') ?> <?= e($appName) ?><?= empty($bare) ? ' · Dane z BZP, TED, KRS, wykazu podatników VAT i rejestru decyzji UOKiK' : ' · Raport tylko do odczytu' ?></footer>
<script src="<?= e(asset('/assets/app.js')) ?>" defer></script>
</body>
</html>
