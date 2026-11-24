<?php
use App\Core\Plans;

/** @var array $firms */
?>
<header class="page-head">
  <p class="crumbs"><a href="/ustawienia">Ustawienia</a> / Administracja platformy</p>
  <h1>Firmy, zaproszenia i pakiety</h1>
  <p class="muted small">Widzisz liczby o firmach, nie ich dane. W tym miesiącu wszystkie firmy zużyły <?= num_pl($totals['calls']) ?> <?= plural((int) $totals['calls'], 'użycie', 'użycia', 'użyć') ?> AI (<?= num_pl($totals['tokens']) ?> tokenów).</p>
</header>

<section class="card flush" id="firmy" aria-labelledby="ad-firms">
  <div class="card-head"><h2 id="ad-firms">Firmy</h2><span class="chip neutral"><?= count($firms) ?></span></div>
  <div class="table-wrap"><table>
    <thead><tr><th scope="col">Firma</th><th scope="col" class="n">Osoby</th><th scope="col" class="n">AI w miesiącu</th><th scope="col" class="n">Czat</th><th scope="col">Ostatnie logowanie</th><th scope="col">Pakiet i stan</th></tr></thead>
    <tbody>
    <?php foreach ($firms as $f): $blocked = ($f['status'] ?? 'active') === 'blocked'; ?>
      <tr>
        <td><b><?= e($f['name']) ?></b><?= $f['operator'] ? ' <span class="chip info">prowadzi panel</span>' : '' ?><?= $blocked ? ' <span class="chip high">zablokowana</span>' : '' ?><br><span class="xs muted"><?= $f['nip'] ? 'NIP ' . e($f['nip']) . ', ' : '' ?>od <?= e(local_dt((string) $f['created_at'], 'd.m.Y')) ?></span></td>
        <td class="n"><?= (int) $f['people'] ?></td>
        <td class="n"><?= num_pl($f['ops']) ?><?= $f['ops_limit'] !== null ? ' z ' . num_pl($f['ops_limit']) : '' ?></td>
        <td class="n"><?= num_pl($f['chat']) ?><?= $f['chat_limit'] !== null ? ' z ' . num_pl($f['chat_limit']) : '' ?></td>
        <td class="small muted nowrap"><?= $f['last_login'] ? e(local_dt((string) $f['last_login'], 'd.m.Y H:i')) : 'nigdy' ?></td>
        <td>
          <?php if ($f['operator']): ?><span class="small muted">bez limitów</span>
          <?php else: ?>
          <form method="post" action="/administracja/firmy/<?= (int) $f['id'] ?>" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <?= csrf_field() ?>
            <label class="sr-only" for="pl-<?= (int) $f['id'] ?>">Pakiet firmy <?= e($f['name']) ?></label>
            <select id="pl-<?= (int) $f['id'] ?>" name="plan" style="min-height:36px;width:auto"><?php foreach (['basic', 'extended'] as $p): ?><option value="<?= $p ?>"<?= $f['plan_key'] === $p ? ' selected' : '' ?>><?= e(Plans::PLANS[$p]) ?></option><?php endforeach ?></select>
            <label class="sr-only" for="st-<?= (int) $f['id'] ?>">Stan firmy <?= e($f['name']) ?></label>
            <select id="st-<?= (int) $f['id'] ?>" name="status" style="min-height:36px;width:auto"><option value="active"<?= $blocked ? '' : ' selected' ?>>aktywna</option><option value="blocked"<?= $blocked ? ' selected' : '' ?>>zablokowana</option></select>
            <button class="btn ghost sm" type="submit">Zapisz</button>
          </form>
          <?php endif ?>
        </td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table></div>
  <p class="sec-note" style="padding-top:12px">Blokada kończy sesje osób z firmy przy najbliższym kliknięciu i wyłącza jej widżet na stronie. Dane zostają. Płatności panel nie obsługuje: pakiet zmieniasz tu po ustaleniach z firmą.</p>
</section>

<section class="card stack-sm" id="zaproszenia" aria-labelledby="ad-inv">
  <h2 id="ad-inv">Zaproś firmę</h2>
  <p class="small muted" style="margin:0">Panel tworzy jednorazowy link dla podanego adresu. Konto powstanie dla tego adresu i w wybranym pakiecie. Link działa <?= App\Core\Invites::DAYS ?> dni.</p>
  <?php if ($link): ?>
    <div class="notice low" role="status">Link do wysłania (pokazujemy go tylko teraz): <code class="copy wrap" title="Kliknij, żeby skopiować"><?= e($link) ?></code></div>
  <?php endif ?>
  <form method="post" action="/administracja/zaproszenia" class="form-grid">
    <?= csrf_field() ?>
    <div class="field"><label for="iv-email">E-mail osoby z firmy</label><input id="iv-email" name="email" type="email" required maxlength="160"></div>
    <div class="field"><label for="iv-co">Nazwa firmy (opcjonalnie)</label><input id="iv-co" name="company" type="text" maxlength="160"></div>
    <div class="field"><label for="iv-plan">Pakiet</label><select id="iv-plan" name="plan"><?php foreach (['basic', 'extended'] as $p): ?><option value="<?= $p ?>"><?= e(Plans::PLANS[$p]) ?></option><?php endforeach ?></select></div>
    <div class="field" style="justify-content:flex-end"><button class="btn" type="submit">Utwórz zaproszenie</button></div>
  </form>
  <?php if (!$mailOn): ?><p class="small muted" style="margin:0">Poczta z panelu nie jest skonfigurowana, więc link wysyłasz samodzielnie.</p><?php endif ?>
  <?php if ($invites): ?>
    <div class="table-wrap"><table>
      <thead><tr><th scope="col">Czeka na użycie</th><th scope="col">Pakiet</th><th scope="col">Ważne do</th><th scope="col"></th></tr></thead>
      <tbody>
      <?php foreach ($invites as $i): ?>
        <tr><td><?= e($i['email']) ?><?= $i['company'] ? '<br><span class="xs muted">' . e($i['company']) . '</span>' : '' ?></td><td class="small"><?= e(Plans::PLANS[$i['plan']] ?? $i['plan']) ?></td><td class="small muted nowrap"><?= e(local_dt((string) $i['expires_at'], 'd.m.Y')) ?></td>
          <td><form method="post" action="/administracja/zaproszenia/<?= (int) $i['id'] ?>/usun"><?= csrf_field() ?><button class="link-btn small" type="submit">Wycofaj</button></form></td></tr>
      <?php endforeach ?>
      </tbody>
    </table></div>
  <?php endif ?>
</section>

<section class="card stack-sm" id="pakiety" aria-labelledby="ad-pl">
  <h2 id="ad-pl">Limity pakietów</h2>
  <p class="small muted" style="margin:0">Po wyczerpaniu przydziału AI panel wraca do wersji bez AI i niczego nie blokuje. Funkcje bez AI nie mają limitu użyć.</p>
  <form method="post" action="/administracja/pakiety">
    <?= csrf_field() ?>
    <div class="table-wrap"><table>
      <thead><tr><th scope="col">Limit</th><?php foreach ($limits as $p => $_): ?><th scope="col"><?= e(Plans::PLANS[$p]) ?></th><?php endforeach ?></tr></thead>
      <tbody>
      <?php foreach (Plans::LIMITS as $key => [$label, $hint]): ?>
        <tr><td><b><?= e($label) ?></b><br><span class="xs muted"><?= e($hint) ?></span></td>
          <?php foreach ($limits as $p => $vals): ?><td><label class="sr-only" for="lm-<?= $p ?>-<?= $key ?>"><?= e($label . ', ' . Plans::PLANS[$p]) ?></label><input id="lm-<?= $p ?>-<?= $key ?>" name="limit[<?= $p ?>][<?= $key ?>]" type="text" inputmode="numeric" maxlength="7" value="<?= (int) $vals[$key] ?>" style="max-width:120px"></td><?php endforeach ?></tr>
      <?php endforeach ?>
      </tbody>
    </table></div>
    <div style="margin-top:12px"><button class="btn ghost" type="submit">Zapisz limity</button></div>
  </form>
  <p class="small muted" style="margin:0">Tańszy model AI dla pakietu Podstawowego ustawia zmienna <code>AI_MODEL_BASIC</code>. Pusta oznacza ten sam model w obu pakietach.</p>
</section>

<section class="card stack-sm" id="awaryjne" aria-labelledby="ad-em">
  <h2 id="ad-em">Awaryjnie: wyłącz logowanie dwuetapowe</h2>
  <p class="small muted" style="margin:0">Dla osoby, która straciła telefon i kody zapasowe, a w jej firmie nie ma kto tego zrobić. Najpierw potwierdź inną drogą, że prosi o to właściciel konta.</p>
  <form method="post" action="/administracja/kody" class="searchbar">
    <?= csrf_field() ?>
    <label class="sr-only" for="em-email">E-mail konta</label>
    <input id="em-email" name="email" type="email" required placeholder="adres e-mail konta">
    <button class="btn ghost" type="submit">Wyłącz</button>
  </form>
</section>
