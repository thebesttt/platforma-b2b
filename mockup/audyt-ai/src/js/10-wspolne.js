/* ===== Wspólne dla nowych modułów ===== */
/* Każdy ekran mówi, czy funkcja działa już w silniku, jest w planie, czy czeka na zgodę z zewnątrz. */
const stan = (key, text) => { const [cls, label] = PLAT.states[key]; return `<p class="small muted">${st(cls, label)}<span style="margin-left:10px">${text}</span></p>`; };
const lata = (n) => n + (n === 1 ? ' rok' : (n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14) ? ' lata' : ' lat'));
const proc = (v, sign) => (sign && v > 0 ? '+' : '') + num(v, 1) + '%';
/* Znaczniki z silnika ofert: zdanie ze źródłem albo zdanie do sprawdzenia przed złożeniem. */
const marks = (text) => esc(text).replace(/⟦źródło: ([^⟧]+)⟧/g, '<span class="src">źródło: $1</span>').replace(/⟦sprawdź⟧/g, '<span class="chk">sprawdź</span>');
const unsourced = (sections) => sections.reduce((n, s) => n + (s[1].match(/⟦sprawdź⟧/g) || []).length, 0);
const secHead = (title, lead, right) => `<div class="sec-h"><h2>${title}</h2>${right ? `<span class="sec-pts">${right}</span>` : ''}${lead ? `<p>${lead}</p>` : ''}</div>`;
const checkList = (rows, yes, no) => `<ul class="qa" style="--qa:${Math.round(26 + 8.2 * Math.max(yes.length, no.length))}px">${rows.map(([ok, text]) => `<li>${st(ok ? 'good' : 'bad', ok ? yes : no)}<span>${esc(text)}</span></li>`).join('')}</ul>`;
