<?php
use App\Http\OfferController;
use App\Modules\Offers\GrantCalls;
/**
 * @var array $current nabory aktualne @var array $past zakończone i odrzucone
 * @var array $form wartości formularza (puste przy nowym) @var int $editId poprawiany nabór albo 0
 */
$v = static fn (string $k): string => e((string) ($form[$k] ?? ''));
$d = static fn (?string $date): string => e(date('d.m.Y', strtotime(substr((string) $date, 0, 10))));
$window = static function (array $g) use ($d): string {
    $opens = days_to_date($g['opens_at']);
    $closes = days_to_date($g['closes_at']);
    if ($opens !== null && $opens > 0) {
        return '<span class="chip info">start ' . $d($g['opens_at']) . ' (za ' . $opens . ' ' . plural($opens, 'dzień', 'dni', 'dni') . ')</span>';
    }
    if ($closes === null) {
        return '<span class="chip neutral">bez daty końca</span>';
    }
    return match (true) {
        $closes < 0 => '<span class="chip neutral">zakończony ' . $d($g['closes_at']) . '</span>',
        $closes === 0 => '<span class="chip high">koniec dziś</span>',
        $closes === 1 => '<span class="chip high">koniec jutro</span>',
        $closes <= 7 => '<span class="chip high">koniec za ' . $closes . ' dni (' . $d($g['closes_at']) . ')</span>',
        $closes <= GrantCalls::BOARD_DAYS => '<span class="chip mid">koniec za ' . $closes . ' dni (' . $d($g['closes_at']) . ')</span>',
        default => '<span class="chip low">do ' . $d($g['closes_at']) . ' (' . $closes . ' dni)</span>',
    };
};
$post = static fn (int $id, string $field, string $value, string $label, string $class = 'btn ghost sm', string $confirm = ''): string =>
    '<form class="inline" method="post" action="/oferty/nabory/' . $id . '/stan"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field()
    . '<input type="hidden" name="' . $field . '" value="' . e($value) . '"><button class="' . $class . '" type="submit">' . e($label) . '</button></form>';
$title = static fn (array $g): string => $g['url']
    ? '<a class="t-title" href="' . e(safe_url($g['url'])) . '" target="_blank" rel="noopener noreferrer">' . e($g['name']) . '</a>'
    : '<span class="t-title">' . e($g['name']) . '</span>';
?>
<header class="between page-head">
  <div>
    <p class="crumbs"><a href="/oferty">Oferty i wnioski</a> / Nabory dotacji</p>
    <h1>Nabory dotacji</h1>
    <p class="muted small">Opisz, na co szukasz pieniędzy, a wyszukiwarka znajdzie nabory i poda źródła. Dla obserwowanych naborów panel pilnuje terminu i pisze szkic wniosku.</p>
  </div>
  <div class="row-flex">
    <a class="btn" href="#szukaj">Szukaj naborów</a>
    <a class="btn ghost" href="#dodaj">Dodaj ręcznie</a>
  </div>
</header>

<div class="stack">
  <section class="card" id="szukaj" aria-labelledby="t-find">
    <h2 id="t-find">Znajdź nabory dla firmy</h2>
    <p class="muted small" style="margin:4px 0 14px">AI przeszukuje internet, wybiera nabory, które trwają albo są zapowiedziane, i przy każdym podaje stronę, na której go znalazła. To propozycje do sprawdzenia: termin i warunki potwierdź u instytucji.</p>
    <?php if (!$aiEnabled): ?>
      <div class="notice info">Wyszukiwarka korzysta z AI, a funkcje AI są w tym panelu wyłączone. Nabór możesz wpisać ręcznie niżej.</div>
    <?php else: ?>
    <form method="post" action="/oferty/nabory/szukaj" class="form-grid two">
      <?= csrf_field() ?>
      <div class="field" style="grid-column:1/-1"><label for="gf-what">Na co szukasz dofinansowania</label><textarea id="gf-what" name="what" rows="2" maxlength="600" required placeholder="np. wdrożenie systemu CRM i automatyzacja sprzedaży, szkolenia dla pracowników, wejście na rynek niemiecki"><?= e($query['what']) ?></textarea></div>
      <div class="field"><label for="gf-region">Województwo</label><select id="gf-region" name="region"><option value="">cała Polska</option><?php foreach (App\Modules\Tenders\Regions::ALL as $slug => $name): ?><option value="<?= e($slug) ?>"<?= $query['region'] === $slug ? ' selected' : '' ?>><?= e(App\Modules\Tenders\Regions::name($slug)) ?></option><?php endforeach ?></select></div>
      <div class="field"><label for="gf-size">Wielkość firmy</label><select id="gf-size" name="size"><option value="">nie podaję</option><?php foreach (App\Modules\Offers\GrantFinder::SIZES as $k => $label): ?><option value="<?= e($k) ?>"<?= $query['size'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></div>
      <div class="field" style="grid-column:1/-1"><div class="row-flex"><button class="btn" type="submit" data-busy="Szukam naborów, to trwa do dwóch minut…">Szukaj naborów</button>
        <span class="small muted"><?= $plan['limit'] === null ? 'Jedno wyszukiwanie to jedno użycie AI.' : 'Jedno wyszukiwanie to jedno użycie AI. W tym miesiącu: ' . (int) $plan['used'] . ' z ' . (int) $plan['limit'] . ' w pakiecie ' . e($plan['name']) . '.' ?></span></div></div>
    </form>
    <p class="small muted" style="margin:12px 0 0"><?php if ($lastSearch): ?>Ostatnie wyszukiwanie: <?= e(local_dt((string) $lastSearch['created_at'])) ?><?= (int) $lastSearch['auto'] === 1 ? ' (automatyczne)' : '' ?><?= $lastSearch['error'] ? ', nieudane' : ', nowych naborów: ' . (int) $lastSearch['found'] ?>. <?php endif ?><?= $plan['auto'] ? 'Panel powtarza to wyszukiwanie co tydzień i dopisuje nowe nabory.' : 'W pakiecie Rozszerzonym panel powtarza wyszukiwanie co tydzień sam.' ?></p>
    <?php endif ?>
  </section>

  <?php if ($finds): ?>
  <section class="card flush" id="znalezione" aria-labelledby="t-found">
    <div class="card-head"><h2 id="t-found">Znalezione nabory</h2><span class="muted xs"><?= count($finds) ?> do przejrzenia, najbliższy koniec na górze</span></div>
    <ul class="item-list">
      <?php foreach ($finds as $f): $fid = (int) $f['id']; $host = preg_replace('/^www\./', '', (string) parse_url((string) $f['url'], PHP_URL_HOST)); ?>
        <li id="z<?= $fid ?>">
          <div class="item-head">
            <div>
              <a class="t-title" href="<?= e(safe_url($f['url'])) ?>" target="_blank" rel="noopener noreferrer"><?= e($f['name']) ?></a>
              <div class="t-sub"><?= $f['institution'] ? e($f['institution']) . ', ' : '' ?>źródło: <?= e($host) ?> <?= (int) $f['official'] === 1 ? '<span class="chip low">strona urzędowa</span>' : '<span class="chip mid">inne źródło, sprawdź u instytucji</span>' ?></div>
            </div>
            <?= $window($f) ?>
          </div>
          <?php if ($f['fit']): ?><p class="small" style="margin:8px 0 0"><?= e($f['fit']) ?></p><?php endif ?>
          <?php if ($f['amount_note'] || $f['audience_note']): ?>
            <p class="small" style="margin:8px 0 0"><?php if ($f['amount_note']): ?><strong>Ile:</strong> <?= e($f['amount_note']) ?><?php endif ?><?= $f['amount_note'] && $f['audience_note'] ? '<br>' : '' ?><?php if ($f['audience_note']): ?><strong>Dla kogo:</strong> <?= e($f['audience_note']) ?><?php endif ?></p>
          <?php endif ?>
          <?php if ($f['quote']): ?><p class="small muted" style="margin:8px 0 0">Ze strony: „<?= e($f['quote']) ?>”</p><?php endif ?>
          <div class="item-actions">
            <?php foreach ([['wniosek', 'Pisz wniosek', 'btn sm'], ['obserwuj', 'Obserwuj', 'btn ghost sm'], ['odrzuc', 'Nie dla nas', 'btn ghost sm']] as [$akcja, $label, $class]): ?>
              <form class="inline" method="post" action="/oferty/nabory/znalezione/<?= $fid ?>"><?= csrf_field() ?><input type="hidden" name="akcja" value="<?= $akcja ?>"><button class="<?= $class ?>" type="submit"><?= $label ?></button></form>
            <?php endforeach ?>
            <span class="xs muted">znaleziono <?= e(local_dt((string) $f['created_at'], 'd.m.Y')) ?></span>
          </div>
        </li>
      <?php endforeach ?>
    </ul>
    <p class="sec-note" style="padding-top:12px">Listę przygotowała AI na podstawie wyszukiwarki internetowej. Może pominąć nabór albo źle odczytać datę, dlatego przed pisaniem wniosku otwórz stronę naboru i sprawdź termin oraz regulamin.</p>
  </section>
  <?php endif ?>

<?php ob_start(); ?>
  <section class="card flush" aria-labelledby="t-cur">
    <div class="card-head"><h2 id="t-cur">Obserwowane nabory</h2><span class="muted xs"><?= count($current) ?> <?= plural(count($current), 'nabór', 'nabory', 'naborów') ?>, najbliższy koniec na górze</span></div>
    <?php if ($current): ?>
      <ul class="item-list">
        <?php foreach ($current as $g): $id = (int) $g['id']; ?>
          <li id="n<?= $id ?>">
            <div class="item-head">
              <div>
                <?= $title($g) ?>
                <?php if ($g['institution']): ?><div class="t-sub"><?= e($g['institution']) ?></div><?php endif ?>
              </div>
              <?= $window($g) ?>
            </div>
            <?php if ($g['amount_note'] || $g['audience_note']): ?>
              <p class="small" style="margin:8px 0 0"><?php if ($g['amount_note']): ?><strong>Ile:</strong> <?= e($g['amount_note']) ?><?php endif ?><?= $g['amount_note'] && $g['audience_note'] ? '<br>' : '' ?><?php if ($g['audience_note']): ?><strong>Dla kogo:</strong> <?= e($g['audience_note']) ?><?php endif ?></p>
            <?php endif ?>
            <?php if ($g['notes']): ?><p class="small muted pre" style="margin:8px 0 0"><?= e($g['notes']) ?></p><?php endif ?>
            <div class="item-actions">
              <?php if ($g['offer_title'] !== null): $st = OfferController::STATUSES[$g['offer_status']] ?? OfferController::STATUSES['draft']; ?>
                <a class="btn sm" href="/oferty/<?= (int) $g['offer_id'] ?>">Otwórz wniosek</a>
                <span class="chip <?= $st['chip'] ?>">wniosek: <?= e(mb_strtolower($st['label'])) ?></span>
              <?php else: ?>
                <?= $post($id, 'start', '1', 'Rozpocznij wniosek', 'btn sm') ?>
              <?php endif ?>
              <?= $post($id, 'status', 'skip', 'Nie dla nas') ?>
              <a class="btn ghost sm" href="/oferty/nabory?edytuj=<?= $id ?>#dodaj">Popraw</a>
            </div>
          </li>
        <?php endforeach ?>
      </ul>
    <?php else: ?>
      <div class="empty"><h3>Nie obserwujesz żadnego naboru</h3><p>Użyj wyszukiwarki na górze strony albo wpisz nabór ręcznie. Panel przypomni o końcu naboru i założy szkic wniosku.</p></div>
    <?php endif ?>
  </section>
<?php $list = ob_get_clean(); ob_start(); ?>
  <section class="card" id="zrodla" aria-labelledby="t-src">
    <h2 id="t-src">Oficjalne listy naborów</h2>
    <p class="muted small" style="margin:4px 0 0">Pełne listy prowadzą serwisy funduszy. Są zabezpieczone przed automatami, więc panel ich nie pobiera: tu sprawdzisz termin i regulamin, a nabór spoza wyszukiwarki wpiszesz ręcznie.</p>
    <div class="src-grid">
      <?php foreach (GrantCalls::SOURCES as $s): ?>
        <div>
          <h3><?= e($s['name']) ?></h3>
          <p class="small" style="margin:4px 0 0"><a href="<?= e($s['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($s['link']) ?></a></p>
          <p class="small" style="margin:8px 0 0"><?= e($s['what']) ?></p>
        </div>
      <?php endforeach ?>
    </div>
    <div class="src-step" style="margin-top:16px">
      <p class="small" style="margin:0"><strong>Co przepisać z ogłoszenia:</strong> nazwę naboru, instytucję, która go prowadzi, daty początku i końca, kwotę albo poziom dofinansowania, kto może się ubiegać, oraz link do strony naboru. Regulamin i kryteria oceny wgrasz później do szkicu wniosku: panel sprawdzi na ich podstawie warunki udziału.</p>
    </div>
  </section>

  <section class="card" id="dodaj" aria-labelledby="t-add">
    <h2 id="t-add"><?= $editId ? 'Popraw nabór' : 'Wpisz znaleziony nabór' ?></h2>
    <p class="muted small" style="margin:4px 0 14px">Wystarczy nazwa. Z datą końca panel przypomni o naborze 14, 7 i 3 dni wcześniej.</p>
    <form method="post" action="/oferty/nabory<?= $editId ? '/' . $editId : '' ?>" class="form-grid two">
      <?= csrf_field() ?>
      <div class="field" style="grid-column:1/-1"><label for="gc-name">Nabór albo program</label><input id="gc-name" name="name" type="text" maxlength="200" required placeholder="np. FENG - Ścieżka SMART, nabór 2026" value="<?= $v('name') ?>"></div>
      <div class="field"><label for="gc-inst">Instytucja prowadząca nabór, opcjonalnie</label><input id="gc-inst" name="institution" type="text" maxlength="200" placeholder="np. PARP" value="<?= $v('institution') ?>"></div>
      <div class="field"><label for="gc-url">Link do strony naboru</label><input id="gc-url" name="url" type="url" maxlength="500" placeholder="https://" value="<?= $v('url') ?: $v('url_raw') ?>"></div>
      <div class="field"><label for="gc-opens">Początek naboru, opcjonalnie</label><input id="gc-opens" name="opens_at" type="date" value="<?= e(substr((string) ($form['opens_at'] ?? ''), 0, 10)) ?>"></div>
      <div class="field"><label for="gc-closes">Koniec naboru</label><input id="gc-closes" name="closes_at" type="date" value="<?= e(substr((string) ($form['closes_at'] ?? ''), 0, 10)) ?>"><span class="hint">Godzinę zamknięcia sprawdź w regulaminie.</span></div>
      <div class="field"><label for="gc-amount">Kwota albo poziom dofinansowania, opcjonalnie</label><input id="gc-amount" name="amount_note" type="text" maxlength="300" placeholder="np. do 500 000 zł, dofinansowanie do 50%" value="<?= $v('amount_note') ?>"></div>
      <div class="field"><label for="gc-aud">Kto może się ubiegać, opcjonalnie</label><input id="gc-aud" name="audience_note" type="text" maxlength="300" placeholder="np. małe i średnie firmy z województwa mazowieckiego" value="<?= $v('audience_note') ?>"></div>
      <div class="field" style="grid-column:1/-1"><label for="gc-notes">Notatka, opcjonalnie</label><textarea id="gc-notes" name="notes" rows="2" maxlength="2000" placeholder="np. wymagany wkład własny, na co można przeznaczyć pieniądze"><?= $v('notes') ?></textarea></div>
      <div class="field" style="grid-column:1/-1"><div class="row-flex"><button class="btn" type="submit"><?= $editId ? 'Zapisz zmiany' : 'Zapisz nabór' ?></button><?php if ($editId): ?><a class="btn ghost" href="/oferty/nabory">Anuluj</a><?php endif ?></div></div>
    </form>
  </section>
<?php $guide = ob_get_clean(); ?>
  <?= $current || $editId ? $list . $guide : $guide . $list ?>

  <?php if ($past): ?>
  <section class="card flush" aria-labelledby="t-past">
    <div class="card-head"><h2 id="t-past">Zakończone i odrzucone</h2><span class="muted xs">ostatnie <?= count($past) ?></span></div>
    <ul class="item-list">
      <?php foreach ($past as $g): $id = (int) $g['id']; ?>
        <li id="n<?= $id ?>">
          <div class="item-head">
            <div>
              <?= $title($g) ?>
              <?php if ($g['institution']): ?><div class="t-sub"><?= e($g['institution']) ?></div><?php endif ?>
            </div>
            <?= $g['status'] === 'skip' ? '<span class="chip neutral">nie dla nas</span>' : $window($g) ?>
          </div>
          <div class="item-actions">
            <?php if ($g['offer_title'] !== null): ?><a class="btn ghost sm" href="/oferty/<?= (int) $g['offer_id'] ?>">Otwórz wniosek</a><?php endif ?>
            <?php if ($g['status'] === 'skip'): ?><?= $post($id, 'status', 'watch', 'Przywróć do obserwowanych') ?><?php else: ?><a class="btn ghost sm" href="/oferty/nabory?edytuj=<?= $id ?>#dodaj">Popraw daty</a><?php endif ?>
            <?= $post($id, 'delete', '1', 'Usuń', 'btn ghost sm', 'Usunąć ten nabór z listy?') ?>
          </div>
        </li>
      <?php endforeach ?>
    </ul>
  </section>
  <?php endif ?>
</div>
