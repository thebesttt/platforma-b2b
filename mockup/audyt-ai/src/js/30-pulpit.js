/* ===== Pulpit ===== */
/* Lista na dziś powstaje z danych wszystkich modułów, tymi samymi funkcjami, które liczą ich ekrany. */
function todayItems() {
  const items = [];
  const scored = LEADS.leads.map((l) => ({ l, pts: leadScore(l.answers, l.phone).pts }));
  const fresh = scored.filter((x) => x.l.status === 'Nowe'), hot = fresh.filter((x) => x.pts >= LEADS.levels.hot), risky = fresh.filter((x) => x.l.nip && x.l.nip[0] === 'bad');
  if (fresh.length) items.push([hot.length ? 'bad' : 'mid', 'Zapytania', `Nowe zapytania: ${fresh.length}, w tym pilne: ${hot.length}`, risky.length ? `Ostrzeżenia o kontrahencie: ${risky.length}. Sprawdź firmę przed wyceną.` : 'Zacznij od pilnych.', 'zapytania']);
  for (const { t, m } of matchAll().ok) {
    const d = daysTo(t.deadline);
    if (m.score >= 70 && d <= 10) items.push([d <= 3 ? 'bad' : 'mid', 'Przetargi', `Oferta do ${dpl(t.deadline)}, zostało ${dni(d)}`, `${t.title}, dopasowanie ${m.score}.`, t.id === PLAT.tender_card.id ? 'przetarg' : 'przetargi']);
  }
  for (const c of PLAT.companies.filter((x) => x.watched)) {
    for (const [at, what] of c.changes) {
      if (-daysTo(at.split('.').reverse().join('-')) <= 14) items.push([companyRisk(c).cls === 'bad' ? 'bad' : 'mid', 'Kontrahenci', what, `${c.name}, ${at}.`, 'obserwowane']);
    }
  }
  const debts = PLAT.receivables.map(bigCheck);
  if (debts.length) items.push(['mid', 'Należności', `Po terminie: ${zl(PLAT.receivables.reduce((a, r) => a + r.amount, 0))}`, `Warunki wpisu do BIG spełnia faktur: ${debts.filter((b) => b.eligible).length}.`, 'naleznosci']);
  const alerts = adsAlerts();
  if (alerts.length) items.push([alerts.some((a) => a[0] === 'bad') ? 'bad' : 'mid', 'Reklamy', `Alerty w kampaniach: ${alerts.length}`, alerts[0][1] + '.', 'alerty']);
  const weak = SAMPLE.pages.filter((p) => p[2] < RULES.levels.mid).sort((a, b) => a[2] - b[2]);
  if (weak.length) items.push(['mid', 'Audyt AI', `Strony poniżej ${RULES.levels.mid} pkt: ${weak.length}`, `Najsłabsza: ${weak[0][0]}, ${weak[0][2]} pkt.`, 'strony']);
  for (const r of PLAT.sequence.running) if (r[2].startsWith('Przypomnienie')) items.push(['plain', 'Klienci', r[2], r[0] + '.', 'wysylka']);
  const order = { bad: 0, mid: 1, plain: 2 };
  return items.sort((a, b) => order[a[0]] - order[b[0]]);
}

function renderDesk() {
  const items = todayItems(), label = { bad: 'pilne', mid: 'do zrobienia', plain: 'informacja' };
  put('pulpit', `<div class="sec">${secHead('Dziś', 'Lista powstaje z danych wszystkich modułów. Najpierw sprawy pilne.', items.length)}` +
    items.map(([cls, mod, title, detail, view]) => `<div class="item"><span class="lead-col">${esc(mod)}</span><div><p><b>${esc(title)}</b></p><p>${esc(detail)}</p></div><div class="end">${st(cls, label[cls])}<a href="#${view}">Otwórz</a></div></div>`).join('') + `</div>
    <div class="sec">${secHead('Moduły', 'Każdy moduł ma zakładki pod tytułem strony. Stan każdej funkcji jest w zakładce <a href="#funkcje">Lista funkcji</a>.', MODS.length - 1)}` +
    MODS.filter((m) => m.id !== 'pulpit').map((m) => `<div class="item"><span style="font-weight:600">${esc(m.label)}</span><div><p>${esc(m.about)}</p></div><div class="end"><a href="#${m.views[0].id}">Otwórz</a></div></div>`).join('') + `</div>`);
}

function renderFunctions() {
  const all = PLAT.functions.flatMap((g) => g.items), count = (k) => all.filter((f) => f[2] === k).length;
  put('funkcje', `<div class="counts"><div><b>${all.length}</b><span>funkcji w mockupie</span></div><div><b id="fnEngine">${count('silnik')}</b><span>działa w silniku</span></div>
      <div><b>${count('plan')}</b><span>do zbudowania</span></div><div><b>${count('zgoda')}</b><span>czeka na zgodę Google i Meta</span></div></div>
    <p class="note">Mockup pokazuje każdą funkcję na przykładowych danych. „Działa w silniku” znaczy, że kod jest w repozytorium i przechodzi testy. Silnik nie jest jeszcze uruchomiony online: do tego potrzeba konta na hostingu i kluczy, które zakłada właściciel.</p>` +
    PLAT.functions.map((g) => `<div class="sec">${secHead(esc(g.module), '', g.items.length)}` +
      g.items.map(([name, view, key, note]) => `<div class="item fn" style="--c1:minmax(0,1.25fr)"><a href="#${view}">${esc(name)}</a><span class="small muted">${esc(note)}</span><div class="end">${st(PLAT.states[key][0], PLAT.states[key][1])}</div></div>`).join('') + `</div>`).join(''));
}

MODS.push({ id: 'pulpit', order: 1, label: 'Pulpit', about: '',
  head: (v) => v.id === 'pulpit' ? { crumbs: 'Panel PerfectData', title: 'Pulpit', facts: [['Dzień', dpl(PLAT.today)], ['Użytkownik', PLAT.org.user + ' (przykład)']] } : { crumbs: 'Panel PerfectData', title: 'Lista funkcji' },
  views: [{ id: 'pulpit', label: 'Dziś', gap: 40, render: renderDesk }, { id: 'funkcje', label: 'Lista funkcji', gap: 32, render: renderFunctions }] });
