<?php use App\Modules\Tenders\Regions; ?>
<header class="between page-head">
  <div>
    <p class="crumbs"><a href="/przetargi">Przetargi</a> / Profile</p>
    <h1>Profile przetargowe</h1>
    <p class="muted small">Każdy profil to osobny zestaw kryteriów. Ogłoszenia z BZP i TED sprawdzamy codziennie rano.</p>
  </div>
  <a class="btn" href="/profile/nowy">Nowy profil</a>
</header>
<?php if (!$profiles): ?>
  <div class="card empty"><h3>Nie masz jeszcze profilu</h3><p>Profil mówi nam, jakich zamówień szukasz: kody CPV, słowa kluczowe, województwa i wartość.</p><a class="btn" href="/profile/nowy">Ustaw pierwszy profil</a></div>
<?php else: ?>
<section class="card flush">
  <div class="table-wrap">
    <table>
      <thead><tr><th scope="col">Profil</th><th scope="col">Kryteria</th><th scope="col" class="n">Dopasowania</th><th scope="col">Status</th></tr></thead>
      <tbody>
      <?php foreach ($profiles as $p): $regions = array_filter(explode(' ', (string) $p['voivodeships'])); ?>
        <tr>
          <td><a class="t-title" href="/profile/<?= (int) $p['id'] ?>"><?= e($p['name']) ?></a></td>
          <td class="small">
            <?php if ($p['cpv']): ?><div>CPV: <?= e($p['cpv']) ?></div><?php endif ?>
            <?php if (trim((string) $p['keywords'])): ?><div>Słowa: <?= e(truncate(str_replace(["\r\n", "\n"], ', ', (string) $p['keywords']), 90)) ?></div><?php endif ?>
            <div class="muted"><?= $regions ? e(implode(', ', array_map([Regions::class, 'name'], $regions))) : 'Cała Polska' ?><?= ($p['min_value'] || $p['max_value']) ? ' · ' . e(($p['min_value'] ? short_money($p['min_value']) : '0') . '–' . ($p['max_value'] ? short_money($p['max_value']) : 'bez limitu')) : '' ?></div>
          </td>
          <td class="n"><a href="/przetargi?profil=<?= (int) $p['id'] ?>"><?= (int) $p['matches'] ?></a></td>
          <td><?= $p['active'] ? '<span class="chip low">aktywny</span>' : '<span class="chip neutral">wstrzymany</span>' ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif ?>
