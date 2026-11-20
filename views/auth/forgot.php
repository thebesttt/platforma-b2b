<div class="card stack-sm">
  <h1>Nowe hasło</h1>
  <?php if ($mail): ?>
    <p class="muted small" style="margin:0">Podaj e-mail konta. Wyślemy link do ustawienia nowego hasła. Link działa godzinę.</p>
    <form method="post" action="/haslo" class="stack-sm">
      <?= csrf_field() ?>
      <div class="field"><label for="email">E-mail</label><input id="email" name="email" type="email" autocomplete="username" required></div>
      <button class="btn" type="submit">Wyślij link</button>
    </form>
  <?php else: ?>
    <p style="margin:0">Wysyłka e-maili nie jest jeszcze skonfigurowana, więc nie możemy wysłać linku. Poproś właściciela konta o nowe hasło.</p>
  <?php endif ?>
  <p class="small" style="margin:0"><a href="/logowanie">Wróć do logowania</a></p>
</div>
