<?php /** @var string $on */
$tabs = ['lista' => ['/zapytania', 'Zapytania'], 'wyniki' => ['/zapytania/wyniki', 'Wyniki'], 'wiedza' => ['/zapytania/wiedza', 'Wiedza czatu'], 'ustawienia' => ['/zapytania/ustawienia', 'Ustawienia widżetu'], 'podglad' => ['/zapytania/podglad', 'Podgląd']];
?>
<nav class="filters" aria-label="Zapytania ze strony">
  <?php foreach ($tabs as $k => [$href, $label]): ?><a href="<?= $href ?>"<?= $on === $k ? ' class="on" aria-current="page"' : '' ?>><?= $label ?></a><?php endforeach ?>
</nav>
