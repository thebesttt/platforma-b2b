<?php
/**
 * Treść raportu miesięcznego: ta sama w podglądzie agencji i na stronie dla klienta.
 * @var array $mr liczby zapisane przy tworzeniu raportu (MonthlyReport::build); nie „$mr”, bo tak nazywa się tablica zmiennych widoku
 * @var array $rep wiersz raportu (notatki agencji)
 * @var array $brand nazwa, logo, kolor i stopka agencji
 * @var ?string $heading h1 na stronie dla klienta, h2 w podglądzie agencji
 */
use App\Modules\Seo\MonthlyReport;

$g = $mr['gsc'] ?? null;
$ai = $mr['ai'] ?? [];
$leads = $mr['leads'] ?? null;
$host = (string) ($mr['host'] ?? '');
[$y, $mo] = array_map('intval', explode('-', (string) $mr['month']));
$pm = $mo === 1 ? 12 : $mo - 1;
$prevGen = MonthlyReport::MONTHS_GEN[$pm];
$prevLoc = MonthlyReport::MONTHS_LOC[$pm];
$pct = static function (int|float|null $now, int|float|null $was): ?string {
    if ($now === null || !$was) {
        return null;
    }
    $d = ($now - $was) / $was * 100;
    return ($d >= 0 ? '+' : '') . num_pl($d, abs($d) < 10 ? 1 : 0) . '%';
};
$cls = static fn (?string $p, bool $lower = false): string => $p === null ? '' : ((str_starts_with($p, '+') xor $lower) ? 'up' : 'down');
$range = static fn (string $a, string $b): string => date('d.m', strtotime($a)) . '-' . date('d.m.Y', strtotime($b));
$path = static function (string $url) use ($host): string {
    $p = parse_url($url);
    return isset($p['host']) && preg_replace('/^www\./', '', (string) $p['host']) === $host ? (($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '')) : $url;
};
$max = $g ? max(1, ...array_map(static fn (array $d): int => (int) $d[1], $g['daily'] ?: [[0, 1]])) : 1;
$holidays = (array) ($mr['holidays'] ?? []);
// Najważniejsze zdania: liczą się same z danych, bez oceny i bez przymiotników.
$lead = [];
if ($g) {
    $p1 = $g['prev'] ? $pct($g['clicks'], $g['prev']['clicks']) : null;
    $p2 = $g['yoy'] ? $pct($g['clicks'], $g['yoy']['clicks']) : null;
    $lead[] = num_pl($g['clicks']) . ' ' . plural((int) $g['clicks'], 'wejście', 'wejścia', 'wejść') . ' z wyników Google'
        . ($p1 || $p2 ? ' (' . implode(', ', array_filter([$p1 ? $p1 . ' wobec ' . $prevGen : null, $p2 ? $p2 . ' rok do roku' : null])) . ')' : '') . '.';
}
if ($leads && $leads['count'] !== null) {
    $lead[] = num_pl($leads['count']) . ' ' . plural((int) $leads['count'], 'zapytanie', 'zapytania', 'zapytań') . ' ze strony' . ($leads['prev'] ? ' (w poprzednim miesiącu ' . num_pl($leads['prev']) . ')' : '') . '.';
}
if (!empty($ai['assistants']['sessions'])) {
    $lead[] = num_pl($ai['assistants']['sessions']) . ' ' . plural((int) $ai['assistants']['sessions'], 'wejście', 'wejścia', 'wejść') . ' z asystentów AI, takich jak ChatGPT i Perplexity.';
}
?>
<style>
.mrep { --rep: <?= e($brand['color']) ?>; color: var(--text, #10141f); }
.mrep h2 { font-size: 18px; margin: 0 0 10px; }
.mrep .mrep-head { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 12px 24px; align-items: center; border-bottom: 3px solid var(--rep); padding-bottom: 14px; }
.mrep .mrep-head img { max-height: 48px; max-width: 200px; }
.mrep .mrep-title { font-size: 26px; line-height: 1.15; margin: 0; }
.mrep .mrep-lead { font-size: 17px; margin: 0; }
.mrep .mrep-lead li { margin: 4px 0; }
.mrep .mrep-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; }
.mrep .mrep-tile { border: 1px solid var(--line, #dde2ea); border-radius: 10px; padding: 12px 14px; }
.mrep .mrep-tile b { display: block; font-size: 24px; }
.mrep .mrep-tile span { font-size: 13px; color: var(--muted, #5b6476); }
.mrep .up { color: var(--low, #067647); font-weight: 600; }
.mrep .down { color: var(--high, #b42318); font-weight: 600; }
.mrep .mrep-bars { display: flex; align-items: flex-end; gap: 2px; height: 120px; border-bottom: 1px solid var(--line, #dde2ea); }
.mrep .mrep-bars i { flex: 1 1 0; min-width: 0; background: var(--rep); border-radius: 2px 2px 0 0; opacity: .85; }
.mrep .mrep-bars i.hol { opacity: .35; }
.mrep .mrep-sec { break-inside: avoid; }
.mrep .mrep-notes { white-space: pre-line; margin: 0; }
.mrep table { width: 100%; border-collapse: collapse; font-size: 14px; }
.mrep th, .mrep td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--line, #dde2ea); vertical-align: top; }
.mrep td.n, .mrep th.n { text-align: right; white-space: nowrap; }
.mrep td.k { overflow-wrap: anywhere; }
.mrep .mrep-foot { font-size: 13px; color: var(--muted, #5b6476); border-top: 1px solid var(--line, #dde2ea); padding-top: 10px; }
@media print { .mrep .mrep-sec { page-break-inside: avoid; } .mrep .mrep-bars i { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
</style>
<article class="mrep stack" aria-label="Raport miesięczny">
  <header class="mrep-head">
    <div>
      <p class="small" style="margin:0 0 4px;color:var(--muted,#5b6476)">Raport miesięczny · <?= e($host) ?></p>
      <<?= $tag = ($heading ?? 'h1') === 'h2' ? 'h2' : 'h1' ?> class="mrep-title"><?= e(ucfirst((string) $mr['label'])) ?></<?= $tag ?>>
    </div>
    <div><?php if ($brand['logo']): ?><img src="<?= e($brand['logo']) ?>" alt="<?= e($brand['name']) ?>"><?php else: ?><b><?= e($brand['name']) ?></b><?php endif ?></div>
  </header>

  <?php if ($lead): ?>
  <section class="mrep-sec" aria-label="Najważniejsze">
    <ul class="mrep-lead"><?php foreach ($lead as $l): ?><li><?= e($l) ?></li><?php endforeach ?></ul>
  </section>
  <?php endif ?>

  <?php if ($g): ?>
  <section class="mrep-sec" aria-labelledby="mr-g">
    <h2 id="mr-g">Wyniki w Google</h2>
    <div class="mrep-tiles">
      <?php foreach ([['clicks', 'Wejścia z Google', false], ['impressions', 'Wyświetlenia w wynikach', false], ['ctr', 'Klikalność', false], ['position', 'Średnia pozycja', true]] as [$f, $name, $lower]):
          $now = $g[$f]; $was = $g['prev'][$f] ?? null; $d = $f === 'position' || $f === 'ctr' ? null : $pct($now, $was); ?>
        <div class="mrep-tile"><span><?= e($name) ?></span>
          <b><?= $now === null ? '-' : ($f === 'ctr' ? pct_pl((float) $now) : ($f === 'position' ? num_pl((float) $now, 1) : num_pl($now))) ?></b>
          <?php if ($was !== null): ?><span><?php if ($d): ?><span class="<?= $cls($d, $lower) ?>"><?= e($d) ?></span> <?php endif ?><?= e($prevLoc) ?>: <?= $f === 'ctr' ? pct_pl((float) $was) : ($f === 'position' ? num_pl((float) $was, 1) : num_pl($was)) ?></span><?php endif ?>
        </div>
      <?php endforeach ?>
    </div>
    <?php if (!empty($g['daily'])): ?>
      <p class="small" style="margin:14px 0 6px;color:var(--muted,#5b6476)">Wejścia z Google dzień po dniu<?= $holidays ? ' (jaśniejsze słupki to święta: ' . e(implode(', ', array_map(static fn (string $d, string $n): string => date('d.m', strtotime($d)) . ' ' . $n, array_keys($holidays), $holidays))) . ')' : '' ?></p>
      <div class="mrep-bars" role="img" aria-label="Wejścia z Google dzień po dniu, najwięcej <?= num_pl($max) ?> dziennie">
        <?php foreach ($g['daily'] as [$day, $n]): ?><i class="<?= isset($holidays[$day]) ? 'hol' : '' ?>" style="height:<?= max(1, (int) round($n / $max * 100)) ?>%" title="<?= e(date('d.m', strtotime($day)) . ': ' . num_pl($n)) ?>"></i><?php endforeach ?>
      </div>
    <?php endif ?>
    <?php if ((int) $g['days'] < (int) $g['of']): ?><p class="small" style="margin:8px 0 0">Dane z Search Console za <?= (int) $g['days'] ?> z <?= (int) $g['of'] ?> dni miesiąca.</p><?php endif ?>
  </section>
  <?php endif ?>

  <?php if (trim((string) ($rep['done_text'] ?? '')) !== '' || trim((string) ($rep['next_text'] ?? '')) !== ''): ?>
  <section class="mrep-sec" aria-labelledby="mr-n">
    <h2 id="mr-n">Co zrobiliśmy w tym miesiącu</h2>
    <p class="mrep-notes"><?= e((string) $rep['done_text']) ?></p>
    <?php if (trim((string) ($rep['next_text'] ?? '')) !== ''): ?>
      <h2 style="margin-top:16px">Plan na następny miesiąc</h2>
      <p class="mrep-notes"><?= e((string) $rep['next_text']) ?></p>
    <?php endif ?>
  </section>
  <?php endif ?>

  <?php if (!empty($mr['queries']['rows'])): $q = $mr['queries']; ?>
  <section class="mrep-sec" aria-labelledby="mr-q">
    <h2 id="mr-q">Najważniejsze zapytania</h2>
    <p class="small" style="margin:0 0 8px;color:var(--muted,#5b6476)">Okres <?= e($range($q['start'], $q['end'])) ?> z Search Console<?= $q['compared'] ? ', zmiana wobec poprzednich 28 dni' : '' ?>.</p>
    <div class="table-wrap"><table>
      <thead><tr><th scope="col">Zapytanie</th><th scope="col" class="n">Wejścia</th><th scope="col" class="n">Wyświetlenia</th><th scope="col" class="n">Pozycja</th></tr></thead>
      <tbody><?php foreach ($q['rows'] as $row): $d = $pct($row['clicks'], $row['clicks_prev']); ?>
        <tr><td class="k"><?= e($row['k']) ?></td><td class="n"><?= num_pl($row['clicks']) ?><?= $d ? ' <span class="' . $cls($d) . '">' . e($d) . '</span>' : '' ?></td><td class="n"><?= num_pl($row['impressions']) ?></td>
          <td class="n"><?= $row['position'] === null ? '-' : num_pl((float) $row['position'], 1) ?></td></tr>
      <?php endforeach ?></tbody>
    </table></div>
  </section>
  <?php endif ?>

  <?php if (!empty($mr['pages']['rows'])): ?>
  <section class="mrep-sec" aria-labelledby="mr-p">
    <h2 id="mr-p">Strony z największą liczbą wejść</h2>
    <div class="table-wrap"><table>
      <thead><tr><th scope="col">Strona</th><th scope="col" class="n">Wejścia</th><th scope="col" class="n">Pozycja</th></tr></thead>
      <tbody><?php foreach ($mr['pages']['rows'] as $row): ?>
        <tr><td class="k"><?= e($path($row['k'])) ?></td><td class="n"><?= num_pl($row['clicks']) ?></td><td class="n"><?= $row['position'] === null ? '-' : num_pl((float) $row['position'], 1) ?></td></tr>
      <?php endforeach ?></tbody>
    </table></div>
  </section>
  <?php endif ?>

  <?php if (!empty($ai['overviews']) || !empty($ai['assistants']) || !empty($ai['bots'])): ?>
  <section class="mrep-sec" aria-labelledby="mr-ai">
    <h2 id="mr-ai">Widoczność w odpowiedziach AI</h2>
    <ul style="margin:0;padding-left:18px">
      <?php if (!empty($ai['overviews'])): ?><li>Linki do strony w odpowiedziach AI Google (Przeglądy od AI, Tryb AI): <b><?= num_pl($ai['overviews']['impressions']) ?></b> <?= plural((int) $ai['overviews']['impressions'], 'wyświetlenie', 'wyświetlenia', 'wyświetleń') ?> w miesiącu.</li><?php endif ?>
      <?php if (!empty($ai['assistants'])): $a = $ai['assistants']; $d = $pct($a['sessions'], $a['prev']); ?>
        <li>Wejścia z asystentów AI: <b><?= num_pl($a['sessions']) ?></b><?= $d ? ' <span class="' . $cls($d) . '">' . e($d) . '</span>' : '' ?> (<?= e($range($a['start'], $a['end'])) ?><?= $a['top'] ? ', najwięcej: ' . e(implode(', ', array_map(static fn (array $t): string => $t[0] . ' ' . num_pl($t[1]), $a['top']))) : '' ?>).</li>
      <?php endif ?>
      <?php if (!empty($ai['bots'])): ?><li>Boty AI pobrały strony <b><?= num_pl($ai['bots']['requests']) ?></b> razy (dane z Cloudflare z <?= (int) $ai['bots']['days'] ?> <?= plural((int) $ai['bots']['days'], 'dnia', 'dni', 'dni') ?>).</li><?php endif ?>
    </ul>
  </section>
  <?php endif ?>

  <?php $t = $mr['tracking'] ?? null; if ($t): ?>
  <section class="mrep-sec" aria-labelledby="mr-t">
    <h2 id="mr-t">Pomiar w Google Analytics</h2>
    <?php if (!empty($t['incidents'])): ?>
      <ul style="margin:0;padding-left:18px"><?php foreach ($t['incidents'] as $i): ?><li><?= e(local_dt($i['at'], 'd.m')) ?>: <?= e($i['title']) ?></li><?php endforeach ?></ul>
    <?php else: ?>
      <p style="margin:0">Przez cały miesiąc pomiar działał bez przerw: ruch i zdarzenia kluczowe były w zwykłym zakresie.</p>
    <?php endif ?>
  </section>
  <?php endif ?>

  <?php $au = $mr['audits'] ?? []; if (!empty($au['tech']) || !empty($au['content'])): ?>
  <section class="mrep-sec" aria-labelledby="mr-a">
    <h2 id="mr-a">Stan strony w audytach</h2>
    <?php if (!empty($au['tech'])): $d = $au['tech']['prev'] !== null ? $au['tech']['score'] - $au['tech']['prev'] : null; ?>
      <p style="margin:0 0 8px">Audyt techniczny (bezpieczeństwo, szybkość, SEO techniczne): <b><?= (int) $au['tech']['score'] ?>/100</b><?= $d ? ' <span class="' . ($d > 0 ? 'up' : 'down') . '">' . ($d > 0 ? '+' : '') . $d . ' pkt</span>' : '' ?>, <?= e(local_dt($au['tech']['at'], 'd.m.Y')) ?>.</p>
    <?php endif ?>
    <?php if (!empty($au['content'])): ?>
      <div class="table-wrap"><table>
        <thead><tr><th scope="col">Strona w audycie AI (wyszukiwarki i asystenci AI)</th><th scope="col" class="n">Wynik</th></tr></thead>
        <tbody><?php foreach ($au['content'] as $c): $d = $c['prev'] !== null ? $c['score'] - $c['prev'] : null; ?>
          <tr><td class="k"><?= e($path($c['url'])) ?></td><td class="n"><?= (int) $c['score'] ?>/100<?= $d ? ' <span class="' . ($d > 0 ? 'up' : 'down') . '">' . ($d > 0 ? '+' : '') . $d . '</span>' : '' ?></td></tr>
        <?php endforeach ?></tbody>
      </table></div>
    <?php endif ?>
  </section>
  <?php endif ?>

  <footer class="mrep-foot">
    <?= $brand['footer'] !== '' ? e($brand['footer']) . '<br>' : '' ?>
    Raport przygotował(a) <?= e(rtrim($brand['name'], '.')) ?>. Dane: Google Search Console<?= !empty($ai['assistants']) || $t ? ', Google Analytics' : '' ?><?= !empty($ai['bots']) ? ', Cloudflare' : '' ?>, stan na <?= e(local_dt((string) $mr['built_at'], 'd.m.Y')) ?>.
  </footer>
</article>
