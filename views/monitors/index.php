<?php
use App\Modules\Notify\Monitors;
use App\Modules\Notify\Notices;

/** @var array $monitors @var array $ctx @var ?array $draft @var array $notices @var ?int $limit @var int $used @var bool $aiOn */
$sites = $ctx['sites'];
$kinds = array_filter(Monitors::KINDS, static fn (array $k): bool => $k[1] === null || in_array($k[1], $ctx['modules'], true));
$examples = [
    'gsc_clicks' => 'Powiadom mnie, gdy kliknięcia z Google spadną o 30%.',
    'site_down' => 'Daj znać, gdy strona firma.pl/kontakt nie działa albo ładuje się dłużej niż 5 s.',
    'inbound_silence' => 'Powiadom, gdy przez 5 dni nie przyjdzie żadne zapytanie ze strony.',
    'tender' => 'Powiadom o przetargach na „pozycjonowanie” od 50 tys. zł.',
    'gsc_position' => 'Daj znać, gdy „ekspres kolbowy” spadnie poza top 10.',
    'rival_pages' => 'Powiadom, gdy konkurent opublikuje 3 nowe strony w tygodniu.',
];
$val = static fn (string $k, mixed $def = '') => $draft['params'][$k] ?? $def;
$siteSelect = static function () use ($sites, $val): string {
    $h = '<div class="field"><label for="mn-site">Witryna</label><select id="mn-site" name="site_id" required>';
    $h .= count($sites) === 1 ? '' : '<option value="">wybierz</option>';
    foreach ($sites as $id => $host) {
        $h .= '<option value="' . (int) $id . '"' . ((int) $val('site_id', 0) === (int) $id ? ' selected' : '') . '>' . e($host) . '</option>';
    }
    return $h . '</select></div>';
};
?>
<header class="page-head">
  <h1>Monitory</h1>
  <p class="muted small">Opisz jednym zdaniem, czego panel ma pilnować. Panel zamieni zdanie na regułę z jawnym progiem i sprawdza ją codziennie rano. Monitory tylko czytają dane, niczego nie zmieniają.</p>
</header>

<section class="card" id="nowy" aria-labelledby="mn-new">
  <h2 id="mn-new" style="margin-bottom:6px">Nowy monitor</h2>
  <?php if ($draft): ?>
    <?php if (!empty($draft['note'])): ?><p class="notice <?= $draft['kind'] === '' ? 'mid' : 'info' ?>" style="margin:0 0 12px"><?= e((string) $draft['note']) ?></p><?php endif ?>
    <?php if ($draft['sentence'] !== ''): ?><p class="small muted" style="margin:0 0 6px">Twoje zdanie: „<?= e((string) $draft['sentence']) ?>”</p><?php endif ?>
    <?php if ($draft['kind'] !== '' && isset($kinds[$draft['kind']])): $k = (string) $draft['kind']; ?>
      <p style="margin:0 0 4px"><b>Tak to rozumiem: <?= e(Monitors::KINDS[$k][0]) ?>.</b> Powiadom, gdy…</p>
      <?php $preview = Monitors::normalize($k, $draft['params'], $ctx); if ($preview['ok']): ?><p style="margin:0 0 12px"><?= e(Monitors::describe($k, $preview['params'], $sites)) ?></p><?php endif ?>
      <form method="post" action="/monitory" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="rodzaj" value="<?= e($k) ?>">
        <?php if (Monitors::KINDS[$k][2]): ?><?= $siteSelect() ?><?php endif ?>
        <?php if ($k === 'gsc_clicks'): ?>
          <div class="field"><label for="mn-pct">Spadek o co najmniej (%)</label><input id="mn-pct" name="pct" type="number" min="10" max="90" value="<?= e((string) $val('pct', Monitors::LIMITS['pct'][0])) ?>"></div>
        <?php elseif ($k === 'gsc_position'): ?>
          <div class="field"><label for="mn-q">Zapytanie</label><input id="mn-q" name="query" type="text" maxlength="120" value="<?= e((string) $val('query')) ?>" required></div>
          <div class="field"><label for="mn-pos">Gorsza niż pozycja</label><input id="mn-pos" name="position" type="number" min="1" max="100" value="<?= e((string) $val('position', Monitors::LIMITS['position'][0])) ?>"></div>
        <?php elseif ($k === 'rival_pages'): ?>
          <div class="field"><label for="mn-rival">Konkurent (puste: wszyscy z listy)</label><input id="mn-rival" name="rival" type="text" value="<?= e((string) $val('rival')) ?>" placeholder="konkurent.pl"></div>
          <div class="field"><label for="mn-cnt">Nowych stron w 7 dni, co najmniej</label><input id="mn-cnt" name="count" type="number" min="1" max="100" value="<?= e((string) $val('count', Monitors::LIMITS['count'][0])) ?>"></div>
        <?php elseif ($k === 'site_down'): ?>
          <div class="field"><label for="mn-url">Adres strony</label><input id="mn-url" name="url" type="url" maxlength="300" value="<?= e((string) $val('url')) ?>" placeholder="https://firma.pl/kontakt" required></div>
          <div class="field"><label for="mn-sec">Wolniej niż (sekundy)</label><input id="mn-sec" name="seconds" type="number" min="1" max="30" value="<?= e((string) $val('seconds', Monitors::LIMITS['seconds'][0])) ?>"></div>
        <?php elseif ($k === 'tender'): ?>
          <div class="field"><label for="mn-ph">Fraza w tytule przetargu</label><input id="mn-ph" name="phrase" type="text" maxlength="80" value="<?= e((string) $val('phrase')) ?>" required></div>
          <div class="field"><label for="mn-min">Wartość od (zł, opcjonalnie)</label><input id="mn-min" name="min_value" type="text" inputmode="decimal" value="<?= e((string) ($val('min_value') ?? '')) ?>"></div>
        <?php elseif ($k === 'inbound_silence'): ?>
          <div class="field"><label for="mn-days">Dni bez zapytania</label><input id="mn-days" name="days" type="number" min="2" max="60" value="<?= e((string) $val('days', Monitors::LIMITS['days'][0])) ?>"></div>
        <?php endif ?>
        <div class="field" style="justify-content:flex-end;flex-direction:row;gap:8px;align-items:flex-end">
          <button class="btn" type="submit">Zapisz monitor</button>
        </div>
      </form>
      <form method="post" action="/monitory/anuluj" style="margin-top:8px"><?= csrf_field() ?><button class="btn ghost sm" type="submit">Anuluj</button></form>
    <?php else: ?>
      <form method="post" action="/monitory/rozpoznaj" class="searchbar">
        <?= csrf_field() ?>
        <input type="hidden" name="zdanie" value="<?= e((string) $draft['sentence']) ?>">
        <label class="sr-only" for="mn-kind2">Rodzaj monitora</label>
        <select id="mn-kind2" name="rodzaj" required><option value="">wybierz rodzaj</option><?php foreach ($kinds as $k => $def): ?><option value="<?= e($k) ?>"><?= e($def[0]) ?></option><?php endforeach ?></select>
        <button class="btn" type="submit">Dalej</button>
      </form>
      <form method="post" action="/monitory/anuluj" style="margin-top:8px"><?= csrf_field() ?><button class="btn ghost sm" type="submit">Anuluj</button></form>
    <?php endif ?>
  <?php elseif ($limit !== null && $used >= $limit): ?>
    <p class="notice mid" style="margin:0">Masz <?= (int) $used ?> z <?= (int) $limit ?> monitorów w pakiecie. Usuń monitor, którego nie potrzebujesz, albo zmień pakiet.</p>
  <?php else: ?>
    <form method="post" action="/monitory/rozpoznaj" class="searchbar">
      <?= csrf_field() ?>
      <label class="sr-only" for="mn-sentence">Czego panel ma pilnować</label>
      <input id="mn-sentence" name="zdanie" type="text" maxlength="400" placeholder="Powiadom mnie, gdy…" required>
      <button class="btn" type="submit" data-busy="Rozpoznaję…">Rozpoznaj</button>
    </form>
    <p class="small muted" style="margin:10px 0 4px">Przykłady:</p>
    <ul class="small" style="margin:0;padding-left:18px">
      <?php foreach (array_intersect_key($examples, $kinds) as $ex): ?><li><?= e($ex) ?></li><?php endforeach ?>
    </ul>
    <details style="margin-top:12px">
      <summary class="small">Wolisz wybrać z listy?</summary>
      <form method="post" action="/monitory/rozpoznaj" class="searchbar" style="margin-top:8px">
        <?= csrf_field() ?>
        <label class="sr-only" for="mn-kind">Rodzaj monitora</label>
        <select id="mn-kind" name="rodzaj" required><option value="">wybierz rodzaj</option><?php foreach ($kinds as $k => $def): ?><option value="<?= e($k) ?>"><?= e($def[0]) ?></option><?php endforeach ?></select>
        <button class="btn ghost" type="submit">Dalej</button>
      </form>
    </details>
    <p class="sec-note" style="margin-top:12px">Proste zdania panel rozpoznaje sam.<?= $aiOn ? ' Gdy nie rozpozna, pomaga AI (jedno użycie z pakietu na zdanie). Codzienne sprawdzanie działa bez AI.' : '' ?><?= $limit !== null ? ' Monitory w pakiecie: ' . (int) $used . ' z ' . (int) $limit . '.' : '' ?></p>
  <?php endif ?>
</section>

<section class="card flush" aria-labelledby="mn-list">
  <div class="card-head"><h2 id="mn-list">Twoje monitory</h2></div>
  <?php if (!$monitors): ?>
    <p class="muted small" style="padding:0 20px 18px">Nie ma jeszcze monitorów. Zacznij od zdania powyżej.</p>
  <?php else: ?>
  <ul class="list-plain" style="padding:0 20px 18px">
    <?php foreach ($monitors as $m): $p = json_in($m['params_json']); $st = json_in($m['state_json']); ?>
      <li<?= (int) $m['active'] ? '' : ' class="muted"' ?>>
        <div style="font-weight:600">Powiadom, gdy: <?= e(Monitors::describe((string) $m['kind'], $p, $sites)) ?></div>
        <div class="small muted">„<?= e(truncate((string) $m['sentence'], 160)) ?>”<?= $m['parsed_by'] === 'ai' ? ' · rozpoznało AI' : '' ?><?= (int) $m['active'] ? '' : ' · wstrzymany' ?></div>
        <div style="margin-top:6px">
          <?php if (!$m['checked_at']): ?><span class="muted small">Jeszcze nie sprawdzono: pierwsze sprawdzenie jutro rano.</span>
          <?php else: ?>
            <span class="chip <?= !empty($st['hit']) ? 'high' : (!empty($st['na']) ? 'neutral' : 'low') ?>"><?= !empty($st['hit']) ? 'warunek spełniony' : (!empty($st['na']) ? 'brak danych' : 'w normie') ?></span>
            <span class="small"><?= e((string) ($st['detail'] ?? '')) ?></span> <span class="small muted"><?= e(local_dt((string) $m['checked_at'], 'd.m H:i')) ?></span>
          <?php endif ?>
        </div>
        <div class="row-flex" style="gap:6px;flex-wrap:wrap;margin-top:8px">
          <form method="post" action="/monitory/<?= (int) $m['id'] ?>/sprawdz"><?= csrf_field() ?><button class="btn ghost sm" type="submit" data-busy="Sprawdzam…">Sprawdź teraz</button></form>
          <form method="post" action="/monitory/<?= (int) $m['id'] ?>/wlacz"><?= csrf_field() ?><button class="btn ghost sm" type="submit"><?= (int) $m['active'] ? 'Wstrzymaj' : 'Włącz' ?></button></form>
          <form method="post" action="/monitory/<?= (int) $m['id'] ?>/usun" data-confirm="Usunąć ten monitor?"><?= csrf_field() ?><button class="btn ghost sm" type="submit">Usuń</button></form>
        </div>
      </li>
    <?php endforeach ?>
  </ul>
  <?php endif ?>
</section>

<section class="card" aria-labelledby="mn-notices">
  <div class="card-head" style="padding:0;margin-bottom:8px"><h2 id="mn-notices">Powiadomienia</h2>
    <?php if (Notices::unread((int) \App\Core\Auth::orgId())): ?><form method="post" action="/monitory/przeczytane"><?= csrf_field() ?><button class="btn ghost sm" type="submit">Oznacz wszystkie jako przeczytane</button></form><?php endif ?></div>
  <?php if (!$notices): ?>
    <p class="muted small" style="margin:0">Tu pojawią się powiadomienia z monitorów, alarm pomiaru Google Analytics i raporty miesięczne do przejrzenia. Te same trafiają do porannego przeglądu e-mailem.</p>
  <?php else: ?>
  <ul class="list-plain">
    <?php foreach ($notices as $x): ?>
      <li>
        <span class="chip <?= $x['level'] === 'high' ? 'high' : ($x['level'] === 'mid' ? 'mid' : 'info') ?>"><?= e(Notices::KINDS[$x['kind']] ?? 'Monitor') ?><?= $x['read_at'] ? '' : ', nowe' ?></span>
        <div style="font-weight:600;margin-top:6px"><?php if ($x['url']): ?><a href="<?= e(str_starts_with((string) $x['url'], '/') ? (string) $x['url'] : '/monitory') ?>"><?= e($x['title']) ?></a><?php else: ?><?= e($x['title']) ?><?php endif ?></div>
        <div class="small muted"><?= e((string) $x['detail']) ?> · <?= e(local_dt((string) $x['created_at'], 'd.m H:i')) ?></div>
      </li>
    <?php endforeach ?>
  </ul>
  <?php endif ?>
</section>
