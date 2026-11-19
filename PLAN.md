# Plan działania

Stan na 19.11.2026.

**Zmiana kierunku.** Pierwszym użytkownikiem narzędzia jest PerfectData (perfectdata.pl). Cel: więcej zapytań ze strony i audyt, którym agencja pracuje z klientami SEO i GEO. Platforma B2B (przetargi, oferty, kontrahenci) zostaje silnikiem pod spodem i osobnym produktem na później.

Na perfectdata.pl nic nie zostało zainstalowane. Kod trafi na stronę dopiero po wyraźnej zgodzie właściciela.

## Co jest gotowe

- **Silnik:** aplikacja PHP z bazą PostgreSQL w repozytorium `platforma-b2b`. Konta, firmy, klienci, bezpieczne pobieranie stron, klient AI z dziennikiem zużycia, 68 testów. Gotowa do uruchomienia w Render.
- **Mockup całego panelu w wyglądzie PerfectData:** `mockup/audyt-ai`. 9 modułów i 32 ekrany, każda funkcja z opisu produktu na przykładowych danych. Prywatny podgląd w Claude i paczka `audyt-ai-mockup.zip` do wgrania na Cloudflare.
- **Wytyczne audytu:** 24 wskaźniki, 100 pkt, każdy z progami i podstawą. Plik `mockup/audyt-ai/WYTYCZNE.md`.
- **Plany modułów:** `PLAN-ZAPYTANIA.md` (zapytania ze strony) i `PLAN-AUDYT-AI.md` (audyt treści pod wyszukiwarki i AI).

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
| Zapytania | 0 | 5 | 0 |
| Klienci | 6 | 5 | 0 |
| Audyt AI | 0 | 7 | 0 |
| SEO i strona | 3 | 2 | 0 |
| Reklamy | 0 | 0 | 6 |
| Przetargi | 7 | 2 | 0 |
| Oferty i wnioski | 6 | 3 | 0 |
| Kontrahenci | 5 | 3 | 0 |
| Razem | 27 | 27 | 6 |

„Działa w silniku” znaczy, że kod jest w repozytorium i przechodzi testy. Silnik nie jest jeszcze uruchomiony online (etap 0).

Gdzie w planie są funkcje do zbudowania:

- **Zapytania i Audyt AI:** etapy 1-3.
- **Klienci:** zbieranie zapytań ofertowych, opis idealnego klienta, wyszukiwarka firm, sekwencje i wykrywanie odpowiedzi są w etapie 4d.
- **SEO i strona:** Search Console i wizytówka Google są w etapie 4d.
- **Reklamy:** wnioski do Google i Meta warto złożyć już teraz, bo zgody trwają tygodnie. Do tego czasu ekran pokazuje dane przykładowe.
- **Przetargi, Oferty, Kontrahenci:** brakujące funkcje są w etapach 4a i 4d.

## Etap 0. Uruchomienie silnika (ok. 2 godziny pracy właściciela)

1. W Render utwórz usługę przez New → Blueprint, z repozytorium `platforma-b2b`.
2. Render zapyta o dwie zmienne: `ANTHROPIC_API_KEY` i `ADMIN_EMAILS` (swój e-mail). Resztę ustawia sam.
3. Zdecyduj o bazie. Darmowa baza Render wygasa po 30 dniach, potem jest 14 dni na przejście na płatny plan, a po tym czasie dane są kasowane. Od razu wybierz płatny plan Render albo bazę w Neon (w Neon skopiuj adres bezpośredni, bez `-pooler` w nazwie hosta).
4. Załóż konto z linku `/rejestracja?kod=<SIGNUP_CODE>`. Kod jest w Render, w zakładce Environment. Rejestracja bez kodu jest zamknięta.
5. W Ustawieniach kliknij „Sprawdź połączenie z AI”. To pierwsze prawdziwe zapytanie do modelu.
6. Zdecyduj o adresie panelu: adres z Render albo subdomena, np. `panel.perfectdata.pl`. Subdomena to jeden rekord CNAME w DNS i wpis domeny w ustawieniach usługi w Render. Strony głównej to nie zmienia.

Kroki potrzebne tylko dla modułu przetargów (można odłożyć do etapu 4):

7. W GitHubie dodaj sekret `DATABASE_URL` (zewnętrzny adres bazy). Włącza codzienny import.
8. Uruchom ręcznie workflow „Codzienny import” i sprawdź, czy przetargi pojawiły się w aplikacji.

## Etap 1. Panel PerfectData i zapytania ze strony

Prace programistyczne, w tej kolejności:

1. **Wygląd PerfectData w platformie.** Plik motywu z kolorami ze strony, nagłówek zamiast bocznego menu, plik logo. Ustawienie organizacji decyduje o wyglądzie i o widocznych modułach.
2. **E-maile z platformy.** Powiadomienie o nowym zapytaniu i reset hasła. Wymaga usługi wysyłkowej ze zweryfikowaną domeną. Bez tego handlowiec nie dowie się o zapytaniu.
3. **Kwalifikator bez AI** (etap 1 z `PLAN-ZAPYTANIA.md`). Migracja 005, widżet, ustawienia pytań i punktów, lista zapytań z oceną, sprawdzenie NIP, zapis kontaktu z podstawą.
4. **Testy.** Ocena zgodna z tabelą zasad dla każdej kombinacji odpowiedzi, zabezpieczenia publicznych adresów widżetu, próba wstrzyknięcia HTML, izolacja danych między firmami.

Po zgodzie właściciela na kod na stronie: test na jednej stronie usługi. Połowa odwiedzających widzi dotychczasowy formularz, połowa kwalifikator. Zwycięzcę ogłaszamy dopiero przy pewności 95%.

Wynik etapu: zapytania z perfectdata.pl trafiają do panelu z oceną, sprawdzeniem firmy i podstawą kontaktu.

## Etap 2. Audyt AI

1. **Pomiar bez AI** (etap 1 z `PLAN-AUDYT-AI.md`). Migracja 004 i 20 z 24 wskaźników, czyli 82 ze 100 punktów.
2. **Ekrany:** raport z linijką 100 punktów, „Wytyczne wskaźników”, „Przed i po”, link do raportu dla klienta.
3. **Pierwsza sprawdzona strona: perfectdata.pl.** Firma, która sprzedaje GEO, powinna mieć u siebie komplet punktów za dostęp botów AI. Strona stoi za Cloudflare, a Cloudflare ma własne ustawienia blokowania botów AI.
4. **Połączenie z zapytaniami.** Adres podany w widżecie sprawdza się sam, a wynik widać przy zapytaniu. Handlowiec dzwoni z wynikiem i trzema najważniejszymi brakami.

Wynik etapu: narzędzie nadaje się do pracy z klientem SEO i GEO. Raport „Przed i po” jest dowodem wykonanej pracy.

## Etap 3. AI i wyniki

- **Czat AI w widżecie** z bazą wiedzy firmy i limitami wydatków (etap 2 zapytań). Przed włączeniem: zapis o asystencie AI w polityce prywatności perfectdata.pl.
- **Lejek i wynik testu** formularz kontra kwalifikator (etap 3 zapytań).
- **Render strony i ocena AI:** wskaźniki A6, E2, D1 i D2 (etap 2 audytu). Wymaga tokenu Cloudflare Browser Run.
- **Test cytowań i lista stron z mapy witryny** (etap 3 audytu).
- **Dane z kont klienta:** Search Console i Bing Webmaster Tools (etap 4 audytu).

## Etap 4. Pozostałe moduły i sprzedaż platformy

Przetargi, oferty, kontrahenci, kontakty i wiadomości działają w silniku od razu po uruchomieniu (etap 0). Ten etap dodaje brakujące funkcje tych modułów i przygotowuje sprzedaż platformy innym firmom.

### 4a. Braki przed pilotażem

1. **E-maile:** codzienny mail z nowymi przetargami i alerty o kontrahentach. Podstawa (usługa wysyłkowa, reset hasła) powstaje w etapie 1.
2. **Konto i zespół:** zaproszenia do zespołu e-mailem, potwierdzenie adresu e-mail przy rejestracji, usunięcie konta i eksport danych (RODO).
3. **AI:** limit wydatków na AI dla każdej firmy. Zużycie tokenów jest już zapisywane w dzienniku.
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

## Decyzje właściciela

Potrzebne teraz, bo blokują etap 1 i 2:

1. **Zgoda na kod widżetu na perfectdata.pl** i wybór strony usługi do pierwszego testu.
2. **Adres panelu:** subdomena perfectdata.pl czy adres z Render.
3. **Plik logo** do panelu i raportów dla klientów.
4. **Usługa wysyłkowa e-mail** i adres nadawcy.
5. **Pytania, punkty i progi kwalifikatora.** Do przejrzenia z osobą, która odbiera zapytania. Osobno: czy pytanie o budżet ma być obowiązkowe.
6. **Progi umowne w wytycznych audytu.** Jest ich 11 oraz progi wyniku 85 i 60 pkt. Zespół SEO powinien je przejrzeć przed wdrożeniem, bo późniejsza zmiana to nowa wersja wytycznych.
7. **Raport dla klienta:** z punktami czy tylko ze stanami „Dobrze”, „Do poprawy”, „Źle”.
8. **Czas odpowiedzi w podziękowaniu.** Widżet obiecuje kontakt w następnym dniu roboczym. Obietnica musi być realna.

Potrzebne przed etapem 4:

- **Nazwa i domena** platformy zamiast „[Nazwa]”.
- **Branża na start.** Dane demo są pod roboty drogowe i budowlane.
- **5-10 firm do pilotażu.**
- **Prawnik.** Potrzebny do:
  - regulaminu i polityki prywatności (po ich przygotowaniu wpisz adresy w `TERMS_URL` i `PRIVACY_URL`),
  - umowy powierzenia przetwarzania danych: klienci wpisują dane osób kontaktowych, więc platforma przetwarza je w ich imieniu, a podwykonawcami są Render, Anthropic i usługa e-mail,
  - opinii o module wiadomości (art. 398 Prawa komunikacji elektronicznej) i o module należności (art. 15 i 15a ustawy o udostępnianiu informacji gospodarczych).

## Co zostało sprawdzone (19.11.2026)

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
- czy perfectdata.pl wpuszcza boty AI (pokaże to pierwszy audyt albo ręczne sprawdzenie),
- kosztu jednej rozmowy w czacie AI (zależy od długości bazy wiedzy, do zmierzenia w etapie 3).
