<?php
use App\Core\View;
use App\Modules\Inbound\Rules;

$pct = static fn (int $x, int $n): string => $n > 0 ? str_replace('.', ',', (string) round(100 * $x / $n, 1)) . '%' : '-';
$all = ['views' => 0, 'started' => 0, 'completed' => 0, 'leads' => 0, 'hot' => 0];
foreach ($stats as $s) {
    foreach ($all as $k => $_) {
        $all[$k] += $s[$k];
    }
}
$both = $stats['a']['views'] > 0 && $stats['b']['views'] > 0;
$zText = $z['z'] !== null ? str_replace('.', ',', (string) round(abs($z['z']), 2)) : null;
?>
<header class="page-head">
  <p class="crumbs"><a href="/zapytania">Zapytania ze strony</a> / Wyniki</p>
  <h1>Wyniki widżetu</h1>
  <p class="muted small">Ostatnie <?= $days ?> dni. Liczby pochodzą z liczników serwera, nie z plików cookie.</p>
</header>
<?= View::partial('partials/inbound_nav', ['on' => 'wyniki']) ?>
<nav class="filters" aria-label="Okres">
  <?php foreach ([7, 30, 90] as $d): ?><a href="/zapytania/wyniki?dni=<?= $d ?>"<?= $days === $d ? ' class="on"' : '' ?>><?= $d ?> dni</a><?php endforeach ?>
</nav>

<?php if ($todayViews >= $limits['daily']): ?>
  <div class="notice high" role="alert">Dziś widżet osiągnął limit <?= (int) $limits['daily'] ?> rozmów i przestał się otwierać. Zwiększ limit w <a href="/zapytania/ustawienia">ustawieniach</a> albo sprawdź, czy to nie automaty.</div>
<?php endif ?>

<?php if ($all['views'] === 0): ?>
  <div class="card empty"><h3>Brak danych z tego okresu</h3><p>Liczniki ruszą, gdy pierwszy odwiedzający otworzy widżet na Twojej stronie. Rozmowy z podglądu w panelu się nie liczą.</p><a class="btn ghost" href="/zapytania/ustawienia">Sprawdź ustawienia widżetu</a></div>
<?php else: ?>
<section class="grid-stats" aria-label="Lejek">
  <div class="card"><div class="stat-label">Otwarcia widżetu</div><div class="stat-value"><?= $all['views'] ?></div><div class="stat-note">ktoś zobaczył pytania albo formularz</div></div>
  <div class="card"><div class="stat-label">Zaczęte rozmowy</div><div class="stat-value"><?= $all['started'] ?></div><div class="stat-note"><?= $pct($all['started'], $all['views']) ?> otwarć</div></div>
  <div class="card"><div class="stat-label">Wszystkie pytania</div><div class="stat-value"><?= $all['completed'] ?></div><div class="stat-note"><?= $pct($all['completed'], $all['views']) ?> otwarć</div></div>
  <div class="card"><div class="stat-label">Zapytania</div><div class="stat-value"><?= $all['leads'] ?></div><div class="stat-note"><?= $pct($all['leads'], $all['views']) ?> otwarć</div></div>
  <div class="card"><div class="stat-label">W tym pilne</div><div class="stat-value"><?= $all['hot'] ?></div><div class="stat-note"><?= $pct($all['hot'], $all['leads']) ?> zapytań</div></div>
</section>

<section class="card flush" aria-labelledby="w-ab">
  <div class="card-head"><h2 id="w-ab">Zwykły formularz i pytania z oceną</h2></div>
  <div class="table-wrap"><table>
    <thead><tr><th scope="col">Wersja</th><th scope="col" class="n">Otwarcia</th><th scope="col" class="n">Zapytania</th><th scope="col" class="n">Skuteczność</th><th scope="col" class="n">Pilne</th></tr></thead>
    <tbody>
      <tr><td>A: zwykły formularz</td><td class="n"><?= $stats['a']['views'] ?></td><td class="n"><?= $stats['a']['leads'] ?></td><td class="n"><b><?= $pct($stats['a']['leads'], $stats['a']['views']) ?></b></td><td class="n"><?= $stats['a']['hot'] ?></td></tr>
      <tr><td>B: pytania z oceną</td><td class="n"><?= $stats['b']['views'] ?></td><td class="n"><?= $stats['b']['leads'] ?></td><td class="n"><b><?= $pct($stats['b']['leads'], $stats['b']['views']) ?></b></td><td class="n"><?= $stats['b']['hot'] ?></td></tr>
    </tbody>
  </table></div>
  <div class="card-body">
    <?php if (!$both): ?>
      <p class="small muted" style="margin:12px 0 0"><?= (int) $w['ab_test'] === 1 ? 'Test trwa, ale jedna z wersji nie ma jeszcze otwarć.' : 'Test dwóch wersji jest wyłączony, więc wszyscy widzą pytania z oceną. Włączysz go w ustawieniach.' ?></p>
    <?php elseif ($z['sure']): ?>
      <p style="margin:12px 0 0"><span class="chip low">Różnica jest pewna</span> Lepiej wypada wersja <?= $z['z'] > 0 ? 'B (pytania z oceną)' : 'A (zwykły formularz)' ?>: <?= $pct($stats['b']['leads'], $stats['b']['views']) ?> wobec <?= $pct($stats['a']['leads'], $stats['a']['views']) ?> dla wersji <?= $z['z'] > 0 ? 'A' : 'B' ?>. Wynik testu: z = <?= $zText ?>, próg pewności 95% to 1,96.</p>
    <?php else: ?>
      <p style="margin:12px 0 0"><span class="chip neutral">Za wcześnie na wniosek</span> Różnica między wersjami może być przypadkiem<?= $zText !== null ? ': z = ' . $zText . ', a pewność 95% zaczyna się od 1,96' : '' ?>. Zbieraj dane dalej i nie wyłączaj testu.</p>
    <?php endif ?>
  </div>
</section>
<?php endif ?>

<div class="cols">
  <section class="card main" aria-labelledby="w-p">
    <h2 id="w-p" style="margin-bottom:8px">Zapytania według oceny</h2>
    <?php $sum = array_sum($levels); foreach (Rules::LEVELS as $k => $lv): ?>
      <div class="kv-row"><div><p class="k"><?= e($lv['label']) ?></p><div class="bar" style="margin-top:6px"><span style="width:<?= $sum ? round(100 * $levels[$k] / $sum) : 0 ?>%"></span></div></div><b><?= $levels[$k] ?></b></div>
    <?php endforeach ?>
    <p class="small muted" style="margin:12px 0 0">Wygranych w tym okresie: <?= $won ?>. Status „Wygrane” ustawiasz na karcie zapytania.</p>
  </section>
  <section class="card side" aria-labelledby="w-ai">
    <h2 id="w-ai" style="margin-bottom:8px">Zużycie</h2>
    <div class="kv-row"><div><p class="k">Odpowiedzi czatu w tym miesiącu</p><p class="v"><?= $aiUsed ?> z <?= (int) $limits['ai_month'] ?></p></div></div>
    <div class="kv-row"><div><p class="k">Rozmowy dzisiaj</p><p class="v"><?= $todayViews ?> z <?= (int) $limits['daily'] ?></p></div></div>
  </section>
</div>
