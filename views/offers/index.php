<?php use App\Http\OfferController; ?>
<header class="between page-head">
  <div>
    <p class="crumbs">Oferty i wnioski</p>
    <h1>Oferty przetargowe i wnioski o dotacje</h1>
    <p class="muted small">Szkice powstają z ogłoszenia lub regulaminu naboru oraz z Twojej biblioteki. Zdania bez źródła oznaczamy do sprawdzenia.</p>
  </div>
  <a class="btn ghost" href="/biblioteka">Biblioteka firmy (<?= $libraryCount ?>)</a>
</header>

<?php if ($libraryCount === 0): ?>
  <div class="notice info">Zacznij od <a href="/biblioteka">biblioteki firmy</a>: dodaj 2-3 referencje, osoby z uprawnieniami i krótki opis firmy. Z nich powstają sekcje „Doświadczenie” i „Zespół”.</div>
<?php endif ?>

<div class="cols">
  <section class="card flush main" aria-labelledby="t-list">
    <div class="card-head"><h2 id="t-list">Twoje szkice</h2></div>
    <?php if ($offers): ?>
      <div class="table-wrap"><table>
        <thead><tr><th scope="col">Dokument</th><th scope="col">Rodzaj</th><th scope="col">Termin</th><th scope="col">Status</th><th scope="col">Zmieniono</th></tr></thead>
        <tbody>
        <?php foreach ($offers as $o): $st = OfferController::STATUSES[$o['status']] ?? OfferController::STATUSES['draft']; $dl = $o['tender_deadline'] ?: $o['deadline_at']; ?>
          <tr>
            <td><a class="t-title" href="/oferty/<?= (int) $o['id'] ?>"><?= e(truncate((string) $o['title'], 110)) ?></a><div class="t-sub"><?= e($o['kind'] === 'grant' ? ($o['call_name'] ?? '') : ($o['buyer_name'] ?? 'bez przetargu')) ?></div></td>
            <td><span class="chip <?= $o['kind'] === 'grant' ? 'info' : 'neutral' ?>"><?= $o['kind'] === 'grant' ? 'wniosek o dotację' : 'oferta' ?></span></td>
            <td class="nowrap small"><?= $dl ? e(local_dt($dl, 'd.m.Y H:i')) : '<span class="muted">-</span>' ?></td>
            <td><span class="chip <?= $st['chip'] ?>"><?= e($st['label']) ?></span></td>
            <td class="nowrap small muted"><?= e(local_dt($o['updated_at'], 'd.m H:i')) ?></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table></div>
    <?php else: ?>
      <div class="empty"><h3>Brak szkiców</h3><p>Otwórz przetarg i kliknij „Utwórz szkic oferty” albo rozpocznij wniosek o dotację.</p><a class="btn ghost" href="/przetargi">Przejdź do przetargów</a></div>
    <?php endif ?>
  </section>

  <div class="side stack">
    <section class="card" aria-labelledby="t-grant">
      <h2 id="t-grant" style="margin-bottom:8px">Nowy wniosek o dotację</h2>
      <form method="post" action="/oferty" class="stack-sm">
        <?= csrf_field() ?><input type="hidden" name="kind" value="grant">
        <div class="field"><label for="call_name">Nabór lub program</label><input id="call_name" name="call_name" type="text" required maxlength="200" placeholder="np. FENG - Ścieżka SMART, nabór 2026"></div>
        <div class="field"><label for="call_url">Strona naboru</label><input id="call_url" name="call_url" type="url" placeholder="https://"></div>
        <div class="field"><label for="deadline">Termin składania wniosków</label><input id="deadline" name="deadline" type="date"></div>
        <button class="btn" type="submit">Rozpocznij wniosek</button>
      </form>
      <p class="muted xs" style="margin:8px 0 0">Aktualne nabory znajdziesz na funduszeeuropejskie.gov.pl. Po utworzeniu wgraj regulamin i kryteria oceny.</p>
    </section>
    <section class="card" aria-labelledby="t-blank">
      <h2 id="t-blank" style="margin-bottom:8px">Oferta poza przetargiem</h2>
      <p class="muted small">Np. na zapytanie ofertowe od firmy prywatnej.</p>
      <form method="post" action="/oferty"><?= csrf_field() ?><button class="btn ghost" type="submit">Pusty szkic oferty</button></form>
    </section>
  </div>
</div>
