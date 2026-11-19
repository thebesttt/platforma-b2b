<?php
use App\Modules\Tenders\Regions;
use App\Modules\Tenders\Tenders;

$days = days_until($tender['deadline_at']);
$open = $tender['deadline_at'] === null || $days === null || strtotime($tender['deadline_at'] . ' UTC') > time();
$risks = $ai['risks'] ?? $rules['risks'];
$docsList = $ai['documents'] ?? $rules['documents'];
$lvl = ['high' => ['high', 'wysokie'], 'mid' => ['mid', 'średnie'], 'low' => ['info', 'niskie']];
?>
<header class="stack-sm">
  <p class="crumbs"><a href="/przetargi">Przetargi</a> / <?= e(truncate((string) $tender['title'], 60)) ?></p>
  <div class="row-flex">
    <?php if ($match): ?><span class="chip <?= score_class((int) $match['score']) ?>">Dopasowanie <?= (int) $match['score'] ?>/100</span><?php endif ?>
    <span class="chip neutral"><?= e(strtoupper((string) $tender['source'])) ?><?= !empty($d['procedure']) ? ' · ' . e(mb_strtolower(truncate((string) $d['procedure'], 40))) : '' ?></span>
    <?php if ($tender['deadline_at']): ?>
      <span class="chip <?= !$open ? 'neutral' : ($days < 5 ? 'high' : 'mid') ?>">Składanie ofert: <?= e(local_dt($tender['deadline_at'], 'd.m, H:i')) ?><?= $open ? ' (za ' . max(0, (int) $days) . ' ' . plural(max(0, (int) $days), 'dzień', 'dni', 'dni') . ')' : ' - termin minął' ?></span>
    <?php endif ?>
  </div>
  <h1><?= e($tender['title']) ?></h1>
  <p class="muted small" style="margin:0">
    <?= e($tender['buyer_name']) ?><?= $tender['buyer_city'] ? ', ' . e($tender['buyer_city']) : '' ?><?= $tender['voivodeship'] ? ' (' . e(Regions::name($tender['voivodeship'])) . ')' : '' ?>
    <?= $tender['value_estimated'] ? ' · wartość szacunkowa ' . e(short_money($tender['value_estimated'])) : '' ?>
    <?= $tender['notice_number'] ? ' · ogłoszenie ' . e($tender['notice_number']) : '' ?>
    <?= $tender['order_type'] ? ' · ' . e(Tenders::orderTypeLabel($tender['order_type'])) : '' ?>
    <?= $tender['cpv_main'] ? ' · CPV ' . e($tender['cpv_main']) : '' ?>
  </p>
  <div class="row-flex no-print">
    <form class="inline" method="post" action="/oferty"><?= csrf_field() ?><input type="hidden" name="tender_id" value="<?= (int) $tender['id'] ?>"><button class="btn" type="submit">Utwórz szkic oferty</button></form>
    <?php if ($tender['deadline_at']): ?><a class="btn ghost" href="/przetargi/<?= (int) $tender['id'] ?>/kalendarz">Dodaj terminy do kalendarza</a><?php endif ?>
    <?php if ($tender['url']): ?><a class="btn ghost" href="<?= e(safe_url($tender['url'])) ?>" target="_blank" rel="noopener">Ogłoszenie w <?= e(strtoupper((string) $tender['source'])) ?></a><?php endif ?>
    <?php if ($match): ?>
      <?php if ($match['status'] !== 'saved'): ?><form class="inline" method="post" action="/przetargi/<?= (int) $tender['id'] ?>/status"><?= csrf_field() ?><input type="hidden" name="status" value="saved"><button class="btn ghost" type="submit">Zapisz</button></form><?php endif ?>
      <?php if ($match['status'] !== 'dismissed'): ?><form class="inline" method="post" action="/przetargi/<?= (int) $tender['id'] ?>/status"><?= csrf_field() ?><input type="hidden" name="status" value="dismissed"><button class="btn ghost" type="submit">Nie interesuje mnie</button></form>
      <?php else: ?><form class="inline" method="post" action="/przetargi/<?= (int) $tender['id'] ?>/status"><?= csrf_field() ?><input type="hidden" name="status" value="new"><button class="btn ghost" type="submit">Przywróć</button></form><?php endif ?>
    <?php endif ?>
  </div>
  <?php if ($match && json_in($match['reasons_json'])): ?><p class="small muted" style="margin:0">Dlaczego pasuje: <?= e(implode(' · ', json_in($match['reasons_json']))) ?></p><?php endif ?>
</header>

<div class="cols">
  <div class="main stack">
    <section class="card" aria-labelledby="t-sum">
      <div class="between" style="align-items:center;margin-bottom:8px">
        <h2 id="t-sum">Najważniejsze z ogłoszenia</h2>
        <span class="muted xs">Odczytane automatycznie z treści ogłoszenia</span>
      </div>
      <?php if ($rules['items']): ?>
        <?php foreach ($rules['items'] as $it): ?>
          <div class="kv-row"><div><p class="k"><?= e($it['label']) ?></p><p class="v"><?= e($it['value']) ?></p></div><span class="src"><?= e($it['source']) ?></span></div>
        <?php endforeach ?>
      <?php elseif ($tender['source'] === 'ted'): ?>
        <p class="muted small">Ogłoszenia z TED mają pełną treść na stronie TED. Wgraj SWZ, a AI wypisze warunki, kryteria i ryzyka.</p>
      <?php else: ?>
        <p class="muted small">Nie udało się odczytać treści tego ogłoszenia. Odśwież stronę za chwilę albo otwórz ogłoszenie w BZP (przycisk powyżej).</p>
      <?php endif ?>
    </section>

    <section class="card" id="analiza" aria-labelledby="t-ai">
      <div class="between" style="align-items:center;margin-bottom:8px">
        <h2 id="t-ai">Analiza dokumentacji (AI)</h2>
        <?php if ($ai): ?><?php $nd = count($ai['documents_used'] ?? []); ?><span class="muted xs"><?= $nd ? 'Z ogłoszenia i ' . $nd . ' ' . plural($nd, 'dokumentu', 'dokumentów', 'dokumentów') : 'Z treści ogłoszenia' ?> · <?= e(local_dt($ai['created_at'])) ?></span><?php endif ?>
      </div>
      <?php if ($ai): ?>
        <?php if (!empty($ai['summary'])): ?><p><?= e($ai['summary']) ?></p><?php endif ?>
        <?php foreach ($ai['items'] ?? [] as $it): ?>
          <div class="kv-row"><div><p class="k"><?= e($it['label'] ?? '') ?></p><p class="v"><?= e($it['value'] ?? '') ?></p></div><?php if (!empty($it['source'])): ?><span class="src"><?= e($it['source']) ?></span><?php endif ?></div>
        <?php endforeach ?>
        <?php if (!empty($ai['conditions'])): ?>
          <h3 style="margin:16px 0 4px">Warunki udziału</h3>
          <?php foreach ($ai['conditions'] as $c): ?><div class="kv-row"><p class="v"><?= e($c['text'] ?? '') ?></p><?php if (!empty($c['source'])): ?><span class="src"><?= e($c['source']) ?></span><?php endif ?></div><?php endforeach ?>
        <?php endif ?>
        <?php if (!empty($ai['questions'])): ?>
          <h3 style="margin:16px 0 4px">Pytania warte zadania zamawiającemu</h3>
          <ul class="law"><?php foreach ($ai['questions'] as $q): ?><li><?= e(is_array($q) ? ($q['text'] ?? '') : $q) ?></li><?php endforeach ?></ul>
        <?php endif ?>
        <p class="muted xs" style="margin:12px 0 0">AI może się mylić. Przed złożeniem oferty sprawdź każdy punkt we wskazanym miejscu dokumentacji.</p>
      <?php else: ?>
        <p class="muted small">Wgraj SWZ, opis przedmiotu zamówienia i wzór umowy (sekcja „Dokumenty”). AI wypisze warunki udziału, kryteria, kary umowne, warunki płatności i ryzyka - każdy punkt ze wskazaniem rozdziału.</p>
      <?php endif ?>
      <form method="post" action="/przetargi/<?= (int) $tender['id'] ?>/analiza" class="no-print" style="margin-top:12px">
        <?= csrf_field() ?>
        <?php if ($aiEnabled): ?>
          <button class="btn<?= $ai ? ' ghost' : '' ?>" type="submit" data-busy="Analizuję dokumentację…"><?= $ai ? 'Przeanalizuj ponownie' : ($documents ? 'Przeanalizuj dokumentację' : 'Przeanalizuj samo ogłoszenie') ?></button>
        <?php else: ?>
          <button class="btn" type="submit" disabled>Analiza AI niedostępna</button>
          <p class="muted xs" style="margin:6px 0 0">Administrator platformy nie ustawił jeszcze klucza API.</p>
        <?php endif ?>
      </form>
    </section>

    <section class="card" id="pytania" aria-labelledby="t-qa">
      <h2 id="t-qa" style="margin-bottom:8px">Zapytaj dokumentację</h2>
      <form method="post" action="/przetargi/<?= (int) $tender['id'] ?>/pytanie" class="searchbar no-print">
        <?= csrf_field() ?>
        <label class="sr-only" for="question">Pytanie do dokumentacji</label>
        <input id="question" name="question" type="text" maxlength="500" placeholder="np. Czy mogę powierzyć część prac podwykonawcy?" required>
        <button class="btn" type="submit" data-busy="Szukam odpowiedzi…"<?= $aiEnabled ? '' : ' disabled' ?>>Zapytaj</button>
      </form>
      <?php foreach ($questions as $qa): ?>
        <div class="qa">
          <p class="small" style="font-weight:600;margin:0 0 4px"><?= e($qa['question']) ?></p>
          <p class="small" style="margin:0 0 6px"><?= e($qa['answer'] ?? '') ?></p>
          <div class="row-flex"><?php foreach ($qa['sources'] ?? [] as $s): ?><span class="src"><?= e(is_array($s) ? json_out($s) : $s) ?></span><?php endforeach ?></div>
        </div>
      <?php endforeach ?>
    </section>
  </div>

  <div class="side stack">
    <section class="card" aria-labelledby="t-risk">
      <h2 id="t-risk" style="margin-bottom:8px">Na co uważać</h2>
      <?php if ($risks): ?>
        <?php foreach ($risks as $rk): $l = $lvl[$rk['level'] ?? 'low'] ?? $lvl['low']; ?>
          <div class="sig"><span class="chip <?= $l[0] ?>"><?= $l[1] ?></span><div><p><?= e($rk['text'] ?? '') ?></p><?php if (!empty($rk['source'])): ?><span class="src" style="margin-top:4px"><?= e($rk['source']) ?></span><?php endif ?></div></div>
        <?php endforeach ?>
      <?php else: ?>
        <p class="muted small" style="margin:0">Nie znaleźliśmy ryzyk w treści ogłoszenia. Pełną listę (kary, płatności) da analiza SWZ i wzoru umowy.</p>
      <?php endif ?>
    </section>

    <section class="card" aria-labelledby="t-buyer">
      <h2 id="t-buyer" style="margin-bottom:8px">Zamawiający jako płatnik</h2>
      <?php if ($buyer): ?>
        <p style="margin:0 0 8px"><?= risk_chip($buyer['risk_score'] !== null ? (int) $buyer['risk_score'] : null) ?></p>
        <p class="small muted">Sprawdzono <?= e(local_dt($buyer['refreshed_at'])) ?> w białej liście VAT, KRS i decyzjach UOKiK.</p>
        <a href="/kontrahenci/<?= e($buyer['nip']) ?>">Karta płatnika</a>
      <?php elseif ($tender['buyer_nip']): ?>
        <p class="small muted">Sprawdź zamawiającego w rejestrach przed podpisaniem umowy.</p>
        <a class="btn ghost" href="/kontrahenci/<?= e($tender['buyer_nip']) ?>">Sprawdź płatnika (NIP <?= e($tender['buyer_nip']) ?>)</a>
      <?php else: ?>
        <p class="small muted" style="margin:0">Ogłoszenie nie podaje NIP zamawiającego. Wyszukaj go w zakładce Kontrahenci.</p>
      <?php endif ?>
    </section>

    <section class="card" id="dokumenty" aria-labelledby="t-docs">
      <h2 id="t-docs" style="margin-bottom:8px">Dokumenty</h2>
      <?php if ($tender['documents_url']): ?><p class="small"><a href="<?= e(safe_url($tender['documents_url'])) ?>" target="_blank" rel="noopener">Pobierz SWZ ze strony zamawiającego</a>, a potem wgraj pliki tutaj.</p><?php endif ?>
      <?php if ($documents): ?>
        <ul class="list-plain">
          <?php foreach ($documents as $doc): ?>
            <li class="between" style="align-items:center">
              <span class="small"><?= e($doc['filename']) ?><br><span class="muted xs"><?= number_format((int) $doc['chars'] / 1000, 0, ',', ' ') ?> tys. znaków</span></span>
              <form class="inline" method="post" action="/przetargi/<?= (int) $tender['id'] ?>/dokumenty/<?= (int) $doc['id'] ?>/usun" data-confirm="Usunąć dokument <?= e($doc['filename']) ?>?"><?= csrf_field() ?><button class="link-btn xs" type="submit">Usuń</button></form>
            </li>
          <?php endforeach ?>
        </ul>
      <?php endif ?>
      <form method="post" action="/przetargi/<?= (int) $tender['id'] ?>/dokumenty" enctype="multipart/form-data" class="stack-sm no-print" style="margin-top:12px">
        <?= csrf_field() ?>
        <label class="sr-only" for="documents">Pliki</label>
        <input id="documents" type="file" name="documents[]" accept=".pdf,.docx,.txt" multiple required>
        <span class="muted xs">PDF, DOCX lub TXT, do <?= (int) config('UPLOAD_MAX_MB', 15) ?> MB każdy. Zapisujemy tylko tekst dokumentów.</span>
        <button class="btn ghost" type="submit" data-busy="Wgrywam…">Wgraj dokumenty</button>
      </form>
    </section>

    <section class="card" aria-labelledby="t-check">
      <h2 id="t-check" style="margin-bottom:4px">Do złożenia z ofertą</h2>
      <p class="muted xs" style="margin:0 0 4px">Pełną listę z miejscami na dane znajdziesz w szkicu oferty.</p>
      <?php foreach ($docsList as $doc): ?>
        <div class="kv-row"><p class="v"><?= e($doc['name'] ?? '') ?></p><?php if (!empty($doc['source'])): ?><span class="src"><?= e($doc['source']) ?></span><?php endif ?></div>
      <?php endforeach ?>
    </section>

    <?php if ($offers): ?>
    <section class="card" aria-labelledby="t-off">
      <h2 id="t-off" style="margin-bottom:8px">Twoje oferty</h2>
      <ul class="list-plain"><?php foreach ($offers as $o): ?><li><a href="/oferty/<?= (int) $o['id'] ?>"><?= e($o['title']) ?></a><div class="xs muted">zmieniono <?= e(local_dt($o['updated_at'])) ?></div></li><?php endforeach ?></ul>
    </section>
    <?php endif ?>
  </div>
</div>
