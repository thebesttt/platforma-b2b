/* ===== Klienci i wiadomości ===== */
state.k = { contact: 4, kind: 'cold_offer', ack: false, saved: false, f: { pkd: '', region: '', tenders: '' } };
const RISK = { low: ['good', 'niskie'], high: ['mid', 'wysokie'], very_high: ['bad', 'bardzo wysokie'] };
const contactOf = (id) => PLAT.contacts.find((c) => c.id === id);
const basisLine = (c) => PLAT.bases[c.basis] + (c.detail ? ': ' + c.detail : '') + (c.at ? ' (' + c.at + ')' : '');

/* Ocena ryzyka prawnego wiadomości. Te same reguły co w silniku (RiskPolicy): platforma pokazuje ryzyko,
   decyzję podejmuje użytkownik, a jedyna twarda blokada to sprzeciw odbiorcy. */
function assessMessage(kind, c) {
  const has = (...b) => b.includes(c.basis), pke = PLAT.law.pke, basis = ['Podstawa kontaktu: ' + basisLine(c)];
  if (c.unsub && kind !== 'rfq_reply') {
    return { level: 'very_high', blocked: true, ack: true, lead: 'Odbiorca wypisał się z wiadomości. Nie możesz wysyłać mu ofert ani próśb o zgodę.', reasons: ['Sprzeciw zapisano ' + c.unsub + '.'], law: [PLAT.law.unsub] };
  }
  let level, lead, reasons = [], law = [];
  if (kind === 'rfq_reply') {
    if (has('rfq')) { level = 'low'; lead = 'Firma sama poprosiła o oferty. Odpowiedź na zapytanie nie wymaga osobnej zgody marketingowej.'; reasons = basis; }
    else { level = 'high'; lead = 'W karcie kontaktu nie ma zapisanego zapytania ofertowego od tej firmy. Bez niego wiadomość może zostać uznana za niezamówioną informację handlową.'; reasons = ['Dopisz w karcie kontaktu, gdzie i kiedy firma prosiła o oferty, np. link do zapytania.']; law = pke; }
  } else if (kind === 'consented') {
    if (has('consent', 'client')) { level = 'low'; lead = 'Odbiorca wyraził zgodę albo jest Twoim klientem. Pamiętaj o łatwym sposobie wypisania się w stopce.'; reasons = basis; }
    else { level = 'very_high'; lead = 'W karcie kontaktu nie ma zapisanej zgody. Wysłanie oferty bez zgody narusza przepisy o informacji handlowej.'; reasons = ['Zapisz w karcie kontaktu, kiedy i w jaki sposób odbiorca wyraził zgodę.']; law = pke; }
  } else if (kind === 'consent_request') {
    level = has('consent', 'client', 'rfq') ? 'low' : 'high';
    lead = level === 'low' ? 'Masz już podstawę kontaktu. Zamiast prosić o zgodę możesz od razu odpowiedzieć albo wysłać ofertę.'
      : 'Prośba o zgodę wysłana e-mailem bez wcześniejszej zgody to sprawa sporna. Sądy uznawały takie wiadomości za pośrednią promocję nadawcy.';
    if (level === 'high') { law = pke.concat(PLAT.law.consent_request); reasons = ['Wiadomość nie może zawierać oferty, cen ani opisu usług. Tylko pytanie o zgodę.']; }
  } else if (has('consent', 'client')) {
    level = 'low'; lead = 'Masz zapisaną zgodę lub relację z klientem, więc to w praktyce oferta za zgodą.'; reasons = basis;
  } else {
    level = 'very_high'; lead = 'Informacja handlowa bez wcześniejszej zgody odbiorcy jest zakazana, także wobec firm.'; law = pke;
    reasons = ['Odbiorca może złożyć skargę do Prezesa UKE albo pozew z tytułu nieuczciwej konkurencji.'];
  }
  return { level, blocked: false, ack: level !== 'low', lead, reasons, law };
}

/* Szkic regułowy, bez AI. Szablony jak w silniku. */
function draftMessage(kind, c) {
  const greet = c.person ? 'Dzień dobry, ' + c.person + ',' : 'Dzień dobry,';
  const sig = PLAT.org.user + '\n' + PLAT.org.name + '\n' + PLAT.org.site;
  const optOut = '\n\nJeśli nie chcą Państwo otrzymywać od nas wiadomości, wystarczy odpisać „rezygnuję”.';
  const about = '[Krótki opis oferty dopasowany do firmy odbiorcy]';
  if (kind === 'rfq_reply') return ['Odpowiedź na zapytanie ofertowe', `${greet}\n\ndziękujemy za zapytanie ofertowe. W załączeniu przesyłamy ofertę.\n\nCena: [CENA] zł netto\nTermin realizacji: [TERMIN]\nWarunki płatności: [WARUNKI]\n\nChętnie odpowiemy na pytania i doprecyzujemy zakres.\n\nZ poważaniem\n${sig}`];
  if (kind === 'consented') return ['Oferta ' + PLAT.org.name, `${greet}\n\nzgodnie z Państwa zgodą przesyłamy informację o naszej ofercie.\n\n${about}\n\nCzy możemy umówić krótką rozmowę w przyszłym tygodniu?\n\nZ poważaniem\n${sig}${optOut}`];
  if (kind === 'consent_request') return ['Czy możemy przesłać ofertę?', `${greet}\n\nczy wyrażają Państwo zgodę na przesłanie na ten adres e-mail jednej wiadomości z ofertą firmy ${PLAT.org.name}?\n\nWystarczy odpowiedzieć „tak”. Jeśli nie odpowiedzą Państwo, nie będziemy pisać ponownie.\n\nZ poważaniem\n${sig}`];
  return ['Propozycja współpracy: ' + PLAT.org.name, `${greet}\n\n${about}\n\nCzy możemy przesłać szczegóły?\n\nZ poważaniem\n${sig}${optOut}`];
}

function renderContacts() {
  const rows = PLAT.contacts.map((c) => `<div class="item" style="--c1:250px">
      <div><b>${esc(c.company)}</b><p class="small muted">${esc((c.person ? c.person + ', ' : '') + c.email)}</p></div>
      <div><p>${esc(basisLine(c))}</p>${c.unsub ? `<p class="warn">Wypisał się ${esc(c.unsub)}. Oferty i prośby o zgodę są zablokowane.</p>` : ''}</div>
      <div class="end">${c.unsub ? st('bad', 'sprzeciw') : (c.basis === 'none' ? st('mid', 'brak podstawy') : st('good', 'jest podstawa'))}<button class="btn ghost sm" type="button" data-act="k-write" data-id="${c.id}">Napisz</button></div></div>`).join('');
  put('kontakty', stan('silnik', 'Kontakty, podstawy kontaktu i historia wiadomości.') +
    `<div class="sec">${secHead('Kontakty', 'Przy każdym kontakcie zapisujesz, skąd masz adres i od kiedy. Od tej podstawy zależy ryzyko wiadomości.', PLAT.contacts.length)}${rows}</div>
    <div class="sec">${secHead('Skąd biorą się kontakty')}<ul class="list" style="padding-top:16px">
      <li><b>Zapytania ze strony.</b> Każde zapytanie z widżetu zapisuje się z podstawą „zapytanie od tej firmy”. <a href="#zapytania">Zobacz zapytania</a></li>
      <li><b>Zapytania ofertowe firm.</b> Firma, która publikuje zapytanie i adres do ofert, sama prosi o kontakt. <a href="#rfq">Zobacz zapytania ofertowe</a></li>
      <li><b>Wyszukiwanie firm.</b> Kontakt dodany z wyszukiwarki ma podstawę „brak”. Przed wysyłką zobaczysz ekran ryzyka. <a href="#firmy">Szukaj firm</a></li>
      <li><b>Własne kontakty.</b> Klienci i osoby, które zostawiły zgodę, np. na targach albo w formularzu.</li></ul></div>`);
}

function renderSearch() {
  const f = state.k.f, uniq = (k) => [...new Set(PLAT.search.map((x) => x[k]))].sort((a, b) => a.localeCompare(b, 'pl'));
  const sel = (id, label, key, opts) => `<label class="field" for="${id}">${label}<select id="${id}" data-on="k-filter" data-key="${key}"><option value="">Wszystkie</option>${opts.map(([v, t]) => `<option value="${esc(v)}"${f[key] === v ? ' selected' : ''}>${esc(t)}</option>`).join('')}</select></label>`;
  const found = PLAT.search.filter((x) => (!f.pkd || x.pkd === f.pkd) && (!f.region || x.region === f.region) && (!f.tenders || (f.tenders === 'tak') === x.tenders));
  const rows = found.map((x) => {
    const known = PLAT.contacts.find((c) => c.company === x.company);
    return `<div class="item" style="--c1:250px">
      <div><b>${esc(x.company)}</b><p class="small muted">${esc(x.pkd)}, ${esc(x.region)}</p></div>
      <div><p>${esc(x.reason)}</p><p>${esc(x.size)}, działa ${lata(x.age)}${x.tenders ? ', startuje w przetargach' : ''}</p></div>
      <div class="end">${st(x.condition === 'dobra' ? 'good' : 'mid', 'kondycja: ' + x.condition)}${known ? `<span class="small muted" tabindex="-1" data-added="${esc(x.company)}">${known.unsub ? 'W kontaktach, wypisany' : 'W kontaktach'}</span>` : `<button class="btn ghost sm" type="button" data-act="k-add" data-name="${esc(x.company)}">Dodaj do kontaktów</button>`}</div></div>`;
  }).join('');
  put('firmy', stan('plan', 'Wyszukiwarki firm nie ma jeszcze w silniku. Ekran pokazuje, jak ma działać.') +
    `<div class="sec">${secHead('Idealny klient', 'Opis powstaje z treści Twojej strony. Zatwierdzasz go albo poprawiasz, zanim posłuży do wyszukiwania.')}
      <p style="padding-top:14px;max-width:78ch">${esc(PLAT.icp)}</p></div>
    <div class="sec">${secHead('Firmy pasujące do opisu', 'Dane z rejestrów publicznych. Powód kontaktu pochodzi z audytu strony firmy.', found.length + ' z ' + PLAT.search.length)}
      <div class="filters" style="padding:16px 0 6px">${sel('fPkd', 'Branża', 'pkd', uniq('pkd').map((v) => [v, v]))}${sel('fRegion', 'Województwo', 'region', uniq('region').map((v) => [v, v]))}${sel('fTenders', 'Przetargi', 'tenders', [['tak', 'Startuje w przetargach'], ['nie', 'Nie startuje']])}</div>
      ${rows || '<p class="muted" style="padding:16px 0">Żadna firma nie pasuje do tych filtrów. Zmień albo wyczyść jeden z nich.</p>'}</div>
    <p class="note">Firma z wyszukiwarki nie prosiła o kontakt. Dodajesz ją z podstawą „brak”, a przy wiadomości zobaczysz ekran ryzyka. Niższe ryzyko mają <a href="#rfq">zapytania ofertowe firm</a>.</p>`);
}

function renderRfq() {
  const rows = PLAT.rfqs.map((r, i) => {
    const d = daysTo(r.deadline.split('.').reverse().join('-'));
    return `<div class="item" style="--c1:250px">
      <div><b>${esc(r.company)}</b><p class="small muted">${esc(r.where)}</p></div>
      <div><p>${esc(r.title)}</p><p>Opublikowano ${esc(r.published)}, oferty na adres ${esc(r.address)}</p></div>
      <div class="end">${st(d <= 7 ? 'mid' : 'plain', 'do ' + r.deadline + ', ' + dni(d))}<button class="btn ghost sm" type="button" data-act="k-rfq" data-i="${i}">Odpowiedz</button></div></div>`;
  }).join('');
  put('rfq', stan('plan', 'Podstawa kontaktu „zapytanie ofertowe” działa w silniku. Zbieranie zapytań ze źródeł jest do zbudowania.') +
    `<div class="sec">${secHead('Zapytania ofertowe firm', 'Firma, która publikuje zapytanie i adres do składania ofert, sama prosi o ofertę. To kanał o najniższym ryzyku prawnym.', PLAT.rfqs.length)}${rows}</div>
    <div class="sec">${secHead('Źródła')}<ul class="list" style="padding-top:16px">
      <li>Strony firm z zakładką zapytań ofertowych.</li><li>Baza Konkurencyjności: zamówienia firm z dotacjami.</li><li>Platformy zakupowe i biuletyny informacji publicznej.</li></ul></div>`);
}

function renderMessage() {
  const k = state.k, c = contactOf(k.contact), a = assessMessage(k.kind, c), [subj, body] = draftMessage(k.kind, c), lv = RISK[a.level];
  const kinds = Object.entries(PLAT.kinds).map(([id, [title, note]]) => `<label for="kind-${id}"><input type="radio" name="kind" id="kind-${id}" value="${id}" data-on="k-kind"${k.kind === id ? ' checked' : ''}><span>${esc(title)}</span><small>${esc(note)}</small></label>`).join('');
  let decision;
  if (k.saved) {
    decision = `<p class="note" id="kSaved" tabindex="-1">Decyzja zapisana. W panelu w tym miejscu otwiera się Twój program pocztowy z gotową wiadomością albo pobiera się plik .eml. Wysyłka idzie z Twojej skrzynki, nie z platformy. <a href="#wysylka">Zobacz dziennik</a></p>`;
  } else if (a.blocked) {
    decision = `<p class="note warn" id="kBlocked">Wysyłka jest zablokowana. To jedyna twarda blokada w platformie: odbiorca zgłosił sprzeciw.</p>`;
  } else {
    decision = (a.ack ? `<label class="check" for="kAck" style="color:var(--text)"><input type="checkbox" id="kAck" data-on="k-ack"${k.ack ? ' checked' : ''}><span>${esc(PLAT.law.ack)}</span></label>` : `<p class="muted">Ryzyko niskie. Potwierdzenie nie jest potrzebne.</p>`) +
      `<div class="acts"><button class="btn" type="button" id="kSend" data-act="k-send"${a.ack && !k.ack ? ' disabled' : ''}>Zapisz decyzję i przygotuj wiadomość</button></div>
      ${a.ack ? '<p class="small muted">Potwierdzenie trafi do dziennika: kto, kiedy, do kogo i z jaką treścią.</p>' : ''}`;
  }
  put('wiadomosc', stan('silnik', 'Szkice, ocena ryzyka, potwierdzenie i dziennik.') + `<div class="two">
    <div class="stack" style="gap:30px">
      <div class="stack"><div class="step-h"><h2><span class="n">1.</span> Odbiorca i rodzaj wiadomości</h2></div>
        <label class="field" for="kContact">Odbiorca<select id="kContact" data-on="k-contact">${PLAT.contacts.map((x) => `<option value="${x.id}"${x.id === c.id ? ' selected' : ''}>${esc(x.company)}</option>`).join('')}</select></label>
        <p class="small muted">Podstawa kontaktu: ${esc(basisLine(c))}${c.unsub ? '. Wypisał się ' + esc(c.unsub) + '.' : ''}</p>
        <fieldset class="pick"><legend class="sr-only">Rodzaj wiadomości</legend>${kinds}</fieldset></div>
      <div class="stack"><div class="step-h"><h2><span class="n">2.</span> Treść</h2></div>
        <div class="msg"><b>${esc(subj)}</b>${esc(body)}</div>
        <p class="small muted">Szkic z szablonu. W panelu przycisk „Napisz z AI” czyta stronę odbiorcy i dopisuje jedno zdanie o jego działalności. Prośba o zgodę nigdy nie zawiera oferty.</p></div>
    </div>
    <div class="stack" style="gap:30px">
      <div class="stack"><div class="step-h"><h2><span class="n">3.</span> Ryzyko prawne</h2><span id="kLevel">${st(lv[0], lv[1])}</span></div>
        <p>${esc(a.lead)}</p>
        ${a.reasons.length ? `<ul class="list">${a.reasons.map((r) => `<li>${esc(r)}</li>`).join('')}</ul>` : ''}
        ${a.law.length ? `<div><h3 style="margin-bottom:8px">Przepisy i orzeczenia</h3><ul class="list small muted">${a.law.map((r) => `<li>${esc(r)}</li>`).join('')}</ul></div>` : ''}</div>
      <div class="stack"><div class="step-h"><h2><span class="n">4.</span> Twoja decyzja</h2></div>${decision}
        <p class="small muted">Platforma pokazuje ryzyko i nie decyduje za Ciebie. To podsumowanie przepisów, nie porada prawna.</p></div>
    </div></div>`);
}

function renderSending() {
  const s = PLAT.sequence;
  put('wysylka', `<div class="sec">${secHead('Dziennik decyzji', 'Każda przygotowana wiadomość zostawia wpis. Przy ryzyku wysokim wpis zawiera Twoje potwierdzenie.', PLAT.log.length)}
      ${stan('silnik', 'Dziennik i potwierdzenia.')}
      <div class="table-wrap"><table style="min-width:680px"><thead><tr><th>Kiedy</th><th>Kto</th><th>Odbiorca</th><th>Rodzaj wiadomości</th><th>Ryzyko</th><th>Potwierdzenie</th></tr></thead><tbody>` +
      PLAT.log.map((l) => `<tr><td class="num">${esc(l.at)}</td><td>${esc(l.who)}</td><td>${esc(l.company)}</td><td>${esc(PLAT.kinds[l.kind][0])}</td><td>${st(RISK[l.level][0], RISK[l.level][1])}</td><td>${l.ack ? 'Ryzyko potwierdzone' : 'Nie było wymagane'}</td></tr>`).join('') + `</tbody></table></div></div>
    <div class="two">
      <div class="sec">${secHead('Sekwencja: ' + esc(s.name))}${stan('plan', 'Dziś każdą wiadomość przygotowujesz ręcznie.')}` +
        s.steps.map(([day, what, note]) => `<div class="item" style="--c1:70px"><span class="lead-col">${esc(day)}</span><div><p><b>${esc(what)}</b></p><p>${esc(note)}</p></div><span></span></div>`).join('') +
        `<p class="small muted" style="padding-top:12px">Sekwencja zatrzymuje się, gdy: ${s.stops.map((x) => esc(x.toLowerCase())).join(', ')}. Limit: ${s.limit} wiadomości dziennie z jednej skrzynki.</p></div>
      <div class="stack" style="gap:28px">
        <div class="sec">${secHead('W toku')}<div class="table-wrap"><table><thead><tr><th>Firma</th><th>Ostatni krok</th><th>Następny</th></tr></thead><tbody>${s.running.map((r) => `<tr><td>${esc(r[0])}</td><td>${esc(r[1])}</td><td>${esc(r[2])}</td></tr>`).join('')}</tbody></table></div></div>
        <div class="sec">${secHead('Skąd idzie wysyłka')}<ul class="list" style="padding-top:16px">
          <li><b>Zawsze z Twojej skrzynki</b>, nigdy z domen platformy.</li>
          <li><b>Dziś:</b> platforma otwiera program pocztowy z gotową wiadomością albo daje plik .eml.</li>
          <li><b>W planie:</b> połączenie z Gmailem i Microsoft 365, wtedy platforma sama wykryje odpowiedź i wypisanie.</li>
          <li><b>Wypisanie</b> blokuje kolejne oferty do tej firmy dla całego konta.</li></ul></div>
      </div>
    </div>`);
}

const resetDecision = () => { state.k.ack = false; state.k.saved = false; };
const refocus = (id) => { const el = document.getElementById(id); if (el) el.focus(); };
ACTIONS['k-write'] = (el) => { state.k.contact = Number(el.dataset.id); resetDecision(); rerender('wiadomosc'); go('wiadomosc'); };
ACTIONS['k-add'] = (el) => {
  const x = PLAT.search.find((s) => s.company === el.dataset.name);
  PLAT.contacts.push({ id: Math.max(...PLAT.contacts.map((c) => c.id)) + 1, company: x.company, person: '', email: 'adres ze strony firmy', site: '', basis: 'none', detail: '', at: '', unsub: null });
  rerender('firmy', 'kontakty', 'wiadomosc');
  const done = [...document.querySelectorAll('[data-added]')].find((e) => e.dataset.added === x.company);
  if (done) done.focus();
};
ACTIONS['k-rfq'] = (el) => {
  const r = PLAT.rfqs[Number(el.dataset.i)];
  if (!r.contact) {
    r.contact = Math.max(...PLAT.contacts.map((c) => c.id)) + 1;
    PLAT.contacts.push({ id: r.contact, company: r.company, person: '', email: r.address, site: '', basis: 'rfq', detail: 'zapytanie ofertowe: ' + r.title, at: r.published, unsub: null });
  }
  state.k.contact = r.contact; state.k.kind = 'rfq_reply'; resetDecision();
  rerender('kontakty', 'wiadomosc'); go('wiadomosc');
};
ACTIONS['k-send'] = () => {
  const k = state.k, c = contactOf(k.contact), a = assessMessage(k.kind, c);
  if (a.blocked || (a.ack && !k.ack)) return;
  PLAT.log.unshift({ at: 'przed chwilą', who: PLAT.org.user, company: c.company, kind: k.kind, level: a.level, ack: a.ack });
  k.saved = true; rerender('wiadomosc', 'wysylka'); refocus('kSaved');
};
CHANGES['k-contact'] = (el) => { state.k.contact = Number(el.value); resetDecision(); rerender('wiadomosc'); refocus('kContact'); };
CHANGES['k-kind'] = (el) => { state.k.kind = el.value; resetDecision(); rerender('wiadomosc'); refocus('kind-' + el.value); };
CHANGES['k-ack'] = (el) => { state.k.ack = el.checked; rerender('wiadomosc'); refocus('kAck'); };
CHANGES['k-filter'] = (el) => { state.k.f[el.dataset.key] = el.value; rerender('firmy'); refocus(el.id); };

MODS.push({ id: 'klienci', order: 3, label: 'Klienci', about: 'Kontakty z podstawą kontaktu, wiadomości z ekranem ryzyka, zapytania ofertowe firm i wyszukiwanie firm.',
  head: (v) => ({ crumbs: 'Klienci i wiadomości', title: { kontakty: 'Kontakty', firmy: 'Szukaj firm', rfq: 'Zapytania ofertowe firm', wiadomosc: 'Nowa wiadomość', wysylka: 'Wysyłka i dziennik' }[v.id] }),
  views: [{ id: 'kontakty', label: 'Kontakty', render: renderContacts }, { id: 'wiadomosc', label: 'Wiadomość', gap: 28, render: renderMessage }, { id: 'rfq', label: 'Zapytania ofertowe', render: renderRfq },
    { id: 'firmy', label: 'Szukaj firm', render: renderSearch }, { id: 'wysylka', label: 'Wysyłka i dziennik', gap: 40, render: renderSending }] });
