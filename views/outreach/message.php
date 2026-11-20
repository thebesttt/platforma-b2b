<?php use App\Modules\Outreach\RiskPolicy; $lv = RiskPolicy::LEVELS[$m['risk']] ?? ['chip' => 'neutral', 'label' => $m['risk']]; ?>
<header class="between page-head">
  <div class="stack-sm">
    <p class="crumbs"><a href="/klienci">Klienci i wiadomości</a> / <a href="/klienci/<?= (int) $m['contact_id'] ?>"><?= e($m['company_name']) ?></a> / Wiadomość</p>
    <div class="row-flex">
      <span class="chip <?= $m['status'] === 'sent' ? 'low' : 'neutral' ?>"><?= $m['status'] === 'sent' ? 'wysłana ' . e(local_dt($m['sent_at'], 'd.m.Y H:i')) : 'do wysłania' ?></span>
      <span class="chip <?= $lv['chip'] ?>">ryzyko <?= e($lv['label']) ?></span>
      <span class="chip neutral"><?= e(RiskPolicy::KINDS[$m['kind']]['title'] ?? $m['kind']) ?></span>
    </div>
    <h1><?= e($m['subject']) ?></h1>
    <p class="muted small" style="margin:0">Do: <?= e(($m['person'] ? $m['person'] . ' ' : '') . '<' . $m['email'] . '>') ?></p>
  </div>
  <div class="row-flex">
    <a class="btn" href="<?= e($mailto) ?>">Otwórz w programie pocztowym</a>
    <a class="btn ghost" href="/wiadomosci/<?= (int) $m['id'] ?>/eml">Pobierz .eml</a>
  </div>
</header>
<div class="cols">
  <section class="card main"><pre class="pre"><?= e($m['body']) ?></pre></section>
  <div class="side stack">
  <?php if ($m['status'] !== 'sent'): ?>
  <section class="card stack-sm" aria-labelledby="t-after">
    <h2 id="t-after">Po wysłaniu</h2>
    <?php if ($sentToday >= $limit): ?><div class="notice mid small">Dziś oznaczono jako wysłane <?= (int) $sentToday ?> <?= plural((int) $sentToday, 'wiadomość', 'wiadomości', 'wiadomości') ?>, a limit firmy to <?= (int) $limit ?> dziennie. Więcej podobnych wiadomości z jednej skrzynki zwiększa ryzyko trafienia do spamu. Decyzja należy do Ciebie.</div><?php endif ?>
    <form method="post" action="/wiadomosci/<?= (int) $m['id'] ?>/wyslana" class="stack-sm">
      <?= csrf_field() ?>
      <?php if (!empty($sequence)): ?>
        <p class="small" style="margin:0">To krok <?= (int) $m['step'] + 1 ?> z 3 (<?= e(mb_strtolower(\App\Modules\Outreach\Sequences::STEPS[(int) $m['step']][1] ?? '')) ?>). Po oznaczeniu wyznaczymy termin kolejnego.</p>
      <?php elseif ($canSequence): ?>
        <label class="check"><input type="checkbox" name="sequence" value="1" checked> <span class="small">Przypomnij mi o kolejnych krokach: po 4 dniach i po 10 dniach</span></label>
        <p class="xs muted" style="margin:0">Przypomnienia zatrzymasz, gdy firma odpisze. Zatrzymują się same, gdy odbiorca się wypisze.</p>
      <?php endif ?>
      <button class="btn ghost" type="submit">Oznacz jako wysłaną</button>
    </form>
  </section>
  <?php elseif (!empty($sequence)): ?>
  <section class="card stack-sm" aria-labelledby="t-after">
    <h2 id="t-after">Przypomnienia</h2>
    <p class="small" style="margin:0"><?= $sequence['status'] === 'active' && $sequence['next_at'] ? 'Kolejny krok: ' . e(local_dt($sequence['next_at'], 'd.m.Y')) . '.' : 'Zakończone: ' . e(\App\Modules\Outreach\Sequences::STOPS[$sequence['stop_reason']] ?? 'bez kolejnych kroków') . '.' ?> <a href="/klienci/przypomnienia">Wszystkie przypomnienia</a></p>
  </section>
  <?php endif ?>
  <section class="card stack-sm">
    <h2>Zapis decyzji</h2>
    <?php if ($m['ack_at']): ?>
      <p class="small" style="margin:0"><?= e($ackUser['name'] ?: $ackUser['email'] ?? '') ?> potwierdził(a) <?= e(local_dt($m['ack_at'], 'd.m.Y H:i')) ?>:</p>
      <blockquote class="small notice mid" style="margin:0">„<?= e($m['ack_text']) ?>”</blockquote>
    <?php else: ?>
      <p class="small" style="margin:0">Ryzyko niskie - potwierdzenie nie było wymagane.</p>
    <?php endif ?>
    <p class="xs muted" style="margin:0">Utworzono <?= e(local_dt($m['created_at'], 'd.m.Y H:i')) ?>. Plik .eml otwiera się jako nowa wiadomość w Outlooku, Thunderbirdzie i Apple Mail.</p>
  </section>
  </div>
</div>
