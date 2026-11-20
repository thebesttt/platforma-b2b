<div class="card stack-sm">
  <h1>Ustaw hasło</h1>
  <?php if (!empty($error)): ?><div class="flash error" role="alert"><?= e($error) ?></div><?php endif ?>
  <form method="post" action="/haslo/<?= e($token) ?>" class="stack-sm">
    <?= csrf_field() ?>
    <div class="field"><label for="password">Nowe hasło</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="10" required aria-describedby="pw-hint"><span class="xs muted" id="pw-hint">Co najmniej 10 znaków.</span></div>
    <button class="btn" type="submit">Zapisz hasło</button>
  </form>
</div>
