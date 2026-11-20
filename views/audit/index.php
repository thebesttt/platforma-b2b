<header class="page-head">
  <p class="crumbs">Audyt i reklamy</p>
  <h1>Audyt techniczny strony</h1>
  <p class="muted small">Bezpieczeństwo, szybkość serwera, SEO techniczne oraz analityka i zgody RODO. Sprawdzamy tylko to, co strona pokazuje publicznie. Treść pod wyszukiwarki i AI ocenia <a href="/audyt/tresc">Audyt AI</a>.</p>
</header>
<?= App\Core\View::partial('partials/audit_nav', ['on' => 'tech']) ?>
<section class="card">
  <form method="post" action="/audyt" class="form-grid">
    <?= csrf_field() ?>
    <div class="field span-2"><label for="url">Adres strony</label><input id="url" name="url" type="text" required placeholder="np. twojafirma.pl" value="<?= e($own) ?>"></div>
    <div class="field"><label for="label">Etykieta</label><select id="label" name="label"><option value="Moja strona">Moja strona</option><option value="Konkurent">Konkurent</option></select></div>
    <div class="field" style="justify-content:flex-end">
      <label class="check" style="min-height:0;padding:0 0 8px"><input type="checkbox" name="pagespeed" value="1"> <span class="small">Dodaj pomiar Google PageSpeed (ok. 30 s)</span></label>
      <button class="btn" type="submit" data-busy="Sprawdzam stronę…">Sprawdź stronę</button>
    </div>
  </form>
</section>
<section class="card flush">
  <div class="card-head"><h2>Ostatnie audyty</h2></div>
  <?php if ($audits): ?>
    <div class="table-wrap"><table>
      <thead><tr><th scope="col">Wynik</th><th scope="col">Strona</th><th scope="col">Problemy</th><th scope="col">Data</th></tr></thead>
      <tbody>
      <?php foreach ($audits as $a): $v = $a['view']; if (!$v) continue; ?>
        <tr>
          <td><?= App\Core\View::partial('partials/audit_score', ['v' => $v]) ?></td>
          <td><a class="t-title" href="/audyt/<?= (int) $a['row']['id'] ?>"><?= e($a['row']['host']) ?></a><?php if (!empty($v['label'])): ?> <span class="chip <?= $v['label'] === 'Konkurent' ? 'neutral' : 'info' ?>"><?= e($v['label']) ?></span><?php endif ?></td>
          <td class="small"><?= (int) ($v['counts']['critical'] ?? 0) ?> krytyczne · <?= (int) ($v['counts']['warning'] ?? 0) ?> do poprawy</td>
          <td class="small muted nowrap"><?= e(local_dt($a['row']['created_at'], 'd.m.Y H:i')) ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table></div>
  <?php else: ?>
    <div class="empty"><h3>Nie masz jeszcze audytów</h3><p>Sprawdź swoją stronę, a potem 2-3 strony konkurentów, żeby zobaczyć porównanie.</p></div>
  <?php endif ?>
</section>
