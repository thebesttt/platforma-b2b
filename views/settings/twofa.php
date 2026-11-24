<header class="page-head">
  <p class="crumbs"><a href="/ustawienia#bezpieczenstwo">Ustawienia</a> / Logowanie dwuetapowe</p>
  <h1>Logowanie dwuetapowe</h1>
  <p class="muted small">Po haśle panel pyta o kod z aplikacji w telefonie. Działa każda aplikacja z kodami: Google Authenticator, Microsoft Authenticator, 1Password, Authy.</p>
</header>
<?php if ($codes): ?>
<section class="card stack-sm" aria-labelledby="tf-codes">
  <h2 id="tf-codes">Kody zapasowe</h2>
  <p class="small" style="margin:0">Każdy działa raz i zastępuje kod z aplikacji, gdy nie masz telefonu. Zapisz je teraz w bezpiecznym miejscu: panel nie pokaże ich drugi raz.</p>
  <p style="margin:0;display:flex;flex-wrap:wrap;gap:8px"><?php foreach ($codes as $c): ?><code class="copy"><?= e($c) ?></code><?php endforeach ?></p>
</section>
<?php endif ?>
<?php if ($on): ?>
<section class="card stack-sm" aria-labelledby="tf-on">
  <h2 id="tf-on">Włączone</h2>
  <p class="small" style="margin:0">Zostało kodów zapasowych: <b><?= (int) $left ?></b>. Gdy stracisz telefon i kody, właściciel konta firmy może wyłączyć logowanie dwuetapowe w zakładce Zespół.</p>
  <details>
    <summary class="small">Wyłącz logowanie dwuetapowe</summary>
    <form method="post" action="/ustawienia/logowanie-dwuetapowe/wylacz" class="form-grid" style="margin-top:12px">
      <?= csrf_field() ?>
      <div class="field"><label for="tf-p2">Hasło</label><input id="tf-p2" name="current" type="password" required autocomplete="current-password"></div>
      <div class="field"><label for="tf-c2">Kod z aplikacji albo zapasowy</label><input id="tf-c2" name="code" type="text" required autocomplete="one-time-code" maxlength="20"></div>
      <div class="field" style="justify-content:flex-end"><button class="btn danger" type="submit">Wyłącz</button></div>
    </form>
  </details>
</section>
<?php elseif ($secret): ?>
<section class="card stack-sm" aria-labelledby="tf-scan">
  <h2 id="tf-scan">Zeskanuj kod i wpisz liczbę z aplikacji</h2>
  <ol class="law">
    <li>W aplikacji z kodami wybierz dodanie konta i zeskanuj ten obrazek.</li>
    <li>Wpisz poniżej sześć cyfr, które pokaże aplikacja.</li>
  </ol>
  <?php if ($qr): ?><p style="margin:0"><img src="data:image/png;base64,<?= base64_encode($qr) ?>" alt="Kod do zeskanowania w aplikacji z kodami" width="220" height="220" style="background:#fff;padding:8px;border-radius:8px;image-rendering:pixelated"></p><?php endif ?>
  <p class="small muted" style="margin:0">Nie możesz zeskanować? Wpisz w aplikacji klucz ręcznie: <code class="copy wrap"><?= e(trim(chunk_split($secret, 4, ' '))) ?></code></p>
  <form method="post" action="/ustawienia/logowanie-dwuetapowe/wlacz" class="searchbar">
    <?= csrf_field() ?>
    <label class="sr-only" for="tf-code">Kod z aplikacji</label>
    <input id="tf-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required maxlength="8" placeholder="123456" style="max-width:200px">
    <button class="btn" type="submit">Włącz</button>
  </form>
</section>
<?php else: ?>
<section class="card stack-sm" aria-labelledby="tf-start">
  <h2 id="tf-start">Włącz w dwóch krokach</h2>
  <p class="small" style="margin:0">Najpierw potwierdź hasłem, że to Ty. Potem zeskanujesz kod aplikacją w telefonie.</p>
  <form method="post" action="/ustawienia/logowanie-dwuetapowe/start" class="searchbar">
    <?= csrf_field() ?>
    <label class="sr-only" for="tf-pass">Hasło</label>
    <input id="tf-pass" name="current" type="password" required autocomplete="current-password" placeholder="Twoje hasło" style="max-width:280px">
    <button class="btn" type="submit">Dalej</button>
  </form>
</section>
<?php endif ?>
