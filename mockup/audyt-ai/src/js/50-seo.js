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
    <p id="rivalGap"><b>Najwięcej tracisz w kategorii: ${esc(g.name)}.</b> Twoja strona ma ${g.mine}%, najlepszy konkurent ${g.best}%. To ok. ${num(g.pts)} pkt wyniku. Zadania z tej kategorii są w zakładce <a href="#technika">Audyt techniczny</a>.</p>` + renderMaps());
}

/* Mapy witryn konkurentów: co publikują i jakich tematów brakuje. Dopisane pod porównaniem audytów. */
function renderMaps() {
  const m = PLAT.gsc.maps;
  return `<div class="sec">${secHead('Ile publikują', 'Z publicznych map witryn. „Nowe” to strony, które pojawiły się w mapie w ostatnich 30 dniach.')}
      <div class="table-wrap"><table><thead><tr><th>Witryna</th><th class="n">Stron w mapie</th><th class="n">Nowe, 30 dni</th><th class="n">Zmienione, 30 dni</th></tr></thead><tbody>` +
      m.sites.map((r) => `<tr><td><b>${esc(r[0])}</b></td><td class="n">${num(r[1])}</td><td class="n">${num(r[2])}</td><td class="n">${num(r[3])}</td></tr>`).join('') + `</tbody></table></div></div>
    <div class="two">
      <div class="sec">${secHead('Co ostatnio opublikowali', '', m.recent.length)}<div class="table-wrap"><table><thead><tr><th>Strona</th><th>Co się stało</th><th class="n">Data</th></tr></thead><tbody>` +
        m.recent.map((r) => `<tr><td><b>${esc(r[0])}</b><br><span class="small muted">${esc(r[1])}</span></td><td>${st(r[2] ? 'good' : 'plain', r[2] ? 'nowa strona' : 'zmieniona')}</td><td class="n">${esc(r[3])}</td></tr>`).join('') + `</tbody></table></div></div>
      <div class="sec">${secHead('Tematy, których nie masz', 'Słowa z adresów konkurentów, których nie ma w Twoich adresach ani zapytaniach.', m.topics.length)}<div class="table-wrap"><table><thead><tr><th>Temat</th><th class="n">Stron</th><th>U kogo</th></tr></thead><tbody>` +
        m.topics.map((r) => `<tr><td><b>${esc(r[0])}</b><br><span class="small muted">${esc(r[3])}</span></td><td class="n">${r[1]}</td><td class="small">${esc(r[2])}</td></tr>`).join('') + `</tbody></table></div></div>
    </div>
    <p class="note">Panel czyta tylko mapę witryny, czyli listę adresów, którą witryna sama podaje robotom. Pozycji konkurentów w Google tu nie ma: te są w płatnych narzędziach.</p>`;
}

/* Wnioski z Search Console liczone z wierszy zapytań tak samo jak w silniku (te same progi). */
function gscInsights() {
  const g = PLAT.gsc, min = 30;
  const brand = (q) => g.brand.some((b) => q.toLowerCase().includes(b));
  const ctr = (r) => r[1] / r[2];
  const prev = Object.fromEntries(g.prev.map((r) => [r[0], r]));
  const open = g.queries.filter((r) => !brand(r[0]) && r[2] >= min);
  const top = open.filter((r) => r[3] < 3.5);
  const topCtr = top.reduce((a, r) => a + r[1], 0) / top.reduce((a, r) => a + r[2], 0);
  const usual = (p) => p < 1.5 ? 0.25 : p < 2.5 ? 0.13 : p < 3.5 ? 0.09 : 0.05;
  const near = open.filter((r) => r[3] >= 3.5 && r[3] <= 15.5).map((r) => ({ r, gain: Math.round(r[2] * topCtr) - r[1] })).filter((x) => x.gain > 0).sort((a, b) => b.gain - a.gain);
  const weak = open.filter((r) => r[3] <= 5.5 && ctr(r) < 0.5 * usual(r[3])).map((r) => ({ r, usual: usual(r[3]) }));
  const moves = g.queries.map((r) => ({ q: r[0], now: r[1], was: prev[r[0]] ? prev[r[0]][1] : 0, gone: false }))
    .concat(g.prev.filter((p) => !g.queries.some((r) => r[0] === p[0])).map((p) => ({ q: p[0], now: 0, was: p[1], gone: true })))
    .filter((m) => Math.abs(m.now - m.was) >= 3 && Math.abs(m.now - m.was) >= 0.2 * Math.max(m.now, m.was));
  /* Pozycja i wyświetlenia bez zmian, klikalność niższa o 40% i więcej: coś nad wynikami zabiera kliknięcia. */
  const squeeze = open.filter((r) => { const p = prev[r[0]]; return p && p[1] >= 5 && r[2] >= 0.9 * p[2] && Math.abs(r[3] - p[3]) <= 1.5 && ctr(r) <= 0.6 * ctr(p); })
    .map((r) => ({ r, was: prev[r[0]] }));
  const sum = (rows, i) => rows.reduce((a, r) => a + r[i], 0);
  const cur = g.clicks.slice(4).reduce((a, b) => a + b, 0), was = g.clicks.slice(0, 4).reduce((a, b) => a + b, 0);
  const brandClicks = sum(g.queries.filter((r) => brand(r[0])), 1), otherClicks = sum(g.queries.filter((r) => !brand(r[0])), 1);
  const question = (q) => /^(jak|jaki|jaka|jakie|co|czy|ile|dlaczego|kiedy|gdzie)\s/.test(q) || q.split(/\s+/).length >= 6;
  return { topCtr, near, weak, squeeze, cur, was, down: moves.filter((m) => m.now < m.was).sort((a, b) => (a.now - a.was) - (b.now - b.was)), up: moves.filter((m) => m.now > m.was).sort((a, b) => (b.now - b.was) - (a.now - a.was)),
    brand: [brandClicks, otherClicks, cur - brandClicks - otherClicks], questions: open.filter((r) => question(r[0])).sort((a, b) => b[2] - a[2]) };
}
const pos1 = (p) => num(p, 1);
const chg = (now, was) => `<span class="delta${now < was ? ' neg' : ''}">${proc(100 * (now - was) / was, true)}</span>`;

function renderGoogle() {
  const g = PLAT.gsc, x = gscInsights();
  const def = { title: 'Kliknięcia z Google tygodniami', labels: g.weeks, prefix: 'Tydzień od ', series: [{ name: 'Kliknięcia', color: 'var(--s1)', values: g.clicks }] };
  const can = g.cannibal;
  put('google', stan('silnik', 'Dane z pliku wyeksportowanego z Search Console albo ze stałego połączenia. Tu dane przykładowe.') +
    `<div class="sec">${secHead('Wynik okresu', 'Okres ' + esc(g.period) + ', porównanie z ' + esc(g.prev_period) + '.')}
      <div class="counts" style="padding:18px 0 6px">
        <div><b>${num(x.cur)}</b><span>kliknięć, ${chg(x.cur, x.was)}</span></div>
        <div><b>${num(g.impressions[1])}</b><span>wyświetleń, ${chg(g.impressions[1], g.impressions[0])}</span></div>
        <div><b>${proc(100 * x.cur / g.impressions[1])}</b><span>klikalność</span></div>
        <div><b>${pos1(g.position[1])}</b><span>średnia pozycja, była ${pos1(g.position[0])}</span></div></div>
      <h3 style="padding-top:14px">Kliknięcia tygodniami</h3>${chart('gsc', def)}${dataTable(def, 'Tydzień od')}</div>
    <div class="sec">${secHead('Tuż za czołówką', 'Zapytania na pozycjach 4-15, które ludzie często widzą. Tu najłatwiej o dodatkowe kliknięcia.', x.near.length)}
      <div class="table-wrap"><table><thead><tr><th>Zapytanie</th><th class="n">Do zyskania</th><th class="n">Pozycja</th><th class="n">Wyświetlenia</th><th class="n">Kliknięcia</th></tr></thead><tbody>` +
      x.near.map(({ r, gain }) => `<tr><td>${esc(r[0])}</td><td class="n"><b>ok. ${num(gain)}</b></td><td class="n">${pos1(r[3])}</td><td class="n">${num(r[2])}</td><td class="n">${num(r[1])}</td></tr>`).join('') + `</tbody></table></div>
      <p class="small muted" style="padding-top:10px">„Do zyskania” to szacunek kliknięć w tym okresie po wejściu do pierwszej trójki. Liczony z klikalności ${proc(100 * x.topCtr)}: tyle mają zapytania tej witryny z pozycji 1-3.</p></div>
    <div class="two">
      <div class="sec">${secHead('Wysoko, ale mało kliknięć', 'Pierwsza piątka i klikalność ponad dwa razy niższa niż zwykle. Najczęściej winny jest tytuł albo opis.', x.weak.length)}
        <div class="table-wrap"><table><thead><tr><th>Zapytanie</th><th class="n">Klikalność</th><th class="n">Zwykle</th><th class="n">Pozycja</th></tr></thead><tbody>` +
        x.weak.map(({ r, usual }) => `<tr><td>${esc(r[0])}</td><td class="n"><b>${proc(100 * r[1] / r[2])}</b></td><td class="n">${proc(100 * usual)}</td><td class="n">${pos1(r[3])}</td></tr>`).join('') + `</tbody></table></div></div>
      <div class="sec">${secHead('Dwie strony na jedno zapytanie', 'Google pokazuje raz jedną, raz drugą. Zwykle lepiej połączyć treść.', 1)}
        <p style="padding:14px 0 8px"><b>${esc(can[0])}</b></p><div class="table-wrap"><table><thead><tr><th>Strona</th><th class="n">Wyświetlenia</th><th class="n">Pozycja</th></tr></thead><tbody>` +
        can[1].map((p) => `<tr><td>${esc(p[0])}</td><td class="n">${num(p[1])}</td><td class="n">${pos1(p[2])}</td></tr>`).join('') + `</tbody></table></div></div>
    </div>
    <div class="two">` + [['Największe spadki', x.down], ['Największe wzrosty', x.up]].map(([title, rows]) =>
      `<div class="sec">${secHead(title, '', rows.length)}<div class="table-wrap"><table><thead><tr><th>Zapytanie</th><th class="n">Kliknięcia</th><th class="n">Było</th></tr></thead><tbody>` +
      rows.slice(0, 5).map((m) => `<tr><td>${esc(m.q)}${m.gone ? ' <span class="small muted">(zniknęło)</span>' : ''}</td><td class="n"><b>${num(m.now)}</b></td><td class="n">${num(m.was)}</td></tr>`).join('') + `</tbody></table></div></div>`).join('') + `</div>
    <p class="note" id="gscBrand"><b>Skąd są kliknięcia.</b> Z nazwą marki: ${num(x.brand[0])}. Pozostałe zapytania: ${num(x.brand[1])}. Ukryte przez Google (rzadkie zapytania): ${num(x.brand[2])}. Nowych klientów przyprowadzają pozostałe.</p>
    <p class="small muted">Start: wpisujesz adres witryny, a potem wgrywasz paczkę z przycisku „Eksportuj” w Search Console albo dodajesz jeden adres e-mail jako użytkownika i klikasz „Połącz”. Panel sam rozpoznaje raport i okres.</p>`);
}

function renderAi() {
  const g = PLAT.gsc, a = g.ai, x = gscInsights();
  const clicksOf = Object.fromEntries(g.pages);
  const cited = new Set(a.google.map((p) => p[0]));
  const missing = g.pages.filter((p) => !cited.has(p[0]) && p[0] !== '/');
  const visits = a.visits.reduce((s, v) => s + v[1], 0), visitsWas = a.visits.reduce((s, v) => s + (v[2] || 0), 0);
  const kinds = { asystent: 'Asystent na żywo', wyszukiwarka: 'Wyszukiwarka AI', trening: 'Trening modeli' };
  const byKind = (k) => a.bots.filter((b) => b[2] === k).reduce((s, b) => s + b[3], 0);
  const blocked = a.bots.filter((b) => b[2] !== 'trening' && b[5] >= 0.5 * b[3]);
  put('ai', stan('silnik', 'Każde źródło mierzy coś innego, więc liczb nie sumujemy. Tu dane przykładowe.') +
    `<div class="sec">${secHead('Google: Przeglądy od AI i Tryb AI', 'Raport „Funkcje generatywnej AI” z Search Console. Same wyświetlenia linków, bez kliknięć i zapytań.')}
      <div class="counts" style="padding:18px 0 6px"><div><b>${num(a.google_total)}</b><span>wyświetleń linków do witryny w odpowiedziach AI</span></div></div>
      <div class="table-wrap"><table><thead><tr><th>Strona pokazywana w odpowiedziach AI</th><th class="n">Wyświetlenia w AI</th><th class="n">Kliknięcia z wyników</th></tr></thead><tbody>` +
      a.google.map((p) => `<tr><td>${esc(p[0])}</td><td class="n"><b>${num(p[1])}</b></td><td class="n">${num(clicksOf[p[0]] || 0)}</td></tr>`).join('') + `</tbody></table></div>
      <p class="small muted" style="padding-top:10px"><b>Strony z kliknięciami, których AI nie pokazuje:</b> ${missing.map((p) => esc(p[0]) + ' (' + num(p[1]) + ')').join(', ')}. Od nich warto zacząć <a href="#raport">Audyt AI</a>.</p></div>
    <div class="two">
      <div class="sec">${secHead('Pytania, na które trafiają do Ciebie', 'Pytania i długie zapytania: tak ludzie rozmawiają z wyszukiwarką i asystentem AI.', x.questions.length)}
        <div class="table-wrap"><table><thead><tr><th>Zapytanie</th><th class="n">Wyświetlenia</th><th class="n">Pozycja</th></tr></thead><tbody>` +
        x.questions.map((r) => `<tr><td>${esc(r[0])}</td><td class="n">${num(r[2])}</td><td class="n">${pos1(r[3])}</td></tr>`).join('') + `</tbody></table></div></div>
      <div class="sec">${secHead('Te same wyświetlenia, mniej kliknięć', 'Pozycja bez zmian, klikalność niższa o 40% lub więcej: nad wynikami pojawiła się odpowiedź AI, reklama albo mapa.', x.squeeze.length)}
        <div class="table-wrap"><table><thead><tr><th>Zapytanie</th><th class="n">Klikalność</th><th class="n">Była</th><th class="n">Stracone</th></tr></thead><tbody>` +
        x.squeeze.map(({ r, was }) => `<tr><td>${esc(r[0])}</td><td class="n"><b>${proc(100 * r[1] / r[2])}</b></td><td class="n">${proc(100 * was[1] / was[2])}</td><td class="n">${num(was[1] - r[1])}</td></tr>`).join('') + `</tbody></table></div></div>
    </div>
    <div class="two">
      <div class="sec">${secHead('Copilot i Bing', 'Raport „AI Performance” z Bing Webmaster Tools: cytowania i frazy, którymi AI szukało treści.')}
        <div class="counts" style="padding:18px 0 6px"><div><b>${num(a.copilot_total)}</b><span>cytowań</span></div></div>
        <div class="table-wrap"><table><thead><tr><th>Fraza, którą AI szukało treści</th><th class="n">Cytowania</th></tr></thead><tbody>` +
        a.copilot_queries.map((q) => `<tr><td>${esc(q[0])}</td><td class="n"><b>${num(q[1])}</b></td></tr>`).join('') + `</tbody></table></div></div>
      <div class="sec">${secHead('Wejścia z asystentów AI', 'Z Google Analytics: kto kliknął link w odpowiedzi asystenta.')}
        <div class="counts" style="padding:18px 0 6px"><div><b>${num(visits)}</b><span>sesji, ${chg(visits, visitsWas)}, ${proc(100 * visits / a.sessions)} wszystkich</span></div></div>
        <div class="table-wrap"><table><thead><tr><th>Asystent</th><th class="n">Sesje</th><th class="n">Poprzednio</th></tr></thead><tbody>` +
        a.visits.map((v) => `<tr><td><b>${esc(v[0])}</b></td><td class="n">${num(v[1])}</td><td class="n">${v[2] === null ? '-' : num(v[2])}</td></tr>`).join('') + `</tbody></table></div></div>
    </div>
    <div class="sec">${secHead('Boty AI na stronie', 'Z Cloudflare, ostatnie 28 dni. Asystent pobiera stronę w chwili, gdy ktoś zadał mu pytanie.')}
      <div class="counts" style="padding:18px 0 6px">${Object.keys(kinds).map((k) => `<div><b>${num(byKind(k))}</b><span>${kinds[k]}</span></div>`).join('')}</div>` +
      blocked.map((b) => `<p class="note warn" id="aiBlocked"><b>${esc(b[0])} dostał odmowę w ${num(b[5])} z ${num(b[3])} pobrań.</b> Ten bot buduje odpowiedzi z linkami, więc blokada odcina stronę od odpowiedzi ${esc(b[1])}. Do sprawdzenia w Cloudflare, w ustawieniach botów AI.</p>`).join('') +
      `<div class="table-wrap" style="padding-top:12px"><table><thead><tr><th>Bot</th><th>Rodzaj</th><th class="n">Pobrania</th><th class="n">Podane</th><th class="n">Zablokowane</th></tr></thead><tbody>` +
      a.bots.map((b) => `<tr><td><b>${esc(b[0])}</b> <span class="small muted">${esc(b[1])}</span></td><td class="small">${kinds[b[2]]}</td><td class="n">${num(b[3])}</td><td class="n">${num(b[4])}</td><td class="n">${b[5] ? '<b>' + num(b[5]) + '</b>' : '0'}</td></tr>`).join('') + `</tbody></table></div></div>
    <p class="small muted">ChatGPT, Claude i Perplexity nie udostępniają właścicielom stron raportu cytowań. Widać je pośrednio: po wejściach i po botach. Kogo AI cytuje zamiast Ciebie, pokaże test cytowań w module Audyt AI (w planie).</p>`);
}

function renderGbp() {
  const p = PLAT.gsc.profile, done = p.checks.filter((c) => c[0]).length;
  put('wizytowka', stan('plan', 'Dane są przykładowe. Dostęp do wizytówek wymaga zgody Google dla aplikacji.') +
    `<div class="sec">${secHead('Wizytówka Google', '', done + ' z ' + p.checks.length)}
      <p style="padding:14px 0 12px">Ocena <b>${esc(p.rating)}</b> z ${p.reviews} opinii.</p>${checkList(p.checks, 'jest', 'brak')}</div>`);
}

MODS.push({ id: 'seo', order: 5, label: 'SEO i strona', about: 'Audyt techniczny, widoczność w Google i w odpowiedziach AI, konkurencja i wizytówka Google.',
  head: (v) => ({ crumbs: 'Audyt widoczności, SEO i konkurencji', title: { technika: 'Audyt techniczny strony', konkurenci: 'Konkurenci', google: 'Widoczność w Google', ai: 'Widoczność w odpowiedziach AI', wizytowka: 'Wizytówka Google' }[v.id], facts: [['Klient', SAMPLE.client], ['Strona', PLAT.tech.site]] }),
  views: [{ id: 'technika', label: 'Audyt techniczny', render: renderTech }, { id: 'konkurenci', label: 'Konkurenci', gap: 24, render: renderRivals }, { id: 'google', label: 'Google', gap: 32, render: renderGoogle },
    { id: 'ai', label: 'Odpowiedzi AI', gap: 32, render: renderAi }, { id: 'wizytowka', label: 'Wizytówka', gap: 32, render: renderGbp }] });
