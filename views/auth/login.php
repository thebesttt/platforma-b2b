<div class="card stack-sm">
  <h1>Zaloguj się</h1>
  <?php if (!empty($error)): ?><div class="flash error" role="alert"><?= e($error) ?></div><?php endif ?>
  <form method="post" action="/logowanie" class="stack-sm">
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <div class="field"><label for="email">E-mail</label><input id="email" name="email" type="email" autocomplete="username" required value="<?= e($email) ?>"></div>
    <div class="field"><label for="password">Hasło</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>
    <button class="btn" type="submit">Zaloguj się</button>
  </form>
  <?php if ((string) config('SIGNUP_OPEN') === '1'): ?><p class="muted small" style="margin:0">Nie masz konta? <a href="/rejestracja">Załóż konto firmy</a></p><?php endif ?>
</div>
