<div class="card stack-sm">
  <h1>Kod logowania</h1>
  <p class="muted small" style="margin:0">Hasło się zgadza. Wpisz sześciocyfrowy kod z aplikacji albo jeden z kodów zapasowych.</p>
  <?php if (!empty($error)): ?><div class="flash error" role="alert"><?= e($error) ?></div><?php endif ?>
  <form method="post" action="/logowanie/kod" class="stack-sm">
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= e($next ?? '') ?>">
    <div class="field"><label for="code">Kod</label><input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required autofocus maxlength="20"></div>
    <button class="btn" type="submit">Zaloguj się</button>
  </form>
  <p class="small" style="margin:0"><a href="/logowanie">Wróć do logowania</a></p>
</div>
