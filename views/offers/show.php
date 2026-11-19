<?php
use App\Http\OfferController;

$st = OfferController::STATUSES[$offer['status']] ?? OfferController::STATUSES['draft'];
$isGrant = $offer['kind'] === 'grant';
$base = '/oferty/' . (int) $offer['id'];
$deadline = $tender['deadline_at'] ?? $offer['deadline_at'];
?>
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
    <a class="btn" href="<?= $base ?>/docx">Eksportuj do .docx</a>
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
          <span><?= e($s['title']) ?></span>
          <?php if ($s['status'] === 'done'): ?><span class="chip low">gotowe</span><?php elseif ($n): ?><span class="chip high"><?= $n ?> bez źródła</span><?php endif ?>
        </a>
      <?php endforeach ?>
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
    <?php if ($current): ?>
      <div class="between" style="align-items:center;margin-bottom:12px">
        <h2 id="t-sec"><?= e($current['title']) ?></h2>
        <div class="row-flex no-print">
          <a class="btn ghost sm" href="<?= $base ?>?sekcja=<?= e(rawurlencode($current['key'])) ?><?= $mode === 'edit' ? '' : '&tryb=edycja' ?>"><?= $mode === 'edit' ? 'Podgląd ze źródłami' : 'Edytuj tekst' ?></a>
          <form class="inline" method="post" action="<?= $base ?>/sekcje/<?= e(rawurlencode($current['key'])) ?>/ai" data-confirm="AI zastąpi obecną treść sekcji nową wersją. Kontynuować?">
            <?= csrf_field() ?>
            <button class="btn sm" type="submit" data-busy="AI pisze…"<?= $aiEnabled ? '' : ' disabled title="Funkcje AI są wyłączone"' ?>><?= trim((string) $current['body']) === '' ? 'Napisz z AI' : 'Przepisz z AI' ?></button>
          </form>
        </div>
      </div>
      <?php if ($mode === 'edit'): ?>
        <form method="post" action="<?= $base ?>/sekcje/<?= e(rawurlencode($current['key'])) ?>" class="stack-sm">
          <?= csrf_field() ?>
          <div class="field"><label for="sec-title">Tytuł sekcji</label><input id="sec-title" name="title" type="text" value="<?= e($current['title']) ?>"></div>
          <div class="field">
            <label for="sec-body">Treść</label>
            <textarea id="sec-body" class="doc" name="body" data-markers="marker-count"><?= e($current['body']) ?></textarea>
            <span class="hint">Znacznik ⟦źródło: …⟧ wskazuje pochodzenie zdania i znika w eksporcie. ⟦sprawdź⟧ oznacza zdanie bez źródła - w pliku Word pojawi się jako [SPRAWDŹ]. <span id="marker-count" class="chip neutral" aria-live="polite"></span></span>
          </div>
          <label class="check"><input type="checkbox" name="done" value="1"<?= $current['status'] === 'done' ? ' checked' : '' ?>> Sekcja sprawdzona i gotowa</label>
          <div class="row-flex"><button class="btn" type="submit">Zapisz sekcję</button><a class="btn ghost" href="<?= $base ?>?sekcja=<?= e(rawurlencode($current['key'])) ?>">Anuluj</a></div>
        </form>
      <?php else: ?>
        <div class="doc-preview"><?= marked_html((string) $current['body']) ?: '<p class="muted">Sekcja jest pusta.</p>' ?></div>
      <?php endif ?>
    <?php endif ?>
  </section>
</div>

<form method="post" action="<?= $base ?>/usun" data-confirm="Usunąć ten szkic? Tej operacji nie można cofnąć." class="no-print"><?= csrf_field() ?><button class="btn danger sm" type="submit">Usuń szkic</button></form>
