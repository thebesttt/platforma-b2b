<?php
use App\Core\View;
use App\Modules\Content\Measure;
use App\Modules\Content\Ruleset;

$types = Ruleset::pageTypes();
$locked = !empty($target['questions_locked_at']);
$path = (string) (parse_url((string) $target['url'], PHP_URL_PATH) ?: '/');
?>
<header class="between page-head">
  <div>
    <p class="crumbs"><a href="/audyt/tresc">Audyt AI</a> / <?= e($target['host']) ?></p>
    <h1><?= e(truncate((string) ($audit['title'] ?? $target['host'] . $path), 90)) ?></h1>
    <p class="small muted" style="margin:6px 0 0"><a href="<?= e(safe_url($target['url'])) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= e(truncate((string) $target['url'], 90)) ?></a> · fraza „<?= e($target['phrase']) ?>” · <?= e(mb_strtolower($types[$target['page_type']] ?? '')) ?><?php if ($contact): ?> · klient <a href="/klienci/<?= (int) $contact['id'] ?>"><?= e($contact['company_name']) ?></a><?php endif ?></p>
  </div>
  <?php if (!$open): ?>
    <form method="post" action="/audyt/tresc/<?= (int) $target['id'] ?>/audyt" class="row-flex">
      <?= csrf_field() ?>
      <label class="sr-only" for="run-label">Etykieta nowego audytu</label>
      <input id="run-label" name="label" type="text" maxlength="60" placeholder="Etykieta, np. po pracach" style="width:210px">
      <button class="btn" type="submit"><?= $audit ? 'Sprawdź ponownie' : 'Sprawdź stronę' ?></button>
    </form>
  <?php endif ?>
</header>
<?= View::partial('partials/audit_nav', ['on' => 'ai']) ?>

<?php if ($open): ?>
<section class="card" id="postep" data-audit-run="/audyt/tresc/audyt/<?= (int) $open['id'] ?>/krok" data-csrf="<?= e(App\Core\Csrf::token()) ?>">
  <h2 style="margin-bottom:4px">Audyt w toku</h2>
  <p class="small muted" style="margin:0 0 12px">Zwykle trwa około minuty. Możesz zostawić tę stronę otwartą, wynik pojawi się sam.</p>
  <ol class="ca-steps">
    <?php foreach (Measure::STEPS as $k => $label): $st = $openSteps[$k] ?? 'pending'; ?>
      <li data-step="<?= e($k) ?>" class="<?= e($st) ?>"><span class="ca-step-state"><?= ['pending' => 'czeka', 'done' => 'gotowe', 'failed' => 'bez pomiaru'][$st] ?? e($st) ?></span> <?= e($label) ?></li>
    <?php endforeach ?>
  </ol>
  <p class="small" data-audit-error hidden role="alert"></p>
  <form method="post" action="/audyt/tresc/audyt/<?= (int) $open['id'] ?>/dokoncz" data-audit-fallback>
    <?= csrf_field() ?>
    <noscript><p class="small">Bez JavaScript audyt wykona się po kliknięciu przycisku i potrwa około minuty.</p></noscript>
    <button class="btn ghost sm" type="submit" data-busy="Sprawdzam…">Dokończ audyt teraz</button>
  </form>
</section>
<?php elseif ($failed): ?>
  <div class="notice high" role="alert">Ostatni audyt (<?= e(local_dt($failed['created_at'])) ?>) się nie udał. <?= e($failed['error']) ?></div>
<?php endif ?>

<?php if ($audit): ?>
  <?php if (count($audits) > 1): ?>
    <nav class="filters" aria-label="Wybierz audyt">
      <?php foreach (array_slice($audits, 0, 8) as $a): ?><a href="/audyt/tresc/<?= (int) $target['id'] ?>?audyt=<?= (int) $a['id'] ?>"<?= (int) $a['id'] === (int) $audit['id'] ? ' class="on" aria-current="true"' : '' ?>><?= e(local_dt($a['finished_at'], 'd.m.Y')) ?><?= $a['label'] ? ' · ' . e($a['label']) : '' ?> · <?= (int) $a['score'] ?> pkt</a><?php endforeach ?>
      <a href="/audyt/tresc/<?= (int) $target['id'] ?>/porownanie">Przed i po</a>
    </nav>
  <?php endif ?>
  <?php if ($audit['ruleset'] !== Ruleset::version()): ?>
    <div class="notice info">Ten audyt policzono według wytycznych w wersji <?= e($audit['ruleset']) ?>. Obecna wersja to <?= e(Ruleset::version()) ?>. Nowy audyt użyje obecnej.</div>
  <?php endif ?>
  <div class="cols">
    <div class="main stack">
      <?= View::partial('partials/content_report', ['target' => $target, 'audit' => $audit, 'checks' => $checks, 'tasks' => $tasks, 'totals' => $totals, 'public' => false]) ?>
    </div>
    <div class="side stack">
      <section class="card stack-sm" id="link" aria-labelledby="ca-link">
        <h2 id="ca-link">Raport dla klienta</h2>
        <?php if ($newLink): ?>
          <div class="field"><label for="new-link">Nowy link (widać go tylko teraz)</label><input id="new-link" type="text" readonly value="<?= e($newLink) ?>"></div>
          <p class="small muted" style="margin:0">Skopiuj adres i wyślij klientowi. Link działa <?= App\Modules\Content\ContentAudit::LINK_DAYS ?> dni i pokazuje zawsze najnowszy audyt tej strony.</p>
        <?php else: ?>
          <p class="small" style="margin:0">Klient zobaczy raport bez logowania i bez menu panelu. Strona z raportem nie trafia do wyszukiwarek.</p>
        <?php endif ?>
        <p class="small muted" style="margin:0">Aktywne linki: <?= count($shares) ?><?php if ($shares): ?>, najnowszy ważny do <?= e(local_dt($shares[0]['expires_at'], 'd.m.Y')) ?><?php endif ?>.</p>
        <div class="row-flex">
          <form method="post" action="/audyt/tresc/<?= (int) $target['id'] ?>/link"><?= csrf_field() ?><button class="btn ghost sm" type="submit">Utwórz link</button></form>
          <?php if ($shares): ?><form method="post" action="/audyt/tresc/<?= (int) $target['id'] ?>/link" data-confirm="Wyłączyć wszystkie linki do tego raportu? Klient straci do niego dostęp."><?= csrf_field() ?><input type="hidden" name="action" value="revoke"><button class="btn danger sm" type="submit">Wyłącz linki</button></form><?php endif ?>
        </div>
      </section>

      <section class="card stack-sm" id="pytania" aria-labelledby="ca-q">
        <h2 id="ca-q">Pytania poboczne (D2)</h2>
        <?php if ($locked): ?>
          <p class="small muted" style="margin:0">Lista zatwierdzona <?= e(local_dt($target['questions_locked_at'], 'd.m.Y')) ?>. Zostaje bez zmian, żeby kolejne audyty dało się porównać.</p>
          <ol class="small" style="margin:0;padding-left:20px"><?php foreach ($questions as $q): ?><li><?= e($q) ?></li><?php endforeach ?></ol>
        <?php else: ?>
          <p class="small muted" style="margin:0">Wyszukiwarki AI rozbijają zapytanie na kilka pytań. Wpisz do 10 pytań, po jednym w wierszu, i zatwierdź listę. Do tego czasu wskaźnik D2 ma stan „brak pomiaru”.</p>
          <form method="post" action="/audyt/tresc/<?= (int) $target['id'] ?>/pytania" class="stack-sm">
            <?= csrf_field() ?>
            <label class="sr-only" for="questions">Pytania poboczne</label>
            <textarea id="questions" name="questions" rows="10" placeholder="Jedno pytanie w wierszu"><?= e(implode("\n", $questions)) ?></textarea>
            <div class="row-flex">
              <button class="btn sm" type="submit" name="action" value="lock">Zatwierdź listę</button>
              <button class="btn ghost sm" type="submit" name="action" value="save">Zapisz roboczo</button>
              <?php if ($services['ai']): ?><button class="btn ghost sm" type="submit" name="action" value="propose">Zaproponuj pytania (AI)</button><?php endif ?>
            </div>
            <?php if (!$services['ai']): ?><p class="xs muted" style="margin:0">Propozycja pytań i ocena D1 i D2 wymagają klucza ANTHROPIC_API_KEY na serwerze.</p><?php endif ?>
          </form>
        <?php endif ?>
      </section>

      <form method="post" action="/audyt/tresc/<?= (int) $target['id'] ?>/usun" class="card stack-sm" data-confirm="Usunąć tę stronę z listy razem z jej audytami i linkami dla klienta?">
        <?= csrf_field() ?>
        <p class="small" style="margin:0">Strona nie jest już potrzebna? Usunięcie kasuje też jej audyty i linki.</p>
        <div><button class="btn danger sm" type="submit">Usuń stronę z listy</button></div>
      </form>
    </div>
  </div>
<?php elseif (!$open): ?>
  <div class="card empty"><h3>Ta strona nie ma jeszcze audytu</h3><p>Kliknij „Sprawdź stronę”. Audyt trwa około minuty.</p></div>
<?php endif ?>
