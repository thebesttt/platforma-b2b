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
<title><?= e(isset($title) ? $title . ' · ' . $appName : $appName . ' – przetargi, oferty i bezpieczni kontrahenci') ?></title>
<meta name="description" content="Przetargi dopasowane do Twojej firmy, szkice ofert z AI, sprawdzanie kontrahentów pod kątem zatorów płatniczych i audyt strony w jednym miejscu.">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/app.css?v=<?= e(APP_VERSION) ?>">
</head>
<body>
<header class="pub-top">
  <a class="brand" href="/"><span class="brand-mark" aria-hidden="true"></span><span class="brand-name"><?= e($appName) ?></span></a>
  <nav class="row-flex">
    <?php if (Auth::check()): ?>
      <a class="btn" href="/pulpit">Przejdź do pulpitu</a>
    <?php else: ?>
      <a class="btn ghost" href="/logowanie">Zaloguj się</a>
      <?php if ((string) config('SIGNUP_OPEN') === '1'): ?><a class="btn" href="/rejestracja">Załóż konto</a><?php endif ?>
    <?php endif ?>
  </nav>
</header>
<main class="pub-main" id="tresc">
  <div class="<?= e($wrap ?? 'pub-narrow') ?> stack">
    <?php foreach ($flash as $f): ?>
      <div class="flash <?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
    <?php endforeach ?>
    <?= $content ?>
  </div>
</main>
<footer class="pub-foot">© <?= date('Y') ?> <?= e($appName) ?> · Dane z BZP, TED, KRS, wykazu podatników VAT i rejestru decyzji UOKiK</footer>
<script src="/assets/app.js?v=<?= e(APP_VERSION) ?>" defer></script>
</body>
</html>
