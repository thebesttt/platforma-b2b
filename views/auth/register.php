<?php
$terms = (string) config('TERMS_URL');
$privacy = (string) config('PRIVACY_URL');
?>
<div class="card stack-sm">
  <h1>Załóż konto firmy</h1>
  <p class="muted small" style="margin:0">Po rejestracji ustawisz profil przetargowy i dodasz kontrahentów do obserwowania.</p>
  <?php if (!empty($error)): ?><div class="flash error" role="alert"><?= e($error) ?></div><?php endif ?>
  <form method="post" action="/rejestracja" class="stack-sm">
    <?= csrf_field() ?>
    <input type="hidden" name="kod" value="<?= e($kod ?? '') ?>">
    <div class="field"><label for="company">Nazwa firmy</label><input id="company" name="company" type="text" required autocomplete="organization" value="<?= e($v['company'] ?? '') ?>"></div>
    <div class="field"><label for="nip">NIP firmy</label><input id="nip" name="nip" type="text" inputmode="numeric" autocomplete="off" value="<?= e($v['nip'] ?? '') ?>"><span class="hint">Potrzebny do formularzy ofert. Możesz dodać później.</span></div>
    <div class="field"><label for="name">Imię i nazwisko</label><input id="name" name="name" type="text" autocomplete="name" value="<?= e($v['name'] ?? '') ?>"></div>
    <div class="field"><label for="email">E-mail służbowy</label><input id="email" name="email" type="email" required autocomplete="email" value="<?= e($v['email'] ?? '') ?>"></div>
    <div class="field"><label for="password">Hasło</label><input id="password" name="password" type="password" required minlength="10" autocomplete="new-password"><span class="hint">Co najmniej 10 znaków.</span></div>
    <label class="check"><input type="checkbox" name="terms" value="1" required>
      <span>
        <?php if ($terms !== '' || $privacy !== ''): ?>
          Akceptuję <?php if ($terms !== ''): ?><a href="<?= e(safe_url($terms)) ?>" target="_blank" rel="noopener">regulamin</a><?php else: ?>regulamin<?php endif ?>
          i <?php if ($privacy !== ''): ?><a href="<?= e(safe_url($privacy)) ?>" target="_blank" rel="noopener">politykę prywatności</a><?php else: ?>informację o przetwarzaniu danych<?php endif ?>.
        <?php else: ?>
          Akceptuję warunki korzystania z platformy i informację o przetwarzaniu danych przekazane wraz z zaproszeniem.
        <?php endif ?>
      </span>
    </label>
    <button class="btn" type="submit">Załóż konto</button>
  </form>
  <p class="muted small" style="margin:0">Masz już konto? <a href="/logowanie">Zaloguj się</a></p>
</div>
