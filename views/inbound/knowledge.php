<?php
use App\Core\View;

$fmt = static fn (int $n): string => number_format($n, 0, ',', ' ');
?>
<header class="page-head">
  <p class="crumbs"><a href="/zapytania">Zapytania ze strony</a> / Wiedza czatu</p>
  <h1>Wiedza czatu</h1>
  <p class="muted small">Asystent w widżecie odpowiada tylko z tego, co tu widzisz. Czego tu nie ma, tego nie powie.</p>
</header>
<?= View::partial('partials/inbound_nav', ['on' => 'wiedza']) ?>

<?php if (!$aiEnabled): ?>
  <div class="notice mid">Czat nie działa, bo na serwerze nie ma klucza ANTHROPIC_API_KEY. Bazę wiedzy możesz przygotować już teraz.</div>
<?php elseif (!$chatOn): ?>
  <div class="notice info">Czat jest wyłączony w <a href="/zapytania/ustawienia">ustawieniach widżetu</a>.</div>
<?php endif ?>
<?php if ($total > $cap): ?>
  <div class="notice mid">Baza wiedzy ma <?= $fmt($total) ?> znaków, a asystent czyta pierwsze <?= $fmt($cap) ?>. Usuń mniej ważne strony albo skróć teksty, żeby nic nie wypadło.</div>
<?php endif ?>

<div class="cols">
  <div class="main stack">
    <section class="card flush" aria-labelledby="k-s">
      <div class="card-head"><h2 id="k-s">Strony Twojej firmy</h2></div>
      <?php if ($pages): ?>
        <div class="table-wrap"><table>
          <thead><tr><th scope="col">Strona</th><th scope="col" class="n">Znaków</th><th scope="col">Pobrano</th><th scope="col"></th></tr></thead>
          <tbody>
          <?php foreach ($pages as $p): ?>
            <tr>
              <td><span class="t-title"><?= e($p['title'] ?: $p['url']) ?></span><div class="t-sub"><?= e($p['url']) ?></div></td>
              <td class="n"><?= $fmt((int) $p['chars']) ?></td>
              <td class="small muted nowrap"><?= e(local_dt($p['fetched_at'], 'd.m.Y')) ?></td>
              <td class="n"><form method="post" action="/zapytania/wiedza/<?= (int) $p['id'] ?>/usun"><?= csrf_field() ?><button class="btn ghost sm" type="submit">Usuń</button></form></td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table></div>
      <?php else: ?>
        <div class="empty"><h3>Nie dodano jeszcze żadnej strony</h3><p>Dodaj podstrony z ofertą, cennikiem i odpowiedziami na częste pytania. Zapiszemy ich tekst, a asystent będzie z niego korzystał.</p></div>
      <?php endif ?>
      <form method="post" action="/zapytania/wiedza" class="card-body stack-sm" style="padding-top:12px">
        <?= csrf_field() ?>
        <div class="field"><label for="k-url">Adres podstrony</label>
          <div class="searchbar"><input id="k-url" name="url" type="text" inputmode="url" placeholder="twojafirma.pl/oferta" required aria-describedby="k-url-h"><button class="btn" type="submit" data-busy="Pobieram…">Dodaj stronę</button></div>
          <span class="hint" id="k-url-h">Pobieramy tekst jednej podstrony, bez menu i stopki. Żeby odświeżyć treść po zmianach na stronie, dodaj ten sam adres jeszcze raz. Najwyżej 30 stron.</span></div>
      </form>
    </section>

    <section class="card" aria-labelledby="k-t">
      <h2 id="k-t" style="margin-bottom:8px">Teksty z biblioteki firmy</h2>
      <?php if ($texts): ?>
        <?php foreach ($texts as $t): ?>
          <div class="kv-row"><div><p class="v"><a href="/biblioteka/<?= (int) $t['id'] ?>"><?= e($t['title']) ?></a></p></div><span class="small muted nowrap"><?= $fmt((int) $t['chars']) ?> znaków</span></div>
        <?php endforeach ?>
      <?php else: ?>
        <p class="small muted" style="margin:0">W bibliotece nie ma jeszcze tekstów. Opis firmy i oferty dodasz w <a href="/biblioteka?rodzaj=text">bibliotece firmy</a>.</p>
      <?php endif ?>
      <p class="small muted" style="margin:12px 0 0">Asystent czyta wpisy rodzaju „tekst”. Referencje, osoby i certyfikaty zostają tylko w ofertach.</p>
    </section>
  </div>

  <div class="side stack">
    <section class="card" aria-labelledby="k-z">
      <h2 id="k-z" style="margin-bottom:8px">Zasady asystenta</h2>
      <ul class="list-plain small">
        <li>Odpowiada wyłącznie z tej bazy wiedzy, najwyżej w 4 zdaniach.</li>
        <li>Gdy odpowiedzi tu nie ma, mówi to wprost i proponuje zostawienie kontaktu.</li>
        <li>Nie podaje cen, terminów ani obietnic, których nie ma w bazie.</li>
        <li>Nie zmienia oceny zapytania. Punkty liczy program.</li>
        <li>Poleceń wpisanych przez odwiedzającego nie wykonuje, traktuje je jak zwykły tekst.</li>
      </ul>
    </section>
    <section class="card" aria-labelledby="k-u">
      <h2 id="k-u" style="margin-bottom:8px">Rozmiar i zużycie</h2>
      <div class="kv-row"><div><p class="k">Baza wiedzy</p><p class="v"><?= $fmt($total) ?> znaków, asystent czyta do <?= $fmt($cap) ?></p></div></div>
      <div class="kv-row"><div><p class="k">Odpowiedzi czatu w tym miesiącu</p><p class="v"><?= $aiUsed ?> z <?= (int) $limits['ai_month'] ?></p></div></div>
    </section>
  </div>
</div>
