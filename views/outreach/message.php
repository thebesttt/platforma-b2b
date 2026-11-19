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
    <?php if ($m['status'] !== 'sent'): ?><form class="inline" method="post" action="/wiadomosci/<?= (int) $m['id'] ?>/wyslana"><?= csrf_field() ?><button class="btn ghost" type="submit">Oznacz jako wysłaną</button></form><?php endif ?>
  </div>
</header>
<div class="cols">
  <section class="card main"><pre class="pre"><?= e($m['body']) ?></pre></section>
  <section class="card side stack-sm">
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
