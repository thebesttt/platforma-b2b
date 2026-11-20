<?php
/** @var ?array $icp @var string $website @var bool $aiEnabled */
?>
<header class="between page-head">
  <div>
    <p class="crumbs"><a href="/klienci">Klienci i wiadomości</a> / Idealny klient</p>
    <h1>Idealny klient</h1>
    <p class="muted small">Krótki opis firmy, dla której pracujesz najlepiej. AI używa go w szkicach ofert, żeby pisać do właściwych ludzi o właściwej sprawie.</p>
  </div>
  <a class="btn ghost" href="/klienci">Kontakty</a>
</header>

<div class="cols">
  <div class="main stack">
    <section class="card stack-sm" aria-labelledby="t-icp">
      <h2 id="t-icp">Opis</h2>
      <form method="post" action="/klienci/idealny-klient" class="stack-sm">
        <?= csrf_field() ?>
        <div class="field">
          <label for="icp-text">Kto jest Twoim idealnym klientem</label>
          <textarea id="icp-text" name="summary" rows="5" maxlength="1200" placeholder="np. Firmy usługowe z własną stroną, które wydają na reklamę co najmniej 1000 zł miesięcznie i nie wiedzą, z której kampanii przychodzą zapytania."><?= e($icp['summary'] ?? '') ?></textarea>
          <span class="hint">2-3 zdania: kto, z jakim problemem i po czym go poznać.</span>
        </div>
        <div class="row-flex">
          <button class="btn" type="submit" name="action" value="save">Zapisz opis</button>
          <button class="btn ghost" type="submit" name="action" value="ai" formnovalidate data-busy="AI czyta stronę…"<?= $aiEnabled && $website !== '' ? '' : ' disabled' ?><?= $icp ? ' data-confirm="Nowy opis zastąpi obecny. Kontynuować?"' : '' ?>><?= $icp ? 'Przygotuj ponownie ze strony' : 'Przygotuj ze strony firmy' ?></button>
        </div>
      </form>
      <?php if ($website === ''): ?>
        <p class="small muted" style="margin:0">Żeby AI przygotowało opis, wpisz adres strony firmy w <a href="/ustawienia">Ustawieniach</a>.</p>
      <?php elseif (!$aiEnabled): ?>
        <p class="small muted" style="margin:0">Funkcje AI są wyłączone. Opis możesz wpisać ręcznie.</p>
      <?php else: ?>
        <p class="small muted" style="margin:0">AI przeczyta <?= e(preg_replace('~^https?://~', '', rtrim($website, '/'))) ?> (stronę główną i najwyżej dwie podstrony o ofercie) oraz bibliotekę firmy.</p>
      <?php endif ?>
    </section>

    <?php if ($icp && !empty($icp['segments'])): ?>
    <section class="card" aria-labelledby="t-seg">
      <h2 id="t-seg" style="margin-bottom:4px">Grupy klientów</h2>
      <p class="muted small" style="margin:0 0 4px">Każda grupa ma cytat ze strony albo z biblioteki. Grupy bez cytatu odrzucamy.</p>
      <ul class="terms">
        <?php foreach ($icp['segments'] as $s): ?>
          <li>
            <h3><?= e($s['name']) ?></h3>
            <?php if (!empty($s['why'])): ?><p class="small" style="margin:6px 0 0"><?= e($s['why']) ?></p><?php endif ?>
            <blockquote><?= e($s['quote']) ?></blockquote>
          </li>
        <?php endforeach ?>
      </ul>
    </section>
    <?php endif ?>
  </div>

  <div class="side stack">
    <?php if ($icp && (!empty($icp['signals']) || !empty($icp['not_for']))): ?>
    <section class="card stack-sm" aria-labelledby="t-sig">
      <?php if (!empty($icp['signals'])): ?>
        <h2 id="t-sig">Po czym poznać dobrego klienta</h2>
        <ul class="law"><?php foreach ($icp['signals'] as $x): ?><li class="small"><?= e($x) ?></li><?php endforeach ?></ul>
      <?php endif ?>
      <?php if (!empty($icp['not_for'])): ?>
        <h2<?= empty($icp['signals']) ? ' id="t-sig"' : '' ?>>Komu nie pomożesz</h2>
        <ul class="law"><?php foreach ($icp['not_for'] as $x): ?><li class="small"><?= e($x) ?></li><?php endforeach ?></ul>
      <?php endif ?>
    </section>
    <?php endif ?>
    <?php if ($icp && !empty($icp['generated_at'])): ?>
    <section class="card stack-sm" aria-labelledby="t-src">
      <h2 id="t-src">Skąd ten opis</h2>
      <p class="small" style="margin:0">Przygotowany <?= e(local_dt($icp['generated_at'], 'd.m.Y H:i')) ?> z:</p>
      <ul class="law"><?php foreach ($icp['pages'] ?? [] as $p): ?><li class="small" style="overflow-wrap:anywhere"><?= e($p) ?></li><?php endforeach ?></ul>
      <?php if (!empty($icp['edited_at'])): ?><p class="xs muted" style="margin:0">Opis poprawiony ręcznie <?= e(local_dt($icp['edited_at'], 'd.m.Y H:i')) ?>.</p><?php endif ?>
      <?php if (!empty($icp['dropped'])): ?><p class="xs muted" style="margin:0">Odrzuciliśmy <?= (int) $icp['dropped'] ?> <?= plural((int) $icp['dropped'], 'grupę', 'grupy', 'grup') ?> bez cytatu z materiałów firmy.</p><?php endif ?>
    </section>
    <?php endif ?>
    <section class="card stack-sm" aria-labelledby="t-use">
      <h2 id="t-use">Gdzie to działa</h2>
      <ul class="law">
        <li class="small">Szkic AI oferty i wiadomości za zgodą dostaje ten opis razem z biblioteką firmy.</li>
        <li class="small">Prośba o zgodę go nie używa, bo nie może zawierać oferty.</li>
      </ul>
    </section>
  </div>
</div>
