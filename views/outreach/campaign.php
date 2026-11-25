<?php
use App\Modules\Outreach\Campaigns;
use App\Modules\Outreach\RiskPolicy;
/**
 * @var array $c kampania @var array $rows odbiorcy z wersjami @var array $audience kontakty z oceną ryzyka @var array $stats
 * @var ?int $room ile panel może dziś wysłać @var ?int $limit @var bool $mailOn @var int $aiLeft @var string $base @var bool $sending @var bool $aiRun
 */
$id = (int) $c['id'];
$url = '/klienci/kampanie/' . $id;
$routeLabel = ['panel' => ['wyśle panel', 'low'], 'own' => ['wyślesz ze swojej skrzynki', 'mid']];
$sourceLabel = ['rules' => 'z szablonu i faktów', 'ai' => 'dopasowana przez AI', 'manual' => 'poprawiona ręcznie'];
$panelPending = (int) ($stats['panel_pending'] ?? 0);
$sample = RiskPolicy::assess('consented', ['basis' => 'none']);
$user = App\Core\Auth::user();
$org = App\Core\Auth::org() ?? [];
?>
<header class="between page-head">
  <div>
    <p class="crumbs"><a href="/klienci">Klienci</a> / <a href="/klienci/kampanie">Kampanie e-mail</a> / <?= e($c['name']) ?></p>
    <h1><?= e($c['name']) ?></h1>
    <p class="muted small">Wysłane przez panel: <?= (int) $stats['sent'] ?> · pobrane do wysłania ze skrzynki: <?= (int) $stats['downloaded'] ?> · odpowiedzi: <?= (int) $stats['replied'] ?> · wypisania: <?= (int) $stats['unsubscribed'] ?></p>
  </div>
  <div class="row-flex">
    <a class="btn ghost" href="#tresc">Treść</a>
    <a class="btn ghost" href="#odbiorcy">Odbiorcy</a>
    <a class="btn ghost" href="#wersje">Wersje (<?= count($rows) ?>)</a>
    <a class="btn ghost" href="#wysylka">Wysyłka</a>
  </div>
</header>

<?php if ($sending && $panelPending > 0): ?>
<div class="notice info" role="status" style="margin-bottom:16px">
  <form method="post" action="<?= $url ?>/wyslij" class="row-flex" data-auto-submit><?= csrf_field() ?>
    <span><strong>Wysyłamy kampanię:</strong> zostało <?= $panelPending ?> <?= plural($panelPending, 'wiadomość', 'wiadomości', 'wiadomości') ?>. Nie zamykaj tej strony.</span>
    <a class="small" href="<?= $url ?>#wysylka">Zatrzymaj</a>
  </form>
</div>
<?php endif ?>
<?php if ($aiRun && $aiLeft > 0): ?>
<div class="notice info" role="status" style="margin-bottom:16px">
  <form method="post" action="<?= $url ?>/ai" class="row-flex" data-auto-submit><?= csrf_field() ?>
    <span><strong>AI dopasowuje wersje:</strong> zostało <?= $aiLeft ?> <?= plural($aiLeft, 'odbiorca', 'odbiorców', 'odbiorców') ?>. Nie zamykaj tej strony.</span>
    <a class="small" href="<?= $url ?>#wersje">Zatrzymaj</a>
  </form>
</div>
<?php endif ?>

<section class="card" id="tresc">
  <h2>1. Treść</h2>
  <form method="post" action="<?= $url ?>" class="stack-sm">
    <?= csrf_field() ?>
    <div class="form-grid two">
      <div class="field"><label for="c-name">Nazwa kampanii</label><input id="c-name" type="text" name="name" maxlength="120" value="<?= e($c['name']) ?>"></div>
      <div class="field"><label for="c-subject">Temat</label><input id="c-subject" type="text" name="subject" maxlength="200" value="<?= e($c['subject']) ?>"></div>
    </div>
    <div class="field"><label for="c-body">Treść z polami</label><textarea id="c-body" name="body" rows="12"><?= e($c['body']) ?></textarea></div>
    <p class="xs muted">Pola: <?php foreach (Campaigns::FIELDS as $field => $desc): ?><code title="<?= e($desc) ?>"><?= e($field) ?></code> <?php endforeach ?>. Stopkę z informacją, skąd masz adres, i linkiem do wypisania panel dodaje sam do każdej wiadomości.</p>
    <div><button class="btn" type="submit">Zapisz treść</button></div>
  </form>
</section>

<section class="card flush" id="odbiorcy">
  <div class="card-head"><h2>2. Odbiorcy</h2></div>
  <?php if ($audience): ?>
  <form method="post" action="<?= $url ?>/odbiorcy">
    <?= csrf_field() ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th scope="col"><span class="sr-only">Wybierz</span></th><th scope="col">Firma</th><th scope="col">Podstawa kontaktu</th><th scope="col">Ryzyko</th><th scope="col">Kto wyśle</th></tr></thead>
        <tbody>
        <?php foreach ($audience as $a): $ct = $a['contact']; $risk = $a['risk']; $lvl = RiskPolicy::LEVELS[$risk['level']]; ?>
          <tr>
            <td><?php if ($risk['blocked']): ?><span class="chip high">wypisana</span><?php else: ?><label class="sr-only" for="a-<?= (int) $ct['id'] ?>">Wybierz <?= e($ct['company_name']) ?></label><input id="a-<?= (int) $ct['id'] ?>" type="checkbox" name="contacts[]" value="<?= (int) $ct['id'] ?>"<?= $a['selected'] ? ' checked' : '' ?>><?php endif ?></td>
            <td><a href="/klienci/<?= (int) $ct['id'] ?>"><?= e($ct['company_name']) ?></a><div class="t-sub"><?= e($ct['email']) ?><?= $ct['person'] ? ', ' . e($ct['person']) : '' ?></div><?php if ($a['recent']): ?><div class="t-sub"><span class="chip neutral">dostała kampanię <?= e(local_dt($a['recent'], 'd.m')) ?>, przerwa <?= Campaigns::PAUSE_DAYS ?> dni</span></div><?php endif ?></td>
            <td class="small"><?= e(truncate(RiskPolicy::basisLine($ct), 120)) ?></td>
            <td><span class="chip <?= $lvl['chip'] ?>"><?= e($lvl['label']) ?></span></td>
            <td class="small"><?= $risk['blocked'] ? 'nikt: firma się wypisała' : ($risk['level'] === 'low' ? 'panel' : 'Ty, ze swojej skrzynki, po potwierdzeniu ryzyka') ?></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <div class="row-flex" style="padding:16px 20px"><button class="btn" type="submit">Zapisz odbiorców i przygotuj wersje</button><span class="xs muted">Odznaczona firma wypada z kampanii, jeśli nie dostała jeszcze wiadomości.</span></div>
  </form>
  <?php else: ?>
    <div class="empty"><h3>Nie masz jeszcze kontaktów z adresem e-mail</h3><p>Dodaj kontakt w zakładce <a href="/klienci/nowy">Klienci</a>. Panel nie zbiera adresów z rejestrów ani stron: kontakty to firmy, które same podały adres (zapytanie, zgoda, współpraca).</p></div>
  <?php endif ?>
</section>

<section class="card" id="wersje">
  <div class="between" style="flex-wrap:wrap;gap:12px">
    <h2>3. Wersje dla odbiorców</h2>
    <?php if ($aiLeft > 0 && App\Modules\Ai\Claude::enabled()): ?>
      <form method="post" action="<?= $url ?>/ai"><?= csrf_field() ?><button class="btn ghost" type="submit">Dopasuj przez AI (<?= $aiLeft ?> <?= plural($aiLeft, 'odbiorca', 'odbiorców', 'odbiorców') ?>)</button></form>
    <?php endif ?>
  </div>
  <?php if ($aiLeft > 0 && App\Modules\Ai\Claude::enabled()): ?><p class="xs muted">AI przepisze wersje z szablonu pod fakty każdej firmy, bez zmiany oferty. Każdy odbiorca to jedno użycie AI z pakietu<?= $aiRoom !== null ? ' (miesięcznie: ' . (int) $aiRoom . ')' : '' ?>. Wersji poprawionych ręcznie AI nie rusza.</p><?php endif ?>
  <?php if (!$rows): ?><p class="muted">Wybierz odbiorców powyżej. Panel przygotuje wersję dla każdej firmy.</p><?php endif ?>
  <?php foreach ($rows as $w): $facts = json_in($w['facts_json']); [$rl, $rc] = $routeLabel[$w['route']] ?? ['', 'neutral']; $editable = $w['status'] === 'pending'; ?>
    <details class="version" id="w<?= (int) $w['id'] ?>"<?= count($rows) <= 3 ? ' open' : '' ?>>
      <summary>
        <b><?= e($w['company_name']) ?></b>
        <span class="chip <?= $rc ?>"><?= e($rl) ?></span>
        <span class="chip neutral"><?= e(Campaigns::STATUSES[$w['status']] ?? $w['status']) ?></span>
        <span class="xs muted"><?= e($sourceLabel[$w['source']] ?? '') ?><?= !empty($facts['reason_source']) ? ' · zdanie o firmie z faktów' : '' ?></span>
      </summary>
      <?php if (!empty($w['error'])): ?><p class="small notice high"><?= e($w['error']) ?></p><?php endif ?>
      <?php if (!empty($facts['list'])): ?>
        <p class="xs muted" style="margin:8px 0 4px">Fakty, które zna panel:</p>
        <ul class="xs muted list-plain"><?php foreach ($facts['list'] as $f): ?><li><?= e($f['label']) ?>: <?= e($f['text']) ?></li><?php endforeach ?></ul>
      <?php else: ?>
        <p class="xs muted">Panel nie zna o tej firmie żadnych faktów poza nazwą, więc zdanie {powod} zostało pominięte.</p>
      <?php endif ?>
      <form method="post" action="<?= $url ?>/wersja/<?= (int) $w['id'] ?>" class="stack-sm">
        <?= csrf_field() ?>
        <div class="field"><label for="s-<?= (int) $w['id'] ?>">Temat</label><input id="s-<?= (int) $w['id'] ?>" type="text" name="subject" value="<?= e($w['subject']) ?>"<?= $editable ? '' : ' readonly' ?>></div>
        <div class="field"><label for="b-<?= (int) $w['id'] ?>">Treść</label><textarea id="b-<?= (int) $w['id'] ?>" name="body" rows="10"<?= $editable ? '' : ' readonly' ?>><?= e($w['body']) ?></textarea></div>
        <pre class="xs muted" style="white-space:pre-wrap;margin:0"><?= e(ltrim(Campaigns::footer(['id' => (int) $w['contact_id']] + $w, $base), "\n")) ?></pre>
        <?php if ($editable): ?>
          <div class="row-flex">
            <button class="btn sm" type="submit" name="action" value="save">Zapisz wersję</button>
            <?php if ($w['source'] !== 'rules'): ?><button class="btn ghost sm" type="submit" name="action" value="rules">Wróć do wersji z szablonu</button><?php endif ?>
            <button class="btn ghost sm" type="submit" name="action" value="exclude">Pomiń tę firmę</button>
          </div>
        <?php elseif ($w['status'] === 'excluded'): ?>
          <div><button class="btn ghost sm" type="submit" name="action" value="include">Przywróć firmę</button></div>
        <?php endif ?>
      </form>
    </details>
  <?php endforeach ?>
</section>

<section class="card" id="wysylka">
  <h2>4. Wysyłka</h2>
  <div class="cols">
    <div class="main stack-sm">
      <h3>Przez panel (firmy ze zgodą albo Twoi klienci): <?= $panelPending ?></h3>
      <?php if (!$mailOn): ?>
        <p class="small notice mid">Na tym serwerze wysyłka e-maili nie jest skonfigurowana. Administrator ustawia ją w zmiennych RESEND_API_KEY i MAIL_FROM.</p>
      <?php else: ?>
        <p class="small">Nadawca: „<?= e($org['name'] ?? '') ?>” z adresu panelu. Odpowiedzi trafią na <?= e($user['email'] ?? '') ?>. Każda wiadomość ma link do wypisania w stopce i w nagłówku, który skrzynki pocztowe pokazują jako przycisk „Wypisz”.</p>
        <p class="xs muted">Dzienny limit wysyłki z pakietu: <?= $limit === null ? 'bez limitu' : $limit . ', dziś zostało ' . (int) $room ?>.</p>
        <form method="post" action="<?= $url ?>/wyslij"><?= csrf_field() ?><button class="btn" type="submit"<?= $panelPending === 0 || $room === 0 ? ' disabled' : '' ?>>Wyślij przez panel (<?= $panelPending ?>)</button></form>
      <?php endif ?>
    </div>
    <div class="side stack-sm">
      <h3>Ze swojej skrzynki: <?= (int) $stats['own_unacked'] + (int) $stats['own_ready'] ?></h3>
      <?php if ((int) $stats['own_unacked'] > 0): ?>
        <div class="notice high small">
          <p style="margin-top:0"><b>Ryzyko <?= e(RiskPolicy::LEVELS[$sample['level']]['label']) ?>.</b> <?= e($sample['lead']) ?> Te firmy nie mają w karcie zapisanej zgody ani relacji.</p>
          <ul><?php foreach ($sample['law'] as $l): ?><li><?= e($l) ?></li><?php endforeach ?></ul>
          <p>Panel nie wyśle tych wiadomości sam. Możesz je pobrać i wysłać ze swojej skrzynki, jeśli tak zdecydujesz. Bezpieczniej: list z kodem strony zgody (zakładka „Potencjalni klienci”).</p>
        </div>
        <form method="post" action="<?= $url ?>/potwierdz" class="stack-sm"><?= csrf_field() ?>
          <label class="check"><input type="checkbox" name="ack" value="1" data-ack-for="ack-btn"> <span class="small"><?= e(RiskPolicy::ACK_TEXT) ?></span></label>
          <div><button id="ack-btn" class="btn ghost" type="submit">Potwierdzam dla <?= (int) $stats['own_unacked'] ?> <?= plural((int) $stats['own_unacked'], 'wiadomości', 'wiadomości', 'wiadomości') ?></button></div>
          <p class="xs muted">Zapiszemy potwierdzenie z datą i godziną w historii kampanii i kontaktu.</p>
        </form>
      <?php endif ?>
      <?php if ((int) $stats['own_ready'] > 0): ?>
        <form method="post" action="<?= $url ?>/pobierz"><?= csrf_field() ?><button class="btn" type="submit">Pobierz <?= (int) $stats['own_ready'] ?> <?= plural((int) $stats['own_ready'], 'wiadomość', 'wiadomości', 'wiadomości') ?> (.eml w ZIP)</button></form>
        <p class="xs muted">Rozpakuj plik i otwórz każdą wiadomość w programie pocztowym (Outlook, Thunderbird, Apple Mail), a potem kliknij Wyślij.</p>
      <?php elseif ((int) $stats['own_unacked'] === 0): ?>
        <p class="small muted">Brak wiadomości do wysłania ze skrzynki.</p>
      <?php endif ?>
    </div>
  </div>
  <?php if ((int) $stats['sent'] + (int) $stats['downloaded'] === 0): ?>
    <form method="post" action="<?= $url ?>/usun" style="margin-top:20px"><?= csrf_field() ?><button class="btn ghost sm" type="submit">Usuń kampanię</button></form>
  <?php endif ?>
</section>
