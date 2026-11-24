<?php
/**
 * Dwie drogi do danych z Google: plik z eksportu (od razu) albo stałe połączenie (panel pobiera dane codziennie).
 * @var array $site
 * @var bool $googleOn czy panel ma klucz konta usługi Google
 * @var string $googleEmail adres konta usługi
 * @var ?array $syncGoogle wynik ostatniego pobrania
 * @var bool $ai czy pokazać też raport o generatywnej AI
 */
$base = '/audyt/widocznosc/' . (int) $site['id'];
$connected = (bool) ($syncGoogle['ok'] ?? false);
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
    <?php if ($googleOn): ?>
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
    <?php else: ?>
      <p class="small muted" style="margin:0">Stałe połączenie włącza administrator panelu jednym kluczem Google (zmienna <code>GOOGLE_SERVICE_ACCOUNT</code>). Potem wystarczy dodać jeden adres e-mail w Search Console i kliknąć „Połącz”. Do tego czasu działa wgrywanie pliku.</p>
    <?php endif ?>
  </div>
</div>
