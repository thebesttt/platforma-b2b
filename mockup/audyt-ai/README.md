# Mockup: audyt treści i zapytania ze strony

Klikalny mockup dwóch modułów platformy: audytu treści pod wyszukiwarki i AI (menu „Audyt i reklamy”) oraz zapytań ze strony (menu „Klienci i wiadomości”). Dane są przykładowe, nic nie jest mierzone ani wysyłane.

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
