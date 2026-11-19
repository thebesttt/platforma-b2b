/* ===== Wykres liniowy =====
   Jedna oś, linie 2 px, kropka na końcu z obwódką w kolorze tła, wartość przy końcu linii.
   Po najechaniu albo po strzałkach z klawiatury: pionowa linia i dymek ze wszystkimi seriami.
   Wykres rysujemy dopiero w widocznym widoku, w jego rzeczywistej szerokości, żeby tekst nie malał na telefonie. */
const CHARTS = {};
function chart(id, def) { CHARTS[id] = def; return `<div class="chart" data-chart="${id}"></div>`; }
function niceMax(v) {
  const p = Math.pow(10, Math.floor(Math.log10(v / 4)));
  for (const k of [1, 2, 2.5, 5, 10]) if (k * p * 4 >= v) return k * p * 4;
  return 40 * p;
}
const keys = (series) => `<div class="keys">${series.map((s) => `<span class="key"><i style="background:${s.color}"></i>${esc(s.name)}</span>`).join('')}</div>`;
const dataTable = (def, first) => `<details class="more"><summary>Pokaż dane w tabeli</summary><div class="table-wrap"><table><thead><tr><th>${esc(first)}</th>${def.series.map((s) => `<th class="n">${esc(s.name)}</th>`).join('')}</tr></thead><tbody>` +
  def.labels.map((l, i) => `<tr><td>${esc(l)}</td>${def.series.map((s) => `<td class="n">${esc((def.tipFmt || num)(s.values[i]))}</td>`).join('')}</tr>`).join('') + `</tbody></table></div></details>`;

function drawCharts() {
  for (const el of document.querySelectorAll('.chart[data-chart]')) {
    const W = Math.floor(el.clientWidth), def = CHARTS[el.dataset.chart];
    if (!W || !def) continue;
    const H = 230, L = 46, R = 64, T = 12, B = 28, n = def.labels.length;
    const max = niceMax(Math.max(...def.series.flatMap((s) => s.values)));
    const x = (i) => L + (W - L - R) * i / (n - 1), y = (v) => T + (H - T - B) * (1 - v / max);
    const fmt = def.fmt || ((v) => num(v));
    let g = '';
    for (let k = 0; k <= 4; k++) {
      const v = max * k / 4;
      g += `<line x1="${L}" x2="${W - R}" y1="${y(v)}" y2="${y(v)}" style="stroke:var(--grid)" stroke-width="1"/><text x="${L - 8}" y="${y(v) + 4}" text-anchor="end">${esc(fmt(v))}</text>`;
    }
    const step = W < 480 ? 2 : 1;
    def.labels.forEach((l, i) => { if ((n - 1 - i) % step === 0) g += `<text x="${x(i)}" y="${H - 8}" text-anchor="middle">${esc(l)}</text>`; });
    const ends = def.series.map((s) => y(s.values[n - 1]));
    const labelEnds = def.series.length === 1 || Math.abs(ends[0] - ends[1]) >= 16;
    def.series.forEach((s, k) => {
      g += `<polyline fill="none" style="stroke:${s.color}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" points="${s.values.map((v, i) => x(i).toFixed(1) + ',' + y(v).toFixed(1)).join(' ')}"/>`;
      g += `<circle cx="${x(n - 1)}" cy="${ends[k]}" r="4.5" style="fill:${s.color};stroke:var(--ink)" stroke-width="2"/>`;
      if (labelEnds) g += `<text class="val" x="${x(n - 1) + 10}" y="${ends[k] + 4}">${esc((def.endFmt || fmt)(s.values[n - 1]))}</text>`;
    });
    g += `<g class="hover" visibility="hidden"><line y1="${T}" y2="${H - B}" style="stroke:var(--muted)" stroke-width="1"/>${def.series.map((s) => `<circle r="4.5" style="fill:${s.color};stroke:var(--ink)" stroke-width="2"/>`).join('')}</g>`;
    el.innerHTML = `<svg viewBox="0 0 ${W} ${H}" height="${H}" role="img" tabindex="0" aria-label="${esc(def.title)}. Strzałki w lewo i w prawo pokazują kolejne wartości.">${g}</svg>`;
    el._geo = { W, L, R, n, x, y };
    el._i = undefined;
  }
}
function chartAt(el, i) {
  const def = CHARTS[el.dataset.chart], geo = el._geo, svg = el.querySelector('svg'), hov = svg.querySelector('.hover'), line = hov.querySelector('line');
  hov.setAttribute('visibility', 'visible');
  line.setAttribute('x1', geo.x(i)); line.setAttribute('x2', geo.x(i));
  hov.querySelectorAll('circle').forEach((c, k) => { c.setAttribute('cx', geo.x(i)); c.setAttribute('cy', geo.y(def.series[k].values[i])); });
  const tip = document.getElementById('tip');
  tip.textContent = '';
  const head = document.createElement('b');
  head.textContent = (def.prefix || '') + def.labels[i];
  tip.appendChild(head);
  for (const s of def.series) {
    const row = document.createElement('div'), key = document.createElement('i'), v = document.createElement('strong'), name = document.createElement('span');
    key.style.background = s.color; v.textContent = (def.tipFmt || num)(s.values[i]); name.textContent = s.name;
    row.append(key, v, name); tip.appendChild(row);
  }
  tip.hidden = false;
  const r = svg.getBoundingClientRect(), px = r.left + geo.x(i), tw = tip.offsetWidth;
  tip.style.left = Math.max(8, Math.min(window.innerWidth - tw - 8, px + 14 + tw > window.innerWidth ? px - tw - 14 : px + 14)) + 'px';
  tip.style.top = Math.max(8, r.top + 6) + 'px';
  el._i = i;
}
function hideTip() {
  const tip = document.getElementById('tip');
  if (tip) tip.hidden = true;
  for (const h of document.querySelectorAll('.chart .hover')) h.setAttribute('visibility', 'hidden');
}
const chartOf = (e) => { const el = e.target.closest ? e.target.closest('.chart') : null; return el && el._geo ? el : null; };
document.addEventListener('pointermove', (e) => {
  const el = chartOf(e);
  if (!el) return;
  const r = el.querySelector('svg').getBoundingClientRect(), g = el._geo;
  chartAt(el, Math.max(0, Math.min(g.n - 1, Math.round((e.clientX - r.left - g.L) / ((g.W - g.L - g.R) / (g.n - 1))))));
});
document.addEventListener('pointerout', (e) => { const el = chartOf(e); if (el && !el.contains(e.relatedTarget)) hideTip(); });
document.addEventListener('focusin', (e) => { const el = chartOf(e); if (el) chartAt(el, el._i === undefined ? el._geo.n - 1 : el._i); });
document.addEventListener('focusout', (e) => { if (chartOf(e)) hideTip(); });
document.addEventListener('keydown', (e) => {
  const el = chartOf(e);
  if (!el || (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight')) return;
  e.preventDefault();
  const i = el._i === undefined ? el._geo.n - 1 : el._i;
  chartAt(el, Math.max(0, Math.min(el._geo.n - 1, i + (e.key === 'ArrowLeft' ? -1 : 1))));
});
