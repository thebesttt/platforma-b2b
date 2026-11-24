<header class="page-head">
  <p class="crumbs">Audyt i reklamy</p>
  <h1>Widoczność w Google i w AI</h1>
  <p class="muted small">Dane z Search Console, sygnały z odpowiedzi AI i to, co publikuje konkurencja. Zaczynasz od adresu witryny.</p>
</header>
<?= App\Core\View::partial('partials/audit_nav', ['on' => 'widocznosc']) ?>
<section class="card" aria-labelledby="sv-add">
  <h2 id="sv-add" style="margin-bottom:12px"><?= $sites ? 'Dodaj kolejną witrynę' : 'Dodaj witrynę' ?></h2>
  <form method="post" action="/audyt/widocznosc">
    <?= csrf_field() ?>
    <div class="searchbar">
      <label class="sr-only" for="sv-host">Adres witryny</label>
      <input id="sv-host" name="host" type="text" inputmode="url" required maxlength="200" placeholder="twojafirma.pl" value="<?= e($sites ? '' : $own) ?>">
      <button class="btn" type="submit">Dodaj</button>
    </div>
    <p class="small muted" style="margin:8px 0 0">Wystarczy sam adres. Dane podłączysz w następnym kroku: plikiem z Search Console albo jednym kliknięciem.</p>
  </form>
</section>
<?php if ($sites): ?>
<section class="card flush" aria-labelledby="sv-list">
  <div class="card-head"><h2 id="sv-list">Twoje witryny</h2></div>
  <div class="table-wrap"><table>
    <thead><tr><th scope="col">Witryna</th><th scope="col">Konkurenci</th><th scope="col">Dodana</th></tr></thead>
    <tbody>
    <?php foreach ($sites as $s): $rv = App\Modules\Seo\Sites::rivals($s); ?>
      <tr>
        <td><a class="t-title" href="/audyt/widocznosc/<?= (int) $s['id'] ?>"><?= e($s['host']) ?></a></td>
        <td class="small"><?= $rv ? e(implode(', ', $rv)) : '<span class="muted">brak</span>' ?></td>
        <td class="small muted nowrap"><?= e(local_dt((string) $s['created_at'], 'd.m.Y')) ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table></div>
</section>
<?php else: ?>
<section class="card" aria-labelledby="sv-what">
  <h2 id="sv-what" style="margin-bottom:10px">Co zobaczysz</h2>
  <ul class="law">
    <li><b>Google:</b> zapytania tuż za czołówką wyników, tytuły, które nie zachęcają do kliknięcia, spadki i dwie strony walczące o jedno zapytanie.</li>
    <li><b>Odpowiedzi AI:</b> strony pokazywane w odpowiedziach AI Google i w Copilocie, wejścia z ChatGPT i innych asystentów oraz boty AI na stronie.</li>
    <li><b>Konkurencja:</b> co konkurenci ostatnio opublikowali i jakich tematów brakuje u Ciebie.</li>
  </ul>
</section>
<?php endif ?>
