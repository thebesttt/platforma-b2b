<?php
use App\Modules\Outreach\RiskPolicy;
use App\Modules\Tenders\Tenders;

$days = ['niedziela', 'poniedziałek', 'wtorek', 'środa', 'czwartek', 'piątek', 'sobota'];
$months = ['', 'stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
$today = ucfirst($days[(int) date('w')]) . ', ' . (int) date('j') . ' ' . $months[(int) date('n')];
$profile = $profiles[0] ?? null;
?>
<header class="between page-head">
  <div>
    <p class="crumbs"><?= e($today) ?></p>
    <?php if (!$profiles): ?>
      <h1>Ustaw profil przetargowy, a codziennie dobierzemy dla Ciebie ogłoszenia</h1>
      <p class="muted small">Wystarczą kody CPV albo słowa kluczowe i województwa, w których pracujesz.</p>
    <?php elseif ($stats['new']): ?>
      <h1><?= $stats['new'] ?> <?= plural($stats['new'], 'nowy przetarg pasuje', 'nowe przetargi pasują', 'nowych przetargów pasuje') ?> do Twojego profilu</h1>
      <p class="muted small">Profil: <?= e($profile['name']) ?><?= count($profiles) > 1 ? ' i ' . (count($profiles) - 1) . ' ' . plural(count($profiles) - 1, 'inny', 'inne', 'innych') : '' ?></p>
    <?php else: ?>
      <h1>Brak nowych przetargów od ostatniego przeglądu</h1>
      <p class="muted small">Sprawdzamy BZP i TED codziennie rano. Możesz poszerzyć profil, żeby widzieć więcej ogłoszeń.</p>
    <?php endif ?>
  </div>
  <div class="row-flex">
    <?php if ($profiles): ?>
      <a class="btn ghost" href="/profile/<?= (int) $profile['id'] ?>">Edytuj profil</a>
      <?php if ($matches): ?><a class="btn" href="/przetargi/<?= (int) $matches[0]['id'] ?>">Przejrzyj najlepsze dopasowanie</a><?php endif ?>
    <?php else: ?>
      <a class="btn" href="/profile/nowy">Ustaw profil przetargowy</a>
    <?php endif ?>
  </div>
</header>

<section class="grid-stats" aria-label="Podsumowanie">
  <div class="card"><div class="stat-label">Nowe dopasowania</div><div class="stat-value"><?= $stats['new'] ?></div><div class="stat-note"><?= $import ? 'ostatni import: ' . e(local_dt($import['at'])) : 'import jeszcze nie działał' ?></div></div>
  <div class="card"><div class="stat-label">Terminy w tym tygodniu</div><div class="stat-value"><?= $stats['week'] ?></div><div class="stat-note"><?= $stats['next_deadline'] ? 'najbliższy: ' . e(local_dt($stats['next_deadline'], 'd.m, H:i')) : 'brak terminów' ?></div></div>
  <div class="card"><div class="stat-label">Oferty w przygotowaniu</div><div class="stat-value"><?= $stats['offers'] ?></div><div class="stat-note"><?= $stats['offers_review'] ? $stats['offers_review'] . ' ' . plural($stats['offers_review'], 'czeka', 'czekają', 'czeka') . ' na sprawdzenie' : 'szkice ofert i wniosków' ?></div></div>
  <div class="card<?= $stats['alerts'] ? ' alert' : '' ?>"><div class="stat-label">Alerty kontrahentów</div><div class="stat-value"<?= $stats['alerts'] ? ' style="color:var(--high)"' : '' ?>><?= $stats['alerts'] ?></div><div class="stat-note"><?= $stats['alerts'] ? e(truncate((string) $alerts[0]['detail'], 60)) : 'obserwowane firmy bez zmian' ?></div></div>
</section>

<div class="cols">
  <section class="card flush main" aria-labelledby="t-match">
    <div class="card-head">
      <h2 id="t-match">Dopasowane przetargi</h2>
      <a class="small" href="/przetargi">Wszystkie dopasowania</a>
    </div>
    <?php if ($matches): ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th scope="col">Ocena</th><th scope="col">Przedmiot i zamawiający</th><th scope="col" class="n">Wartość szac.</th><th scope="col">Składanie ofert</th><th scope="col">Płatnik</th></tr></thead>
        <tbody>
        <?php foreach ($matches as $m): $reasons = json_in($m['reasons_json']); ?>
          <tr>
            <td><span class="score <?= score_class((int) $m['score']) ?>"><?= (int) $m['score'] ?></span></td>
            <td>
              <a class="t-title" href="/przetargi/<?= (int) $m['id'] ?>"><?= e(truncate((string) $m['title'], 140)) ?></a>
              <?php if ($m['status'] === 'new'): ?> <span class="chip info">nowy</span><?php endif ?>
              <div class="t-sub"><?= e($m['buyer_name']) ?> · <?= e(strtoupper((string) $m['source'])) ?><?= $m['order_type'] ? ' · ' . e(Tenders::orderTypeLabel($m['order_type'])) : '' ?></div>
              <?php if ($reasons): ?><div class="t-sub">Dlaczego: <?= e(implode(' · ', array_slice($reasons, 0, 3))) ?></div><?php endif ?>
            </td>
            <td class="n"><?= $m['value_estimated'] ? e(short_money($m['value_estimated'])) : '<span class="muted">-</span>' ?></td>
            <td class="nowrap"><?= $m['deadline_at'] ? e(local_dt($m['deadline_at'], 'd.m, H:i')) : '<span class="muted">-</span>' ?></td>
            <td><?= $m['buyer_risk'] !== null ? risk_chip((int) $m['buyer_risk']) : '<span class="chip neutral">Nie sprawdzono</span>' ?></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
      <div class="empty"><h3>Nic tu jeszcze nie ma</h3><p><?= $profiles ? 'Nowe ogłoszenia pojawią się po najbliższym imporcie.' : 'Zacznij od profilu przetargowego.' ?></p><?php if (!$profiles): ?><a class="btn" href="/profile/nowy">Ustaw profil</a><?php else: ?><a class="btn ghost" href="/przetargi/szukaj">Przeszukaj wszystkie ogłoszenia</a><?php endif ?></div>
    <?php endif ?>
  </section>

  <div class="side stack">
    <section class="card" aria-labelledby="t-alerts">
      <h2 id="t-alerts" style="margin-bottom:8px">Kontrahenci</h2>
      <?php if ($alerts): ?>
        <ul class="list-plain">
        <?php foreach ($alerts as $a): ?>
          <li>
            <span class="chip <?= $a['level'] === 'high' ? 'high' : ($a['level'] === 'mid' ? 'mid' : 'info') ?>"><?= $a['read_at'] ? 'przeczytane' : 'nowe' ?></span>
            <div style="font-weight:600;margin-top:6px"><?php if ($a['nip']): ?><a href="/kontrahenci/<?= e($a['nip']) ?>"><?= e($a['title']) ?></a><?php else: ?><?= e($a['title']) ?><?php endif ?></div>
            <div class="small muted"><?= e($a['detail']) ?> · <?= e(local_dt($a['created_at'], 'd.m H:i')) ?></div>
          </li>
        <?php endforeach ?>
        </ul>
      <?php else: ?>
        <p class="muted small">Dodaj kontrahentów do obserwowanych, a powiadomimy Cię o zmianach w KRS, na białej liście VAT i o decyzjach UOKiK.</p>
      <?php endif ?>
      <form class="searchbar" method="get" action="/kontrahenci/sprawdz" style="margin-top:12px">
        <label class="sr-only" for="dash-nip">NIP firmy</label>
        <input id="dash-nip" name="nip" type="text" inputmode="numeric" placeholder="NIP kontrahenta">
        <button class="btn ghost" type="submit">Sprawdź</button>
      </form>
    </section>

    <section class="card" aria-labelledby="t-rec">
      <h2 id="t-rec" style="margin-bottom:8px">Należności po terminie</h2>
      <?php if ($receivables['overdue_count']): ?>
        <div class="stat-value" style="margin-top:0"><?= e(money_pl($receivables['overdue_sum'])) ?></div>
        <p class="muted small"><?= $receivables['overdue_count'] ?> <?= plural($receivables['overdue_count'], 'faktura', 'faktury', 'faktur') ?> po terminie</p>
      <?php else: ?>
        <p class="muted small">Brak zapisanych należności po terminie. Dodasz je w karcie kontrahenta.</p>
      <?php endif ?>
    </section>

    <?php if ($messages): ?>
    <section class="card" aria-labelledby="t-msg">
      <h2 id="t-msg" style="margin-bottom:8px">Ostatnie wiadomości</h2>
      <ul class="list-plain">
        <?php foreach ($messages as $m): ?>
          <li><a href="/wiadomosci/<?= (int) $m['id'] ?>"><?= e(truncate((string) $m['subject'], 60)) ?></a><div class="small muted"><?= e($m['company_name']) ?> · <span class="chip <?= RiskPolicy::LEVELS[$m['risk']]['chip'] ?? 'neutral' ?>">ryzyko <?= e(RiskPolicy::LEVELS[$m['risk']]['label'] ?? $m['risk']) ?></span></div></li>
        <?php endforeach ?>
      </ul>
    </section>
    <?php endif ?>
  </div>
</div>
