<?php
use App\Core\Auth;
use App\Core\Brand;
use App\Core\Session;
use App\Http\Nav;

$u = Auth::user();
$org = Auth::org();
$counts = Nav::counts();
$flash = Session::takeFlash();
$appName = (string) config('APP_NAME');
$theme = Brand::theme($org);
$logo = Brand::logo();
$mods = Brand::modules($org);
$has = static fn (string $m): bool => in_array($m, $mods, true);
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($title ?? 'Pulpit') . ' · ' . $appName) ?></title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('/assets/app.css')) ?>">
<?php if ($theme !== 'default'): ?><link rel="stylesheet" href="<?= e(asset('/assets/theme-' . $theme . '.css')) ?>"><?php endif ?>
<meta name="robots" content="noindex">
</head>
<body>
<a class="sr-only" href="#tresc">Przejdź do treści</a>
<div class="shell">
  <aside class="sidebar">
    <a class="brand" href="/pulpit"><?php if ($logo): ?><img src="<?= e($logo) ?>" alt="<?= e($appName) ?>"><?php else: ?><span class="brand-mark" aria-hidden="true"><?= $theme === 'perfectdata' ? 'PD' : '' ?></span><span class="brand-name"><?= $appName === 'PerfectData' ? 'Perfect<b>Data</b>' : e($appName) ?></span><?php endif ?></a>
    <nav class="nav" aria-label="Menu główne">
      <a href="/pulpit"<?= nav_on('/pulpit') ?>>Pulpit</a>
      <?php if ($has('zapytania')): ?><a href="/zapytania"<?= nav_on('/zapytania') ?>>Zapytania<?php if ($counts['leads']): ?> <span class="chip info"><?= $counts['leads'] ?> <?= plural($counts['leads'], 'nowe', 'nowe', 'nowych') ?></span><?php endif ?></a><?php endif ?>
      <?php if ($has('klienci')): ?><a href="/klienci"<?= nav_on('/klienci') ?: nav_on('/wiadomosci') ?>>Klienci i wiadomości</a><?php endif ?>
      <?php if ($has('audyt')): ?><a href="/audyt"<?= nav_on('/audyt') ?: nav_on('/reklamy') ?>>Audyt i reklamy</a><?php endif ?>
      <?php if ($has('przetargi')): ?><a href="/przetargi"<?= nav_on('/przetargi') ?: nav_on('/profile') ?>>Przetargi<?php if ($counts['matches']): ?> <span class="chip info"><?= $counts['matches'] ?> <?= plural($counts['matches'], 'nowy', 'nowe', 'nowych') ?></span><?php endif ?></a><?php endif ?>
      <?php if ($has('oferty')): ?><a href="/oferty"<?= nav_on('/oferty') ?>>Oferty i wnioski</a><?php endif ?>
      <?php if ($has('kontrahenci')): ?><a href="/kontrahenci"<?= nav_on('/kontrahenci') ?>>Kontrahenci<?php if ($counts['alerts']): ?> <span class="chip high"><?= $counts['alerts'] ?></span><?php endif ?></a><?php endif ?>
      <div class="nav-sub">Konto</div>
      <a href="/biblioteka"<?= nav_on('/biblioteka') ?>>Biblioteka firmy</a>
      <a href="/ustawienia"<?= nav_on('/ustawienia') ?>>Ustawienia</a>
      <?php if (Auth::isAdmin()): ?><a href="/administracja"<?= nav_on('/administracja') ?>>Administracja</a><?php endif ?>
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
<script src="<?= e(asset('/assets/app.js')) ?>" defer></script>
</body>
</html>
