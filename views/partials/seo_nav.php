<?php
/**
 * Nagłówek i zakładki jednej witryny w części „Widoczność”.
 * @var array $site
 * @var string $tab google | ai | konkurencja | dane
 * @var array $sites wszystkie witryny firmy
 * @var string $lead jedno zdanie pod tytułem
 */
$base = '/audyt/widocznosc/' . (int) $site['id'];
$tabs = ['google' => [$base, 'Google'], 'ai' => [$base . '/ai', 'Odpowiedzi AI'], 'konkurencja' => [$base . '/konkurencja', 'Konkurencja'], 'dane' => [$base . '/dane', 'Dane i połączenia']];
?>
<header class="page-head">
  <p class="crumbs"><a href="/audyt/widocznosc?lista=1">Widoczność</a> / <?= e($site['host']) ?></p>
  <h1><?= e($heading) ?></h1>
  <p class="muted small"><?= e($lead) ?></p>
</header>
<?= App\Core\View::partial('partials/audit_nav', ['on' => 'widocznosc']) ?>
<nav class="filters" aria-label="Widoczność witryny <?= e($site['host']) ?>">
  <?php foreach ($tabs as $k => [$href, $label]): ?><a href="<?= e($href) ?>"<?= $tab === $k ? ' class="on" aria-current="page"' : '' ?>><?= e($label) ?></a><?php endforeach ?>
  <?php if (count($sites) > 1): ?><a href="/audyt/widocznosc?lista=1">Inna witryna</a><?php endif ?>
</nav>
