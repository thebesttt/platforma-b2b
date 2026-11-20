<header class="page-head">
  <p class="crumbs"><a href="/audyt">Audyt i reklamy</a> / Reklamy</p>
  <h1>Reklamy Google Ads i Meta</h1>
  <p class="muted small">Prosty panel: budżety, wydatki i wyniki kampanii w jednym miejscu, z możliwością wstrzymania i wznowienia kampanii.</p>
</header>
<?= App\Core\View::partial('partials/audit_nav', ['on' => 'reklamy']) ?>
<div class="notice info">Moduł reklam jest w przygotowaniu. Połączenie z kontami reklamowymi wymaga zatwierdzenia platformy przez Google i Meta - do tego czasu podłączanie kont jest wyłączone.</div>
<div class="cols">
  <section class="card main stack-sm">
    <h2>Co będzie dostępne</h2>
    <ul class="law">
      <li>Wydatki, kliknięcia, konwersje i koszt pozyskania z Google Ads i Meta na jednym wykresie.</li>
      <li>Wstrzymanie i wznowienie kampanii oraz zmiana dziennego budżetu.</li>
      <li>Alerty: wyczerpany budżet, odrzucona reklama, nagły wzrost kosztu kliknięcia.</li>
      <li>Wskazówki z audytu strony powiązane z kampaniami (np. wolna strona docelowa).</li>
    </ul>
  </section>
  <section class="card side stack-sm">
    <h2>Wymagane zgody</h2>
    <div class="sig"><span class="chip mid">w toku</span><div><p style="font-weight:600">Google Ads API</p><p class="small">Token deweloperski z dostępem podstawowym i weryfikacja aplikacji OAuth w Google Cloud.</p></div></div>
    <div class="sig"><span class="chip mid">w toku</span><div><p style="font-weight:600">Meta Marketing API</p><p class="small">Weryfikacja firmy i przegląd aplikacji (App Review) dla uprawnień ads_read i ads_management.</p></div></div>
    <button class="btn" type="button" disabled>Podłącz konto reklamowe</button>
  </section>
</div>
