/* ===== Klienci i wiadomości ===== */
state.k = { contact: 4, kind: 'cold_offer', ack: false, saved: false, f: { pkd: '', region: '', tenders: '' }, open: '' };
const RISK = { low: ['good', 'niskie'], high: ['mid', 'wysokie'], very_high: ['bad', 'bardzo wysokie'] };
const contactOf = (id) => PLAT.contacts.find((c) => c.id === id);
const basisLine = (c) => PLAT.bases[c.basis] + (c.detail ? ': ' + c.detail : '') + (c.at ? ' (' + c.at + ')' : '');

const INVITE_LEAD = 'Firma sama opublikowała adres do przesyłania ofert. Udostępnienie adresu w tym celu jest formą zgody.';
const INVITE_NOTES = ['Zgoda obejmuje oferty, o jakie firma prosi. Jeśli zaprasza dostawców materiałów, oferta innej usługi się w tym nie mieści.', 'Pisz tylko na adres podany w zaproszeniu i zachowaj link oraz cytat jako dowód.'];

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
    if (has('invite')) { level = 'low'; lead = INVITE_LEAD; reasons = basis.concat(INVITE_NOTES); law = [PLAT.law.invite]; }
    else if (has('consent', 'client')) { level = 'low'; lead = 'Odbiorca wyraził zgodę albo jest Twoim klientem. Pamiętaj o łatwym sposobie wypisania się w stopce.'; reasons = basis; }
    else { level = 'very_high'; lead = 'W karcie kontaktu nie ma zapisanej zgody. Wysłanie oferty bez zgody narusza przepisy o informacji handlowej.'; reasons = ['Zapisz w karcie kontaktu, kiedy i w jaki sposób odbiorca wyraził zgodę.']; law = pke; }
  } else if (kind === 'consent_request') {
    level = has('consent', 'client', 'rfq', 'invite') ? 'low' : 'very_high';
    lead = level === 'low' ? 'Masz już podstawę kontaktu. Zamiast prosić o zgodę możesz od razu odpowiedzieć albo wysłać ofertę.'
      : 'Prośba o zgodę wysłana e-mailem też jest kontaktem bez zgody. Ministerstwo Cyfryzacji uznaje taki kontakt za niedozwolony.';
    if (level !== 'low') { law = pke.concat(PLAT.law.consent_request); reasons = ['Bez zgody możesz wysłać list pocztą albo odpowiedzieć na zapytanie ofertowe firmy. Te drogi nie wymagają zgody na kontakt elektroniczny.']; }
  } else if (has('invite')) {
    level = 'low'; lead = INVITE_LEAD; reasons = basis.concat(INVITE_NOTES); law = [PLAT.law.invite];
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
      <li><b>Potencjalni klienci.</b> Firma z listy dostaje podstawę kontaktu tylko wtedy, gdy ją ma: zapytanie ofertowe albo adres, który sama podała do ofert. Bez niej zostaje list pocztą albo ekran ryzyka. <a href="#firmy">Zobacz listę</a></li>
      <li><b>Własne kontakty.</b> Klienci i osoby, które zostawiły zgodę, np. na targach albo w formularzu.</li></ul></div>`);
}

/* Drogi kontaktu z firmą z listy, od najmniej ryzykownej. Lista i treść oferty nie wymagają zgody, zgody wymaga wysyłka e-mailem. */
function prospectPaths(x) {
  const known = PLAT.contacts.find((c) => c.company === x.company);
  if (known && known.unsub) return [['bad', 'zablokowane', 'Firma zgłosiła sprzeciw', 'Wypisała się ' + known.unsub + '. Panel nie przygotuje dla niej listu ani oferty.', '']];
  const out = [];
  if (x.invite) out.push(['good', 'niskie ryzyko', 'Oferta na adres, który firma podała do ofert', '„' + x.invite[0] + '” (' + x.invite[2] + '). Udostępnienie adresu w tym celu jest formą zgody. Zgoda obejmuje oferty, o jakie firma prosi.', 'invite']);
  out.push(['good', 'niskie ryzyko', 'List pocztą', 'Wymóg zgody dotyczy telefonu i środków elektronicznych. List papierowy pod niego nie podlega. Panel przygotowuje plik .docx z tą treścią.', 'letter']);
  if (!x.invite) out.push(['bad', 'bardzo wysokie ryzyko', 'E-mail bez zgody', 'Informacja handlowa bez wcześniejszej zgody jest zakazana, także wobec firm. Panel przygotuje wiadomość i pokaże ekran ryzyka, a decyzję podejmujesz Ty.', 'email']);
  return out;
}
const prospectPitch = (x) => (x.win ? 'Piszemy, ponieważ Państwa firma zawarła niedawno umowę na zamówienie „' + x.win + '”.' : 'Zajrzeliśmy na Państwa stronę i zauważyliśmy rzecz do poprawy: ' + x.reason.charAt(0).toLowerCase() + x.reason.slice(1));

function renderSearch() {
  const f = state.k.f, uniq = (k) => [...new Set(PLAT.search.map((x) => x[k]))].sort((a, b) => a.localeCompare(b, 'pl'));
  const sel = (id, label, key, opts) => `<label class="field" for="${id}">${label}<select id="${id}" data-on="k-filter" data-key="${key}"><option value="">Wszystkie</option>${opts.map(([v, t]) => `<option value="${esc(v)}"${f[key] === v ? ' selected' : ''}>${esc(t)}</option>`).join('')}</select></label>`;
  const found = PLAT.search.filter((x) => (!f.pkd || x.pkd === f.pkd) && (!f.region || x.region === f.region) && (!f.tenders || (f.tenders === 'tak') === x.tenders)).sort((a, b) => b.score - a.score);
  const rows = found.map((x, i) => {
    const paths = prospectPaths(x), best = paths[0], open = state.k.open === x.company;
    const detail = !open ? '' : `<div class="stack" style="grid-column:1/-1;padding:14px 0 6px">
        ${best[4] === '' ? '' : `<div><h3>Treść oferty</h3><p class="small muted">Panel układa ją z faktów o firmie i z Twojej biblioteki. Napisanie oferty nie wymaga zgody firmy. Ta sama treść trafia do listu i do wiadomości.</p>
          <p style="padding-top:10px;max-width:78ch">${esc(prospectPitch(x))}</p><p style="padding-top:8px;max-width:78ch" class="muted">[Tu trafia opis „O firmie” z Twojej biblioteki.]</p></div>`}
        <div><h3>Jak możesz się odezwać</h3>${paths.map(([cls, label, title, text, key]) => `<div class="item" style="--c1:230px"><div><b>${esc(title)}</b></div><div><p>${esc(text)}</p></div>
          <div class="end">${st(cls, label)}${key === 'invite' || key === 'email' ? `<button class="btn ghost sm" type="button" data-act="k-add" data-name="${esc(x.company)}">Przygotuj wiadomość</button>` : ''}${key === 'letter' ? '<button class="btn ghost sm" type="button" data-note>Pobierz list .docx</button><p class="note" hidden style="flex-basis:100%">W mockupie nie ma pliku. W panelu ten przycisk pobiera list z treścią oferty, adresem z rejestru i zdaniem o rezygnacji.</p>' : ''}</div></div>`).join('')}</div></div>`;
    return `<div class="item" style="--c1:250px">
      <div><b>${esc(x.company)}</b><p class="small muted">${esc(x.pkd)}, ${esc(x.region)}, ${esc(x.source)}</p></div>
      <div><p>${esc(x.win ? 'Wygrane zamówienie: ' + x.win + '.' : x.reason)}</p><p>${esc(x.size)}, działa ${lata(x.age)}, kondycja: ${esc(x.condition)}${x.win ? '. ' + esc(x.reason) : ''}</p></div>
      <div class="end">${best[1] === 'zablokowane' ? st('bad', 'sprzeciw') : (best[4] === 'invite' ? st('good', 'zaprasza do ofert') : '')}${st(x.score >= 85 ? 'good' : 'plain', 'dopasowanie ' + x.score)}<button class="btn ghost sm" type="button" data-act="k-open" data-name="${esc(x.company)}" aria-expanded="${open}" id="pro-${i}">${open ? 'Zwiń' : 'Oferta i drogi kontaktu'}</button></div>${detail}</div>`;
  }).join('');
  put('firmy', stan('silnik', 'Lista z wyników przetargów i z Twojej listy, dane z rejestrów, treść oferty i drogi kontaktu z oceną ryzyka. Wyszukiwanie w rejestrach REGON i CEIDG wymaga kluczy.') +
    `<div class="sec">${secHead('Idealny klient', 'Opis powstaje z treści Twojej strony. Zatwierdzasz go albo poprawiasz. AI korzysta z niego, gdy pisze treść oferty.')}
      <p style="padding-top:14px;max-width:78ch">${esc(PLAT.icp)}</p></div>
    <div class="sec">${secHead('Firmy, do których warto się odezwać', 'Od najlepiej dopasowanej. Punkty wynikają z jawnej tabeli: branża 40, region 15, staż 10, kondycja 15, powód kontaktu 20.', found.length + ' z ' + PLAT.search.length)}
      <div class="filters" style="padding:16px 0 6px">${sel('fPkd', 'Branża', 'pkd', uniq('pkd').map((v) => [v, v]))}${sel('fRegion', 'Województwo', 'region', uniq('region').map((v) => [v, v]))}${sel('fTenders', 'Przetargi', 'tenders', [['tak', 'Startuje w przetargach'], ['nie', 'Nie startuje']])}</div>
      ${rows || '<p class="muted" style="padding:16px 0">Żadna firma nie pasuje do tych filtrów. Zmień albo wyczyść jeden z nich.</p>'}</div>
    <div class="sec">${secHead('Skąd są firmy')}<ul class="list" style="padding-top:16px">
      <li><b>Wyniki przetargów.</b> Wykonawcy, którzy wygrywają zamówienia publiczne: wiadomo, co robią, gdzie działają i co właśnie podpisali.</li>
      <li><b>Twoja lista.</b> Wklejasz NIP-y albo adresy stron z katalogu branżowego, listy wystawców targów albo notatek. Adresów e-mail panel nie przyjmuje i nie zbiera ze stron.</li>
      <li><b>Dane z rejestrów.</b> Panel dopisuje nazwę, adres, formę prawną, staż i ryzyko płatnicze z wykazu VAT i KRS.</li></ul></div>
    <p class="note">Prowadzenie listy i napisanie oferty nie wymagają zgody firmy. Zgody wymaga wysłanie oferty e-mailem albo telefon z ofertą, także do firm (art. 398 Prawa komunikacji elektronicznej). Dlatego każda firma ma rozpisane drogi kontaktu z oceną ryzyka, a wysyłasz sam.</p>`);
}

function renderRfq() {
  const rows = PLAT.rfqs.map((r, i) => {
    const d = r.deadline ? daysTo(r.deadline.split('.').reverse().join('-')) : null;
    return `<div class="item" style="--c1:250px">
      <div><b>${esc(r.company)}</b><p class="small muted">${esc(r.where)}</p></div>
      <div><p>${esc(r.title)}</p><p>Opublikowano ${esc(r.published)}, ${r.address ? 'oferty na adres ' + esc(r.address) : 'ofertę składa się na stronie zapytania'}</p></div>
      <div class="end">${d === null ? st('plain', 'bez terminu') : st(d <= 7 ? 'mid' : 'plain', 'do ' + r.deadline + ', ' + dni(d))}${r.address ? `<button class="btn ghost sm" type="button" data-act="k-rfq" data-i="${i}">Odpowiedz</button>` : ''}</div></div>`;
  }).join('');
  const src = PLAT.rfq_sources.map(([name, url, link, what]) => `<div class="item" style="--c1:250px">
      <div><b>${esc(name)}</b>${url ? `<p class="small"><a href="${esc(url)}" target="_blank" rel="noopener noreferrer">${esc(link)}</a></p>` : ''}</div><div><p>${esc(what)}</p></div><span></span></div>`).join('');
  put('rfq', stan('silnik', 'Źródła zapytań nie udostępniają danych programom. Panel pokazuje, gdzie szukać, a znalezione zapytanie wpisujesz do rejestru.') +
    `<div class="sec">${secHead('Zapytania do odpowiedzi', 'Firma, która publikuje zapytanie i adres do składania ofert, sama prosi o ofertę. To kanał o najniższym ryzyku prawnym.', PLAT.rfqs.length)}${rows}</div>
    <div class="sec">${secHead('Gdzie szukać zapytań', 'Otwórz źródło, wpisz słowa opisujące Twoją ofertę i przepisz do panelu zapytania, na które chcesz odpowiedzieć.')}${src}</div>
    <div class="sec">${secHead('Wpisz znalezione zapytanie', 'Z terminem panel przypomni o zapytaniu 3 dni wcześniej. Adres e-mail z ogłoszenia zakłada kontakt z podstawą „zapytanie ofertowe”.')}
      <div class="form" style="padding-top:16px"><label class="field" for="rqCo">Firma<input id="rqCo" type="text" maxlength="120"></label>
        <label class="field" for="rqTitle" style="flex:2 1 300px">Czego dotyczy zapytanie<input id="rqTitle" type="text" maxlength="200"></label>
        <label class="field" for="rqDl" style="flex:0 1 190px">Termin składania ofert<input id="rqDl" type="date"></label>
        <label class="field" for="rqMail">E-mail z ogłoszenia, opcjonalnie<input id="rqMail" type="email" maxlength="120"></label>
        <button class="btn" type="button" data-act="k-rfq-add">Zapisz zapytanie</button></div>
      <p class="note" id="rqNote" tabindex="-1" hidden style="margin-top:14px"></p></div>`);
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
      <div class="sec">${secHead('Sekwencja: ' + esc(s.name))}${stan('silnik', 'Panel pilnuje terminów i przygotowuje treść kolejnych kroków.')}` +
        s.steps.map(([day, what, note]) => `<div class="item" style="--c1:70px"><span class="lead-col">${esc(day)}</span><div><p><b>${esc(what)}</b></p><p>${esc(note)}</p></div><span></span></div>`).join('') +
        `<p class="small muted" style="padding-top:12px">Sekwencja zatrzymuje się, gdy: ${s.stops.map((x) => esc(x.toLowerCase())).join(', ')}. Limit: ${s.limit} wiadomości dziennie z jednej skrzynki.</p></div>
      <div class="stack" style="gap:28px">
        <div class="sec">${secHead('W toku')}<div class="table-wrap"><table><thead><tr><th>Firma</th><th>Ostatni krok</th><th>Następny</th></tr></thead><tbody>${s.running.map((r) => `<tr><td>${esc(r[0])}</td><td>${esc(r[1])}</td><td>${esc(r[2])}</td></tr>`).join('')}</tbody></table></div></div>
        <div class="sec">${secHead('Skąd idzie wysyłka')}<ul class="list" style="padding-top:16px">
          <li><b>Zawsze z Twojej skrzynki</b>, nigdy z domen platformy.</li>
          <li><b>Dziś:</b> platforma otwiera program pocztowy z gotową wiadomością albo daje plik .eml.</li>
          <li><b>Wypisanie:</b> odbiorca klika adres w stopce oferty, bez logowania.</li>
          <li><b>W planie:</b> połączenie z Gmailem i Microsoft 365, wtedy platforma sama wykryje odpowiedź.</li>
          <li><b>Wypisanie</b> blokuje kolejne oferty do tej firmy dla całego konta.</li></ul></div>
      </div>
    </div>`);
}

const resetDecision = () => { state.k.ack = false; state.k.saved = false; };
const refocus = (id) => { const el = document.getElementById(id); if (el) el.focus(); };
ACTIONS['k-write'] = (el) => { state.k.contact = Number(el.dataset.id); resetDecision(); rerender('wiadomosc'); go('wiadomosc'); };
ACTIONS['k-open'] = (el) => { state.k.open = state.k.open === el.dataset.name ? '' : el.dataset.name; const id = el.id; rerender('firmy'); refocus(id); };
ACTIONS['k-add'] = (el) => {
  const x = PLAT.search.find((s) => s.company === el.dataset.name);
  let c = PLAT.contacts.find((k) => k.company === x.company);
  if (!c) {
    c = { id: Math.max(...PLAT.contacts.map((k) => k.id)) + 1, company: x.company, person: '', email: x.invite ? x.invite[1] : 'adres wpisany przez Ciebie', site: '', basis: x.invite ? 'invite' : 'none',
      detail: x.invite ? '„' + x.invite[0] + '”' : '', at: x.invite ? dpl(PLAT.today) : '', unsub: null };
    PLAT.contacts.push(c);
  }
  state.k.contact = c.id; state.k.kind = c.basis === 'invite' ? 'consented' : 'cold_offer'; resetDecision();
  rerender('firmy', 'kontakty', 'wiadomosc'); go('wiadomosc');
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
ACTIONS['k-rfq-add'] = () => {
  const val = (id) => document.getElementById(id).value.trim();
  const [company, title, dl, mail] = ['rqCo', 'rqTitle', 'rqDl', 'rqMail'].map(val);
  const say = (text) => { const n = document.getElementById('rqNote'); n.textContent = text; n.hidden = false; n.focus(); };
  if (!company || !title) { say('Podaj firmę i temat zapytania.'); return; }
  PLAT.rfqs.push({ contact: null, company, title, where: 'wpisane ręcznie', published: dpl(PLAT.today), deadline: dl ? dpl(dl) : '', address: mail });
  rerender('rfq');
  say(mail ? 'Zapisano zapytanie. Przycisk „Odpowiedz” założy kontakt z podstawą „zapytanie ofertowe” i przygotuje wiadomość.' : 'Zapisano zapytanie. Nie ma adresu e-mail, więc ofertę złożysz na stronie zapytania, a panel przypomni o terminie.');
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

MODS.push({ id: 'klienci', order: 3, label: 'Klienci', about: 'Kontakty z podstawą kontaktu, wiadomości z ekranem ryzyka, zapytania ofertowe firm i lista potencjalnych klientów.',
  head: (v) => ({ crumbs: 'Klienci i wiadomości', title: { kontakty: 'Kontakty', firmy: 'Potencjalni klienci', rfq: 'Zapytania ofertowe firm', wiadomosc: 'Nowa wiadomość', wysylka: 'Wysyłka i dziennik' }[v.id] }),
  views: [{ id: 'kontakty', label: 'Kontakty', render: renderContacts }, { id: 'wiadomosc', label: 'Wiadomość', gap: 28, render: renderMessage }, { id: 'rfq', label: 'Zapytania ofertowe', render: renderRfq },
    { id: 'firmy', label: 'Potencjalni klienci', render: renderSearch }, { id: 'wysylka', label: 'Wysyłka i dziennik', gap: 40, render: renderSending }] });
