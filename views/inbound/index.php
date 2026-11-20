<?php
use App\Core\View;
use App\Modules\Inbound\Inbound;
use App\Modules\Inbound\Rules;

$tabs = ['' => 'Wszystkie'] + Inbound::STATUSES;
?>
<header class="between page-head">
  <div>
    <p class="crumbs">Zapytania ze strony</p>
    <h1>Zapytania ze strony</h1>
    <p class="muted small">Ocenę liczy program z jawnej tabeli punktów. Przy każdym zapytaniu widzisz, za co są punkty.</p>
  </div>
  <a class="btn ghost" href="/zapytania/podglad">Zobacz widżet</a>
</header>
<?= View::partial('partials/inbound_nav', ['on' => 'lista']) ?>

<?php if (!$installed): ?>
  <div class="notice mid">Widżet nie odpowiada jeszcze żadnej stronie. Wpisz adres strony w <a href="/zapytania/ustawienia">ustawieniach widżetu</a> i wklej na nią kod, który tam znajdziesz.</div>
<?php elseif ((int) $widget['active'] !== 1): ?>
  <div class="notice mid">Widżet jest wyłączony, więc nie pokazuje się na stronie. Włączysz go w <a href="/zapytania/ustawienia">ustawieniach</a>.</div>
<?php endif ?>

<section class="card flush" aria-labelledby="t-l">
  <div class="card-head" style="align-items:flex-start">
    <h2 id="t-l" class="sr-only">Lista zapytań</h2>
    <nav class="filters" aria-label="Status">
      <?php foreach ($tabs as $k => $label): $n = $k === '' ? $counts['all'] : $counts[$k]; ?>
        <a href="/zapytania<?= $k === '' ? '' : '?status=' . e($k) ?>"<?= $status === $k || ($k === '' && !isset(Inbound::STATUSES[$status])) ? ' class="on"' : '' ?>><?= e($label) ?> (<?= $n ?>)</a>
      <?php endforeach ?>
    </nav>
  </div>
  <?php if ($leads): ?>
    <div class="table-wrap"><table>
      <thead><tr><th scope="col">Ocena</th><th scope="col">Kto pyta</th><th scope="col">Czego potrzebuje</th><th scope="col">Kiedy</th><th scope="col">Status</th></tr></thead>
      <tbody>
      <?php foreach ($leads as $l): $lv = Rules::LEVELS[$l['level']] ?? ['label' => $l['level'], 'chip' => 'neutral']; ?>
        <tr>
          <td class="nowrap"><span class="score <?= $lv['chip'] ?>"><?= (int) $l['score'] ?></span> <span class="small"><?= e($lv['label']) ?></span></td>
          <td style="min-width:170px"><a class="t-title" href="/zapytania/<?= (int) $l['id'] ?>"><?= e($l['name'] ?: $l['email']) ?></a>
            <div class="t-sub"><?= e(implode(' · ', array_filter([$l['name'] ? $l['email'] : null, $l['phone'], $l['site_url'] ? (string) parse_url((string) $l['site_url'], PHP_URL_HOST) : null]))) ?></div></td>
          <td class="small" style="min-width:260px"><?= e(truncate((string) $l['summary'], 140)) ?: '<span class="muted">Zwykły formularz, bez pytań</span>' ?></td>
          <td class="small muted nowrap"><?= e(local_dt($l['created_at'], 'd.m.Y H:i')) ?></td>
          <td><span class="chip <?= $l['status'] === 'new' ? 'info' : ($l['status'] === 'won' ? 'low' : 'neutral') ?>"><?= e(Inbound::STATUSES[$l['status']] ?? $l['status']) ?></span></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table></div>
  <?php elseif ($counts['all'] > 0): ?>
    <div class="empty"><h3>Brak zapytań z tym statusem</h3><p>Wybierz inny status powyżej.</p></div>
  <?php else: ?>
    <div class="empty"><h3>Nie ma jeszcze zapytań</h3><p>Gdy ktoś wypełni widżet na Twojej stronie, zapytanie pojawi się tutaj z oceną i trafi na e-mail zespołu. Możesz też wysłać próbne zapytanie z podglądu.</p><a class="btn" href="/zapytania/podglad">Otwórz podgląd widżetu</a></div>
  <?php endif ?>
</section>
