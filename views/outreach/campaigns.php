<?php
use App\Modules\Outreach\Campaigns;
/** @var array $rows */
$statusLabel = ['draft' => ['szkic', 'neutral'], 'sending' => ['w trakcie wysyłki', 'info'], 'done' => ['wysłana', 'low']];
?>
<header class="between page-head">
  <div>
    <p class="crumbs"><a href="/klienci">Klienci</a> / Kampanie e-mail</p>
    <h1>Kampanie e-mail</h1>
    <p class="muted small" style="max-width:70ch">Jedna wiadomość do wielu firm, ale każda firma dostaje własną wersję: z jej nazwą, osobą i zdaniem o tym, skąd się znacie albo co ostatnio zrobiła. Panel wysyła sam tylko do firm, które zgodziły się na oferty e-mailem albo są Twoimi klientami.</p>
  </div>
</header>

<div class="cols">
  <section class="card main">
    <h2>Nowa kampania</h2>
    <form method="post" action="/klienci/kampanie" class="stack-sm">
      <?= csrf_field() ?>
      <div class="field"><label for="k-name">Nazwa (widzisz ją tylko Ty)</label><input id="k-name" type="text" name="name" maxlength="120" placeholder="np. Przegląd stron, listopad"></div>
      <div class="field"><label for="k-subject">Temat wiadomości</label><input id="k-subject" type="text" name="subject" maxlength="200" value="Propozycja dla {firma}"></div>
      <div class="field"><label for="k-body">Treść</label><textarea id="k-body" name="body" rows="12"><?= e(Campaigns::defaultBody()) ?></textarea></div>
      <p class="xs muted">Pola w nawiasach klamrowych panel wypełni osobno dla każdej firmy. Stopkę z informacją, skąd masz adres, i linkiem do wypisania panel doda sam.</p>
      <button class="btn" type="submit">Zapisz i wybierz odbiorców</button>
    </form>
  </section>
  <section class="card side">
    <h2>Pola w treści</h2>
    <dl class="facts">
      <?php foreach (Campaigns::FIELDS as $field => $desc): ?><dt><code><?= e($field) ?></code></dt><dd><?= e($desc) ?></dd><?php endforeach ?>
    </dl>
    <p class="small muted">Zdanie <code>{powod}</code> panel składa z faktów, które już ma: zapytania ze strony, odpowiedzi klienta, zapytania ofertowego, tematów ze strony zgody, wygranego przetargu, projektu z dotacją UE albo audytu strony. Gdy nie ma żadnego faktu, linia znika.</p>
  </section>
</div>

<section class="card flush">
  <div class="card-head"><h2>Twoje kampanie</h2></div>
  <?php if ($rows): ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th scope="col">Kampania</th><th scope="col">Stan</th><th scope="col" class="n">Odbiorcy</th><th scope="col" class="n">Wysłane</th><th scope="col">Utworzona</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $k): [$label, $chip] = $statusLabel[$k['status']] ?? ['szkic', 'neutral']; ?>
        <tr>
          <td><a class="t-title" href="/klienci/kampanie/<?= (int) $k['id'] ?>"><?= e($k['name']) ?></a><div class="t-sub"><?= e(truncate((string) $k['subject'], 90)) ?></div></td>
          <td><span class="chip <?= $chip ?>"><?= $label ?></span></td>
          <td class="n"><?= (int) $k['recipients'] ?></td>
          <td class="n"><?= (int) $k['sent'] ?></td>
          <td class="nowrap"><?= e(local_dt((string) $k['created_at'], 'd.m.Y')) ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <div class="empty"><h3>Nie masz jeszcze kampanii</h3><p>Zacznij od formularza obok. Odbiorców wybierzesz z kontaktów w zakładce Klienci.</p></div>
  <?php endif ?>
</section>
