<?php
use App\Modules\Outreach\RiskPolicy;
$lv = RiskPolicy::LEVELS[$risk['level']];
$action = '/klienci/' . (int) $c['id'] . '/wiadomosc';
?>
<header class="page-head">
  <p class="crumbs"><a href="/klienci">Klienci i wiadomości</a> / <a href="/klienci/<?= (int) $c['id'] ?>"><?= e($c['company_name']) ?></a> / Nowa wiadomość</p>
  <h1>Wiadomość do: <?= e($c['company_name']) ?></h1>
  <p class="muted small"><?= e($c['email']) ?> · podstawa kontaktu: <?= e(RiskPolicy::basisLine($c)) ?><?= $c['unsubscribed_at'] ? ' · <strong>odbiorca wypisany</strong>' : '' ?></p>
</header>

<form method="post" action="<?= e($action) ?>" class="cols">
  <?= csrf_field() ?>
  <?php /* Pierwszy przycisk formularza jest domyślny dla klawisza Enter: tylko odświeża stronę z wpisaną treścią. */ ?>
  <button type="submit" name="action" value="keep" class="sr-only" tabindex="-1" aria-hidden="true">Odśwież</button>
  <div class="main stack">
    <section class="card stack-sm" aria-labelledby="t-kind">
      <h2 id="t-kind">1. Rodzaj wiadomości</h2>
      <p class="muted small" style="margin:0">Decyzja należy do Ciebie. Przy każdej opcji pokazujemy, jakie niesie ryzyko prawne.</p>
      <div class="opt-list" role="radiogroup" aria-label="Rodzaj wiadomości">
        <?php foreach (RiskPolicy::KINDS as $k => $def): $rk = $risks[$k]; $l = RiskPolicy::LEVELS[$rk['level']]; ?>
          <label class="opt">
            <input type="radio" name="kind" value="<?= e($k) ?>"<?= $kind === $k ? ' checked' : '' ?>>
            <span style="flex:1">
              <span class="between" style="align-items:center"><strong class="small"><?= e($def['title']) ?></strong><span class="chip <?= $rk['blocked'] ? 'high' : $l['chip'] ?>"><?= $rk['blocked'] ? 'zablokowane' : 'ryzyko ' . e($l['label']) ?></span></span>
              <span class="xs muted" style="display:block;margin-top:4px"><?= e($def['note']) ?></span>
            </span>
          </label>
        <?php endforeach ?>
      </div>
      <div class="row-flex"><button class="btn ghost sm" type="submit" name="action" value="switch">Przygotuj szkic dla wybranego rodzaju</button><span class="xs muted">Zmiana rodzaju zastępuje treść nowym szkicem.</span></div>
    </section>

    <section class="card stack-sm" aria-labelledby="t-body">
      <div class="between" style="align-items:center"><h2 id="t-body">2. Treść</h2>
        <?php if ($aiEnabled): ?><button class="btn ghost sm" type="submit" name="action" value="ai" data-busy="AI pisze…">Szkic AI<?= !empty($c['website']) && $kind !== 'consent_request' ? ' (z treści strony firmy)' : '' ?></button><?php endif ?>
      </div>
      <?php if ($aiEnabled): ?><div class="field"><label for="hint" class="small">Wskazówki dla AI (opcjonalnie)</label><input id="hint" name="hint" type="text" maxlength="500" placeholder="np. podkreśl termin realizacji 2 tygodnie"></div><?php endif ?>
      <div class="field"><label for="subject">Temat</label><input id="subject" name="subject" type="text" maxlength="250" value="<?= e($subject) ?>"></div>
      <div class="field"><label for="body">Treść</label><textarea id="body" name="body" class="doc" style="min-height:280px"><?= e($body) ?></textarea></div>
    </section>
  </div>

  <div class="side stack">
    <section class="card stack-sm" aria-labelledby="t-risk">
      <h2 id="t-risk">3. Ryzyko prawne: <span class="chip <?= $risk['blocked'] ? 'high' : $lv['chip'] ?>"><?= $risk['blocked'] ? 'zablokowane' : e($lv['label']) ?></span></h2>
      <p class="small" style="margin:0"><?= e($risk['lead']) ?></p>
      <?php if ($risk['reasons']): ?><ul class="law"><?php foreach ($risk['reasons'] as $x): ?><li><?= e($x) ?></li><?php endforeach ?></ul><?php endif ?>
      <?php if ($risk['law']): ?>
        <details class="details" open><summary class="small">Przepisy i orzecznictwo</summary><ul class="law" style="margin-top:8px"><?php foreach ($risk['law'] as $x): ?><li class="small"><?= e($x) ?></li><?php endforeach ?></ul></details>
      <?php endif ?>
      <p class="xs muted" style="margin:0">To informacja, nie porada prawna. W razie wątpliwości skonsultuj się z prawnikiem.</p>
    </section>

    <section class="card stack-sm" aria-labelledby="t-dec">
      <h2 id="t-dec">4. Twoja decyzja</h2>
      <?php if ($risk['blocked']): ?>
        <p class="small" style="margin:0">Tej wiadomości nie przygotujemy. Wybierz „Odpowiedź na zapytanie ofertowe”, jeśli firma ponownie sama poprosiła o ofertę.</p>
      <?php elseif ($risk['needs_ack']): ?>
        <label class="check"><input type="checkbox" name="ack" value="1" data-ack-for="save-btn"> <span class="small"><?= e(RiskPolicy::ACK_TEXT) ?></span></label>
        <p class="xs muted" style="margin:0">Zapiszemy Twoje potwierdzenie z datą i godziną w historii kontaktu.</p>
      <?php else: ?>
        <p class="small" style="margin:0">Ryzyko niskie – możesz przygotować wiadomość do wysłania.</p>
      <?php endif ?>
      <button id="save-btn" class="btn" type="submit" name="action" value="save"<?= $risk['blocked'] ? ' disabled' : '' ?>><?= $risk['needs_ack'] ? 'Potwierdzam i przygotowuję do wysłania' : 'Przygotuj do wysłania' ?></button>
      <p class="xs muted" style="margin:0">Wiadomość wyślesz ze swojej skrzynki (otworzy się w programie pocztowym).</p>
    </section>
  </div>
</form>
