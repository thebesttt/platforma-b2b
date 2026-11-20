<?php
use App\Core\Nip;
use App\Modules\Companies\ManualChecks;
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
      <?php if (($checks['krz']['data']['result'] ?? '') === 'found'): ?><a class="chip high" href="#sprawdzenia">KRZ: <?= e(mb_strtolower(ManualChecks::KRZ_TYPES[$checks['krz']['data']['type']] ?? 'wpis')) ?></a><?php endif ?>
    </div>
    <h1><?= e($c['name'] ?: 'NIP ' . $c['nip']) ?></h1>
    <p class="muted small" style="margin:0">NIP <?= e(Nip::format($c['nip'])) ?><?= $c['regon'] ? ' · REGON ' . e($c['regon']) : '' ?><?= $c['address'] ? ' · ' . e($c['address']) : '' ?></p>
    <p class="muted xs" style="margin:0">Dane z białej listy VAT<?= $krs ? ', KRS' : '' ?> i rejestru decyzji UOKiK, pobrane <?= e(local_dt($c['refreshed_at'])) ?></p>
  </div>
  <div class="row-flex no-print">
    <form class="inline" method="post" action="/kontrahenci/<?= e($c['nip']) ?>/obserwuj"><?= csrf_field() ?><button class="btn<?= $watched ? ' ghost' : '' ?>" type="submit"><?= $watched ? 'Obserwujesz - przestań' : 'Obserwuj zmiany' ?></button></form>
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
      <p class="muted xs" style="margin:12px 0 0">Ocena opiera się wyłącznie na danych publicznych. Nie zawiera informacji o długach zgłoszonych przez innych użytkowników platformy - takie dane trafiają tylko do biur informacji gospodarczej. Pełny raport z BIG (np. KRD, BIG InfoMonitor) zamówisz u biura.</p>
    </section>

    <section class="card" id="sprawdzenia" aria-labelledby="t-checks">
      <div class="between" style="align-items:center;margin-bottom:4px">
        <h2 id="t-checks">Zadłużenie i płatności: sprawdź w rejestrach</h2>
        <span class="muted xs">Widoczne tylko dla Twojej firmy</span>
      </div>
      <p class="muted small" style="margin:0 0 12px">Te dwa rejestry nie udostępniają danych programom, więc panel nie pobierze ich sam. Poniżej jest adres, czego tam szukać i miejsce na wynik. Wpisy nie zmieniają oceny ryzyka powyżej.</p>

      <?php $kz = $checks['krz']; $kd = $kz['data'] ?? []; ?>
      <div class="src-step">
        <div class="between" style="align-items:flex-start;gap:12px">
          <h3>Krajowy Rejestr Zadłużonych</h3>
          <?php if (!$kz): ?><span class="chip neutral">nie sprawdzono</span>
          <?php elseif (($kd['result'] ?? '') === 'found'): ?><span class="chip high">jest wpis</span>
          <?php elseif ($checks['krz_stale']): ?><span class="chip mid">sprawdź ponownie</span>
          <?php else: ?><span class="chip low">brak wpisów</span><?php endif ?>
        </div>
        <?php if ($kz): ?>
          <p class="small" style="margin:6px 0 0">
            <?php if (($kd['result'] ?? '') === 'found'): ?>
              <strong><?= e(ManualChecks::KRZ_TYPES[$kd['type']] ?? 'Wpis') ?></strong><?= !empty($kd['signature']) ? ', sygn. ' . e($kd['signature']) : '' ?><?= !empty($kd['entry_date']) ? ', z ' . e(date('d.m.Y', strtotime($kd['entry_date']))) : '' ?>.
              <?php if (!empty($kd['note'])): ?><?= e($kd['note']) ?><?php endif ?>
            <?php else: ?>W rejestrze nie było wpisów o tej firmie.<?php endif ?>
            <span class="muted">Sprawdził(a) <?= e($kz['by']) ?>, <?= e(date('d.m.Y', strtotime($kz['checked_at']))) ?><?= $checks['krz_stale'] ? ' (' . (int) $checks['krz_age'] . ' dni temu)' : '' ?>.</span>
          </p>
        <?php endif ?>
        <details class="details no-print" style="margin-top:8px"<?= $kz ? '' : ' open' ?>>
          <summary class="small"><?= $kz ? 'Sprawdź ponownie i wpisz wynik' : 'Gdzie to sprawdzić i jak wpisać wynik' ?></summary>
          <ol class="src-how">
            <li>Otwórz <a href="<?= e(ManualChecks::KRZ_URL) ?>" target="_blank" rel="noopener">krz.ms.gov.pl</a> (bezpłatnie, bez konta) i przejdź do wyszukiwania podmiotów.</li>
            <li>Wpisz NIP <code class="copy"><?= e($c['nip']) ?></code><?php if (!empty($c['krs'])): ?> albo numer KRS <code class="copy"><?= e($c['krs']) ?></code><?php endif ?>.</li>
            <li>Sprawdź, czy są postępowania upadłościowe albo restrukturyzacyjne, umorzone egzekucje i zakazy prowadzenia działalności. Wynik wpisz poniżej.</li>
          </ol>
          <form method="post" action="/kontrahenci/<?= e($c['nip']) ?>/sprawdzenia" class="form-grid" style="margin-top:12px">
            <?= csrf_field() ?><input type="hidden" name="kind" value="krz">
            <fieldset class="field" style="grid-column:1/-1;border:0;padding:0;margin:0">
              <legend class="small" style="font-weight:600;margin-bottom:6px">Co pokazał rejestr</legend>
              <label class="check"><input type="radio" name="result" value="none" required> <span class="small">Brak wpisów o tej firmie</span></label>
              <label class="check"><input type="radio" name="result" value="found"> <span class="small">Jest wpis (uzupełnij pola poniżej)</span></label>
            </fieldset>
            <div class="field"><label for="krz-type">Rodzaj wpisu</label><select id="krz-type" name="type"><option value="">-</option><?php foreach (ManualChecks::KRZ_TYPES as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach ?></select></div>
            <div class="field"><label for="krz-sig">Sygnatura sprawy</label><input id="krz-sig" name="signature" type="text" maxlength="80" placeholder="np. WA1M/GR/12/2026"></div>
            <div class="field"><label for="krz-date">Data wpisu albo obwieszczenia</label><input id="krz-date" name="entry_date" type="date"></div>
            <div class="field"><label for="krz-on">Kiedy sprawdzasz</label><input id="krz-on" name="checked_on" type="date" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>"></div>
            <div class="field" style="grid-column:1/-1"><label for="krz-note">Notatka</label><input id="krz-note" name="note" type="text" maxlength="500" placeholder="np. otwarto postępowanie sanacyjne, nadzorca: …"></div>
            <div class="field"><button class="btn ghost" type="submit">Zapisz wynik z KRZ</button></div>
          </form>
        </details>
      </div>

      <div class="src-step">
        <?php $fin = $checks['finance']; $last = $fin[0] ?? null; ?>
        <div class="between" style="align-items:flex-start;gap:12px">
          <h3>Po ilu dniach firma płaci dostawcom</h3>
          <?php if ($last && $last['calc']['dpo'] !== null): ?><span class="chip <?= e($last['calc']['level']) ?>">ok. <?= (int) $last['calc']['dpo'] ?> dni, <?= e($last['calc']['label']) ?></span>
          <?php else: ?><span class="chip neutral">brak danych</span><?php endif ?>
        </div>
        <?php if ($fin): ?>
          <div class="table-wrap" style="margin-top:8px"><table>
            <thead><tr><th scope="col">Rok</th><th scope="col" class="n">Płaci po</th><th scope="col" class="n">Dostaje zapłatę po</th><th scope="col" class="n">Zobowiązania wobec dostawców</th><th scope="col" class="n">Zakupy w roku</th><th scope="col"></th></tr></thead>
            <tbody>
            <?php foreach ($fin as $f): $d = $f['data']; ?>
              <tr>
                <td><strong><?= (int) $d['year'] ?></strong></td>
                <td class="n" style="white-space:nowrap"><strong><?= $f['calc']['dpo'] !== null ? 'ok. ' . (int) $f['calc']['dpo'] . ' dni' : '-' ?></strong></td>
                <td class="n" style="white-space:nowrap"><?= $f['calc']['dso'] !== null ? 'ok. ' . (int) $f['calc']['dso'] . ' dni' : '<span class="muted">-</span>' ?></td>
                <td class="n" style="white-space:nowrap"><?= e(money_pl($d['payables'])) ?></td>
                <td class="n" style="white-space:nowrap"><?= e(money_pl($d['purchases'])) ?><div class="t-sub">wpisał(a) <?= e($f['by']) ?></div></td>
                <td class="n no-print"><form class="inline" method="post" action="/kontrahenci/<?= e($c['nip']) ?>/sprawdzenia/<?= (int) $f['id'] ?>/usun" data-confirm="Usunąć liczby za <?= (int) $d['year'] ?> rok?"><?= csrf_field() ?><button class="link-btn xs" type="submit">Usuń</button></form></td>
              </tr>
              <?php foreach ($f['calc']['notes'] as $note): ?><tr><td colspan="6" class="small" style="color:var(--mid)"><?= (int) $d['year'] ?>: <?= e($note) ?></td></tr><?php endforeach ?>
            <?php endforeach ?>
            </tbody>
          </table></div>
          <p class="muted xs" style="margin:8px 0 0">Szacunek: zobowiązania wobec dostawców na koniec roku podzielone przez roczne zakupy, razy 365. Stan z jednego dnia nie pokazuje sezonowości. Skala umowna: do 60 dni typowo, 61-90 długo, ponad 90 bardzo długo.</p>
        <?php endif ?>
        <?php if (empty($c['krs'])): ?>
          <p class="small muted" style="margin:8px 0 0">Ta firma nie ma numeru KRS, więc nie składa sprawozdań do repozytorium sądowego. Poproś ją o sprawozdanie albo oprzyj się na pozostałych sygnałach.</p>
        <?php endif ?>
        <details class="details no-print" style="margin-top:8px"<?= $fin || empty($c['krs']) ? '' : ' open' ?>>
          <summary class="small"><?= $fin ? 'Dopisz kolejny rok' : 'Gdzie znaleźć sprawozdanie i które liczby wpisać' ?></summary>
          <ol class="src-how">
            <li>Otwórz <a href="<?= e(ManualChecks::RDF_URL) ?>" target="_blank" rel="noopener">ekrs.ms.gov.pl</a> i wybierz „Przeglądarka dokumentów finansowych” (bezpłatnie, bez konta). <a href="<?= e(ManualChecks::RDF_HELP_URL) ?>" target="_blank" rel="noopener">Instrukcja</a></li>
            <li>Wpisz numer KRS<?php if (!empty($c['krs'])): ?> <code class="copy"><?= e($c['krs']) ?></code><?php endif ?> i otwórz ostatnie roczne sprawozdanie finansowe.</li>
            <li>Z bilansu (pasywa, zobowiązania krótkoterminowe) przepisz „z tytułu dostaw i usług”.</li>
            <li>Z rachunku zysków i strat przepisz zakupy od dostawców. Wariant porównawczy: suma pozycji „zużycie materiałów i energii”, „usługi obce” i „wartość sprzedanych towarów i materiałów”. Wariant kalkulacyjny: „koszt sprzedanych produktów, towarów i materiałów”.</li>
          </ol>
          <form method="post" action="/kontrahenci/<?= e($c['nip']) ?>/sprawdzenia" class="form-grid" style="margin-top:12px">
            <?= csrf_field() ?><input type="hidden" name="kind" value="finance">
            <div class="field"><label for="fin-year">Rok obrotowy</label><input id="fin-year" name="year" type="number" min="2000" max="<?= e(date('Y')) ?>" value="<?= (int) date('Y') - 1 ?>" required></div>
            <div class="field"><label for="fin-pay">Zobowiązania z tytułu dostaw i usług (zł)</label><input id="fin-pay" name="payables" type="text" inputmode="decimal" required placeholder="np. 1 234 567,89"></div>
            <div class="field"><label for="fin-pur">Zakupy od dostawców w roku (zł)</label><input id="fin-pur" name="purchases" type="text" inputmode="decimal" required></div>
            <div class="field"><label for="fin-rev">Przychody netto ze sprzedaży (zł), opcjonalnie</label><input id="fin-rev" name="revenue" type="text" inputmode="decimal"></div>
            <div class="field"><label for="fin-rec">Należności z tytułu dostaw i usług (zł), opcjonalnie</label><input id="fin-rec" name="receivables" type="text" inputmode="decimal"></div>
            <div class="field"><label for="fin-net">Zysk albo strata netto (zł), opcjonalnie</label><input id="fin-net" name="net_result" type="text" inputmode="decimal" placeholder="strata z minusem"></div>
            <div class="field"><label for="fin-eq">Kapitał własny (zł), opcjonalnie</label><input id="fin-eq" name="equity" type="text" inputmode="decimal"></div>
            <div class="field" style="justify-content:flex-end"><button class="btn ghost" type="submit">Policz i zapisz</button></div>
          </form>
        </details>
      </div>
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
              <td><?= $rr['paid_at'] ? '<span class="muted">-</span>' : ($big['eligible'] ? '<span class="chip low">możliwy</span>' : '<span class="chip neutral">jeszcze nie</span>') ?></td>
            </tr>
            <?php if (!$rr['paid_at']): ?>
            <tr><td colspan="5" style="background:var(--bg)">
              <details class="details">
                <summary class="small">Wezwanie do zapłaty i wpis do biura informacji gospodarczej</summary>
                <div class="cols" style="margin-top:12px;gap:16px">
                  <div class="main">
                    <p class="small" style="font-weight:600;margin:0 0 4px">Warunki wpisu do BIG (art. 15 ustawy o udostępnianiu informacji gospodarczych)</p>
                    <ul class="list-plain">
                      <?php foreach ($big['checks'] as $chk): ?><li class="small"><span class="chip <?= $chk['ok'] ? 'low' : 'neutral' ?>"><?= $chk['ok'] ? 'spełniony' : 'brak' ?></span> <?= e($chk['text']) ?></li><?php endforeach ?>
                    </ul>
                    <?php if ($big['eligible_from']): ?><p class="small" style="margin:8px 0 0">Wpis będzie możliwy od <?= e(date('d.m.Y', strtotime($big['eligible_from']))) ?>.</p><?php endif ?>
                    <?php if ($big['eligible']): ?><p class="small notice low" style="margin:8px 0 0">Spełniasz warunki. Wpisu dokonasz w panelu wybranego biura (np. KRD, BIG InfoMonitor, ERIF) - wymagają one umowy z wierzycielem.</p><?php endif ?>
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

    <?php if (!empty($wins['count'])): ?>
    <section class="card flush" id="wygrane" aria-labelledby="t-wins">
      <div class="card-head"><h2 id="t-wins">Wygrane przetargi</h2><span class="muted xs"><?= (int) $wins['count'] ?> <?= plural((int) $wins['count'], 'umowa', 'umowy', 'umów') ?><?= $wins['total'] > 0 ? ' na ' . e(short_money($wins['total'])) : '' ?> od <?= e(local_date($wins['first_at'])) ?></span></div>
      <?= App\Core\View::partial('partials/result_rows', ['rows' => $wins['rows']]) ?>
      <?php if ((int) $wins['count'] > count($wins['rows'])): ?><p class="small" style="padding:0 20px 16px;margin:0"><a href="/przetargi/wyniki?<?= e(http_build_query(['profil' => 0, 'dni' => 365, 'q' => (string) $c['name']])) ?>">Wszystkie wygrane</a></p><?php endif ?>
    </section>
    <?php endif ?>
    <?php if (!empty($awards)): ?>
    <section class="card flush" aria-labelledby="t-awards">
      <div class="card-head"><h2 id="t-awards">Kto wygrywa u tej firmy</h2><span class="muted xs">Ostatnie rozstrzygnięcia, gdy była zamawiającym</span></div>
      <?= App\Core\View::partial('partials/result_rows', ['rows' => $awards, 'withBuyer' => false]) ?>
    </section>
    <?php endif ?>
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
        <?php if (!empty($krs['board'])): $bn = count((array) $krs['board']); ?><dt>Zarząd</dt><dd><?= $bn ?> <?= plural($bn, 'osoba', 'osoby', 'osób') ?>: <?= e(mb_strtolower(implode(', ', array_map('to_text', (array) $krs['board'])))) ?><br><span class="xs muted">KRS nie udostępnia nazwisk przez API.</span></dd><?php endif ?>
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
          <li class="small"><strong><?= e($u['signature']) ?></strong><?= $u['decided_at'] ? ' z ' . e(date('d.m.Y', strtotime($u['decided_at']))) : '' ?><br><?= e($u['company_name']) ?><?php if (($u['matched_by'] ?? '') === 'nazwa'): ?><br><span class="xs muted">Dopasowano po nazwie firmy - sprawdź w treści decyzji, czy to ten sam podmiot.</span><?php endif ?><?php if (!empty($u['url'])): ?><br><a href="<?= e(safe_url($u['url'])) ?>" target="_blank" rel="noopener">Treść decyzji</a><?php endif ?></li>
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
