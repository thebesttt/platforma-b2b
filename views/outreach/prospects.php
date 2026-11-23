<?php
use App\Modules\Outreach\Prospects;
use App\Modules\Tenders\Regions;
/**
 * @var array $rows firmy w wybranym stanie @var array<string,int> $counts @var string $status @var array $criteria
 * @var int $pending ile firm czeka na dane z rejestrów @var array $filters @var bool $searched @var array $candidates @var array $coverage
 */
$tabs = ['new' => 'Do oceny', 'picked' => 'Wybrane', 'contacted' => 'Po kontakcie', 'skipped' => 'Pominięte'];
$tabCount = static fn (string $k): int => $k === 'skipped' ? $counts['skipped'] + $counts['objected'] : $counts[$k];
$post = static fn (int $id, string $value, string $label, string $back): string =>
    '<form class="inline" method="post" action="/klienci/potencjalni/' . $id . '/stan">' . csrf_field()
    . '<input type="hidden" name="status" value="' . e($value) . '"><input type="hidden" name="back" value="list"><input type="hidden" name="stan" value="' . e($back) . '">'
    . '<button class="btn ghost sm" type="submit">' . e($label) . '</button></form>';
$total = array_sum($counts);
?>
<header class="between page-head">
  <div>
    <p class="crumbs"><a href="/klienci">Klienci i wiadomości</a> / Potencjalni klienci</p>
    <h1>Potencjalni klienci</h1>
    <p class="muted small">Firmy, do których warto się odezwać. Panel dopisuje dane z rejestrów, liczy dopasowanie i przygotowuje ofertę. Lista i szkic oferty nie wymagają zgody firmy. Zgody wymaga wysyłka e-mailem, dlatego na karcie firmy widzisz wszystkie drogi kontaktu z oceną ryzyka.</p>
  </div>
  <div class="row-flex">
    <a class="btn ghost" href="#kryteria">Kogo szukasz</a>
    <a class="btn" href="#znajdz">Znajdź firmy</a>
  </div>
</header>

<div class="stack">
  <?php if ($pending): ?>
    <div class="notice info" data-work-queue="/klienci/potencjalni/krok" data-csrf="<?= e(App\Core\Csrf::token()) ?>" data-left="<?= (int) $pending ?>">
      <span data-work-queue-state>Dopisuję dane z rejestrów: <?= (int) $pending ?> <?= plural($pending, 'firma czeka', 'firmy czekają', 'firm czeka') ?>. Zostaw tę kartę otwartą.</span>
      <noscript>Dane dopisze też zadanie dzienne, bez Twojego udziału.</noscript>
    </div>
  <?php endif ?>

  <section class="card flush" aria-labelledby="t-list">
    <div class="card-head">
      <h2 id="t-list">Lista</h2>
      <nav class="filters" aria-label="Stan firm">
        <?php foreach ($tabs as $k => $label): ?><a href="/klienci/potencjalni<?= $k === 'new' ? '' : '?stan=' . e($k) ?>"<?= $status === $k ? ' class="on" aria-current="page"' : '' ?>><?= e($label) ?> (<?= $tabCount($k) ?>)</a><?php endforeach ?>
      </nav>
    </div>
    <?php if ($rows): ?>
      <ul class="item-list">
        <?php foreach ($rows as $p): $id = (int) $p['id']; $facts = Prospects::facts($p); $reasons = Prospects::reasons($p, $p['company_id'] ? $p : null);
          $sub = array_filter([$p['city'], Regions::name($p['voivodeship']) ?: null, Prospects::SOURCES[$p['source']] ?? null, !empty($p['nip']) ? 'NIP ' . App\Core\Nip::format((string) $p['nip']) : $p['host']]); ?>
          <li id="f<?= $id ?>">
            <div class="item-head">
              <div>
                <a class="t-title" href="/klienci/potencjalni/<?= $id ?>"><?= e($p['name']) ?></a>
                <div class="t-sub"><?= e(implode(' · ', $sub)) ?></div>
              </div>
              <span class="row-flex" style="gap:6px">
                <?php if ($p['status'] === 'objected'): ?><span class="chip high">sprzeciw</span><?php endif ?>
                <?php if (!empty($facts['invite'])): ?><span class="chip low">zaprasza do ofert</span><?php elseif (!empty($facts['invite_found'])): ?><span class="chip mid">zaproszenie do potwierdzenia</span><?php endif ?>
                <?php if ($p['enriched_at'] === null && $p['nip']): ?><span class="chip neutral">czeka na rejestry</span><?php endif ?>
                <span class="score <?= score_class((int) $p['score']) ?>" title="Dopasowanie <?= (int) $p['score'] ?> na 100"><?= (int) $p['score'] ?></span>
              </span>
            </div>
            <?php if ($reasons): ?><p class="small" style="margin:8px 0 0"><?= e(truncate($reasons[0], 260)) ?></p><?php endif ?>
            <?php if ($p['status'] === 'contacted' && $p['contacted_at']): ?><p class="small muted" style="margin:6px 0 0"><?= $p['contacted_how'] === 'letter' ? 'List wysłany' : 'Wiadomość wysłana' ?> <?= e(local_date($p['contacted_at'])) ?>.</p><?php endif ?>
            <div class="item-actions">
              <a class="btn sm" href="/klienci/potencjalni/<?= $id ?>">Otwórz kartę</a>
              <?php if ($p['status'] === 'new'): ?><?= $post($id, 'picked', 'Wybierz', $status) ?><?= $post($id, 'skipped', 'Pomiń', $status) ?>
              <?php elseif ($p['status'] === 'picked'): ?><?= $post($id, 'skipped', 'Pomiń', $status) ?>
              <?php elseif ($p['status'] === 'skipped'): ?><?= $post($id, 'new', 'Przywróć do oceny', $status) ?><?php endif ?>
            </div>
          </li>
        <?php endforeach ?>
      </ul>
    <?php elseif ($total === 0): ?>
      <div class="empty"><h3>Lista jest pusta</h3><p>Dodaj firmy z wyników przetargów albo wklej własną listę NIP-ów i stron. Panel dopisze dane z rejestrów i ułoży firmy od najlepiej dopasowanej.</p></div>
    <?php else: ?>
      <div class="empty"><h3>Brak firm w tym stanie</h3><p>Zajrzyj do innej zakładki albo dodaj kolejne firmy poniżej.</p></div>
    <?php endif ?>
  </section>

  <section class="card" id="znajdz" aria-labelledby="t-find">
    <h2 id="t-find">Znajdź firmy</h2>
    <div class="src-grid">
      <div>
        <h3>Wykonawcy z wyników przetargów</h3>
        <p class="small muted" style="margin:6px 0 12px">Firmy, które wygrywają zamówienia publiczne: wiesz, co robią, gdzie działają i co właśnie podpisały.
          <?php if ($coverage['count']): ?>W bazie jest <?= (int) $coverage['count'] ?> <?= plural((int) $coverage['count'], 'rozstrzygnięcie', 'rozstrzygnięcia', 'rozstrzygnięć') ?> z BZP od <?= e(local_date($coverage['first_at'])) ?>.
          <?php else: ?>Wyniki pojawią się po pierwszym dziennym imporcie z BZP.<?php endif ?></p>
        <form method="get" action="/klienci/potencjalni#znajdz" class="form-grid">
          <input type="hidden" name="szukaj" value="1">
          <div class="field" style="grid-column:1/-1"><label for="pf-q">Słowa z nazwy zamówienia</label><input id="pf-q" name="q" type="text" maxlength="120" placeholder="np. remont drogi" value="<?= e($filters['q']) ?>"></div>
          <div class="field"><label for="pf-woj">Województwo wykonawcy</label><select id="pf-woj" name="woj"><option value="">Cała Polska</option><?php foreach (Regions::ALL as $slug => $r): ?><option value="<?= e($slug) ?>"<?= $filters['woj'] === $slug ? ' selected' : '' ?>><?= e($r[0]) ?></option><?php endforeach ?></select></div>
          <div class="field"><label for="pf-dni">Okres</label><select id="pf-dni" name="dni"><?php foreach ([30 => 'ostatnie 30 dni', 90 => 'ostatnie 90 dni', 180 => 'ostatnie pół roku', 365 => 'ostatni rok'] as $d => $label): ?><option value="<?= $d ?>"<?= $filters['dni'] === $d ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></div>
          <div class="field"><label for="pf-cpv">Kod CPV, opcjonalnie</label><input id="pf-cpv" name="cpv" type="text" maxlength="40" placeholder="np. 45233" value="<?= e($filters['cpv']) ?>"></div>
          <div class="field" style="align-self:end"><button class="btn ghost" type="submit">Pokaż firmy</button></div>
        </form>
      </div>
      <div>
        <h3>Twoja lista</h3>
        <p class="small muted" style="margin:6px 0 12px">Wklej firmy z katalogu branżowego, listy wystawców targów albo własnych notatek. W każdym wierszu NIP, adres strony albo jedno i drugie.</p>
        <form method="post" action="/klienci/potencjalni/lista">
          <?= csrf_field() ?>
          <div class="field"><label for="pl-lines">Firmy, po jednej w wierszu</label>
            <textarea id="pl-lines" name="lines" rows="6" placeholder="firma-budowlana.pl&#10;525-000-00-00 Nazwa firmy&#10;https://hurtownia.pl 7790000000"></textarea>
            <span class="hint">Najwyżej <?= Prospects::PASTE_LIMIT ?> wierszy naraz. Adresy e-mail pomijamy: to lista firm, a nie baza adresów do wysyłki.</span></div>
          <div style="margin-top:12px"><button class="btn ghost" type="submit">Dodaj do listy</button></div>
        </form>
      </div>
    </div>

    <?php if ($searched): ?>
      <div class="src-step" style="margin-top:20px">
        <?php if ($candidates): ?>
          <form method="post" action="/klienci/potencjalni/z-przetargow">
            <?= csrf_field() ?>
            <p class="small" style="margin:0 0 10px"><strong><?= count($candidates) ?> <?= plural(count($candidates), 'firma', 'firmy', 'firm') ?></strong> spoza Twojej listy, od najczęściej wygrywających. Zaznacz te, które chcesz ocenić.</p>
            <div class="table-wrap">
              <table>
                <thead><tr><th scope="col"><span class="sr-only">Zaznacz</span></th><th scope="col">Firma</th><th scope="col" class="n">Wygrane</th><th scope="col" class="n">Wartość</th><th scope="col">Ostatnia umowa</th></tr></thead>
                <tbody>
                  <?php foreach ($candidates as $i => $c): ?>
                    <tr>
                      <td><input type="checkbox" id="cand-<?= $i ?>" name="nips[]" value="<?= e($c['winner_nip']) ?>" style="width:18px;height:18px"></td>
                      <td><label for="cand-<?= $i ?>"><strong><?= e($c['winner_name']) ?></strong></label><div class="t-sub"><?= e(implode(' · ', array_filter([$c['winner_city'], Regions::name($c['winner_voivodeship']) ?: null, 'NIP ' . App\Core\Nip::format((string) $c['winner_nip'])]))) ?></div></td>
                      <td class="n"><?= (int) $c['wins'] ?></td>
                      <td class="n nowrap"><?= (float) $c['total'] > 0 ? e(money_pl((float) $c['total'])) : '<span class="muted">brak danych</span>' ?></td>
                      <td class="nowrap"><?= e(local_date($c['last_at'])) ?></td>
                    </tr>
                  <?php endforeach ?>
                </tbody>
              </table>
            </div>
            <div style="margin-top:12px"><button class="btn" type="submit">Dodaj zaznaczone do listy</button></div>
          </form>
        <?php else: ?>
          <p class="small" style="margin:0">Przy tych filtrach nie ma nowych firm. Zmień słowa, okres albo województwo. Firmy, które już są na Twojej liście, nie wracają w wynikach.</p>
        <?php endif ?>
      </div>
    <?php endif ?>
  </section>

  <section class="card" id="kryteria" aria-labelledby="t-crit">
    <h2 id="t-crit">Kogo szukasz</h2>
    <p class="small muted" style="margin:4px 0 14px">Z tych ustawień powstają punkty dopasowania: branża 40, region 15, staż firmy 10, kondycja 15, powód kontaktu 20. Każda firma ma na karcie rozpisane, skąd są jej punkty.</p>
    <form method="post" action="/klienci/potencjalni/kryteria" class="stack-sm">
      <?= csrf_field() ?>
      <div class="form-grid two">
        <div class="field"><label for="pc-kw">Słowa opisujące branżę klientów</label><textarea id="pc-kw" name="keywords" rows="4" placeholder="np.&#10;budow&#10;transport&#10;hurtowni"><?= e($criteria['keywords']) ?></textarea><span class="hint">Po jednym w wierszu. Wystarczy początek słowa: „budow” znajdzie „budowlane” i „budowa”. Szukamy w nazwie firmy, opisie działalności i wygranych zamówieniach.</span></div>
        <div class="field"><label for="pc-years">Firma działa co najmniej (lat)</label><input id="pc-years" name="min_years" type="number" min="0" max="30" value="<?= (int) $criteria['min_years'] ?>"></div>
      </div>
      <fieldset style="border:0;padding:0;margin:0">
        <legend class="label">Województwa</legend>
        <p class="hint muted small" style="margin:4px 0 8px">Nie zaznaczaj żadnego, jeśli szukasz w całej Polsce.</p>
        <div class="form-grid" style="gap:0 16px">
          <?php foreach (Regions::ALL as $slug => $r): ?><label class="check"><input type="checkbox" name="regions[]" value="<?= e($slug) ?>"<?= in_array($slug, $criteria['regions'], true) ? ' checked' : '' ?>> <?= e($r[0]) ?></label><?php endforeach ?>
        </div>
      </fieldset>
      <div><button class="btn ghost" type="submit">Zapisz i przelicz punkty</button></div>
    </form>
  </section>
</div>
