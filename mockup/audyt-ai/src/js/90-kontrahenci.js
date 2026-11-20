/* ===== Kontrahenci i należności ===== */
state.co = { id: 'b2', letter: 0 };
const companyOf = (id) => PLAT.companies.find((c) => c.id === id);
const SIGNAL = { high: ['bad', 'wysoki'], mid: ['mid', 'średni'], info: ['plain', 'informacja'], none: ['good', 'w porządku'] };

/* Ocena ryzyka płatniczego jak w silniku (Risk::assess): 5 punktów na start plus wagi sygnałów, najwyżej 100.
   Tylko dane publiczne. Długów zgłoszonych przez użytkowników tu nie ma. */
function companyRisk(c) {
  const score = Math.max(0, Math.min(100, 5 + c.signals.reduce((a, s) => a + s[4], 0)));
  const [label, cls] = score >= 70 ? ['Wysokie', 'bad'] : (score >= 45 ? ['Podwyższone', 'mid'] : (score >= 20 ? ['Umiarkowane', 'mid'] : ['Niskie', 'good']));
  return { score, label, cls };
}
const overdue = (r) => Math.max(0, -daysTo(r.due));
const addMonth = (iso) => { const [y, m, d] = iso.split('-').map(Number), x = new Date(y, m, d, 12); return `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`; };
/* Warunki wpisu do BIG dla dłużnika niebędącego konsumentem (art. 15 i 15a ustawy), jak w silniku. */
function bigCheck(r) {
  const days = overdue(r), wait = r.demand ? addMonth(r.demand) : null, waitOk = wait !== null && PLAT.today >= wait;
  const checks = [
    [true, 'Należność nie została zapłacona'],
    [r.amount >= 500, 'Łączna kwota zaległości co najmniej 500 zł (dłużnik niebędący konsumentem)'],
    [days >= 30, `Wymagalna od co najmniej 30 dni (teraz: ${dni(days)} po terminie)`],
    [!!r.demand, 'Wysłane wezwanie do zapłaty z ostrzeżeniem o zamiarze przekazania danych do biura i informacją o prawie do sprzeciwu'],
    [!!r.bureau, 'W wezwaniu podano firmę i adres siedziby biura informacji gospodarczej'],
    [waitOk, 'Minął co najmniej miesiąc od wysłania wezwania'],
  ];
  const eligible = checks.every((c) => c[0]);
  return { checks, eligible, days, from: !eligible && wait && !waitOk ? wait : null };
}
const compensation = (amount) => amount <= 5000 ? 40 : (amount < 50000 ? 70 : 100);
function demandLetter(r) {
  const c = companyOf(r.company);
  return [`Wezwanie do zapłaty: faktura ${r.invoice}`,
    `${dpl(PLAT.today)}\n\nWierzyciel: ${PLAT.org.name}\nDłużnik: ${c.name}, NIP ${c.nip}\n\nWEZWANIE DO ZAPŁATY\n\nWzywamy do zapłaty kwoty ${zl(r.amount)} z tytułu faktury nr ${r.invoice} z dnia ${dpl(r.issued)}, której termin płatności upłynął ${dpl(r.due)}, wraz z odsetkami ustawowymi za opóźnienie w transakcjach handlowych oraz rekompensatą za koszty odzyskiwania należności w wysokości równowartości ${compensation(r.amount)} euro, w terminie 7 dni od dnia otrzymania niniejszego wezwania.` +
    (r.bureau ? `\n\nJednocześnie informujemy, że w przypadku braku zapłaty w terminie miesiąca od dnia doręczenia niniejszego wezwania zamierzamy przekazać dane dotyczące zobowiązania do biura informacji gospodarczej: ${r.bureau} (art. 15 ust. 1 pkt 3 ustawy o udostępnianiu informacji gospodarczych i wymianie danych gospodarczych).\n\nInformujemy o możliwości zgłoszenia nam sprzeciwu wobec zamiaru przekazania danych do biura (art. 15a tej ustawy).` : '') +
    `\n\nZ poważaniem\n${PLAT.org.name}`];
}

function renderCompany() {
  const c = companyOf(state.co.id), r = companyRisk(c), mine = PLAT.receivables.filter((x) => x.company === c.id);
  put('kontrahent', stan('silnik', 'Wykaz VAT, KRS i decyzje UOKiK. Firmy w mockupie są zmyślone.') +
    `<div class="form"><label class="field" for="coPick" style="flex:0 1 460px">Firma<select id="coPick" data-on="co-pick">${PLAT.companies.map((x) => `<option value="${x.id}"${x.id === c.id ? ' selected' : ''}>${esc(x.name)}</option>`).join('')}</select></label>
      <button class="btn ghost" type="button" data-act="co-watch" id="coWatch">${c.watched ? 'Przestań obserwować' : 'Obserwuj firmę'}</button></div>
    <p class="small muted">W panelu wpisujesz NIP, a karta powstaje z rejestrów w kilka sekund.</p>
    <div class="two">
      <div class="stack"><div class="score-line"><span class="seller-num" id="coScore">${r.score}</span><div class="ro-side"><span class="muted">ryzyko płatnicze, 0-100</span>${st(r.cls, r.label)}</div></div>
        <div class="hbar key"><i style="width:${r.score}%"></i></div>
        <p class="small muted">Im więcej punktów, tym większe ryzyko. Od 70 wysokie, od 45 podwyższone, od 20 umiarkowane. Ocena powstaje tylko z danych publicznych.</p></div>
      <dl class="kv" style="grid-template-columns:150px minmax(0,1fr)"><dt>NIP</dt><dd class="num">${esc(c.nip)}</dd><dt>Forma</dt><dd>${esc(c.form)}</dd><dt>Działa od</dt><dd>${esc(c.since)}</dd>
        <dt>Rachunki w wykazie VAT</dt><dd>${c.accounts}</dd><dt>Zarząd</dt><dd>${c.board.length ? esc(c.board.join(', ')) : 'nie dotyczy'}</dd><dt>Obserwacja</dt><dd>${c.watched ? 'włączona, zmiany w rejestrach dają alert' : 'wyłączona'}</dd></dl>
    </div>
    <div class="sec">${secHead('Z czego wynika ocena', 'Każdy sygnał ma źródło i wagę. Na start każda firma ma 5 punktów.', r.score + ' pkt')}` +
      c.signals.map(([lv, title, detail, src, w]) => `<div class="item" style="--c1:120px"><span>${st(SIGNAL[lv][0], SIGNAL[lv][1])}</span><div><p><b>${esc(title)}</b></p><p>${esc(detail)} <span class="src">${esc(src)}</span></p></div><div class="end"><span class="num${w ? '' : ' muted'}">${w ? '+' + w : '0'}</span></div></div>`).join('') + `</div>
    <div class="two">
      <div class="sec">${secHead('Zmiany od początku obserwacji', '', c.changes.length)}${c.changes.length ? c.changes.map(([at, what]) => `<div class="item" style="--c1:96px"><span class="lead-col num">${esc(at)}</span><div><p>${esc(what)}</p></div><span></span></div>`).join('') : '<p class="muted" style="padding-top:14px">Bez zmian w rejestrach.</p>'}</div>
      <div class="sec">${secHead('Twoje należności od tej firmy', '', mine.length)}${mine.length ? mine.map((x) => `<div class="item" style="--c1:150px"><span class="lead-col">${esc(x.invoice)}</span><div><p><b>${zl(x.amount)}</b>, ${dni(overdue(x))} po terminie</p></div><div class="end"><a href="#naleznosci">Należności</a></div></div>`).join('') : '<p class="muted" style="padding-top:14px">Brak zapisanych należności.</p>'}</div>
    </div>
    <p class="note">W planie: upadłości i restrukturyzacje z Krajowego Rejestru Zadłużonych oraz szacowany czas płacenia dostawcom ze sprawozdań finansowych.</p>`);
}

function renderWatch() {
  const watched = PLAT.companies.filter((c) => c.watched);
  const changes = watched.flatMap((c) => c.changes.map(([at, what]) => ({ at, what, c }))).sort((a, b) => b.at.split('.').reverse().join('').localeCompare(a.at.split('.').reverse().join('')));
  put('obserwowane', stan('silnik', 'Platforma codziennie porównuje rejestry z poprzednim stanem. Zmiany trafiają do porannego przeglądu e-mailem.') +
    `<div class="sec">${secHead('Alerty', 'Zmiany w rejestrach u obserwowanych firm, od najnowszej.', changes.length)}` +
      changes.map((x) => `<div class="item" style="--c1:96px"><span class="lead-col num">${esc(x.at)}</span><div><p><b>${esc(x.what)}</b></p><p>${esc(x.c.name)}</p></div><div class="end"><a href="#kontrahent" data-act="co-open" data-id="${x.c.id}">Karta firmy</a></div></div>`).join('') + `</div>
    <div class="sec">${secHead('Obserwowane firmy', '', watched.length)}` +
      watched.map((c) => { const r = companyRisk(c); return `<div class="item" style="--c1:58px"><span class="big">${r.score}</span><div><p><b>${esc(c.name)}</b></p><p>${esc(c.form)}, NIP ${esc(c.nip)}</p></div><div class="end">${st(r.cls, 'ryzyko ' + r.label.toLowerCase())}<a href="#kontrahent" data-act="co-open" data-id="${c.id}">Karta firmy</a></div></div>`; }).join('') + `</div>`);
}

function renderReceivables() {
  const rows = PLAT.receivables.map((r, i) => ({ r, i, b: bigCheck(r), c: companyOf(r.company) })), total = rows.reduce((a, x) => a + x.r.amount, 0), [title, body] = demandLetter(PLAT.receivables[state.co.letter]);
  put('naleznosci', stan('silnik', 'Należności, wezwanie do zapłaty w .docx i lista warunków wpisu do BIG.') +
    `<p class="note">Te dane widzi tylko Twoja firma. Platforma nie pokazuje długów innym użytkownikom, bo zakazuje tego ustawa o udostępnianiu informacji gospodarczych. Wpisu do biura dokonuje wierzyciel.</p>
    <div class="counts"><div><b>${zl(total)}</b><span>po terminie</span></div><div><b>${rows.length}</b><span>liczba faktur</span></div><div><b>${rows.filter((x) => x.b.eligible).length}</b><span>spełnia warunki wpisu do BIG</span></div></div>
    <div class="sec">${secHead('Należności po terminie', 'Rozwiń fakturę, żeby zobaczyć warunki wpisu do biura informacji gospodarczej.')}` +
      rows.map(({ r, i, b, c }) => `<details class="row lead debt"${i === 0 ? ' open' : ''}><summary>
          <span class="l-score" style="font-size:16px">${b.days}<span class="small muted" style="font-weight:400"> dni</span></span>
          <span class="l-who">${esc(c.name)}<span>${esc(r.invoice)}, termin ${dpl(r.due)}</span></span>
          <span class="c-val num">${zl(r.amount)}</span>${st(b.eligible ? 'good' : 'plain', b.eligible ? 'można wpisać do BIG' : 'warunki niespełnione')}<span class="l-status">${r.demand ? 'wezwanie ' + dpl(r.demand) : 'bez wezwania'}</span></summary>
        <div class="row-body"><div class="stack">${checkList(b.checks, 'spełniony', 'niespełniony')}
            ${b.from ? `<p class="small muted">Wpis możliwy najwcześniej ${dpl(b.from)}, jeśli spełnione będą pozostałe warunki.</p>` : ''}</div>
          <div class="stack"><p>Rekompensata za koszty odzyskiwania: <b>${compensation(r.amount)} euro</b> (art. 10 ustawy o przeciwdziałaniu nadmiernym opóźnieniom).</p>
            <div class="acts"><button class="btn ghost sm" type="button" data-act="co-letter" data-i="${i}">Pokaż wezwanie do zapłaty</button><a href="#kontrahent" data-act="co-open" data-id="${c.id}">Karta firmy</a></div></div></div></details>`).join('') + `</div>
    <div class="sec" id="letter">${secHead('Wezwanie do zapłaty', 'W panelu pobierasz je jako plik .docx. Treść zawiera ostrzeżenie o biurze i pouczenie o sprzeciwie, gdy wpisano biuro.')}
      <div class="msg" style="margin-top:16px;max-width:86ch"><b>${esc(title)}</b>${esc(body)}</div></div>`);
}

ACTIONS['co-open'] = (el) => { state.co.id = el.dataset.id; rerender('kontrahent'); };
ACTIONS['co-watch'] = () => { const c = companyOf(state.co.id); c.watched = !c.watched; rerender('kontrahent', 'obserwowane', 'pulpit'); refocus('coWatch'); };
ACTIONS['co-letter'] = (el) => { state.co.letter = Number(el.dataset.i); rerender('naleznosci'); const l = document.getElementById('letter'); if (l) l.scrollIntoView({ block: 'start' }); };
CHANGES['co-pick'] = (el) => { state.co.id = el.value; rerender('kontrahent'); refocus('coPick'); };

MODS.push({ id: 'kontrahenci', order: 9, label: 'Kontrahenci', about: 'Karta firmy po NIP z oceną ryzyka płatniczego, obserwowanie zmian w rejestrach i należności z warunkami wpisu do BIG.',
  head: (v) => ({ crumbs: 'Wiarygodność kontrahentów', title: { kontrahent: 'Karta firmy', obserwowane: 'Obserwowane firmy', naleznosci: 'Należności' }[v.id] }),
  views: [{ id: 'kontrahent', label: 'Karta firmy', gap: 32, render: renderCompany }, { id: 'obserwowane', label: 'Obserwowane i alerty', render: renderWatch }, { id: 'naleznosci', label: 'Należności', gap: 32, render: renderReceivables }] });
