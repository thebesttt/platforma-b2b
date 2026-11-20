<?php
use App\Modules\Outreach\RiskPolicy;
use App\Modules\Outreach\Sequences;
/**
 * @var array $due @var int $room ile wiadomości mieści się jeszcze w dzisiejszym limicie @var int $limit @var int $sent
 * @var array $waiting @var array $closed
 */
$stepName = static fn (int $n): string => Sequences::STEPS[$n][1] ?? 'Krok ' . ($n + 1);
$today = array_slice($due, 0, $room);
$later = count($due) - count($today);
?>
<header class="between page-head">
  <div>
    <p class="crumbs"><a href="/klienci">Klienci i wiadomości</a> / Przypomnienia</p>
    <h1>Przypomnienia</h1>
    <p class="muted small">Po wysłanej wiadomości pilnujemy dwóch kolejnych kroków: przypomnienia po 4 dniach i ostatniej wiadomości po 10. Każdą wysyłasz ze swojej skrzynki.</p>
  </div>
  <a class="btn ghost" href="/klienci">Kontakty</a>
</header>

<div class="cols">
  <div class="main stack">
    <section class="card flush" aria-labelledby="t-due">
      <div class="card-head"><h2 id="t-due">Do wysłania dziś</h2><span class="muted xs">Dziś wysłano <?= $sent ?> z <?= $limit ?></span></div>
      <?php if ($today): ?>
        <div class="table-wrap"><table>
          <thead><tr><th scope="col">Firma</th><th scope="col">Krok</th><th scope="col">Ostatnia wiadomość</th><th scope="col"></th></tr></thead>
          <tbody>
          <?php foreach ($today as $s): $next = (int) $s['step'] + 1; ?>
            <tr>
              <td><a class="t-title" href="/klienci/<?= (int) $s['contact_id'] ?>"><?= e($s['company_name']) ?></a><div class="t-sub"><?= e(trim(($s['person'] ? $s['person'] . ' · ' : '') . $s['email'])) ?></div></td>
              <td><?= e($stepName($next)) ?><div class="t-sub"><?= e(RiskPolicy::KINDS[$s['kind']]['title'] ?? $s['kind']) ?> · termin <?= e(local_dt($s['next_at'], 'd.m.Y')) ?></div></td>
              <td class="small nowrap"><?= $s['last_sent_at'] ? e(local_dt($s['last_sent_at'], 'd.m.Y')) : '-' ?></td>
              <td class="n">
                <div class="row-flex" style="justify-content:flex-end;flex-wrap:nowrap">
                  <a class="btn sm" href="/klienci/<?= (int) $s['contact_id'] ?>/wiadomosc?sekwencja=<?= (int) $s['id'] ?>"><?= (int) $s['drafts'] ? 'Napisz ponownie' : 'Napisz' ?></a>
                  <form class="inline" method="post" action="/klienci/<?= (int) $s['contact_id'] ?>/odpowiedz"><?= csrf_field() ?><input type="hidden" name="back" value="sekwencje"><button class="btn ghost sm" type="submit">Odpisał(a)</button></form>
                  <form class="inline" method="post" action="/klienci/przypomnienia/<?= (int) $s['id'] ?>/zatrzymaj" data-confirm="Zatrzymać przypomnienia dla tej firmy?"><?= csrf_field() ?><button class="btn ghost sm" type="submit">Zatrzymaj</button></form>
                </div>
              </td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table></div>
      <?php elseif ($due): ?>
        <div class="empty"><h3>Dzisiejszy limit jest wykorzystany</h3><p>Na termin czeka <?= count($due) ?> <?= plural(count($due), 'przypomnienie', 'przypomnienia', 'przypomnień') ?>. Pokażemy je jutro, żeby z jednej skrzynki nie wychodziło więcej niż <?= $limit ?> wiadomości dziennie.</p></div>
      <?php else: ?>
        <div class="empty"><h3>Dziś nie ma nic do wysłania</h3><p>Przypomnienia pojawią się tu w dniu, na który przypada kolejny krok.</p></div>
      <?php endif ?>
      <?php if ($today && $later > 0): ?><p class="small muted" style="padding:0 20px 16px;margin:0">Jeszcze <?= $later ?> <?= plural($later, 'przypomnienie czeka', 'przypomnienia czekają', 'przypomnień czeka') ?> na jutro, bo dzienny limit to <?= $limit ?> wiadomości.</p><?php endif ?>
    </section>

    <section class="card flush" aria-labelledby="t-wait">
      <div class="card-head"><h2 id="t-wait">W toku</h2><span class="muted xs"><?= count($waiting) ?> <?= plural(count($waiting), 'firma', 'firmy', 'firm') ?></span></div>
      <?php if ($waiting): ?>
        <div class="table-wrap"><table>
          <thead><tr><th scope="col">Firma</th><th scope="col">Ostatni krok</th><th scope="col">Następny</th><th scope="col"></th></tr></thead>
          <tbody>
          <?php foreach ($waiting as $s): ?>
            <tr>
              <td><a class="t-title" href="/klienci/<?= (int) $s['contact_id'] ?>"><?= e($s['company_name']) ?></a></td>
              <td class="small"><?= e($stepName((int) $s['step'])) ?><?= $s['last_sent_at'] ? ', ' . e(local_dt($s['last_sent_at'], 'd.m.Y')) : '' ?></td>
              <td class="small"><?= e($stepName((int) $s['step'] + 1)) ?> <?= e(local_dt($s['next_at'], 'd.m.Y')) ?></td>
              <td class="n">
                <div class="row-flex" style="justify-content:flex-end;flex-wrap:nowrap">
                  <form class="inline" method="post" action="/klienci/<?= (int) $s['contact_id'] ?>/odpowiedz"><?= csrf_field() ?><input type="hidden" name="back" value="sekwencje"><button class="btn ghost sm" type="submit">Odpisał(a)</button></form>
                  <form class="inline" method="post" action="/klienci/przypomnienia/<?= (int) $s['id'] ?>/zatrzymaj" data-confirm="Zatrzymać przypomnienia dla tej firmy?"><?= csrf_field() ?><button class="btn ghost sm" type="submit">Zatrzymaj</button></form>
                </div>
              </td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table></div>
      <?php else: ?>
        <div class="empty"><p>Żadna firma nie czeka na kolejny krok. Przypomnienia włączasz przy oznaczaniu wiadomości jako wysłanej.</p></div>
      <?php endif ?>
    </section>

    <?php if ($closed): ?>
    <section class="card flush" aria-labelledby="t-closed">
      <div class="card-head"><h2 id="t-closed">Zakończone</h2></div>
      <div class="table-wrap"><table>
        <thead><tr><th scope="col">Firma</th><th scope="col">Powód</th><th scope="col">Kiedy</th></tr></thead>
        <tbody>
        <?php foreach ($closed as $s): ?>
          <tr>
            <td><a class="t-title" href="/klienci/<?= (int) $s['contact_id'] ?>"><?= e($s['company_name']) ?></a></td>
            <td class="small"><?= e(Sequences::STOPS[$s['stop_reason']] ?? 'zakończona') ?></td>
            <td class="small nowrap"><?= e(local_dt($s['updated_at'], 'd.m.Y')) ?></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table></div>
    </section>
    <?php endif ?>
  </div>

  <div class="side stack">
    <section class="card stack-sm" aria-labelledby="t-limit">
      <h2 id="t-limit">Dzienny limit</h2>
      <p class="small" style="margin:0">Lista „Do wysłania dziś” nie pokaże więcej przypomnień, niż mieści się w limicie. Duża liczba podobnych wiadomości z jednej skrzynki w jeden dzień zwiększa ryzyko trafienia do spamu.</p>
      <form method="post" action="/klienci/przypomnienia/limit" class="row-flex" style="align-items:flex-end">
        <?= csrf_field() ?>
        <div class="field" style="flex:0 0 120px"><label for="lim">Wiadomości dziennie</label><input id="lim" name="limit" type="number" min="1" max="200" value="<?= $limit ?>" required></div>
        <button class="btn ghost" type="submit">Zapisz</button>
      </form>
    </section>
    <section class="card stack-sm" aria-labelledby="t-how">
      <h2 id="t-how">Jak to działa</h2>
      <ul class="law">
        <li class="small">Wysyłka idzie zawsze z Twojej skrzynki. Panel przygotowuje treść i pilnuje terminu.</li>
        <li class="small">Przypomnienia zatrzymują się, gdy klikniesz „Odpisał(a)”, gdy odbiorca wypisze się linkiem ze stopki albo gdy zatrzymasz je ręcznie.</li>
        <li class="small">Wypisanie blokuje kolejne oferty do tej firmy dla całego konta.</li>
        <li class="small">Prośba o zgodę nie ma przypomnień, bo jej treść obiecuje, że nie napiszesz ponownie.</li>
        <li class="small">Odpowiedzi nie wykrywamy automatycznie. Wymaga to połączenia ze skrzynką, którego jeszcze nie ma.</li>
      </ul>
    </section>
  </div>
</div>
