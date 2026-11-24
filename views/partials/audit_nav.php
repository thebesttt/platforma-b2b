<?php /** @var string $on */
$tabs = ['ai' => ['/audyt/tresc', 'Audyt AI'], 'wytyczne' => ['/audyt/wytyczne', 'Wytyczne wskaźników'], 'tech' => ['/audyt', 'Audyt techniczny'], 'widocznosc' => ['/audyt/widocznosc', 'Widoczność'], 'porownanie' => ['/audyt/porownanie', 'Porównanie z konkurencją'], 'reklamy' => ['/reklamy', 'Reklamy']];
?>
<nav class="filters" aria-label="Audyt i reklamy">
  <?php foreach ($tabs as $k => [$href, $label]): ?><a href="<?= $href ?>"<?= $on === $k ? ' class="on" aria-current="page"' : '' ?>><?= $label ?></a><?php endforeach ?>
</nav>
