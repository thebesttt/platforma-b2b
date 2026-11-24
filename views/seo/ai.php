<?php
use App\Core\View;
use App\Modules\Seo\CloudflareBots;
use App\Modules\Seo\Sync;

$base = '/audyt/widocznosc/' . (int) $site['id'];
$pos = static fn (?float $p): string => $p === null ? '-' : num_pl($p, 1);
$g = $ai['google'];
$c = $ai['copilot'];
$v = $ai['visits'];
echo View::partial('partials/seo_nav', ['site' => $site, 'tab' => $tab, 'sites' => $sites, 'heading' => 'Widoczność w odpowiedziach AI',
    'lead' => 'Gdzie AI pokazuje i czyta stronę ' . $site['host'] . '. Każde źródło mierzy coś innego, więc liczb nie sumujemy.']);
?>
<section class="card flush" aria-labelledby="sa-g">
  <div class="card-head"><h2 id="sa-g">Google: Przeglądy od AI i Tryb AI</h2><?php if ($g['cur']): ?><span class="small muted"><?= e(Sync::range($g['cur']['start'], $g['cur']['end'])) ?></span><?php endif ?></div>
  <?php if (!$g['cur']): ?>
    <div class="card-body">
      <p class="small" style="margin:0 0 14px">Search Console ma osobny raport: ile razy link do Twojej strony pojawił się w odpowiedzi AI Google. Podaje same wyświetlenia, bez kliknięć i bez zapytań. Nie ma go w API, więc wgrywa się eksport.</p>
      <ol class="law">
        <li>Otwórz raport <a href="https://search.google.com/search-console/performance/search-analytics/ai" target="_blank" rel="noopener noreferrer">Funkcje generatywnej AI</a> w Search Console.</li>
        <li>Kliknij „Eksportuj” i „Pobierz plik CSV”.</li>
        <li>Wgraj paczkę tutaj. Panel sam rozpozna, że to ten raport.</li>
      </ol>
      <form method="post" action="<?= e($base) ?>/import" enctype="multipart/form-data" style="margin-top:12px">
        <?= csrf_field() ?>
        <div class="upload-row"><label class="sr-only" for="sa-files">Plik z eksportu raportu AI</label><input id="sa-files" type="file" name="files[]" accept=".zip,.csv" multiple required><button class="btn" type="submit" data-busy="Wczytuję…">Wgraj</button></div>
      </form>
      <p class="small muted" style="margin:10px 0 0">Raportu nie widać w Search Console, gdy strona ma za mało wyświetleń w odpowiedziach AI.</p>
    </div>
  <?php else: ?>
    <div class="card-body">
      <p style="margin:0 0 4px"><span class="stat-value" style="margin:0"><?= num_pl($g['total']) ?></span> <?= plural((int) $g['total'], 'wyświetlenie', 'wyświetlenia', 'wyświetleń') ?> linków do witryny w odpowiedziach AI <?= delta_html($g['total'], $g['prevTotal'] !== null && $g['prev'] ? $g['prevTotal'] * $g['cur']['days'] / $g['prev']['days'] : null) ?></p>
      <p class="small muted" style="margin:0"><?= $g['totalPartial'] ? 'Suma z wierszy tabeli stron. ' : '' ?>To liczba pokazań linku, a nie wejść: raport Google nie podaje kliknięć ani zapytań.</p>
      <?php if (count($aiWeekly) >= 3): ?><?= View::partial('partials/line_chart', ['points' => $aiWeekly, 'name' => 'Wyświetlenia w odpowiedziach AI', 'unit' => 'wyświetleń', 'prefix' => 'Tydzień od ']) ?><?php endif ?>
    </div>
    <?php if ($g['pages']): ?>
    <div class="table-wrap"><table>
      <thead><tr><th scope="col">Strona pokazywana w odpowiedziach AI</th><th scope="col" class="n">Wyświetlenia w AI</th><th scope="col" class="n">Kliknięcia z wyników</th><th scope="col" class="n">Pozycja</th></tr></thead>
      <tbody>
      <?php foreach ($g['pages'] as $p): ?>
        <tr><td class="cell-url"><a href="<?= e(safe_url($p['k'])) ?>" target="_blank" rel="noopener noreferrer"><?= e($p['path']) ?></a></td><td class="n"><b><?= num_pl($p['impressions']) ?></b></td>
          <td class="n"><?= $p['organic'] ? num_pl($p['organic']['clicks']) : '<span class="muted">-</span>' ?></td><td class="n"><?= $p['organic'] ? $pos($p['organic']['position']) : '<span class="muted">-</span>' ?></td></tr>
      <?php endforeach ?>
      </tbody>
    </table></div>
    <p class="sec-note" style="padding-top:12px"><?= $g['pagesCount'] > count($g['pages']) ? 'Pokazujemy ' . count($g['pages']) . ' z ' . (int) $g['pagesCount'] . ' stron. ' : '' ?><?= $g['organicPeriod'] ? 'Kliknięcia i pozycja pochodzą z raportu „Skuteczność” za okres ' . e(Sync::range($g['organicPeriod']['start'], $g['organicPeriod']['end'])) . '.' : 'Wgraj też raport „Skuteczność”, żeby zobaczyć obok kliknięcia tych stron.' ?></p>
    <?php endif ?>
    <?php if ($g['missing']): ?>
    <div class="card-body" style="border-top:1px solid var(--line-soft);padding-top:16px">
      <h3 style="margin-bottom:6px">Strony z kliknięciami, których AI nie pokazuje</h3>
      <p class="small muted" style="margin:0 0 10px">Mają ruch z wyników, a w odpowiedziach AI ich nie ma. Od nich warto zacząć <a href="/audyt/tresc">Audyt AI</a>: krótka odpowiedź na początku, dane strukturalne, źródła i data.</p>
      <ul class="law"><?php foreach ($g['missing'] as $p): ?><li><a href="<?= e(safe_url($p['k'])) ?>" target="_blank" rel="noopener noreferrer"><?= e($p['path']) ?></a> <span class="muted">(<?= num_pl($p['clicks']) ?> <?= plural((int) $p['clicks'], 'kliknięcie', 'kliknięcia', 'kliknięć') ?>)</span></li><?php endforeach ?></ul>
    </div>
    <?php endif ?>
  <?php endif ?>
</section>

<section class="card flush" aria-labelledby="sa-q">
  <div class="card-head"><h2 id="sa-q">Co widać w zapytaniach</h2><?php if ($gsc['cur']): ?><span class="small muted"><?= e(Sync::range($gsc['cur']['start'], $gsc['cur']['end'])) ?></span><?php endif ?></div>
  <?php if (!$gsc['cur']): ?>
    <p class="sec-note">Tu pojawią się pytania, na które ludzie trafiają do Ciebie, i zapytania, w które przestali klikać. Potrzebne są dane z raportu „Skuteczność”: <a href="<?= e($base) ?>">podłącz Search Console</a>.</p>
  <?php else: ?>
    <p class="sec-note">Pytania i długie zapytania (od 6 słów): tak ludzie rozmawiają z wyszukiwarką i z asystentem AI. <?= $gsc['questionShare'] !== null ? 'To <b>' . pct_pl($gsc['questionShare'], 0) . '</b> wyświetleń Twoich zapytań bez nazwy marki i ukrytych.' : '' ?> Strona, która odpowiada na takie pytanie jednym akapitem na początku, ma większą szansę trafić do odpowiedzi AI.</p>
    <?php if ($gsc['questions']): ?>
    <div class="table-wrap"><table>
      <thead><tr><th scope="col">Pytanie albo długie zapytanie</th><th scope="col" class="n">Wyświetlenia</th><th scope="col" class="n">Kliknięcia</th><th scope="col" class="n">Pozycja</th></tr></thead>
      <tbody><?php foreach ($gsc['questions'] as $q): ?><tr><td><?= e($q['k']) ?></td><td class="n"><?= num_pl($q['impressions']) ?></td><td class="n"><?= num_pl($q['clicks']) ?></td><td class="n"><?= $pos($q['position']) ?></td></tr><?php endforeach ?></tbody>
    </table></div>
    <?php endif ?>
    <div class="card-body" style="border-top:1px solid var(--line-soft);padding-top:16px">
      <h3 style="margin-bottom:6px">Te same wyświetlenia, mniej kliknięć</h3>
      <?php if ($gsc['squeeze'] === null): ?>
        <p class="small muted" style="margin:0">Do tego zestawienia potrzebne są dwa okresy. <a href="<?= e($base) ?>/dane">Połącz konto</a> albo wgraj eksport wcześniejszego okresu.</p>
      <?php elseif (!$gsc['squeeze']): ?>
        <p class="small muted" style="margin:0">Nie ma zapytań, przy których pozycja i wyświetlenia się utrzymały, a klikalność spadła o 40% lub więcej.</p>
      <?php else: ?>
        <p class="small muted" style="margin:0 0 10px">Pozycja się nie zmieniła, ludzie nadal widzą link, ale klikają o 40% rzadziej albo jeszcze mniej. Tak wygląda zapytanie, nad którego wynikami pojawiła się odpowiedź AI, reklama albo mapa. Sprawdź to, wpisując zapytanie w Google.</p>
      <?php endif ?>
    </div>
    <?php if ($gsc['squeeze']): ?>
    <div class="table-wrap"><table>
      <thead><tr><th scope="col">Zapytanie</th><th scope="col" class="n">Pozycja</th><th scope="col" class="n">Klikalność</th><th scope="col" class="n">Była</th><th scope="col" class="n">Stracone kliknięcia</th></tr></thead>
      <tbody><?php foreach ($gsc['squeeze'] as $q): ?><tr><td><a href="https://www.google.com/search?q=<?= e(rawurlencode($q['k'])) ?>" target="_blank" rel="noopener noreferrer"><?= e($q['k']) ?></a><?= $q['question'] ? ' <span class="chip info">pytanie</span>' : '' ?></td><td class="n"><?= $pos($q['position']) ?></td><td class="n"><b><?= pct_pl($q['ctr']) ?></b></td><td class="n"><?= pct_pl($q['wasCtr']) ?></td><td class="n"><?= num_pl($q['lost']) ?></td></tr><?php endforeach ?></tbody>
    </table></div>
    <?php endif ?>
  <?php endif ?>
</section>

<section class="card flush" aria-labelledby="sa-b">
  <div class="card-head"><h2 id="sa-b">Copilot i Bing</h2><?php if ($c['cur']): ?><span class="small muted"><?= e(Sync::range($c['cur']['start'], $c['cur']['end'])) ?></span><?php endif ?></div>
  <?php if (!$c['cur']): ?>
    <div class="card-body">
      <p class="small" style="margin:0 0 12px">Bing Webmaster Tools pokazuje w raporcie „AI Performance”, ile razy Copilot i podsumowania AI w Bingu podały Twoją stronę jako źródło oraz jakimi frazami AI szukało treści. Raport jest bezpłatny i nie ma API.</p>
      <ol class="law">
        <li>Zaloguj się w <a href="https://www.bing.com/webmasters" target="_blank" rel="noopener noreferrer">Bing Webmaster Tools</a>. Witrynę dodasz jednym kliknięciem przez import z Search Console.</li>
        <li>Otwórz raport „AI Performance” i zaznacz myszą tabelę stron albo fraz.</li>
        <li>Wklej ją w zakładce <a href="<?= e($base) ?>/dane#wklej">Dane i połączenia</a>.</li>
      </ol>
    </div>
  <?php else: ?>
    <div class="card-body">
      <p style="margin:0 0 4px"><span class="stat-value" style="margin:0"><?= num_pl($c['total']) ?></span> <?= plural((int) $c['total'], 'cytowanie', 'cytowania', 'cytowań') ?> <?= delta_html($c['total'], $c['prevTotal'] !== null && $c['prev'] ? $c['prevTotal'] * $c['cur']['days'] / $c['prev']['days'] : null) ?></p>
      <p class="small muted" style="margin:0"><?= $c['totalPartial'] ? 'Suma z wklejonych wierszy. ' : '' ?>Cytowanie to użycie strony jako źródła odpowiedzi. Raport nie mówi, w którym miejscu odpowiedzi ani czy ktoś kliknął.</p>
    </div>
    <div class="cols" style="gap:0">
      <?php if ($c['pages']): ?>
      <div class="main" style="flex-basis:320px"><div class="table-wrap"><table>
        <thead><tr><th scope="col">Cytowana strona</th><th scope="col" class="n">Cytowania</th></tr></thead>
        <tbody><?php foreach ($c['pages'] as $p): ?><tr><td class="cell-url"><a href="<?= e(safe_url($p['k'])) ?>" target="_blank" rel="noopener noreferrer"><?= e($p['path']) ?></a></td><td class="n"><b><?= num_pl($p['value']) ?></b></td></tr><?php endforeach ?></tbody>
      </table></div></div>
      <?php endif ?>
      <?php if ($c['queries']): ?>
      <div class="main" style="flex-basis:320px"><div class="table-wrap"><table>
        <thead><tr><th scope="col">Fraza, którą AI szukało treści</th><th scope="col" class="n">Cytowania</th></tr></thead>
        <tbody><?php foreach ($c['queries'] as $q): ?><tr><td><?= e($q['k']) ?></td><td class="n"><b><?= num_pl($q['value']) ?></b></td></tr><?php endforeach ?></tbody>
      </table></div></div>
      <?php endif ?>
    </div>
    <?php if ($c['queries']): ?><p class="sec-note" style="padding-top:12px">Frazy to próbka podana przez Binga, nie pełna lista. To jedyne miejsce, w którym widać, jak AI samo formułuje wyszukiwanie.</p><?php endif ?>
  <?php endif ?>
</section>

<section class="card flush" aria-labelledby="sa-v">
  <div class="card-head"><h2 id="sa-v">Wejścia z asystentów AI</h2><?php if ($v['cur']): ?><span class="small muted"><?= e(Sync::range($v['cur']['start'], $v['cur']['end'])) ?></span><?php endif ?></div>
  <?php if (!$v['cur']): ?>
    <div class="card-body">
      <p class="small" style="margin:0 0 12px">Ile osób kliknęło link do Twojej strony w ChatGPT, Perplexity, Copilocie, Gemini albo Claude. Dane są w Google Analytics.</p>
      <?php if ($googleOn): ?>
        <ol class="law">
          <li>Skopiuj adres: <code class="copy wrap" title="Kliknij, żeby skopiować"><?= e($googleEmail) ?></code></li>
          <li>W Google Analytics wejdź w Administracja, potem „Zarządzanie dostępem do usługi”, i dodaj ten adres z rolą „Wyświetlający”.</li>
          <li>Kliknij „Pobierz”. Panel sam znajdzie usługę tej witryny.</li>
        </ol>
        <?php if ($sync['ga4'] && !$sync['ga4']['ok']): ?><p class="notice mid" style="margin:12px 0"><?= e((string) $sync['ga4']['note']) ?></p><?php endif ?>
        <form method="post" action="<?= e($base) ?>/pobierz" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="zrodlo" value="google"><button class="btn" type="submit" data-busy="Pobieram dane…">Pobierz</button></form>
      <?php else: ?>
        <p class="small muted" style="margin:0">W Google Analytics otwórz raport „Pozyskiwanie ruchu”, ustaw wymiar „Źródło sesji”, skopiuj tabelę i wklej ją w zakładce <a href="<?= e($base) ?>/dane#wklej">Dane i połączenia</a>. Panel sam wybierze z niej asystentów AI.</p>
      <?php endif ?>
    </div>
  <?php else: ?>
    <div class="card-body">
      <p style="margin:0 0 4px"><span class="stat-value" style="margin:0"><?= num_pl($v['sum']) ?></span> <?= plural((int) $v['sum'], 'sesja', 'sesje', 'sesji') ?> z asystentów AI <?= delta_html($v['sum'], $v['prevSum']) ?><?= $v['all'] ? ' <span class="muted">(' . pct_pl($v['sum'] / max(1, $v['all']), 1) . ' wszystkich sesji)</span>' : '' ?></p>
      <p class="small muted" style="margin:0">Liczą się wejścia, przy których przeglądarka podała, skąd przyszła. Wejścia z aplikacji i ze skopiowanych linków Analytics pokazuje jako bezpośrednie, więc prawdziwa liczba jest wyższa.</p>
    </div>
    <div class="table-wrap"><table>
      <thead><tr><th scope="col">Asystent</th><th scope="col" class="n">Sesje</th><th scope="col" class="n">Poprzednio</th></tr></thead>
      <tbody><?php foreach ($v['rows'] as $r): ?><tr><td><b><?= e($r['name']) ?></b></td><td class="n"><?= num_pl($r['sessions']) ?> <?= delta_html($r['sessions'], $r['was']) ?></td><td class="n"><?= $r['was'] === null ? '<span class="muted">-</span>' : num_pl($r['was']) ?></td></tr><?php endforeach ?></tbody>
    </table></div>
    <?php if ($v['pages']): ?>
    <div class="card-body" style="padding-top:16px">
      <h3 style="margin-bottom:8px">Na które strony wchodzą</h3>
      <ul class="law"><?php foreach ($v['pages'] as $p): ?><li><?= e($p['path']) ?> <span class="muted">(<?= e($p['name']) ?>, <?= num_pl($p['sessions']) ?>)</span></li><?php endforeach ?></ul>
    </div>
    <?php endif ?>
  <?php endif ?>
</section>

<section class="card flush" aria-labelledby="sa-c">
  <div class="card-head"><h2 id="sa-c">Boty AI na stronie</h2><?php if ($bots['has']): ?><span class="small muted"><?= e(Sync::range($bots['from'], $bots['to'])) ?></span><?php endif ?></div>
  <?php if (!$bots['has']): ?>
    <div class="card-body">
      <p class="small" style="margin:0 0 12px">Dla witryn za Cloudflare panel pokaże, które boty AI pobierają strony i ile razy. Najciekawsze są boty asystentów (ChatGPT-User, Claude-User, Perplexity-User): przychodzą w chwili, gdy ktoś zadał asystentowi pytanie.</p>
      <?php if ($cfOn): ?>
        <?php if ($sync['cloudflare'] && !$sync['cloudflare']['ok']): ?><p class="notice mid" style="margin:0 0 12px"><?= e((string) $sync['cloudflare']['note']) ?></p><?php endif ?>
        <form method="post" action="<?= e($base) ?>/pobierz"><?= csrf_field() ?><input type="hidden" name="zrodlo" value="cloudflare"><button class="btn" type="submit" data-busy="Pobieram dane…">Pobierz z Cloudflare</button></form>
        <p class="small muted" style="margin:8px 0 0">Panel sam znajdzie domenę na koncie Cloudflare. Potem pobiera dane codziennie.</p>
      <?php else: ?>
        <p class="small muted" style="margin:0">Włącza to administrator panelu tokenem Cloudflare z prawem odczytu statystyk (zmienna <code>CF_ANALYTICS_TOKEN</code>).</p>
      <?php endif ?>
    </div>
  <?php else:
      $blockedLinking = array_values(array_filter($bots['bots'], static fn (array $b): bool => $b['category'] !== 'training' && $b['requests'] >= 5 && $b['blocked'] >= 0.5 * $b['requests']));
  ?>
    <div class="card-body">
      <div class="grid-stats">
        <?php foreach (CloudflareBots::CATEGORIES as $key => [$label, $hint]): ?>
          <div><div class="stat-label"><?= e($label) ?></div><div class="stat-value"><?= num_pl($bots['byCategory'][$key]) ?></div><div class="stat-note"><?= e($hint) ?></div></div>
        <?php endforeach ?>
      </div>
      <?php foreach ($blockedLinking as $b): ?>
        <p class="notice high" role="alert" style="margin:16px 0 0"><b><?= e($b['name']) ?></b> dostał odmowę w <?= num_pl($b['blocked']) ?> z <?= num_pl($b['requests']) ?> pobrań. Ten bot buduje odpowiedzi z linkami, więc blokada odcina stronę od odpowiedzi <?= e($b['operator']) ?>. Sprawdź w Cloudflare ustawienia botów AI (AI Crawl Control).</p>
      <?php endforeach ?>
      <?php if (count($bots['weekly']) >= 3): ?>
        <h3 style="margin-top:20px">Pobrania przez asystentów tygodniami</h3>
        <?= View::partial('partials/line_chart', ['points' => $bots['weekly'], 'name' => 'Pobrania przez asystentów', 'unit' => 'pobrań', 'prefix' => 'Tydzień od ']) ?>
      <?php endif ?>
    </div>
    <div class="table-wrap"><table>
      <thead><tr><th scope="col">Bot</th><th scope="col">Rodzaj</th><th scope="col" class="n">Pobrania</th><th scope="col" class="n">Podane</th><th scope="col" class="n">Zablokowane</th></tr></thead>
      <tbody>
      <?php foreach ($bots['bots'] as $b): ?>
        <tr><td><b><?= e($b['name']) ?></b> <span class="small muted"><?= e($b['operator']) ?></span></td><td class="small"><?= e(CloudflareBots::CATEGORIES[$b['category']][0]) ?></td><td class="n"><?= num_pl($b['requests']) ?></td><td class="n"><?= num_pl($b['served']) ?></td><td class="n"><?= $b['blocked'] ? '<b>' . num_pl($b['blocked']) . '</b>' : '0' ?></td></tr>
      <?php endforeach ?>
      </tbody>
    </table></div>
    <?php if ($bots['pages']): ?>
    <div class="card-body" style="padding-top:16px">
      <h3 style="margin-bottom:6px">Strony, po które przychodzą asystenci i wyszukiwarki AI</h3>
      <ul class="law"><?php foreach ($bots['pages'] as $p): arsort($p['bots']); ?><li><a href="<?= e('https://' . $site['host'] . $p['path']) ?>" target="_blank" rel="noopener noreferrer"><?= e($p['path']) ?></a> <span class="muted">(<?= num_pl($p['requests']) ?>: <?= e(implode(', ', array_slice(array_keys($p['bots']), 0, 3))) ?>)</span></li><?php endforeach ?></ul>
    </div>
    <?php endif ?>
    <p class="sec-note">Liczby z Cloudflare są próbkowane, czyli przybliżone. Bota rozpoznajemy po nazwie, którą sam podaje, a tę da się podrobić.</p>
  <?php endif ?>
</section>

<details class="card">
  <summary><b>Czego tu nie ma</b></summary>
  <ul class="law" style="margin-top:12px">
    <li>ChatGPT, Claude i Perplexity nie udostępniają właścicielom stron raportu cytowań. Ich aktywność widać tylko pośrednio: po wejściach (Google Analytics) i po botach (Cloudflare).</li>
    <li>Raport Google podaje wyświetlenia linków w odpowiedziach AI bez kliknięć i bez zapytań. Kliknięcia z odpowiedzi AI są wliczone w raport „Skuteczność” i nie da się ich oddzielić.</li>
    <li>Żadne z tych źródeł nie mówi, czy AI wymienia nazwę firmy bez linku, ani jak wypadasz na tle konkurencji. Do tego służy test cytowań: zadanie tych samych pytań kilku asystentom. Jest w planie, wymaga płatnych kluczy.</li>
  </ul>
</details>
