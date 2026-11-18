<div class="card stack-sm">
  <h1>Załóż konto firmy</h1>
  <p class="muted small" style="margin:0">14 dni bez opłat. Po rejestracji ustawisz profil przetargowy i dodasz kontrahentów do obserwowania.</p>
  <?php if (!empty($error)): ?><div class="flash error" role="alert"><?= e($error) ?></div><?php endif ?>
  <form method="post" action="/rejestracja" class="stack-sm">
    <?= csrf_field() ?>
    <div class="field"><label for="company">Nazwa firmy</label><input id="company" name="company" type="text" required autocomplete="organization" value="<?= e($v['company'] ?? '') ?>"></div>
    <div class="field"><label for="nip">NIP firmy</label><input id="nip" name="nip" type="text" inputmode="numeric" autocomplete="off" value="<?= e($v['nip'] ?? '') ?>"><span class="hint">Potrzebny do formularzy ofert. Możesz dodać później.</span></div>
    <div class="field"><label for="name">Imię i nazwisko</label><input id="name" name="name" type="text" autocomplete="name" value="<?= e($v['name'] ?? '') ?>"></div>
    <div class="field"><label for="email">E-mail służbowy</label><input id="email" name="email" type="email" required autocomplete="email" value="<?= e($v['email'] ?? '') ?>"></div>
    <div class="field"><label for="password">Hasło</label><input id="password" name="password" type="password" required minlength="10" autocomplete="new-password"><span class="hint">Co najmniej 10 znaków.</span></div>
    <label class="check"><input type="checkbox" name="terms" value="1" required> <span>Akceptuję regulamin i informację o przetwarzaniu danych.</span></label>
    <button class="btn" type="submit">Załóż konto</button>
  </form>
  <p class="muted small" style="margin:0">Masz już konto? <a href="/logowanie">Zaloguj się</a></p>
</div>
