<?php
use App\Http\OfferController;

$st = OfferController::STATUSES[$offer['status']] ?? OfferController::STATUSES['draft'];
$isGrant = $offer['kind'] === 'grant';
$base = '/oferty/' . (int) $offer['id'];
$deadline = $tender['deadline_at'] ?? $offer['deadline_at'];
/** @var array $team @var int $me @var array $checklist @var bool $showDocs @var bool $showTerms @var ?array $eligibility @var ?array $draft @var ?array $conflict */
$short = static function (?int $id) use ($team, $me): string {
    if (!$id || !isset($team[$id])) {
        return '';
    }
    if ($id === $me) {
        return 'Ty';
    }
    // „Anna Demo” → „Anna D.”, adres e-mail → część przed małpą.
    $parts = preg_split('/\s+/', $team[$id]['name']) ?: [];
    return count($parts) > 1 ? $parts[0] . ' ' . mb_substr((string) end($parts), 0, 1) . '.' : explode('@', $team[$id]['name'])[0];
};
$docsDone = count(array_filter($checklist, static fn ($c) => (int) $c['done'] === 1));
$docsKey = OfferController::DOCS_KEY;
?>
<?php if (!empty($writing['queue'])): $left = count($writing['queue']); $total = $left + (int) $writing['written']; ?>
<div class="notice info no-print" role="status" style="margin-bottom:16px">
  <form method="post" action="<?= $base ?>/napisz" class="row-flex" data-auto-submit>
    <?= csrf_field() ?>
    <input type="hidden" name="kolejka" value="<?= e(implode(',', $writing['queue'])) ?>"><input type="hidden" name="napisane" value="<?= (int) $writing['written'] ?>">
    <span><strong>AI pisze <?= $isGrant ? 'wniosek' : 'ofertę' ?>:</strong> gotowe <?= (int) $writing['written'] ?> z <?= $total ?> sekcji. Nie zamykaj tej strony.</span>
    <button class="btn sm" type="submit" data-busy="AI pisze kolejną sekcję…">Pisz dalej</button>
    <a class="btn ghost sm" href="<?= $base ?>">Zatrzymaj</a>
  </form>
</div>
<?php endif ?>
<header class="between page-head">
  <div class="stack-sm">
    <p class="crumbs"><a href="/oferty">Oferty i wnioski</a><?php if ($tender): ?> / <a href="/przetargi/<?= (int) $tender['id'] ?>"><?= e(truncate((string) $tender['title'], 50)) ?></a><?php endif ?> / Szkic</p>
    <div class="row-flex">
      <span class="chip <?= $st['chip'] ?>"><?= e($st['label']) ?></span>
      <span class="chip <?= $unsourced ? 'high' : 'low' ?>"><?= $unsourced ? $unsourced . ' ' . plural($unsourced, 'zdanie', 'zdania', 'zdań') . ' bez źródła' : 'Każde zdanie ma źródło' ?></span>
      <?php if ($deadline): ?><span class="chip mid">Termin: <?= e(local_dt($deadline, 'd.m.Y H:i')) ?></span><?php endif ?>
    </div>
    <h1><?= e($offer['title']) ?></h1>
    <p class="muted small" style="margin:0">AI pisze tylko na podstawie <?= $isGrant ? 'regulaminu naboru' : 'ogłoszenia i SWZ' ?> oraz Twojej biblioteki. Zdania bez źródła są podświetlone na czerwono.</p>
  </div>
  <div class="row-flex no-print">
    <?php $openSections = count(array_filter($sections, static fn (array $s): bool => $s['status'] !== 'done')); ?>
    <?php if ($openSections && empty($writing['queue'])): ?>
    <form class="inline" method="post" action="<?= $base ?>/napisz" data-confirm="AI napisze od nowa <?= $openSections ?> <?= plural($openSections, 'sekcję', 'sekcje', 'sekcji') ?> (wszystkie, które nie są oznaczone jako gotowe) i zastąpi ich obecną treść. To <?= $openSections ?> <?= plural($openSections, 'użycie', 'użycia', 'użyć') ?> AI<?= $plan['limit'] !== null ? ' z ' . max(0, (int) $plan['limit'] - (int) $plan['used']) . ' pozostałych w tym miesiącu' : '' ?>. Kontynuować?">
      <?= csrf_field() ?>
      <button class="btn" type="submit" data-busy="AI pisze pierwszą sekcję…"<?= $aiEnabled ? '' : ' disabled title="Funkcje AI są wyłączone"' ?>><?= $isGrant ? 'Napisz cały wniosek' : 'Napisz całą ofertę' ?></button>
    </form>
    <?php endif ?>
    <a class="btn<?= $openSections ? ' ghost' : '' ?>" href="<?= $base ?>/docx">Eksportuj do .docx</a>
    <form class="inline" method="post" action="<?= $base ?>">
      <?= csrf_field() ?>
      <label class="sr-only" for="o-status">Status</label>
      <select id="o-status" name="status" data-autosubmit style="min-width:170px">
        <?php foreach (OfferController::STATUSES as $k => $s): ?><option value="<?= e($k) ?>"<?= $offer['status'] === $k ? ' selected' : '' ?>><?= e($s['label']) ?></option><?php endforeach ?>
      </select>
      <noscript><button class="btn ghost sm" type="submit">Zmień</button></noscript>
    </form>
  </div>
</header>

<div class="cols">
  <aside class="card side" style="flex:0 1 280px" aria-label="Sekcje">
    <nav class="sec-nav">
      <?php foreach ($sections as $s): $n = substr_count((string) $s['body'], '⟦sprawdź⟧'); ?>
        <a href="<?= $base ?>?sekcja=<?= e(rawurlencode($s['key'])) ?>"<?= $current && $current['key'] === $s['key'] ? ' class="on" aria-current="true"' : '' ?>>
          <span><?= e($s['title']) ?><?php if ($who = $short((int) ($s['assignee_id'] ?? 0))): ?><span class="sec-who"><?= e($who) ?></span><?php endif ?></span>
          <?php if ($s['status'] === 'done'): ?><span class="chip low">gotowe</span><?php elseif ($n): ?><span class="chip high"><?= $n ?> bez źródła</span><?php endif ?>
        </a>
      <?php endforeach ?>
      <?php if ($isGrant): ?>
      <a href="<?= $base ?>?sekcja=<?= e(OfferController::TERMS_KEY) ?>"<?= $showTerms ? ' class="on" aria-current="true"' : '' ?>>
        <span>Warunki naboru</span>
        <?php if ($eligibility): ?><span class="chip <?= $eligibility['counts']['not_met'] ? 'high' : ($eligibility['counts']['unknown'] ? 'mid' : 'low') ?>"><?= $eligibility['counts']['not_met'] ? $eligibility['counts']['not_met'] . ' nie spełnia' : ($eligibility['counts']['unknown'] ? $eligibility['counts']['unknown'] . ' do sprawdzenia' : 'spełnione') ?></span><?php endif ?>
      </a>
      <?php endif ?>
      <a href="<?= $base ?>?sekcja=<?= e($docsKey) ?>"<?= $showDocs ? ' class="on" aria-current="true"' : '' ?>>
        <span>Dokumenty do złożenia</span>
        <?php if ($checklist): ?><span class="chip <?= $docsDone === count($checklist) ? 'low' : 'neutral' ?>"><?= $docsDone ?> z <?= count($checklist) ?></span><?php endif ?>
      </a>
    </nav>
    <div style="border-top:1px solid var(--line-soft);margin-top:12px;padding-top:12px" class="stack-sm">
      <p class="small" style="font-weight:600;margin:0">Źródła</p>
      <?php if ($documents): ?>
        <ul class="list-plain"><?php foreach ($documents as $d): ?><li class="xs"><?= e($d['filename']) ?></li><?php endforeach ?></ul>
      <?php else: ?>
        <p class="xs muted" style="margin:0"><?= $isGrant ? 'Wgraj regulamin naboru i kryteria oceny.' : ($tender ? 'Brak wgranych dokumentów SWZ - AI użyje treści ogłoszenia.' : 'Szkic bez przetargu - AI użyje tylko biblioteki.') ?></p>
      <?php endif ?>
      <?php if ($isGrant): ?>
        <form method="post" action="<?= $base ?>/dokumenty" enctype="multipart/form-data" class="stack-sm">
          <?= csrf_field() ?>
          <label class="sr-only" for="gdocs">Dokumenty naboru</label>
          <input id="gdocs" type="file" name="documents[]" accept=".pdf,.docx,.txt" multiple required>
          <button class="btn ghost sm" type="submit" data-busy="Wgrywam…">Wgraj dokumenty naboru</button>
        </form>
      <?php elseif ($tender): ?>
        <a class="small" href="/przetargi/<?= (int) $tender['id'] ?>#dokumenty">Dodaj dokumenty SWZ</a>
      <?php endif ?>
      <p class="xs muted" style="margin:0">Biblioteka firmy: <?= $libraryCount ?> <?= plural($libraryCount, 'pozycja', 'pozycje', 'pozycji') ?>. <a href="/biblioteka">Uzupełnij</a></p>
    </div>
  </aside>

  <section class="card main" aria-labelledby="t-sec">
    <?php if ($showTerms): ?>
      <div class="between" style="align-items:center;margin-bottom:4px">
        <h2 id="t-sec">Warunki naboru</h2>
        <form class="inline no-print" method="post" action="<?= $base ?>/warunki"<?= $eligibility ? ' data-confirm="Sprawdzić warunki jeszcze raz? Poprzedni wynik zostanie zastąpiony nowym."' : '' ?>>
          <?= csrf_field() ?>
          <button class="btn sm" type="submit" data-busy="AI czyta dokumenty…"<?= $aiEnabled && $documents ? '' : ' disabled' ?>><?= $eligibility ? 'Sprawdź ponownie' : 'Sprawdź warunki naboru' ?></button>
        </form>
      </div>
      <p class="muted small" style="margin:0 0 12px">AI wypisuje warunki z wgranych dokumentów naboru i porównuje je z danymi firmy i biblioteką. Każdy warunek ma cytat z dokumentu. Ocena „Spełnia” albo „Nie spełnia” pojawia się tylko wtedy, gdy potwierdza ją zapis w danych firmy.</p>
      <?php if (!$documents): ?>
        <div class="notice info">Wgraj regulamin naboru albo kryteria wyboru projektów (pole „Dokumenty naboru” po lewej). Warunki bierzemy tylko z tych dokumentów.</div>
      <?php elseif (!$aiEnabled): ?>
        <div class="notice info">Funkcje AI są wyłączone, więc warunków nie da się sprawdzić automatycznie.</div>
      <?php endif ?>
      <?php if ($eligibility): $ec = $eligibility['counts']; ?>
        <p class="row-flex" style="gap:8px;margin:0 0 4px">
          <?php foreach (\App\Modules\Offers\Eligibility::STATES as $st => [$label, $chip]): if ($ec[$st]): ?><span class="chip <?= $chip ?>"><?= e($label) ?>: <?= (int) $ec[$st] ?></span><?php endif; endforeach ?>
        </p>
        <ul class="terms">
          <?php foreach ($eligibility['conditions'] as $c): [$label, $chip] = \App\Modules\Offers\Eligibility::STATES[$c['status']] ?? \App\Modules\Offers\Eligibility::STATES['unknown']; ?>
            <li>
              <div class="between" style="align-items:flex-start;gap:12px">
                <h3><?= e($c['condition']) ?></h3>
                <span class="chip <?= $chip ?>"><?= e($label) ?></span>
              </div>
              <blockquote><?= e($c['quote']) ?><?php if ($c['doc']): ?><cite><?= e($c['doc']) ?></cite><?php endif ?></blockquote>
              <?php if ($c['evidence']): ?><p class="small" style="margin:8px 0 0"><span class="muted">W danych firmy:</span> „<?= e($c['evidence']) ?>”</p><?php endif ?>
              <?php if ($c['note']): ?><p class="small" style="margin:6px 0 0"><?= e($c['note']) ?></p><?php endif ?>
            </li>
          <?php endforeach ?>
        </ul>
        <p class="xs muted" style="margin:12px 0 0">
          Sprawdzono <?= e(local_dt($eligibility['created_at'], 'd.m.Y H:i')) ?> na podstawie: <?= e(implode(', ', $eligibility['docs'] ?? [])) ?>.
          <?php if (!empty($eligibility['dropped'])): ?>Pominęliśmy <?= (int) $eligibility['dropped'] ?> <?= plural((int) $eligibility['dropped'], 'pozycję', 'pozycje', 'pozycji') ?> bez dosłownego cytatu z dokumentów.<?php endif ?>
          To pomoc w czytaniu regulaminu. O przyznaniu wsparcia decyduje instytucja prowadząca nabór.
        </p>
      <?php endif ?>
    <?php elseif ($showDocs): ?>
      <div class="between" style="align-items:center;margin-bottom:4px">
        <h2 id="t-sec">Dokumenty do złożenia</h2>
        <?php if ($checklist): ?><span class="chip <?= $docsDone === count($checklist) ? 'low' : 'neutral' ?>"><?= $docsDone ?> z <?= count($checklist) ?> gotowe</span><?php endif ?>
      </div>
      <p class="muted small" style="margin:0 0 12px"><?= $isGrant ? 'Załączniki wymagane w naborze. Dopisz je z regulaminu i odhaczaj w miarę kompletowania.' : 'Pozycje startowe pochodzą z ogłoszenia. Po analizie SWZ możesz dopisać brakujące jednym kliknięciem.' ?></p>
      <?php if ($checklist): ?>
        <ul class="todo">
          <?php foreach ($checklist as $c): $done = (int) $c['done'] === 1; ?>
            <li class="<?= $done ? 'is-done' : '' ?>">
              <form method="post" action="<?= $base ?>/lista/<?= (int) $c['id'] ?>" class="todo-main">
                <?= csrf_field() ?>
                <button class="todo-box" type="submit" aria-pressed="<?= $done ? 'true' : 'false' ?>" aria-label="<?= $done ? 'Cofnij odhaczenie: ' : 'Odhacz: ' ?><?= e($c['label']) ?>"><span aria-hidden="true"></span></button>
                <div>
                  <p class="todo-label"><?= e($c['label']) ?></p>
                  <p class="xs muted" style="margin:2px 0 0"><?= $c['source'] ? e($c['source']) : 'dopisane przez zespół' ?><?php if ($done): ?> · gotowe<?= $c['done_by'] && isset($team[(int) $c['done_by']]) ? ', ' . e($short((int) $c['done_by'])) : '' ?>, <?= e(local_dt($c['done_at'], 'd.m H:i')) ?><?php endif ?></p>
                </div>
              </form>
              <form method="post" action="<?= $base ?>/lista/<?= (int) $c['id'] ?>" class="no-print" data-confirm="Usunąć tę pozycję z listy?">
                <?= csrf_field() ?><input type="hidden" name="delete" value="1">
                <button class="link-btn xs" type="submit" aria-label="Usuń z listy: <?= e($c['label']) ?>">Usuń</button>
              </form>
            </li>
          <?php endforeach ?>
        </ul>
      <?php else: ?>
        <p class="muted small">Lista jest pusta. Dopisz pierwszy dokument poniżej.</p>
      <?php endif ?>
      <div class="row-flex no-print" style="margin-top:16px;align-items:flex-end">
        <form method="post" action="<?= $base ?>/lista" class="row-flex" style="flex:1 1 320px;align-items:flex-end">
          <?= csrf_field() ?>
          <div class="field" style="flex:1 1 220px"><label for="doc-new">Dopisz dokument</label><input id="doc-new" name="label" type="text" maxlength="300" required placeholder="np. polisa OC, wykaz osób"></div>
          <button class="btn ghost" type="submit">Dopisz</button>
        </form>
        <?php if ($tender): ?>
          <form method="post" action="<?= $base ?>/lista"><?= csrf_field() ?><input type="hidden" name="sync" value="1"><button class="btn ghost" type="submit">Uzupełnij z ogłoszenia i analizy</button></form>
        <?php endif ?>
      </div>
    <?php elseif ($current): ?>
      <?php $edit = $mode === 'edit'; $secUrl = $base . '/sekcje/' . rawurlencode($current['key']); ?>
      <div class="between" style="align-items:center;margin-bottom:12px">
        <h2 id="t-sec"><?= e($current['title']) ?></h2>
        <div class="row-flex no-print">
          <form class="inline" method="post" action="<?= e($secUrl) ?>/osoba">
            <?= csrf_field() ?>
            <label class="sr-only" for="sec-who">Osoba odpowiedzialna za sekcję</label>
            <select id="sec-who" name="user_id" data-autosubmit style="min-width:150px">
              <option value="0">Bez osoby</option>
              <?php foreach ($team as $u): ?><option value="<?= (int) $u['id'] ?>"<?= (int) ($current['assignee_id'] ?? 0) === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?><?= (int) $u['id'] === $me ? ' (Ty)' : '' ?></option><?php endforeach ?>
            </select>
            <noscript><button class="btn ghost sm" type="submit">Przydziel</button></noscript>
          </form>
          <a class="btn ghost sm" href="<?= $base ?>?sekcja=<?= e(rawurlencode($current['key'])) ?><?= $edit ? '' : '&tryb=edycja' ?>"><?= $edit ? 'Podgląd ze źródłami' : 'Edytuj tekst' ?></a>
          <form class="inline" method="post" action="<?= e($secUrl) ?>/ai" data-confirm="AI zastąpi obecną treść sekcji nową wersją. Kontynuować?">
            <?= csrf_field() ?>
            <button class="btn sm" type="submit" data-busy="AI pisze…"<?= $aiEnabled ? '' : ' disabled title="Funkcje AI są wyłączone"' ?>><?= trim((string) $current['body']) === '' ? 'Napisz z AI' : 'Przepisz z AI' ?></button>
          </form>
        </div>
      </div>
      <?php if (!empty($current['updated_by']) && isset($team[(int) $current['updated_by']])): ?>
        <p class="xs muted" style="margin:-6px 0 12px">Ostatnia zmiana: <?= e($short((int) $current['updated_by'])) ?>, <?= e(local_dt($current['updated_at'], 'd.m.Y H:i')) ?></p>
      <?php endif ?>
      <?php if ($conflict): ?>
        <div class="flash error" role="alert">
          Nie zapisaliśmy Twojej wersji, bo w trakcie edycji tę sekcję <?= isset($team[(int) ($conflict['updated_by'] ?? 0)]) ? 'zapisał(a) ' . e($team[(int) $conflict['updated_by']]['name']) : 'zapisał ktoś inny' ?> (<?= e(local_dt($conflict['updated_at'], 'd.m H:i')) ?>).
          Twój tekst jest w polu poniżej. Porównaj go z aktualną wersją pod formularzem i zapisz ponownie.
        </div>
      <?php endif ?>
      <?php if ($edit): ?>
        <form method="post" action="<?= e($secUrl) ?>" class="stack-sm">
          <?= csrf_field() ?>
          <input type="hidden" name="version" value="<?= (int) ($conflict['version'] ?? $current['version'] ?? 1) ?>">
          <div class="field"><label for="sec-title">Tytuł sekcji</label><input id="sec-title" name="title" type="text" value="<?= e($draft['title'] ?? $current['title']) ?>"></div>
          <div class="field">
            <label for="sec-body">Treść</label>
            <textarea id="sec-body" class="doc" name="body" data-markers="marker-count"><?= e($draft['body'] ?? $current['body']) ?></textarea>
            <span class="hint">Znacznik ⟦źródło: …⟧ wskazuje pochodzenie zdania i znika w eksporcie. ⟦sprawdź⟧ oznacza zdanie bez źródła - w pliku Word pojawi się jako [SPRAWDŹ]. <span id="marker-count" class="chip neutral" aria-live="polite"></span></span>
          </div>
          <label class="check"><input type="checkbox" name="done" value="1"<?= ($draft['done'] ?? ($current['status'] === 'done')) ? ' checked' : '' ?>> Sekcja sprawdzona i gotowa</label>
          <div class="row-flex"><button class="btn" type="submit"><?= $conflict ? 'Zapisz moją wersję' : 'Zapisz sekcję' ?></button><a class="btn ghost" href="<?= $base ?>?sekcja=<?= e(rawurlencode($current['key'])) ?>">Anuluj</a></div>
        </form>
        <?php if ($conflict): ?>
          <h3 style="margin:24px 0 8px">Aktualna wersja w bazie</h3>
          <div class="doc-preview"><?= marked_html((string) $conflict['body']) ?: '<p class="muted">Sekcja jest pusta.</p>' ?></div>
        <?php endif ?>
      <?php else: ?>
        <div class="doc-preview"><?= marked_html((string) $current['body']) ?: '<p class="muted">Sekcja jest pusta.</p>' ?></div>
      <?php endif ?>
    <?php endif ?>
  </section>
</div>

<form method="post" action="<?= $base ?>/usun" data-confirm="Usunąć ten szkic? Tej operacji nie można cofnąć." class="no-print"><?= csrf_field() ?><button class="btn danger sm" type="submit">Usuń szkic</button></form>
