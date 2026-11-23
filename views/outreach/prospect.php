<?php
use App\Core\Nip;
use App\Modules\Outreach\Prospects;
use App\Modules\Tenders\Regions;
/**
 * @var array $p @var ?array $company @var ?array $contact @var array $facts @var array $score @var string[] $reasons
 * @var array $paths drogi kontaktu z oceną ryzyka @var ?int $infoDays @var array $criteria
 */
$id = (int) $p['id'];
$levels = ['low' => ['low', 'niskie ryzyko'], 'very_high' => ['high', 'bardzo wysokie ryzyko'], 'check' => ['mid', 'do potwierdzenia'], 'blocked' => ['high', 'zablokowane'], 'none' => ['neutral', 'brak danych']];
$state = static fn (string $value, string $label, string $class = 'btn ghost', string $confirm = ''): string =>
    '<form class="inline" method="post" action="/klienci/potencjalni/' . $id . '/stan"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field()
    . '<input type="hidden" name="' . ($value === 'delete' ? 'delete' : 'status') . '" value="' . ($value === 'delete' ? '1' : e($value)) . '"><button class="' . $class . '" type="submit">' . e($label) . '</button></form>';
$site = $facts['site'] ?? null;
$wins = $facts['wins'] ?? null;
$sub = array_filter([!empty($p['nip']) ? 'NIP ' . Nip::format((string) $p['nip']) : null, $company['address'] ?? $p['city'], Regions::name($p['voivodeship']) ?: null]);
$objected = $p['status'] === 'objected';
$hasConsent = (bool) array_filter($paths, static fn ($x) => $x['key'] === 'consent');
$openConsent = false;
?>
<header class="between page-head">
  <div class="stack-sm">
    <p class="crumbs"><a href="/klienci">Klienci i wiadomości</a> / <a href="/klienci/potencjalni">Potencjalni klienci</a></p>
    <div class="row-flex">
      <span class="chip <?= $objected ? 'high' : ($p['status'] === 'contacted' ? 'low' : 'neutral') ?>"><?= e(Prospects::STATUSES[$p['status']] ?? $p['status']) ?></span>
      <span class="chip <?= score_class((int) $p['score']) ?>">Dopasowanie <?= (int) $p['score'] ?>/100</span>
      <span class="chip neutral">źródło: <?= e(Prospects::SOURCES[$p['source']] ?? $p['source']) ?></span>
      <?php if (Prospects::isPerson($company)): ?><span class="chip info">osoba prowadząca działalność</span><?php endif ?>
    </div>
    <h1><?= e($p['name']) ?></h1>
    <?php if ($sub): ?><p class="muted small" style="margin:0"><?= e(implode(' · ', $sub)) ?></p><?php endif ?>
  </div>
  <div class="row-flex">
    <?php if ($p['status'] === 'new'): ?><?= $state('picked', 'Wybierz', 'btn') ?><?= $state('skipped', 'Pomiń') ?>
    <?php elseif ($p['status'] === 'picked'): ?><?= $state('new', 'Cofnij wybór') ?><?= $state('skipped', 'Pomiń') ?>
    <?php elseif ($p['status'] === 'skipped'): ?><?= $state('new', 'Przywróć do oceny') ?>
    <?php elseif ($p['status'] === 'contacted'): ?><?= $state('picked', 'Wróć do wybranych') ?><?php endif ?>
    <?php if (!empty($p['nip'])): ?><a class="btn ghost" href="/kontrahenci/<?= e($p['nip']) ?>">Karta firmy z rejestrów</a><?php endif ?>
  </div>
</header>

<?php if (!empty($facts['consent']) && !$objected && $p['status'] !== 'contacted'): ?>
  <div class="notice low" style="margin-bottom:16px">Firma zostawiła zgodę na ofertę e-mailem <?= e(local_dt($facts['consent']['at'])) ?>. <a href="#droga-consent">Przygotuj ofertę</a>.</div>
<?php endif ?>
<?php if ($infoDays !== null): ?>
  <div class="notice <?= $infoDays <= 5 ? 'mid' : 'info' ?>" style="margin-bottom:16px">
    To osoba prowadząca działalność, więc jej dane chroni RODO. Masz obowiązek poinformować ją, skąd masz dane, najpóźniej w ciągu miesiąca od dodania do listy albo przy pierwszym kontakcie.
    <?= $infoDays > 0 ? 'Zostało ' . $infoDays . ' ' . plural($infoDays, 'dzień', 'dni', 'dni') . '.' : 'Termin minął.' ?> List z panelu zawiera tę informację. Jeśli nie zamierzasz pisać, usuń firmę z listy.
  </div>
<?php endif ?>
<?php if (!empty($facts['registers_error'])): ?>
  <div class="notice mid" style="margin-bottom:16px">Nie mamy danych z rejestrów: <?= e($facts['registers_error']) ?></div>
<?php endif ?>

<div class="cols">
  <div class="main stack">
    <section class="card" aria-labelledby="t-why">
      <h2 id="t-why">Dlaczego ta firma</h2>
      <?php if ($reasons): ?>
        <ul class="law" style="margin-top:10px"><?php foreach ($reasons as $line): ?><li><?= e($line) ?></li><?php endforeach ?></ul>
      <?php else: ?>
        <p class="small muted" style="margin:8px 0 0">Na razie wiemy o tej firmie niewiele. Dopisz NIP albo adres strony i sprawdź stronę: panel zbierze fakty, na których oprzesz ofertę.</p>
      <?php endif ?>
      <?php if ($wins && !empty($wins['rows'])): ?>
        <h3 style="margin:16px 0 0">Ostatnie wygrane zamówienia</h3>
        <?php foreach ($wins['rows'] as $w): ?>
          <div class="kv-row"><div><p class="v"><?= e($w['title']) ?></p><p class="k" style="margin:4px 0 0"><?= e($w['buyer']) ?></p></div>
            <span class="small nowrap"><?= !empty($w['value']) ? e(money_pl((float) $w['value'])) . ', ' : '' ?><?= e(date('d.m.Y', strtotime((string) $w['date']))) ?></span></div>
        <?php endforeach ?>
      <?php endif ?>
    </section>

    <?php if (!$objected): ?>
    <section class="card" id="oferta" aria-labelledby="t-pitch">
      <div class="between" style="align-items:flex-start;gap:8px 12px">
        <h2 id="t-pitch">Treść oferty</h2>
        <span class="chip <?= $pitch['by'] === 'ai' ? 'mid' : 'neutral' ?>"><?= ['rules' => 'ułożona z faktów', 'ai' => 'napisana przez AI: sprawdź fakty', 'user' => 'poprawiona przez Ciebie'][$pitch['by']] ?? 'zapisana' ?></span>
      </div>
      <p class="small muted" style="margin:4px 0 12px">Panel układa ją sam z faktów o firmie i z Twojej biblioteki. Napisanie oferty nie wymaga zgody firmy. Ta sama treść trafi do listu i do wiadomości e-mail.</p>
      <form method="post" action="/klienci/potencjalni/<?= $id ?>/oferta">
        <?= csrf_field() ?>
        <label class="sr-only" for="pp-text">Treść oferty</label>
        <textarea id="pp-text" name="text" rows="7" maxlength="3000" required><?= e($pitch['text']) ?></textarea>
        <?php if (!$hasLibrary): ?><p class="small muted" style="margin:8px 0 0">Dodaj w <a href="/biblioteka">Bibliotece firmy</a> krótki opis tego, co robicie. Panel wstawi go do każdej oferty zamiast nawiasu kwadratowego.</p><?php endif ?>
        <div class="row-flex" style="margin-top:12px">
          <button class="btn ghost" type="submit" name="action" value="save">Zapisz treść</button>
          <?php if ($aiEnabled): ?><button class="btn ghost" type="submit" name="action" value="ai" formnovalidate data-busy="AI pisze…">Szkic AI</button><?php endif ?>
          <?php if ($pitch['by'] !== 'rules'): ?><button class="btn ghost" type="submit" name="action" value="reset" formnovalidate>Ułóż od nowa z faktów</button><?php endif ?>
        </div>
      </form>
    </section>
    <?php endif ?>

    <section class="card" id="kontakt" aria-labelledby="t-paths">
      <h2 id="t-paths">Jak możesz się odezwać</h2>
      <p class="small muted" style="margin:4px 0 0">Prowadzenie listy i napisanie oferty nie wymagają zgody firmy. Zgody wymaga wysłanie oferty e-mailem albo telefon z ofertą, także do firm. Poniżej są wszystkie drogi, od najmniej ryzykownej. Wysyłasz sam, ze swojej skrzynki albo pocztą.</p>
      <?php foreach ($paths as $path): [$chip, $label] = $levels[$path['level']] ?? $levels['none']; ?>
        <div class="src-step" id="droga-<?= e($path['key']) ?>" style="margin-top:14px">
          <div class="between" style="align-items:flex-start;gap:8px 12px"><h3 style="margin:0"><?= e($path['title']) ?></h3><span class="chip <?= $chip ?>"><?= e($label) ?></span></div>
          <p class="small" style="margin:8px 0 0"><?= e($path['text']) ?></p>
          <?php if (!empty($path['invite'])): $i = $path['invite']; ?>
            <blockquote class="quote small" style="margin:10px 0 0">„<?= e($i['quote']) ?>”<br><a href="<?= e(safe_url($i['url'])) ?>" target="_blank" rel="noopener noreferrer"><?= e(truncate((string) $i['url'], 70)) ?></a></blockquote>
          <?php endif ?>
          <?php if ($path['notes']): ?><ul class="law small" style="margin-top:8px"><?php foreach ($path['notes'] as $n): ?><li><?= e($n) ?></li><?php endforeach ?></ul><?php endif ?>
          <?php if ($path['law']): ?><details class="small" style="margin-top:8px"><summary>Podstawa prawna</summary><ul class="law" style="margin-top:8px"><?php foreach ($path['law'] as $l): ?><li><?= e($l) ?></li><?php endforeach ?></ul></details><?php endif ?>

          <?php if ($path['key'] === 'rfq'): ?>
            <div class="item-actions"><a class="btn sm" href="/klienci/zapytania#z<?= (int) $path['rfq_id'] ?>">Otwórz zapytanie</a></div>
          <?php elseif ($path['key'] === 'consent'): $ev = $path['evidence'] ?? null; ?>
            <?php if ($ev): ?>
              <blockquote class="quote small" style="margin:10px 0 0">
                <?= $ev['source'] === 'page' ? '„' . e($ev['wording']) . '”' : e($ev['wording']) ?><br>
                <span class="muted"><?= $ev['source'] === 'page' ? 'Strona zgody z listu' : 'Zapisane ręcznie' ?>, <?= e(local_dt($ev['created_at'])) ?><?= !empty($ev['person']) ? ', ' . e($ev['person']) : '' ?><?= !empty($ev['phone']) ? ', zgoda także na telefon: ' . e($ev['phone']) : '' ?></span>
              </blockquote>
            <?php endif ?>
            <div class="item-actions">
              <a class="btn sm" href="/klienci/<?= (int) $contact['id'] ?>/wiadomosc?kind=consented">Przygotuj ofertę</a>
              <?php if ($ev): ?><form class="inline" method="post" action="/klienci/potencjalni/<?= $id ?>/zgoda" data-confirm="Wycofać zgodę? Oferta e-mailem do tej firmy straci podstawę."><?= csrf_field() ?><input type="hidden" name="withdraw" value="1"><button class="btn ghost sm" type="submit">Firma wycofała zgodę</button></form><?php endif ?>
            </div>
          <?php elseif ($path['key'] === 'invite'): ?>
            <div class="item-actions">
              <form class="inline" method="post" action="/klienci/potencjalni/<?= $id ?>/kontakt"><?= csrf_field() ?><input type="hidden" name="email" value="<?= e($path['invite']['email']) ?>"><button class="btn sm" type="submit">Przygotuj ofertę na <?= e($path['invite']['email']) ?></button></form>
              <form class="inline" method="post" action="/klienci/potencjalni/<?= $id ?>/zaproszenie" data-confirm="Usunąć zaproszenie z karty firmy?"><?= csrf_field() ?><input type="hidden" name="drop" value="1"><button class="btn ghost sm" type="submit">To nie jest zaproszenie</button></form>
            </div>
          <?php elseif ($path['key'] === 'invite_found'): ?>
            <form method="post" action="/klienci/potencjalni/<?= $id ?>/zaproszenie" style="margin-top:6px">
              <?= csrf_field() ?>
              <label class="check"><input type="checkbox" name="confirm" value="1" required> Przeczytałem to zdanie na stronie firmy. Firma prosi w nim o przesyłanie ofert takich jak moja na adres <?= e($path['invite']['email']) ?>.</label>
              <div class="row-flex"><button class="btn sm" type="submit">Potwierdzam zaproszenie</button><button class="btn ghost sm" type="submit" name="drop" value="1" formnovalidate>To nie jest zaproszenie</button></div>
            </form>
          <?php elseif ($path['key'] === 'letter' && $path['level'] === 'low'): ?>
            <?php if (!empty($path['consent_url'])): ?>
              <p class="small" style="margin:10px 0 0">Strona zgody tej firmy: <code class="copy wrap" title="Kliknij, żeby skopiować"><?= e($path['consent_url']) ?></code></p>
            <?php endif ?>
            <div class="item-actions">
              <a class="btn sm" href="/klienci/potencjalni/<?= $id ?>/list">Przygotuj list</a>
              <?php if (!empty($path['consent_url'])): ?><a class="btn ghost sm" href="<?= e($path['consent_url']) ?>" target="_blank" rel="noopener noreferrer">Zobacz stronę zgody</a><?php endif ?>
            </div>
          <?php elseif ($path['key'] === 'email'): ?>
            <form method="post" action="/klienci/potencjalni/<?= $id ?>/kontakt" class="form-grid two" style="margin-top:10px">
              <?= csrf_field() ?>
              <div class="field"><label for="pe-email">Adres e-mail firmy</label><input id="pe-email" name="email" type="email" maxlength="200" required><span class="hint">Wpisz go sam, np. ze strony kontaktowej firmy. Panel nie zbiera adresów e-mail ze stron.</span></div>
              <div class="field"><label for="pe-person">Osoba, opcjonalnie</label><input id="pe-person" name="person" type="text" maxlength="120"></div>
              <div class="field" style="grid-column:1/-1"><button class="btn ghost sm" type="submit">Przygotuj wiadomość i pokaż ryzyko</button></div>
            </form>
          <?php endif ?>
        </div>
      <?php endforeach ?>

      <?php if (!$objected && empty($facts['invite']) && empty($facts['invite_found'])): ?>
        <details class="small" style="margin-top:14px">
          <summary>Firma zaprasza do przesyłania ofert, a panel tego nie znalazł? Wpisz zaproszenie</summary>
          <form method="post" action="/klienci/potencjalni/<?= $id ?>/zaproszenie" class="form-grid two" style="margin-top:10px">
            <?= csrf_field() ?><input type="hidden" name="manual" value="1">
            <div class="field" style="grid-column:1/-1"><label for="pi-quote">Zdanie ze strony firmy</label><textarea id="pi-quote" name="quote" rows="2" maxlength="400" required placeholder="np. Oferty handlowe prosimy kierować na adres oferty@firma.pl"></textarea></div>
            <div class="field"><label for="pi-url">Link do tej strony</label><input id="pi-url" name="url" type="url" maxlength="500" required placeholder="https://"></div>
            <div class="field"><label for="pi-email">Adres e-mail z zaproszenia</label><input id="pi-email" name="email" type="email" maxlength="200" required></div>
            <div class="field" style="grid-column:1/-1"><label class="check"><input type="checkbox" name="confirm" value="1" required> Firma prosi w tym zdaniu o przesyłanie ofert takich jak moja.</label><button class="btn ghost sm" type="submit">Zapisz zaproszenie</button></div>
          </form>
        </details>
      <?php endif ?>
      <?php if (!$objected && !$hasConsent): ?>
        <details class="small" style="margin-top:14px"<?= $openConsent ? ' open' : '' ?> id="zgoda-reczna">
          <summary>Firma zgodziła się inaczej, np. odpisała na list albo poprosiła o ofertę w rozmowie? Zapisz zgodę</summary>
          <p class="muted" style="margin:10px 0 0">Zapisz tylko zgodę, którą firma naprawdę wyraziła i którą potrafisz wykazać: odpowiedź na list, notatkę ze spotkania, wiadomość od firmy. Sama wizytówka albo adres ze strony internetowej nie są zgodą.</p>
          <form method="post" action="/klienci/potencjalni/<?= $id ?>/zgoda" class="form-grid two" style="margin-top:10px">
            <?= csrf_field() ?>
            <div class="field"><label for="pz-email">Adres e-mail, na który firma chce ofertę</label><input id="pz-email" name="email" type="email" maxlength="200" required></div>
            <div class="field"><label for="pz-person">Osoba, opcjonalnie</label><input id="pz-person" name="person" type="text" maxlength="120"></div>
            <div class="field"><label for="pz-way">Jak firma wyraziła zgodę</label>
              <select id="pz-way" name="way" required><option value="">Wybierz</option><?php foreach (App\Modules\Outreach\Consents::MANUAL_WAYS as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach ?></select></div>
            <div class="field"><label for="pz-on">Kiedy</label><input id="pz-on" name="on" type="date" max="<?= e(today_pl()) ?>" value="<?= e(today_pl()) ?>" required></div>
            <div class="field" style="grid-column:1/-1"><label for="pz-detail">Kto i co dokładnie powiedział albo napisał</label><textarea id="pz-detail" name="detail" rows="2" maxlength="500" required minlength="10" placeholder="np. Pani Anna Nowak, kierownik zakupów, odpisała na list: proszę o ofertę na adres zakupy@firma.pl"></textarea><span class="hint">To Twój dowód w razie sporu. Zachowaj też oryginał: list, notatkę albo wiadomość.</span></div>
            <div class="field" style="grid-column:1/-1"><label class="check"><input type="checkbox" name="confirm" value="1" required> Firma wyraziła tę zgodę i potrafię to wykazać.</label><button class="btn ghost sm" type="submit">Zapisz zgodę</button></div>
          </form>
        </details>
      <?php endif ?>
      <?php if (!$objected): ?>
        <div class="small muted row-flex" style="margin:16px 0 0"><span>Firma odpisała, że nie chce korespondencji?</span> <?= $state('objected', 'Zapisz sprzeciw', 'btn danger sm', 'Zapisać sprzeciw? Panel nie przygotuje już dla tej firmy listu ani oferty.') ?></div>
      <?php endif ?>
      <p class="xs muted" style="margin:12px 0 0">To informacja o przepisach, a nie porada prawna. W razie wątpliwości zapytaj prawnika.</p>
    </section>

    <section class="card" id="strona" aria-labelledby="t-site">
      <h2 id="t-site">Strona firmy</h2>
      <?php if ($site && !empty($site['ok'])): ?>
        <p class="small muted" style="margin:4px 0 10px">Sprawdzona <?= e(local_dt($site['checked_at'])) ?>: <a href="<?= e(safe_url($site['url'])) ?>" target="_blank" rel="noopener noreferrer"><?= e(truncate((string) $site['url'], 60)) ?></a>. To pierwsze spojrzenie na stronę główną, nie pełny audyt.</p>
        <?php if ($site['findings']): ?>
          <ul class="law"><?php foreach ($site['findings'] as $f): ?><li><?= e($f) ?></li><?php endforeach ?></ul>
        <?php else: ?>
          <p class="small" style="margin:0">Na stronie głównej nie widać podstawowych braków.</p>
        <?php endif ?>
      <?php elseif ($site): ?>
        <p class="small" style="margin:8px 0 0;color:var(--high)"><?= e($site['error'] ?? 'Nie udało się sprawdzić strony.') ?></p>
      <?php else: ?>
        <p class="small muted" style="margin:4px 0 0">Panel otworzy stronę główną i podstrony o kontakcie albo współpracy. Sprawdzi podstawowe rzeczy widoczne dla wyszukiwarek i poszuka zdania, w którym firma zaprasza do przesyłania ofert.</p>
      <?php endif ?>
      <form method="post" action="/klienci/potencjalni/<?= $id ?>/strona" class="row-flex" style="margin-top:12px;align-items:flex-end">
        <?= csrf_field() ?>
        <div class="field" style="flex:1 1 240px;min-width:0"><label for="ps-web">Adres strony<?= !empty($facts['website_from']) ? ' (z ' . e($facts['website_from']) . ')' : '' ?></label><input id="ps-web" name="website" type="text" maxlength="300" placeholder="firma.pl" value="<?= e((string) ($p['website'] ?? '')) ?>" required></div>
        <button class="btn ghost" type="submit" data-busy="Sprawdzam stronę…"><?= $site ? 'Sprawdź ponownie' : 'Sprawdź stronę' ?></button>
      </form>
      <?php if (!empty($p['website'])): ?><p class="xs muted" style="margin:10px 0 0">Pełny audyt techniczny tej strony uruchomisz w zakładce <a href="/audyt">Audyt</a>.</p><?php endif ?>
    </section>
  </div>

  <div class="side stack">
    <section class="card" aria-labelledby="t-score">
      <h2 id="t-score">Dopasowanie: <?= (int) $p['score'] ?> na 100</h2>
      <?php foreach ($score as $row): ?>
        <div class="kv-row"><div><p class="v" style="font-weight:600"><?= e($row['label']) ?></p><p class="k" style="margin:2px 0 0"><?= e($row['note']) ?></p></div><span class="small nowrap"><?= (int) $row['points'] ?> z <?= (int) $row['max'] ?></span></div>
      <?php endforeach ?>
      <p class="xs muted" style="margin:10px 0 0">Punkty wynikają z ustawień <a href="/klienci/potencjalni#kryteria">Kogo szukasz</a>.</p>
    </section>

    <section class="card" aria-labelledby="t-data">
      <h2 id="t-data">Dane i notatka</h2>
      <form method="post" action="/klienci/potencjalni/<?= $id ?>" class="stack-sm" style="margin-top:10px">
        <?= csrf_field() ?>
        <div class="field"><label for="pd-nip">NIP</label><input id="pd-nip" name="nip" type="text" inputmode="numeric" maxlength="20" value="<?= e((string) ($p['nip'] ?? '')) ?>"><span class="hint">Z NIP-em panel pobierze dane z wykazu VAT i KRS.</span></div>
        <div class="field"><label for="pd-web">Strona</label><input id="pd-web" name="website" type="text" maxlength="300" value="<?= e((string) ($p['website'] ?? '')) ?>"></div>
        <div class="field"><label for="pd-notes">Notatka</label><textarea id="pd-notes" name="notes" rows="3" maxlength="2000"><?= e((string) ($p['notes'] ?? '')) ?></textarea></div>
        <button class="btn ghost" type="submit">Zapisz</button>
      </form>
      <?php if ($contact): ?><p class="small" style="margin:12px 0 0">Kontakt: <a href="/klienci/<?= (int) $contact['id'] ?>"><?= e($contact['email']) ?></a></p><?php endif ?>
      <div style="margin:14px 0 0"><?= $state('delete', 'Usuń z listy', 'btn danger sm', 'Usunąć firmę z listy potencjalnych klientów?') ?></div>
    </section>
  </div>
</div>
