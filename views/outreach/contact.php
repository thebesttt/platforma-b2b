<?php use App\Modules\Outreach\RiskPolicy; ?>
<header class="between page-head">
  <div>
    <p class="crumbs"><a href="/klienci">Klienci i wiadomości</a> / <?= $c['id'] ? e($c['company_name']) : 'Nowy kontakt' ?></p>
    <h1><?= $c['id'] ? e($c['company_name']) : 'Nowy kontakt' ?></h1>
    <?php if (!empty($c['unsubscribed_at'])): ?><p style="margin:8px 0 0"><span class="chip high">Wypisany <?= e(local_dt($c['unsubscribed_at'], 'd.m.Y')) ?> - nie wysyłaj ofert</span></p><?php endif ?>
  </div>
  <?php if ($c['id']): ?><a class="btn" href="/klienci/<?= (int) $c['id'] ?>/wiadomosc">Napisz wiadomość</a><?php endif ?>
</header>
<div class="cols">
  <form method="post" action="<?= $c['id'] ? '/klienci/' . (int) $c['id'] : '/klienci' ?>" class="card main stack-sm">
    <?= csrf_field() ?>
    <div class="form-grid">
      <div class="field"><label for="company_name">Firma</label><input id="company_name" name="company_name" type="text" required value="<?= e($c['company_name']) ?>"></div>
      <div class="field"><label for="nip">NIP</label><input id="nip" name="nip" type="text" inputmode="numeric" value="<?= e($c['nip']) ?>"></div>
      <div class="field"><label for="person">Osoba kontaktowa</label><input id="person" name="person" type="text" value="<?= e($c['person']) ?>"></div>
      <div class="field"><label for="email">E-mail</label><input id="email" name="email" type="email" required value="<?= e($c['email']) ?>"></div>
      <div class="field"><label for="phone">Telefon</label><input id="phone" name="phone" type="text" value="<?= e($c['phone']) ?>"></div>
      <div class="field"><label for="website">Strona firmy</label><input id="website" name="website" type="text" placeholder="firma.pl" value="<?= e($c['website'] ?? '') ?>"><span class="hint">AI nawiąże do działalności firmy w szkicu wiadomości.</span></div>
    </div>
    <fieldset style="border:0;padding:0;margin:8px 0 0">
      <legend class="label">Podstawa kontaktu</legend>
      <p class="hint muted small" style="margin:4px 0 8px">Od niej zależy ryzyko prawne wiadomości. Zapisz dowód: kiedy i jak firma poprosiła o ofertę lub zgodziła się na kontakt.</p>
      <div class="opt-list">
        <?php foreach (RiskPolicy::BASES as $k => $label): ?>
          <label class="opt"><input type="radio" name="basis" value="<?= e($k) ?>"<?= $c['basis'] === $k ? ' checked' : '' ?>> <span class="small"><?= e($label) ?></span></label>
        <?php endforeach ?>
      </div>
    </fieldset>
    <div class="form-grid">
      <div class="field" style="grid-column:1/-1"><label for="basis_detail">Szczegóły podstawy</label><input id="basis_detail" name="basis_detail" type="text" placeholder="np. zapytanie ofertowe „Utwardzenie placu manewrowego” na stronie firmy" value="<?= e($c['basis_detail']) ?>"></div>
      <div class="field"><label for="basis_url">Link do dowodu</label><input id="basis_url" name="basis_url" type="url" placeholder="https://" value="<?= e($c['basis_url']) ?>"></div>
      <div class="field"><label for="basis_at">Data</label><input id="basis_at" name="basis_at" type="date" value="<?= e($c['basis_at']) ?>"></div>
    </div>
    <div class="field"><label for="notes">Notatki</label><textarea id="notes" name="notes" rows="3"><?= e($c['notes']) ?></textarea></div>
    <div><button class="btn" type="submit">Zapisz kontakt</button></div>
  </form>
  <div class="side stack">
    <?php if ($company): ?>
      <section class="card"><h2 style="margin-bottom:8px">Kontrahent</h2><p style="margin:0 0 6px"><?= risk_chip($company['risk_score'] !== null ? (int) $company['risk_score'] : null) ?></p><a class="small" href="/kontrahenci/<?= e($company['nip']) ?>">Karta firmy</a></section>
    <?php elseif (!empty($c['nip'])): ?>
      <section class="card"><h2 style="margin-bottom:8px">Kontrahent</h2><a class="btn ghost sm" href="/kontrahenci/<?= e($c['nip']) ?>">Sprawdź firmę w rejestrach</a></section>
    <?php endif ?>
    <?php if ($c['id']): ?>
    <section class="card">
      <h2 style="margin-bottom:8px">Historia wiadomości</h2>
      <?php if ($messages): ?>
        <ul class="list-plain"><?php foreach ($messages as $m): ?><li class="small"><a href="/wiadomosci/<?= (int) $m['id'] ?>"><?= e(truncate((string) $m['subject'], 60)) ?></a><br><span class="xs muted"><?= e(RiskPolicy::KINDS[$m['kind']]['title'] ?? $m['kind']) ?> · <?= e(local_dt($m['created_at'], 'd.m.Y')) ?></span></li><?php endforeach ?></ul>
      <?php else: ?><p class="small muted" style="margin:0">Brak wiadomości.</p><?php endif ?>
    </section>
    <form method="post" action="/klienci/<?= (int) $c['id'] ?>/wypisz" class="card stack-sm" <?= empty($c['unsubscribed_at']) ? 'data-confirm="Zapisać sprzeciw tej firmy wobec dalszych wiadomości?"' : '' ?>>
      <?= csrf_field() ?>
      <p class="small" style="margin:0"><?= empty($c['unsubscribed_at']) ? 'Firma poprosiła o niewysyłanie wiadomości? Zapisz sprzeciw - zablokujemy oferty i prośby o zgodę.' : 'Sprzeciw zapisany. Usuń go tylko wtedy, gdy firma ponownie wyraziła zgodę.' ?></p>
      <button class="btn <?= empty($c['unsubscribed_at']) ? 'danger' : 'ghost' ?> sm" type="submit"><?= empty($c['unsubscribed_at']) ? 'Zapisz sprzeciw (wypisz)' : 'Usuń oznaczenie sprzeciwu' ?></button>
    </form>
    <?php endif ?>
  </div>
</div>
