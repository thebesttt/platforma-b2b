<?php
/**
 * Dwie drogi do danych z Google: plik z eksportu (od razu) albo stałe połączenie (panel pobiera dane codziennie).
 * @var array $site
 * @var array $conn stan połączeń firmy: mode (oauth = własne konto Google, service = klucz platformy, null = brak), oauth, google, owner, admin
 * @var string $googleEmail adres konta usługi (tryb service)
 * @var ?array $syncGoogle wynik ostatniego pobrania
 * @var bool $ai czy pokazać też raport o generatywnej AI
 */
$base = '/audyt/widocznosc/' . (int) $site['id'];
$conn = $conn ?? [];
$mode = $conn['mode'] ?? null;
// „Działa” pokazujemy tylko wtedy, gdy połączenie nadal istnieje i ostatnie pobranie się udało.
$connected = $mode !== null && (bool) ($syncGoogle['ok'] ?? false);
?>
<div class="start-grid">
  <div>
    <h3>Wgraj plik z Search Console</h3>
    <ol>
      <li>Otwórz w Search Console raport <a href="https://search.google.com/search-console/performance/search-analytics" target="_blank" rel="noopener noreferrer">Skuteczność</a><?php if (!empty($ai)): ?> albo <a href="https://search.google.com/search-console/performance/search-analytics/ai" target="_blank" rel="noopener noreferrer">raport o generatywnej AI</a><?php endif ?>.</li>
      <li>Kliknij „Eksportuj” i wybierz „Pobierz plik CSV”.</li>
      <li>Wgraj pobraną paczkę tutaj. Nie rozpakowuj jej i nic w niej nie zmieniaj.</li>
    </ol>
    <form method="post" action="<?= e($base) ?>/import" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="upload-row">
        <label class="sr-only" for="seo-files">Plik z eksportu Search Console</label>
        <input id="seo-files" type="file" name="files[]" accept=".zip,.csv" multiple required>
        <button class="btn" type="submit" data-busy="Wczytuję…">Wgraj</button>
      </div>
      <p class="small muted" style="margin:8px 0 0">Raport i okres panel rozpozna sam. Możesz wgrać kilka plików naraz.</p>
    </form>
  </div>
  <div>
    <h3><?= $connected ? 'Połączenie z Google działa' : 'Albo połącz na stałe' ?></h3>
    <?php if ($mode === 'oauth'): ?>
      <?php if ($connected): ?>
        <p class="small" style="margin:0 0 12px">Panel pobiera dane codziennie z połączonego konta Google. Ostatnio: <?= e(local_dt((string) $syncGoogle['at'])) ?> (<?= e((string) $syncGoogle['note']) ?>).</p>
      <?php else: ?>
        <p class="small" style="margin:0 0 12px">Konto Google jest połączone<?= !empty($conn['google']['created_at']) ? ' od ' . e(local_dt((string) $conn['google']['created_at'], 'd.m.Y')) : '' ?>. Kliknij „Pobierz dane”: panel sam znajdzie tę witrynę na połączonym koncie.</p>
        <?php if ($syncGoogle && !$syncGoogle['ok']): ?><p class="notice mid" style="margin:0 0 12px"><?= e((string) $syncGoogle['note']) ?></p><?php endif ?>
      <?php endif ?>
      <form method="post" action="<?= e($base) ?>/pobierz">
        <?= csrf_field() ?><input type="hidden" name="zrodlo" value="google">
        <button class="btn<?= $connected ? ' ghost' : '' ?>" type="submit" data-busy="Pobieram dane…"><?= $connected ? 'Pobierz teraz' : 'Pobierz dane' ?></button>
      </form>
      <?php if (!empty($conn['owner'])): ?>
      <details style="margin-top:12px">
        <summary class="small">Odłącz konto Google</summary>
        <form method="post" action="/audyt/widocznosc/google/odlacz" style="margin-top:8px">
          <?= csrf_field() ?><input type="hidden" name="witryna" value="<?= (int) $site['id'] ?>">
          <p class="small muted" style="margin:0 0 8px">Panel cofnie swój dostęp w Google i przestanie pobierać dane wszystkich witryn firmy. Dane wczytane do tej pory zostają.</p>
          <button class="btn ghost sm" type="submit">Odłącz</button>
        </form>
      </details>
      <?php endif ?>
    <?php elseif ($mode === 'service'): ?>
      <?php if ($connected): ?>
        <p class="small" style="margin:0 0 12px">Panel pobiera dane codziennie. Ostatnio: <?= e(local_dt((string) $syncGoogle['at'])) ?> (<?= e((string) $syncGoogle['note']) ?>).</p>
      <?php else: ?>
        <ol>
          <li>Skopiuj adres: <code class="copy wrap" title="Kliknij, żeby skopiować"><?= e($googleEmail) ?></code></li>
          <li>W Search Console wejdź w Ustawienia, potem „Użytkownicy i uprawnienia”, i dodaj ten adres jako użytkownika z uprawnieniami „Ograniczone”.</li>
          <li>Kliknij „Połącz”. Panel sam znajdzie witrynę i od tej pory pobiera dane codziennie.</li>
        </ol>
        <?php if ($syncGoogle && !$syncGoogle['ok']): ?><p class="notice mid" style="margin:0 0 12px"><?= e((string) $syncGoogle['note']) ?></p><?php endif ?>
      <?php endif ?>
      <form method="post" action="<?= e($base) ?>/pobierz">
        <?= csrf_field() ?><input type="hidden" name="zrodlo" value="google">
        <button class="btn<?= $connected ? ' ghost' : '' ?>" type="submit" data-busy="Pobieram dane…"><?= $connected ? 'Pobierz teraz' : 'Połącz' ?></button>
      </form>
      <p class="small muted" style="margin:8px 0 0">Ten sam adres dodany w Google Analytics (rola „Wyświetlający”) pokaże wejścia z ChatGPT i innych asystentów.</p>
    <?php elseif (!empty($conn['oauth'])): ?>
      <?php if (!empty($conn['owner'])): ?>
        <ol>
          <li>Kliknij „Połącz z Google” i wybierz konto, które ma dostęp do tej witryny w Search Console.</li>
          <li>Zaznacz zgodę na odczyt Search Console. Zgoda na odczyt Analytics pokaże też wejścia z ChatGPT i innych asystentów.</li>
          <li>Po powrocie panel sam znajdzie witrynę i od tej pory pobiera dane codziennie.</li>
        </ol>
        <a class="btn" href="/audyt/widocznosc/google/polacz?witryna=<?= (int) $site['id'] ?>">Połącz z Google</a>
        <p class="small muted" style="margin:8px 0 0">Panel prosi tylko o odczyt i niczego nie zmienia w koncie Google. Dostęp cofniesz tu jednym kliknięciem.</p>
      <?php else: ?>
        <p class="small muted" style="margin:0">Konto Google łączy właściciel konta firmy w tym panelu. Do tego czasu działa wgrywanie pliku.</p>
      <?php endif ?>
    <?php elseif (!empty($conn['admin'])): ?>
      <p class="small muted" style="margin:0">Stałe połączenie włączasz jako administrator panelu. Dla firm klientów: klient OAuth z Google Cloud w zmiennych <code>GOOGLE_OAUTH_CLIENT_ID</code> i <code>GOOGLE_OAUTH_CLIENT_SECRET</code>, wtedy każda firma łączy własne konto Google jednym kliknięciem. Dla własnych witryn wystarczy klucz konta usługi w <code>GOOGLE_SERVICE_ACCOUNT</code>. Do tego czasu działa wgrywanie pliku.</p>
    <?php else: ?>
      <p class="small muted" style="margin:0">Stałe połączenie z Google nie jest jeszcze włączone w tym panelu. Wgrany plik daje te same dane: wystarczy go odświeżać raz w miesiącu.</p>
    <?php endif ?>
  </div>
</div>
