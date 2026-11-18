<?php
use App\Core\Auth;
use App\Core\Session;
use App\Http\Nav;

$u = Auth::user();
$org = Auth::org();
$counts = Nav::counts();
$flash = Session::takeFlash();
$appName = (string) config('APP_NAME');
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($title ?? 'Pulpit') . ' · ' . $appName) ?></title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/app.css?v=<?= e(APP_VERSION) ?>">
<meta name="robots" content="noindex">
</head>
<body>
<a class="sr-only" href="#tresc">Przejdź do treści</a>
<div class="shell">
  <aside class="sidebar">
    <a class="brand" href="/pulpit"><span class="brand-mark" aria-hidden="true"></span><span class="brand-name"><?= e($appName) ?></span></a>
    <nav class="nav" aria-label="Menu główne">
      <a href="/pulpit"<?= nav_on('/pulpit') ?>>Pulpit</a>
      <a href="/przetargi"<?= nav_on('/przetargi') ?: nav_on('/profile') ?>>Przetargi<?php if ($counts['matches']): ?> <span class="chip info"><?= $counts['matches'] ?> <?= plural($counts['matches'], 'nowy', 'nowe', 'nowych') ?></span><?php endif ?></a>
      <a href="/oferty"<?= nav_on('/oferty') ?: nav_on('/biblioteka') ?>>Oferty i wnioski</a>
      <a href="/kontrahenci"<?= nav_on('/kontrahenci') ?>>Kontrahenci<?php if ($counts['alerts']): ?> <span class="chip high"><?= $counts['alerts'] ?></span><?php endif ?></a>
      <a href="/klienci"<?= nav_on('/klienci') ?: nav_on('/wiadomosci') ?>>Klienci i wiadomości</a>
      <a href="/audyt"<?= nav_on('/audyt') ?: nav_on('/reklamy') ?>>Audyt i reklamy</a>
      <div class="nav-sub">Konto</div>
      <a href="/biblioteka"<?= nav_on('/biblioteka') ?>>Biblioteka firmy</a>
      <a href="/ustawienia"<?= nav_on('/ustawienia') ?>>Ustawienia</a>
    </nav>
    <div class="org-box">
      <div style="font-weight:600"><?= e($org['name'] ?? '') ?></div>
      <div class="muted"><?= e($u['name'] ?: $u['email']) ?> · <?= $counts['profiles'] ?> <?= plural($counts['profiles'], 'profil przetargowy', 'profile przetargowe', 'profili przetargowych') ?></div>
      <form method="post" action="/wyloguj"><?= csrf_field() ?><button class="link-btn small" type="submit">Wyloguj</button></form>
    </div>
  </aside>
  <main class="content" id="tresc">
    <div class="container">
      <?php foreach ($flash as $f): ?>
        <div class="flash <?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
      <?php endforeach ?>
      <?= $content ?>
    </div>
  </main>
</div>
<script src="/assets/app.js?v=<?= e(APP_VERSION) ?>" defer></script>
</body>
</html>
