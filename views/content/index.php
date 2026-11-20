<?php
use App\Core\View;
use App\Modules\Content\Ruleset;

$types = Ruleset::pageTypes();
$byHost = [];
foreach ($targets as $t) {
    $byHost[$t['host']][] = $t;
}
$missing = array_keys(array_filter(['D1 i D2 (klucz ANTHROPIC_API_KEY)' => !$services['ai'], 'A6 i E2 (usługa Cloudflare Browser Run)' => !$services['render']]));
?>
<header class="page-head">
  <p class="crumbs">Audyt i reklamy</p>
  <h1>Audyt AI: treść pod wyszukiwarki i odpowiedzi AI</h1>
  <p class="muted small">24 wskaźniki, 100 punktów. Każdy wskaźnik ma jawny próg i podstawę w dokumentacji Google, Microsoftu albo dostawców botów AI. Wynik nie obiecuje pozycji ani cytowań.</p>
</header>
<?= View::partial('partials/audit_nav', ['on' => 'ai']) ?>

<section class="card" aria-labelledby="ca-add">
  <h2 id="ca-add" style="margin-bottom:12px">Sprawdź stronę</h2>
  <form method="post" action="/audyt/tresc" class="form-grid">
    <?= csrf_field() ?>
    <div class="field span-2"><label for="url">Adres strony</label><input id="url" name="url" type="text" inputmode="url" required placeholder="twojafirma.pl/poradnik/jak-wybrac"></div>
    <div class="field span-2"><label for="phrase">Fraza, na którą strona ma odpowiadać</label><input id="phrase" name="phrase" type="text" required maxlength="120" placeholder="np. jaki ekspres ciśnieniowy do domu" aria-describedby="phrase-h"><span class="hint" id="phrase-h">Jedna strona, jedna fraza. Z nią porównujemy tytuł, H1 i początek treści.</span></div>
    <div class="field"><label for="page_type">Rodzaj strony</label><select id="page_type" name="page_type"><?php foreach ($types as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach ?></select></div>
    <div class="field"><label for="contact_id">Klient (opcjonalnie)</label><select id="contact_id" name="contact_id"><option value="">Bez przypisania</option><?php foreach ($contacts as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $contactId === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['company_name']) ?></option><?php endforeach ?></select></div>
    <div class="field"><label for="label">Etykieta audytu (opcjonalnie)</label><input id="label" name="label" type="text" maxlength="60" placeholder="np. przed pracami"></div>
    <div class="field" style="justify-content:flex-end"><button class="btn" type="submit">Dodaj i sprawdź</button></div>
  </form>
  <p class="small" style="margin:12px 0 0">Masz wiele podstron? <a href="/audyt/tresc/mapa">Dodaj strony z mapy witryny</a>.</p>
  <?php if ($missing): ?><p class="small muted" style="margin:12px 0 0">Na tym serwerze nie zmierzymy: <?= e(implode('; ', $missing)) ?>. Te wskaźniki dostaną stan „brak pomiaru” i nie odbiorą punktów.</p><?php endif ?>
</section>

<?php $queue = array_values(array_map('intval', array_filter(array_column($targets, 'open_audit')))); if ($queue): ?>
  <div class="notice info" role="status" data-audit-queue="<?= e(json_encode($queue)) ?>" data-csrf="<?= e(App\Core\Csrf::token()) ?>">
    <b>W kolejce: <?= count($queue) ?> <?= plural(count($queue), 'audyt', 'audyty', 'audytów') ?>.</b> <span data-audit-queue-state>Audyty wykonują się po kolei, gdy ta karta jest otwarta. Niedokończone dokończy nocne zadanie.</span>
  </div>
<?php endif ?>

<section class="card flush" aria-labelledby="ca-list">
  <div class="card-head"><h2 id="ca-list">Strony witryny</h2><?php if ($contactId): ?><a class="small" href="/audyt/tresc">Pokaż strony wszystkich klientów</a><?php endif ?></div>
  <?php if ($targets): ?>
    <div class="table-wrap"><table>
      <thead><tr><th scope="col">Wynik</th><th scope="col">Strona i fraza</th><th scope="col">Rodzaj</th><th scope="col">Audyty</th><th scope="col">Ostatni</th></tr></thead>
      <tbody>
      <?php foreach ($byHost as $host => $rows): ?>
        <tr><th scope="rowgroup" colspan="5" class="ca-host"><?= e($host) ?></th></tr>
        <?php foreach ($rows as $t): $lv = $t['score'] !== null ? Ruleset::level((int) $t['score']) : null; ?>
          <tr>
            <td class="nowrap"><?php if ($t['open_audit']): ?><span class="chip info">w toku</span><?php elseif ($lv): ?><span class="score <?= ['good' => 'low', 'mid' => 'mid', 'bad' => 'high'][$lv] ?>"><?= (int) $t['score'] ?></span><?php if ((int) $t['blocked']): ?> <span class="chip high">warunek konieczny</span><?php endif ?><?php else: ?><span class="chip neutral">brak</span><?php endif ?></td>
            <td style="min-width:240px"><a class="t-title" href="/audyt/tresc/<?= (int) $t['id'] ?>"><?= e(truncate((string) (parse_url((string) $t['url'], PHP_URL_PATH) ?: '/'), 70)) ?></a><div class="t-sub">„<?= e($t['phrase']) ?>”</div></td>
            <td class="small"><?= e($types[$t['page_type']] ?? $t['page_type']) ?></td>
            <td class="small"><?= (int) $t['audits'] ?><?php if ((int) $t['audits'] > 1): ?> · <a href="/audyt/tresc/<?= (int) $t['id'] ?>/porownanie">przed i po</a><?php endif ?></td>
            <td class="small muted nowrap"><?= $t['finished_at'] ? e(local_dt($t['finished_at'], 'd.m.Y')) : '-' ?></td>
          </tr>
        <?php endforeach ?>
      <?php endforeach ?>
      </tbody>
    </table></div>
  <?php else: ?>
    <div class="empty"><h3>Nie sprawdzono jeszcze żadnej strony</h3><p>Podaj adres i frazę powyżej. Audyt trwa około minuty i od razu pokazuje, co poprawić w pierwszej kolejności.</p></div>
  <?php endif ?>
</section>
