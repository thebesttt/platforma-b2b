<?php
/**
 * @var array $p firma z listy razem z danymi nadawcy @var string $state form | done | sent | already | refused @var ?string $error
 * @var string $email @var array $in @var array{email: string, phone: string, report: string} $wording @var string $code
 * @var ?array $report raport z audytu strony tej firmy @var bool $reportOpen firma poprosiła o raport, więc go pokazujemy
 */
$v = static fn (string $k): string => e((string) ($in[$k] ?? ''));
[$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
$masked = $email !== '' ? mb_substr($local, 0, 1) . str_repeat('•', max(2, min(6, mb_strlen($local) - 1))) . '@' . $domain : '';
$sender = e($p['org_name']);
?>
<?php
// Raport jest osobną prośbą: firma może go dostać bez zgody na ofertę. Gdy już o niego poprosiła, formularz pyta tylko o ofertę.
$askReport = $report !== null && !$reportOpen;
$showForm = in_array($state, ['form', 'refused', 'sent'], true);
$checked = static fn (string $k): string => ($in[$k] ?? '') === '1' ? ' checked' : '';
?>
<section class="card stack-sm" aria-labelledby="t-consent">
  <?php if ($state === 'done'): ?>
    <h1 id="t-consent">Dziękujemy, zgoda zapisana</h1>
    <p>Firma <strong><?= $sender ?></strong> wyśle ofertę<?= $masked !== '' ? ' na adres ' . e($masked) : '' ?>.</p>
    <p class="muted small" style="margin:0">Zgodę można wycofać w każdej chwili: w każdej wiadomości jest link rezygnacji.</p>
  <?php elseif ($state === 'sent'): ?>
    <h1 id="t-consent">Raport jest gotowy</h1>
    <p>Link do raportu wysłaliśmy też<?= $masked !== '' ? ' na adres ' . e($masked) : ' e-mailem' ?>. Innych wiadomości nie wyślemy, bo nie zaznaczyli Państwo zgody na ofertę.</p>
  <?php elseif ($state === 'already'): ?>
    <h1 id="t-consent">Zgoda jest już zapisana</h1>
    <p>Firma <strong><?= $sender ?></strong> ma Państwa zgodę na ofertę<?= $masked !== '' ? ' pod adresem ' . e($masked) : '' ?>.</p>
    <p class="muted small" style="margin:0">Zgodę można wycofać linkiem w każdej wiadomości albo przyciskiem poniżej.</p>
  <?php elseif ($state === 'refused'): ?>
    <h1 id="t-consent">Zapisane: bez korespondencji</h1>
    <p>Firma <strong><?= $sender ?></strong> nie wyśle do Państwa kolejnych listów ani ofert.</p>
    <p class="muted small" style="margin:0">Jeśli zmienią Państwo zdanie, wystarczy wypełnić formularz poniżej.</p>
  <?php elseif ($askReport): ?>
    <h1 id="t-consent">Raport z audytu strony <?= e($report['host']) ?></h1>
    <p>Firma <strong><?= $sender ?></strong> sprawdziła Państwa stronę i wysłała list z wynikiem: <strong><?= (int) $report['score'] ?> na 100 punktów</strong>. Pełny raport z listą rzeczy do poprawy pokażemy od razu i wyślemy na podany adres. Jest bezpłatny i do niczego nie zobowiązuje.</p>
  <?php else: ?>
    <h1 id="t-consent">Zgoda na ofertę e-mailem</h1>
    <p>Firma <strong><?= $sender ?></strong> wysłała do Państwa list z propozycją współpracy. Jeśli chcą Państwo dostać ofertę e-mailem, prosimy wpisać adres i zaznaczyć zgodę. Bez zgody nie wyślemy żadnej wiadomości.</p>
  <?php endif ?>
  <?php if ($reportOpen && $report !== null && $report['url'] !== '' && $state !== 'refused'): ?>
    <div><a class="btn<?= $state === 'sent' ? '' : ' ghost sm' ?>" href="<?= e($report['url']) ?>">Otwórz raport z audytu strony</a></div>
  <?php endif ?>
  <?php if ($state === 'already'): ?>
    <form method="post" action="/z/<?= e($code) ?>">
      <button class="btn ghost sm" type="submit" name="action" value="refuse">Wycofuję zgodę i nie chcę korespondencji</button>
    </form>
  <?php endif ?>
  <?php if ($showForm): ?>
    <?php if ($state === 'sent'): ?><h2 style="margin-top:8px">Chcą Państwo dostać też ofertę?</h2><?php endif ?>
    <?php if ($error !== null): ?><div class="flash error" role="alert"><?= e($error) ?></div><?php endif ?>
    <form method="post" action="/z/<?= e($code) ?>" class="stack-sm">
      <p class="muted small" style="margin:0">Dla: <?= e($p['name']) ?></p>
      <div class="field"><label for="zg-email"><?= $askReport ? 'Adres e-mail, na który wysłać raport' : 'Adres e-mail, na który wysłać ofertę' ?></label><input id="zg-email" name="email" type="email" maxlength="200" required autocomplete="email" value="<?= $v('email') ?>"></div>
      <div class="field"><label for="zg-person">Imię i nazwisko, opcjonalnie</label><input id="zg-person" name="person" type="text" maxlength="120" autocomplete="name" value="<?= $v('person') ?>"></div>
      <?php if ($askReport): ?>
        <label class="check"><input type="checkbox" name="want_report" value="1" required<?= $checked('want_report') ?>> <span><?= e($wording['report']) ?></span></label>
        <label class="check"><input type="checkbox" name="agree" value="1"<?= $checked('agree') ?>> <span><?= e($wording['email']) ?> To pole nie jest wymagane.</span></label>
      <?php else: ?>
        <label class="check"><input type="checkbox" name="agree" value="1" required<?= $checked('agree') ?>> <span><?= e($wording['email']) ?></span></label>
      <?php endif ?>
      <div class="field"><label for="zg-phone">Telefon, opcjonalnie</label><input id="zg-phone" name="phone" type="tel" maxlength="40" autocomplete="tel" value="<?= $v('phone') ?>"></div>
      <label class="check"><input type="checkbox" name="agree_phone" value="1"<?= $checked('agree_phone') ?>> <span><?= e($wording['phone']) ?><?= $askReport ? ' Dotyczy tylko zgody na ofertę.' : '' ?></span></label>
      <div class="sr-only" aria-hidden="true"><label for="zg-www">Nie wypełniaj tego pola</label><input id="zg-www" name="strona_www" type="text" tabindex="-1" autocomplete="off"></div>
      <div><button class="btn<?= $state === 'sent' ? ' ghost' : '' ?>" type="submit" name="action" value="agree"><?= $askReport ? 'Pokaż i wyślij mi raport' : 'Zgadzam się i proszę o ofertę' ?></button></div>
    </form>
    <?php if ($state !== 'refused'): ?>
      <form method="post" action="/z/<?= e($code) ?>">
        <button class="btn ghost sm" type="submit" name="action" value="refuse">Nie chcemy korespondencji od tej firmy</button>
      </form>
    <?php endif ?>
    <p class="muted xs" style="margin:0">Administratorem danych jest <?= $sender ?><?= !empty($p['org_address']) ? ', ' . e($p['org_address']) : '' ?><?= !empty($p['org_nip']) ? ', NIP ' . e(App\Core\Nip::format((string) $p['org_nip'])) : '' ?>.
      Adres i telefon posłużą tylko do <?= $report !== null ? 'przesłania raportu, a po zaznaczeniu zgody także oferty i rozmowy o niej' : 'przesłania oferty i rozmowy o niej' ?>, na podstawie Państwa zgody (art. 6 ust. 1 lit. a RODO). Zgodę można wycofać w każdej chwili linkiem w wiadomości albo odpowiedzią „rezygnuję”. Wycofanie nie wpływa na to, co zrobiono przed nim.
      Przysługuje Państwu prawo dostępu do danych, ich sprostowania i usunięcia oraz skargi do Prezesa Urzędu Ochrony Danych Osobowych. Ta strona nie zapisuje plików cookie.</p>
  <?php endif ?>
</section>
