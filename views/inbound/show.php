<?php
use App\Core\Nip;
use App\Modules\Inbound\Inbound;
use App\Modules\Inbound\Rules;
use App\Modules\Outreach\RiskPolicy;

$lv = Rules::LEVELS[$lead['level']] ?? ['label' => $lead['level'], 'chip' => 'neutral'];
$who = $lead['name'] ?: $lead['email'];
?>
<header class="between page-head">
  <div>
    <p class="crumbs"><a href="/zapytania">Zapytania ze strony</a> / <?= e($who) ?></p>
    <h1><?= e($who) ?></h1>
    <p class="row-flex" style="margin:8px 0 0">
      <span class="chip <?= $lv['chip'] ?>"><?= e($lv['label']) ?> · <?= (int) $lead['score'] ?>/100</span>
      <span class="chip <?= $lead['status'] === 'new' ? 'info' : ($lead['status'] === 'won' ? 'low' : 'neutral') ?>"><?= e(Inbound::STATUSES[$lead['status']] ?? $lead['status']) ?></span>
      <span class="small muted"><?= e(local_dt($lead['created_at'])) ?></span>
    </p>
  </div>
  <?php if ($contact): ?><a class="btn" href="/klienci/<?= (int) $contact['id'] ?>/wiadomosc?kind=rfq_reply">Przygotuj odpowiedź</a><?php endif ?>
</header>

<div class="cols">
  <div class="main stack">
    <section class="card flush" aria-labelledby="t-o">
      <div class="card-head"><h2 id="t-o">Ocena: <?= (int) $lead['score'] ?> na 100 punktów</h2></div>
      <div class="table-wrap"><table>
        <thead><tr><th scope="col">Pytanie</th><th scope="col">Odpowiedź</th><th scope="col" class="n">Punkty</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td class="small muted"><?= e($row['label']) ?></td>
            <td><?= $row['answer'] !== null ? e($row['answer']) : '<span class="muted">bez odpowiedzi</span>' ?></td>
            <td class="n"><b><?= (int) $row['points'] ?></b> <span class="muted">z <?= (int) $row['max'] ?></span></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table></div>
      <div class="card-body">
        <p class="small muted" style="margin:12px 0 0">Ocenę policzył program z tabeli punktów<?= $lead['rules_version'] ? ' w wersji ' . (int) $lead['rules_version'] : '' ?>. AI jej nie zmienia. Progi: „Pilne” od <?= (int) $levels['hot'] ?> pkt, „Do rozmowy” od <?= (int) $levels['talk'] ?> pkt.<?php if ($lead['rules_version'] && (int) $lead['rules_version'] !== $rulesVersion): ?> Od tego czasu zasady się zmieniły (teraz wersja <?= $rulesVersion ?>), ta ocena zostaje bez zmian.<?php endif ?><?php if ($lead['variant'] === 'a'): ?> To zapytanie przyszło ze zwykłego formularza (wariant A testu), więc punkty są tylko za telefon.<?php endif ?></p>
      </div>
    </section>

    <section class="card" aria-labelledby="t-k">
      <h2 id="t-k" style="margin-bottom:8px">Dane z formularza</h2>
      <div class="kv-row"><div><p class="k">E-mail</p><p class="v"><?= e($lead['email']) ?></p></div></div>
      <?php if ($lead['phone']): ?><div class="kv-row"><div><p class="k">Telefon</p><p class="v"><?= e($lead['phone']) ?></p></div></div><?php endif ?>
      <?php if ($lead['site_url']): ?><div class="kv-row"><div><p class="k">Strona</p><p class="v"><a href="<?= e(safe_url($lead['site_url'])) ?>" rel="noopener noreferrer nofollow" target="_blank"><?= e($lead['site_url']) ?></a></p></div></div><?php endif ?>
      <?php if ($lead['nip']): ?><div class="kv-row"><div><p class="k">NIP</p><p class="v"><?= e(Nip::format((string) $lead['nip'])) ?></p></div></div><?php endif ?>
      <?php if ($lead['message']): ?><div class="kv-row"><div><p class="k">Wiadomość</p><p class="v"><?= e($lead['message']) ?></p></div></div><?php endif ?>
      <div class="kv-row"><div><p class="k">Wysłano ze strony</p><p class="v"><?= e($lead['page_url'] ?: 'nie podano') ?></p></div></div>
      <div class="kv-row"><div><p class="k">Zgoda na oferty e-mailem</p><p class="v"><?= (int) $lead['marketing_consent'] ? 'Tak. Treść zgody: „' . e($lead['consent_text']) . '”' : 'Nie zaznaczono. Odpowiedź na to zapytanie jej nie wymaga, kolejne oferty już tak.' ?></p></div></div>
      <?php if ($risk): ?>
        <div class="kv-row"><div><p class="k">Podstawa kontaktu</p><p class="v"><?= e(RiskPolicy::basisLine($contact)) ?></p></div><span class="chip <?= RiskPolicy::LEVELS[$risk['level']]['chip'] ?>">ryzyko odpowiedzi: <?= e(RiskPolicy::LEVELS[$risk['level']]['label']) ?></span></div>
      <?php endif ?>
    </section>

    <?php if ($chat): ?>
    <section class="card" aria-labelledby="t-r">
      <h2 id="t-r" style="margin-bottom:8px">Rozmowa z asystentem AI</h2>
      <?php foreach ($chat as $m): ?>
        <div class="kv-row"><div><p class="k"><?= $m['role'] === 'user' ? 'Odwiedzający' : 'Asystent' ?> · <?= e(local_dt($m['created_at'], 'H:i')) ?></p><p class="v"><?= e($m['text']) ?></p></div></div>
      <?php endforeach ?>
    </section>
    <?php endif ?>
  </div>

  <div class="side stack">
    <form method="post" action="/zapytania/<?= (int) $lead['id'] ?>/status" class="card stack-sm">
      <?= csrf_field() ?>
      <div class="field"><label for="status">Status zapytania</label>
        <select id="status" name="status"><?php foreach (Inbound::STATUSES as $k => $label): ?><option value="<?= e($k) ?>"<?= $lead['status'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select>
      </div>
      <div><button class="btn ghost sm" type="submit">Zapisz status</button></div>
    </form>

    <section class="card" aria-labelledby="t-f">
      <h2 id="t-f" style="margin-bottom:8px">Firma</h2>
      <?php if ($company): ?>
        <p style="margin:0 0 6px"><b><?= e($company['name']) ?></b></p>
        <p style="margin:0 0 8px"><?= risk_chip($company['risk_score'] !== null ? (int) $company['risk_score'] : null) ?></p>
        <a class="small" href="/kontrahenci/<?= e($company['nip']) ?>">Karta firmy z rejestrów</a>
      <?php elseif ($lead['nip']): ?>
        <p class="small muted" style="margin:0 0 8px">Nie udało się pobrać danych tej firmy z rejestrów.</p>
        <a class="btn ghost sm" href="/kontrahenci/<?= e($lead['nip']) ?>">Sprawdź ponownie</a>
      <?php else: ?>
        <p class="small muted" style="margin:0">Odwiedzający nie podał NIP, więc nie sprawdziliśmy firmy w rejestrach.</p>
      <?php endif ?>
    </section>

    <section class="card" aria-labelledby="t-s">
      <h2 id="t-s" style="margin-bottom:8px">Strona klienta</h2>
      <?php if ($audit): ?>
        <p class="row-flex" style="margin:0 0 8px"><span class="score <?= (int) $audit['score'] >= 85 ? 'low' : ((int) $audit['score'] >= 60 ? 'mid' : 'high') ?>"><?= (int) $audit['score'] ?></span> <span class="small">wynik audytu na 100</span></p>
        <a class="small" href="/audyt/<?= (int) $audit['id'] ?>">Otwórz audyt strony <?= e($audit['host']) ?></a>
      <?php elseif ($lead['site_url']): ?>
        <p class="small muted" style="margin:0 0 8px">Audyt tej strony się nie udał. Strona mogła nie odpowiedzieć.</p>
        <form method="post" action="/audyt"><?= csrf_field() ?><input type="hidden" name="url" value="<?= e($lead['site_url']) ?>"><button class="btn ghost sm" type="submit" data-busy="Sprawdzam…">Uruchom audyt</button></form>
      <?php else: ?>
        <p class="small muted" style="margin:0">Odwiedzający nie podał adresu strony.</p>
      <?php endif ?>
    </section>

    <form method="post" action="/zapytania/<?= (int) $lead['id'] ?>/usun" class="card stack-sm" data-confirm="Usunąć to zapytanie razem z rozmową? Tego nie da się cofnąć.">
      <?= csrf_field() ?>
      <p class="small" style="margin:0">Usuń zapytanie, gdy osoba poprosi o usunięcie danych albo gdy to próba z podglądu.</p>
      <div><button class="btn danger sm" type="submit">Usuń zapytanie</button></div>
    </form>
  </div>
</div>
