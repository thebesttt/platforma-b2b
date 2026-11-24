# Panel PerfectData: zapytania ze strony, audyt, klienci, przetargi, oferty, kontrahenci

Aplikacja dla firm, które sprzedają innym firmom. Zbiera i ocenia zapytania ze strony, sprawdza strony i kontrahentów, pilnuje ryzyka prawnego wiadomości i dobiera przetargi. Nazwę i wygląd ustawiają zmienne `APP_NAME` i `APP_THEME` (`perfectdata` to granat i złoto ze strony perfectdata.pl, puste to wygląd standardowy). Każda firma może w Ustawieniach wybrać wygląd i moduły widoczne w menu.

## Moduły

| Moduł | Co robi | Źródła danych |
|---|---|---|
| Zapytania ze strony | Widżet wklejany na stronę firmy zadaje odwiedzającemu kilka pytań zamiast pustego formularza. Program liczy ocenę 0-100 z jawnej tabeli punktów, zapisuje kontakt z podstawą „zapytanie od tej firmy”, wysyła powiadomienie e-mail, sprawdza firmę po NIP i audytuje podaną stronę. Czat AI odpowiada wyłącznie z bazy wiedzy firmy. Test dwóch wersji (zwykły formularz i pytania) z testem istotności. | Odpowiedzi odwiedzającego, biała lista VAT, KRS, silnik audytu, Claude API |
| Przetargi | Codzienny import ogłoszeń i dopasowanie do profili firmy (CPV, słowa kluczowe, województwa, wartość) z uzasadnieniem oceny. Karta przetargu pokazuje wadium, kryteria, terminy i warunki z treści ogłoszenia, każdy punkt ze wskazaniem źródła. Terminy można dodać do kalendarza (.ics). Ekran „Wyniki” pokazuje, kto wygrywa w branży i regionie z profilu, za ile i przy ilu ofertach. Karta przetargu pokazuje wynik postępowania albo wcześniejszych zwycięzców u tego zamawiającego. | BZP (API e-Zamówień), TED API v3 |
| Analiza SWZ (AI) | Wgrywasz SWZ, OPZ i wzór umowy (PDF, DOCX, TXT). AI wypisuje warunki udziału, kary, płatności i ryzyka ze wskazaniem rozdziału. Odpowiada też na pytania do dokumentacji. | Dokumenty użytkownika, Claude API |
| Oferty i wnioski | Szkic oferty: formularz, sposób realizacji, doświadczenie, zespół i lista dokumentów, z danych ogłoszenia i biblioteki firmy. Wnioski o dotacje mają osobne sekcje i korzystają z regulaminu naboru. Zdania bez źródła są oznaczone ⟦sprawdź⟧. Eksport do .docx. Praca zespołu: sekcję przydziela się osobie (dostaje e-mail i widzi ją na pulpicie), a zapis sprawdza wersję, więc dwie osoby nie nadpiszą sobie tekstu. Lista „Dokumenty do złożenia” powstaje z ogłoszenia i analizy SWZ, zespół ją odhacza i uzupełnia. Wniosek o dotację ma ekran „Warunki naboru”: AI wypisuje warunki z wgranego regulaminu, a program zostawia tylko te z dosłownym cytatem i pozwala na ocenę „spełnia” albo „nie spełnia” tylko z potwierdzeniem w danych firmy. Ekran „Nabory dotacji” pokazuje pięć oficjalnych serwisów z naborami i prowadzi listę naborów wpisanych przez użytkownika: przypomina 14, 7 i 3 dni przed końcem i zakłada z naboru szkic wniosku. | Ogłoszenie, SWZ, regulamin, biblioteka firmy, nabory wpisane przez użytkownika |
| Kontrahenci | Karta firmy po NIP z oceną ryzyka płatniczego 0-100 i listą sygnałów. Firmę można obserwować, a zmiany w rejestrach wywołują alerty. Przy należnościach są wezwanie do zapłaty (.docx) i lista warunków wpisu do BIG. Sekcja „Zadłużenie i płatności” prowadzi przez dwa rejestry, których program nie pobierze: użytkownik wpisuje wynik z Krajowego Rejestru Zadłużonych i dwie liczby ze sprawozdania finansowego, a panel liczy, po ilu dniach firma płaci dostawcom, i co 90 dni przypomina o ponownym sprawdzeniu obserwowanych firm. | Biała lista VAT, KRS, rejestr decyzji UOKiK (zatory płatnicze), wpisy użytkownika z KRZ i sprawozdań |
| Klienci i wiadomości | Kontakty z zapisaną podstawą kontaktu, szkice wiadomości (regułowe lub AI z treści strony odbiorcy) oraz ocena ryzyka prawnego przy każdym rodzaju wiadomości. Przy ryzyku wysokim użytkownik musi świadomie je potwierdzić, a potwierdzenie trafia do historii. Wysyłka idzie z własnej skrzynki użytkownika (mailto lub .eml). Przypomnienia: po wysłanej wiadomości panel pilnuje dwóch kolejnych kroków (po 4 i po 10 dniach), przygotowuje ich treść i trzyma się dziennego limitu firmy. Zatrzymuje je odpowiedź zaznaczona w panelu albo wypisanie. Oferty mają w stopce adres wypisania: odbiorca wypisuje się sam, bez logowania i bez plików cookie, a panel blokuje kolejne oferty do tej firmy. Ekran „Idealny klient”: opis przygotowany przez AI ze strony firmy i biblioteki (grupy klientów tylko z dosłownym cytatem) albo wpisany ręcznie. Szkice ofert pisane przez AI z niego korzystają. Ekran „Zapytania z rynku”: lista miejsc, w których firmy ogłaszają zapytania ofertowe (Baza Konkurencyjności, platformy zakupowe, strony firm), i rejestr zapytań wpisanych przez użytkownika. Panel pilnuje terminu, z adresu e-mail z ogłoszenia zakłada kontakt z podstawą „zapytanie ofertowe” i przygotowuje odpowiedź. Ekran „Potencjalni klienci”: lista firm, do których warto się odezwać, z wyników przetargów albo z NIP-ów i stron wklejonych przez użytkownika. Panel dopisuje dane z wykazu VAT i KRS, liczy dopasowanie z jawnej tabeli punktów, układa treść oferty z faktów i pokazuje drogi kontaktu z oceną ryzyka: odpowiedź na zapytanie, adres podany przez firmę do ofert (cytat i link ze strony firmy), list pocztą (.docx) i e-mail bez zgody przez ekran ryzyka. Adresów e-mail panel nie zbiera. Zgoda na ofertę e-mailem: list ma adres strony zgody (`/z/kod`) i kod QR, firma sama wpisuje tam e-mail i zaznacza zgodę, a panel zapisuje dowód (treść, czas, źródło) i daje kontaktowi podstawę „zgoda”. Strona zgody działa bez logowania i bez plików cookie. Zgodę wyrażoną inaczej użytkownik zapisuje ręcznie z opisem. Dodatki: audyt strony firmy jako zachęta (list obiecuje raport, prośba o raport i zgoda na ofertę to osobne pola, raport pod adresem `/raport/token`), lejek listów z kosztem jednej zgody i historią na karcie, listy seryjnie (jeden plik z kodami QR i adresy w CSV), drugi list po 21 dniach bez odpowiedzi, ogólny kod QR firmy na wizytówkę i stoisko, pytanie o zainteresowania na stronie zgody oraz wyszukiwanie jednoosobowych działalności w CEIDG po PKD i regionie (po wpisaniu `CEIDG_TOKEN`). | Dane użytkownika, strona firmy, wyniki przetargów, wykaz VAT, KRS |
| Audyt AI (treść) | Jedna strona i jedna fraza: 24 wskaźniki, 100 punktów, pięć grup (dostęp botów, struktura, wiarygodność, odpowiedzi, szybkość). Raport z linijką punktów, listą „Najpierw to”, dowodem i trzema progami przy każdym wskaźniku. Porównanie „przed i po”, strona „Wytyczne wskaźników” i raport dla klienta pod linkiem bez logowania. 22 wskaźniki liczy program, 2 ocenia model AI z cytatem, który program sprawdza w treści strony. | Publiczna strona i jej robots.txt, PageSpeed Insights, Claude API, opcjonalnie Cloudflare Browser Run |
| Audyt techniczny | Bezpieczeństwo, szybkość serwera, SEO techniczne, analityka i RODO. Możesz zestawić swoją stronę z konkurentami. To ten sam silnik co w PrestaCheck (`lib/prestacheck`). | Publiczne strony, opcjonalnie Google PageSpeed |
| Widoczność | Co poprawić najpierw w Google, gdzie stronę widać w odpowiedziach AI i co publikuje konkurencja. Start od adresu witryny: dane z Search Console trafiają z pliku wyeksportowanego przyciskiem „Eksportuj” (panel sam rozpoznaje raport i okres) albo ze stałego połączenia przez konto usługi Google. Ekrany: zapytania tuż za czołówką, wysoka pozycja z niską klikalnością, spadki, dwie strony na jedno zapytanie, strony w odpowiedziach AI Google, cytowania z Copilota, wejścia z asystentów AI, boty AI na stronie, nowe strony i tematy konkurentów. | Search Console (plik albo API), Google Analytics, Bing Webmaster Tools (wklejana tabela), Cloudflare, publiczne mapy witryn |
| Reklamy | Ekran informacyjny. Integracja z Google Ads i Meta czeka na zatwierdzenie dostępu do API. | - |

### Zasady wbudowane w produkt

- **Wiadomości.** Platforma pokazuje ryzyko prawne każdego rodzaju wiadomości. Podstawą jest art. 398 Prawa komunikacji elektronicznej. Kary wynikają z art. 444 ust. 1 pkt 81 i art. 446 ust. 5 (do 3% przychodu albo 1 mln zł) oraz z art. 448 (wykroczenie). Decyzję podejmuje klient.
  - Przy ryzyku wysokim wymagamy potwierdzenia. Zapisujemy wtedy, kto potwierdził, kiedy, z jakiego IP i jakim tekstem (`messages.ack_*` i `audit_log`).
  - Jedyna twarda blokada dotyczy kontaktu, który zgłosił sprzeciw (art. 21 RODO). Takiemu kontaktowi nie da się wysłać ofert ani próśb o zgodę.
- **Należności.** Długi zgłoszone przez użytkownika są widoczne tylko dla jego firmy. Platforma nie udostępnia ich innym użytkownikom, bo zakazuje tego ustawa o udostępnianiu informacji gospodarczych. Wpisu do BIG dokonuje się u biura, a aplikacja sprawdza tylko warunki z art. 15 (dłużnik niebędący konsumentem; art. 14 dotyczy konsumentów):
  - co najmniej 500 zł,
  - wymagalne od co najmniej 30 dni,
  - wezwanie wysłane co najmniej miesiąc wcześniej, z firmą i adresem biura oraz pouczeniem o prawie do sprzeciwu (art. 15a).
- **Zapytania ze strony.** Ocenę liczy program, nie AI: te same odpowiedzi dają zawsze tę samą ocenę, a na karcie zapytania widać, za co są punkty. Punkty i progi nie trafiają do przeglądarki odwiedzającego. Zmiana zasad podnosi ich wersję i działa tylko dla kolejnych zapytań.
  - Widżet odpowiada tylko stronom wpisanym w ustawieniach (nagłówek `Origin`). Nie zapisuje plików cookie ani niczego w pamięci przeglądarki. Zamiast adresu IP zapisujemy jego skrót z solą zmienianą co dobę.
  - Zgoda na oferty e-mailem to osobne, niezaznaczone pole. Odpowiedź na zapytanie jej nie wymaga, kolejne oferty tak. Treść i datę zgody zapisujemy przy kontakcie.
  - Czat ma limit pytań na rozmowę i limit odpowiedzi na miesiąc. Treść od odwiedzającego trafia do modelu jako dane, nie jako polecenie.
- **Audyt AI.** Wytyczne są w jednym pliku `resources/audyt-ai-rules.json`. Z niego powstaje punktacja w silniku, strona „Wytyczne” i dokument `mockup/audyt-ai/WYTYCZNE.md`, więc próg w dokumentacji jest zawsze progiem w raporcie.
  - Każdy audyt zapisuje wersję wytycznych. Zmiana progu to nowa wersja, a stare audyty zostają przy swojej.
  - Gdy wskaźnika nie da się zmierzyć (usługa nie jest podłączona albo nie odpowiada) albo nie dotyczy danego rodzaju strony, dostaje stan „brak pomiaru” lub „nie dotyczy” i nie odbiera punktów: wynik liczymy proporcjonalnie z pozostałych.
  - Ocena AI (D1, D2) wymaga cytatu ze strony. Cytat, którego nie ma w treści, odrzucamy. Lista 10 pytań pobocznych jest zatwierdzana raz i potem się nie zmienia. Gdy treść i pytania się nie zmieniły, bierzemy poprzednią ocenę.
  - Po audycie nie przechowujemy treści strony, tylko jej skrót, wyniki i dowody.
- **AI.** Model pisze wyłącznie z podanych materiałów. Każdy punkt analizy ma źródło, a zdania bez źródła są oznaczone do sprawdzenia. Bez klucza `ANTHROPIC_API_KEY` działa analiza regułowa, a przyciski AI są nieaktywne.

## Technologia

- PHP 8.2 lub nowszy, bez zewnętrznych bibliotek. Rozszerzenia: `pdo_pgsql` albo `pdo_sqlite`, `curl`, `mbstring`, `zip`, `intl`.
- Opcjonalnie `pdftotext` z pakietu poppler-utils do odczytu PDF.
- PostgreSQL w produkcji, SQLite lokalnie i w testach. Migracje są w `migrations/`.
- Widoki to szablony PHP w `views/`. Strona działa bez JavaScriptu (`public/assets/app.js` to drobne udogodnienia).
- Bezpieczeństwo:
  - sesje w bazie,
  - tokeny CSRF przy każdym POST,
  - hasła argon2id,
  - limit prób logowania,
  - nagłówki CSP i X-Frame-Options,
  - izolacja danych firm (każde zapytanie filtruje po `org_id`),
  - audyt stron z blokadą adresów wewnętrznych (ochrona przed SSRF).

```
bin/console          polecenia: migrate, import, uokik:sync, watchlist, daily, user:create, demo:seed
lib/prestacheck/     silnik audytu stron (wspólny z PrestaCheck)
migrations/          schemat bazy
public/              index.php, CSS, JS
src/Core/            routing, baza, sesje, logowanie, HTTP
src/Http/            kontrolery stron
src/Modules/         Tenders, Companies, Offers, Outreach, Audit, Ai
tests/               testy (php tests/run.php), fixture'y API, test dymny, test kontraktu z API
views/               szablony stron
```

## Uruchomienie lokalne

```bash
cp .env.example .env                  # APP_ENV=dev, baza SQLite w storage/
php bin/console migrate
php bin/console user:create ty@firma.pl 'twoje-haslo-10+' 'Twoja Firma sp. z o.o.'
php bin/console import                # pobiera ogłoszenia z BZP i TED z ostatnich 3 dni
php -S localhost:8080 -t public public/index.php
```

Bez dostępu do sieci można pracować na zapisanych odpowiedziach API. Wtedy dopisz do `.env` linię `HTTP_FIXTURES=tests/fixtures/http`, uruchom `php bin/console demo:seed` i zaloguj się jako `demo@example.com` / `demo-haslo-123`.

## Wdrożenie na Render.com

1. W Render wybierz **New → Blueprint** i wskaż to repozytorium. Plik `render.yaml` utworzy aplikację (Docker) i bazę PostgreSQL.
2. Render zapyta o cztery zmienne. Każdą można uzupełnić później w zakładce Environment:
   - `ANTHROPIC_API_KEY` (funkcje AI: czat w widżecie, analiza dokumentów, szkice),
   - `ADMIN_EMAILS` (Twój e-mail: test połączenia z AI i z pocztą, ręczna aktualizacja decyzji UOKiK),
   - `RESEND_API_KEY` i `MAIL_FROM` (wysyłka e-maili: powiadomienia o zapytaniach, reset hasła, zaproszenia do zespołu). Klucz założysz na resend.com. Adres nadawcy musi być w domenie zweryfikowanej w Resend, np. `Panel PerfectData <panel@perfectdata.pl>`.

   Pozostałe ustawia się same. Opcjonalne (`PSI_KEY`, `TERMS_URL`, `PRIVACY_URL`, `APP_URL` przy własnej domenie) dodasz później.
3. Załóż pierwsze konto. Rejestracja jest domyślnie zamknięta (`SIGNUP_OPEN=0`), a Render generuje kod zaproszenia `SIGNUP_CODE`. Odczytaj go w zakładce Environment i otwórz `/rejestracja?kod=<kod>`. Ten sam link wyślesz firmom z pilotażu. Otwartą rejestrację (`SIGNUP_OPEN=1`) włącz dopiero, gdy będą regulamin i polityka prywatności (`TERMS_URL`, `PRIVACY_URL`).
4. Włącz codzienny import w GitHub: **Settings → Secrets and variables → Actions**. Wybierz jeden wariant:
   - `DATABASE_URL`: zewnętrzny adres bazy z Render („External Database URL”). Import działa wtedy w GitHub Actions. Ten wariant jest zalecany.
   - `APP_URL` i `CRON_TOKEN` (wartość z Render). Workflow wywoła wtedy `POST /cron/run` z nagłówkiem `X-Cron-Token`.
   Nieudany import kończy zadanie błędem, a GitHub wysyła o tym e-mail.

Bez zmiennej `DATABASE_URL` aplikacja na serwerze nie wystartuje. To celowe: zapis do pliku w kontenerze znikałby przy każdym wdrożeniu.

Darmowa baza PostgreSQL na Render wygasa po 30 dniach. Przed pierwszymi klientami przejdź na płatny plan albo podłącz bazę z Neon (`DATABASE_URL=postgres://…?sslmode=require`; w Neon skopiuj adres bezpośredni, bez `-pooler` w nazwie hosta). Darmowa instancja aplikacji usypia po okresie bezczynności, więc pierwsze wejście trwa dłużej.

### Zmienne środowiskowe

| Zmienna | Opis |
|---|---|
| `DATABASE_URL` | `postgres://user:haslo@host:5432/baza` (opcjonalnie `?sslmode=require`). Pusta oznacza SQLite. |
| `APP_SECRET` | Losowy sekret (Render generuje go sam). |
| `APP_THEME` | `perfectdata` włącza wygląd PerfectData (plik `public/assets/theme-perfectdata.css`). Logo: plik `public/assets/brand/logo.svg` (albo `.png`, `.webp`). Bez pliku w nagłówku jest logo tekstowe. |
| `RESEND_API_KEY`, `MAIL_FROM` | Wysyłka e-maili przez Resend. Bez nich panel działa, ale nie wysyła powiadomień ani linków do ustawienia hasła. Każda wiadomość, także nieudana, trafia do tabeli `outbox` i jest widoczna w Ustawieniach. Przy `APP_ENV=dev` wiadomości są tylko zapisywane. |
| `APP_NAME`, `APP_URL` | Nazwa produktu i adres publiczny. `APP_URL` jest opcjonalny: na Render aplikacja bierze adres z `RENDER_EXTERNAL_URL`. Ustaw go przy własnej domenie. |
| `ANTHROPIC_API_KEY`, `AI_MODEL` | Claude API, domyślnie `claude-sonnet-5-5`. `AI_MAX_INPUT_CHARS` ogranicza długość dokumentacji wysyłanej do modelu (domyślnie 300 000 znaków). Dokumentacja idzie jako blok z pamięcią podręczną, więc kolejne pytania o ten sam przetarg są tańsze. Zużycie tokenów trafia do `audit_log` (`ai.call`). |
| `CRON_TOKEN` | Token do `/cron/run`, przekazywany w nagłówku `X-Cron-Token`. |
| `PSI_KEY` | Klucz Google PageSpeed Insights (szybkość w audycie technicznym i wskaźnik E1 audytu AI). Bez klucza Google mocno ogranicza liczbę zapytań. |
| `CF_ACCOUNT_ID`, `CF_BROWSER_TOKEN` | Cloudflare Browser Run: strona po uruchomieniu skryptów przy ekranie 360 px (wskaźniki A6 i E2 audytu AI). Token API z uprawnieniem „Browser Rendering - Edit”. Bez nich te dwa wskaźniki mają stan „brak pomiaru”. |
| `CEIDG_TOKEN` | Token hurtowni danych CEIDG (dane.biznes.gov.pl, API v3): wyszukiwanie jednoosobowych działalności po kodzie PKD, regionie i dacie założenia na liście potencjalnych klientów. Bezpłatny, na wniosek właściciela firmy. Bez tokenu panel pokazuje, jak go zdobyć. |
| `GOOGLE_SERVICE_ACCOUNT` | Klucz konta usługi Google (treść pliku JSON albo ten plik w base64): stałe połączenie z Search Console API, Google Analytics Data API i Admin API, tylko odczyt. Właściciel witryny dodaje adres konta usługi jako użytkownika w Search Console i GA4, a panel sam znajduje usługę po domenie. Bez klucza działa wgrywanie plików z eksportu. Klucz działa tylko w firmie administratora panelu (`ADMIN_EMAILS`). |
| `CF_ANALYTICS_TOKEN` | Token Cloudflare z uprawnieniami „Zone → Analytics → Read” i „Zone → Zone → Read”: boty AI na witrynach za Cloudflare. Gdy jest pusty, panel używa `CF_BROWSER_TOKEN`. |
| `SIGNUP_OPEN`, `SIGNUP_CODE` | `SIGNUP_OPEN=0` (domyślnie): konto tylko z linku `/rejestracja?kod=<SIGNUP_CODE>`. `SIGNUP_OPEN=1`: rejestracja otwarta. |
| `TERMS_URL`, `PRIVACY_URL` | Adresy regulaminu i polityki prywatności, linkowane przy rejestracji. |
| `UPLOAD_MAX_MB` | Limit rozmiaru dokumentu, domyślnie 15. |
| `ADMIN_EMAILS` | E-maile administratorów platformy, rozdzielone przecinkami. Dają dostęp do ręcznej aktualizacji decyzji UOKiK. |

## Zadania cykliczne

Komenda `php bin/console daily`, endpoint `/cron/run?task=daily` i workflow `.github/workflows/daily.yml` uruchamiają to samo zadanie:

1. synchronizację decyzji UOKiK (raz w tygodniu),
2. import ogłoszeń z BZP i TED od ostatniego importu,
3. dopasowanie ogłoszeń do profili,
4. wyniki przetargów z BZP: kto wygrał każdą część, a z treści ogłoszeń ceny ofert, liczba ofert i wartość umowy. Pierwszy import obejmuje 7 dni, starsze pobiera `php bin/console results <dni>`. Firma, która zapisała przetarg, dostaje alert po jego rozstrzygnięciu,
5. odświeżenie obserwowanych kontrahentów (alerty o zmianach),
6. audyty AI, które ktoś zaczął i nie dokończył (także strony dodane hurtowo z mapy witryny),
7. zapytania ze strony: sprawdzenie firmy i strony dla zapytań, których nikt jeszcze nie otworzył, oraz usunięcie rozmów bez zostawionego kontaktu starszych niż 30 dni. Lista potencjalnych klientów: dane z rejestrów dla firm, które jeszcze ich nie mają,
8. poranny przegląd e-mailem dla każdej firmy: nowe dopasowane przetargi, rozstrzygnięte przetargi z listy zapisanych, zapytania ze strony i alerty o kontrahentach od poprzedniego przeglądu, a do tego terminy z list prowadzonych ręcznie: zapytania ofertowe z terminem w ciągu 3 dni i nabory dotacji 14, 7 i 3 dni przed końcem. Wychodzi tylko wtedy, gdy jest coś nowego, a firma może go wyłączyć w Ustawieniach,
9. sprzątanie: sesje, wygasłe wpisy, ogłoszenia po terminie starsze niż 120 dni bez ofert i wyniki przetargów starsze niż 400 dni.

### Rejestr decyzji UOKiK

Serwer `decyzje.uokik.gov.pl` nie przyjmuje połączeń z GitHub Actions. Połączenie kończy się przekroczeniem czasu, co wskazuje na blokadę adresów spoza Polski. Jeśli tak samo będzie z serwera Render, automatyczna synchronizacja nie zadziała. Listy można wtedy wgrać ręcznie na dwa sposoby:

- **Przez stronę.** Administrator (`ADMIN_EMAILS`) otwiera **Ustawienia → Decyzje UOKiK**, gdzie są linki do list z kolejnych lat. Zapisuje je w przeglądarce jako HTML i wgrywa.
- **Z konsoli.** Polecenie `php bin/console uokik:import pliki.html`.

Docelowym rozwiązaniem może być mały serwer pośredniczący w Polsce.

## Testy

```bash
php tests/run.php                                           # 172 testy, SQLite, bez sieci
TEST_DATABASE_URL=postgres://… php tests/run.php            # te same testy na PostgreSQL
php tests/live_contract.php                                 # prawdziwe API: BZP, TED, VAT, KRS, UOKiK
sh tests/smoke.sh http://localhost:10000                    # test dymny działającej instancji
php bin/console content:audit twojafirma.pl "fraza" home    # audyt AI jednej strony w konsoli, bez zapisu do bazy
```

Testy obejmują m.in. próbę wstrzyknięcia HTML w każdym polu na każdej stronie, wszystkie akcje zapisu i izolację danych między firmami.

Workflowy w GitHub Actions:

- `ci.yml`: testy na SQLite i PostgreSQL, budowa obrazu Docker i test dymny kontenera z bazą (nagłówki, pliki statyczne, rejestracja z kodem, zapis formularza, zadania cykliczne).
- `rehearsal.yml` (na żądanie): próba generalna na prawdziwych danych. Pełny import z BZP i TED do PostgreSQL, dopasowanie do przykładowych profili, parser na 40 losowych ogłoszeniach, strony aplikacji i szkice ofert na prawdziwych ogłoszeniach, karty prawdziwych firm.
- `live-api.yml` (co tydzień): czy publiczne API nie zmieniły formatu.
- `daily.yml`: codzienny import.

## Ograniczenia, o których warto wiedzieć

- **Biała lista VAT** ma dzienny limit zapytań na adres serwera. Dlatego obserwowane firmy odświeżamy zapytaniami zbiorczymi (30 firm naraz), a jedna firma może sprawdzić 40 nowych kontrahentów dziennie.
- **Firmy spoza wykazu VAT** (zwolnione z VAT, część jednostek publicznych) nie mają karty, bo nie mamy skąd pobrać danych. Rozwiązaniem będzie integracja z CEIDG.
- **Wysyłka e-maili** wymaga klucza Resend i zweryfikowanej domeny nadawcy. Bez nich nie ma powiadomień o zapytaniach ani linków do ustawienia hasła, a właściciel konta podaje nowym osobom hasło startowe. Oferty do klientów nadal wychodzą ze skrzynki użytkownika, nie z platformy.
- **Widżet zapytań** wymaga wklejenia jednej linii kodu na stronę firmy i wpisania jej domeny w ustawieniach widżetu. Jeśli strona ma nagłówek `Content-Security-Policy`, trzeba dopisać adres panelu do `script-src` i `connect-src`.
- **Audyt AI** mierzy to, co widać z zewnątrz. Test odpowiedzi dla botów wychodzi z serwera panelu z nagłówkiem User-Agent bota, więc zapora, która sprawdza adresy IP botów, może potraktować prawdziwego bota inaczej. Test cytowań w ChatGPT, Perplexity i Gemini oraz dane z Search Console nie są jeszcze zbudowane.
- **Funkcje AI** są przetestowane na zapisanych odpowiedziach w formacie API. Pierwsze prawdziwe zapytanie warto wykonać przyciskiem „Sprawdź połączenie z AI” w Ustawieniach (dla `ADMIN_EMAILS`).

## Plan rozwoju

- Automatyczny import tam, gdzie dziś dane wpisuje użytkownik (KRZ, sprawozdania finansowe, zapytania ofertowe, nabory dotacji), jeśli źródło udostępni dane programom.
- Integracja z biurem informacji gospodarczej przez partnera (wpis długu, raport o kontrahencie).
- Google Ads API i Meta Marketing API po zatwierdzeniu dostępu.
- Wysyłka wiadomości przez skrzynkę użytkownika (Gmail i Microsoft 365 przez OAuth) oraz przypomnienia o follow-upie.
- Płatności i plany abonamentowe.
