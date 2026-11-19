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
        <div class="acts" style="padding-top:16px"><button class="btn" type="button" data-note>Pobierz .docx</button><p class="note" hidden style="flex-basis:100%">W mockupie nie ma pliku. W panelu ten przycisk pobiera ofertę w formacie .docx.</p></div></div>
    </div>`);
}

function renderGrants() {
  const g = PLAT.grants;
  put('dotacje', `<div class="sec">${secHead('Otwarte nabory', 'Dla każdego naboru platforma sprawdza warunki, które da się ocenić z danych firmy.', g.calls.length)}${stan('plan', 'Bazy naborów i oceny warunków nie ma jeszcze w silniku. Dziś nabór wpisujesz ręcznie.')}` +
      g.calls.map((c) => { const open = c.checks.filter((x) => !x[0]).length; return `<div style="padding:18px 0;border-bottom:1px solid var(--line)" class="stack">
        <div class="bar-row"><div><h3>${esc(c.name)}</h3><p class="small muted">Wnioski do ${esc(c.until)}, ${esc(c.amount)}</p></div>${st(open ? 'mid' : 'good', open ? 'do sprawdzenia: ' + open : 'warunki spełnione')}</div>
        ${checkList(c.checks, 'spełnione', 'do sprawdzenia')}</div>`; }).join('') + `</div>
    <div class="sec">${secHead('Wniosek o dotację', 'Sekcje wniosku wynikają z regulaminu naboru. Po wgraniu regulaminu i kryteriów AI pisze każdą sekcję na ich podstawie.', 'do sprawdzenia: ' + unsourced(g.sections))}${stan('silnik', 'Wniosek z własnymi sekcjami i dokumentami naboru.')}${docOf(g.sections)}</div>`);
}

function renderLibrary() {
  const kinds = [...new Set(PLAT.library.map((i) => i[0]))];
  put('biblioteka', stan('silnik', 'Z biblioteki korzystają szkice ofert, wniosków i wiadomości.') +
    `<div class="sec">${secHead('Biblioteka firmy', 'Wpisujesz raz, a szkice biorą stąd fakty ze wskazaniem źródła. AI nie wymyśla referencji ani liczb.', PLAT.library.length)}` +
      kinds.map((k) => PLAT.library.filter((i) => i[0] === k).map((i, n) => `<div class="item" style="--c1:250px"><span class="lead-col">${n ? '' : esc(k)}</span><div><p><b>${esc(i[1])}</b></p><p>${esc(i[2])}</p></div><span></span></div>`).join('')).join('') + `</div>`);
}

MODS.push({ id: 'oferty', order: 8, label: 'Oferty', about: 'Szkic oferty z ogłoszenia i biblioteki firmy, lista dokumentów, wnioski o dotacje i eksport do .docx.',
  head: (v) => v.id === 'oferta'
    ? { crumbs: 'Oferty i wnioski', title: 'Oferta: ' + tenderOf(PLAT.offer.tender).title, facts: [['Zamawiający', tenderOf(PLAT.offer.tender).buyer], ['Składanie ofert', dpl(tenderOf(PLAT.offer.tender).deadline)]] }
    : { crumbs: 'Oferty i wnioski', title: { dotacje: 'Wnioski o dotacje', biblioteka: 'Biblioteka firmy' }[v.id] },
  views: [{ id: 'oferta', label: 'Oferta', render: renderOffer }, { id: 'dotacje', label: 'Dotacje', gap: 40, render: renderGrants }, { id: 'biblioteka', label: 'Biblioteka firmy', render: renderLibrary }] });
