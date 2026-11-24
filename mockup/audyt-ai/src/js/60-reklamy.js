/* ===== Reklamy: Google Ads i Meta Ads ===== */
state.ads = { metric: 'cpa', confirm: null, error: '' };
const sum = (a) => a.reduce((x, y) => x + y, 0);
const METRICS = { cpa: ['Koszt konwersji', (v) => zl(v, 2)], spend: ['Wydatki', (v) => zl(v)], conv: ['Konwersje', (v) => num(v)], clicks: ['Kliknięcia', (v) => num(v)] };
const AD_COLOR = { google: 'var(--s1)', meta: 'var(--s2)' };
const change = (cur, prev) => 100 * (cur - prev) / prev;
/* Dwa okresy po 4 tygodnie: bieżący i poprzedni. Koszt konwersji to wydatki podzielone przez konwersje. */
function period(rows) {
  const mk = (from) => { const part = (k) => sum(rows.map((p) => sum(p[k].slice(from, from + 4)))), spend = part('spend'), conv = part('conv'); return { spend, clicks: part('clicks'), conv, cpa: spend / conv }; };
  return { cur: mk(4), prev: mk(0) };
}
/* Wzrost kosztu jest zły, wzrost konwersji dobry. Wydatki i kliknięcia pokazujemy bez oceny. */
function delta(k, cur, prev) {
  const ch = change(cur, prev), cls = k === 'cpa' ? (ch > 0 ? ' delta neg' : ' delta') : (k === 'conv' ? (ch < 0 ? ' delta neg' : ' delta') : ' muted');
  return `<span class="small${cls}">${proc(ch, true)}</span>`;
}
const campaignOf = (id) => PLAT.ads.campaigns.find((c) => c.id === id);
const platformName = (id) => PLAT.ads.platforms.find((p) => p.id === id).name;

/* Alerty liczone z danych: budżet wykorzystany w co najmniej 90%, koszt konwersji wyższy o co najmniej 30%, kampania wstrzymana. */
function adsAlerts() {
  const out = [], left = new Date(TODAY.getFullYear(), TODAY.getMonth() + 1, 0).getDate() - TODAY.getDate();
  for (const p of PLAT.ads.platforms) {
    const used = p.month_spend / p.month_budget;
    if (used >= 0.9) out.push(['bad', `Budżet miesięczny ${p.name} wykorzystany w ${Math.round(100 * used)}%`, `Wydano ${zl(p.month_spend)} z ${zl(p.month_budget)}, do końca miesiąca ${dni(left)}.`]);
  }
  for (const c of PLAT.ads.campaigns) {
    const cur = c.spend / c.conv, prev = c.prev_spend / c.prev_conv, ch = change(cur, prev);
    if (c.active && ch >= 30) out.push(['mid', `Koszt konwersji w kampanii „${c.name}” wzrósł o ${num(ch)}%`, `${zl(cur, 2)} wobec ${zl(prev, 2)} w poprzednich 4 tygodniach.`]);
    if (!c.active) out.push(['plain', `Kampania „${c.name}” jest wstrzymana`, 'Wstrzymano ' + (c.paused || 'przed chwilą') + '.']);
  }
  return out;
}
const ALERT = { bad: 'pilne', mid: 'do sprawdzenia', plain: 'informacja' };
const adsNote = () => stan('zgoda', 'Dane są przykładowe. Podłączenie kont wymaga zatwierdzenia platformy przez Google i Meta.');

function renderAds() {
  const A = PLAT.ads, m = state.ads.metric, rows = A.platforms.map((p) => [p.name, period([p]), p.id]).concat([['Razem', period(A.platforms), null]]);
  const cell = (k, r) => `<td class="n">${METRICS[k][1](r.cur[k])}<br>${delta(k, r.cur[k], r.prev[k])}</td>`;
  const def = { title: METRICS[m][0] + ' tygodniami, Google Ads i Meta Ads', labels: A.weeks, prefix: 'Tydzień od ', tipFmt: METRICS[m][1], endFmt: (v) => num(v, m === 'cpa' ? 2 : 0),
    series: A.platforms.map((p) => ({ name: p.name, color: AD_COLOR[p.id], values: p.spend.map((s, i) => m === 'cpa' ? s / p.conv[i] : p[m][i]) })) };
  put('reklamy', adsNote() +
    `<div class="sec">${secHead('Ostatnie 4 tygodnie', 'Pod każdą liczbą zmiana wobec poprzednich 4 tygodni.')}
      <div class="table-wrap"><table style="min-width:620px"><thead><tr><th>Konto</th><th class="n">Wydatki</th><th class="n">Kliknięcia</th><th class="n">Konwersje</th><th class="n">Koszt konwersji</th></tr></thead><tbody>` +
      rows.map(([name, r, id]) => `<tr><td>${id ? `<span class="key"><i style="background:${AD_COLOR[id]}"></i><b>${esc(name)}</b></span>` : `<b>${esc(name)}</b>`}</td>${['spend', 'clicks', 'conv', 'cpa'].map((k) => cell(k, r)).join('')}</tr>`).join('') + `</tbody></table></div></div>
    <div class="sec">${secHead('Przebieg tygodniami')}
      <div class="bar-row" style="padding:16px 0 12px"><div class="switch wide" role="group" aria-label="Wybierz miarę">${Object.entries(METRICS).map(([k, v]) => `<button type="button" data-act="ad-metric" data-m="${k}" aria-pressed="${k === m}">${v[0]}</button>`).join('')}</div>${keys(def.series)}</div>
      <p class="small muted" style="padding-bottom:6px">${esc(METRICS[m][0])}${m === 'cpa' || m === 'spend' ? ', zł' : ''}</p>${chart('ads', def)}${dataTable(def, 'Tydzień od')}</div>
    <p class="note">Alerty z tych danych: ${adsAlerts().length}. <a href="#alerty">Zobacz alerty</a></p>`);
}

function renderCampaigns() {
  const A = PLAT.ads, cf = state.ads.confirm;
  const row = (c) => {
    const cur = c.spend / c.conv, prev = c.prev_spend / c.prev_conv;
    let h = `<tr${cf && cf.id === c.id ? ' class="own"' : ''}><td><span class="key"><i style="background:${AD_COLOR[c.platform]}"></i><b>${esc(c.name)}</b></span><br><span class="small muted">${esc(platformName(c.platform))}</span></td>
      <td>${st(c.active ? 'good' : 'plain', c.active ? 'aktywna' : 'wstrzymana')}</td><td class="n">${zl(c.budget)}</td><td class="n">${zl(c.spend)}</td><td class="n">${num(c.conv)}</td>
      <td class="n">${zl(cur, 2)}<br>${delta('cpa', cur, prev)}</td>
      <td><div class="acts"><button class="btn ghost sm" type="button" data-act="ad-ask" data-type="toggle" data-id="${c.id}">${c.active ? 'Wstrzymaj' : 'Wznów'}</button><button class="btn ghost sm" type="button" data-act="ad-ask" data-type="budget" data-id="${c.id}">Zmień budżet</button></div></td></tr>`;
    return h;
  };
  let ask = '';
  if (cf) {
    const c = campaignOf(cf.id);
    ask = `<div class="panel" id="adAsk" style="margin-top:16px">` + (cf.type === 'toggle'
      ? `<div class="acts"><span style="flex:1 1 260px"><b>${c.active ? 'Wstrzymać' : 'Wznowić'} kampanię „${esc(c.name)}”?</b> Zmiana trafi do dziennika.</span><button class="btn sm" type="button" id="adOk" data-act="ad-ok">Potwierdź</button><button class="btn ghost sm" type="button" data-act="ad-cancel">Anuluj</button></div>`
      : `<form class="form" id="adBudgetForm" novalidate><label class="field" for="adBudget" style="flex:1 1 220px;max-width:320px">Nowy budżet dzienny kampanii „${esc(c.name)}”, zł<input id="adBudget" type="text" inputmode="numeric" autocomplete="off" value="${c.budget}"></label>
          <button class="btn sm" type="submit">Potwierdź zmianę</button><button class="btn ghost sm" type="button" data-act="ad-cancel">Anuluj</button>
          ${state.ads.error ? `<p class="err" role="alert" style="flex-basis:100%">${esc(state.ads.error)}</p>` : `<p class="small muted" style="flex-basis:100%">Teraz ${zl(c.budget)} dziennie. Zmiana trafi do dziennika.</p>`}</form>`) + `</div>`;
  }
  put('kampanie', adsNote() +
    `<div class="sec">${secHead('Kampanie', 'Wydatki i konwersje z ostatnich 4 tygodni. Każda zmiana wymaga potwierdzenia.', A.campaigns.length)}${ask}
      <div class="table-wrap"><table style="min-width:900px"><thead><tr><th>Kampania</th><th>Stan</th><th class="n">Budżet dzienny</th><th class="n">Wydatki</th><th class="n">Konwersje</th><th class="n">Koszt konwersji</th><th>Zmiana</th></tr></thead><tbody>${A.campaigns.map(row).join('')}</tbody></table></div></div>
    <div class="sec">${secHead('Dziennik zmian', 'Kto, kiedy i co zmienił w kampaniach z tego panelu.', A.changes.length)}
      <div class="table-wrap"><table id="adLog"><thead><tr><th>Kiedy</th><th>Kto</th><th>Zmiana</th></tr></thead><tbody>${A.changes.map((x) => `<tr><td class="num">${esc(x.at)}</td><td>${esc(x.who)}</td><td>${esc(x.what)}</td></tr>`).join('')}</tbody></table></div></div>`);
}

function renderAlerts() {
  const list = adsAlerts();
  put('alerty', adsNote() +
    `<div class="sec">${secHead('Alerty', 'Liczone z danych kampanii przy każdym odświeżeniu.', list.length)}` +
      (list.length ? list.map(([cls, title, detail]) => `<div class="item" style="--c1:130px"><span>${st(cls, ALERT[cls])}</span><div><p><b>${esc(title)}</b></p><p>${esc(detail)}</p></div><div class="end"><a href="#kampanie">Kampanie</a></div></div>`).join('') : '<p class="muted" style="padding-top:14px">Brak alertów.</p>') + `</div>
    <div class="sec">${secHead('Kiedy powstaje alert')}<ul class="list" style="padding-top:16px">
      <li><b>Budżet:</b> wydano co najmniej 90% miesięcznego budżetu konta.</li>
      <li><b>Koszt konwersji:</b> wzrost o co najmniej 30% wobec poprzednich 4 tygodni w aktywnej kampanii.</li>
      <li><b>Kampania wstrzymana:</b> ręcznie albo przez platformę reklamową, np. po odrzuceniu reklamy.</li>
      <li>Alert trafia też na pulpit. Powiadomienie e-mail wymaga wysyłki e-maili z platformy.</li></ul></div>`);
}

ACTIONS['ad-metric'] = (el) => { state.ads.metric = el.dataset.m; rerender('reklamy'); const b = document.querySelector(`[data-act="ad-metric"][data-m="${el.dataset.m}"]`); if (b) b.focus(); };
ACTIONS['ad-ask'] = (el) => { state.ads.confirm = { id: el.dataset.id, type: el.dataset.type }; state.ads.error = ''; rerender('kampanie'); refocus(el.dataset.type === 'toggle' ? 'adOk' : 'adBudget'); };
ACTIONS['ad-cancel'] = () => { state.ads.confirm = null; rerender('kampanie'); };
function adLog(what) { PLAT.ads.changes.unshift({ at: 'przed chwilą', who: PLAT.org.user, what }); state.ads.confirm = null; rerender('kampanie', 'alerty', 'reklamy', 'pulpit'); }
ACTIONS['ad-ok'] = () => {
  const c = campaignOf(state.ads.confirm.id);
  c.active = !c.active; c.paused = null;
  adLog(`${c.name}: ${c.active ? 'wznowiono' : 'wstrzymano'} kampanię`);
};
FORMS.adBudgetForm = () => {
  const c = campaignOf(state.ads.confirm.id), raw = document.getElementById('adBudget').value.trim(), v = Number(raw);
  if (!/^\d+$/.test(raw) || v < 1 || v > 10000) { state.ads.error = 'Wpisz kwotę w pełnych złotych, od 1 do 10 000.'; rerender('kampanie'); refocus('adBudget'); return; }
  if (v === c.budget) { state.ads.error = 'To jest obecny budżet. Wpisz inną kwotę albo anuluj.'; rerender('kampanie'); refocus('adBudget'); return; }
  const old = c.budget;
  c.budget = v; state.ads.error = '';
  adLog(`${c.name}: budżet dzienny z ${num(old)} zł na ${num(v)} zł`);
};

MODS.push({ id: 'reklamy', group: 'marketing', order: 6, label: 'Reklamy', about: 'Wydatki, konwersje i koszt konwersji z Google Ads i Meta w jednym widoku, alerty i proste zarządzanie kampaniami.',
  head: (v) => ({ crumbs: 'Google Ads i Meta Ads', title: { reklamy: 'Wyniki reklam', kampanie: 'Kampanie', alerty: 'Alerty' }[v.id], facts: [['Klient', PLAT.ads.client], ['Okres', '19.10-15.11.2026'], ['Porównanie z', '21.09-18.10.2026']] }),
  views: [{ id: 'reklamy', label: 'Wyniki', render: renderAds }, { id: 'kampanie', label: 'Kampanie', render: renderCampaigns }, { id: 'alerty', label: 'Alerty', render: renderAlerts }] });
