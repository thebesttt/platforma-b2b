<?php
use App\Core\Nip;
use App\Modules\Companies\Receivables;

$risk = $signals['risk'] ?? ['score' => $c['risk_score'], 'label' => '', 'level' => 'low', 'signals' => []];
$lvl = ['high' => ['high', 'wysoki'], 'mid' => ['mid', 'średni'], 'info' => ['info', 'informacja'], 'none' => ['low', 'brak']];
$isActiveVat = ($c['vat_status'] ?? '') === 'Czynny';
$hasUokik = !empty($signals['uokik']);
?>
<section class="card stack-sm">
  <form class="searchbar no-print" method="get" action="/kontrahenci/sprawdz">
    <label class="sr-only" for="c-q">NIP lub nazwa firmy</label>
    <input id="c-q" name="q" type="search" placeholder="NIP, KRS lub nazwa firmy">
    <button class="btn ghost" type="submit">Sprawdź firmę</button>
  </form>
</section>

<header class="between page-head">
  <div class="stack-sm">
    <div class="row-flex">
      <?php if ($krs): ?><span class="chip low">W KRS: <?= e($krs['krs'] ?? $c['krs']) ?></span><?php endif ?>
      <span class="chip <?= $isActiveVat ? 'low' : 'mid' ?>"><?= e($c['vat_status'] ? 'VAT: ' . mb_strtolower($c['vat_status']) : 'Brak w wykazie VAT') ?></span>
      <?php if ($hasUokik): ?><span class="chip high">Decyzja UOKiK: nadmierne opóźnianie płatności</span><?php endif ?>
    </div>
    <h1><?= e($c['name'] ?: 'NIP ' . $c['nip']) ?></h1>
    <p class="muted small" style="margin:0">NIP <?= e(Nip::format($c['nip'])) ?><?= $c['regon'] ? ' · REGON ' . e($c['regon']) : '' ?><?= $c['address'] ? ' · ' . e($c['address']) : '' ?></p>
    <p class="muted xs" style="margin:0">Dane z białej listy VAT<?= $krs ? ', KRS' : '' ?> i rejestru decyzji UOKiK, pobrane <?= e(local_dt($c['refreshed_at'])) ?></p>
  </div>
  <div class="row-flex no-print">
    <form class="inline" method="post" action="/kontrahenci/<?= e($c['nip']) ?>/obserwuj"><?= csrf_field() ?><button class="btn<?= $watched ? ' ghost' : '' ?>" type="submit"><?= $watched ? 'Obserwujesz – przestań' : 'Obserwuj zmiany' ?></button></form>
    <form class="inline" method="post" action="/kontrahenci/<?= e($c['nip']) ?>/odswiez"><?= csrf_field() ?><button class="btn ghost" type="submit" data-busy="Pobieram dane…">Odśwież dane</button></form>
    <button class="btn ghost" type="button" data-print>Drukuj / PDF</button>
  </div>
</header>

<div class="cols">
  <div class="main stack">
    <section class="card" aria-labelledby="t-risk">
      <div class="between" style="align-items:flex-start">
        <div>
          <h2 id="t-risk">Ryzyko płatnicze</h2>
          <p class="stat-value" style="margin:6px 0 0;color:<?= $risk['level'] === 'high' ? 'var(--high)' : ($risk['level'] === 'mid' ? 'var(--mid)' : 'var(--low)') ?>"><?= e($risk['label']) ?> · <?= (int) $risk['score'] ?>/100</p>
        </div>
      </div>
      <div class="meter" role="img" aria-label="Skala ryzyka: <?= (int) $risk['score'] ?> na 100"><i style="left:<?= max(1, min(99, (int) $risk['score'])) ?>%"></i></div>
      <p class="muted xs" style="margin:0 0 12px">0 = brak sygnałów, 100 = bardzo wysokie</p>
      <h3 style="margin:8px 0 0">Z czego wynika ocena</h3>
      <?php foreach ($risk['signals'] as $s): $l = $lvl[$s['level']] ?? $lvl['info']; ?>
        <div class="sig"><span class="chip <?= $l[0] ?>"><?= $l[1] ?></span><div><p style="font-weight:600"><?= e($s['title']) ?></p><p class="small"><?= e($s['detail']) ?></p><span class="src" style="margin-top:4px"><?= e($s['source']) ?></span></div></div>
      <?php endforeach ?>
      <p class="muted xs" style="margin:12px 0 0">Ocena opiera się wyłącznie na danych publicznych. Nie zawiera informacji o długach zgłoszonych przez innych użytkowników platformy – takie dane trafiają tylko do biur informacji gospodarczej. Pełny raport z BIG (np. KRD, BIG InfoMonitor) zamówisz u biura.</p>
    </section>

    <section class="card" id="naleznosci" aria-labelledby="t-rec">
      <div class="between" style="align-items:center;margin-bottom:8px">
        <h2 id="t-rec">Twoje należności od tej firmy</h2>
        <span class="muted xs">Widoczne tylko dla Twojej firmy</span>
      </div>
      <?php if ($receivables): ?>
        <div class="table-wrap"><table>
          <thead><tr><th scope="col">Faktura</th><th scope="col" class="n">Kwota</th><th scope="col">Termin</th><th scope="col">Status</th><th scope="col">Wpis do BIG</th></tr></thead>
          <tbody>
          <?php foreach ($receivables as $rr): $od = Receivables::daysOverdue($rr); $big = Receivables::bigEligibility($rr); ?>
            <tr>
              <td><?= e($rr['invoice_no']) ?><?php if ($rr['notes']): ?><div class="t-sub"><?= e($rr['notes']) ?></div><?php endif ?></td>
              <td class="n"><?= e(money_pl($rr['amount'])) ?></td>
              <td class="nowrap"><?= e(date('d.m.Y', strtotime($rr['due_at']))) ?></td>
              <td><?php if ($rr['paid_at']): ?><span class="chip low">zapłacona <?= e(date('d.m.Y', strtotime($rr['paid_at']))) ?></span><?php elseif ($od): ?><span class="chip <?= $od >= 30 ? 'high' : 'mid' ?>"><?= $od ?> <?= plural($od, 'dzień', 'dni', 'dni') ?> po terminie</span><?php else: ?><span class="chip info">przed terminem</span><?php endif ?>
                <?php if ($rr['demand_sent_at']): ?><div class="t-sub">Wezwanie: <?= e(date('d.m.Y', strtotime($rr['demand_sent_at']))) ?></div><?php endif ?></td>
              <td><?= $rr['paid_at'] ? '<span class="muted">—</span>' : ($big['eligible'] ? '<span class="chip low">możliwy</span>' : '<span class="chip neutral">jeszcze nie</span>') ?></td>
            </tr>
            <?php if (!$rr['paid_at']): ?>
            <tr><td colspan="5" style="background:var(--bg)">
              <details class="details">
                <summary class="small">Wezwanie do zapłaty i wpis do biura informacji gospodarczej</summary>
                <div class="cols" style="margin-top:12px;gap:16px">
                  <div class="main">
                    <p class="small" style="font-weight:600;margin:0 0 4px">Warunki wpisu do BIG (art. 14 ustawy o udostępnianiu informacji gospodarczych)</p>
                    <ul class="list-plain">
                      <?php foreach ($big['checks'] as $chk): ?><li class="small"><span class="chip <?= $chk['ok'] ? 'low' : 'neutral' ?>"><?= $chk['ok'] ? 'spełniony' : 'brak' ?></span> <?= e($chk['text']) ?></li><?php endforeach ?>
                    </ul>
                    <?php if ($big['eligible_from']): ?><p class="small" style="margin:8px 0 0">Wpis będzie możliwy od <?= e(date('d.m.Y', strtotime($big['eligible_from']))) ?>.</p><?php endif ?>
                    <?php if ($big['eligible']): ?><p class="small notice low" style="margin:8px 0 0">Spełniasz warunki. Wpisu dokonasz w panelu wybranego biura (np. KRD, BIG InfoMonitor, ERIF) – wymagają one umowy z wierzycielem.</p><?php endif ?>
                  </div>
                  <div class="side stack-sm">
                    <form method="get" action="/naleznosci/<?= (int) $rr['id'] ?>/wezwanie" class="stack-sm">
                      <div class="field"><label for="bureau-<?= (int) $rr['id'] ?>" class="small">Biuro informacji gospodarczej (nazwa i adres)</label><input id="bureau-<?= (int) $rr['id'] ?>" name="bureau" type="text" value="<?= e($rr['demand_bureau'] ?? '') ?>" placeholder="np. Krajowy Rejestr Długów Biuro Informacji Gospodarczej S.A., ul. Danuty Siedzikówny 12, 51-214 Wrocław"></div>
                      <button class="btn ghost sm" type="submit">Przygotuj wezwanie do zapłaty</button>
                    </form>
                    <form method="post" action="/naleznosci/<?= (int) $rr['id'] ?>" class="stack-sm">
                      <?= csrf_field() ?><input type="hidden" name="action" value="demand">
                      <input type="hidden" name="demand_bureau" value="<?= e($rr['demand_bureau'] ?? '') ?>">
                      <div class="field"><label for="ds-<?= (int) $rr['id'] ?>" class="small">Data wysłania wezwania</label><input id="ds-<?= (int) $rr['id'] ?>" name="demand_sent_at" type="date" value="<?= e($rr['demand_sent_at'] ?? date('Y-m-d')) ?>"></div>
                      <button class="btn ghost sm" type="submit"<?= empty($rr['demand_bureau']) ? ' disabled title="Najpierw przygotuj wezwanie z nazwą biura"' : '' ?>>Zapisz: wezwanie wysłane</button>
                    </form>
                    <div class="row-flex">
                      <form class="inline" method="post" action="/naleznosci/<?= (int) $rr['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="paid"><button class="btn ghost sm" type="submit">Oznacz jako zapłaconą</button></form>
                      <form class="inline" method="post" action="/naleznosci/<?= (int) $rr['id'] ?>" data-confirm="Usunąć należność <?= e($rr['invoice_no']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="link-btn xs" type="submit">Usuń</button></form>
                    </div>
                  </div>
                </div>
              </details>
            </td></tr>
            <?php endif ?>
          <?php endforeach ?>
          </tbody>
        </table></div>
      <?php else: ?>
        <p class="muted small">Brak zapisanych faktur od tej firmy.</p>
      <?php endif ?>
      <details class="details no-print" style="margin-top:12px">
        <summary class="small">Dodaj nieopłaconą fakturę</summary>
        <form method="post" action="/kontrahenci/<?= e($c['nip']) ?>/naleznosci" class="form-grid" style="margin-top:12px">
          <?= csrf_field() ?>
          <div class="field"><label for="inv">Numer faktury</label><input id="inv" name="invoice_no" type="text" required maxlength="60"></div>
          <div class="field"><label for="amt">Kwota brutto (zł)</label><input id="amt" name="amount" type="text" inputmode="decimal" required></div>
          <div class="field"><label for="iss">Data wystawienia</label><input id="iss" name="issued_at" type="date"></div>
          <div class="field"><label for="due">Termin płatności</label><input id="due" name="due_at" type="date" required></div>
          <div class="field" style="grid-column:1/-1"><label for="nts">Notatka</label><input id="nts" name="notes" type="text" maxlength="500"></div>
          <div><button class="btn" type="submit">Dodaj należność</button></div>
        </form>
      </details>
    </section>
  </div>

  <div class="side stack">
    <section class="card" aria-labelledby="t-data">
      <h2 id="t-data" style="margin-bottom:12px">Dane rejestrowe</h2>
      <dl class="facts">
        <?php if (!empty($krs['legal_form']) || $c['legal_form']): ?><dt>Forma prawna</dt><dd><?= e(mb_strtolower((string) ($krs['legal_form'] ?? $c['legal_form']))) ?></dd><?php endif ?>
        <?php if ($c['registered_at']): ?><dt>Rejestracja</dt><dd><?= e(date('d.m.Y', strtotime($c['registered_at']))) ?></dd><?php endif ?>
        <?php if (isset($krs['capital']) && $krs['capital'] !== null): ?><dt>Kapitał zakładowy</dt><dd><?= e(money_pl($krs['capital'])) ?></dd><?php endif ?>
        <?php if (!empty($krs['pkd'][0])): ?><dt>Działalność</dt><dd><?= e(is_array($krs['pkd'][0]) ? implode(' ', $krs['pkd'][0]) : $krs['pkd'][0]) ?></dd><?php endif ?>
        <?php if (!empty($krs['last_statement_period_end'])): ?><dt>Ostatnie sprawozdanie</dt><dd>za okres do <?= e(date('d.m.Y', strtotime($krs['last_statement_period_end']))) ?></dd><?php endif ?>
        <?php if (!empty($krs['board'])): ?><dt>Zarząd</dt><dd><?= e(implode(', ', array_map(static fn ($b) => is_array($b) ? implode(' ', array_filter($b)) : (string) $b, (array) $krs['board']))) ?></dd><?php endif ?>
        <?php if (!empty($krs['representation'])): ?><dt>Reprezentacja</dt><dd><?= e(truncate((string) $krs['representation'], 200)) ?></dd><?php endif ?>
        <?php if ($wl): ?><dt>Rachunki na białej liście</dt><dd><?= (int) ($wl['accountCount'] ?? 0) ?><?= !empty($wl['hasVirtualAccounts']) ? ' (w tym wirtualne)' : '' ?></dd><?php endif ?>
        <?php if (!empty($krs['website'])): ?><dt>Strona</dt><dd><?= e($krs['website']) ?></dd><?php endif ?>
      </dl>
      <p class="xs muted" style="margin:12px 0 0">Przed przelewem powyżej 15 000 zł sprawdź, czy numer konta z faktury jest na białej liście (podatki.gov.pl).</p>
    </section>

    <?php if ($signals['uokik'] ?? []): ?>
    <section class="card alert" aria-labelledby="t-uokik">
      <h2 id="t-uokik" style="margin-bottom:8px">Decyzje UOKiK</h2>
      <ul class="list-plain">
        <?php foreach ($signals['uokik'] as $u): ?>
          <li class="small"><strong><?= e($u['signature']) ?></strong><?= $u['decided_at'] ? ' z ' . e(date('d.m.Y', strtotime($u['decided_at']))) : '' ?><br><?= e($u['company_name']) ?><?php if (($u['matched_by'] ?? '') === 'nazwa'): ?><br><span class="xs muted">Dopasowano po nazwie firmy – sprawdź w treści decyzji, czy to ten sam podmiot.</span><?php endif ?><?php if (!empty($u['url'])): ?><br><a href="<?= e($u['url']) ?>" target="_blank" rel="noopener">Treść decyzji</a><?php endif ?></li>
        <?php endforeach ?>
      </ul>
    </section>
    <?php endif ?>

    <?php if ($alerts): ?>
    <section class="card" aria-labelledby="t-ch">
      <h2 id="t-ch" style="margin-bottom:8px">Zmiany od początku obserwacji</h2>
      <ul class="list-plain"><?php foreach ($alerts as $a): ?><li class="small"><?= e($a['detail']) ?><br><span class="xs muted"><?= e(local_dt($a['created_at'])) ?></span></li><?php endforeach ?></ul>
    </section>
    <?php endif ?>

    <?php if ($tenders): ?>
    <section class="card" aria-labelledby="t-ten">
      <h2 id="t-ten" style="margin-bottom:8px">Przetargi tego zamawiającego</h2>
      <ul class="list-plain"><?php foreach ($tenders as $t): ?><li class="small"><a href="/przetargi/<?= (int) $t['id'] ?>"><?= e(truncate((string) $t['title'], 90)) ?></a><br><span class="xs muted"><?= $t['deadline_at'] ? 'oferty do ' . e(local_dt($t['deadline_at'], 'd.m.Y')) : '' ?></span></li><?php endforeach ?></ul>
    </section>
    <?php endif ?>
  </div>
</div>
