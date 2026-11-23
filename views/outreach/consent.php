<?php
/**
 * @var array $p firma z listy razem z danymi nadawcy @var string $state form | done | already | refused @var ?string $error
 * @var string $email @var array $in @var array{email: string, phone: string} $wording @var string $code
 */
$v = static fn (string $k): string => e((string) ($in[$k] ?? ''));
[$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
$masked = $email !== '' ? mb_substr($local, 0, 1) . str_repeat('•', max(2, min(6, mb_strlen($local) - 1))) . '@' . $domain : '';
$sender = e($p['org_name']);
?>
<section class="card stack-sm" aria-labelledby="t-consent">
  <?php if ($state === 'done'): ?>
    <h1 id="t-consent">Dziękujemy, zgoda zapisana</h1>
    <p>Firma <strong><?= $sender ?></strong> wyśle ofertę<?= $masked !== '' ? ' na adres ' . e($masked) : '' ?>.</p>
    <p class="muted small" style="margin:0">Zgodę można wycofać w każdej chwili: w każdej wiadomości jest link rezygnacji.</p>
  <?php elseif ($state === 'already'): ?>
    <h1 id="t-consent">Zgoda jest już zapisana</h1>
    <p>Firma <strong><?= $sender ?></strong> ma Państwa zgodę na ofertę<?= $masked !== '' ? ' pod adresem ' . e($masked) : '' ?>.</p>
    <p class="muted small" style="margin:0">Zgodę można wycofać linkiem w każdej wiadomości albo przyciskiem poniżej.</p>
    <form method="post" action="/z/<?= e($code) ?>">
      <button class="btn ghost sm" type="submit" name="action" value="refuse">Wycofuję zgodę i nie chcę korespondencji</button>
    </form>
  <?php elseif ($state === 'refused'): ?>
    <h1 id="t-consent">Zapisane: bez korespondencji</h1>
    <p>Firma <strong><?= $sender ?></strong> nie wyśle do Państwa kolejnych listów ani ofert.</p>
    <p class="muted small" style="margin:0">Jeśli zmienią Państwo zdanie, wystarczy wypełnić formularz zgody poniżej.</p>
  <?php else: ?>
    <h1 id="t-consent">Zgoda na ofertę e-mailem</h1>
    <p>Firma <strong><?= $sender ?></strong> wysłała do Państwa list z propozycją współpracy. Jeśli chcą Państwo dostać ofertę e-mailem, prosimy wpisać adres i zaznaczyć zgodę. Bez zgody nie wyślemy żadnej wiadomości.</p>
  <?php endif ?>
  <?php if ($state === 'form' || $state === 'refused'): ?>
    <?php if ($error !== null): ?><div class="flash error" role="alert"><?= e($error) ?></div><?php endif ?>
    <form method="post" action="/z/<?= e($code) ?>" class="stack-sm">
      <p class="muted small" style="margin:0">Dla: <?= e($p['name']) ?></p>
      <div class="field"><label for="zg-email">Adres e-mail, na który wysłać ofertę</label><input id="zg-email" name="email" type="email" maxlength="200" required autocomplete="email" value="<?= $v('email') ?>"></div>
      <div class="field"><label for="zg-person">Imię i nazwisko, opcjonalnie</label><input id="zg-person" name="person" type="text" maxlength="120" autocomplete="name" value="<?= $v('person') ?>"></div>
      <label class="check"><input type="checkbox" name="agree" value="1" required<?= ($in['agree'] ?? '') === '1' ? ' checked' : '' ?>> <span><?= e($wording['email']) ?></span></label>
      <div class="field"><label for="zg-phone">Telefon, opcjonalnie</label><input id="zg-phone" name="phone" type="tel" maxlength="40" autocomplete="tel" value="<?= $v('phone') ?>"></div>
      <label class="check"><input type="checkbox" name="agree_phone" value="1"<?= ($in['agree_phone'] ?? '') === '1' ? ' checked' : '' ?>> <span><?= e($wording['phone']) ?></span></label>
      <div class="sr-only" aria-hidden="true"><label for="zg-www">Nie wypełniaj tego pola</label><input id="zg-www" name="strona_www" type="text" tabindex="-1" autocomplete="off"></div>
      <div><button class="btn" type="submit" name="action" value="agree">Zgadzam się i proszę o ofertę</button></div>
    </form>
    <?php if ($state === 'form'): ?>
      <form method="post" action="/z/<?= e($code) ?>">
        <button class="btn ghost sm" type="submit" name="action" value="refuse">Nie chcemy korespondencji od tej firmy</button>
      </form>
    <?php endif ?>
    <p class="muted xs" style="margin:0">Administratorem danych jest <?= $sender ?><?= !empty($p['org_address']) ? ', ' . e($p['org_address']) : '' ?><?= !empty($p['org_nip']) ? ', NIP ' . e(App\Core\Nip::format((string) $p['org_nip'])) : '' ?>.
      Adres i telefon posłużą tylko do przesłania oferty i rozmowy o niej, na podstawie Państwa zgody (art. 6 ust. 1 lit. a RODO). Zgodę można wycofać w każdej chwili linkiem w wiadomości albo odpowiedzią „rezygnuję”. Wycofanie nie wpływa na to, co zrobiono przed nim.
      Przysługuje Państwu prawo dostępu do danych, ich sprostowania i usunięcia oraz skargi do Prezesa Urzędu Ochrony Danych Osobowych. Ta strona nie zapisuje plików cookie.</p>
  <?php endif ?>
</section>
