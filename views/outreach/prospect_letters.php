<?php
use App\Modules\Outreach\Prospects;
/** @var array<int, array> $rows firmy do listów seryjnych: gotowe i te, którym czegoś brakuje @var bool $second tryb drugiego listu */
$ready = array_filter($rows, static fn ($r) => $r['ready']);
$blocked = array_filter($rows, static fn ($r) => !$r['ready']);
$states = Prospects::STATUSES;
?>
<header class="between page-head">
  <div>
    <p class="crumbs"><a href="/klienci/potencjalni">Potencjalni klienci</a> / Listy seryjnie</p>
    <h1>Listy seryjnie</h1>
    <p class="muted small">Jeden plik do druku dla wielu firm. Każdy list ma treść oferty z karty firmy, adres jej strony zgody i własny kod QR. List papierowy nie wymaga zgody na kontakt elektroniczny.</p>
  </div>
</header>

<?php $dueCount = count(array_filter($rows, static fn ($r) => $r['ready'] && $r['due'])); ?>
<?php if ($second): ?>
  <div class="notice info" style="margin-bottom:16px">Zaznaczone są firmy, które nie odpowiedziały na pierwszy list: <?= (int) $dueCount ?>. Dostaną krótkie przypomnienie z tym samym adresem strony zgody. <a href="/klienci/potencjalni/listy">Wróć do pierwszych listów</a>.</div>
<?php elseif ($dueCount > 0): ?>
  <div class="notice info" style="margin-bottom:16px"><?= (int) $dueCount ?> <?= plural($dueCount, 'firma nie odpowiedziała', 'firmy nie odpowiedziały', 'firm nie odpowiedziało') ?> na list sprzed <?= Prospects::FOLLOWUP_DAYS ?> dni. <a href="/klienci/potencjalni/listy?drugi=1">Zaznacz je do drugiego listu</a>.</div>
<?php endif ?>
<div class="cols">
  <section class="card flush main" aria-labelledby="t-ready">
    <div class="card-head"><h2 id="t-ready">Firmy gotowe do listu (<?= count($ready) ?>)</h2></div>
    <?php if ($ready): ?>
      <form method="post" action="/klienci/potencjalni/listy<?= $second ? '?drugi=1' : '' ?>" id="bulk-letters">
        <?= csrf_field() ?>
        <div class="table-wrap">
          <table>
            <thead><tr><th scope="col"><span class="sr-only">Zaznacz</span></th><th scope="col">Firma i adres</th><th scope="col">Stan</th><th scope="col" class="n">Dopasowanie</th><th scope="col">Raport</th><th scope="col">Listy</th></tr></thead>
            <tbody>
              <?php foreach ($ready as $id => $row): $p = $row['p']; ?>
                <tr>
                  <td><input type="checkbox" name="ids[]" value="<?= (int) $id ?>" id="bl-<?= (int) $id ?>"<?= $row['checked'] ? ' checked' : '' ?>></td>
                  <td><label for="bl-<?= (int) $id ?>" style="font-weight:600"><?= e(truncate($row['name'], 80)) ?></label><div class="small muted"><?= e($row['address']) ?></div></td>
                  <td class="small nowrap"><?= e($states[$p['status']] ?? $p['status']) ?></td>
                  <td class="n"><span class="score <?= score_class((int) $p['score']) ?>"><?= (int) $p['score'] ?></span></td>
                  <td class="small nowrap"><?= !empty($p['audit_id']) ? 'jest' : '<span class="muted">brak</span>' ?></td>
                  <td class="small nowrap"><?= $row['letters'] > 0 ? (int) $row['letters'] . ', ostatni ' . e(local_date($row['last'])) : '<span class="muted">jeszcze nie</span>' ?><?= $row['due'] ? '<br><span class="chip mid">pora na drugi list</span>' : ($row['letters'] > 0 ? '<br><span class="muted">dostanie przypomnienie</span>' : '') ?></td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
        <div class="card-foot row-flex" style="padding:14px 18px">
          <button class="btn" type="submit" name="action" value="docx">Pobierz listy (.docx)</button>
          <button class="btn ghost" type="submit" name="action" value="addresses">Pobierz adresy na koperty (.csv)</button>
          <button class="btn ghost" type="submit" name="action" value="sent" data-confirm="Oznaczyć zaznaczone listy jako wysłane? Firmy przejdą do zakładki „Po kontakcie”.">Oznacz: listy wysłane</button>
        </div>
      </form>
    <?php else: ?>
      <div class="empty"><h3>Brak firm gotowych do listu</h3><p>Do listu potrzebny jest adres z rejestrów. Dodaj firmy z NIP-em na liście potencjalnych klientów i wybierz te, do których chcesz napisać.</p></div>
    <?php endif ?>
  </section>

  <div class="side stack">
    <section class="card" aria-labelledby="t-how">
      <h2 id="t-how">Jak to wysłać</h2>
      <ol class="law" style="margin-top:10px">
        <li>Zaznacz firmy. Z góry zaznaczone są wybrane, do których list jeszcze nie wyszedł. W jednym pliku mieści się <?= Prospects::LETTERS_LIMIT ?> listów.</li>
        <li>Pobierz listy i wydrukuj plik. Każdy list zaczyna się od nowej strony.</li>
        <li>Pobierz adresy. Plik otworzysz w arkuszu i użyjesz do etykiet albo korespondencji seryjnej.</li>
        <li>Po nadaniu kliknij „Oznacz: listy wysłane”. Od tej chwili lejek liczy wejścia na strony zgody.</li>
        <li>Po <?= Prospects::FOLLOWUP_DAYS ?> dniach bez odpowiedzi panel przypomni o drugim, krótkim liście. Trzeciego nie proponuje.</li>
      </ol>
      <p class="small muted" style="margin:12px 0 0">Treść bierze się z karty każdej firmy. Chcesz napisać do jednej firmy inaczej? Popraw treść oferty na jej karcie przed pobraniem pliku.</p>
      <p class="small muted" style="margin:8px 0 0">Firmy z audytem strony dostają w liście obietnicę raportu. List do osoby prowadzącej działalność ma informację o danych osobowych.</p>
      <p class="xs muted" style="margin:12px 0 0">To informacja o przepisach, a nie porada prawna.</p>
    </section>
    <?php if ($blocked): ?>
    <section class="card" aria-labelledby="t-blocked">
      <h2 id="t-blocked">Poza listami (<?= count($blocked) ?>)</h2>
      <?php foreach (array_slice($blocked, 0, 30, true) as $id => $row): ?>
        <div class="kv-row"><div><p class="v" style="font-weight:600"><a href="/klienci/potencjalni/<?= (int) $id ?>"><?= e(truncate($row['name'], 60)) ?></a></p><p class="k" style="margin:2px 0 0"><?= e($row['why']) ?></p></div></div>
      <?php endforeach ?>
    </section>
    <?php endif ?>
  </div>
</div>
