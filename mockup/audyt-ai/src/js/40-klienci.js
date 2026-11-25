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
    if (level !== 'low') { law = pke.concat(PLAT.law.consent_request); reasons = ['Ryzyko dotyczy tej jednej wiadomości. Jeśli firma odpowie i poda adres do ofert, zapisz zgodę z opisem odpowiedzi: kolejne oferty będą miały podstawę.',
      'Żeby ryzyko było jak najmniejsze, zostaw samo pytanie: bez nazwy usługi, cen, linków i zachęt. Nie pytaj drugi raz, jeśli firma nie odpowie.',
      'Bez zgody możesz wysłać list pocztą albo odpowiedzieć na zapytanie ofertowe firmy. Te drogi nie wymagają zgody na kontakt elektroniczny.',
      'Zgodę zbierzesz bez ryzyka listem: panel drukuje w nim adres strony zgody, na której firma sama wpisuje e-mail i zaznacza zgodę. Znajdziesz to w zakładce „Potencjalni klienci”.']; }
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
  if (kind === 'consent_request') return ['Pytanie o adres do ofert', `${greet}\n\nczy przyjmują Państwo oferty handlowe e-mailem, a jeśli tak, to na jaki adres? Na Państwa stronie nie znaleźliśmy tej informacji.\n\nJeśli nie odpowiedzą Państwo, nie napiszemy ponownie.\n\nZ poważaniem\n${sig}`];
  return ['Propozycja współpracy: ' + PLAT.org.name, `${greet}\n\n${about}\n\nCzy możemy przesłać szczegóły?\n\nZ poważaniem\n${sig}${optOut}`];
}

function renderContacts() {
  const rows = PLAT.contacts.map((c) => `<div class="item" style="--c1:250px">
      <div><b>${esc(c.company)}</b><p class="small muted">${esc((c.person ? c.person + ', ' : '') + c.email)}</p></div>
      <div><p>${esc(basisLine(c))}</p>${c.voice ? `<details class="more"><summary>Co napisał klient${c.voice.length > 1 ? ' (' + c.voice.length + ')' : ''}, ostatnio ${esc(c.voice[c.voice.length - 1].at)}</summary>` +
        c.voice.slice().reverse().map((v) => `<p class="small muted" style="padding-top:8px">${esc(v.source)}, ${esc(v.at)}</p><p class="small">${esc(v.text).replace(/\n/g, '<br>')}</p>`).join('') + `</details>` : ''}${c.unsub ? `<p class="warn">Wypisał się ${esc(c.unsub)}. Oferty i prośby o zgodę są zablokowane.</p>` : ''}</div>
      <div class="end">${c.unsub ? st('bad', 'sprzeciw') : (c.basis === 'none' ? st('mid', 'brak podstawy') : st('good', 'jest podstawa'))}<button class="btn ghost sm" type="button" data-act="k-write" data-id="${c.id}">Napisz</button></div></div>`).join('');
  put('kontakty', stan('silnik', 'Kontakty, podstawy kontaktu i historia wiadomości.') +
    `<div class="sec">${secHead('Kontakty', 'Przy każdym kontakcie zapisujesz, skąd masz adres i od kiedy. Od tej podstawy zależy ryzyko wiadomości.', PLAT.contacts.length)}${rows}
      <p class="small muted" style="padding-top:14px">„Co napisał klient” zbiera jego własne słowa: zapytanie ze strony, zapytania ofertowe i odpowiedzi e-mail, które wklejasz przy „Firma odpisała”. Panel nie czyta skrzynki, bo wiadomości wychodzą z Twojego programu pocztowego.</p></div>
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
  if (x.consent) out.push(['good', 'niskie ryzyko', 'Oferta e-mailem: masz zgodę', 'Firma sama wpisała adres ' + x.consent[0] + ' na stronie zgody z listu i zaznaczyła zgodę (' + x.consent[1] + '). Panel przechowuje dowód: treść zgody, czas i źródło.'
    + (x.topics && x.topics.length ? ' Interesuje ją: ' + x.topics.join(', ') + '.' : ''), 'consent']);
  if (x.invite) out.push(['good', 'niskie ryzyko', 'Oferta na adres, który firma podała do ofert', '„' + x.invite[0] + '” (' + x.invite[2] + '). Udostępnienie adresu w tym celu jest formą zgody. Zgoda obejmuje oferty, o jakie firma prosi.', 'invite']);
  out.push(['good', 'niskie ryzyko', x.consent ? 'List pocztą' : 'List pocztą z prośbą o zgodę na e-mail', 'Wymóg zgody dotyczy telefonu i środków elektronicznych. List papierowy pod niego nie podlega. Panel przygotowuje plik .docx z tą treścią.'
    + (x.consent ? '' : ' W liście jest adres strony zgody i kod QR. Firma sama wpisuje tam e-mail i zaznacza zgodę, a od tej chwili oferta e-mailem ma podstawę.')
    + (x.audit && !x.consent ? ' List obiecuje bezpłatny raport z audytu strony (wynik ' + x.audit + ' na 100). Firma dostaje go, gdy sama o niego poprosi.' : '')
    + (x.report && !x.consent ? ' Firma poprosiła o raport na adres ' + x.report[0] + ' (' + x.report[1] + ') i go dostała. To nie jest zgoda na ofertę.' : ''), 'letter']);
  if (!x.invite && !x.consent) out.push(['bad', 'bardzo wysokie ryzyko', 'E-mail bez zgody', 'Informacja handlowa bez wcześniejszej zgody jest zakazana, także wobec firm. Prośba o zgodę wysłana e-mailem też. Panel przygotuje wiadomość i pokaże ekran ryzyka, a decyzję podejmujesz Ty.', 'email']);
  return out;
}
/* Strona zgody tak, jak widzi ją firma po wpisaniu adresu z listu albo zeskanowaniu kodu QR. */
function consentPage(x, i) {
  const who = PLAT.org.name, rep = x.audit && !x.report;
  const topics = `<p class="small" style="padding-top:12px">Co Państwa interesuje? Opcjonalnie</p>${PLAT.topics.map((t, n) => `<label class="check" style="padding-top:6px"><input type="checkbox" id="cpT-${i}-${n}"> <span>${esc(t)}</span></label>`).join('')}`;
  return `<div class="note" style="grid-column:1/-1;margin-top:6px"><p class="small muted">Tak wygląda strona zgody tej firmy. Adres i kod QR są wydrukowane w liście. Strona działa bez logowania i nie zapisuje plików cookie.</p>
    <div style="max-width:440px;padding:16px 0 4px"><h3>${rep ? 'Raport z audytu strony' : 'Zgoda na ofertę e-mailem'}</h3>
      <p style="padding-top:8px">${rep ? `Firma <b>${esc(who)}</b> sprawdziła Państwa stronę i wysłała list z wynikiem: <b>${x.audit} na 100 punktów</b>. Pełny raport pokażemy od razu i wyślemy na podany adres. Jest bezpłatny i do niczego nie zobowiązuje.`
        : `Firma <b>${esc(who)}</b> wysłała do Państwa list z propozycją współpracy. Jeśli chcą Państwo dostać ofertę e-mailem, prosimy wpisać adres i zaznaczyć zgodę. Bez zgody nie wyślemy żadnej wiadomości.`}</p>
      <label class="field" for="cpMail-${i}" style="padding-top:12px">${rep ? 'Adres e-mail, na który wysłać raport' : 'Adres e-mail, na który wysłać ofertę'}<input id="cpMail-${i}" type="email" maxlength="120" autocomplete="off"></label>
      ${rep ? `<label class="check" style="padding-top:12px"><input type="checkbox" id="cpRep-${i}"> <span>Proszę o przesłanie raportu z audytu strony na podany adres e-mail.</span></label>` : ''}
      <label class="check" style="padding-top:12px"><input type="checkbox" id="cpOk-${i}"> <span>Zgadzam się na przesłanie przez firmę ${esc(who)} oferty handlowej na podany adres e-mail.${rep ? ' To pole nie jest wymagane.' : ''}</span></label>
      ${topics}
      <div style="display:flex;gap:10px;flex-wrap:wrap;padding-top:14px"><button class="btn sm" type="button" data-act="k-consent" data-name="${esc(x.company)}" data-i="${i}">${rep ? 'Pokaż i wyślij mi raport' : 'Zgadzam się i proszę o ofertę'}</button>
        <button class="btn ghost sm" type="button" data-act="k-consent-no" data-name="${esc(x.company)}">Nie chcemy korespondencji od tej firmy</button></div>
      <p class="small muted" id="cpNote-${i}" tabindex="-1" hidden style="padding-top:10px"></p></div></div>`;
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
        <div><h3>Jak możesz się odezwać</h3>${paths.map(([cls, label, title, text, key]) => {
          const btns = (key === 'invite' || key === 'email' || key === 'consent' ? `<button class="btn ghost sm" type="button" data-act="k-add" data-name="${esc(x.company)}">Przygotuj wiadomość</button>` : '')
            + (key === 'letter' ? `<button class="btn ghost sm" type="button" data-act="k-lnote" data-name="${esc(x.company)}" aria-expanded="${state.k.lnote === x.company}" id="lnb-${i}">Pobierz list .docx</button>` : '')
            + (key === 'letter' && !x.consent ? `<button class="btn ghost sm" type="button" data-act="k-cpage" data-name="${esc(x.company)}" aria-expanded="${state.k.cpage === x.company}" id="cpb-${i}">${state.k.cpage === x.company ? 'Ukryj stronę zgody' : 'Zobacz stronę zgody'}</button>` : '');
          return `<div class="item" style="--c1:230px"><div><b>${esc(title)}</b></div><div><p>${esc(text)}</p>${btns ? `<div style="display:flex;flex-wrap:wrap;gap:8px;padding-top:10px">${btns}</div>` : ''}</div>
          <div class="end">${st(cls, label)}</div>${key === 'letter' && state.k.lnote === x.company ? `<p class="note" style="grid-column:1/-1;margin-top:6px">W mockupie nie ma pliku. W panelu ten przycisk pobiera list z treścią oferty i adresem z rejestru${x.consent ? '' : ', adresem strony zgody z kodem QR'} oraz zdaniem o rezygnacji.</p>` : ''}${key === 'letter' && !x.consent && state.k.cpage === x.company ? consentPage(x, i) : ''}</div>`;
        }).join('')}</div></div>`;
    return `<div class="item" style="--c1:250px">
      <div><b>${esc(x.company)}</b><p class="small muted">${esc(x.pkd)}, ${esc(x.region)}, ${esc(x.source)}</p></div>
      <div><p>${esc(x.win ? 'Wygrane zamówienie: ' + x.win + '.' : x.reason)}</p><p>${esc(x.size)}, działa ${lata(x.age)}, kondycja: ${esc(x.condition)}${x.win ? '. ' + esc(x.reason) : ''}</p></div>
      <div class="end">${best[1] === 'zablokowane' ? st('bad', 'sprzeciw') : (best[4] === 'consent' ? st('good', 'zgoda na e-mail') : best[4] === 'invite' ? st('good', 'zaprasza do ofert') : '')}${st(x.score >= 85 ? 'good' : 'plain', 'dopasowanie ' + x.score)}<button class="btn ghost sm" type="button" data-act="k-open" data-name="${esc(x.company)}" aria-expanded="${open}" id="pro-${i}">${open ? 'Zwiń' : 'Oferta i drogi kontaktu'}</button></div>${detail}</div>`;
  }).join('');
  const L = PLAT.letters, pct = (n) => Math.round(100 * n / L.firms), zl = (v) => v.toFixed(2).replace('.', ',') + ' zł';
  const funnel = [['Firmy z listem', L.firms, L.letters + ' listów, w tym drugie'], ['Weszły na stronę zgody', L.visit, pct(L.visit) + '% firm z listem'], ['Zostawiły zgodę na ofertę', L.consent, pct(L.consent) + '% firm z listem'],
    ['Dostały ofertę e-mailem', L.offer, pct(L.offer) + '% firm z listem'], ['Odpowiedziały', L.reply, pct(L.reply) + '% firm z listem']]
    .map(([name, n, sub]) => `<div class="meter"><span>${esc(name)} <span class="muted small">${esc(sub)}</span></span><span class="num"><b>${n}</b></span><div class="hbar key"><i style="width:${Math.round(100 * n / L.firms)}%"></i></div></div>`).join('');
  put('firmy', stan('silnik', 'Lista firm, dane z rejestrów, treść oferty, drogi kontaktu z oceną ryzyka, listy z kodem QR i lejek listów. Wyszukiwanie w CEIDG wymaga bezpłatnego tokenu.') +
    `<div class="sec">${secHead('Idealny klient', 'Opis powstaje z treści Twojej strony. Zatwierdzasz go albo poprawiasz. AI korzysta z niego, gdy pisze treść oferty.')}
      <p style="padding-top:14px;max-width:78ch">${esc(PLAT.icp)}</p></div>
    <div class="sec">${secHead('Firmy, do których warto się odezwać', 'Od najlepiej dopasowanej. Punkty wynikają z jawnej tabeli: branża 40, region 15, staż 10, kondycja 15, powód kontaktu 20.', found.length + ' z ' + PLAT.search.length)}
      <div class="filters" style="padding:16px 0 6px">${sel('fPkd', 'Branża', 'pkd', uniq('pkd').map((v) => [v, v]))}${sel('fRegion', 'Województwo', 'region', uniq('region').map((v) => [v, v]))}${sel('fTenders', 'Przetargi', 'tenders', [['tak', 'Startuje w przetargach'], ['nie', 'Nie startuje']])}</div>
      ${rows || '<p class="muted" style="padding:16px 0">Żadna firma nie pasuje do tych filtrów. Zmień albo wyczyść jeden z nich.</p>'}</div>
    <div class="sec">${secHead('Czy listy działają', 'Firmy, do których wyszedł list, i co stało się potem. Wejścia liczy serwer, bez plików cookie.')}
      <div class="stack" style="padding-top:16px;max-width:640px">${funnel}</div>
      <p style="padding-top:16px;max-width:78ch">Poprosiły o raport z audytu: <b>${L.report}</b>. Odmówiły korespondencji: <b>${L.refused}</b>. Listy kosztowały <b>${zl(L.letters * L.cost)}</b>, czyli <b>${zl(L.letters * L.cost / L.consent)}</b> za jedną zgodę.</p>
      <p class="note" style="margin-top:14px">${L.quiet} firm nie odpowiedziało na list sprzed 21 dni. Panel proponuje drugi, krótszy list, który zapowiada, że jest ostatni. Trzeciego nie proponuje. Listy do wielu firm pobierasz jednym plikiem: każdy od nowej strony, z własnym kodem QR, a adresy na koperty w pliku dla arkusza.</p></div>
    <div class="sec">${secHead('Kod QR na wizytówkę i stoisko', 'Jedna strona zgody Twojej firmy, niezwiązana z żadnym listem.')}
      <div style="display:flex;flex-wrap:wrap;gap:20px;align-items:flex-start;padding-top:16px">
        <img src="${PLAT.cardQr}" width="132" height="132" alt="Przykładowy kod QR ogólnej strony zgody" style="background:#fff;border-radius:8px;image-rendering:pixelated;flex:none">
        <div style="flex:1 1 320px;min-width:0;max-width:62ch"><p>Wydrukuj kod na wizytówce, ulotce albo postaw na stoisku. Kto go zeskanuje, sam wpisuje firmę i e-mail i zaznacza zgodę. Panel dopisuje firmę do listy i zakłada kontakt z podstawą „zgoda”.</p>
          <p class="small muted" style="padding-top:8px">Kod w mockupie jest przykładowy. Tego adresu nie wysyła się e-mailem ani SMS-em do firm, które o to nie prosiły: taka wiadomość byłaby prośbą o zgodę bez zgody.</p></div></div></div>
    <div class="sec">${secHead('Skąd są firmy')}<ul class="list" style="padding-top:16px">
      <li><b>Wyniki przetargów.</b> Wykonawcy, którzy wygrywają zamówienia publiczne: wiadomo, co robią, gdzie działają i co właśnie podpisali.</li>
      <li><b>Twoja lista.</b> Wklejasz NIP-y albo adresy stron z katalogu branżowego, listy wystawców targów albo notatek. Adresów e-mail panel nie przyjmuje i nie zbiera ze stron.</li>
      <li><b>CEIDG.</b> Jednoosobowe działalności po kodzie PKD, województwie, mieście i dacie założenia. Wymaga bezpłatnego tokenu, o który występuje właściciel firmy. Panel bierze nazwę, NIP i adres, bez adresów e-mail i telefonów.</li>
      <li><b>Kod QR firmy.</b> Osoby, które zeskanowały kod z wizytówki albo ze stoiska i same zostawiły zgodę.</li>
      <li><b>Dane z rejestrów.</b> Panel dopisuje nazwę, adres, formę prawną, staż i ryzyko płatnicze z wykazu VAT i KRS.</li></ul></div>
    <p class="note">Prowadzenie listy i napisanie oferty nie wymagają zgody firmy. Zgody wymaga wysłanie oferty e-mailem albo telefon z ofertą, także do firm (art. 398 Prawa komunikacji elektronicznej). Prośba o zgodę wysłana e-mailem też jej wymaga, dlatego zgodę zbiera list: jest w nim adres strony, na której firma sama zostawia e-mail. Każda firma ma rozpisane drogi kontaktu z oceną ryzyka, a wysyłasz sam.</p>`);
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
          <li><b>Wiadomość do jednej firmy:</b> zawsze z Twojej skrzynki.</li>
          <li><b>Kampania:</b> panel wysyła sam tylko do firm ze zgodą i do klientów, z nazwą Twojej firmy jako nadawcą. Resztę pobierasz jako .eml.</li>
          <li><b>Dziś:</b> platforma otwiera program pocztowy z gotową wiadomością albo daje plik .eml.</li>
          <li><b>Wypisanie:</b> odbiorca klika adres w stopce oferty, bez logowania.</li>
          <li><b>W planie:</b> połączenie z Gmailem i Microsoft 365, wtedy platforma sama wykryje odpowiedź.</li>
          <li><b>Wypisanie</b> blokuje kolejne oferty do tej firmy dla całego konta.</li></ul></div>
      </div>
    </div>`);
}

/* ===== Firmy z dotacjami UE ===== */
state.eu = { q: '', region: '', added: {}, pick: {} };
const stemQ = (q) => norm(q).split(' ').filter(Boolean).map((w) => (typeof stemPl === 'function' ? stemPl(w)[0] : w));
function renderEu() {
  const e = state.eu, terms = stemQ(e.q);
  const rows = PLAT.eu_projects.filter((p) => (!e.region || p.region === e.region) && terms.every((t) => norm(p.title + ' ' + p.summary + ' ' + p.beneficiary).includes(t)));
  const regions = [...new Set(PLAT.eu_projects.map((p) => p.region).filter(Boolean))];
  put('dotacje-ue', stan('silnik', 'Lista projektów z funduszy europejskich 2021-2027 z funduszeeuropejskie.gov.pl, pobierana raz w miesiącu. Firmy w mockupie są zmyślone.') +
    `<div class="sec">${secHead('Szukaj w projektach firm', 'Spółki w trakcie projektu z dotacją UE mają budżet na zakupy. Większe zakupy ogłaszają zwykle w Bazie Konkurencyjności.')}
      <form class="form" id="euSearch" style="padding-top:16px;grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr));display:grid;gap:12px">
        <label class="field" for="euQ">Czego szukasz w projektach<input id="euQ" type="search" value="${esc(e.q)}" placeholder="np. sklep internetowy, cyfryzacja"></label>
        <label class="field" for="euR">Województwo<select id="euR"><option value="">Cała Polska</option>${regions.map((r) => `<option${e.region === r ? ' selected' : ''}>${esc(r)}</option>`).join('')}</select></label>
        <div style="align-self:end"><button class="btn" type="submit">Szukaj</button></div></form></div>
    <div class="sec">${secHead('Projekty', 'Tylko spółki: bez osób fizycznych i spółek cywilnych. Lista nie podaje NIP-u, więc NIP i stronę dopiszesz na karcie firmy.', rows.length)}` +
      rows.map((p) => { const on = e.added[p.id]; return `<div class="item" style="--c1:40px">
        <span>${on ? st('good', 'na liście') : `<label class="sr-only" for="eu-${p.id}">Wybierz ${esc(p.beneficiary)}</label><input type="checkbox" id="eu-${p.id}" data-on="eu-pick" data-id="${p.id}"${e.pick[p.id] ? ' checked' : ''}>`}</span>
        <div><p><b>${esc(p.beneficiary)}</b></p><p>${esc(p.title)}</p><p class="small muted">${esc(p.program)}${p.region ? ', ' + esc(p.region) : ', cały kraj'} · ${dpl(p.start)} - ${dpl(p.end)}</p>
          <details class="more"><summary>Opis projektu</summary><p class="small">${esc(p.summary)}</p></details></div>
        <div class="end"><span class="num">${zl(p.value)}</span><span class="small muted">UE: ${zl(p.eu)}</span></div></div>`; }).join('') +
      `<div class="acts" style="padding-top:16px"><button class="btn" type="button" data-act="eu-add">Dodaj zaznaczone do potencjalnych klientów</button></div>
      <p class="small muted" style="padding-top:10px">Panel nie zbiera adresów e-mail z tej listy. Do firmy napiszesz list z kodem strony zgody albo odpowiesz na jej zapytanie ofertowe. W punktach dopasowania projekt w toku daje powód kontaktu.</p></div>`);
}
FORMS.euSearch = () => { state.eu.q = document.getElementById('euQ').value.trim().slice(0, 80); state.eu.region = document.getElementById('euR').value; rerender('dotacje-ue'); };
CHANGES['eu-pick'] = (el) => { if (el.checked) state.eu.pick[el.dataset.id] = true; else delete state.eu.pick[el.dataset.id]; };
ACTIONS['eu-add'] = () => { for (const id of Object.keys(state.eu.pick)) state.eu.added[id] = true; state.eu.pick = {}; rerender('dotacje-ue'); };

/* ===== Kampanie e-mail ===== */
state.kc = { ai: false, sent: false, ack: false, ackBox: false, downloaded: false, skip: {} };
/* Zdanie {powod} z faktów, w tej samej kolejności co w silniku (Campaigns::facts): zapytanie ze strony, odpowiedź klienta,
   zapytanie ofertowe, tematy ze strony zgody, wygrany przetarg, dotacja UE, audyt strony, współpraca. */
function campaignFacts(c) {
  const voice = c.voice || [], list = [], reasons = [];
  const lead = voice.find((v) => v.source.startsWith('Zapytanie ze strony')), reply = voice.find((v) => v.source.startsWith('Odpowiedź'));
  if (lead) { const need = (lead.text.match(/Potrzeba: (.+)/) || [])[1] || ''; list.push(['Zapytanie ze strony (' + lead.at + ')', need]); reasons.push('Nawiązujemy do Państwa zapytania z ' + lead.at + (need ? ': ' + need + '.' : '.')); }
  if (reply) { list.push(['Odpowiedź klienta', 'z ' + reply.at]); reasons.push('Dziękujemy za wiadomość z ' + reply.at + '.'); }
  if (c.basis === 'rfq' && c.detail && !lead) { list.push(['Zapytanie ofertowe', c.detail]); reasons.push('Nawiązujemy do Państwa zapytania ofertowego: ' + c.detail + '.'); }
  if (c.basis === 'client') reasons.push('Dziękujemy za dotychczasową współpracę.');
  return { list, reason: reasons[0] || '' };
}
function campaignVersion(c) {
  const k = PLAT.campaign, f = campaignFacts(c), sig = PLAT.org.user + '\n' + PLAT.org.name + '\n' + PLAT.org.site;
  let body = k.body;
  if (!f.reason) body = body.replace(/^\{powod\}\n?/m, '');
  body = body.replace('{powitanie}', c.person ? 'Dzień dobry, ' + c.person + ',' : 'Dzień dobry,').replace('{powod}', f.reason).replace('{podpis}', sig).replace(/\n{3,}/g, '\n\n');
  const why = c.basis === 'consent' ? 'Piszemy, bo wyrazili Państwo zgodę na informacje handlowe e-mailem. ' : c.basis === 'client' ? 'Piszemy, bo współpracujemy z Państwa firmą. ' : c.basis === 'rfq' ? 'Piszemy w związku z Państwa zapytaniem ofertowym. ' : '';
  return { subject: k.subject.replace('{firma}', c.company), body, facts: f, footer: '--\n' + why + 'Jeśli nie chcą Państwo otrzymywać od nas wiadomości, wystarczy otworzyć ten adres: https://panel.perfectdata.pl/wypisz/…\nalbo odpisać „rezygnuję”.' };
}
function renderCampaign() {
  const k = PLAT.campaign, kc = state.kc;
  const rows = k.recipients.map((id) => { const c = contactOf(id), a = assessMessage('consented', c); return { c, a, route: a.blocked ? 'blocked' : (a.level === 'low' ? 'panel' : 'own'), v: campaignVersion(c) }; });
  const panel = rows.filter((r) => r.route === 'panel' && !kc.skip[r.c.id]), own = rows.filter((r) => r.route === 'own' && !kc.skip[r.c.id]), blocked = rows.filter((r) => r.route === 'blocked');
  const status = (r) => kc.skip[r.c.id] ? st('plain', 'pominięta') : r.route === 'blocked' ? st('bad', 'wypisana: nie dostanie') : r.route === 'panel' ? (kc.sent ? st('good', 'wysłana przez panel') : st('plain', 'wyśle panel'))
    : (kc.downloaded ? st('good', 'pobrana jako .eml') : st('mid', kc.ack ? 'gotowa do pobrania' : 'wyślesz ze skrzynki'));
  const pke = assessMessage('consented', { basis: 'none' });
  put('kampanie-email', stan('silnik', 'Kampania z wersją dla każdego odbiorcy, ocena ryzyka, wysyłka partiami i wypisanie jednym kliknięciem. Wersja AI wymaga klucza API.') +
    `<div class="counts">
      <div><b>${rows.length}</b><span>odbiorców wybranych</span></div><div><b>${panel.length}</b><span>wyśle panel (zgoda albo klient)</span></div>
      <div><b>${own.length}</b><span>do wysłania ze skrzynki</span></div><div><b>${blocked.length}</b><span>wypisanych: nie dostaną</span></div></div>
    <div class="two">
      <div class="sec">${secHead('1. Treść: ' + esc(k.name), 'Pola w nawiasach panel wypełnia osobno dla każdej firmy. Stopkę ze źródłem adresu i wypisaniem dodaje sam.')}
        <div class="msg" style="margin-top:16px"><b>${esc(k.subject)}</b>${esc(k.body)}</div>
        <dl class="kv" style="padding-top:14px;grid-template-columns:110px minmax(0,1fr)"><dt>{powitanie}</dt><dd>„Dzień dobry,” z osobą, jeśli ją znamy</dd><dt>{firma}</dt><dd>nazwa firmy odbiorcy</dd><dt>{powod}</dt><dd>zdanie z faktów: zapytanie, odpowiedź, zapytanie ofertowe, tematy zgody, przetarg, dotacja UE, audyt</dd><dt>{podpis}</dt><dd>Twoje dane z konta</dd></dl></div>
      <div class="sec">${secHead('Zasady wysyłki')}<ul class="list" style="padding-top:16px">
        <li><b>Panel wysyła sam tylko do firm ze zgodą albo do klientów.</b> Nadawca to nazwa Twojej firmy, odpowiedzi trafiają na Twoją skrzynkę.</li>
        <li><b>Pozostałe wiadomości</b> pobierzesz jako pliki .eml i wyślesz ze swojej skrzynki, jeśli potwierdzisz ryzyko.</li>
        <li><b>Wypisani nie dostają nic.</b> Każda wiadomość ma link do wypisania w stopce i w nagłówku (przycisk „Wypisz” w skrzynce).</li>
        <li><b>Przerwa ${k.pause} dni</b> między kampaniami do tej samej firmy i dzienny limit z pakietu (${k.limit}).</li>
        <li><b>Bez pikseli śledzących.</b> Wynik to wysłane, odpowiedzi i wypisania.</li></ul></div>
    </div>
    <div class="sec">${secHead('2. Wersje dla odbiorców', kc.ai ? 'Wersje dopasowane przez AI: ta sama oferta, nawiązanie do faktów tej firmy. Każdy odbiorca to jedno użycie AI z pakietu.' : 'Wersje z szablonu i faktów. Każdą możesz poprawić ręcznie albo pominąć firmę.', rows.length)}
      <div class="acts" style="padding-top:12px">${kc.ai ? st('good', 'dopasowane przez AI') : `<button class="btn ghost" type="button" data-act="kc-ai">Dopasuj przez AI (${panel.length + own.length} użyć)</button>`}</div>` +
      rows.map((r) => `<details class="more" style="padding:12px 0;border-top:1px solid var(--line)"${r.c.id === 1 ? ' open' : ''}><summary><b>${esc(r.c.company)}</b> ${status(r)}</summary>
        ${r.v.facts.list.length ? `<p class="small muted" style="padding-top:8px">Fakty, które zna panel: ${r.v.facts.list.map(([l, t]) => esc(l) + ': ' + esc(t)).join('; ')}</p>` : `<p class="small muted" style="padding-top:8px">Panel nie zna o tej firmie faktów poza nazwą, więc zdanie {powod} zostało pominięte.</p>`}
        ${r.route === 'blocked' ? '' : `<div class="msg" style="margin-top:10px"><b>${esc(r.v.subject)}</b>${esc(r.v.body)}${kc.ai && r.v.facts.reason ? '\n\n(AI przepisało wstęp pod fakty tej firmy, ofertę zostawiło bez zmian)' : ''}<span class="small muted" style="display:block;margin-top:10px;white-space:pre-wrap">${esc(r.v.footer)}</span></div>
        <div class="acts" style="padding-top:8px"><button class="btn ghost sm" type="button" data-act="kc-skip" data-id="${r.c.id}">${kc.skip[r.c.id] ? 'Przywróć firmę' : 'Pomiń tę firmę'}</button></div>`}</details>`).join('') + `</div>
    <div class="two">
      <div class="sec">${secHead('3. Przez panel', 'Firmy ze zgodą albo Twoi klienci.', panel.length)}
        <p style="padding-top:14px">Nadawca: „${esc(PLAT.org.name)}” z adresu panelu. Odpowiedzi trafią na Twoją skrzynkę.</p>
        <div class="acts" style="padding-top:12px">${kc.sent ? `<p class="note">Wysłano ${panel.length} wiadomości. W panelu wysyłka idzie partiami po 20 i zatrzymuje się na dziennym limicie.</p>` : `<button class="btn" type="button" data-act="kc-send"${panel.length ? '' : ' disabled'}>Wyślij przez panel (${panel.length})</button>`}</div></div>
      <div class="sec">${secHead('4. Ze swojej skrzynki', 'Firmy bez zapisanej zgody.', own.length)}
        ${own.length ? `<p style="padding-top:14px">${st(RISK[pke.level][0], 'ryzyko ' + RISK[pke.level][1])} ${esc(pke.lead)}</p><ul class="list small muted">${pke.law.map((l) => `<li>${esc(l)}</li>`).join('')}</ul>
        <p class="small">Panel nie wyśle tych wiadomości sam. Bezpieczniej: list z kodem strony zgody.</p>
        ${kc.downloaded ? `<p class="note">Pobrano ${own.length} pliki .eml. Otwórz każdy w programie pocztowym i kliknij Wyślij. Potwierdzenie ryzyka jest w historii kontaktu.</p>`
          : kc.ack ? `<div class="acts"><button class="btn" type="button" data-act="kc-dl">Pobierz ${own.length} wiadomości (.eml w ZIP)</button></div>`
          : `<label class="check" for="kcAck" style="color:var(--text)"><input type="checkbox" id="kcAck" data-on="kc-ackbox"${kc.ackBox ? ' checked' : ''}><span>${esc(PLAT.law.ack)}</span></label><div class="acts"><button class="btn ghost" type="button" id="kcAckBtn" data-act="kc-ack"${kc.ackBox ? '' : ' disabled'}>Potwierdzam dla ${own.length} wiadomości</button></div>`}` : '<p class="muted" style="padding-top:14px">Brak.</p>'}
        <p class="small muted">To podsumowanie przepisów, nie porada prawna. Decyzja należy do Ciebie.</p></div>
    </div>`);
}
ACTIONS['kc-ai'] = () => { state.kc.ai = true; rerender('kampanie-email'); };
ACTIONS['kc-send'] = () => { state.kc.sent = true; rerender('kampanie-email'); };
ACTIONS['kc-skip'] = (b) => { const id = Number(b.dataset.id); if (state.kc.skip[id]) delete state.kc.skip[id]; else state.kc.skip[id] = true; rerender('kampanie-email'); };
CHANGES['kc-ackbox'] = (el) => { state.kc.ackBox = el.checked; document.getElementById('kcAckBtn').disabled = !el.checked; };
ACTIONS['kc-ack'] = () => { if (state.kc.ackBox) { state.kc.ack = true; rerender('kampanie-email'); } };
ACTIONS['kc-dl'] = () => { state.kc.downloaded = true; rerender('kampanie-email'); };

const resetDecision = () => { state.k.ack = false; state.k.saved = false; };
const refocus = (id) => { const el = document.getElementById(id); if (el) el.focus(); };
/* Rodzaj wiadomości dopasowany do podstawy kontaktu: odpowiedź na zapytanie, oferta za zgodą albo oferta bez zgody. Tak samo wybiera go silnik. */
const kindFor = (c) => (c.basis === 'rfq' ? 'rfq_reply' : ['consent', 'client', 'invite'].includes(c.basis) ? 'consented' : 'cold_offer');
ACTIONS['k-write'] = (el) => { state.k.contact = Number(el.dataset.id); state.k.kind = kindFor(contactOf(state.k.contact)); resetDecision(); rerender('wiadomosc'); go('wiadomosc'); };
ACTIONS['k-open'] = (el) => { state.k.open = state.k.open === el.dataset.name ? '' : el.dataset.name; const id = el.id; rerender('firmy'); refocus(id); };
ACTIONS['k-add'] = (el) => {
  const x = PLAT.search.find((s) => s.company === el.dataset.name);
  let c = PLAT.contacts.find((k) => k.company === x.company);
  if (!c) {
    c = { id: Math.max(...PLAT.contacts.map((k) => k.id)) + 1, company: x.company, person: '', email: x.consent ? x.consent[0] : x.invite ? x.invite[1] : 'adres wpisany przez Ciebie', site: '',
      basis: x.consent ? 'consent' : x.invite ? 'invite' : 'none', detail: x.consent ? 'zgoda na stronie zgody z listu' : x.invite ? '„' + x.invite[0] + '”' : '',
      at: x.consent ? x.consent[1] : x.invite ? dpl(PLAT.today) : '', unsub: null };
    PLAT.contacts.push(c);
  } else if (x.consent && c.basis === 'none') { c.basis = 'consent'; c.email = x.consent[0]; c.detail = 'zgoda na stronie zgody z listu'; c.at = x.consent[1]; }
  state.k.contact = c.id; state.k.kind = kindFor(c); resetDecision();
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
ACTIONS['k-lnote'] = (el) => { state.k.lnote = state.k.lnote === el.dataset.name ? '' : el.dataset.name; const id = el.id; rerender('firmy'); refocus(id); };
ACTIONS['k-cpage'] = (el) => { state.k.cpage = state.k.cpage === el.dataset.name ? '' : el.dataset.name; const id = el.id; rerender('firmy'); refocus(id); };
ACTIONS['k-consent'] = (el) => {
  const i = el.dataset.i, mail = document.getElementById('cpMail-' + i).value.trim().toLowerCase(), ok = document.getElementById('cpOk-' + i).checked;
  const say = (text) => { const n = document.getElementById('cpNote-' + i); n.textContent = text; n.hidden = false; n.focus(); };
  const x = PLAT.search.find((s) => s.company === el.dataset.name), repBox = document.getElementById('cpRep-' + i);
  if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(mail)) { say('Wpisz poprawny adres e-mail.'); return; }
  if (repBox && !repBox.checked && !ok) { say('Zaznacz, że chcą Państwo dostać raport. Bez tego nie możemy go wysłać.'); return; }
  if (!repBox && !ok) { say('Zaznacz zgodę. Bez niej nie możemy wysłać oferty.'); return; }
  x.topics = PLAT.topics.filter((t, n) => document.getElementById('cpT-' + i + '-' + n).checked);
  if (repBox && repBox.checked) x.report = [mail, dpl(PLAT.today)];
  if (ok) x.consent = [mail, dpl(PLAT.today)];
  state.k.cpage = ''; rerender('firmy'); refocus('pro-' + i);
};
ACTIONS['k-consent-no'] = (el) => {
  const x = PLAT.search.find((s) => s.company === el.dataset.name);
  PLAT.contacts.push({ id: Math.max(...PLAT.contacts.map((k) => k.id)) + 1, company: x.company, person: '', email: '', site: '', basis: 'none', detail: '', at: '', unsub: dpl(PLAT.today) + ' na stronie zgody' });
  state.k.cpage = ''; rerender('firmy', 'kontakty');
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
CHANGES['k-contact'] = (el) => { state.k.contact = Number(el.value); state.k.kind = kindFor(contactOf(state.k.contact)); resetDecision(); rerender('wiadomosc'); refocus('kContact'); };
CHANGES['k-kind'] = (el) => { state.k.kind = el.value; resetDecision(); rerender('wiadomosc'); refocus('kind-' + el.value); };
CHANGES['k-ack'] = (el) => { state.k.ack = el.checked; rerender('wiadomosc'); refocus('kAck'); };
CHANGES['k-filter'] = (el) => { state.k.f[el.dataset.key] = el.value; rerender('firmy'); refocus(el.id); };

MODS.push({ id: 'klienci', group: 'sprzedaz', order: 3, label: 'Klienci', about: 'Kontakty z podstawą kontaktu, wiadomości z ekranem ryzyka, zapytania ofertowe firm, potencjalni klienci (także firmy z dotacjami UE) i kampanie e-mail z wersją dla każdego odbiorcy.',
  head: (v) => ({ crumbs: 'Klienci i wiadomości', title: { kontakty: 'Kontakty', firmy: 'Potencjalni klienci', 'dotacje-ue': 'Firmy z dotacjami UE', 'kampanie-email': 'Kampanie e-mail', rfq: 'Zapytania ofertowe firm', wiadomosc: 'Nowa wiadomość', wysylka: 'Wysyłka i dziennik' }[v.id] }),
  views: [{ id: 'kontakty', label: 'Kontakty', render: renderContacts }, { id: 'wiadomosc', label: 'Wiadomość', gap: 28, render: renderMessage }, { id: 'rfq', label: 'Zapytania ofertowe', render: renderRfq },
    { id: 'firmy', label: 'Potencjalni klienci', render: renderSearch }, { id: 'dotacje-ue', label: 'Firmy z dotacjami UE', gap: 32, render: renderEu },
    { id: 'kampanie-email', label: 'Kampanie e-mail', gap: 36, render: renderCampaign }, { id: 'wysylka', label: 'Wysyłka i dziennik', gap: 40, render: renderSending }] });
