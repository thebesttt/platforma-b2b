/* ===== SEO i strona: audyt techniczny, konkurenci, dane z Google ===== */
const SEV = { critical: ['bad', 'Krytyczne'], warning: ['mid', 'Do poprawy'], info: ['plain', 'Wskazówka'] };
const mins = (m) => m >= 60 ? num(m / 60, m % 60 ? 1 : 0) + ' godz.' : m + ' min';
const techTotal = () => PLAT.tech.cats.reduce((a, c) => a + c[1], 0);
/* Wynik strony z procentów kategorii: każda kategoria ma swoją wagę, razem 100. */
const siteTotal = (pcts) => Math.round(pcts.reduce((a, p, i) => a + p * PLAT.tech.cats[i][2] / 100, 0));

function renderTech() {
  const t = PLAT.tech, total = techTotal(), time = t.issues.reduce((a, i) => a + i[4], 0);
  put('technika', stan('silnik', 'Ten sam silnik co w PrestaCheck. Szacowany czas pracy jest do dodania.') +
    `<div class="two">
      <div class="stack"><div class="score-line"><span class="seller-num">${total}</span><div class="ro-side"><span class="muted">na 100 punktów</span>${st(level(total))}</div></div>
        <p class="small muted">Strona ${esc(t.site)}, audyt z ${esc(t.at)}. Dobrze od 85 pkt, do poprawy od 60 pkt.</p></div>
      <div class="stack">${t.cats.map(([name, pts, max]) => `<div class="meter"><span>${esc(name)}</span><span class="num muted">${pts} / ${max}</span><div class="hbar key"><i style="width:${Math.round(100 * pts / max)}%"></i></div></div>`).join('')}</div>
    </div>
    <div class="sec">${secHead('Do poprawy', 'Od najpilniejszych. Szacowany czas pracy programisty razem: ' + mins(time), t.issues.length)}` +
      t.issues.map(([sev, cat, title, fix, m]) => `<div class="item" style="--c1:150px"><span class="lead-col">${esc(cat)}</span><div><p><b>${esc(title)}</b></p><p>${esc(fix)}</p></div><div class="end">${st(SEV[sev][0], SEV[sev][1])}<span class="num muted" style="min-width:64px;text-align:right">${mins(m)}</span></div></div>`).join('') + `</div>
    <div class="sec">${secHead('W porządku', '', t.ok.length)}<ul class="qa" style="padding-top:16px">${t.ok.map((x) => `<li>${st('good', 'jest')}<span>${esc(x)}</span></li>`).join('')}</ul></div>
    <p class="note">Ten audyt sprawdza technikę strony. Treść pod wyszukiwarki i AI ocenia <a href="#raport">Audyt AI</a>.</p>`);
}

function renderRivals() {
  const t = PLAT.tech, cats = t.cats.map((c) => c[0]), own = t.rivals.find((r) => r[1] === 'Moja strona'), others = t.rivals.filter((r) => r !== own);
  /* Strata do najlepszego konkurenta przeliczona na punkty wyniku (procent razy waga kategorii) wskazuje, od czego zacząć. */
  const gaps = cats.map((name, i) => { const best = Math.max(...others.map((r) => r[2][i])); return { name, mine: own[2][i], best, pts: (best - own[2][i]) * t.cats[i][2] / 100 }; }).sort((a, b) => b.pts - a.pts);
  const g = gaps[0];
  put('konkurenci', stan('silnik', 'Porównanie najnowszych audytów każdej domeny.') +
    `<div class="sec">${secHead('Twoja strona na tle konkurencji', 'Wynik każdej kategorii w procentach. Strony konkurentów dodajesz w audycie z etykietą „Konkurent”.')}
      <div class="table-wrap"><table style="min-width:760px"><thead><tr><th>Strona</th><th class="n">Wynik</th>${cats.map((c) => `<th class="n">${esc(c)}</th>`).join('')}<th class="n">Krytyczne</th></tr></thead><tbody>` +
      t.rivals.map((r) => `<tr${r === own ? ' class="own"' : ''}><td><b>${esc(r[0])}</b><br><span class="small muted">${esc(r[1])}</span></td><td class="n"><b>${siteTotal(r[2])}</b></td>${r[2].map((p) => `<td class="n">${p}</td>`).join('')}<td class="n">${r[3]}</td></tr>`).join('') + `</tbody></table></div></div>
    <p id="rivalGap"><b>Najwięcej tracisz w kategorii: ${esc(g.name)}.</b> Twoja strona ma ${g.mine}%, najlepszy konkurent ${g.best}%. To ok. ${num(g.pts)} pkt wyniku. Zadania z tej kategorii są w zakładce <a href="#technika">Audyt techniczny</a>.</p>`);
}

function renderGoogle() {
  const g = PLAT.gsc, cur = g.clicks.slice(4).reduce((a, b) => a + b, 0), prev = g.clicks.slice(0, 4).reduce((a, b) => a + b, 0), p = g.profile;
  const def = { title: 'Kliknięcia z Google tygodniami', labels: g.weeks, prefix: 'Tydzień od ', series: [{ name: 'Kliknięcia', color: 'var(--s1)', values: g.clicks }] };
  const done = p.checks.filter((c) => c[0]).length;
  put('google', stan('plan', 'Dane są przykładowe. W panelu pojawią się po podłączeniu konta Google klienta.') +
    `<div class="sec">${secHead('Widoczność w Google', 'Dane z Search Console klienta, ostatnie 28 dni.')}
      <div class="counts" style="padding:18px 0 6px">
        <div><b>${num(cur)}</b><span>kliknięć, <span class="delta${cur < prev ? ' neg' : ''}">${proc(100 * (cur - prev) / prev, true)}</span> do poprzednich 28 dni</span></div>
        <div><b>${num(g.impressions)}</b><span>wyświetleń</span></div>
        <div><b>${proc(100 * cur / g.impressions)}</b><span>klikalność</span></div>
        <div><b>${esc(g.position)}</b><span>średnia pozycja</span></div></div>
      <h3 style="padding-top:14px">Kliknięcia tygodniami</h3>${chart('gsc', def)}${dataTable(def, 'Tydzień od')}</div>
    <div class="two">
      <div class="sec">${secHead('Zapytania z największą liczbą kliknięć')}<div class="table-wrap"><table><thead><tr><th>Zapytanie</th><th class="n">Kliknięcia</th><th class="n">Wyświetlenia</th><th class="n">Klikalność</th><th class="n">Pozycja</th></tr></thead><tbody>` +
        g.queries.map((q) => `<tr><td>${esc(q[0])}</td><td class="n">${num(q[1])}</td><td class="n">${num(q[2])}</td><td class="n">${proc(100 * q[1] / q[2])}</td><td class="n">${esc(q[3])}</td></tr>`).join('') + `</tbody></table></div></div>
      <div class="sec">${secHead('Wizytówka Google', '', done + ' z ' + p.checks.length)}
        <p style="padding:14px 0 12px">Ocena <b>${esc(p.rating)}</b> z ${p.reviews} opinii.</p>${checkList(p.checks, 'jest', 'brak')}</div>
    </div>`);
}

MODS.push({ id: 'seo', order: 5, label: 'SEO i strona', about: 'Audyt techniczny strony, porównanie z konkurentami, dane z Search Console i wizytówki Google.',
  head: (v) => ({ crumbs: 'Audyt widoczności, SEO i konkurencji', title: { technika: 'Audyt techniczny strony', konkurenci: 'Konkurenci', google: 'Widoczność w Google' }[v.id], facts: [['Klient', SAMPLE.client], ['Strona', PLAT.tech.site]] }),
  views: [{ id: 'technika', label: 'Audyt techniczny', render: renderTech }, { id: 'konkurenci', label: 'Konkurenci', gap: 24, render: renderRivals }, { id: 'google', label: 'Google', gap: 40, render: renderGoogle }] });
