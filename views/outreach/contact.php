<?php use App\Modules\Outreach\RiskPolicy; ?>
<header class="between page-head">
  <div>
    <p class="crumbs"><a href="/klienci">Klienci i wiadomości</a> / <?= $c['id'] ? e($c['company_name']) : 'Nowy kontakt' ?></p>
    <h1><?= $c['id'] ? e($c['company_name']) : 'Nowy kontakt' ?></h1>
    <?php if (!empty($c['unsubscribed_at'])): ?><p style="margin:8px 0 0"><span class="chip high">Wypisany <?= e(local_dt($c['unsubscribed_at'], 'd.m.Y')) ?> - nie wysyłaj ofert</span></p><?php endif ?>
  </div>
  <?php if ($c['id']): ?><a class="btn" href="/klienci/<?= (int) $c['id'] ?>/wiadomosc">Napisz wiadomość</a><?php endif ?>
</header>
<?php if (!empty($voice)): ?>
<section class="card" id="glos" aria-labelledby="t-voice" style="margin-bottom:16px">
  <h2 id="t-voice" style="margin-bottom:4px">Co napisał klient</h2>
  <p class="small muted" style="margin:0 0 10px">Własne słowa klienta: zapytanie ze strony, zapytania ofertowe i odpowiedzi, które wkleiłeś z poczty.</p>
  <?php foreach ($voice as $i => $v): ?>
    <details class="voice"<?= $i === 0 ? ' open' : '' ?>>
      <summary><b><?= e($v['source']) ?></b> <span class="muted">· <?= e(local_dt($v['at'], 'd.m.Y')) ?></span> <span class="voice-peek"><?= e(truncate(preg_replace('/\s+/u', ' ', $v['text']), 90)) ?></span></summary>
      <div class="voice-body"><?= nl2br(e($v['text'])) ?></div>
      <div class="row-flex" style="margin-top:8px">
        <?php if ($v['link']): ?><a class="small" href="<?= e($v['link']) ?>"><?= $v['kind'] === 'lead' ? 'Otwórz zapytanie' : 'Otwórz w zapytaniach z rynku' ?></a><?php endif ?>
        <?php if ($v['kind'] === 'reply'): ?>
          <form class="inline" method="post" action="/klienci/<?= (int) $c['id'] ?>/odpowiedz" data-confirm="Usunąć tę wklejoną odpowiedź?"><?= csrf_field() ?><input type="hidden" name="usun_odpowiedz" value="<?= (int) $v['id'] ?>"><button class="btn ghost sm" type="submit">Usuń</button></form>
        <?php endif ?>
      </div>
    </details>
  <?php endforeach ?>
</section>
<?php endif ?>
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
    <form method="post" action="/klienci/<?= (int) $c['id'] ?>/odpowiedz" class="card stack-sm">
      <?= csrf_field() ?>
      <h2>Odpowiedź i przypomnienia</h2>
      <p class="small" style="margin:0">
        <?php if (!empty($c['replied_at'])): ?>Firma odpisała <?= e(local_dt($c['replied_at'], 'd.m.Y')) ?>. Przypomnień nie planujemy.
        <?php elseif (!empty($sequence)): ?>Przypomnienia w toku: kolejny krok <?= e(local_dt($sequence['next_at'], 'd.m.Y')) ?>. <a href="/klienci/przypomnienia">Zobacz listę</a>
        <?php else: ?>Gdy firma odpisze, zaznacz to tutaj. Zatrzyma to zaplanowane przypomnienia.<?php endif ?>
      </p>
      <div class="field"><label for="rp-body">Co napisał klient (opcjonalnie)</label><textarea id="rp-body" name="tresc" rows="3" maxlength="20000" placeholder="Wklej treść odpowiedzi z poczty"></textarea>
        <span class="hint">Panel nie czyta Twojej skrzynki. Wklejona odpowiedź zostaje przy kontakcie i widać ją na liście klientów.</span></div>
      <div class="field"><label for="rp-date">Kiedy napisał (opcjonalnie)</label><input id="rp-date" type="date" name="data" max="<?= e(gmdate('Y-m-d')) ?>"></div>
      <div class="row-flex">
        <button class="btn sm" type="submit" name="zapisz" value="1"><?= empty($c['replied_at']) ? 'Firma odpisała' : 'Dodaj odpowiedź' ?></button>
        <?php if (!empty($c['replied_at'])): ?><button class="btn ghost sm" type="submit" name="cofnij" value="1" formnovalidate>Usuń oznaczenie odpowiedzi</button><?php endif ?>
      </div>
    </form>
    <form method="post" action="/klienci/<?= (int) $c['id'] ?>/wypisz" class="card stack-sm" <?= empty($c['unsubscribed_at']) ? 'data-confirm="Zapisać sprzeciw tej firmy wobec dalszych wiadomości?"' : '' ?>>
      <?= csrf_field() ?>
      <p class="small" style="margin:0"><?= empty($c['unsubscribed_at']) ? 'Firma poprosiła o niewysyłanie wiadomości? Zapisz sprzeciw - zablokujemy oferty i prośby o zgodę.' : 'Sprzeciw zapisany. Usuń go tylko wtedy, gdy firma ponownie wyraziła zgodę.' ?></p>
      <button class="btn <?= empty($c['unsubscribed_at']) ? 'danger' : 'ghost' ?> sm" type="submit"><?= empty($c['unsubscribed_at']) ? 'Zapisz sprzeciw (wypisz)' : 'Usuń oznaczenie sprzeciwu' ?></button>
    </form>
    <?php endif ?>
  </div>
</div>
