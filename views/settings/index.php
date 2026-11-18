<header class="page-head">
  <p class="crumbs">Ustawienia</p>
  <h1>Ustawienia</h1>
</header>
<div class="cols">
  <div class="main stack">
    <form method="post" action="/ustawienia" class="card stack-sm">
      <?= csrf_field() ?>
      <h2>Dane firmy</h2>
      <p class="muted small" style="margin:0">Trafiają do formularzy ofert, wniosków i wezwań do zapłaty.</p>
      <div class="form-grid">
        <div class="field"><label for="s-name">Nazwa firmy</label><input id="s-name" name="name" type="text" value="<?= e($org['name']) ?>"<?= App\Core\Auth::isOwner() ? '' : ' disabled' ?>></div>
        <div class="field"><label for="s-nip">NIP</label><input id="s-nip" name="nip" type="text" inputmode="numeric" value="<?= e($org['nip']) ?>"></div>
        <div class="field" style="grid-column:1/-1"><label for="s-addr">Adres siedziby</label><input id="s-addr" name="address" type="text" value="<?= e($org['address']) ?>" placeholder="ul. Przykładowa 1, 00-001 Warszawa"></div>
        <div class="field"><label for="s-phone">Telefon</label><input id="s-phone" name="phone" type="text" value="<?= e($org['phone']) ?>"></div>
        <div class="field"><label for="s-web">Strona internetowa</label><input id="s-web" name="website" type="text" value="<?= e($org['website']) ?>"></div>
        <div class="field"><label for="s-uname">Twoje imię i nazwisko</label><input id="s-uname" name="user_name" type="text" value="<?= e($user['name']) ?>"></div>
      </div>
      <div><button class="btn" type="submit">Zapisz</button></div>
    </form>

    <form method="post" action="/ustawienia/haslo" class="card stack-sm" id="haslo">
      <?= csrf_field() ?>
      <h2>Zmiana hasła</h2>
      <div class="form-grid">
        <div class="field"><label for="p-cur">Obecne hasło</label><input id="p-cur" name="current" type="password" autocomplete="current-password" required></div>
        <div class="field"><label for="p-new">Nowe hasło</label><input id="p-new" name="new" type="password" autocomplete="new-password" minlength="10" required></div>
      </div>
      <div><button class="btn ghost" type="submit">Zmień hasło</button></div>
    </form>
  </div>
  <div class="side stack">
    <section class="card" id="zespol">
      <h2 style="margin-bottom:8px">Zespół</h2>
      <ul class="list-plain"><?php foreach ($team as $t): ?><li class="small"><?= e($t['name'] ?: $t['email']) ?> <span class="chip neutral"><?= $t['role'] === 'owner' ? 'właściciel' : 'członek' ?></span><br><span class="xs muted"><?= e($t['email']) ?></span></li><?php endforeach ?></ul>
      <?php if (App\Core\Auth::isOwner()): ?>
      <form method="post" action="/ustawienia/zespol" class="stack-sm" style="margin-top:12px">
        <?= csrf_field() ?>
        <div class="field"><label for="t-name" class="small">Imię i nazwisko</label><input id="t-name" name="name" type="text"></div>
        <div class="field"><label for="t-email" class="small">E-mail</label><input id="t-email" name="email" type="email" required></div>
        <div class="field"><label for="t-pass" class="small">Hasło startowe</label><input id="t-pass" name="password" type="text" minlength="10" required autocomplete="off"></div>
        <button class="btn ghost sm" type="submit">Dodaj osobę</button>
      </form>
      <?php endif ?>
    </section>
    <section class="card">
      <h2 style="margin-bottom:8px">Dane i AI</h2>
      <p class="small">Funkcje AI: <?= $aiEnabled ? '<span class="chip low">włączone</span>' : '<span class="chip neutral">wyłączone</span>' ?></p>
      <p class="small muted" style="margin:0">Dokumenty przetargów i naborów przechowujemy jako tekst, wyłącznie dla Twojej firmy. Do modelu AI trafiają tylko przy analizie, którą sam uruchamiasz. Należności od kontrahentów nie są pokazywane innym użytkownikom.</p>
    </section>
  </div>
</div>
