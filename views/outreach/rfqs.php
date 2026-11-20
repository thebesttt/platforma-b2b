<?php
use App\Modules\Outreach\Rfqs;
/**
 * @var array $open zapytania do odpowiedzi @var array $closed @var array $form wartości formularza (puste przy nowym)
 * @var int $editId poprawiane zapytanie albo 0 @var string[] $keywords słowa z profili przetargowych
 */
$v = static fn (string $k): string => e((string) ($form[$k] ?? ''));
$deadline = static function (?string $date, bool $openRow = true): string {
    $n = days_to_date($date);
    if ($n === null) {
        return '<span class="chip neutral">bez terminu</span>';
    }
    $d = e(date('d.m.Y', strtotime((string) $date)));
    if (!$openRow) {
        return '<span class="muted small nowrap">termin ' . $d . '</span>';
    }
    return match (true) {
        $n < 0 => '<span class="chip neutral">po terminie (' . $d . ')</span>',
        $n === 0 => '<span class="chip high">termin dziś</span>',
        $n === 1 => '<span class="chip high">termin jutro</span>',
        $n <= Rfqs::SOON_DAYS => '<span class="chip mid">za ' . $n . ' dni (' . $d . ')</span>',
        default => '<span class="chip low">za ' . $n . ' dni (' . $d . ')</span>',
    };
};
$who = static function (array $r): string {
    $name = e($r['company_name']);
    $out = !empty($r['nip']) ? '<a href="/kontrahenci/' . e($r['nip']) . '">' . $name . '</a>' : $name;
    $out .= ' · ' . e(Rfqs::SOURCE_LABELS[$r['source']] ?? Rfqs::SOURCE_LABELS['other']);
    if (!empty($r['email'])) {
        $out .= ' · ' . e(trim(($r['person'] ? $r['person'] . ', ' : '') . $r['email']));
    } elseif (!empty($r['person'])) {
        $out .= ' · ' . e($r['person']);
    }
    return $out;
};
$post = static fn (int $id, string $field, string $value, string $label, string $class = 'btn ghost sm', string $confirm = ''): string =>
    '<form class="inline" method="post" action="/klienci/zapytania/' . $id . '/stan"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field()
    . '<input type="hidden" name="' . $field . '" value="' . e($value) . '"><button class="' . $class . '" type="submit">' . e($label) . '</button></form>';
?>
<header class="between page-head">
  <div>
    <p class="crumbs"><a href="/klienci">Klienci i wiadomości</a> / Zapytania ofertowe z rynku</p>
    <h1>Zapytania ofertowe z rynku</h1>
    <p class="muted small">Firmy ogłaszają zapytania w miejscach, które nie udostępniają danych programom. Panel pokazuje, gdzie szukać, a po wpisaniu zapytania pilnuje terminu i przygotowuje odpowiedź.</p>
  </div>
  <div class="row-flex">
    <a class="btn ghost" href="#zrodla">Gdzie szukać</a>
    <a class="btn" href="#dodaj">Dodaj zapytanie</a>
  </div>
</header>

<div class="stack">
<?php ob_start(); ?>
  <section class="card flush" aria-labelledby="t-open">
    <div class="card-head"><h2 id="t-open">Do odpowiedzi</h2><span class="muted xs"><?= count($open) ?> <?= plural(count($open), 'zapytanie', 'zapytania', 'zapytań') ?>, najbliższy termin na górze</span></div>
    <?php if ($open): ?>
      <ul class="item-list">
        <?php foreach ($open as $r): $id = (int) $r['id']; $late = (days_to_date($r['deadline_at']) ?? 0) < 0; ?>
          <li id="z<?= $id ?>">
            <div class="item-head">
              <div>
                <?php if ($r['url']): ?><a class="t-title" href="<?= e(safe_url($r['url'])) ?>" target="_blank" rel="noopener noreferrer"><?= e($r['subject']) ?></a><?php else: ?><span class="t-title"><?= e($r['subject']) ?></span><?php endif ?>
                <div class="t-sub"><?= $who($r) ?></div>
              </div>
              <?= $deadline($r['deadline_at']) ?>
            </div>
            <?php if ($r['notes']): ?><p class="small muted pre" style="margin:8px 0 0"><?= e($r['notes']) ?></p><?php endif ?>
            <?php if ($r['contact_id'] && $r['unsubscribed_at']): ?><p class="small" style="margin:8px 0 0;color:var(--high)">Ten adres wypisał się z wiadomości ofertowych. Na zapytanie nadal możesz odpowiedzieć.</p><?php endif ?>
            <div class="item-actions">
              <?php if ($r['contact_id'] && $r['draft_id']): ?>
                <a class="btn sm" href="/wiadomosci/<?= (int) $r['draft_id'] ?>">Otwórz przygotowaną odpowiedź</a>
              <?php elseif ($r['contact_id']): ?>
                <a class="btn sm" href="/klienci/<?= (int) $r['contact_id'] ?>/wiadomosc?kind=rfq_reply"><?= $late ? 'Odpowiedz mimo terminu' : 'Przygotuj odpowiedź' ?></a>
              <?php elseif ($r['url']): ?>
                <a class="btn sm" href="<?= e(safe_url($r['url'])) ?>" target="_blank" rel="noopener noreferrer">Otwórz zapytanie i złóż ofertę</a>
              <?php endif ?>
              <?= $post($id, 'status', 'replied', 'Oferta złożona') ?>
              <?= $post($id, 'status', 'skipped', 'Pomiń') ?>
              <a class="btn ghost sm" href="/klienci/zapytania?edytuj=<?= $id ?>#dodaj">Popraw</a>
            </div>
          </li>
        <?php endforeach ?>
      </ul>
    <?php else: ?>
      <div class="empty"><h3>Nie masz zapytań do odpowiedzi</h3><p>Znajdź zapytanie w jednym z miejsc z listy „Gdzie szukać” i wpisz je w formularzu. Panel przypomni o terminie i przygotuje odpowiedź.</p></div>
    <?php endif ?>
  </section>
<?php $list = ob_get_clean(); ob_start(); ?>
  <section class="card" id="zrodla" aria-labelledby="t-src">
    <h2 id="t-src">Gdzie szukać zapytań</h2>
    <p class="muted small" style="margin:4px 0 0">Otwórz źródło, wpisz słowa opisujące Twoją ofertę i przepisz do panelu zapytania, na które chcesz odpowiedzieć.</p>
    <div class="src-grid">
      <?php foreach (Rfqs::SOURCES as $s): ?>
        <div>
          <h3><?= e($s['name']) ?></h3>
          <?php if ($s['url']): ?><p class="small" style="margin:4px 0 0"><a href="<?= e($s['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($s['link']) ?></a></p><?php endif ?>
          <p class="small" style="margin:8px 0 0"><?= e($s['what']) ?></p>
          <p class="small muted" style="margin:6px 0 0"><?= e($s['how']) ?></p>
        </div>
      <?php endforeach ?>
    </div>
    <div class="src-step" style="margin-top:16px">
      <?php if ($keywords): ?>
        <p class="small" style="margin:0 0 8px"><strong>Słowa do wyszukiwarki</strong> z Twojego profilu przetargowego (kliknij słowo, żeby je zaznaczyć i skopiować):</p>
        <p class="row-flex" style="margin:0"><?php foreach ($keywords as $w): ?><code class="copy"><?= e($w) ?></code><?php endforeach ?></p>
      <?php else: ?>
        <p class="small" style="margin:0">Nie masz jeszcze profilu przetargowego. <a href="/profile/nowy">Dodaj profil</a>, a pokażemy tu słowa, których warto szukać.</p>
      <?php endif ?>
      <p class="small muted" style="margin:10px 0 0">Przetargi publiczne z BZP i TED panel pobiera sam: są w zakładce <a href="/przetargi">Przetargi</a>. Zapytania wysłane do Ciebie przez formularz na stronie są w zakładce <a href="/zapytania">Zapytania</a>.</p>
    </div>
  </section>

  <section class="card" id="dodaj" aria-labelledby="t-add">
    <h2 id="t-add"><?= $editId ? 'Popraw zapytanie' : 'Wpisz znalezione zapytanie' ?></h2>
    <p class="muted small" style="margin:4px 0 14px">Wystarczy firma, temat i link albo e-mail z ogłoszenia. Z terminem panel przypomni o zapytaniu <?= Rfqs::SOON_DAYS ?> dni wcześniej.</p>
    <form method="post" action="/klienci/zapytania<?= $editId ? '/' . $editId : '' ?>" class="form-grid two">
      <?= csrf_field() ?>
      <div class="field"><label for="rq-company">Firma, która ogłosiła zapytanie</label><input id="rq-company" name="company_name" type="text" maxlength="200" required value="<?= $v('company_name') ?>"></div>
      <div class="field"><label for="rq-nip">NIP firmy, opcjonalnie</label><input id="rq-nip" name="nip" type="text" inputmode="numeric" maxlength="20" value="<?= $v('nip') ?>"><span class="hint">Z NIP-em od razu otworzysz kartę firmy z oceną ryzyka.</span></div>
      <div class="field" style="grid-column:1/-1"><label for="rq-subject">Czego dotyczy zapytanie</label><input id="rq-subject" name="subject" type="text" maxlength="300" required placeholder="np. remont nawierzchni placu manewrowego, ok. 1200 m2" value="<?= $v('subject') ?>"></div>
      <div class="field"><label for="rq-url">Link do zapytania</label><input id="rq-url" name="url" type="url" maxlength="500" placeholder="https://" value="<?= $v('url') ?: $v('url_raw') ?>"></div>
      <div class="field"><label for="rq-source">Gdzie je znalazłeś</label><select id="rq-source" name="source"><?php foreach (Rfqs::SOURCE_LABELS as $k => $label): ?><option value="<?= e($k) ?>"<?= ($form['source'] ?? 'bk') === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></div>
      <div class="field"><label for="rq-deadline">Termin składania ofert</label><input id="rq-deadline" name="deadline_at" type="date" value="<?= e(substr((string) ($form['deadline_at'] ?? ''), 0, 10)) ?>"></div>
      <div class="field"><label for="rq-pub">Data ogłoszenia, opcjonalnie</label><input id="rq-pub" name="published_at" type="date" max="<?= e(today_pl()) ?>" value="<?= e(substr((string) ($form['published_at'] ?? ''), 0, 10)) ?>"></div>
      <div class="field"><label for="rq-email">E-mail do składania ofert, opcjonalnie</label><input id="rq-email" name="email" type="email" maxlength="200" value="<?= $v('email') ?: $v('email_raw') ?>"><span class="hint">Wpisz, jeśli oferty przyjmowane są e-mailem. Dodamy kontakt z podstawą „zapytanie ofertowe” i przygotujemy odpowiedź.</span></div>
      <div class="field"><label for="rq-person">Osoba kontaktowa, opcjonalnie</label><input id="rq-person" name="person" type="text" maxlength="120" value="<?= $v('person') ?>"></div>
      <div class="field" style="grid-column:1/-1"><label for="rq-notes">Notatka, opcjonalnie</label><textarea id="rq-notes" name="notes" rows="2" maxlength="2000" placeholder="np. wymagane referencje, wadium, pytania do zamawiającego"><?= $v('notes') ?></textarea></div>
      <div class="field" style="grid-column:1/-1"><div class="row-flex"><button class="btn" type="submit"><?= $editId ? 'Zapisz zmiany' : 'Zapisz zapytanie' ?></button><?php if ($editId): ?><a class="btn ghost" href="/klienci/zapytania">Anuluj</a><?php endif ?></div></div>
    </form>
  </section>
<?php $guide = ob_get_clean(); ?>
  <?= $open || $editId ? $list . $guide : $guide . $list ?>

  <?php if ($closed): ?>
  <section class="card flush" aria-labelledby="t-closed">
    <div class="card-head"><h2 id="t-closed">Zamknięte</h2><span class="muted xs">ostatnie <?= count($closed) ?></span></div>
    <ul class="item-list">
      <?php foreach ($closed as $r): $id = (int) $r['id']; ?>
        <li id="z<?= $id ?>">
          <div class="item-head">
            <div>
              <?php if ($r['url']): ?><a class="t-title" href="<?= e(safe_url($r['url'])) ?>" target="_blank" rel="noopener noreferrer"><?= e($r['subject']) ?></a><?php else: ?><span class="t-title"><?= e($r['subject']) ?></span><?php endif ?>
              <div class="t-sub"><?= $who($r) ?></div>
            </div>
            <span class="chip <?= $r['status'] === 'replied' ? 'low' : 'neutral' ?>"><?= e(Rfqs::STATUSES[$r['status']] ?? $r['status']) ?></span>
          </div>
          <div class="item-actions">
            <?= $deadline($r['deadline_at'], false) ?>
            <?php if ($r['contact_id']): ?><a class="btn ghost sm" href="/klienci/<?= (int) $r['contact_id'] ?>">Kontakt i wiadomości</a><?php endif ?>
            <?= $post($id, 'status', 'new', 'Przywróć do odpowiedzi') ?>
            <?= $post($id, 'delete', '1', 'Usuń', 'btn ghost sm', 'Usunąć to zapytanie z rejestru?') ?>
          </div>
        </li>
      <?php endforeach ?>
    </ul>
  </section>
  <?php endif ?>
</div>
