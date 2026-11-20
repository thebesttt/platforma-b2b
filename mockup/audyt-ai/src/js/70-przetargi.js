/* ===== Przetargi ===== */
const norm = (s) => s.toLowerCase().replace(/[ąćęłńóśźż]/g, (c) => ({ ą: 'a', ć: 'c', ę: 'e', ł: 'l', ń: 'n', ó: 'o', ś: 's', ź: 'z', ż: 'z' }[c])).replace(/[^a-z0-9]+/g, ' ').trim();
const daysExact = (iso) => (new Date(iso + ':00') - TODAY) / 86400000;

/* Dopasowanie ogłoszenia do profilu. Te same reguły i punkty co w silniku (Matcher::score):
   województwo 10, kod CPV do 60, słowa kluczowe do 40, wartość +10 albo -25, mniej niż 3 dni na ofertę -15. */
function matchTender(t, p) {
  const text = ' ' + norm(t.title + ' ' + t.text) + ' ', reasons = [];
  let score = 0;
  if (p.regions.length) {
    if (!p.regions.includes(t.region)) return { out: 'województwo spoza profilu: ' + t.region };
    score += 10; reasons.push('Województwo: ' + t.region);
  }
  for (const kw of p.exclude) if (text.includes(' ' + norm(kw))) return { out: 'słowo wykluczające: ' + kw };
  let best = 0, code = '';
  for (const raw of p.cpv) {
    const prefix = raw.replace(/\D/g, '').replace(/0+$/, '');
    if (prefix.length < 2) continue;
    for (const c of t.cpv_all) {
      if (!c.startsWith(prefix)) continue;
      const pts = (c === t.cpv_main ? 50 : 38) + Math.min(10, (prefix.length - 2) * 2);
      if (pts > best) { best = pts; code = c; }
    }
  }
  if (best) { score += Math.min(60, best); reasons.push('Kod CPV ' + code + (code === t.cpv_main ? ' (główny)' : '')); }
  const hits = p.keywords.filter((kw) => text.includes(' ' + norm(kw)));
  if (hits.length) { score += Math.min(40, 20 + 10 * (hits.length - 1)); reasons.push('Słowa kluczowe: ' + hits.slice(0, 4).join(', ')); }
  if (!best && !hits.length) return { out: 'brak kodu CPV i słów kluczowych z profilu' };
  if (t.value !== null) {
    if (t.value < p.min || t.value > p.max) { score -= 25; reasons.push('Wartość poza Twoim zakresem'); }
    else { score += 10; reasons.push('Wartość w Twoim zakresie'); }
  }
  if (daysExact(t.deadline) < 3) { score -= 15; reasons.push('Mniej niż 3 dni na ofertę'); }
  return { score: Math.max(0, Math.min(100, score)), reasons };
}
function matchAll() {
  const all = PLAT.tenders.map((t) => ({ t, m: matchTender(t, PLAT.profile) }));
  return { ok: all.filter((x) => !x.m.out).sort((a, b) => b.m.score - a.m.score), out: all.filter((x) => x.m.out) };
}
const tenderOf = (id) => PLAT.tenders.find((t) => t.id === id);
const leftState = (d, text) => st(d <= 3 ? 'bad' : (d <= 7 ? 'mid' : 'plain'), text);

function renderTenders() {
  const { ok, out } = matchAll(), next = ok.slice().sort((a, b) => a.t.deadline.localeCompare(b.t.deadline))[0];
  put('przetargi', stan('silnik', 'Import z BZP i TED oraz dopasowanie do profilu. Ogłoszenia w mockupie są zmyślone.') +
    `<div class="counts">
      <div><b>${ok.length}</b><span>dopasowanych ogłoszeń</span></div><div><b>${ok.filter((x) => x.m.score >= 70).length}</b><span>z oceną od 70 pkt</span></div>
      <div><b>${dni(daysTo(next.t.deadline))}</b><span>do najbliższego terminu</span></div><div><b>${out.length}</b><span>odrzuconych przez profil</span></div></div>
    <div class="sec">${secHead('Dopasowane przetargi', 'Ocena 0-100 liczona z profilu „' + esc(PLAT.profile.name) + '”. Pod tytułem widać, z czego wynika.', ok.length)}` +
      ok.map(({ t, m }) => { const d = daysTo(t.deadline), card = t.id === PLAT.tender_card.id; return `<div class="item" style="--c1:58px">
        <span class="big" data-score="${t.id}">${m.score}</span>
        <div><p><b>${card ? `<a href="#przetarg">${esc(t.title)}</a>` : esc(t.title)}</b></p><p>${esc(t.buyer)}, ${esc(t.source)}${t.value === null ? '' : ', ' + zl(t.value)}</p><p>${esc(m.reasons.join('. '))}.</p></div>
        <div class="end">${leftState(d, 'do ' + dpl(t.deadline) + ', ' + dni(d))}</div></div>`; }).join('') + `</div>
    <div class="sec">${secHead('Odrzucone przez profil', 'Te ogłoszenia nie trafiają na listę ani do porannego e-maila.', out.length)}` +
      out.map(({ t, m }) => `<div class="item" style="--c1:58px"><span></span><div><p>${esc(t.title)}</p><p>${esc(t.buyer)}</p></div><div class="end"><span class="small muted">${esc(m.out)}</span></div></div>`).join('') + `</div>`);
}

function renderTender() {
  const c = PLAT.tender_card, t = tenderOf(c.id), buyer = companyOf(t.buyer_company), r = companyRisk(buyer), missing = c.docs.filter((d) => !d[1]).length;
  const rowsOf = (list) => list.map(([label, value, src]) => `<div class="item" style="--c1:150px"><span class="lead-col">${esc(label)}</span><div><p>${esc(value)}</p></div><div class="end"><span class="src">${esc(src)}</span></div></div>`).join('');
  put('przetarg', stan('silnik', 'Karta z ogłoszenia i analiza dokumentów przez AI. Analiza wymaga klucza API.') +
    `<div class="sec">${secHead('Najważniejsze z ogłoszenia', 'Każdy punkt ma wskazaną sekcję ogłoszenia.')}${rowsOf(c.notice)}</div>
    <div class="sec">${secHead('Analiza dokumentacji (AI)', 'Wgrane pliki: ' + esc(c.ai.docs.join(', ')) + '. AI pisze tylko z tych dokumentów i podaje rozdział.')}
      <p style="padding:14px 0 6px;max-width:80ch">${esc(c.ai.summary)}</p>${rowsOf(c.ai.items)}</div>
    <div class="two">
      <div class="sec">${secHead('Na co uważać')}` + c.risks.map(([cls, text, src]) => `<div class="item" style="--c1:96px"><span>${st(cls, cls === 'bad' ? 'wysokie' : 'średnie')}</span><div><p>${esc(text)}</p><p class="src">${esc(src)}</p></div><span></span></div>`).join('') + `</div>
      <div class="stack" style="gap:28px">
        <div class="sec">${secHead('Pytania warte zadania zamawiającemu')}<ul class="list" style="padding-top:16px">${c.ai.questions.map((q) => `<li>${esc(q)}</li>`).join('')}</ul></div>
        <div class="sec">${secHead('Zamawiający jako płatnik')}<p style="padding-top:14px">${st(r.cls, 'ryzyko ' + r.label.toLowerCase())}<span style="margin-left:10px">${r.score} / 100, tylko dane publiczne.</span> <a href="#kontrahent" data-act="co-open" data-id="${buyer.id}">Otwórz kartę firmy</a></p></div>
      </div>
    </div>
    <div class="sec">${secHead('Zapytaj dokumentację', 'Odpowiedź powstaje tylko z wgranych plików i zawsze podaje źródło.')}
      <div class="stack" style="padding-top:16px;max-width:80ch"><p><b>${esc(c.ai.qa.q)}</b></p><p>${esc(c.ai.qa.a)} <span class="src">${esc(c.ai.qa.src)}</span></p>
        <form class="form" id="tenderAsk"><label class="field" for="tq">Twoje pytanie<input id="tq" type="text" placeholder="np. Jaki jest termin płatności?"></label><button class="btn ghost" type="submit">Zapytaj</button></form>
        <p class="note" id="tqNote" hidden>W mockupie jest tylko przykładowe pytanie powyżej. W panelu AI odpowiada z wgranych dokumentów i podaje rozdział.</p></div></div>
    <div class="sec">${secHead('Do złożenia z ofertą', 'Lista wynika z ogłoszenia i dokumentów. W ofercie zespół odhacza gotowe pozycje i dopisuje własne.', 'brakuje ' + missing)}<div style="padding-top:16px">${checkList(c.docs.map(([text, ok]) => [ok, text]), 'gotowe', 'brakuje')}</div>
      <div class="acts" style="padding-top:18px"><a class="btn" href="#oferta">Otwórz szkic oferty</a><a class="btn ghost" href="#terminy">Terminy</a></div></div>`);
}

function renderProfile() {
  const p = PLAT.profile, row = (k, v) => `<dt>${k}</dt><dd>${esc(v)}</dd>`;
  put('profil', stan('silnik', 'Profil, dopasowanie i poranny przegląd e-mailem. Przegląd wychodzi tylko wtedy, gdy jest coś nowego.') +
    `<div class="two">
      <div class="sec">${secHead('Profil: ' + esc(p.name), 'Firma może mieć kilka profili, np. osobno dla stron WWW i dla kampanii.')}
        <dl class="kv" style="padding-top:16px;grid-template-columns:150px minmax(0,1fr)">${row('Kody CPV', p.cpv.join(', '))}${row('Słowa kluczowe', p.keywords.join(', '))}${row('Słowa wykluczające', p.exclude.join(', '))}
          ${row('Województwa', p.regions.join(', '))}${row('Wartość', 'od ' + zl(p.min) + ' do ' + zl(p.max))}${row('Źródła', 'BZP i TED')}${row('Poranny e-mail', 'po codziennym imporcie')}</dl></div>
      <div class="sec">${secHead('Jak liczymy dopasowanie', 'Ocena od 0 do 100. Te same reguły dają zawsze ten sam wynik.')}
        <div class="table-wrap"><table><thead><tr><th>Co sprawdzamy</th><th class="n">Punkty</th></tr></thead><tbody>
          <tr><td>Kod CPV z profilu jest głównym kodem ogłoszenia</td><td class="n">50-60</td></tr>
          <tr><td>Kod CPV z profilu jest kodem dodatkowym</td><td class="n">38-48</td></tr>
          <tr><td>Słowa kluczowe w tytule i opisie: pierwsze 20, każde kolejne 10</td><td class="n">do 40</td></tr>
          <tr><td>Województwo z profilu</td><td class="n">10</td></tr>
          <tr><td>Wartość w Twoim zakresie</td><td class="n">10</td></tr>
          <tr><td>Wartość poza Twoim zakresem</td><td class="n">-25</td></tr>
          <tr><td>Mniej niż 3 dni na ofertę</td><td class="n">-15</td></tr></tbody></table></div>
        <p class="small muted" style="padding-top:12px">Ogłoszenie odpada całkiem, gdy ma słowo wykluczające, jest z innego województwa albo nie pasuje ani kodem, ani słowem.</p></div>
    </div>`);
}

function renderTerms() {
  put('terminy', stan('silnik', 'Terminy z kart przetargów. W panelu każdy termin dodasz do własnego kalendarza plikiem .ics.') +
    `<div class="sec">${secHead('Terminy', 'Od najbliższego. Dni liczone od ' + dpl(PLAT.today) + '.', PLAT.terms.length)}` +
      PLAT.terms.map(([at, what, id]) => { const d = daysTo(at); return `<div class="item" style="--c1:150px"><span class="num"><b>${dpl(at)}</b></span><div><p><b>${esc(what)}</b></p><p>${esc(tenderOf(id).title)}</p></div><div class="end">${leftState(d, 'za ' + dni(d))}</div></div>`; }).join('') + `</div>`);
}

FORMS.tenderAsk = () => { document.getElementById('tqNote').hidden = false; };

MODS.push({ id: 'przetargi', order: 7, label: 'Przetargi', about: 'Codzienny import ogłoszeń z BZP i TED, dopasowanie do profilu, karta przetargu z analizą SWZ i kalendarz terminów.',
  head: (v) => {
    if (v.id !== 'przetarg') return { crumbs: 'Przetargi', title: { przetargi: 'Dopasowane przetargi', profil: 'Profil przetargowy', terminy: 'Kalendarz terminów' }[v.id] };
    const t = tenderOf(PLAT.tender_card.id);
    return { crumbs: 'Przetargi, karta ogłoszenia', title: t.title, facts: [['Zamawiający', t.buyer], ['Źródło', t.source], ['Wartość', zl(t.value)], ['Składanie ofert', dpl(t.deadline)], ['Dopasowanie', matchTender(t, PLAT.profile).score + ' / 100']] };
  },
  views: [{ id: 'przetargi', label: 'Dopasowane', gap: 32, render: renderTenders }, { id: 'przetarg', label: 'Karta przetargu', gap: 40, render: renderTender }, { id: 'profil', label: 'Profil', render: renderProfile }, { id: 'terminy', label: 'Terminy', render: renderTerms }] });
