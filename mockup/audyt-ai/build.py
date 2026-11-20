#!/usr/bin/env python3
"""Buduje mockup z jednego źródła prawdy: resources/audyt-ai-rules.json w katalogu głównym (wytyczne, te same czyta silnik), src/sample.json i src/leads.json (audyt i zapytania),
src/platform.json (pozostałe moduły) oraz src/page.html i src/js/*.js (wygląd i zachowanie).

Wynik:
  public/index.html  - pełna strona do wdrożenia na Cloudflare (Workers Static Assets albo Pages),
  artifact.html      - ta sama strona jako fragment do podglądu w Claude,
  WYTYCZNE.md        - wytyczne wskaźników w formie dokumentu.
"""
import json
import re
import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).parent
RULES_FILE = ROOT.parent.parent / 'resources/audyt-ai-rules.json'
rules = json.loads(RULES_FILE.read_text(encoding='utf-8'))
sample = json.loads((ROOT / 'src/sample.json').read_text(encoding='utf-8'))
leads = json.loads((ROOT / 'src/leads.json').read_text(encoding='utf-8'))
plat = json.loads((ROOT / 'src/platform.json').read_text(encoding='utf-8'))
views = '\n'.join(f.read_text(encoding='utf-8') for f in sorted((ROOT / 'src/js').glob('*.js')))
page = (ROOT / 'src/page.html').read_text(encoding='utf-8')

# --- Kontrola spójności wytycznych -------------------------------------------------------------
errors = []
total = sum(r['max'] for r in rules['rules'])
if total != 100:
    errors.append(f'Suma punktów to {total}, powinno być 100.')
codes = [r['code'] for r in rules['rules']]
if len(set(codes)) != len(codes):
    errors.append('Powtórzony kod wskaźnika.')
groups = {g['id'] for g in rules['groups']}
for g in rules['groups']:
    if not g.get('short'):
        errors.append(f"Grupa {g['id']}: brak krótkiej nazwy (short) do linijki punktów.")
for r in rules['rules']:
    for key in ('what', 'how', 'good', 'mid', 'bad', 'fix'):
        if not r.get(key):
            errors.append(f"{r['code']}: brak pola {key}.")
    if r['group'] not in groups:
        errors.append(f"{r['code']}: nieznana grupa.")
    if not 0 < r['partial'] < r['max']:
        errors.append(f"{r['code']}: punkty za stan pośredni muszą być między 0 a maksimum.")
    if not r['basis']:
        errors.append(f"{r['code']}: brak podstawy.")
    for b in r['basis']:
        if b not in rules['sources']:
            errors.append(f"{r['code']}: nieznane źródło {b}.")
    if r['code'] not in sample['results']:
        errors.append(f"{r['code']}: brak danych przykładowych.")
for u in rules['unscored']:
    for b in u['basis']:
        if b not in rules['sources']:
            errors.append(f"Wskazówka „{u['name']}”: nieznane źródło {b}.")
for code in sample['results']:
    if code not in codes:
        errors.append(f'Dane przykładowe dla nieznanego wskaźnika {code}.')
# Zasady kwalifikacji zapytań: komplet punktów to 100, a przykładowe zapytania wskazują istniejące odpowiedzi.
lead_max = sum(max(o[1] for o in q['options']) for q in leads['questions']) + leads['phone_points']
if lead_max != 100:
    errors.append(f'Zasady kwalifikacji: komplet punktów to {lead_max}, powinno być 100.')
if not 0 < leads['levels']['talk'] < leads['levels']['hot'] <= 100:
    errors.append('Zasady kwalifikacji: błędne progi.')
for lead in leads['leads']:
    if len(lead['answers']) != len(leads['questions']) or any(a >= len(q['options']) for a, q in zip(lead['answers'], leads['questions'])):
        errors.append(f"Zapytanie {lead['company']}: odpowiedzi nie pasują do pytań.")
steps = [s[1] for s in leads['funnel']]
if steps != sorted(steps, reverse=True) or steps[0] != leads['ab']['b'][1] or steps[3] != leads['ab']['b'][2]:
    errors.append('Lejek: kroki muszą maleć i zgadzać się z wariantem „Kwalifikator” w teście.')
# Pozostałe moduły: odwołania między danymi muszą wskazywać istniejące rzeczy.
view_ids = set(re.findall(r"\{ id: '([a-z]+)', label:", page + views))
for group in plat['functions']:
    for name, view, state, _note in group['items']:
        if view not in view_ids:
            errors.append(f"Lista funkcji: „{name}” wskazuje nieistniejący widok {view}.")
        if state not in plat['states']:
            errors.append(f"Lista funkcji: „{name}” ma nieznany stan {state}.")
contact_ids = {c['id'] for c in plat['contacts']}
for c in plat['contacts']:
    if c['basis'] not in plat['bases']:
        errors.append(f"Kontakt {c['company']}: nieznana podstawa kontaktu.")
for r in plat['rfqs']:
    if r['contact'] is not None and r['contact'] not in contact_ids:
        errors.append(f"Zapytanie ofertowe {r['company']}: nieznany kontakt.")
for entry in plat['log']:
    if entry['kind'] not in plat['kinds']:
        errors.append('Dziennik: nieznany rodzaj wiadomości.')
tender_ids = {t['id'] for t in plat['tenders']}
company_ids = {c['id'] for c in plat['companies']}
for ref in [plat['tender_card']['id'], plat['offer']['tender']] + [t[2] for t in plat['terms']]:
    if ref not in tender_ids:
        errors.append(f'Nieznany przetarg {ref}.')
for t in plat['tenders']:
    if t['buyer_company'] and t['buyer_company'] not in company_ids:
        errors.append(f"Przetarg {t['id']}: nieznana firma zamawiającego.")
    if t['cpv_main'] not in t['cpv_all']:
        errors.append(f"Przetarg {t['id']}: główny kod CPV musi być na liście kodów.")
for r in plat['receivables']:
    if r['company'] not in company_ids:
        errors.append(f"Należność {r['invoice']}: nieznana firma.")
tech = plat['tech']
if sum(c[2] for c in tech['cats']) != 100:
    errors.append('Audyt techniczny: wagi kategorii muszą dawać 100.')
own = [r for r in tech['rivals'] if r[1] == 'Moja strona']
if len(own) != 1 or own[0][2] != [round(100 * c[1] / c[2]) for c in tech['cats']]:
    errors.append('Audyt techniczny: procenty własnej strony w porównaniu muszą wynikać z punktów kategorii.')
if own and own[0][3] != sum(1 for i in tech['issues'] if i[0] == 'critical'):
    errors.append('Audyt techniczny: liczba krytycznych w porównaniu musi zgadzać się z listą poprawek.')
ads = plat['ads']
for pl in ads['platforms']:
    if not (len(pl['spend']) == len(pl['clicks']) == len(pl['conv']) == len(ads['weeks']) == 8):
        errors.append(f"Reklamy {pl['name']}: każda seria musi mieć 8 tygodni.")
    for key, prev_key, src in (('spend', 'prev_spend', 'spend'), ('conv', 'prev_conv', 'conv')):
        mine = [c for c in ads['campaigns'] if c['platform'] == pl['id']]
        if sum(c[key] for c in mine) != sum(pl[src][4:]) or sum(c[prev_key] for c in mine) != sum(pl[src][:4]):
            errors.append(f"Reklamy {pl['name']}: suma kampanii ({key}) musi zgadzać się z danymi tygodniowymi.")
if len(plat['gsc']['clicks']) != len(plat['gsc']['weeks']):
    errors.append('Search Console: liczba tygodni i wartości musi być taka sama.')
if errors:
    sys.exit('Błędy:\n- ' + '\n- '.join(errors))


def embed(obj):
    return json.dumps(obj, ensure_ascii=False, separators=(',', ':')).replace('</', '<\\/')


built = (page.replace('/*__RULES__*/null', embed(rules)).replace('/*__SAMPLE__*/null', embed(sample)).replace('/*__LEADS__*/null', embed(leads))
         .replace('/*__PLATFORM__*/null', embed(plat)).replace('/*__VIEWS__*/', views))
assert '/*__' not in built

# Kontrola składni skryptu, jeśli jest Node.js. Błąd składni zatrzymałby całą stronę.
script = built[built.index('<script>') + 8:built.rindex('</script>')]
if shutil.which('node'):
    check = subprocess.run(['node', '--check', '-'], input=script, text=True, capture_output=True)
    if check.returncode:
        sys.exit('Błąd składni w skrypcie strony:\n' + check.stderr[:1500])

head, body = built.split('</style>', 1)
head = head.replace('<meta name="claude:viewport-fit-sides" content="cover">\n', '') + '</style>'
full = (
    '<!doctype html>\n<html lang="pl">\n<head>\n<meta charset="utf-8">\n'
    '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">\n'
    '<meta name="robots" content="noindex">\n' + head + '\n</head>\n<body>' + body + '</body>\n</html>\n'
)

# --- Wytyczne jako dokument ---------------------------------------------------------------------
lv = rules['levels']
md = [
    '# Wytyczne wskaźników audytu treści pod wyszukiwarki i AI',
    '',
    f"Wersja {rules['version']}. Plik powstaje z `resources/audyt-ai-rules.json` poleceniem `python3 mockup/audyt-ai/build.py`. Nie edytuj go ręcznie.",
    '',
    '## Zasady',
    '',
    f"- {total} punktów w {len(rules['groups'])} grupach, {len(codes)} wskaźników. Każdy wskaźnik ma trzy stany: „Dobrze” (komplet punktów), „Do poprawy” (część punktów), „Źle” (0 pkt).",
    f"- Wynik strony: {lv['good']}-100 pkt to „Dobrze”, {lv['mid']}-{lv['good'] - 1} pkt to „Do poprawy”, poniżej {lv['mid']} pkt to „Źle”. Te progi są umowne.",
    '- Warunki konieczne: ' + ', '.join(f"{r['code']} {r['name']}" for r in rules['rules'] if r.get('required')) + '. Gdy któryś jest w stanie „Źle”, raport pokazuje blokadę niezależnie od liczby punktów.',
    '- Punkty dajemy tylko za to, co ma podstawę: oficjalną dokumentację Google, Microsoftu i dostawców botów AI albo opublikowane badanie. Gdy sama liczba progu jest nasza, piszemy „próg umowny”.',
    '- Ten sam pomiar daje ten sam wynik. Wskaźniki D1 i D2 ocenia model AI, zawsze z cytatem ze strony jako dowodem i z zamrożoną listą pytań.',
    '- Brak pomiaru nie odbiera punktów. Gdy wskaźnika nie da się zmierzyć, raport pokazuje „brak pomiaru”, a wynik liczymy proporcjonalnie z pozostałych punktów.',
    '- Wynik nie jest obietnicą pozycji ani cytowań. Mówi, czy strona spełnia opisane tu warunki.',
    '',
]
for g in rules['groups']:
    rs = [r for r in rules['rules'] if r['group'] == g['id']]
    md += [f"## {g['id']}. {g['name']} ({sum(r['max'] for r in rs)} pkt)", '', g['lead'], '']
    md += ['| Kod | Wskaźnik | Jak mierzymy | Dobrze | Do poprawy | Źle | Punkty | Podstawa |', '|---|---|---|---|---|---|---|---|']
    for r in rs:
        pts = '2 / 1 / 0 za każdy z 3 wskaźników' if r.get('parts') else f"{r['max']} / {r['partial']} / 0"
        basis = ', '.join(f"[{rules['sources'][b]['short']}]({rules['sources'][b]['url']})" for b in r['basis'])
        extra = ' '.join(x for x in (r.get('note', ''), r.get('convention', '')) if x)
        name = r['name'] + (' (konieczny)' if r.get('required') else '') + (' (ocena AI)' if r.get('ai') else '')
        how = r['how'] + (' ' + extra if extra else '')
        md.append('| ' + ' | '.join([r['code'], name, how, r['good'], r['mid'], r['bad'], pts, basis]).replace('\n', ' ') + ' |')
    md.append('')
md += ['## Czego nie punktujemy i dlaczego', '', '| Wskazówka | Dlaczego bez punktów | Źródło |', '|---|---|---|']
for u in rules['unscored']:
    basis = ', '.join(f"[{rules['sources'][b]['short']}]({rules['sources'][b]['url']})" for b in u['basis']) or 'brak'
    md.append(f"| {u['name']} | {u['why']} | {basis} |")
md += ['', '## Źródła', '']
md += [f"- [{s['label']}]({s['url']})" for s in rules['sources'].values()]
md_text = '\n'.join(md) + '\n'

for name, text in (('artifact.html', built), ('public/index.html', full), ('WYTYCZNE.md', md_text)):
    if re.search('[' + chr(0x2013) + chr(0x2014) + ']', text):
        sys.exit(f'{name}: znaleziono pauzę lub półpauzę, użyj zwykłego łącznika.')
    (ROOT / name).write_text(text, encoding='utf-8')
print(f"OK: {len(codes)} wskaźników, {total} pkt, {len(view_ids)} widoków, {sum(len(g['items']) for g in plat['functions'])} funkcji na liście, public/index.html {len(full) // 1024} kB")
