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
  const finds = PLAT.grants.finds.filter((f) => !f.done);
  if (finds.length) items.push(['mid', 'Dotacje', 'Znalezione nabory do przejrzenia: ' + finds.length, 'Wyszukiwarka dopisała propozycje ze źródłami. Sprawdź termin i zdecyduj: obserwuj, pisz wniosek albo odrzuć.', 'dotacje']);
  const tr = PLAT.gsc.tracking;
  if (tr && tr.status !== 'ok') items.push(['bad', 'Pomiar', 'Zdarzenia kluczowe przestały się liczyć: ' + PLAT.tech.site, `Od ${dpl(tr.since)}. Ruch jest normalny, a Google Analytics nie liczy formularzy.`, 'google']);
  const hits = PLAT.monitors.filter((m) => m.state === 'hit');
  if (hits.length) items.push(['mid', 'Monitory', 'Warunek spełniony w monitorach: ' + hits.length, hits[0].detail, 'monitory']);
  const order = { bad: 0, mid: 1, plain: 2 };
  return items.sort((a, b) => order[a[0]] - order[b[0]]);
}

function renderDesk() {
  const items = todayItems(), label = { bad: 'pilne', mid: 'do zrobienia', plain: 'informacja' };
  put('pulpit', `<div class="sec">${secHead('Dziś', 'Lista powstaje z danych wszystkich modułów. Najpierw sprawy pilne.', items.length)}` +
    items.map(([cls, mod, title, detail, view]) => `<div class="item"><span class="lead-col">${esc(mod)}</span><div><p><b>${esc(title)}</b></p><p>${esc(detail)}</p></div><div class="end">${st(cls, label[cls])}<a href="#${view}">Otwórz</a></div></div>`).join('') + `</div>
    <div class="sec">${secHead('Moduły', 'Każdy moduł ma zakładki pod tytułem strony. Stan każdej funkcji jest w zakładce <a href="#funkcje">Lista funkcji</a>.', MODS.length - 1)}` +
    MODS.filter((m) => m.id !== 'pulpit').map((m, i, all) => (i === 0 || (all[i - 1].group !== m.group && (groupMods(m.group).length > 1 || groupMods(all[i - 1].group).length > 1)) ? `<p class="small muted" style="padding:16px 0 2px"><b>${esc(groupMods(m.group).length > 1 ? GROUPS[m.group] : 'Pozostałe')}</b></p>` : '') +
      `<div class="item"><span style="font-weight:600">${esc(m.label)}</span><div><p>${esc(m.about)}</p></div><div class="end"><a href="#${m.views[0].id}">Otwórz</a></div></div>`).join('') + `</div>`);
}

function renderFunctions() {
  const all = PLAT.functions.flatMap((g) => g.items), count = (k) => all.filter((f) => f[2] === k).length;
  put('funkcje', `<div class="counts"><div><b>${all.length}</b><span>funkcji w mockupie</span></div><div><b id="fnEngine">${count('silnik')}</b><span>działa w silniku</span></div>
      <div><b>${count('plan')}</b><span>do zbudowania</span></div><div><b>${count('zgoda')}</b><span>czeka na zgodę Google i Meta</span></div></div>
    <p class="note">Mockup pokazuje każdą funkcję na przykładowych danych. „Działa w silniku” znaczy, że kod jest w repozytorium i przechodzi testy. Silnik nie jest jeszcze uruchomiony online: do tego potrzeba konta na hostingu i kluczy, które zakłada właściciel.</p>` +
    PLAT.functions.map((g) => `<div class="sec">${secHead(esc(g.module), '', g.items.length)}` +
      g.items.map(([name, view, key, note]) => `<div class="item fn" style="--c1:minmax(0,1.25fr)"><a href="#${view}">${esc(name)}</a><span class="small muted">${esc(note)}</span><div class="end">${st(PLAT.states[key][0], PLAT.states[key][1])}</div></div>`).join('') + `</div>`).join(''));
}

MODS.push({ id: 'pulpit', group: 'start', order: 1, label: 'Pulpit', about: '',
  head: (v) => v.id === 'pulpit' ? { crumbs: 'Panel PerfectData', title: 'Pulpit', facts: [['Dzień', dpl(PLAT.today)], ['Użytkownik', PLAT.org.user + ' (przykład)']] } : { crumbs: 'Panel PerfectData', title: v.id === 'monitory' ? 'Monitory' : 'Lista funkcji' },
  views: [{ id: 'pulpit', label: 'Dziś', gap: 40, render: renderDesk }, { id: 'monitory', label: 'Monitory', gap: 32, render: renderMonitors }, { id: 'funkcje', label: 'Lista funkcji', gap: 32, render: renderFunctions }] });

/* ===== Monitory opisane jednym zdaniem ===== */
/* Rozpoznanie zdania jak w silniku (Monitors::parse): proste wzorce bez AI. Gdy panel nie rozpozna, w silniku pomaga AI. */
state.mon = { list: PLAT.monitors.map((m) => ({ ...m })), draft: null, msg: '', read: false };
const MON_KINDS = { gsc_clicks: 'Spadek kliknięć z Google', gsc_position: 'Pozycja zapytania w Google', rival_pages: 'Nowe strony u konkurencji', site_down: 'Strona nie działa albo działa wolno', tender: 'Nowy przetarg z frazą', inbound_silence: 'Brak zapytań ze strony' };
const fold = (s) => s.toLowerCase().replace(/[ąćęłńóśźż]/g, (c) => ({ ą: 'a', ć: 'c', ę: 'e', ł: 'l', ń: 'n', ó: 'o', ś: 's', ź: 'z', ż: 'z' }[c]));
function monParse(sentence) {
  const f = fold(sentence), host = PLAT.tech.site, n = (re) => { const m = f.match(re); return m ? Number(m[1]) : null; };
  const quoted = (sentence.match(/[„"“”']([^„"“”']{2,100})[„"“”']/) || [])[1] || null;
  if (f.includes('przetarg')) {
    const phrase = quoted || ((sentence.toLowerCase().match(/przetarg\S*\s+(?:na|o|z)\s+(.+?)(?=\s*(?:[,.;!?]|$|\s(?:o wartości|powyżej|ponad|od \d)))/) || [])[1] || null);
    const m = f.match(/(?:powyzej|ponad|od)\s*(\d[\d\s.,]*)\s*(tys\.?|mln)?/);
    const min = m ? Number(m[1].replace(/\s/g, '').replace(',', '.')) * ({ tys: 1e3, 'tys.': 1e3, mln: 1e6 }[m[2]] || 1) : null;
    return phrase ? { kind: 'tender', rule: `Pojawi się nowy przetarg z frazą „${phrase}” w tytule${min ? ' o wartości od ' + zl(min) : ''}.` } : null;
  }
  if (f.includes('zapyta') && /brak|nie przyjdzie|zadne|nikt|nie ma|cisza/.test(f)) {
    const d = n(/(\d{1,2})\s*(?:dni|dzien)/) || (f.includes('tydzien') ? 7 : 5);
    return { kind: 'inbound_silence', rule: `Przez ${d} dni nie przyjdzie żadne zapytanie ze strony.` };
  }
  if (/nie dziala|nie odpowiada|\bpadnie\b|niedostepn|awari|wolno|wolniej|dluzej niz/.test(f)) {
    const url = (f.match(/((?:https?:\/\/)?(?:[a-z0-9-]+\.)+[a-z]{2,}(?:\/\S*)?)/) || [])[1];
    return { kind: 'site_down', rule: `Strona ${url ? (url.startsWith('http') ? url : 'https://' + url) : 'https://' + host + '/'} nie odpowie poprawnie (kod inny niż 200) albo odpowie wolniej niż w ${n(/(\d{1,2})\s*(?:s\b|sek)/) || 5} s.` };
  }
  if (/pozycj|miejsc|top\s*\d/.test(f) && quoted) {
    return { kind: 'gsc_position', rule: `Średnia pozycja zapytania „${quoted}” na ${host} będzie gorsza niż ${n(/top\s*(\d{1,3})/) || n(/(?:pozycj\S*|miejsc\S*)\D{0,25}?(\d{1,3})/) || 10} (ostatni okres z Search Console).` };
  }
  if (/klikni|ruch z google|wejsc\S* z google|spadek ruchu/.test(f)) {
    return { kind: 'gsc_clicks', rule: `Kliknięcia z Google na ${host} w ostatnich 7 dniach spadną o co najmniej ${n(/(\d{1,2})\s*(?:%|proc)/) || 30}% wobec 7 dni wcześniej (średnio na dzień, bez świąt).` };
  }
  if (f.includes('konkuren')) {
    return { kind: 'rival_pages', rule: `Konkurenci witryny ${host} razem opublikują co najmniej ${n(/(\d{1,2})\s*(?:now\S*\s*)?(?:stron|wpis|artykul)/) || 3} nowe strony w 7 dni (według map witryn).` };
  }
  return null;
}
function renderMonitors() {
  const m = state.mon, chip = (s) => s === 'hit' ? st('bad', 'warunek spełniony') : s === 'na' ? st('plain', 'brak danych') : st('good', 'w normie');
  const examples = ['Powiadom mnie, gdy kliknięcia z Google spadną o 30%.', 'Daj znać, gdy strona sklep-przykladowy.example/kontakt nie działa albo ładuje się dłużej niż 5 s.', 'Powiadom, gdy przez 5 dni nie przyjdzie żadne zapytanie ze strony.', 'Daj znać, gdy „ekspres kolbowy” spadnie poza top 10.'];
  put('monitory', stan('silnik', 'Monitory tylko czytają dane. Sprawdzanie codziennie rano, bez AI. Tu dane przykładowe.') +
    `<div class="sec">${secHead('Nowy monitor', 'Opisz jednym zdaniem, czego panel ma pilnować. Panel zamieni zdanie na regułę z jawnym progiem.')}
      <form class="form" id="monForm" style="padding-top:14px">
        <label class="field" for="monS" style="flex:1 1 360px">Czego panel ma pilnować<input id="monS" type="text" maxlength="400" placeholder="Powiadom mnie, gdy…"></label>
        <div class="acts" style="align-self:end"><button class="btn" type="submit">Rozpoznaj</button></div></form>
      ${m.draft ? `<div class="item" style="--c1:110px"><span class="lead-col">Tak to rozumiem</span><div><p><b>${esc(MON_KINDS[m.draft.kind])}.</b> Powiadom, gdy… ${esc(m.draft.rule)}</p><p class="small muted">Twoje zdanie: „${esc(m.draft.sentence)}”</p></div><div class="end"><button class="btn sm" type="button" data-act="mon-save">Zapisz monitor</button><button class="btn ghost sm" type="button" data-act="mon-cancel">Anuluj</button></div></div>` : ''}
      ${m.msg ? `<p class="note" id="monMsg" tabindex="-1" role="status">${esc(m.msg)}</p>` : ''}
      <p class="small muted" style="padding-top:10px">Przykłady: ${examples.map((e) => '„' + esc(e) + '”').join(' ')}</p>
      <p class="small muted">Proste zdania panel rozpoznaje sam. Gdy nie rozpozna, pomaga AI (jedno użycie z pakietu na zdanie). Monitory w pakiecie Podstawowym: ${m.list.length} z 5.</p></div>
    <div class="sec">${secHead('Twoje monitory', '', m.list.length)}` +
      m.list.map((x, i) => `<div class="item" style="--c1:minmax(0,1.3fr)"><div><p><b>${esc(x.rule)}</b></p><p class="small muted">„${esc(x.sentence)}”${x.by === 'ai' ? ' · rozpoznało AI' : ''}</p></div><div><p class="small">${esc(x.detail)}</p><p class="small muted">${esc(x.at)}</p></div><div class="end">${chip(x.state)}<button class="btn ghost sm" type="button" data-act="mon-del" data-i="${i}">Usuń</button></div></div>`).join('') + `</div>
    <div class="sec">${secHead('Powiadomienia', 'Z monitorów, alarmu pomiaru Google Analytics i raportów miesięcznych. Te same trafiają do porannego przeglądu e-mailem.', PLAT.notices.length)}` +
      PLAT.notices.map(([cls, kind, title, detail, view, at]) => `<div class="item"><span class="lead-col">${esc(kind)}</span><div><p><b>${esc(title)}</b></p><p class="small muted">${esc(detail)} · ${esc(at)}</p></div><div class="end">${st(cls === 'high' ? 'bad' : cls === 'mid' ? 'mid' : 'plain', m.read ? 'przeczytane' : 'nowe')}<a href="#${view}">Otwórz</a></div></div>`).join('') +
      (m.read ? '' : '<div class="acts" style="padding-top:12px"><button class="btn ghost sm" type="button" data-act="mon-read">Oznacz wszystkie jako przeczytane</button></div>') + '</div>');
}
FORMS.monForm = () => {
  const s = document.getElementById('monS').value.trim(), m = state.mon;
  if (s.length < 8) { m.msg = 'Opisz jednym zdaniem, czego panel ma pilnować.'; m.draft = null; rerender('monitory'); refocus('monMsg'); return; }
  const r = monParse(s);
  m.draft = r ? { ...r, sentence: s } : null;
  m.msg = r ? '' : 'Panel nie rozpoznał tego zdania. W silniku pomaga wtedy AI (jedno użycie z pakietu), a bez AI wybierasz rodzaj z listy.';
  rerender('monitory'); refocus(r ? 'monS' : 'monMsg');
};
ACTIONS['mon-save'] = () => {
  const m = state.mon;
  if (m.list.length >= 5) { m.msg = 'Limit monitorów w pakiecie Podstawowy to 5. Usuń monitor, którego nie potrzebujesz, albo zmień pakiet.'; rerender('monitory'); refocus('monMsg'); return; }
  m.list.unshift({ kind: m.draft.kind, sentence: m.draft.sentence, rule: m.draft.rule, state: 'na', detail: 'Pierwsze sprawdzenie zaraz po zapisaniu. W silniku na Twoich danych.', at: 'teraz', by: 'panel' });
  m.draft = null; m.msg = 'Monitor zapisany. Panel sprawdza go codziennie rano.'; rerender('monitory'); refocus('monMsg');
};
ACTIONS['mon-cancel'] = () => { state.mon.draft = null; rerender('monitory'); refocus('monS'); };
ACTIONS['mon-del'] = (el) => { state.mon.list.splice(Number(el.dataset.i), 1); state.mon.msg = 'Monitor usunięty.'; rerender('monitory'); refocus('monMsg'); };
ACTIONS['mon-read'] = () => { state.mon.read = true; rerender('monitory'); };
