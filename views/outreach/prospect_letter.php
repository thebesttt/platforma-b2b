<?php
/** @var array $p @var ?array $company @var array{title: string, body: string} $letter @var bool $person */
$id = (int) $p['id'];
?>
<header class="between page-head">
  <div>
    <p class="crumbs"><a href="/klienci/potencjalni">Potencjalni klienci</a> / <a href="/klienci/potencjalni/<?= $id ?>"><?= e(truncate((string) $p['name'], 50)) ?></a> / List</p>
    <h1>List z ofertą</h1>
    <p class="muted small">List papierowy nie podlega wymogowi zgody na kontakt elektroniczny. Popraw treść, pobierz plik, wydrukuj i wyślij pocztą.</p>
  </div>
  <a class="btn ghost" href="/klienci/potencjalni/<?= $id ?>">Wróć do karty firmy</a>
</header>

<div class="cols">
  <section class="card main" aria-labelledby="t-letter">
    <h2 id="t-letter">Treść listu</h2>
    <p class="small muted" style="margin:4px 0 12px">Treść powstała z faktów z karty firmy i z pierwszego opisu w Twojej bibliotece. Miejsca w nawiasach kwadratowych uzupełnij sam.</p>
    <form method="post" action="/klienci/potencjalni/<?= $id ?>/list">
      <?= csrf_field() ?>
      <label class="sr-only" for="pl-body">Treść listu</label>
      <textarea id="pl-body" name="body" rows="24" maxlength="12000" required style="font-family:inherit;line-height:1.6"><?= e($letter['body']) ?></textarea>
      <div class="row-flex" style="margin-top:14px">
        <button class="btn" type="submit" name="action" value="docx">Pobierz .docx</button>
        <button class="btn ghost" type="submit" name="action" value="sent">Oznacz: list wysłany</button>
      </div>
    </form>
  </section>
  <div class="side stack">
    <section class="card" aria-labelledby="t-rules">
      <h2 id="t-rules">Zanim wyślesz</h2>
      <ul class="law" style="margin-top:10px">
        <li>List jest propozycją rozmowy. Poproś w nim o adres e-mail do szczegółów: odpowiedź z adresem jest zgodą na wiadomość.</li>
        <?php if ($person): ?><li>Adresat to osoba prowadząca działalność. Zostaw w liście informację o danych osobowych: to Twój obowiązek z art. 14 RODO.</li><?php endif ?>
        <li>Zostaw ostatnie zdanie o rezygnacji. Gdy firma napisze, że nie chce korespondencji, zapisz sprzeciw na jej karcie.</li>
        <li>Po wysłaniu kliknij „Oznacz: list wysłany”. Firma przejdzie do zakładki „Po kontakcie”.</li>
      </ul>
      <p class="xs muted" style="margin:12px 0 0">To informacja o przepisach, a nie porada prawna.</p>
    </section>
  </div>
</div>
