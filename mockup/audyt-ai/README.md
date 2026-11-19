# Mockup: audyt treści i zapytania ze strony

Klikalny mockup dwóch modułów panelu PerfectData: „Audyt AI” (audyt treści pod wyszukiwarki i AI) oraz „Zapytania” (zapytania ze strony). Dane są przykładowe, nic nie jest mierzone ani wysyłane.

## Wygląd

Mockup ma wygląd perfectdata.pl: kolory odczytane ze stylów strony 19.11.2026 (zmienne na początku `src/page.html`), czcionka systemowa, jeden ciemny motyw, nagłówek jak na stronie. Strona nie pobiera nic z sieci. Logo jest przybliżeniem tekstowym, przy wdrożeniu zastąpi je plik logo ze strony. Zasady wyglądu są opisane w `PLAN.md` w głównym folderze.

## Co tu jest

- `src/rules.json` - wytyczne wskaźników: 24 wskaźniki, 100 pkt, progi, punkty, podstawa. Jedno źródło prawdy.
- `src/sample.json` - dane przykładowe: dwa audyty tej samej strony (przed i po pracach).
- `src/leads.json` - zasady kwalifikacji zapytań (pytania, punkty, progi) i przykładowe zapytania.
- `src/page.html` - wygląd i zachowanie strony.
- `build.py` - składa stronę i sprawdza spójność: suma 100 pkt w wytycznych i w zasadach kwalifikacji, komplet progów, źródła.
- `public/index.html` - gotowa strona do wdrożenia.
- `WYTYCZNE.md` - te same wytyczne jako dokument.

Po zmianie w `src/` uruchom `python3 build.py`.

## Wdrożenie na Cloudflare

Najprościej, bez instalowania czegokolwiek:

1. W panelu Cloudflare otwórz Workers & Pages.
2. Wybierz Create application, potem Get started i „Drag and drop your files”.
3. Wpisz nazwę (np. `audyt-ai-mockup`) i przeciągnij plik `audyt-ai-mockup.zip` albo folder `public`.
4. Kliknij Deploy site. Strona będzie pod adresem `audyt-ai-mockup.pages.dev`.

Z wiersza poleceń (wymaga Node.js i zalogowanego Wranglera): `npx wrangler deploy` w tym folderze. Konfiguracja jest w `wrangler.jsonc`.

Plik `public/_headers` ustawia nagłówek `X-Robots-Tag: noindex`, żeby mockup nie trafił do wyszukiwarek.
