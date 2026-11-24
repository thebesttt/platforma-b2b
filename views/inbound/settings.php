<?php
use App\Core\View;
use App\Modules\Inbound\Rules;

$dis = $canEdit ? '' : ' disabled';
$t = $f['texts'];
$embed = '<script async src="' . $scriptUrl . '"></script>';
?>
<header class="page-head">
  <p class="crumbs"><a href="/zapytania">Zapytania ze strony</a> / Ustawienia widżetu</p>
  <h1>Ustawienia widżetu</h1>
  <p class="muted small">Widżet zadaje odwiedzającym kilka pytań zamiast pustego formularza. Tu ustalasz pytania, punkty i strony, na których działa.</p>
</header>
<?= View::partial('partials/inbound_nav', ['on' => 'ustawienia']) ?>

<?php if ($errors): ?>
  <div class="notice high" role="alert"><b>Nic nie zapisano. Popraw:</b>
    <ul style="margin:6px 0 0;padding-left:18px"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach ?></ul>
  </div>
<?php endif ?>
<?php if (!$canEdit): ?><div class="notice info">Ustawienia widżetu zmienia właściciel konta. Możesz je przeglądać.</div><?php endif ?>

<form method="post" action="/zapytania/ustawienia" class="cols">
  <?= csrf_field() ?>
  <div class="main stack">
    <section class="card stack-sm" aria-labelledby="u-kod">
      <h2 id="u-kod">Gdzie działa widżet</h2>
      <label class="check" style="min-height:0;padding:0"><input type="checkbox" name="active" value="1"<?= $f['active'] ? ' checked' : '' ?><?= $dis ?>> <span>Widżet jest włączony</span></label>
      <div class="field"><label for="origins">Strony, na których widżet ma działać</label>
        <textarea id="origins" name="origins" rows="3" placeholder="twojafirma.pl" aria-describedby="origins-h"<?= $dis ?>><?= e($f['origins']) ?></textarea>
        <span class="hint" id="origins-h">Jedna domena w wierszu, bez https i bez www. Widżet nie odpowie żadnej innej stronie, więc nikt nie podszyje się pod Twój formularz.</span></div>
      <div class="field"><label for="embed">Kod do wklejenia na stronę</label>
        <textarea id="embed" rows="2" readonly spellcheck="false" style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px" aria-describedby="embed-h"><?= e($embed) ?></textarea>
        <span class="hint" id="embed-h">Wklej go przed znacznikiem &lt;/body&gt; na każdej podstronie, na której ma być przycisk. Żeby formularz pojawił się w treści strony zamiast przycisku w rogu, dodaj w wybranym miejscu <code>&lt;div data-pd-widget&gt;&lt;/div&gt;</code>. Jeśli strona ma nagłówek Content-Security-Policy, dopisz adres tego panelu do script-src i connect-src.</span></div>
      <p class="small muted" style="margin:0">Widżet nie zapisuje plików cookie i niczego nie przechowuje w przeglądarce odwiedzającego.</p>
    </section>

    <section class="card stack-sm" aria-labelledby="u-zasady">
      <h2 id="u-zasady">Pytania i punkty</h2>
      <p class="muted small" style="margin:0">Każde pytanie zaczyna się od wiersza <code># Etykieta | Treść pytania</code>. Pod nim odpowiedzi w postaci <code>Tekst odpowiedzi = punkty</code>. Najwyżej punktowane odpowiedzi ze wszystkich pytań plus punkty za telefon muszą dać razem <?= Rules::TOTAL ?>.</p>
      <div class="field"><label for="rules">Pytania, odpowiedzi i punkty</label>
        <textarea id="rules" name="rules" rows="30" spellcheck="false" style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px;line-height:1.55"<?= $dis ?>><?= e($f['rules_text']) ?></textarea></div>
      <div class="form-grid">
        <div class="field"><label for="phone_points">Punkty za podanie telefonu</label><input id="phone_points" name="phone_points" type="text" inputmode="numeric" value="<?= (int) $f['phone_points'] ?>"<?= $dis ?>></div>
        <div class="field"><label for="hot">„Pilne” od ilu punktów</label><input id="hot" name="hot" type="text" inputmode="numeric" value="<?= (int) $f['hot'] ?>"<?= $dis ?>></div>
        <div class="field"><label for="talk">„Do rozmowy” od ilu punktów</label><input id="talk" name="talk" type="text" inputmode="numeric" value="<?= (int) $f['talk'] ?>"<?= $dis ?>></div>
      </div>
      <p class="small muted" style="margin:0">Teraz obowiązuje wersja <?= (int) $w['rules_version'] ?>. Zmiana zasad działa dla kolejnych zapytań, a wcześniejsze zachowują swoją ocenę. Odwiedzający nie widzi punktów.</p>
    </section>

    <section class="card stack-sm" aria-labelledby="u-teksty">
      <h2 id="u-teksty">Teksty w widżecie</h2>
      <div class="form-grid">
        <div class="field"><label for="company">Nazwa firmy w widżecie</label><input id="company" name="company" type="text" maxlength="120" value="<?= e($t['company']) ?>" aria-describedby="company-h"<?= $dis ?>><span class="hint" id="company-h">Trafia też do informacji o administratorze danych pod formularzem.</span></div>
        <div class="field"><label for="button">Napis na przycisku</label><input id="button" name="button" type="text" maxlength="40" value="<?= e($t['button']) ?>"<?= $dis ?>></div>
        <div class="field span-2"><label for="greeting">Powitanie przed pierwszym pytaniem</label><textarea id="greeting" name="greeting" rows="2" maxlength="300"<?= $dis ?>><?= e($t['greeting']) ?></textarea></div>
        <div class="field span-2"><label for="url_prompt">Podpowiedź przy polu z adresem strony</label><input id="url_prompt" name="url_prompt" type="text" maxlength="200" value="<?= e($t['url_prompt']) ?>"<?= $dis ?>></div>
        <div class="field span-2"><label for="thanks">Podziękowanie po wysłaniu</label><textarea id="thanks" name="thanks" rows="2" maxlength="300"<?= $dis ?>><?= e($t['thanks']) ?></textarea></div>
        <div class="field span-2"><label for="privacy_url">Adres polityki prywatności</label><input id="privacy_url" name="privacy_url" type="text" inputmode="url" placeholder="https://twojafirma.pl/polityka-prywatnosci" value="<?= e($t['privacy_url']) ?>" aria-describedby="privacy-h"<?= $dis ?>>
          <span class="hint" id="privacy-h"><?= $t['privacy_url'] === '' ? 'Dodaj ten adres przed wklejeniem widżetu na stronę. Sama krótka klauzula pod formularzem nie wypełnia obowiązku informacyjnego z art. 13 RODO.' : 'Link pokazuje się pod formularzem obok klauzuli o administratorze danych.' ?></span></div>
      </div>
    </section>
    <?php if ($canEdit): ?><div><button class="btn" type="submit">Zapisz ustawienia</button></div><?php endif ?>
  </div>

  <div class="side stack">
    <section class="card stack-sm" aria-labelledby="u-czat">
      <h2 id="u-czat">Czat AI</h2>
      <label class="check" style="min-height:0;padding:0"><input type="checkbox" name="chat" value="1"<?= $f['chat'] ? ' checked' : '' ?><?= $dis ?>> <span>Odwiedzający może zadać pytanie asystentowi</span></label>
      <p class="small muted" style="margin:0">Asystent odpowiada tylko z <a href="/zapytania/wiedza">bazy wiedzy</a> Twojej firmy. Gdy czegoś w niej nie ma, mówi to wprost i proponuje kontakt. Pod czatem zawsze jest informacja, że odpowiada AI.</p>
      <?php if (!$aiEnabled): ?><div class="notice mid">Czat nie działa, bo na serwerze nie ma klucza ANTHROPIC_API_KEY. Pytania i formularz działają bez niego.</div><?php endif ?>
      <div class="field"><label for="limit_messages">Pytań do czatu w jednej rozmowie</label><input id="limit_messages" name="limit_messages" type="text" inputmode="numeric" value="<?= (int) $f['limits']['messages'] ?>"<?= $dis ?>></div>
      <div class="field"><label for="limit_ai_month">Odpowiedzi czatu w miesiącu</label><input id="limit_ai_month" name="limit_ai_month" type="text" inputmode="numeric" value="<?= (int) $f['limits']['ai_month'] ?>" aria-describedby="aim-h"<?= $dis ?>><span class="hint" id="aim-h">Po wyczerpaniu limitu czat proponuje zostawienie kontaktu.<?= ($chatCap ?? null) !== null ? ' Pakiet ' . e($planName) . ' obejmuje najwyżej ' . (int) $chatCap . ' w miesiącu.' : ' To górna granica kosztu AI.' ?></span></div>
    </section>

    <section class="card stack-sm" aria-labelledby="u-test">
      <h2 id="u-test">Test dwóch wersji</h2>
      <label class="check" style="min-height:0;padding:0"><input type="checkbox" name="ab_test" value="1"<?= $f['ab_test'] ? ' checked' : '' ?><?= $dis ?>> <span>Połowie odwiedzających pokazuj zwykły formularz</span></label>
      <p class="small muted" style="margin:0">Wariant A to zwykły formularz, wariant B to pytania z oceną. Wersję losuje serwer. W zakładce <a href="/zapytania/wyniki">Wyniki</a> zobaczysz, która zbiera więcej zapytań, i czy różnica jest już pewna.</p>
    </section>

    <section class="card stack-sm" aria-labelledby="u-limit">
      <h2 id="u-limit">Ochrona przed automatami</h2>
      <div class="field"><label for="limit_daily">Rozmów dziennie</label><input id="limit_daily" name="limit_daily" type="text" inputmode="numeric" value="<?= (int) $f['limits']['daily'] ?>" aria-describedby="ld-h"<?= $dis ?>><span class="hint" id="ld-h">Po przekroczeniu widżet przestaje się otwierać do końca dnia. Do tego dochodzą limity na jeden adres IP i ukryte pole, które wypełniają tylko automaty.</span></div>
    </section>
  </div>
</form>
