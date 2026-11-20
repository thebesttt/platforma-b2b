<?php
use App\Modules\Outreach\RiskPolicy;
use App\Modules\Tenders\Tenders;

$days = ['niedziela', 'poniedziałek', 'wtorek', 'środa', 'czwartek', 'piątek', 'sobota'];
$months = ['', 'stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
$today = ucfirst($days[(int) date('w')]) . ', ' . (int) date('j') . ' ' . $months[(int) date('n')];
$profile = $profiles[0] ?? null;
?>
<?php
$has = static fn (string $m): bool => in_array($m, $mods, true);
$lead = $leads[0] ?? null;
?>
<header class="between page-head">
  <div>
    <p class="crumbs"><?= e($today) ?></p>
    <?php if ($has('zapytania') && $stats['leads_new']): ?>
      <h1><?= $stats['leads_new'] ?> <?= plural($stats['leads_new'], 'nowe zapytanie ze strony czeka', 'nowe zapytania ze strony czekają', 'nowych zapytań ze strony czeka') ?> na odpowiedź</h1>
      <p class="muted small"><?= $stats['leads_hot'] ? 'W tym pilnych: ' . $stats['leads_hot'] . '. ' : '' ?>Ocenę policzył program z Twojej tabeli punktów.</p>
    <?php elseif (!$has('przetargi')): ?>
      <h1>Pulpit</h1>
      <p class="muted small">Nie ma nowych zapytań ze strony. Nowe pojawią się tutaj i w poczcie zespołu.</p>
    <?php elseif (!$profiles): ?>
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
    <?php if ($has('zapytania') && $stats['leads_new'] && $lead): ?>
      <a class="btn" href="/zapytania/<?= (int) $lead['id'] ?>">Otwórz najpilniejsze zapytanie</a>
    <?php elseif ($has('przetargi') && $profiles): ?>
      <a class="btn ghost" href="/profile/<?= (int) $profile['id'] ?>">Edytuj profil</a>
      <?php if ($matches): ?><a class="btn" href="/przetargi/<?= (int) $matches[0]['id'] ?>">Przejrzyj najlepsze dopasowanie</a><?php endif ?>
    <?php elseif ($has('przetargi')): ?>
      <a class="btn" href="/profile/nowy">Ustaw profil przetargowy</a>
    <?php endif ?>
  </div>
</header>

<section class="grid-stats" aria-label="Podsumowanie">
  <?php if ($has('zapytania')): ?><div class="card"><div class="stat-label">Nowe zapytania ze strony</div><div class="stat-value"><?= $stats['leads_new'] ?></div><div class="stat-note"><?= $stats['leads_hot'] ? 'pilnych: ' . $stats['leads_hot'] : ($stats['leads_new'] ? 'żadne nie jest pilne' : 'wszystkie obsłużone') ?></div></div><?php endif ?>
  <?php if ($has('audyt')): ?><div class="card"><div class="stat-label">Strony w audycie AI</div><div class="stat-value"><?= (int) ($stats['pages'] ?? 0) ?></div><div class="stat-note"><?= $pages ? 'najniższy wynik: ' . (int) $pages[0]['score'] . ' pkt' : 'dodaj pierwszą stronę' ?></div></div><?php endif ?>
  <?php if ($has('przetargi')): ?>
  <div class="card"><div class="stat-label">Nowe dopasowania</div><div class="stat-value"><?= $stats['new'] ?></div><div class="stat-note"><?= $import ? 'ostatni import: ' . e(local_dt($import['at'])) : 'import jeszcze nie działał' ?></div></div>
  <div class="card"><div class="stat-label">Terminy w tym tygodniu</div><div class="stat-value"><?= $stats['week'] ?></div><div class="stat-note"><?= $stats['next_deadline'] ? 'najbliższy: ' . e(local_dt($stats['next_deadline'], 'd.m, H:i')) : 'brak terminów' ?></div></div>
  <?php endif ?>
  <?php if ($has('oferty')): ?><div class="card"><div class="stat-label">Oferty w przygotowaniu</div><div class="stat-value"><?= $stats['offers'] ?></div><div class="stat-note"><?= $stats['offers_review'] ? $stats['offers_review'] . ' ' . plural($stats['offers_review'], 'czeka', 'czekają', 'czeka') . ' na sprawdzenie' : 'szkice ofert i wniosków' ?></div></div><?php endif ?>
  <?php if ($has('kontrahenci')): ?><div class="card<?= $stats['alerts'] ? ' alert' : '' ?>"><div class="stat-label">Alerty kontrahentów</div><div class="stat-value"<?= $stats['alerts'] ? ' style="color:var(--high)"' : '' ?>><?= $stats['alerts'] ?></div><div class="stat-note"><?= $stats['alerts'] ? e(truncate((string) $alerts[0]['detail'], 60)) : 'obserwowane firmy bez zmian' ?></div></div><?php endif ?>
</section>

<div class="cols">
  <div class="main stack">
  <?php if ($has('zapytania')): ?>
  <section class="card flush" aria-labelledby="t-leads">
    <div class="card-head"><h2 id="t-leads">Zapytania ze strony</h2><a class="small" href="/zapytania">Wszystkie zapytania</a></div>
    <?php if ($leads): ?>
      <div class="table-wrap"><table>
        <thead><tr><th scope="col">Ocena</th><th scope="col">Kto pyta</th><th scope="col">Czego potrzebuje</th><th scope="col">Kiedy</th></tr></thead>
        <tbody>
        <?php foreach ($leads as $l): $lv = App\Modules\Inbound\Rules::LEVELS[$l['level']] ?? ['label' => $l['level'], 'chip' => 'neutral']; ?>
          <tr>
            <td class="nowrap"><span class="score <?= $lv['chip'] ?>"><?= (int) $l['score'] ?></span> <span class="small"><?= e($lv['label']) ?></span></td>
            <td style="min-width:150px"><a class="t-title" href="/zapytania/<?= (int) $l['id'] ?>"><?= e($l['name'] ?: $l['email']) ?></a><?php if ($l['status'] === 'new'): ?> <span class="chip info">nowe</span><?php endif ?></td>
            <td class="small" style="min-width:220px"><?= e(truncate((string) $l['summary'], 110)) ?></td>
            <td class="small muted nowrap"><?= e(local_dt($l['created_at'], 'd.m, H:i')) ?></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table></div>
    <?php else: ?>
      <div class="empty"><h3>Brak zapytań do obsłużenia</h3><p>Nowe zapytanie z widżetu pojawi się tutaj z oceną i powodem.</p><a class="btn ghost" href="/zapytania/ustawienia">Ustawienia widżetu</a></div>
    <?php endif ?>
  </section>
  <?php endif ?>

  <?php if ($has('audyt') && $pages): ?>
  <section class="card flush" aria-labelledby="t-pages">
    <div class="card-head"><h2 id="t-pages">Audyt AI: strony z najniższym wynikiem</h2><a class="small" href="/audyt/tresc">Wszystkie strony</a></div>
    <div class="table-wrap"><table>
      <thead><tr><th scope="col">Wynik</th><th scope="col">Strona i fraza</th><th scope="col">Najpierw to</th></tr></thead>
      <tbody>
      <?php foreach ($pages as $pg): $lv = App\Modules\Content\Ruleset::level((int) $pg['score']); ?>
        <tr>
          <td class="nowrap"><span class="score <?= ['good' => 'low', 'mid' => 'mid', 'bad' => 'high'][$lv] ?>"><?= (int) $pg['score'] ?></span><?php if ((int) $pg['blocked']): ?> <span class="chip high">warunek konieczny</span><?php endif ?></td>
          <td style="min-width:220px"><a class="t-title" href="/audyt/tresc/<?= (int) $pg['id'] ?>"><?= e(truncate($pg['host'] . (string) (parse_url((string) $pg['url'], PHP_URL_PATH) ?: '/'), 70)) ?></a><div class="t-sub">„<?= e($pg['phrase']) ?>”</div></td>
          <td class="small" style="min-width:200px"><?= $pg['task'] ? e($pg['task']) : '<span class="muted">nic do poprawy</span>' ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table></div>
  </section>
  <?php endif ?>

  <?php if ($has('przetargi')): ?>
  <section class="card flush" aria-labelledby="t-match">
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
  <?php endif ?>
  </div>

  <div class="side stack">
    <?php if (!empty($deadlines)): ?>
    <section class="card" aria-labelledby="t-deadlines">
      <h2 id="t-deadlines" style="margin-bottom:8px">Zbliżające się terminy</h2>
      <ul class="list-plain">
        <?php foreach ($deadlines as $x): $n = (int) days_to_date($x['date']); ?>
          <li>
            <span class="chip <?= $n <= 1 ? 'high' : ($n <= 7 ? 'mid' : 'neutral') ?>"><?= $n === 0 ? 'dziś' : ($n === 1 ? 'jutro' : 'za ' . $n . ' dni') ?></span>
            <div style="font-weight:600;margin-top:6px"><a href="<?= e($x['url']) ?>"><?= e(truncate($x['label'], 80)) ?></a></div>
            <div class="small muted"><?= e(truncate($x['sub'], 90)) ?></div>
          </li>
        <?php endforeach ?>
      </ul>
    </section>
    <?php endif ?>
    <?php if ($has('oferty') && $mySections): ?>
    <section class="card" aria-labelledby="t-mysec">
      <h2 id="t-mysec" style="margin-bottom:8px">Twoje sekcje do napisania</h2>
      <ul class="list-plain">
        <?php foreach ($mySections as $m): ?>
          <li><a href="/oferty/<?= (int) $m['offer_id'] ?>?sekcja=<?= e(rawurlencode((string) $m['key'])) ?>&amp;tryb=edycja" style="font-weight:600"><?= e($m['section_title']) ?></a>
            <div class="small muted"><?= e(truncate((string) $m['offer_title'], 70)) ?><?= $m['deadline_at'] ? ' · termin ' . e(local_dt($m['deadline_at'], 'd.m.Y')) : '' ?></div></li>
        <?php endforeach ?>
      </ul>
    </section>
    <?php endif ?>
    <?php if ($has('przetargi') && $resolved): ?>
    <section class="card" aria-labelledby="t-resolved">
      <h2 id="t-resolved" style="margin-bottom:8px">Rozstrzygnięte przetargi</h2>
      <ul class="list-plain">
        <?php foreach ($resolved as $a): ?>
          <li>
            <?php if (!$a['read_at']): ?><span class="chip info">nowe</span><?php endif ?>
            <div style="font-weight:600;margin-top:6px"><a href="/przetargi/<?= (int) $a['tender_id'] ?>#wynik"><?= e(preg_replace('/^Rozstrzygnięto przetarg: /u', '', (string) $a['title'])) ?></a></div>
            <div class="small muted"><?= e($a['detail']) ?></div>
          </li>
        <?php endforeach ?>
      </ul>
      <p class="small" style="margin:12px 0 0"><a href="/przetargi/wyniki">Kto wygrywa w Twojej branży</a></p>
    </section>
    <?php endif ?>
    <?php if ($has('kontrahenci')): ?>
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

    <?php endif ?>

    <?php if ($messages && $has('klienci')): ?>
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
