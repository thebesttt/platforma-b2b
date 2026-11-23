<?php
/**
 * Ogólna strona zgody firmy: adres z wizytówki, ulotki albo kodu QR na stoisku.
 * @var array $org nadawca @var string $state form | done @var ?string $error @var string $email @var array $in
 * @var array{email: string, phone: string, report: string} $wording @var string $code
 */
$v = static fn (string $k): string => e((string) ($in[$k] ?? ''));
$checked = static fn (string $k): string => ($in[$k] ?? '') === '1' ? ' checked' : '';
[$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
$masked = $email !== '' ? mb_substr($local, 0, 1) . str_repeat('•', max(2, min(6, mb_strlen($local) - 1))) . '@' . $domain : '';
$sender = e($org['name']);
?>
<section class="card stack-sm" aria-labelledby="t-consent">
  <?php if ($state === 'done'): ?>
    <h1 id="t-consent">Dziękujemy, zgoda zapisana</h1>
    <p>Firma <strong><?= $sender ?></strong> wyśle ofertę<?= $masked !== '' ? ' na adres ' . e($masked) : '' ?>.</p>
    <p class="muted small" style="margin:0">Zgodę można wycofać w każdej chwili: w każdej wiadomości jest link rezygnacji.</p>
  <?php else: ?>
    <h1 id="t-consent">Oferta od firmy <?= $sender ?></h1>
    <p>Jeśli chcą Państwo dostać ofertę e-mailem, prosimy wpisać dane i zaznaczyć zgodę. Bez zgody nie wyślemy żadnej wiadomości.</p>
    <?php if ($error !== null): ?><div class="flash error" role="alert"><?= e($error) ?></div><?php endif ?>
    <form method="post" action="/z/<?= e($code) ?>" class="stack-sm">
      <div class="field"><label for="zo-company">Nazwa firmy</label><input id="zo-company" name="company" type="text" maxlength="200" required autocomplete="organization" value="<?= $v('company') ?>"></div>
      <div class="field"><label for="zo-nip">NIP, opcjonalnie</label><input id="zo-nip" name="nip" type="text" inputmode="numeric" maxlength="13" autocomplete="off" value="<?= $v('nip') ?>"></div>
      <div class="field"><label for="zo-email">Adres e-mail, na który wysłać ofertę</label><input id="zo-email" name="email" type="email" maxlength="200" required autocomplete="email" value="<?= $v('email') ?>"></div>
      <div class="field"><label for="zo-person">Imię i nazwisko, opcjonalnie</label><input id="zo-person" name="person" type="text" maxlength="120" autocomplete="name" value="<?= $v('person') ?>"></div>
      <label class="check"><input type="checkbox" name="agree" value="1" required<?= $checked('agree') ?>> <span><?= e($wording['email']) ?></span></label>
      <div class="field"><label for="zo-phone">Telefon, opcjonalnie</label><input id="zo-phone" name="phone" type="tel" maxlength="40" autocomplete="tel" value="<?= $v('phone') ?>"></div>
      <label class="check"><input type="checkbox" name="agree_phone" value="1"<?= $checked('agree_phone') ?>> <span><?= e($wording['phone']) ?></span></label>
      <div class="sr-only" aria-hidden="true"><label for="zo-www">Nie wypełniaj tego pola</label><input id="zo-www" name="strona_www" type="text" tabindex="-1" autocomplete="off"></div>
      <div><button class="btn" type="submit">Zgadzam się i proszę o ofertę</button></div>
    </form>
    <p class="muted xs" style="margin:0">Administratorem danych jest <?= $sender ?><?= !empty($org['address']) ? ', ' . e($org['address']) : '' ?><?= !empty($org['nip']) ? ', NIP ' . e(App\Core\Nip::format((string) $org['nip'])) : '' ?>.
      Dane posłużą tylko do przesłania oferty i rozmowy o niej, na podstawie Państwa zgody (art. 6 ust. 1 lit. a RODO). Zgodę można wycofać w każdej chwili linkiem w wiadomości albo odpowiedzią „rezygnuję”. Wycofanie nie wpływa na to, co zrobiono przed nim.
      Przysługuje Państwu prawo dostępu do danych, ich sprostowania i usunięcia oraz skargi do Prezesa Urzędu Ochrony Danych Osobowych. Ta strona nie zapisuje plików cookie.</p>
  <?php endif ?>
</section>
