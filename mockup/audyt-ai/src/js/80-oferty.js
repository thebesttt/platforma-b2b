/* ===== Oferty i wnioski ===== */
const docOf = (sections) => sections.map(([title, body]) => `<div class="doc" style="padding:16px 0;border-bottom:1px solid var(--line)"><h3 style="margin-bottom:8px">${esc(title)}</h3>${body.split('\n').map((l) => `<p>${marks(l)}</p>`).join('')}</div>`).join('');

function renderOffer() {
  const o = PLAT.offer, t = tenderOf(o.tender), todo = unsourced(o.sections), docs = PLAT.tender_card.docs;
  put('oferta', stan('silnik', 'Szkic z ogłoszenia, analizy SWZ i biblioteki firmy. Eksport do .docx.') +
    `<p class="note">AI pisze szkic, człowiek zatwierdza i podpisuje. Platforma niczego nie składa sama.</p>
    <div class="sec">${secHead('Szkic oferty', 'Każde zdanie ma źródło albo znacznik „sprawdź”. Zdania ze znacznikiem trzeba potwierdzić albo usunąć przed złożeniem.', 'do sprawdzenia: ' + todo)}${docOf(o.sections)}</div>
    <div class="two">
      <div class="sec">${secHead('Do złożenia z ofertą', 'Ta sama lista co w karcie przetargu.', 'brakuje ' + docs.filter((d) => !d[1]).length)}<div style="padding-top:16px">${checkList(docs.map(([text, ok]) => [ok, text]), 'gotowe', 'brakuje')}</div></div>
      <div class="sec">${secHead('Co dalej')}<ul class="list" style="padding-top:16px">
        <li>Uzupełnij miejsca w nawiasach kwadratowych: cenę, harmonogram, osoby.</li>
        <li>Dodaj brakujące referencje w <a href="#biblioteka">bibliotece firmy</a>. Pojawią się w szkicu same.</li>
        <li>Termin składania ofert: <b>${dpl(t.deadline)}</b>, zostało ${dni(daysTo(t.deadline))}.</li></ul>
        <div class="acts" style="padding-top:16px"><button class="btn" type="button" data-note>Napisz całą ofertę</button><p class="note" hidden style="flex-basis:100%">W panelu ten przycisk pisze po kolei wszystkie sekcje, których nikt nie oznaczył jako gotowe, tak jak we wniosku o dotację. Każda sekcja to jedno użycie AI.</p></div>
        <div class="acts" style="padding-top:12px"><button class="btn ghost" type="button" data-note>Pobierz .docx</button><p class="note" hidden style="flex-basis:100%">W mockupie nie ma pliku. W panelu ten przycisk pobiera ofertę w formacie .docx.</p></div></div>
    </div>`);
}

function renderGrants() {
  const g = PLAT.grants, q = g.query;
  const src = PLAT.grant_sources.map(([name, url, link, what]) => `<div class="item" style="--c1:250px">
      <div><b>${esc(name)}</b><p class="small"><a href="${esc(url)}" target="_blank" rel="noopener noreferrer">${esc(link)}</a></p></div><div><p>${esc(what)}</p></div><span></span></div>`).join('');
  const when = (f) => f.opens && daysTo(f.opens) > 0 ? st('plain', 'start ' + dpl(f.opens)) : (f.until ? leftState(daysTo(f.until), 'do ' + dpl(f.until) + ', ' + dni(daysTo(f.until))) : st('plain', 'bez daty końca'));
  const finds = g.finds.filter((f) => !f.done);
  const writing = g.writing || 0, left = unsourced(g.sections);
  put('dotacje', `<div class="sec">${secHead('Znajdź nabory dla firmy', 'Opisujesz, na co szukasz pieniędzy. AI przeszukuje internet, wybiera nabory, które trwają albo są zapowiedziane, i przy każdym podaje stronę, na której go znalazła.')}${stan('silnik', 'Wyszukiwarka naborów. Jedno wyszukiwanie to jedno użycie AI z pakietu. W pakiecie Rozszerzonym panel powtarza je co tydzień sam.')}
      <div class="form" style="padding-top:16px"><label class="field" for="gfWhat" style="flex:1 1 100%">Na co szukasz dofinansowania<input id="gfWhat" type="text" maxlength="300" value="${esc(q.what)}"></label>
        <label class="field" for="gfRegion" style="flex:0 1 220px">Województwo<input id="gfRegion" type="text" value="${esc(q.region)}"></label>
        <label class="field" for="gfSize" style="flex:0 1 220px">Wielkość firmy<input id="gfSize" type="text" value="${esc(q.size)}"></label>
        <button class="btn" type="button" data-act="o-find">Szukaj naborów</button></div>
      <p class="note" id="gfNote" tabindex="-1" hidden style="margin-top:14px"></p></div>
    <div class="sec">${secHead('Znalezione nabory', 'Propozycje do sprawdzenia. Na listę trafia tylko nabór, którego adres był w wynikach wyszukiwania, a nabory zakończone odpadają.', finds.length)}` +
      (finds.length ? finds.map((f) => `<div style="padding:18px 0;border-bottom:1px solid var(--line)" class="stack">
        <div class="bar-row"><div><h3>${esc(f.name)}</h3><p class="small muted">${f.inst ? esc(f.inst) + ', ' : ''}źródło: ${esc(f.host)} ${f.official ? st('good', 'strona urzędowa') : st('mid', 'inne źródło, sprawdź u instytucji')}</p></div>${when(f)}</div>
        <p>${esc(f.fit)}</p>${f.amount || f.audience ? `<p class="small">${f.amount ? '<b>Ile:</b> ' + esc(f.amount) : ''}${f.amount && f.audience ? '<br>' : ''}${f.audience ? '<b>Dla kogo:</b> ' + esc(f.audience) : ''}</p>` : ''}
        ${f.quote ? `<p class="small muted">Ze strony: „${esc(f.quote)}”</p>` : ''}
        <div class="acts"><button class="btn sm" type="button" data-act="o-find-go" data-id="${f.id}" data-how="write">Pisz wniosek</button><button class="btn ghost sm" type="button" data-act="o-find-go" data-id="${f.id}" data-how="watch">Obserwuj</button><button class="btn ghost sm" type="button" data-act="o-find-go" data-id="${f.id}" data-how="skip">Nie dla nas</button></div></div>`).join('')
        : '<p class="small muted" style="padding-top:16px">Wszystkie propozycje są przejrzane. Nowe pojawią się po kolejnym wyszukiwaniu.</p>') +
      `<p class="small muted" style="padding-top:14px">Listę przygotowuje AI na podstawie wyszukiwarki internetowej. Może pominąć nabór albo źle odczytać datę, dlatego przed pisaniem wniosku otwórz stronę naboru i sprawdź termin oraz regulamin.</p></div>
    <div class="sec" id="gcList">${secHead('Obserwowane nabory', 'Panel przypomina o końcu naboru 14, 7 i 3 dni wcześniej, a po wgraniu regulaminu sprawdza warunki udziału.', g.calls.length)}${stan('silnik', 'Lista naborów z przypomnieniami i ocena warunków na wgranym regulaminie.')}` +
      g.calls.map((c) => { const open = c.checks.filter((x) => !x[0]).length; return `<div style="padding:18px 0;border-bottom:1px solid var(--line)" class="stack">
        <div class="bar-row"><div><h3>${esc(c.name)}</h3><p class="small muted">${c.until ? 'Wnioski do ' + esc(c.until) : 'Bez daty końca'}${c.amount ? ', ' + esc(c.amount) : ''}</p></div>${c.checks.length ? st(open ? 'mid' : 'good', open ? 'do sprawdzenia: ' + open : 'warunki spełnione') : st('plain', 'obserwowany')}</div>
        ${c.checks.length ? checkList(c.checks, 'spełnione', 'do sprawdzenia') : '<p class="small muted">Warunki udziału panel sprawdzi po wgraniu regulaminu do wniosku.</p>'}</div>`; }).join('') + `</div>
    <div class="sec" id="gcDoc">${secHead('Wniosek o dotację', 'Jeden przycisk pisze wszystkie sekcje, których nikt nie oznaczył jako gotowe. AI korzysta tylko z regulaminu naboru i biblioteki firmy.', 'do sprawdzenia: ' + left)}${stan('silnik', 'Wniosek z własnymi sekcjami i dokumentami naboru. Każda sekcja to jedno użycie AI.')}
      <div class="acts" style="padding-top:16px"><button class="btn" type="button" data-act="o-write-all"${writing ? ' disabled' : ''}>${writing ? 'AI pisze sekcję ' + writing + ' z ' + g.sections.length + '…' : (g.wrote ? 'Napisz cały wniosek od nowa' : 'Napisz cały wniosek')}</button>
        <span class="small muted">${g.wrote ? 'AI napisało ' + g.sections.length + ' sekcje. Przeczytaj je, popraw i oznacz jako gotowe.' : 'Sekcje: ' + g.sections.length + '. Regulamin i kryteria oceny wgrywasz sam, bo panel nie pobiera plików z zabezpieczonych stron.'}</span></div>
      ${docOf(g.sections)}</div>
    <div class="sec">${secHead('Oficjalne listy naborów', 'Pełne listy prowadzą serwisy funduszy. Są zabezpieczone przed automatami, więc panel ich nie pobiera: tu sprawdzisz termin i regulamin.')}${src}</div>
    <div class="sec">${secHead('Wpisz nabór ręcznie', 'Dla naboru, którego wyszukiwarka nie znalazła. Wystarczy nazwa. Z datą końca panel przypomni o naborze.')}
      <div class="form" style="padding-top:16px"><label class="field" for="gcName" style="flex:2 1 300px">Nabór albo program<input id="gcName" type="text" maxlength="200"></label>
        <label class="field" for="gcUntil" style="flex:0 1 190px">Koniec naboru<input id="gcUntil" type="date"></label>
        <label class="field" for="gcAmount">Kwota albo poziom dofinansowania, opcjonalnie<input id="gcAmount" type="text" maxlength="120"></label>
        <button class="btn" type="button" data-act="o-call-add">Zapisz nabór</button></div>
      <p class="note" id="gcNote" tabindex="-1" hidden style="margin-top:14px"></p></div>`);
}

const sayIn = (id, text) => { const n = document.getElementById(id); if (!n) return; n.textContent = text; n.hidden = false; n.focus(); };

ACTIONS['o-find'] = () => {
  const what = document.getElementById('gfWhat').value.trim();
  if (what.length < 10) { sayIn('gfNote', 'Napisz w jednym lub dwóch zdaniach, na co szukasz dofinansowania.'); return; }
  PLAT.grants.query.what = what;
  for (const f of PLAT.grants.finds) f.done = false;
  rerender('dotacje');
  sayIn('gfNote', 'W mockupie lista jest przykładowa. W panelu AI szuka w internecie do dwóch minut i dopisuje nowe nabory razem ze źródłami.');
};

ACTIONS['o-find-go'] = (btn) => {
  const f = PLAT.grants.finds.find((x) => x.id === btn.dataset.id), how = btn.dataset.how;
  f.done = true;
  if (how !== 'skip') PLAT.grants.calls.push({ name: f.name, until: f.until ? dpl(f.until) : '', amount: f.amount, checks: [] });
  rerender('dotacje');
  if (how === 'write') { document.getElementById('gcDoc').scrollIntoView({ block: 'start' }); return; }
  sayIn('gfNote', how === 'skip' ? 'Nabór zdjęty z listy znalezionych.' : 'Nabór jest na liście obserwowanych. Panel przypomni o końcu naboru 14, 7 i 3 dni wcześniej.');
};

/* Pisanie całego wniosku: sekcja po sekcji, tak jak w panelu. W mockupie treść jest przykładowa, a krok trwa chwilę. */
ACTIONS['o-write-all'] = () => {
  const g = PLAT.grants;
  if (g.writing) return;
  const step = (i) => {
    if (i >= g.sections.length) { g.writing = 0; g.wrote = true; rerender('dotacje'); return; }
    g.writing = i + 1;
    rerender('dotacje');
    window.setTimeout(() => { g.sections[i][1] = g.written[i]; step(i + 1); }, 900);
  };
  step(0);
};

ACTIONS['o-call-add'] = () => {
  const val = (id) => document.getElementById(id).value.trim();
  const [name, until, amount] = ['gcName', 'gcUntil', 'gcAmount'].map(val);
  const say = (text) => { const n = document.getElementById('gcNote'); n.textContent = text; n.hidden = false; n.focus(); };
  if (!name) { say('Podaj nazwę naboru albo programu.'); return; }
  PLAT.grants.calls.push({ name, until: until ? dpl(until) : '', amount, checks: [] });
  rerender('dotacje');
  say('Zapisano nabór. W panelu przycisk „Rozpocznij wniosek” zakłada z niego szkic z nazwą, linkiem i terminem.');
};

function renderLibrary() {
  const kinds = [...new Set(PLAT.library.map((i) => i[0]))];
  put('biblioteka', stan('silnik', 'Z biblioteki korzystają szkice ofert, wniosków i wiadomości.') +
    `<div class="sec">${secHead('Biblioteka firmy', 'Wpisujesz raz, a szkice biorą stąd fakty ze wskazaniem źródła. AI nie wymyśla referencji ani liczb.', PLAT.library.length)}` +
      kinds.map((k) => PLAT.library.filter((i) => i[0] === k).map((i, n) => `<div class="item" style="--c1:250px"><span class="lead-col">${n ? '' : esc(k)}</span><div><p><b>${esc(i[1])}</b></p><p>${esc(i[2])}</p></div><span></span></div>`).join('')).join('') + `</div>`);
}

MODS.push({ id: 'oferty', order: 8, label: 'Oferty', about: 'Szkic oferty z ogłoszenia i biblioteki firmy, wyszukiwarka naborów, wniosek o dotację jednym przyciskiem i eksport do .docx.',
  head: (v) => v.id === 'oferta'
    ? { crumbs: 'Oferty i wnioski', title: 'Oferta: ' + tenderOf(PLAT.offer.tender).title, facts: [['Zamawiający', tenderOf(PLAT.offer.tender).buyer], ['Składanie ofert', dpl(tenderOf(PLAT.offer.tender).deadline)]] }
    : { crumbs: 'Oferty i wnioski', title: { dotacje: 'Nabory i wnioski o dotacje', biblioteka: 'Biblioteka firmy' }[v.id] },
  views: [{ id: 'oferta', label: 'Oferta', render: renderOffer }, { id: 'dotacje', label: 'Dotacje', gap: 40, render: renderGrants }, { id: 'biblioteka', label: 'Biblioteka firmy', render: renderLibrary }] });
