<?php
use App\Core\View;
use App\Modules\Content\Fetcher;
use App\Modules\Content\Ruleset;
use App\Modules\Content\Sitemap;

$types = Ruleset::pageTypes();
?>
<header class="page-head">
  <p class="crumbs"><a href="/audyt/tresc">Audyt AI</a> / Mapa witryny</p>
  <h1>Dodaj wiele stron z mapy witryny</h1>
  <p class="muted small">Pobieramy listę podstron z pliku sitemap.xml. Wpisz frazę przy stronach, które chcesz sprawdzić. Strony bez frazy pomijamy, bo każda strona odpowiada na inne pytanie.</p>
</header>
<?= View::partial('partials/audit_nav', ['on' => 'ai']) ?>

<form method="get" action="/audyt/tresc/mapa" class="card">
  <div class="field"><label for="site">Adres witryny</label>
    <div class="searchbar"><input id="site" name="url" type="text" inputmode="url" required placeholder="twojafirma.pl" value="<?= e($site) ?>"><button class="btn" type="submit" data-busy="Pobieram…">Pobierz listę stron</button></div></div>
</form>

<?php if ($res && !$res['ok']): ?>
  <div class="notice high" role="alert"><?= e($res['error']) ?></div>
<?php elseif ($res): ?>
<form method="post" action="/audyt/tresc/mapa" class="card flush">
  <?= csrf_field() ?>
  <div class="card-head"><h2><?= count($res['urls']) ?> <?= plural(count($res['urls']), 'strona', 'strony', 'stron') ?> z mapy witryny</h2><span class="small muted">Źródło: <?= e(truncate((string) $res['source'], 70)) ?><?= count($res['urls']) >= Sitemap::MAX_URLS ? '. Pokazujemy pierwsze ' . Sitemap::MAX_URLS . '.' : '' ?></span></div>
  <div class="table-wrap"><table>
    <thead><tr><th scope="col">Strona</th><th scope="col">Fraza</th><th scope="col">Rodzaj strony</th></tr></thead>
    <tbody>
    <?php foreach ($res['urls'] as $i => $u): $path = (string) (parse_url($u, PHP_URL_PATH) ?: '/'); $onList = isset($known[Fetcher::canonicalForm($u)]); $guess = Sitemap::guessType($u); ?>
      <tr>
        <td style="min-width:220px"><span class="t-title"><?= e(truncate($path, 70)) ?></span><?php if ($onList): ?> <span class="chip neutral">już na liście</span><?php endif ?><input type="hidden" name="u[<?= $i ?>]" value="<?= e($u) ?>"></td>
        <td style="min-width:240px"><label class="sr-only" for="p-<?= $i ?>">Fraza dla strony <?= e($path) ?></label><input id="p-<?= $i ?>" name="p[<?= $i ?>]" type="text" maxlength="120" placeholder="fraza, np. jak dobrać ekspres"></td>
        <td style="min-width:200px"><label class="sr-only" for="t-<?= $i ?>">Rodzaj strony <?= e($path) ?></label><select id="t-<?= $i ?>" name="t[<?= $i ?>]"><?php foreach ($types as $k => $label): ?><option value="<?= e($k) ?>"<?= $k === $guess ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table></div>
  <div class="card-body" style="padding-top:16px"><button class="btn" type="submit">Dodaj strony z frazą i sprawdź</button> <span class="small muted">Jednorazowo najwyżej 20 stron.</span></div>
</form>
<?php endif ?>
