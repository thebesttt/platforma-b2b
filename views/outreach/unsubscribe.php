<?php
/** @var array $c kontakt z nazwą firmy nadawcy @var bool $done @var string $token */
// Adres pokazujemy częściowo: link mógł trafić do kogoś innego niż adresat.
[$local, $domain] = array_pad(explode('@', (string) $c['email'], 2), 2, '');
$masked = mb_substr($local, 0, 1) . str_repeat('•', max(2, min(6, mb_strlen($local) - 1))) . '@' . $domain;
?>
<section class="card stack-sm" aria-labelledby="t-unsub">
  <?php if ($done): ?>
    <h1 id="t-unsub">Adres wypisany</h1>
    <p>Firma <strong><?= e($c['org_name']) ?></strong> nie będzie już wysyłać ofert ani próśb o zgodę na adres <?= e($masked) ?>.</p>
    <p class="muted small" style="margin:0">Jeśli sami wyślą Państwo do tej firmy zapytanie, odpowiedź nadal może przyjść.</p>
  <?php else: ?>
    <h1 id="t-unsub">Rezygnacja z wiadomości</h1>
    <p>Czy wypisać adres <strong><?= e($masked) ?></strong> z wiadomości od firmy <strong><?= e($c['org_name']) ?></strong>?</p>
    <form method="post" action="/wypisz/<?= e($token) ?>">
      <button class="btn" type="submit">Tak, wypisz ten adres</button>
    </form>
    <p class="muted small" style="margin:0">Po wypisaniu firma nie przygotuje już dla tego adresu ofert ani próśb o zgodę.</p>
  <?php endif ?>
</section>
