/* ===== Konto i platforma: pakiety, bezpieczeństwo, administracja ===== */
const PLAN_NAMES = { basic: 'Podstawowy', extended: 'Rozszerzony', internal: 'bez limitów' };

function renderPlan() {
  const p = PLAT.plan;
  put('pakiet', stan('silnik', 'Pakiety z miesięcznym przydziałem AI. Liczby zmienia właściciel panelu w Administracji.') +
    `<div class="sec">${secHead('Pakiet Podstawowy', 'Tak widzi to firma klienta: ' + esc(p.firm) + '. PerfectData prowadzi panel, więc nie ma limitów.', 'odnowienie ' + esc(p.renews))}
      <div class="table-wrap" style="padding-top:8px"><table><thead><tr><th>Co</th><th class="n">Zużycie</th><th class="n">Limit</th><th>Stan</th></tr></thead><tbody>` +
      p.usage.map(([label, used, limit]) => `<tr><td>${esc(label)}</td><td class="n"><b>${num(used)}</b></td><td class="n">${num(limit)}</td><td>${used >= limit ? st('bad', 'wyczerpany') : (used >= 0.8 * limit ? st('mid', 'kończy się') : st('good', 'zapas'))}</td></tr>`).join('') + `</tbody></table></div>
      <p class="note" style="margin-top:14px">Po wyczerpaniu przydziału panel nie wysyła zapytania do modelu i mówi to wprost. Wszystko, co działa bez AI (analiza regułowa, szablony, audyt bez oceny AI), działa dalej.</p></div>
    <div class="sec">${secHead('Co jest w pakietach', 'Limity miesięczne. AI jest ograniczone w Podstawowym, w Rozszerzonym jest go więcej.')}
      <div class="table-wrap" style="padding-top:8px"><table><thead><tr><th>Limit</th><th class="n">Podstawowy</th><th class="n">Rozszerzony</th></tr></thead><tbody>` +
      p.limits.map(([label, basic, ext]) => `<tr><td>${esc(label)}</td><td class="n">${num(basic)}</td><td class="n"><b>${num(ext)}</b></td></tr>`).join('') +
      `<tr><td>Cotygodniowe wyszukiwanie naborów bez klikania</td><td class="n">nie</td><td class="n"><b>tak</b></td></tr></tbody></table></div>
      <p class="small muted" style="padding-top:14px">Jedno użycie AI to jedna analiza, jeden szkic, jedna sekcja oferty albo jedno wyszukiwanie naborów. Ceny pakietów i ostateczne limity ustala właściciel panelu. Płatności panel nie obsługuje.</p></div>`);
}

function renderSecurity() {
  const on = PLAT.plan.twofa;
  put('bezpieczenstwo', stan('silnik', 'Logowanie dwuetapowe, unieważnianie sesji, szyfrowanie sekretów, eksport i usunięcie danych firmy.') +
    `<div class="sec">${secHead('Logowanie dwuetapowe', 'Po haśle panel pyta o kod z aplikacji w telefonie. Kod działa raz, a sześć błędnych prób kończy logowanie.', on ? 'włączone' : 'wyłączone')}
      <div class="acts" style="padding-top:16px"><button class="btn${on ? ' ghost' : ''}" type="button" data-act="u-2fa">${on ? 'Wyłącz logowanie dwuetapowe' : 'Włącz logowanie dwuetapowe'}</button></div>
      ${on ? `<p class="note" style="margin-top:14px">Kody zapasowe (panel pokazuje je tylko raz): <b>k7m2p-x9qd4</b>, <b>t3vh8-b6nw1</b> i sześć kolejnych. Każdy działa raz. W mockupie kody są przykładowe.</p>` : ''}</div>
    <div class="two">
      <div class="sec">${secHead('Sesje')}<ul class="list" style="padding-top:16px">
        <li>Zmiana hasła wylogowuje pozostałe urządzenia.</li><li>Nowe hasło z linku z e-maila wylogowuje wszystkie.</li><li>Właściciel może usunąć osobę z zespołu i wyłączyć jej kody, gdy zgubi telefon.</li></ul>
        <div class="acts" style="padding-top:16px"><button class="btn ghost" type="button" data-note>Wyloguj wszędzie</button><p class="note" hidden style="flex-basis:100%">W panelu ten przycisk kończy sesje na innych urządzeniach. Bieżąca zostaje.</p></div></div>
      <div class="sec">${secHead('Dane firmy')}<ul class="list" style="padding-top:16px">
        <li>Każda firma widzi tylko swoje dane. Test przechodzi cały panel jako jedna firma i otwiera każdy adres jako druga.</li>
        <li>Dostęp do konta Google, token Cloudflare i klucz logowania dwuetapowego leżą w bazie zaszyfrowane.</li></ul>
        <div class="acts" style="padding-top:16px"><button class="btn ghost" type="button" data-note>Pobierz dane firmy</button><button class="btn ghost" type="button" data-note>Usuń konto firmy</button>
          <p class="note" hidden style="flex-basis:100%">W panelu „Pobierz” daje paczkę ZIP ze wszystkimi danymi firmy, bez haseł i sekretów. „Usuń” wymaga hasła i przepisania nazwy firmy, a potem kasuje wszystko bez możliwości cofnięcia.</p></div></div>
    </div>`);
}

function renderAdmin() {
  const a = PLAT.admin, total = a.firms.reduce((n, f) => n + f.ops, 0);
  put('administracja', stan('silnik', 'Ekran tylko dla właściciela panelu. Zwykła firma dostaje pod tym adresem „nie znaleziono”.') +
    `<div class="counts"><div><b>${a.firms.length}</b><span>firm w panelu</span></div><div><b>${num(total)}</b><span>użyć AI w tym miesiącu</span></div>
      <div><b>${a.firms.filter((f) => f.blocked).length}</b><span>zablokowanych</span></div><div><b>${a.invites.length}</b><span>zaproszeń czeka</span></div></div>
    <div class="sec">${secHead('Firmy', 'Widzisz liczby o firmach, nie ich dane. Pakiet zmieniasz po opłaceniu faktury.', a.firms.length)}` +
      a.firms.map((f) => `<div class="item" style="--c1:minmax(0,1.2fr)">
        <div><b>${esc(f.name)}</b><p class="small muted">${f.operator ? 'prowadzi panel' : 'pakiet ' + PLAN_NAMES[f.plan]}, osób: ${f.people}, ostatnie logowanie ${esc(f.last)}</p></div>
        <div><p class="small">AI: <b>${num(f.ops)}</b>${f.operator ? '' : ' z ' + num(PLAT.plan.limits[0][f.plan === 'basic' ? 1 : 2])}, czat: <b>${num(f.chat)}</b>${f.operator ? '' : ' z ' + num(PLAT.plan.limits[1][f.plan === 'basic' ? 1 : 2])}</p></div>
        <div class="end">${f.blocked ? st('bad', 'zablokowana') : st('good', f.operator ? 'bez limitów' : 'aktywna')}${f.operator ? '' : `<button class="btn ghost sm" type="button" data-act="a-plan" data-id="${f.id}">${f.plan === 'basic' ? 'Na Rozszerzony' : 'Na Podstawowy'}</button><button class="btn ghost sm" type="button" data-act="a-block" data-id="${f.id}">${f.blocked ? 'Odblokuj' : 'Zablokuj'}</button>`}</div></div>`).join('') +
      `<p class="small muted" style="padding-top:14px">Blokada kończy sesje osób z firmy przy najbliższym kliknięciu i wyłącza jej widżet na stronie. Dane zostają.</p></div>
    <div class="sec">${secHead('Zaproś firmę', 'Panel tworzy jednorazowy link dla podanego adresu. Konto powstanie dla tego adresu i w wybranym pakiecie. Link działa 14 dni.', a.invites.length)}
      <div class="form" style="padding-top:16px"><label class="field" for="ivEmail" style="flex:2 1 280px">E-mail osoby z firmy<input id="ivEmail" type="email" maxlength="160"></label>
        <label class="field" for="ivPlan" style="flex:0 1 200px">Pakiet<select id="ivPlan"><option value="Podstawowy">Podstawowy</option><option value="Rozszerzony">Rozszerzony</option></select></label>
        <button class="btn" type="button" data-act="a-invite">Utwórz zaproszenie</button></div>
      <p class="note" id="ivNote" tabindex="-1" hidden style="margin-top:14px"></p>` +
      a.invites.map(([email, plan, until]) => `<div class="item" style="--c1:minmax(0,1.2fr)"><div><b>${esc(email)}</b></div><div><p class="small">pakiet ${esc(plan)}, ważne do ${esc(until)}</p></div><span></span></div>`).join('') + `</div>`);
}

ACTIONS['u-2fa'] = () => { PLAT.plan.twofa = !PLAT.plan.twofa; rerender('bezpieczenstwo'); };
ACTIONS['a-plan'] = (btn) => { const f = PLAT.admin.firms.find((x) => x.id === btn.dataset.id); f.plan = f.plan === 'basic' ? 'extended' : 'basic'; rerender('administracja'); };
ACTIONS['a-block'] = (btn) => { const f = PLAT.admin.firms.find((x) => x.id === btn.dataset.id); f.blocked = !f.blocked; rerender('administracja'); };
ACTIONS['a-invite'] = () => {
  const email = document.getElementById('ivEmail').value.trim().toLowerCase(), plan = document.getElementById('ivPlan').value;
  const say = (text) => { const n = document.getElementById('ivNote'); n.textContent = text; n.hidden = false; n.focus(); };
  if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) { say('Podaj poprawny adres e-mail.'); return; }
  PLAT.admin.invites.push([email, plan, '08.12.2026']);
  rerender('administracja');
  say('Link do wysłania (panel pokazuje go tylko raz): panel.perfectdata.pl/rejestracja?zaproszenie=3f9c… W mockupie link jest przykładowy.');
};

MODS.push({ id: 'konto', group: 'konto', order: 10, label: 'Konto', about: 'Pakiet firmy i przydział AI, logowanie dwuetapowe, dane firmy i administracja panelu dla wielu firm.',
  head: (v) => ({ crumbs: 'Konto i platforma', title: { pakiet: 'Pakiet i przydział AI', bezpieczenstwo: 'Bezpieczeństwo i dane firmy', administracja: 'Administracja panelu' }[v.id] }),
  views: [{ id: 'pakiet', label: 'Pakiet i AI', render: renderPlan }, { id: 'bezpieczenstwo', label: 'Bezpieczeństwo i dane', render: renderSecurity }, { id: 'administracja', label: 'Administracja', render: renderAdmin }] });
