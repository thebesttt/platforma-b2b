<?php
/** @var int $page @var int $total @var int $per @var array $query */
$pages = (int) ceil($total / max(1, $per));
if ($pages > 1): ?>
<nav class="row-flex" aria-label="Strony" style="padding:16px 20px">
  <?php if ($page > 1): ?><a class="btn ghost sm" href="?<?= e(http_build_query($query + ['strona' => $page - 1])) ?>">Poprzednia</a><?php endif ?>
  <span class="muted small">Strona <?= $page ?> z <?= $pages ?></span>
  <?php if ($page < $pages): ?><a class="btn ghost sm" href="?<?= e(http_build_query($query + ['strona' => $page + 1])) ?>">Następna</a><?php endif ?>
</nav>
<?php endif;
