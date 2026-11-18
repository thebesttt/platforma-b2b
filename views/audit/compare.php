<?php
$cats = [];
foreach ($sites as $s) { foreach ($s['view']['categories'] ?? [] as $k => $c) { $cats[$k] = $c['label']; } }
?>
<header class="page-head">
  <p class="crumbs"><a href="/audyt">Audyt</a> / Porównanie</p>
  <h1>Twoja strona na tle konkurencji</h1>
  <p class="muted small">Najnowszy audyt każdej domeny. Dodaj strony konkurentów w zakładce Audyt z etykietą „Konkurent”.</p>
</header>
<section class="card flush">
  <?php if ($sites): ?>
  <div class="table-wrap"><table>
    <thead><tr><th scope="col">Strona</th><th scope="col" class="n">Wynik</th><?php foreach ($cats as $label): ?><th scope="col" class="n"><?= e($label) ?></th><?php endforeach ?><th scope="col" class="n">Krytyczne</th></tr></thead>
    <tbody>
    <?php foreach ($sites as $s): $v = $s['view']; ?>
      <tr<?= ($v['label'] ?? '') === 'Moja strona' ? ' style="background:var(--accent-soft)"' : '' ?>>
        <td><a class="t-title" href="/audyt/<?= (int) $s['row']['id'] ?>"><?= e($s['row']['host']) ?></a><?php if (!empty($v['label'])): ?><div class="t-sub"><?= e($v['label']) ?></div><?php endif ?></td>
        <td class="n"><?= App\Core\View::partial('partials/audit_score', ['v' => $v]) ?></td>
        <?php foreach (array_keys($cats) as $k): ?><td class="n"><?= isset($v['categories'][$k]) ? (int) $v['categories'][$k]['score'] : '—' ?></td><?php endforeach ?>
        <td class="n"><?= (int) ($v['counts']['critical'] ?? 0) ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table></div>
  <?php else: ?><div class="empty"><h3>Brak audytów do porównania</h3><a class="btn" href="/audyt">Sprawdź pierwszą stronę</a></div><?php endif ?>
</section>
