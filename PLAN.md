# Plan działania

Stan na 20.11.2026.

**Zmiana kierunku.** Pierwszym użytkownikiem narzędzia jest PerfectData (perfectdata.pl). Cel: więcej zapytań ze strony i audyt, którym agencja pracuje z klientami SEO i GEO. Platforma B2B (przetargi, oferty, kontrahenci) zostaje silnikiem pod spodem i osobnym produktem na później.

Na perfectdata.pl nic nie zostało zainstalowane. Kod trafi na stronę dopiero po wyraźnej zgodzie właściciela.

**Co się zmieniło 20.11.2026.** Etapy 1 i 2 oraz większość etapu 3 są zbudowane w silniku i przechodzą testy. Do uruchomienia zostały kroki właściciela z etapu 0: hosting, baza, klucze usług i zgoda na kod widżetu. Lista na końcu, w części „Co jest potrzebne od właściciela”.

## Co jest gotowe

- **Silnik:** aplikacja PHP z bazą PostgreSQL w repozytorium `platforma-b2b`, 113 testów na SQLite i PostgreSQL. Gotowa do uruchomienia w Render.
- **Panel w wyglądzie PerfectData** (zmienna `APP_THEME=perfectdata` albo ustawienie firmy) z wyborem modułów w menu i pulpitem, który pokazuje tylko włączone moduły.
- **Zapytania ze strony:** widżet do wklejenia jedną linią kodu, kwalifikator z jawną tabelą punktów, lista i karta zapytań, czat AI z bazy wiedzy, lejek i test dwóch wersji.
- **Audyt AI:** 24 wskaźniki i 100 punktów z jednego pliku wytycznych, raport z linijką punktów i listą „Najpierw to”, „Przed i po”, strona wytycznych, raport dla klienta pod linkiem, audyt wielu stron z mapy witryny. Sprawdzony na prawdziwej stronie perfectdata.pl.
- **Poczta z panelu:** powiadomienie o zapytaniu, reset hasła, zaproszenia do zespołu i poranny przegląd (przetargi, zapytania, alerty).
- **Moduły działające wcześniej:** przetargi, oferty i wnioski, kontrahenci, klienci i wiadomości z oceną ryzyka prawnego, audyt techniczny.
- **Mockup całego panelu:** `mockup/audyt-ai`, 9 modułów i 32 ekrany, z listą 60 funkcji i ich stanem. Prywatny podgląd w Claude i paczka `audyt-ai-mockup.zip`.
- **Wytyczne audytu:** `resources/audyt-ai-rules.json` (czyta je silnik) i dokument dla zespołu `mockup/audyt-ai/WYTYCZNE.md`.
- **Plany modułów:** `PLAN-ZAPYTANIA.md` i `PLAN-AUDYT-AI.md`.

## Wygląd panelu

Panel i raporty wyglądają jak perfectdata.pl, żeby klient agencji widział jedną markę, a nie obce narzędzie.

- **Kolory ze strony** (odczytane ze stylów perfectdata.pl 19.11.2026): tło `#0a0e1a`, panel `#0f1524`, powierzchnia `#151c2e`, linie `#232d45`, złoto `#d4a94a` (jaśniejsze `#e8c97a`, ciemniejsze `#a87f2c`), tekst `#f2f3f7`, tekst pomocniczy `#9aa3b8`.
- **Czcionka systemowa**, ta sama co na stronie. Panel nie pobiera żadnych czcionek ani skryptów z zewnątrz.
- **Jeden ciemny motyw**, bo strona ma tylko ciemny.
- **Układ:** nagłówek jak na stronie (logo, 9 modułów, link do perfectdata.pl), pod nim zakładki modułu. Bez bocznego menu platformy. Na telefonie pasek modułów przewija się w bok.
- **Złoto ma jedno znaczenie:** zdobyte punkty, główny przycisk i aktywna pozycja menu. Nie służy do ozdoby.
- **Linijka 100 punktów** w raporcie audytu. Każdy z 24 wskaźników to odcinek o szerokości równej jego wadze. Złote wypełnienie to zdobyte punkty, czerwona ramka to wskaźnik z zerem. Kliknięcie odcinka otwiera wskaźnik. Ta sama linijka pokazuje stan „przed” i „po”.
- **Stany rozróżnia kształt i słowo, nie sam kolor:** pełne koło „Dobrze”, pół koła „Do poprawy”, pierścień „Źle”.
- **Dane w wierszach na liniach**, bez rzędów jednakowych kart.
- **Przycisk główny i pola formularzy** jak na stronie: złoty gradient z ciemnym tekstem, pola na tle strony z cienką ramką.
- **Logo:** w mockupie jest przybliżenie tekstowe. Przy wdrożeniu zastąpi je plik logo ze strony.

W platformie wygląd i zestaw modułów będą ustawieniem organizacji (pole `settings_json` w tabeli `organizations`, bez nowej tabeli). Mockup pokazuje wszystkie moduły, bo tak ma wyglądać pełny panel. Moduł, którego firma nie używa, można wyłączyć w ustawieniach. Ten sam silnik obsłuży później inne firmy z ich wyglądem.

## Funkcje i ich stan

Mockup pokazuje 60 funkcji. Zakładka „Lista funkcji” na pulpicie podaje stan każdej z nich i prowadzi do ekranu.

| Moduł | Działa w silniku | Do zbudowania | Czeka na zgodę Google i Meta |
|---|---|---|---|
| Zapytania | 5 | 0 | 0 |
| Klienci | 6 | 5 | 0 |
| Audyt AI | 6 | 1 | 0 |
| SEO i strona | 3 | 2 | 0 |
| Reklamy | 0 | 0 | 6 |
| Przetargi | 8 | 1 | 0 |
| Oferty i wnioski | 6 | 3 | 0 |
| Kontrahenci | 5 | 3 | 0 |
| Razem | 39 | 15 | 6 |

„Działa w silniku” znaczy, że kod jest w repozytorium i przechodzi testy. Silnik nie jest jeszcze uruchomiony online (etap 0).

Dlaczego 15 funkcji nie jest zbudowanych:

- **Wymagają zgody albo konta u zewnętrznej firmy:** Search Console i wizytówka Google (logowanie Google klienta), wyszukiwarka firm (klucze do REGON i CEIDG), sekwencje i wykrywanie odpowiedzi (połączenie ze skrzynką Gmail lub Microsoft 365), wpis długu do BIG (umowa z biurem), test cytowań w AI (klucze OpenAI, Perplexity i Gemini oraz decyzja o koszcie).
- **Nie mają czystego źródła danych:** Krajowy Rejestr Zadłużonych i sprawozdania finansowe z KRS, baza otwartych naborów dotacji, zbieranie zapytań ofertowych ze źródeł.
- **Są w kolejce jako następne:** wyniki przetargów z BZP, opis idealnego klienta, ocena warunków naboru, podział sekcji oferty między osoby.

Reklamy Google i Meta czekają na zgody platform. Wnioski warto złożyć już teraz, bo zgody trwają tygodnie.

## Etap 0. Uruchomienie silnika (ok. 2-3 godziny pracy właściciela)

1. W Render utwórz usługę przez New → Blueprint, z repozytorium `platforma-b2b`. Plik `render.yaml` ustawia nazwę PerfectData i wygląd PerfectData.
2. Render zapyta o cztery zmienne. Każdą można uzupełnić później:
   - `ANTHROPIC_API_KEY`: czat w widżecie, ocena AI w audycie (D1, D2), analiza dokumentów, szkice,
   - `ADMIN_EMAILS`: Twój e-mail,
   - `RESEND_API_KEY` i `MAIL_FROM`: wysyłka e-maili. Konto na resend.com, domena perfectdata.pl zweryfikowana rekordami DNS, nadawca np. `Panel PerfectData <panel@perfectdata.pl>`.
3. Zdecyduj o bazie. Darmowa baza Render wygasa po 30 dniach, potem jest 14 dni na przejście na płatny plan, a po tym czasie dane są kasowane. Od razu wybierz płatny plan Render albo bazę w Neon (w Neon skopiuj adres bezpośredni, bez `-pooler` w nazwie hosta).
4. Załóż konto z linku `/rejestracja?kod=<SIGNUP_CODE>`. Kod jest w Render, w zakładce Environment. Rejestracja bez kodu jest zamknięta.
5. W Ustawieniach kliknij „Sprawdź połączenie z AI” i „Wyślij wiadomość kontrolną”. To pierwsze prawdziwe zapytanie do modelu i pierwszy prawdziwy e-mail.
6. Zdecyduj o adresie panelu: adres z Render albo subdomena, np. `panel.perfectdata.pl`. Subdomena to jeden rekord CNAME w DNS i wpis domeny w ustawieniach usługi w Render. Strony głównej to nie zmienia.
7. Wgraj plik logo do `public/assets/brand/logo.svg` (albo `.png`). Bez pliku w nagłówku jest logo tekstowe.
8. Dodaj klucz `PSI_KEY` (Google PageSpeed Insights, darmowy). Bez niego Google odrzuca większość zapytań o szybkość i wskaźnik E1 ma stan „brak pomiaru”.
9. Opcjonalnie: `CF_ACCOUNT_ID` i `CF_BROWSER_TOKEN` (Cloudflare Browser Run, token z uprawnieniem „Browser Rendering - Edit”). Włącza wskaźniki A6 i E2.

Kroki dla codziennych zadań (import przetargów, poranny przegląd, dokańczanie audytów):

10. W GitHubie dodaj sekret `DATABASE_URL` (zewnętrzny adres bazy) albo `APP_URL` i `CRON_TOKEN`.
11. Uruchom ręcznie workflow „Codzienny import” i sprawdź, czy przetargi pojawiły się w aplikacji.

## Etap 1. Panel PerfectData i zapytania ze strony

**Stan: zbudowane 20.11.2026.**

- Wygląd PerfectData, wybór modułów, pulpit dla włączonych modułów.
- Poczta: powiadomienie o zapytaniu, reset hasła, zaproszenie do zespołu linkiem.
- Kwalifikator: pytania i punkty jako tekst do edycji, komplet zawsze 100 punktów, wersje zasad.
- Widżet: przycisk w rogu albo formularz w treści strony, bez plików cookie, z klauzulą RODO i osobną zgodą na oferty.
- Lista i karta zapytań: rozpisane punkty, firma z rejestrów po NIP, audyt techniczny podanej strony, podstawa kontaktu, status, usuwanie na żądanie.

Co zostało po stronie właściciela:

1. Zgoda na kod widżetu na perfectdata.pl i wybór strony do pierwszego testu.
2. W ustawieniach widżetu: domena strony, adres polityki prywatności, przegląd pytań i punktów z osobą, która odbiera zapytania.
3. Dopisanie w polityce prywatności perfectdata.pl formularza zapytań i asystenta AI.
4. Jeśli strona ma nagłówek Content-Security-Policy: dopisanie adresu panelu do `script-src` i `connect-src`.

Test na stronie: połowa odwiedzających widzi zwykły formularz, połowa pytania. Panel ogłasza zwycięzcę dopiero przy pewności 95%.

## Etap 2. Audyt AI

**Stan: zbudowane 20.11.2026.** Wszystkie 24 wskaźniki są w silniku. Ile z nich da się zmierzyć, zależy od podłączonych usług:

| Usługa | Wskaźniki | Bez niej |
|---|---|---|
| Brak (sam silnik) | 19 wskaźników, 76 pkt | - |
| PageSpeed Insights (`PSI_KEY`) | E1, 6 pkt | brak pomiaru |
| Claude (`ANTHROPIC_API_KEY`) | D1 i D2, 12 pkt | brak pomiaru |
| Cloudflare Browser Run | A6 i E2, 6 pkt | brak pomiaru |

Brak pomiaru nie odbiera punktów: wynik liczymy proporcjonalnie z tego, co zmierzone.

**Pierwszy audyt prawdziwej strony: perfectdata.pl, 20.11.2026** (strona główna, fraza przykładowa, bez kluczy usług). Wynik 74 na 100 z 68 zmierzonych punktów. Najważniejsze ustalenia:

- **A5: zapytanie z nagłówkiem PerplexityBot dostaje kod 403**, a Googlebot, Bingbot, OAI-SearchBot i Claude-SearchBot kod 200. Test wychodzi z serwera GitHuba, więc to sygnał do sprawdzenia w Cloudflare (ustawienia botów AI), a nie dowód.
- **robots.txt blokuje boty treningowe** (GPTBot, ClaudeBot, Google-Extended). To nie odbiera punktów i nie wpływa na wyszukiwanie, ale warto wiedzieć, że tak jest ustawione.
- **B3:** nagłówek H1 („Marketing dla Twojej firmy. Wyniki, które widać.”) ma 1 z 5 słów wspólnych z tytułem strony.
- **B7:** jest typ ProfessionalService, brakuje WebSite.
- **C1 i C5:** nie ma linku do strony albo sekcji „O nas”, a w stopce nie ma danych firmy (NIP, telefon albo e-mail).
- Dobrze: indeksowanie, fragment, robots.txt dla botów wyszukiwania, tytuł, opis, nagłówki sekcji, akapity, opisy zdjęć, HTTPS.

Audyt można powtórzyć workflowem „Audyt AI prawdziwej strony” w GitHubie albo w panelu po uruchomieniu.

Połączenie z zapytaniami: adres podany w widżecie dostaje audyt techniczny, widoczny na karcie zapytania. Audyt AI wymaga frazy, więc handlowiec uruchamia go sam jednym kliknięciem.

## Etap 3. AI i wyniki

- **Czat AI w widżecie** z bazą wiedzy firmy, limitem pytań na rozmowę i limitem odpowiedzi na miesiąc: zbudowane. Wymaga klucza `ANTHROPIC_API_KEY`. Przed włączeniem: zapis o asystencie AI w polityce prywatności perfectdata.pl.
- **Lejek i wynik testu** formularz kontra kwalifikator: zbudowane.
- **Render strony i ocena AI** (A6, E2, D1, D2): zbudowane. Wymagają tokenu Cloudflare i klucza Claude. Wywołanie Cloudflare jest napisane według dokumentacji i przetestowane na zapisanej odpowiedzi, bez prawdziwego tokenu.
- **Lista stron z mapy witryny:** zbudowane.
- **Test cytowań w ChatGPT, Perplexity i Gemini:** do zbudowania. Wymaga trzech kluczy i decyzji, jak często pytać, bo to jedyny koszt zależny od liczby audytów.
- **Dane z kont klienta** (Search Console, Bing Webmaster Tools): do zbudowania. Wymagają logowania Google i Microsoft.

## Etap 4. Pozostałe moduły i sprzedaż platformy

Przetargi, oferty, kontrahenci, kontakty i wiadomości działają w silniku od razu po uruchomieniu (etap 0). Ten etap dodaje brakujące funkcje tych modułów i przygotowuje sprzedaż platformy innym firmom.

### 4a. Braki przed pilotażem

1. **E-maile:** zbudowane (poranny przegląd z przetargami, zapytaniami i alertami, reset hasła, zaproszenia).
2. **Konto i zespół:** zaproszenia e-mailem są. Do zrobienia: potwierdzenie adresu e-mail przy rejestracji, usunięcie konta i eksport danych (RODO).
3. **AI:** czat w widżecie ma miesięczny limit odpowiedzi. Do zrobienia: limit wydatków na AI dla pozostałych funkcji. Zużycie tokenów jest zapisywane w dzienniku.
4. **Kopie i awarie:** kopie zapasowe bazy. Powiadomienie o nieudanym imporcie już działa (GitHub wysyła e-mail).
5. **Firmy spoza wykazu VAT:** integracja z CEIDG, żeby dało się sprawdzić jednoosobowe firmy zwolnione z VAT.

Do sprawdzenia po wdrożeniu: czy rejestr decyzji UOKiK przyjmuje połączenia z serwera we Frankfurcie. Jeśli nie, decyzje wgrywa się ręcznie w Ustawienia → Decyzje UOKiK (minuta raz w tygodniu).

### 4b. Pilotaż (4-6 tygodni)

- Bezpłatny dostęp w zamian za krótką rozmowę co tydzień. Konta z linku z kodem zaproszenia.
- Co mierzyć:
  - ile dopasowanych przetargów klient otwiera,
  - ile jest trafień nietrafionych,
  - ile ofert powstaje i ile czasu oszczędza szkic,
  - których modułów nikt nie używa.
- Poprawki co tydzień.
- Pod koniec pytanie, ile klient zapłaciłby za to miesięcznie.

### 4c. Start sprzedaży

1. **Cennik i pakiety** z limitami: profile przetargowe, analizy AI, obserwowane firmy, osoby w zespole, widżety i audyty.
2. **Płatności:** bramka z płatnościami cyklicznymi i automatyczne faktury VAT.
3. **Strona sprzedażowa:** cennik, 2-minutowe nagranie demo i opinie z pilotażu (prawdziwe, za zgodą firm).
4. **Darmowe sprawdzenie kontrahenta po NIP** jako sposób na zdobywanie kontaktów, na wzór PrestaCheck.
5. **Demo z adresu:** wpisujesz adres strony, platforma pokazuje gotowy kwalifikator i wynik audytu (etap 4 zapytań).
6. **Kanały:** LinkedIn, stowarzyszenia branżowe i biura rachunkowe jako partnerzy polecający.
7. **Otwarta rejestracja** (`SIGNUP_OPEN=1`) dopiero z regulaminem i polityką prywatności.

### 4d. Rozwój produktu (kolejność według wartości)

1. **Wyniki przetargów z BZP:** kto wygrywa i za ile. Daje analizę konkurencji i listę potencjalnych klientów, np. zwycięzców szukających podwykonawców.
2. **Baza Konkurencyjności** jako trzecie źródło. To zamówienia firm z dotacjami, często małe i dobre dla MŚP.
3. **Ryzyko płatnicze:**
   - szacunek, po ilu dniach firma płaci dostawcom, ze sprawozdań finansowych w KRS,
   - postępowania upadłościowe i restrukturyzacyjne z Krajowego Rejestru Zadłużonych.
4. **Reklamy Google i Meta.** Wnioski warto złożyć wcześnie, bo zależą od zewnętrznych zgód:
   - Google: poziom dostępu Basic dla projektu Google Cloud, z weryfikacją marki,
   - Meta: weryfikacja firmy i przegląd aplikacji (App Review) dla uprawnienia ads_management.
5. **Wpis długu do BIG z platformy.** KRD ma API, ale wymaga umowy. Trzeba zapytać KRD i BIG InfoMonitor o współpracę partnerską.
6. **Wysyłka z Gmaila i Microsoft 365** zamiast pliku .eml. Uprawnienie Gmaila do wysyłania wymaga weryfikacji aplikacji przez Google.
7. **Serwer pośredniczący w Polsce** dla rejestru UOKiK, jeśli blokada adresów zagranicznych się potwierdzi.
8. **Pozyskiwanie klientów:**
   - zbieranie zapytań ofertowych firm ze źródeł (strony firm, Baza Konkurencyjności, platformy zakupowe),
   - opis idealnego klienta z treści strony firmy,
   - wyszukiwarka firm: branża, region, wielkość, wiek, kondycja, udział w przetargach,
   - sekwencje z przypomnieniami i dziennym limitem. Wymagają połączenia ze skrzynką (punkt 6).
9. **Widoczność w Google:** dane z Search Console klienta i ocena wizytówki Google.
10. **Dotacje:** baza otwartych naborów i ocena, czy firma spełnia warunki.
11. **Drobne uzupełnienia:** odhaczanie gotowych dokumentów w ofercie i szacowany czas pracy przy poprawkach z audytu technicznego.

## Co jest potrzebne od właściciela

Bez tego panel nie ruszy albo nie zrobi części pracy. Tych rzeczy nie da się zdobyć z kodu:

| Co | Do czego | Skąd |
|---|---|---|
| Konto Render i baza | uruchomienie panelu | render.com (albo Neon dla bazy) |
| `ANTHROPIC_API_KEY` | czat, ocena AI, szkice | console.anthropic.com |
| `RESEND_API_KEY`, `MAIL_FROM` i rekordy DNS | powiadomienia, reset hasła, poranny przegląd | resend.com |
| `PSI_KEY` | szybkość strony w audytach | Google Cloud, interfejs PageSpeed Insights |
| `CF_ACCOUNT_ID`, `CF_BROWSER_TOKEN` | wskaźniki A6 i E2 | Cloudflare, Browser Run |
| Zgoda na kod widżetu i wpis w polityce prywatności | zapytania z perfectdata.pl | decyzja właściciela |
| Plik logo | nagłówek panelu i raporty | - |
| Wnioski do Google i Meta | moduł Reklamy | Google Ads API (dostęp Basic), Meta App Review |
| Logowanie Google dla aplikacji | Search Console, wizytówka Google | Google Cloud, ekran zgody OAuth |

Decyzje:

1. **Adres panelu:** subdomena perfectdata.pl czy adres z Render.
2. **Pytania, punkty i progi kwalifikatora.** Startowe są w panelu. Do przejrzenia z osobą, która odbiera zapytania. Osobno: czy pytanie o budżet ma zostać.
3. **Progi umowne w wytycznych audytu.** Jest ich 11 oraz progi wyniku 85 i 60 pkt. Zespół SEO powinien je przejrzeć przed pracą z klientami, bo późniejsza zmiana to nowa wersja wytycznych.
4. **Zakres wskaźników C2 i C3.** W wersji 2026-11-20 daty i źródła punktujemy tylko na artykułach. Na stronie głównej, stronie produktu i ofercie raport pokazuje „nie dotyczy”. Powód: wymaganie daty i linków do źródeł na stronie głównej nie jest dobrym SEO.
5. **Raport dla klienta:** teraz pokazuje punkty. Do decyzji, czy mają zostać same stany „Dobrze”, „Do poprawy”, „Źle”.
6. **Czas odpowiedzi w podziękowaniu.** Widżet obiecuje kontakt w następnym dniu roboczym. Tekst zmienisz w ustawieniach widżetu.
7. **Test cytowań w AI:** które wyszukiwarki i jak często.

Potrzebne przed etapem 4:

- **Nazwa i domena** platformy zamiast „[Nazwa]”.
- **Branża na start.** Dane demo są pod roboty drogowe i budowlane.
- **5-10 firm do pilotażu.**
- **Prawnik.** Potrzebny do:
  - regulaminu i polityki prywatności (po ich przygotowaniu wpisz adresy w `TERMS_URL` i `PRIVACY_URL`),
  - umowy powierzenia przetwarzania danych: klienci wpisują dane osób kontaktowych, więc platforma przetwarza je w ich imieniu, a podwykonawcami są Render, Anthropic i usługa e-mail,
  - opinii o module wiadomości (art. 398 Prawa komunikacji elektronicznej) i o module należności (art. 15 i 15a ustawy o udostępnianiu informacji gospodarczych).

## Co zostało sprawdzone

### Budowa silnika 20.11.2026

- **113 testów** na SQLite i PostgreSQL (GitHub Actions) oraz test obrazu Docker. Nowe testy obejmują:
  - ocenę zapytania dla każdej z 800 kombinacji odpowiedzi,
  - widżet: obcą stronę, brak nagłówka Origin, wyłączony widżet, limity prób, dzienny limit, za długie pola, ukryte pole dla automatów, formularz wysłany w tej samej sekundzie,
  - punkty i progi, których nie ma w odpowiedzi dla przeglądarki,
  - czat: odpowiedź tylko z bazy wiedzy, limity, pytanie odwiedzającego poza instrukcją modelu,
  - audyt AI: stronę wzorcową (100 pkt), stronę bez niczego, próg graniczny każdego wskaźnika, ten sam wynik przy powtórzeniu, odrzucenie cytatu spoza strony, brak ponownego pytania modelu przy niezmienionej treści,
  - izolację danych między firmami i kod HTML z cudzej strony pokazany jako tekst na każdym nowym ekranie,
  - reset hasła, zaproszenia, pocztę (wysłana, błąd usługi, brak konfiguracji), poranny przegląd.
- **Przeglądarka (Chromium):**
  - widżet w panelu i na osobnej stronie z własnymi stylami: style strony nie zmieniają widżetu, widżet nie zapisuje plików cookie ani pamięci przeglądarki, niedopuszczona strona dostaje odmowę,
  - audyt uruchomiony z interfejsu kończy się sam i pokazuje wynik, raport dla klienta otwiera się bez logowania i ma nagłówek noindex,
  - 108 stron panelu w 5 szerokościach (320-1280 px) i wcześniej 109 stron w 10 szerokościach bez poziomego przewijania, 96 stron i 10 pobrań bez błędów.
- **Prawdziwe dane:** audyt AI strony perfectdata.pl wykonany dwa razy z serwera GitHuba (tylko odczyt publicznych stron). Pierwszy przebieg pokazał dwa błędy silnika, oba poprawione i potwierdzone drugim przebiegiem: sklejone słowa w nagłówku z podziałem wiersza oraz niezaliczanie sekcji „Kontakt” na stronie jednoekranowej.
- **Wytyczne:** silnik, strona „Wytyczne” i dokument dla zespołu czytają jeden plik. Test sprawdza, że każdy próg na stronie wytycznych jest progiem w silniku.

Czego nie dało się sprawdzić przy budowie:

- prawdziwej wysyłki e-maila przez Resend, prawdziwego zapytania do modelu AI, prawdziwego wywołania Cloudflare Browser Run i PageSpeed z kluczem (wymagają kluczy właściciela, w testach są zapisane odpowiedzi w formacie tych usług),
- widżetu na samej perfectdata.pl (wymaga zgody),
- czy zapora perfectdata.pl wpuszcza prawdziwego PerplexityBota (test wychodził z serwera GitHuba z nagłówkiem bota),
- zachowania na samej platformie Render.

### Wcześniejsze sprawdzenia (19.11.2026)

Silnik:

- 68 testów automatycznych na SQLite i PostgreSQL, w tym próba wstrzyknięcia HTML w każdym polu na każdej stronie, wszystkie akcje zapisu i izolacja danych między firmami.
- Poprawka znaleziona przy budowie mockupu: w uzasadnieniu dopasowania przetargu słowa kluczowe były pokazywane bez polskich znaków. Teraz są w zapisie użytkownika.
- Obraz Docker z bazą PostgreSQL: test dymny w GitHub Actions (nagłówki bezpieczeństwa, pliki statyczne, rejestracja z kodem, zapis formularza, zadania cykliczne, brak startu bez bazy).
- Ten sam obraz z zasobami najmniejszej instancji Render (512 MB RAM, 0,1 CPU): start w 2 s, 60 zapytań naraz obsłużonych, ok. 30 MB zajętej pamięci.
- Próba generalna na prawdziwych danych: import 1334 ogłoszeń z BZP i 861 z TED z 2 dni bez błędów i duplikatów, parser na 40 losowych ogłoszeniach (w tym krótkie ogłoszenia poniżej progu ustawy Pzp), 75 stron aplikacji i 6 szkiców ofert na prawdziwych ogłoszeniach, karty 10 prawdziwych firm (w tym poprawnie wykryta spółka w likwidacji).
- Test w przeglądarce całej ścieżki użytkownika na danych demonstracyjnych.
- Import przy bazie w innym regionie niż GitHub Actions (symulowane opóźnienie 100 ms): 2200 ogłoszeń i 10 profili w kilkanaście sekund. Przed poprawką trwałoby to ponad pół godziny.
- Przepisy cytowane w aplikacji sprawdzone w źródłach.

Mockup całego panelu:

- 32 ekrany w 4 szerokościach (340, 390, 820 i 1366 px): bez błędów w konsoli, bez poziomego przewijania, bez uciętego tekstu w przyciskach i oznaczeniach stanu, bez nachodzących na siebie elementów.
- Reguły przeniesione z silnika dają te same wyniki co kod PHP: ryzyko prawne wiadomości (24 kombinacje kontaktu i rodzaju wiadomości), dopasowanie 7 przetargów, warunki wpisu do BIG dla 3 należności i etykiety oceny 4 firm. Razem 38 porównań, wszystkie zgodne.
- Liczby liczone z danych, nie wpisane ręcznie: wynik audytu 53 i 97 pkt, oceny zapytań, dopasowania przetargów 100, 96, 86, 44 i 41, oceny firm 5, 50 i 93, koszt konwersji i jego zmiana, alerty reklam, wynik testu z = 1,07.
- Ekran ryzyka wiadomości: przy ryzyku wysokim przycisk działa dopiero po potwierdzeniu, decyzja trafia do dziennika, a przy sprzeciwie odbiorcy wysyłka jest zablokowana.
- Kampanie: wstrzymanie i zmiana budżetu wymagają potwierdzenia, trafiają do dziennika i zmieniają listę alertów. Błędna kwota zatrzymuje zmianę.
- Widżet: błędny e-mail zatrzymuje wysyłkę i nie kasuje pozostałych pól, kod HTML wpisany w pole wyświetla się jako tekst.
- Wykresy: wartości pod kursorem i po strzałkach z klawiatury, te same dane w tabeli pod wykresem. Dwa kolory serii sprawdzone pod kątem rozróżnialności dla osób z zaburzeniami widzenia barw.
- Obsługa klawiaturą z widocznym fokusem, kontrast tekstu na tle co najmniej 7:1 (wymagane minimum to 4,5:1).
- Strona nie pobiera żadnych zasobów z sieci. Żaden link na pulpicie i liście funkcji nie prowadzi donikąd.

Ponowne sprawdzenie 20.11.2026:

- Próba generalna na prawdziwych danych: import 1398 ogłoszeń z BZP i 861 z TED z 2 dni, parser na 40 losowych ogłoszeniach (40 z 40), karty prawdziwych firm, 6 szkiców ofert z poprawnym plikiem .docx, powtórny import bez duplikatów.
- Połączenia z rejestrami: BZP, TED, wykaz VAT i KRS odpowiadają w oczekiwanym formacie. Rejestr decyzji UOKiK nie przyjmuje połączeń z sieci GitHuba, więc zostaje ręczne wgrywanie w Ustawieniach.
- Uruchomiona aplikacja na danych demonstracyjnych: 59 stron i 10 pobrań (kalendarz, .docx, .eml) bez błędów, błędne hasło i błędny NIP dają czytelny komunikat, nieistniejące adresy zwracają 404, wylogowanie zamyka dostęp.
- Wiadomość o ryzyku bardzo wysokim: przycisk jest nieaktywny bez potwierdzenia, serwer odmawia zapisu także przy ominięciu przycisku, a potwierdzenie zapisuje się z użytkownikiem i godziną.
- Znalezione i poprawione: na telefonie strona audytu i część kart przetargów przewijały się w bok. Po poprawce 71 stron w 11 szerokościach (320-1366 px) nie ma poziomego przewijania.

Czego nie dało się sprawdzić:

- prawdziwego zapytania do modelu AI (wymaga klucza API),
- połączenia z rejestrem UOKiK z serwera Render,
- zachowania na samej platformie Render (obraz jest ten sam co w teście, ale infrastruktura inna),
- wyglądu mockupu z czcionkami systemowymi Windows i macOS (test był w Chromium na Linuksie),
- kosztu jednej rozmowy w czacie AI (zależy od długości bazy wiedzy, do zmierzenia w pilotażu).
