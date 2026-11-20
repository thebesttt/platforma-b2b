<header class="page-head">
  <p class="crumbs">Kontrahenci</p>
  <h1>Sprawdź kontrahenta przed umową</h1>
  <p class="muted small">Biała lista VAT, KRS i decyzje UOKiK o nadmiernym opóźnianiu płatności. Tylko dane publiczne.</p>
</header>
<section class="card">
  <form class="searchbar" method="get" action="/kontrahenci/sprawdz">
    <label class="sr-only" for="c-q">NIP lub nazwa firmy</label>
    <input id="c-q" name="q" type="search" value="<?= e($q) ?>" placeholder="NIP (10 cyfr) albo nazwa firmy sprawdzonej wcześniej" required>
    <button class="btn" type="submit">Sprawdź firmę</button>
  </form>
  <?php if ($q !== ''): ?>
    <div style="margin-top:12px">
    <?php if ($found): ?>
      <ul class="list-plain"><?php foreach ($found as $f): ?><li class="between" style="align-items:center"><span><a href="/kontrahenci/<?= e($f['nip']) ?>"><?= e($f['name']) ?></a><br><span class="xs muted">NIP <?= e(App\Core\Nip::format($f['nip'])) ?> · <?= e($f['address']) ?></span></span><?= risk_chip($f['risk_score'] !== null ? (int) $f['risk_score'] : null) ?></li><?php endforeach ?></ul>
    <?php else: ?>
      <p class="muted small" style="margin:0">Po nazwie wyszukujemy firmy sprawdzone wcześniej na platformie. Nową firmę znajdziesz po numerze NIP.</p>
    <?php endif ?>
    </div>
  <?php endif ?>
</section>

<div class="cols">
  <section class="card flush main" aria-labelledby="t-watch">
    <div class="card-head"><h2 id="t-watch">Obserwowane firmy</h2><span class="muted xs">Sprawdzamy zmiany codziennie</span></div>
    <?php if ($watch): ?>
      <div class="table-wrap"><table>
        <thead><tr><th scope="col">Firma</th><th scope="col">Ryzyko płatnicze</th><th scope="col" class="n">Otwarte faktury</th><th scope="col">Sprawdzono</th></tr></thead>
        <tbody>
        <?php foreach ($watch as $w): ?>
          <tr>
            <td><a class="t-title" href="/kontrahenci/<?= e($w['nip']) ?>"><?= e($w['name'] ?: 'NIP ' . $w['nip']) ?></a><div class="t-sub">NIP <?= e(App\Core\Nip::format($w['nip'])) ?><?= $w['note'] ? ' · ' . e($w['note']) : '' ?></div></td>
            <td><?= risk_chip($w['risk_score'] !== null ? (int) $w['risk_score'] : null) ?></td>
            <td class="n"><?= (int) $w['open_invoices'] ?: '<span class="muted">-</span>' ?></td>
            <td class="nowrap small muted"><?= e(local_dt($w['refreshed_at'], 'd.m H:i')) ?></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table></div>
    <?php else: ?>
      <div class="empty"><h3>Nie obserwujesz jeszcze żadnej firmy</h3><p>Sprawdź kontrahenta po NIP i kliknij „Obserwuj”. Powiadomimy Cię o zmianie konta na białej liście, nowych wpisach w KRS i decyzjach UOKiK.</p></div>
    <?php endif ?>
  </section>

  <div class="side stack">
    <section class="card" aria-labelledby="t-al">
      <div class="between" style="align-items:center;margin-bottom:8px">
        <h2 id="t-al">Alerty</h2>
        <?php if (array_filter($alerts, static fn ($a) => !$a['read_at'])): ?><form class="inline" method="post" action="/kontrahenci/alerty"><?= csrf_field() ?><button class="link-btn small" type="submit">Oznacz jako przeczytane</button></form><?php endif ?>
      </div>
      <?php if ($alerts): ?>
        <ul class="list-plain">
          <?php foreach ($alerts as $a): ?>
            <li><span class="chip <?= $a['level'] === 'high' ? 'high' : 'mid' ?>"><?= $a['read_at'] ? 'przeczytany' : 'nowy' ?></span> <span class="xs muted"><?= e(local_dt($a['created_at'], 'd.m.Y H:i')) ?></span>
              <div style="font-weight:600;margin-top:4px"><?php if ($a['nip']): ?><a href="/kontrahenci/<?= e($a['nip']) ?>"><?= e($a['title']) ?></a><?php elseif ($a['tender_id']): ?><a href="/przetargi/<?= (int) $a['tender_id'] ?>#wynik"><?= e($a['title']) ?></a><?php else: ?><?= e($a['title']) ?><?php endif ?></div>
              <div class="small"><?= e($a['detail']) ?></div></li>
          <?php endforeach ?>
        </ul>
      <?php else: ?><p class="muted small" style="margin:0">Brak alertów.</p><?php endif ?>
    </section>
    <section class="card" aria-labelledby="t-rec">
      <h2 id="t-rec" style="margin-bottom:8px">Twoje należności</h2>
      <?php if ($receivables['rows']): ?>
        <p class="small" style="margin:0 0 8px">Po terminie: <strong><?= e(money_pl($receivables['overdue_sum'])) ?></strong> (<?= $receivables['overdue_count'] ?> <?= plural($receivables['overdue_count'], 'faktura', 'faktury', 'faktur') ?>)</p>
        <ul class="list-plain">
          <?php foreach (array_slice($receivables['rows'], 0, 6) as $rr): $od = App\Modules\Companies\Receivables::daysOverdue($rr); ?>
            <li class="small"><a href="/kontrahenci/<?= e($rr['nip']) ?>#naleznosci"><?= e($rr['company_name']) ?></a> · <?= e($rr['invoice_no']) ?> · <?= e(money_pl($rr['amount'])) ?><?= $od ? ' · <span class="chip ' . ($od >= 30 ? 'high' : 'mid') . '">' . $od . ' ' . plural($od, 'dzień', 'dni', 'dni') . ' po terminie</span>' : '' ?></li>
          <?php endforeach ?>
        </ul>
      <?php else: ?><p class="muted small" style="margin:0">Dodaj nieopłacone faktury w karcie kontrahenta. Pomożemy przygotować wezwanie do zapłaty i sprawdzimy, kiedy możesz wpisać dług do biura informacji gospodarczej.</p><?php endif ?>
    </section>
  </div>
</div>
