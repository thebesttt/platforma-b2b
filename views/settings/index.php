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
        <div class="field"><label for="s-nip">NIP</label><input id="s-nip" name="nip" type="text" inputmode="numeric" value="<?= e($org['nip']) ?>"<?= App\Core\Auth::isOwner() ? '' : ' disabled' ?>></div>
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

    <section class="card stack-sm" id="bezpieczenstwo" aria-labelledby="bz-h">
      <h2 id="bz-h">Bezpieczeństwo konta</h2>
      <p class="small" style="margin:0">Logowanie dwuetapowe: <?= (int) $user['totp_on'] === 1 ? '<span class="chip low">włączone</span>' : '<span class="chip neutral">wyłączone</span>' ?>. Po haśle panel pyta o kod z aplikacji w telefonie, więc samo hasło nie wystarczy do wejścia na konto.</p>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <a class="btn ghost" href="/ustawienia/logowanie-dwuetapowe"><?= (int) $user['totp_on'] === 1 ? 'Kody zapasowe i wyłączenie' : 'Włącz logowanie dwuetapowe' ?></a>
        <form method="post" action="/ustawienia/wyloguj-wszedzie"><?= csrf_field() ?><button class="btn ghost" type="submit">Wyloguj pozostałe urządzenia</button></form>
      </div>
    </section>

    <?php if (App\Core\Auth::isOwner()): ?>
    <section class="card stack-sm" id="dane" aria-labelledby="dn-h">
      <h2 id="dn-h">Dane firmy</h2>
      <p class="small muted" style="margin:0">Wszystko, co firma wpisała i wgrała do panelu, w jednej paczce: kontakty, wiadomości, oferty, zapytania, audyty, ustawienia. Bez haseł i kluczy.</p>
      <div><a class="btn ghost" href="/ustawienia/dane-firmy.zip">Pobierz dane firmy</a></div>
      <details>
        <summary class="small">Usuń konto firmy</summary>
        <form method="post" action="/ustawienia/usun-firme" class="stack-sm" style="margin-top:12px">
          <?= csrf_field() ?>
          <p class="small" style="margin:0">Usuwa firmę, wszystkie konta osób i wszystkie dane. Tego nie da się cofnąć, więc najpierw pobierz dane.</p>
          <div class="form-grid">
            <div class="field"><label for="d-name">Przepisz nazwę firmy</label><input id="d-name" name="confirm" type="text" required autocomplete="off" placeholder="<?= e($org['name']) ?>"></div>
            <div class="field"><label for="d-pass">Twoje hasło</label><input id="d-pass" name="current" type="password" required autocomplete="current-password"></div>
          </div>
          <div><button class="btn danger" type="submit">Usuń firmę i wszystkie dane</button></div>
        </form>
      </details>
    </section>
    <?php endif ?>

    <?php if (App\Core\Auth::isOwner()): ?>
    <form method="post" action="/ustawienia/wyglad" class="card stack-sm" id="wyglad">
      <?= csrf_field() ?>
      <h2>Wygląd i moduły</h2>
      <div class="field" style="max-width:360px"><label for="a-theme">Wygląd panelu</label>
        <select id="a-theme" name="theme"><?php foreach (App\Core\Brand::THEMES as $k => $label): ?><option value="<?= e($k) ?>"<?= $theme === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></div>
      <fieldset style="border:0;padding:0;margin:0">
        <legend class="label" style="padding:0;margin-bottom:4px">Moduły w menu</legend>
        <p class="muted small" style="margin:0 0 4px">Wyłączony moduł znika z menu. Jego dane zostają.</p>
        <?php foreach (App\Core\Brand::MODULES as $k => $label): ?>
          <label class="check" style="min-height:0;padding:6px 0"><input type="checkbox" name="modules[]" value="<?= e($k) ?>"<?= in_array($k, $modules, true) ? ' checked' : '' ?>> <span><?= e($label) ?></span></label>
        <?php endforeach ?>
      </fieldset>
      <div><button class="btn ghost" type="submit">Zapisz wygląd i moduły</button></div>
    </form>
    <?php endif ?>
  </div>
  <div class="side stack">
    <section class="card" id="pakiet" aria-labelledby="pk-h">
      <h2 id="pk-h" style="margin-bottom:4px">Pakiet <?= e($plan['name']) ?></h2>
      <p class="small muted" style="margin:0 0 8px"><?= $plan['key'] === 'internal' ? 'Firma prowadząca panel: bez limitów.' : 'Przydział AI odnawia się ' . e(date('d.m.Y', strtotime($plan['renews']))) . '. Funkcje bez AI nie mają limitu użyć.' ?></p>
      <?php foreach ($plan['limits'] as $key => $l): $full = $l['limit'] !== null && $l['used'] >= $l['limit']; ?>
        <div class="kv-row"><div>
          <p class="k"><?= e($l['label']) ?></p>
          <?php if ($l['limit'] !== null): ?><div class="bar" style="margin-top:6px" role="img" aria-label="<?= e($l['label'] . ': ' . $l['used'] . ' z ' . $l['limit']) ?>"><span style="width:<?= $l['limit'] > 0 ? min(100, round(100 * $l['used'] / $l['limit'])) : 100 ?>%"></span></div><?php endif ?>
          <?php if ($full && str_starts_with($key, 'ai_')): ?><p class="xs" style="margin:6px 0 0">Przydział wyczerpany. <?= $key === 'ai_ops' ? 'Wersje bez AI działają dalej.' : 'Widżet zbiera kontakty bez czatu.' ?></p><?php endif ?>
        </div><b class="nowrap"><?= num_pl($l['used']) ?><?= $l['limit'] !== null ? ' z ' . num_pl($l['limit']) : '' ?></b></div>
      <?php endforeach ?>
      <?php if ($plan['key'] === 'basic'): ?><p class="small muted" style="margin:10px 0 0">Więcej AI, osób i witryn jest w pakiecie Rozszerzonym.<?= $plan['sales'] !== '' ? ' Napisz: ' . e($plan['sales']) . '.' : ' Zmienia go administrator platformy.' ?></p><?php endif ?>
    </section>
    <section class="card" id="zespol">
      <h2 style="margin-bottom:8px">Zespół</h2>
      <ul class="list-plain"><?php foreach ($team as $t): ?><li class="small"><?= e($t['name'] ?: $t['email']) ?> <span class="chip neutral"><?= $t['role'] === 'owner' ? 'właściciel' : 'członek' ?></span><?= (int) $t['totp_on'] === 1 ? ' <span class="chip low">dwa etapy</span>' : '' ?><br><span class="xs muted"><?= e($t['email']) ?></span>
        <?php if (App\Core\Auth::isOwner() && $t['role'] !== 'owner' && (int) $t['id'] !== (int) $user['id']): ?>
          <span style="display:flex;gap:12px;flex-wrap:wrap;margin-top:4px">
            <form method="post" action="/ustawienia/zespol/<?= (int) $t['id'] ?>/usun"><?= csrf_field() ?><button class="link-btn xs" type="submit">Usuń z zespołu</button></form>
            <?php if ((int) $t['totp_on'] === 1): ?><form method="post" action="/ustawienia/zespol/<?= (int) $t['id'] ?>/kody"><?= csrf_field() ?><button class="link-btn xs" type="submit">Wyłącz logowanie dwuetapowe</button></form><?php endif ?>
          </span>
        <?php endif ?></li><?php endforeach ?></ul>
      <?php if (App\Core\Auth::isOwner()): ?>
      <form method="post" action="/ustawienia/zespol" class="stack-sm" style="margin-top:12px">
        <?= csrf_field() ?>
        <div class="field"><label for="t-name" class="small">Imię i nazwisko</label><input id="t-name" name="name" type="text"></div>
        <div class="field"><label for="t-email" class="small">E-mail</label><input id="t-email" name="email" type="email" required></div>
        <?php if ($mail !== 'none'): ?>
          <div class="field"><label for="t-pass" class="small">Hasło startowe (opcjonalnie)</label><input id="t-pass" name="password" type="text" minlength="10" autocomplete="off" aria-describedby="t-pass-h"><span class="xs muted" id="t-pass-h">Puste pole: osoba dostanie e-mail z linkiem do ustawienia hasła.</span></div>
        <?php else: ?>
          <div class="field"><label for="t-pass" class="small">Hasło startowe</label><input id="t-pass" name="password" type="text" minlength="10" required autocomplete="off"></div>
        <?php endif ?>
        <button class="btn ghost sm" type="submit">Dodaj osobę</button>
      </form>
      <?php endif ?>
    </section>
    <?php if (App\Core\Auth::isAdmin()): ?>
    <section class="card stack-sm"><h2>Administracja</h2>
      <a href="/administracja">Firmy, zaproszenia i pakiety</a>
      <a href="/ustawienia/uokik">Decyzje UOKiK - aktualizacja ręczna</a>
      <form method="post" action="/ustawienia/test-ai"><?= csrf_field() ?><button class="btn ghost sm" type="submit" data-busy="Sprawdzam…">Sprawdź połączenie z AI</button></form>
      <form method="post" action="/ustawienia/test-ceidg"><?= csrf_field() ?><button class="btn ghost sm" type="submit" data-busy="Sprawdzam…">Sprawdź połączenie z CEIDG</button></form>
      <form method="post" action="/ustawienia/test-google"><?= csrf_field() ?><button class="btn ghost sm" type="submit" data-busy="Sprawdzam…">Sprawdź połączenie z Google</button></form>
    </section>
    <?php endif ?>
    <section class="card stack-sm" id="poczta">
      <h2>Poczta z panelu</h2>
      <p class="small" style="margin:0">Powiadomienia i linki do hasła:
        <?php if ($mail === 'resend'): ?><span class="chip low">wysyłka działa</span><?php elseif ($mail === 'log'): ?><span class="chip neutral">tryb lokalny, bez wysyłki</span><?php else: ?><span class="chip mid">nie skonfigurowana</span><?php endif ?></p>
      <?php if ($mail === 'none'): ?><p class="small muted" style="margin:0">Administrator ustawia zmienne RESEND_API_KEY i MAIL_FROM. Bez nich panel nie wyśle powiadomienia o nowym zapytaniu ani linku do hasła.</p><?php endif ?>
      <?php if ($outbox): ?>
        <ul class="list-plain"><?php foreach ($outbox as $m): ?><li class="xs"><span class="chip <?= in_array($m['status'], ['sent', 'logged'], true) ? 'low' : 'high' ?>"><?= e(['sent' => 'wysłano', 'logged' => 'zapisano', 'failed' => 'błąd', 'skipped' => 'pominięto'][$m['status']] ?? $m['status']) ?></span> <?= e(truncate((string) $m['subject'], 60)) ?><br><span class="muted"><?= e($m['recipient']) ?>, <?= e(local_dt($m['created_at'])) ?><?= $m['error'] ? ': ' . e($m['error']) : '' ?></span></li><?php endforeach ?></ul>
      <?php endif ?>
      <?php if (App\Core\Auth::isOwner()): ?>
        <form method="post" action="/ustawienia/przeglad" class="stack-sm">
          <?= csrf_field() ?>
          <label class="check" style="min-height:0;padding:0"><input type="checkbox" name="digest" value="1"<?= $digest ? ' checked' : '' ?>> <span class="small">Poranny przegląd: nowe przetargi, zapytania ze strony i alerty o kontrahentach. Wychodzi tylko wtedy, gdy jest coś nowego.</span></label>
          <div><button class="btn ghost sm" type="submit">Zapisz</button></div>
        </form>
      <?php endif ?>
      <?php if (App\Core\Auth::isAdmin()): ?><form method="post" action="/ustawienia/test-poczty"><?= csrf_field() ?><button class="btn ghost sm" type="submit" data-busy="Wysyłam…">Wyślij wiadomość kontrolną</button></form><?php endif ?>
    </section>
    <section class="card">
      <h2 style="margin-bottom:8px">Dane i AI</h2>
      <p class="small">Funkcje AI: <?= $aiEnabled ? '<span class="chip low">włączone</span>' : '<span class="chip neutral">wyłączone</span>' ?></p>
      <p class="small muted" style="margin:0">Dokumenty przetargów i naborów przechowujemy jako tekst, wyłącznie dla Twojej firmy. Do modelu AI trafiają tylko przy analizie, którą sam uruchamiasz. Należności od kontrahentów nie są pokazywane innym użytkownikom.</p>
    </section>
    <section class="card"><form method="post" action="/wyloguj"><?= csrf_field() ?><button class="btn ghost sm" type="submit">Wyloguj</button></form></section>
  </div>
</div>
