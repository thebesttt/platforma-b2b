# Mockup panelu PerfectData

Klikalny mockup całego panelu: 9 modułów, 32 ekrany. Pokazuje każdą funkcję z opisu produktu na przykładowych danych. Nic nie jest mierzone ani wysyłane.

| Moduł | Ekrany |
|---|---|
| Pulpit | Dziś, Lista funkcji (63 funkcje ze stanem: działa w silniku, do zbudowania, czeka na zgodę Google i Meta) |
| Zapytania | Zapytania, Zasady kwalifikacji, Podgląd widżetu, Wyniki |
| Klienci | Kontakty, Wiadomość z ekranem ryzyka i decyzją, Zapytania ofertowe, Potencjalni klienci (lista firm, treść oferty, drogi kontaktu z ryzykiem), Wysyłka i dziennik |
| Audyt AI | Raport, Wytyczne wskaźników, Przed i po, Cytowania w AI, Strony witryny |
| SEO i strona | Audyt techniczny, Konkurenci, Google (Search Console i wizytówka) |
| Reklamy | Wyniki, Kampanie z potwierdzeniem zmian i dziennikiem, Alerty |
| Przetargi | Dopasowane, Karta przetargu z analizą SWZ, Profil, Terminy |
| Oferty | Oferta, Dotacje, Biblioteka firmy |
| Kontrahenci | Karta firmy, Obserwowane i alerty, Należności |

## Wygląd

Mockup ma wygląd perfectdata.pl: kolory odczytane ze stylów strony 19.11.2026 (zmienne na początku `src/page.html`), czcionka systemowa, jeden ciemny motyw, nagłówek jak na stronie. Strona nie pobiera nic z sieci. Logo jest przybliżeniem tekstowym, przy wdrożeniu zastąpi je plik logo ze strony. Zasady wyglądu są opisane w `PLAN.md` w głównym folderze.

## Reguły przeniesione z silnika

Cztery obliczenia w mockupie to kopie reguł z kodu PHP. Wyniki porównałem z silnikiem na danych mockupu: 38 porównań, wszystkie zgodne.

| Co liczy mockup | Funkcja w mockupie | Źródło w silniku |
|---|---|---|
| Ryzyko prawne wiadomości | `assessMessage` w `src/js/40-klienci.js` | `src/Modules/Outreach/RiskPolicy.php` |
| Dopasowanie przetargu 0-100 | `matchTender` w `src/js/70-przetargi.js` | `src/Modules/Tenders/Matcher.php` |
| Warunki wpisu do BIG i rekompensata | `bigCheck`, `compensation` w `src/js/90-kontrahenci.js` | `src/Modules/Companies/Receivables.php` |
| Ocena ryzyka płatniczego | `companyRisk` w `src/js/90-kontrahenci.js` | `src/Modules/Companies/Risk.php` |

Po zmianie reguły w silniku trzeba poprawić też mockup.

## Co tu jest

- `../../resources/audyt-ai-rules.json` - wytyczne audytu AI: 24 wskaźniki, 100 pkt, progi, punkty, podstawa. Jedno źródło prawdy: ten sam plik czyta silnik.
- `src/sample.json` - dane audytu AI: dwa audyty tej samej strony (przed i po pracach).
- `src/leads.json` - zasady kwalifikacji zapytań (pytania, punkty, progi) i przykładowe zapytania.
- `src/platform.json` - dane pozostałych modułów i lista funkcji ze stanem.
- `src/page.html` - wygląd, wspólne funkcje, audyt AI i zapytania.
- `src/js/*.js` - wykres i widoki pozostałych modułów, po jednym pliku na moduł.
- `build.py` - składa stronę i sprawdza spójność danych: sumy punktów, odwołania między danymi, zgodność sum kampanii z danymi tygodniowymi, składnię skryptu.
- `public/index.html` - gotowa strona do wdrożenia.
- `WYTYCZNE.md` - wytyczne audytu AI jako dokument.

Po zmianie w `src/` uruchom `python3 build.py`.

## Wdrożenie na Cloudflare

Najprościej, bez instalowania czegokolwiek:

1. W panelu Cloudflare otwórz Workers & Pages.
2. Wybierz Create application, potem Get started i „Drag and drop your files”.
3. Wpisz nazwę (np. `audyt-ai-mockup`) i przeciągnij plik `audyt-ai-mockup.zip` albo folder `public`.
4. Kliknij Deploy site. Strona będzie pod adresem `audyt-ai-mockup.pages.dev`.

Z wiersza poleceń (wymaga Node.js i zalogowanego Wranglera): `npx wrangler deploy` w tym folderze. Konfiguracja jest w `wrangler.jsonc`.

Plik `public/_headers` ustawia nagłówek `X-Robots-Tag: noindex`, żeby mockup nie trafił do wyszukiwarek.
