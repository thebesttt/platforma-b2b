<?php use App\Modules\Outreach\RiskPolicy; ?>
<header class="between page-head">
  <div>
    <p class="crumbs">Klienci i wiadomości</p>
    <h1>Klienci i wiadomości</h1>
    <p class="muted small">Przy każdej wiadomości widzisz ryzyko prawne. Decyzję o wysłaniu podejmujesz Ty, a wiadomość wychodzi z Twojej skrzynki.</p>
  </div>
  <div class="row-flex">
    <a class="btn ghost" href="/klienci/potencjalni">Potencjalni klienci<?= ($prospects['new'] + $prospects['picked']) ? ' (' . (int) ($prospects['new'] + $prospects['picked']) . ')' : '' ?></a>
    <a class="btn ghost" href="/klienci/zapytania">Zapytania z rynku<?= $rfqOpen ? ' (' . (int) $rfqOpen . ')' : '' ?></a>
    <a class="btn ghost" href="/klienci/idealny-klient">Idealny klient</a>
    <a class="btn ghost" href="/klienci/przypomnienia">Przypomnienia<?= $due ? ' (' . (int) $due . ' na dziś)' : '' ?></a>
    <a class="btn" href="/klienci/nowy">Dodaj kontakt</a>
  </div>
</header>
<?php if (!empty($rfqSoon)): ?>
  <div class="notice mid" style="margin-bottom:16px">
    <?= count($rfqSoon) === 1 ? 'Kończy się termin zapytania ofertowego' : 'Kończą się terminy ' . count($rfqSoon) . ' zapytań ofertowych' ?>:
    <?php foreach (array_slice($rfqSoon, 0, 4) as $i => $x): $n = (int) days_to_date($x['deadline_at']); ?><?= $i ? ', ' : ' ' ?><a href="/klienci/zapytania#z<?= (int) $x['id'] ?>"><?= e(truncate((string) $x['subject'], 60)) ?></a> (<?= $n === 0 ? 'dziś' : ($n === 1 ? 'jutro' : 'za ' . $n . ' dni') ?>)<?php endforeach ?><?= count($rfqSoon) > 4 ? ' i kolejne' : '' ?>.
  </div>
<?php endif ?>
<div class="cols">
  <section class="card flush main" aria-labelledby="t-c">
    <div class="card-head">
      <h2 id="t-c">Kontakty</h2>
      <form class="searchbar" method="get" action="/klienci" style="flex:0 1 360px">
        <label class="sr-only" for="cq">Szukaj kontaktu</label>
        <input id="cq" type="search" name="q" value="<?= e($q) ?>" placeholder="Firma, e-mail lub NIP">
        <button class="btn ghost" type="submit">Szukaj</button>
      </form>
    </div>
    <?php if ($contacts): ?>
      <div class="table-wrap"><table>
        <thead><tr><th scope="col">Firma</th><th scope="col">Podstawa kontaktu</th><th scope="col">Ostatnia wiadomość</th><th scope="col"></th></tr></thead>
        <tbody>
        <?php foreach ($contacts as $c): ?>
          <tr>
            <td><a class="t-title" href="/klienci/<?= (int) $c['id'] ?>"><?= e($c['company_name']) ?></a><div class="t-sub"><?= e(trim(($c['person'] ? $c['person'] . ' · ' : '') . $c['email'])) ?></div></td>
            <td><?php if ($c['unsubscribed_at']): ?><span class="chip high">wypisany</span><?php else: ?><span class="chip <?= $c['basis'] === 'none' ? 'neutral' : 'low' ?>"><?= e(['none' => 'brak', 'rfq' => 'zapytanie ofertowe', 'consent' => 'zgoda', 'client' => 'klient'][$c['basis']] ?? $c['basis']) ?></span><?php endif ?></td>
            <td class="small muted nowrap"><?= $c['last_message_at'] ? e(local_dt($c['last_message_at'], 'd.m.Y')) : '-' ?></td>
            <td class="n"><a class="btn ghost sm" href="/klienci/<?= (int) $c['id'] ?>/wiadomosc">Napisz</a></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table></div>
    <?php else: ?>
      <div class="empty"><h3><?= $q ? 'Brak wyników' : 'Nie masz jeszcze kontaktów' ?></h3><p>Dodaj firmę, która wysłała Ci zapytanie ofertowe albo zgodziła się na kontakt. Zapisz, skąd masz adres - to Twój dowód.</p><a class="btn" href="/klienci/nowy">Dodaj kontakt</a></div>
    <?php endif ?>
  </section>
  <section class="card side" aria-labelledby="t-m">
    <h2 id="t-m" style="margin-bottom:8px">Ostatnie wiadomości</h2>
    <?php if ($messages): ?>
      <ul class="list-plain">
        <?php foreach ($messages as $m): $lv = RiskPolicy::LEVELS[$m['risk']] ?? ['chip' => 'neutral', 'label' => $m['risk']]; ?>
          <li><a href="/wiadomosci/<?= (int) $m['id'] ?>"><?= e(truncate((string) $m['subject'], 70)) ?></a>
            <div class="xs muted"><?= e($m['company_name']) ?> · <?= e(local_dt($m['created_at'], 'd.m H:i')) ?></div>
            <div class="row-flex" style="margin-top:4px"><span class="chip <?= $lv['chip'] ?>">ryzyko <?= e($lv['label']) ?></span><span class="chip <?= $m['status'] === 'sent' ? 'low' : 'neutral' ?>"><?= $m['status'] === 'sent' ? 'wysłana' : 'do wysłania' ?></span></div>
          </li>
        <?php endforeach ?>
      </ul>
    <?php else: ?><p class="muted small" style="margin:0">Brak wiadomości.</p><?php endif ?>
  </section>
</div>
