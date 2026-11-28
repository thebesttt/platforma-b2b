<?php
/**
 * Alarm pomiaru GA4 witryny. Tryb „alarm”: tylko gdy coś jest nie tak (zakładka Google). Tryb „pelny”: sekcja z zasadami (Dane i połączenia).
 * @var array $site
 * @var string $mode alarm | pelny
 */
use App\Modules\Seo\Tracking;

$st = Tracking::state($site);
$status = (string) ($st['status'] ?? '');
$alarm = in_array($status, ['events', 'sessions'], true);
if ($mode === 'alarm' && !$alarm) {
    return;
}
$base = '/audyt/widocznosc/' . (int) $site['id'];
?>
<?php if ($mode === 'alarm'): ?>
<div class="notice high" role="alert" style="margin:0">
  <b><?= $status === 'events' ? 'Zdarzenia kluczowe przestały się liczyć' : 'Google Analytics przestał liczyć ruch' ?></b> (od <?= e(local_date((string) ($st['since'] ?? ''))) ?>). <?= e((string) $st['detail']) ?>
  <a href="<?= e($base) ?>/dane#pomiar">Jak to sprawdzamy</a>
</div>
<?php else: ?>
<section class="card" id="pomiar" aria-labelledby="sd-track">
  <h2 id="sd-track" style="margin-bottom:6px">Pomiar w Google Analytics</h2>
  <?php if ($status === ''): ?>
    <p class="small muted" style="margin:0">Alarm włączy się sam po pierwszym pobraniu danych z Google Analytics przez połączone konto. Plik ani wklejona tabela nie wystarczą, bo potrzebne są dane z każdego dnia.</p>
  <?php else: ?>
    <p style="margin:0 0 8px"><span class="state <?= $alarm ? 's-bad' : ($status === 'ok' ? 's-good' : 's-na') ?>"><?= $alarm ? 'alarm' : ($status === 'ok' ? 'działa' : 'za mało danych') ?></span>
      <?= e((string) $st['detail']) ?></p>
    <p class="small muted" style="margin:0">Sprawdzone <?= e(local_dt((string) ($st['checked_at'] ?? ''))) ?>, dni <?= e(implode(' i ', array_map(static fn (string $d): string => date('d.m', strtotime($d)), array_reverse((array) ($st['days'] ?? []))))) ?>.</p>
  <?php endif ?>
  <details style="margin-top:12px">
    <summary class="small">Jak to sprawdzamy</summary>
    <ul class="small" style="margin:8px 0 0;padding-left:18px">
      <li>Każdy dzień porównujemy z tym samym dniem tygodnia w <?= Tracking::HISTORY_WEEKS ?> poprzednich tygodniach tej witryny. Progi wynikają z jej własnej historii, nie z liczb przyjętych z góry.</li>
      <li><b>Zdarzenia:</b> ruch co najmniej <?= (int) (Tracking::NORMAL_TRAFFIC * 100) ?>% zwykłego, zdarzeń kluczowych 0, a w historii były w co najmniej <?= (int) (Tracking::EVENT_DAYS_SHARE * 100) ?>% takich dni.</li>
      <li><b>Ruch:</b> sesji <?= (int) (Tracking::DEAD_TRAFFIC * 100) ?>% zwykłego albo mniej, przy zwykłym ruchu od <?= Tracking::MIN_SESSIONS ?> sesji dziennie.</li>
      <li>Alarm włącza się, gdy sygnał trwa <?= Tracking::DAYS ?> dni z rzędu. Sprawdzamy dni sprzed <?= Tracking::LAG_DAYS ?> dni, bo dane GA4 z wczoraj bywają niepełne. Święta pomijamy.</li>
      <li>Powiadomienie przychodzi raz, na początku alarmu, i drugi raz, gdy pomiar wróci do normy (pulpit i poranny przegląd).</li>
    </ul>
  </details>
</section>
<?php endif ?>
