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

// Menu główne w kolejności pracy: pulpit, sprzedaż, marketing, przetargi i dotacje, kontrahenci, konto.
// Pozycja: [adres, nazwa, czy aktywna, znacznik z liczbą]. Wygląd PerfectData pokazuje grupy w górnym wierszu, a pozycje aktywnej grupy pod nim.
$path = current_path();
$on = static function (string ...$prefixes) use ($path): bool {
    foreach ($prefixes as $p) {
        if ($path === $p || str_starts_with($path, $p . '/')) {
            return true;
        }
    }
    return false;
};
$chip = static fn (int $n, string $class, string $text): string => $n ? ' <span class="chip ' . $class . '">' . e($text) . '</span>' : '';
$calls = $on('/oferty/nabory');
$groups = [['Pulpit', [['/pulpit', 'Pulpit', $on('/pulpit'), ''], ['/monitory', 'Monitory', $on('/monitory'), $chip((int) ($counts['notices'] ?? 0), 'info', (string) ($counts['notices'] ?? 0))]]]];
$sales = [];
if ($has('zapytania')) {
    $sales[] = ['/zapytania', 'Zapytania', $on('/zapytania'), $chip((int) $counts['leads'], 'info', $counts['leads'] . ' ' . plural((int) $counts['leads'], 'nowe', 'nowe', 'nowych'))];
}
if ($has('klienci')) {
    $sales[] = ['/klienci', 'Klienci', $on('/klienci', '/wiadomosci'), ''];
}
if ($sales) {
    $groups[] = ['Sprzedaż', $sales];
}
if ($has('audyt')) {
    $groups[] = ['Marketing', [['/audyt', 'Audyt i reklamy', $on('/audyt', '/reklamy'), '']]];
}
$bids = [];
if ($has('przetargi')) {
    $bids[] = ['/przetargi', 'Przetargi', $on('/przetargi', '/profile'), $chip((int) $counts['matches'], 'info', $counts['matches'] . ' ' . plural((int) $counts['matches'], 'nowy', 'nowe', 'nowych'))];
}
if ($has('oferty')) {
    $bids[] = ['/oferty', 'Oferty', $on('/oferty') && !$calls, ''];
    $bids[] = ['/oferty/nabory', 'Dotacje', $calls, ''];
}
$library = ['/biblioteka', 'Biblioteka firmy', $on('/biblioteka'), ''];
if ($bids) {
    $bids[] = $library;
    $groups[] = ['Przetargi i dotacje', $bids];
}
if ($has('kontrahenci')) {
    $groups[] = ['Kontrahenci', [['/kontrahenci', 'Kontrahenci', $on('/kontrahenci'), $chip((int) $counts['alerts'], 'high', (string) $counts['alerts'])]]];
}
$account = array_merge($bids ? [] : [$library], [['/ustawienia', 'Ustawienia', $on('/ustawienia'), '']], Auth::isAdmin() ? [['/administracja', 'Administracja', $on('/administracja'), '']] : []);
$groups[] = ['Konto', $account];
$activeLabel = '';
$activeItems = [];
foreach ($groups as [$groupLabel, $items]) {
    if (array_filter($items, static fn (array $i): bool => $i[2])) {
        [$activeLabel, $activeItems] = [$groupLabel, $items];
    }
}
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
      <?php foreach ($groups as [$groupLabel, $items]): $groupOn = (bool) array_filter($items, static fn (array $i): bool => $i[2]); $chips = implode('', array_column($items, 3)); ?>
      <div class="nav-group<?= $groupOn ? ' on' : '' ?><?= count($items) === 1 && $items[0][1] === $groupLabel ? ' single' : '' ?>">
        <a class="nav-top<?= $groupOn ? ' on' : '' ?>" href="<?= e($items[0][0]) ?>"<?= $groupOn ? ' aria-current="true"' : '' ?>><?= e($groupLabel) ?><?= $chips ?></a>
        <div class="nav-items">
          <?php foreach ($items as [$href, $name, $isOn, $chip]): ?><a href="<?= e($href) ?>"<?= $isOn ? ' class="on" aria-current="page"' : '' ?>><?= e($name) ?><?= $chip ?></a><?php endforeach ?>
        </div>
      </div>
      <?php endforeach ?>
    </nav>
    <div class="org-box">
      <div style="font-weight:600"><?= e($org['name'] ?? '') ?></div>
      <div class="muted"><?= e($u['name'] ?: $u['email']) ?> · <?= $counts['profiles'] ?> <?= plural($counts['profiles'], 'profil przetargowy', 'profile przetargowe', 'profili przetargowych') ?></div>
      <form method="post" action="/wyloguj"><?= csrf_field() ?><button class="link-btn small" type="submit">Wyloguj</button></form>
    </div>
    <?php if (count($activeItems) > 1): ?>
    <nav class="subnav" aria-label="<?= e($activeLabel) ?>">
      <?php foreach ($activeItems as [$href, $name, $isOn, $chip]): ?><a href="<?= e($href) ?>"<?= $isOn ? ' class="on" aria-current="page"' : '' ?>><?= e($name) ?><?= $chip ?></a><?php endforeach ?>
    </nav>
    <?php endif ?>
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
