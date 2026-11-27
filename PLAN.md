# Plan działania

Stan na 27.11.2026.

**Zmiana kierunku.** Pierwszym użytkownikiem narzędzia jest PerfectData (perfectdata.pl). Cel: więcej zapytań ze strony i audyt, którym agencja pracuje z klientami SEO i GEO. Platforma B2B (przetargi, oferty, kontrahenci) zostaje silnikiem pod spodem i osobnym produktem na później.

Na perfectdata.pl nic nie zostało zainstalowane. Kod trafi na stronę dopiero po wyraźnej zgodzie właściciela.

**Co się zmieniło 20.11.2026.** Etapy 1 i 2 oraz większość etapu 3 są zbudowane w silniku i przechodzą testy. Tego samego dnia doszło pięć funkcji z etapu 4: wyniki przetargów, praca zespołu nad ofertą, warunki naboru dotacji, przypomnienia z adresem wypisania i opis idealnego klienta. Potem doszły cztery funkcje, których dane nie są dostępne dla programów: panel pokazuje, gdzie je znaleźć, i daje miejsce na wpisanie (szczegóły niżej, w części „Dane wpisywane ręcznie”). 23.11.2026 doszła lista potencjalnych klientów: panel proponuje firmy, układa treść oferty i pokazuje drogi kontaktu z oceną ryzyka (część „Potencjalni klienci i wysyłka”). Tego samego dnia doszła zgoda na ofertę e-mailem zbierana listem: strona zgody z kodem QR i zapisanym dowodem. Potem siedem dodatków do listów i zgód: audyt strony jako zachęta, lejek listów, listy seryjnie, kod QR na wizytówkę, pytanie o zainteresowania, wyszukiwanie w CEIDG i drugi list (część „Listy i zgody: siedem dodatków”). 24.11.2026 doszła widoczność: dane z Search Console, sygnały z odpowiedzi AI i konkurencja z map witryn (część „Widoczność w Google, w AI i u konkurencji”). 25.11.2026 doszły trzy publiczne źródła popytu i kampanie e-mail: plany zamówień z BZP i zapowiedzi z TED, firmy z dotacjami UE oraz kampanie z wersją wiadomości dla każdego odbiorcy (części „Skąd brać popyt: źródła publiczne” i „Kampanie e-mail dopasowane do odbiorcy”). 27.11.2026 doszła biała lista VAT przed przelewem: sprawdzenie rachunku z faktury i statusu VAT na wybrany dzień z kluczem zapytania Ministerstwa Finansów (część „Biała lista VAT przed przelewem”). Do uruchomienia zostały kroki właściciela z etapu 0: hosting, baza, klucze usług i zgoda na kod widżetu. Lista na końcu, w części „Co jest potrzebne od właściciela”.

## Co jest gotowe

- **Silnik:** aplikacja PHP z bazą PostgreSQL w repozytorium `platforma-b2b`, 211 testów na SQLite i PostgreSQL. Gotowa do uruchomienia w Render.
- **Panel w wyglądzie PerfectData** (zmienna `APP_THEME=perfectdata` albo ustawienie firmy) z wyborem modułów w menu i pulpitem, który pokazuje tylko włączone moduły.
- **Zapytania ze strony:** widżet do wklejenia jedną linią kodu, kwalifikator z jawną tabelą punktów, lista i karta zapytań, czat AI z bazy wiedzy, lejek i test dwóch wersji.
- **Audyt AI:** 24 wskaźniki i 100 punktów z jednego pliku wytycznych, raport z linijką punktów i listą „Najpierw to”, „Przed i po”, strona wytycznych, raport dla klienta pod linkiem, audyt wielu stron z mapy witryny. Sprawdzony na prawdziwej stronie perfectdata.pl.
- **Poczta z panelu:** powiadomienie o zapytaniu, reset hasła, zaproszenia do zespołu i poranny przegląd (przetargi, zapytania, alerty).
- **Przetargi:** import i dopasowanie do profilu, karta przetargu, analiza SWZ oraz wyniki: kto wygrywa w branży i regionie, za ile i przy ilu ofertach. Alert, gdy zapisany przetarg zostanie rozstrzygnięty. Plany zamówień: pozycje z rocznych planów urzędów w BZP i zapowiedzi z TED, dopasowane do profilu, zanim przetarg zostanie ogłoszony.
- **Oferty i wnioski:** szkic z ogłoszenia i biblioteki, praca zespołu (sekcja przydzielona osobie, zapis nie nadpisuje cudzej zmiany), lista dokumentów do odhaczania, a we wniosku o dotację sprawdzenie warunków naboru z cytatami. Lista obserwowanych naborów z przewodnikiem po pięciu oficjalnych serwisach, przypomnieniami o końcu naboru i wnioskiem zakładanym z naboru.
- **Klienci i wiadomości:** ocena ryzyka prawnego przed wysyłką, przypomnienia po 4 i po 10 dniach z dziennym limitem, adres wypisania w stopce ofert, opis idealnego klienta ze strony firmy. Rejestr zapytań ofertowych z rynku: gdzie szukać, termin, kontakt z podstawą „zapytanie ofertowe” i odpowiedź jednym kliknięciem. Lista potencjalnych klientów: firmy z wyników przetargów i z listy użytkownika, dane z rejestrów, punkty dopasowania, treść oferty układana automatycznie i drogi kontaktu z oceną ryzyka. Zgoda na ofertę e-mailem zbierana listem: w liście jest adres strony zgody i kod QR, firma sama zostawia e-mail, a panel zapisuje dowód. Do tego audyt strony jako zachęta, lejek listów z kosztem jednej zgody, listy seryjnie, drugi list po 21 dniach, kod QR na wizytówkę, pytanie o zainteresowania i wyszukiwanie firm w CEIDG (po wpisaniu tokenu). Przycisk „Napisz” przy kontakcie sam dobiera rodzaj wiadomości do podstawy kontaktu. Firmy z dotacjami UE: spółki w trakcie projektu z funduszy europejskich, do dodania na listę potencjalnych klientów. Kampanie e-mail: jedna treść, ale wersja dla każdego odbiorcy z faktów o jego firmie. Panel wysyła sam tylko do zgód i klientów.
- **Kontrahenci:** karta firmy z rejestrów publicznych, sprawdzenie rachunku na białej liście VAT przed przelewem z kluczem zapytania MF, a do tego dwa sprawdzenia robione ręcznie: Krajowy Rejestr Zadłużonych i liczby ze sprawozdania finansowego, z których panel liczy, po ilu dniach firma płaci dostawcom.
- **Audyt techniczny:** bez zmian, działa od początku.
- **Platforma dla wielu firm:** pakiety Podstawowy i Rozszerzony z miesięcznym przydziałem AI, ekran administratora (firmy, zaproszenia, blokada, limity), logowanie dwuetapowe, eksport i usunięcie danych firmy, własne połączenia każdej firmy w Widoczności.
- **Nabory dotacji:** wyszukiwarka naborów (AI szuka w internecie i podaje źródła) oraz przycisk, który pisze cały wniosek.
- **Widoczność:** zakładka w module Audyt. Użytkownik wpisuje adres witryny i wgrywa paczkę z przycisku „Eksportuj” w Search Console albo dodaje jeden adres e-mail jako użytkownika i klika „Połącz”. Panel pokazuje, co poprawić najpierw w Google, gdzie stronę widać w odpowiedziach AI i co publikuje konkurencja.
- **Mockup całego panelu:** `mockup/audyt-ai`, 11 modułów w sześciu grupach menu i 41 ekranów, z listą 85 funkcji i ich stanem. Prywatny podgląd w Claude i paczka `audyt-ai-mockup.zip`.
- **Wytyczne audytu:** `resources/audyt-ai-rules.json` (czyta je silnik) i dokument dla zespołu `mockup/audyt-ai/WYTYCZNE.md`.
- **Plany modułów:** `PLAN-ZAPYTANIA.md` i `PLAN-AUDYT-AI.md`.

## Wygląd panelu

Panel i raporty wyglądają jak perfectdata.pl, żeby klient agencji widział jedną markę, a nie obce narzędzie.

- **Kolory ze strony** (odczytane ze stylów perfectdata.pl 19.11.2026): tło `#0a0e1a`, panel `#0f1524`, powierzchnia `#151c2e`, linie `#232d45`, złoto `#d4a94a` (jaśniejsze `#e8c97a`, ciemniejsze `#a87f2c`), tekst `#f2f3f7`, tekst pomocniczy `#9aa3b8`.
- **Czcionka systemowa**, ta sama co na stronie. Panel nie pobiera żadnych czcionek ani skryptów z zewnątrz.
- **Jeden ciemny motyw**, bo strona ma tylko ciemny.
- **Układ:** nagłówek jak na stronie (logo, menu, konto). Menu ma sześć pozycji w kolejności pracy: Pulpit, Sprzedaż, Marketing, Przetargi i dotacje, Kontrahenci, Konto. Pozycje aktywnej grupy są w drugim wierszu (np. Przetargi, Oferty, Dotacje, Biblioteka firmy), a zakładki ekranu pod tytułem strony. Bez bocznego menu platformy. Na telefonie pasek modułów przewija się w bok.
- **Złoto ma jedno znaczenie:** zdobyte punkty, główny przycisk i aktywna pozycja menu. Nie służy do ozdoby.
- **Linijka 100 punktów** w raporcie audytu. Każdy z 24 wskaźników to odcinek o szerokości równej jego wadze. Złote wypełnienie to zdobyte punkty, czerwona ramka to wskaźnik z zerem. Kliknięcie odcinka otwiera wskaźnik. Ta sama linijka pokazuje stan „przed” i „po”.
- **Stany rozróżnia kształt i słowo, nie sam kolor:** pełne koło „Dobrze”, pół koła „Do poprawy”, pierścień „Źle”.
- **Dane w wierszach na liniach**, bez rzędów jednakowych kart.
- **Przycisk główny i pola formularzy** jak na stronie: złoty gradient z ciemnym tekstem, pola na tle strony z cienką ramką.
- **Logo:** w mockupie jest przybliżenie tekstowe. Przy wdrożeniu zastąpi je plik logo ze strony.

W platformie wygląd i zestaw modułów będą ustawieniem organizacji (pole `settings_json` w tabeli `organizations`, bez nowej tabeli). Mockup pokazuje wszystkie moduły, bo tak ma wyglądać pełny panel. Moduł, którego firma nie używa, można wyłączyć w ustawieniach. Ten sam silnik obsłuży później inne firmy z ich wyglądem.

## Funkcje i ich stan

Mockup pokazuje 85 funkcji. Zakładka „Lista funkcji” na pulpicie podaje stan każdej z nich i prowadzi do ekranu.

| Moduł | Działa w silniku | Do zbudowania | Czeka na zgodę Google i Meta |
|---|---|---|---|
| Zapytania | 5 | 0 | 0 |
| Klienci | 22 | 1 | 0 |
| Audyt AI | 6 | 1 | 0 |
| SEO i strona | 8 | 1 | 0 |
| Reklamy | 0 | 0 | 6 |
| Przetargi | 11 | 0 | 0 |
| Oferty | 6 | 0 | 0 |
| Dotacje | 5 | 0 | 0 |
| Kontrahenci | 8 | 1 | 0 |
| Konto i platforma | 4 | 0 | 0 |
| Razem | 75 | 4 | 6 |

„Działa w silniku” znaczy, że kod jest w repozytorium i przechodzi testy. Silnik nie jest jeszcze uruchomiony online (etap 0).

Dlaczego 4 funkcje nie są zbudowane: każda wymaga zgody albo konta u zewnętrznej firmy.

- wizytówka Google (zgoda Google na dostęp aplikacji do wizytówek),
- automatyczne wykrywanie odpowiedzi (połączenie ze skrzynką Gmail lub Microsoft 365). Wypisanie działa już przez adres w stopce, a odpowiedź zaznacza się ręcznie i można wkleić jej treść,
- wpis długu do BIG (umowa z biurem),
- test cytowań w AI (klucze OpenAI, Perplexity i Gemini oraz decyzja o koszcie).

Wyszukiwanie firm w CEIDG jest zbudowane i czeka tylko na bezpłatny token (zmienna `CEIDG_TOKEN`). Obejmuje jednoosobowe działalności. Spółek po branży szukać się tak nie da: KRS nie udostępnia takiego wyszukiwania, a REGON pyta tylko po numerze.

### Dane wpisywane ręcznie

Cztery funkcje opierają się na źródłach, które nie udostępniają danych programom. Panel nie pobiera z nich niczego. Pokazuje, gdzie dane są, co z nich przepisać, i daje formularz. Od wpisania działa już sam: liczy, pilnuje terminów i przypomina.

| Funkcja | Gdzie są dane | Gdzie się je wpisuje | Co robi panel |
|---|---|---|---|
| Upadłości i restrukturyzacje | krz.ms.gov.pl (bezpłatnie, bez konta) | Karta firmy, sekcja „Zadłużenie i płatności” | Zapisuje wynik z datą i osobą, pokazuje znacznik na karcie, co 90 dni przypomina o powtórce dla obserwowanych firm |
| Czas płacenia dostawcom | ekrs.ms.gov.pl, Przeglądarka dokumentów finansowych | Ta sama sekcja karty firmy | Z dwóch liczb sprawozdania liczy dni (zobowiązania wobec dostawców / zakupy × 365), porównuje lata, dopisuje uwagę o stracie i ujemnym kapitale |
| Zapytania ofertowe firm | Baza Konkurencyjności, platformazakupowa.pl, strony firm | Klienci → Zapytania z rynku | Pilnuje terminu, zakłada kontakt z podstawą „zapytanie ofertowe”, przygotowuje odpowiedź, zatrzymuje przypomnienia po terminie |
| Nabory dotacji | funduszeunijne.gov.pl (wyszukiwarka i ogłoszenia), funduszeeuropejskie.gov.pl (przegląd i harmonogramy), parp.gov.pl | Oferty → Nabory dotacji | Przypomina 14, 7 i 3 dni przed końcem naboru, zakłada z naboru szkic wniosku |

Wpisy widzi tylko firma, która je zrobiła. Sprawdzenia w KRZ i liczby ze sprawozdań nie zmieniają oceny ryzyka liczonej z danych publicznych.

Czego ta wersja nie robi: nie pobiera danych z tych źródeł i nie podpowie zapytania ofertowego, którego nie wpiszesz. Nabory od 24.11.2026 podpowiada wyszukiwarka naborów (część „Nabory dotacji: wyszukiwarka i pisanie wniosku”), a ręczne wpisanie zostaje dla naborów, których nie znalazła. Gdyby któreś źródło udostępniło dane programom albo zgodziło się na pobieranie, import można dopisać do tych samych tabel.

### Potencjalni klienci i wysyłka

Zapytania ze strony to firmy, które piszą do nas. Lista potencjalnych klientów działa w drugą stronę: panel proponuje firmy, do których warto się odezwać.

Jak to działa:

1. **Skąd są firmy.** Wykonawcy z wyników przetargów (kto wygrał, co i za ile), lista NIP-ów albo stron wklejona przez użytkownika, jednoosobowe działalności z CEIDG (po kodzie PKD, regionie i dacie założenia) oraz osoby, które same zgłosiły się przez kod QR firmy. Adresów e-mail panel nie przyjmuje i nie zbiera ze stron ani z CEIDG.
2. **Co panel dopisuje.** Nazwę, adres, formę prawną, staż i ryzyko płatnicze z wykazu VAT i KRS. Liczy dopasowanie z jawnej tabeli: branża 40, region 15, staż 10, kondycja 15, powód kontaktu 20.
3. **Powód kontaktu i treść oferty.** Panel układa je sam z faktów: wygrane zamówienie albo rzeczy do poprawy na stronie firmy, plus opis z biblioteki. Treść można poprawić albo zlecić AI. Ta sama trafia do listu i do wiadomości.
4. **Wysyłka.** Prowadzenie listy i napisanie oferty nie wymagają zgody firmy. Zgody wymaga wysłanie oferty e-mailem albo telefon z ofertą, także do firm. Dlatego karta firmy pokazuje wszystkie drogi kontaktu, a wysyła użytkownik.

| Droga kontaktu | Ryzyko | Dlaczego |
|---|---|---|
| Odpowiedź na zapytanie ofertowe firmy | niskie | Firma sama prosi o oferty |
| Oferta do firmy, która wyraziła zgodę albo jest klientem | niskie | Jest zgoda. Zgodę ze strony zgody panel pokazuje razem z dowodem: treść, czas, źródło |
| Oferta na adres, który firma podała do przesyłania ofert | niskie | Udostępnienie adresu w tym celu jest formą zgody (art. 398 ust. 2 PKE). Panel szuka takiego zdania na stronie firmy i pokazuje cytat z linkiem. Podstawą staje się dopiero po potwierdzeniu przez użytkownika |
| List pocztą z prośbą o zgodę na e-mail | niskie | Art. 398 PKE dotyczy telefonu i środków elektronicznych. Panel przygotowuje list .docx z adresem strony zgody i kodem QR. Dla osób prowadzących działalność dodaje informację z art. 14 RODO i przypomina o terminie miesiąca |
| E-mail bez zgody | bardzo wysokie | Art. 398 ust. 1 PKE. Panel przygotuje wiadomość, pokaże ekran ryzyka i wymaga potwierdzenia. Decyzję podejmuje użytkownik |
| Pytanie e-mailem, czy i gdzie firma przyjmuje oferty | bardzo wysokie | Ministerstwo Cyfryzacji (odpowiedź z 13.10.2025 na interpelację nr 12337) uznaje kontakt w celu uzyskania zgody za niedozwolony. Sąd Apelacyjny w Warszawie (VII AGa 1567/18) uznał SMS z prośbą o zgodę za informację handlową. W drugą stronę poszedł Sąd Okręgowy Warszawa-Praga (IV Ca 1873/16): dopuścił pierwszy telefon z samym pytaniem. Oba wyroki dotyczą poprzednich przepisów. Szkic w panelu to samo pytanie, bez nazwy usługi. Ryzyko dotyczy tej jednej wiadomości: gdy firma odpowie i poda adres, zgodę zapisuje się z opisem, a kolejne oferty mają podstawę |

Sprzeciw firmy blokuje listy i oferty. Odpowiedź na jej własne zapytanie ofertowe zostaje możliwa.

#### Jak zdobyć zgodę na e-mail zgodnie z prawem

Pytanie o zgodę wysłane e-mailem, SMS-em albo zadane przez telefon samo wymaga zgody. Zgodę musi więc zebrać kanał, który jej nie wymaga. Panel robi to tak:

1. **List pocztą.** Panel przygotowuje list z ofertą. Jest w nim zdanie o zgodzie, adres strony zgody tej firmy (np. `panel.pl/z/tb6y4s29mb`) i kod QR do zeskanowania telefonem.
2. **Strona zgody.** Firma wchodzi na nią sama. Widzi, kto pyta, wpisuje adres e-mail i zaznacza zgodę o dokładnej treści. Może dodać telefon z osobną zgodą albo kliknąć „Nie chcemy korespondencji”. Strona działa bez logowania i nie zapisuje plików cookie.
3. **Dowód.** Panel zapisuje treść zgody, czas, źródło i skrót adresu IP. Kontakt dostaje podstawę „zgoda”. Zespół dostaje powiadomienie, a firma potwierdzenie z linkiem wycofania (bez oferty).
4. **Oferta e-mailem.** Od tej chwili wiadomość ma niskie ryzyko. Karta firmy pokazuje dowód obok przycisku „Przygotuj ofertę”, a lista przypomina o firmach, które czekają na wiadomość.

Zgodę wyrażoną inaczej (odpowiedź na list, rozmowa na targach, prośba firmy o ofertę) użytkownik zapisuje ręcznie: adres, sposób, data i opis, kto co powiedział. To on musi umieć ją wykazać.

Zasady, których panel pilnuje:

- Adres strony zgody trafia tylko do listu. Karta ostrzega, że wysłany e-mailem albo SMS-em byłby prośbą o zgodę bez zgody.
- Zgoda dotyczy oferty tej jednej firmy. Wycofuje ją link w każdej wiadomości, przycisk na stronie zgody albo użytkownik w panelu. Dowód zostaje w bazie z datą wycofania.
- Odmowa na stronie zgody jest sprzeciwem. Sprzeciwu nie nadpisze ręczny wpis użytkownika. Zdanie może zmienić tylko sama firma, na swojej stronie zgody.
- Adres, który się wypisał, nie wróci przez formularz.
- Potwierdzenie nie jest podwójne (bez klikania w link z e-maila), bo adres strony zna tylko adresat listu, a potwierdzenie z linkiem wycofania wychodzi od razu.

Inne legalne drogi do e-maila, które panel już obsługuje: odpowiedź na zapytanie ofertowe firmy, adres podany przez firmę do przesyłania ofert, zgoda zaznaczona w rozmowie w widżecie na stronie i kod QR na wizytówce albo stoisku.

#### Listy i zgody: siedem dodatków

1. **Audyt strony jako zachęta.** Na karcie firmy jeden przycisk uruchamia audyt techniczny jej strony. Wynik trafia do treści oferty i do punktów dopasowania, a list obiecuje pełny raport. Na stronie zgody prośba o raport i zgoda na ofertę to dwa osobne pola: raport nie zależy od zgody na ofertę. Firma, która poprosi tylko o raport, dostaje go od razu i e-mailem, ale kontakt nie zyskuje podstawy do ofert. Raport ma publiczny adres z losowym tokenem i prowadzi z powrotem na stronę zgody. W liście nie ma ustaleń o bezpieczeństwie, te są dopiero w raporcie.
2. **Lejek listów.** Panel zapisuje zdarzenia: list wysłany, wejście na stronę zgody, prośba o raport, otwarcie raportu, zgoda, odmowa, oferta, odpowiedź. Na liście pokazuje, ile firm z listem przeszło każdy krok, w wybranym okresie. Po wpisaniu kosztu listu liczy koszt jednej zgody. Wejścia liczy serwer, bez plików cookie, raz na godzinę z jednego adresu. Podgląd z panelu się nie liczy. Karta firmy ma historię.
3. **Listy seryjnie.** Ekran z firmami gotowymi do listu (adres z rejestrów, bez zgody na e-mail). Jeden plik .docx: każdy list od nowej strony, z adresem strony zgody tej firmy i jej kodem QR. Do tego adresy w CSV dla arkusza i zbiorcze „Oznacz: listy wysłane”. Najwyżej 50 listów w pliku.
4. **Kod QR na wizytówkę i stoisko.** Ogólna strona zgody firmy pod tym samym adresem `/z/kod`. Osoba sama wpisuje nazwę firmy, e-mail i zaznacza zgodę. Panel dopisuje firmę do listy (albo dołącza zgodę do firmy z tym samym NIP-em) i zakłada kontakt z podstawą „zgoda”. Zgłoszenie z otwartej strony nie cofa sprzeciwu zapisanego dla firmy. Dzienny limit 200 zgłoszeń, kod można wymienić.
5. **Pytanie, co firmę interesuje.** Do sześciu tematów ustawianych w panelu i krótka wiadomość, na obu stronach zgody. Odpowiedź jest zapisana przy dowodzie zgody, widoczna na karcie, a szkic oferty do niej nawiązuje.
6. **Wyszukiwanie w CEIDG.** Aktywne jednoosobowe działalności po kodach PKD, województwie, mieście i dacie założenia. Panel bierze nazwę, NIP, adres i datę rozpoczęcia. To osoby prowadzące działalność: list ma informację o danych osobowych ze wskazaniem CEIDG jako źródła, a karta liczy 30 dni na jej przekazanie. Nowa firma (do 90 dni) dostaje punkty za powód kontaktu i własne otwarcie treści oferty. Włącza się tokenem `CEIDG_TOKEN`, bez niego panel pokazuje, jak go zdobyć.
7. **Drugi list.** Po 21 dniach bez zgody, odmowy, prośby o raport i odpowiedzi panel proponuje krótkie przypomnienie: na liście, karcie, w listach seryjnych i w porannym przeglądzie. Drugi list zapowiada, że jest ostatni. Po dwóch listach przypomnienia się kończą, a ekran listu ostrzega przed trzecim.

Do tego poprawka z uwagi właściciela: przycisk „Napisz” przy kontakcie sam dobiera rodzaj wiadomości. Zapytanie ofertowe daje odpowiedź na zapytanie, zgoda i klient ofertę za zgodą, a brak podstawy ofertę bez zgody z ekranem ryzyka. Prośba o zgodę nie jest domyślna, bo sama wymaga zgody.

Źródła: [art. 398 Prawa komunikacji elektronicznej](https://lexlege.pl/komunik-elektr/art-398/), [odpowiedź Ministerstwa Cyfryzacji na interpelację nr 12337](https://api.sejm.gov.pl/sejm/term10/interpellations/attachment/ATTDMGHNP/i12337-o1.pdf), [Grant Thornton o marketingu pocztą tradycyjną](https://grantthornton.pl/publikacja/marketing-rodo-i-wysylka-kartek-swiatecznych/). To opis przepisów, a nie porada prawna. Opinia prawnika o module wiadomości jest na liście rzeczy potrzebnych przed sprzedażą platformy.

### Platforma dla wielu firm: pakiety, administracja, zabezpieczenia

Panel jest przygotowany do tego, żeby korzystały z niego inne firmy, a każda widziała tylko swoje dane.

**Pakiety.** Dwa pakiety dla firm klientów i trzeci, wewnętrzny, dla firmy, która prowadzi panel (jej adresy są w `ADMIN_EMAILS`, nie ma limitów).

| Limit miesięczny | Podstawowy | Rozszerzony |
|---|---|---|
| Użycia AI w panelu (analiza, szkice, wyszukiwanie naborów, sekcje wniosku) | 30 | 400 |
| Odpowiedzi czatu AI na stronie firmy | 100 | 1500 |
| Osoby w zespole | 3 | 15 |
| Witryny w Widoczności | 2 | 20 |
| Cotygodniowe wyszukiwanie naborów bez klikania | nie | tak |

- Liczby zmienia administrator na ekranie **Administracja → Limity pakietów**, bez wdrożenia.
- Po wyczerpaniu przydziału panel nie wysyła zapytania do modelu, mówi to wprost i podaje dzień odnowienia. Wszystko, co działa bez AI (analiza regułowa, szablony, audyt bez oceny AI), działa dalej.
- Firma widzi licznik w **Ustawienia → Pakiet**. Zmienna `AI_MODEL_BASIC` pozwala dać pakietowi Podstawowemu tańszy model.
- Płatności panel nie obsługuje: pakiet zmienia administrator po opłaceniu faktury.

**Administracja (tylko adresy z `ADMIN_EMAILS`).** Lista firm z liczbą osób i zużyciem AI, zaproszenie firmy (jednorazowy link na 14 dni, przypisany do adresu e-mail i pakietu), zmiana pakietu, blokada firmy (kończy sesje, wyłącza widżet, dane zostają) i wyłączenie logowania dwuetapowego osobie, która straciła telefon. Administrator widzi liczby, nie dane firm.

**Jak wpuścić nową firmę:** Administracja → wpisz adres e-mail osoby z firmy i wybierz pakiet → wyślij jej link. Rejestracja otwarta dla wszystkich (`SIGNUP_OPEN=1`) nie jest do tego potrzebna.

**Zabezpieczenia.**

- Logowanie dwuetapowe kodem z aplikacji (TOTP, kody zapasowe, kod działa raz, sześć błędów kończy próbę). Klucz leży w bazie zaszyfrowany.
- Zmiana hasła, nowe hasło z linku i przycisk „Wyloguj wszędzie” unieważniają pozostałe sesje.
- Sekrety firm (dostęp do konta Google, token Cloudflare, klucz logowania dwuetapowego) są szyfrowane kluczem z `APP_SECRET`. Sama kopia bazy nie wystarcza, żeby je odczytać.
- Izolacja firm ma test na całym panelu: przechodzi po danych przykładowych jako jedna firma, a potem otwiera każdy adres z identyfikatorem jako druga. Żaden nie może się otworzyć.
- Eksport danych firmy (ZIP, jeden plik na tabelę, bez haseł i sekretów) i usunięcie konta firmy ze wszystkimi danymi (hasło i przepisanie nazwy firmy).
- Adres odwiedzającego do limitów prób bierzemy z nagłówka, który ustawia hosting. Na hostingu innym niż Render wskazuje go `CLIENT_IP_HEADER`.

**Widoczność: połączenia każdej firmy.** Klucz konta usługi Google i token Cloudflare z serwera działają tylko w firmie, która prowadzi panel, bo widzą jej dane. Pozostałe firmy:

- łączą własne konto Google przyciskiem „Połącz z Google” (OAuth, tylko odczyt Search Console i Analytics). Wymaga to jednorazowo klienta OAuth w Google Cloud (`GOOGLE_OAUTH_CLIENT_ID`, `GOOGLE_OAUTH_CLIENT_SECRET`, adres powrotu `<adres panelu>/audyt/widocznosc/google/powrot`),
- wklejają własny token Cloudflare z prawem odczytu statystyk,
- albo wgrywają plik z eksportu, jak dotąd.

Ograniczenie po stronie Google: dopóki aplikacja OAuth nie przejdzie weryfikacji Google, ekran zgody pokazuje ostrzeżenie, limit to 100 kont, a w trybie testowym dostęp wygasa po 7 dniach. Weryfikację zgłasza właściciel projektu w Google Cloud.

### Nabory dotacji: wyszukiwarka i pisanie wniosku

**Wyszukiwarka (Oferty → Nabory dotacji).** Użytkownik opisuje jednym zdaniem, na co szuka pieniędzy, wybiera województwo i wielkość firmy. AI przeszukuje internet (narzędzie web search u dostawcy modelu) i zwraca nabory, które trwają albo są zapowiedziane. Zasady:

- na listę trafia tylko nabór, którego adres był w wynikach wyszukiwarki. Linku wymyślonego przez model panel nie pokaże,
- data, której nie było na stronie, zostaje pusta, a nabory zakończone odpadają,
- każda pozycja ma link do źródła, oznaczenie „strona urzędowa” albo „inne źródło, sprawdź u instytucji” i przypomnienie, żeby termin i regulamin potwierdzić u instytucji,
- „Obserwuj” przenosi nabór na listę z przypomnieniami o terminie, „Pisz wniosek” zakłada szkic wniosku,
- jedno wyszukiwanie to jedno użycie AI z pakietu i najwyżej 6 zapytań do wyszukiwarki (u dostawcy płatne osobno, 10 USD za 1000 zapytań),
- w pakiecie Rozszerzonym panel powtarza ostatnie wyszukiwanie co tydzień.

**Dlaczego nie pobieramy oficjalnych list.** Sprawdzone 24.11.2026 w przeglądarce: lista naborów na funduszeunijne.gov.pl wymaga tokenu reCAPTCHA przy każdym pobraniu, a strona PARP stoi za ochroną przed automatami (Incapsula). Nowy portal funduszeeuropejskie.gov.pl nie udostępnia naborów przez interfejs WordPressa. Panel takich zabezpieczeń nie obchodzi, więc lista naborów nie będzie kompletna: wyszukiwarka znajduje to, co te serwisy udostępniają wyszukiwarkom.

**Napisz cały wniosek.** Na ekranie wniosku (i oferty przetargowej) jeden przycisk uruchamia pisanie wszystkich sekcji, których nikt nie oznaczył jako gotowe. AI pisze sekcję po sekcji, strona pokazuje postęp i sama prowadzi kolejne kroki, a „Zatrzymaj” przerywa. Każda sekcja to jedno użycie AI. Zasady pisania zostają: tylko z regulaminu naboru i biblioteki firmy, zdania bez źródła są podświetlone. Regulamin i kryteria użytkownik wgrywa sam, bo panel nie pobiera plików z zabezpieczonych stron.

**Przetargi: do ofert jednym kliknięciem.** Na listach przetargów przycisk „Do ofert” zakłada szkic oferty i zapisuje przetarg. Drugie kliknięcie nie tworzy duplikatu, tylko pokazuje znacznik „w ofertach”. „Zapisz” na karcie przetargu robi to samo.

### Biała lista VAT przed przelewem

Karta kontrahenta → „Biała lista VAT: rachunek przed przelewem” (27.11.2026). Biała lista była w ocenie ryzyka od początku (status VAT, liczba rachunków, alert o zmianie rachunków u obserwowanych firm). Doszło to, czego potrzebuje osoba, która płaci fakturę:

- **Sprawdzenie rachunku z faktury** na dzień zlecenia przelewu (metoda „check” API wykazu): odpowiedź TAK albo NIE. Numer z błędną sumą kontrolną panel odrzuca sam, zanim zapyta Ministerstwo Finansów, bo takie zapytanie też zużywa limit.
- **Status VAT na wybrany dzień**, np. dzień wystawienia faktury: czynny, zwolniony, brak w wykazie, z datami wykreślenia i przywrócenia.
- **Klucz zapytania MF przy każdym sprawdzeniu.** Ministerstwo opisuje go jako elektroniczny klucz, który potwierdza wykonanie zapytania. Panel zapisuje go z dniem, rachunkiem, kwotą i osobą, która sprawdzała. Historię widzi tylko firma, która sprawdzała. Przycisk „Pobierz potwierdzenia (CSV)” daje plik dla księgowości.
- **Wynik po ludzku z kwotą.** Przy NIE i płatności powyżej 15 000 zł panel pisze, czym to grozi (wyłączenie z kosztów, solidarna odpowiedzialność za VAT) i co zrobić (poprosić o rachunek z wykazu, a po przelewie złożyć ZAW-NR w ciągu 7 dni od zlecenia przelewu). Do 15 000 zł sankcje z białej listy nie działają.
- **Nowy sygnał w ocenie ryzyka:** przywrócenie do rejestru VAT w ostatnich 12 miesiącach (+8 punktów).
- **Limit API:** 100 zapytań dziennie z jednego adresu, wspólnie dla całego panelu. Każda firma ma 20 sprawdzeń dziennie, a ostatnie 10 zapytań zostaje dla nocnego odświeżania obserwowanych firm. Po odpowiedzi „za dużo zapytań” panel nie pyta Ministerstwa do północy. Licznik na karcie pokazuje, ile sprawdzeń zostało, i odsyła do wyszukiwarki na podatki.gov.pl, która limitu nie ma.

Źródła: [jak korzystać z wykazu (KAS)](https://www.gov.pl/web/kas/jak-korzystac-z-wykazu), [dokumentacja API wykazu](https://www.gov.pl/attachment/10b6eb11-ee07-4e6d-b28e-262ed624309c). To opis przepisów, nie porada podatkowa.

### Klienci: co napisał klient

Na liście klientów i na karcie klienta jest rozwijane „Co napisał klient”. Pokazuje własne słowa klienta z trzech miejsc: zapytanie ze strony (wiadomość, odpowiedzi na pytania, pytania do czatu), zapytania ofertowe i odpowiedzi e-mail wklejone przez użytkownika przy „Firma odpisała”.

Panel nie czyta skrzynki pocztowej: wiadomości wychodzą z programu pocztowego użytkownika, więc odpowiedzi trafiają tylko do niego. Automatyczne wciąganie odpowiedzi wymaga podłączenia skrzynki. Dwie drogi, obie do decyzji właściciela:

1. dostęp do Gmaila albo Microsoft 365 (Google wymaga dla odczytu poczty płatnej oceny bezpieczeństwa aplikacji),
2. adres w panelu, na który użytkownik przekazuje odpowiedzi (wymaga odbierania poczty w domenie panelu, np. Resend Inbound, i rekordów MX).

### Skąd brać popyt: źródła publiczne

Popyt na usługi firm, które korzystają z panelu, widać w danych publicznych, zanim klient sam się odezwie. Zasada dla wszystkich źródeł: panel bierze tylko to, co instytucja udostępnia programom albo do pobrania, nie obchodzi zabezpieczeń i nie zbiera adresów e-mail z rejestrów ani stron.

| Źródło | Co mówi o popycie | Stan |
|---|---|---|
| Plany postępowań w BZP | Zamawiający publiczni publikują plan zamówień na rok w ciągu 30 dni od przyjęcia budżetu (art. 23 Pzp): przedmiot, tryb, orientacyjna wartość i kwartał wszczęcia. W 2026 r. do maja było ok. 4000 ogłoszeń planów, najwięcej w styczniu i lutym. | Zbudowane 25.11.2026 (Przetargi → Plany zamówień) |
| Wstępne ogłoszenia informacyjne w TED | Zapowiedzi zamówień od progów unijnych, często z kodami CPV i datą planowanego ogłoszenia. | Zbudowane 25.11.2026, w tym samym ekranie |
| Lista projektów z funduszy UE 2021-2027 | Kto dostał dofinansowanie, na co, za ile i do kiedy trwa projekt. Firma w trakcie projektu ma budżet, a większe zakupy ogłasza zwykle w Bazie Konkurencyjności. Plik CSV ok. 138 MB, co miesiąc, bez NIP-u. | Zbudowane 25.11.2026 (Klienci → Firmy z dotacjami UE): tylko spółki z trwającymi projektami |
| Baza Konkurencyjności | Zapytania ofertowe firm z dotacjami, często małe i dobre dla MŚP. | Ręcznie: rejestr zapytań z rynku (20.11.2026). Automat wymaga zgody operatora Bazy |
| SUDOP (UOKiK) | Pomoc publiczna i de minimis dla firm, z NIP-em beneficjenta. | Do sprawdzenia: czy dane da się pobrać bez obchodzenia zabezpieczeń |
| Rejestr wniosków, decyzji i zgłoszeń budowlanych (GUNB) | Kto i gdzie buduje: sygnał dla firm budowlanych, instalacyjnych i wykończeniowych. | Do sprawdzenia: zakres danych i sposób pobierania |
| Centralna Baza Ofert Pracy | Firma, która zatrudnia, rośnie i kupuje. Oferty z urzędów pracy są publiczne. | Do zrobienia. Ofert z prywatnych portali nie pobieramy |
| CEIDG i KRS | Nowe firmy i zmiany w firmach (zarząd, kapitał, adres). | CEIDG: zbudowane, działa po wpisaniu tokenu. KRS: alerty o zmianach u obserwowanych firm. Wyszukiwania spółek po branży KRS nie udostępnia |
| Nowe obowiązki prawne | Powód kontaktu, gdy przepisy zmuszają firmy do zmian, np. wymagania dostępności części e-sklepów i usług od 28.06.2025, KSeF, nowelizacja ustawy o krajowym systemie cyberbezpieczeństwa. Pomysł: test dostępności strony w audycie jako zachęta w liście. | Pomysł. Treść o przepisach do sprawdzenia przez prawnika |
| Google Trends i Bank Danych Lokalnych GUS | O co ludzie pytają w regionie i jak zmienia się lokalny rynek: do planowania treści i kampanii. | Pomysł |

**Plany zamówień, jak działają.** Codzienny import pobiera listę planów z BZP (`NoticeType=TenderPlanNotice`) i treść każdego planu (`GetNoticeQuery`, bo `GetNoticeHtmlBodyById` zwraca dla planów pustą treść). Z jednego okresu zostaje najnowsza wersja planu. Nowa wersja aktualizuje pozycje po numerze, a pozycje, z których urząd zrezygnował, znikają z listy, ale zapisane dopasowania zostają w historii. Plan nie ma kodów CPV, więc dopasowanie idzie po słowach kluczowych z profilu z prostą odmianą („odpady” znajdzie „odpadów”, „hala” znajdzie „hali”). Punkty: województwo 10, słowa 30 i po 10 za kolejne (do 50), wartość w zakresie +10, poza zakresem -25, wszczęcie w tym kwartale +10, później +15. Minięty termin nie odejmuje punktów, tylko ostrzega, że przetarg mógł już zostać ogłoszony. Pierwszy import dociąga plany od 1 stycznia w porcjach po 800 planów dziennie, a `php bin/console plans` robi to naraz. Zapowiedzi z TED to typy `pin-only`, `pin-buyer` i `pin-rtl`.

**Firmy z dotacjami UE, jak działają.** Zadanie dzienne z GitHub Actions sprawdza stronę z listą projektów i pobiera plik tylko wtedy, gdy pojawił się nowy miesiąc. Plik czytamy strumieniowo. Zostają spółki (sp. z o.o., S.A., spółki komandytowe, jawne, partnerskie, proste spółki akcyjne, spółdzielnie) z projektem w toku albo zakończonym w ostatnich 6 miesiącach. Osób fizycznych i spółek cywilnych nie zapisujemy, bo ich nazwa to imiona i nazwiska. Firma dodana na listę potencjalnych klientów dostaje w punktacji powód kontaktu „projekt z dotacją UE w toku”, a w treści oferty zdanie o projekcie. NIP i stronę użytkownik dopisuje na karcie firmy.

### Kampanie e-mail dopasowane do odbiorcy

Klienci → Kampanie e-mail. Jedna treść do wielu firm, ale każda firma dostaje własną wersję.

- **Szablon z polami:** `{powitanie}`, `{firma}`, `{miasto}`, `{powod}`, `{podpis}`. Zdanie `{powod}` panel składa z faktów, które już zna, w tej kolejności: zapytanie ze strony, odpowiedź klienta, zapytanie ofertowe, tematy zaznaczone na stronie zgody, wygrany przetarg, projekt z dotacją UE, audyt strony, współpraca. Gdy nie zna żadnego faktu, linia znika.
- **Wersja przez AI (opcja):** AI przepisuje wersję pod fakty firmy, bez zmiany oferty, cen i linków. Każdy odbiorca to jedno użycie AI z pakietu. Wersji poprawionych ręcznie AI nie rusza.
- **Ryzyko dla każdego odbiorcy** z tych samych reguł co pojedyncza wiadomość. Panel wysyła sam tylko do odbiorców z ryzykiem niskim (zgoda, klient, adres podany do ofert). Pozostałe wersje użytkownik może pobrać jako pliki .eml i wysłać ze swojej skrzynki, jeśli zapozna się z ryzykiem i to potwierdzi. Potwierdzenie trafia do historii kampanii i kontaktu. Wypisani nie dostają nic.
- **Dlaczego panel nie wysyła ryzykownych wiadomości sam:** wtedy platforma byłaby narzędziem wysyłki informacji handlowej bez zgody, a jej domena traciłaby reputację u skrzynek pocztowych. Decyzja o takiej wiadomości zostaje u klienta, a wysyłka z jego skrzynki.
- **Wysyłka przez panel:** nadawca to nazwa firmy użytkownika z adresu panelu (`MAIL_FROM` albo osobny `CAMPAIGN_FROM`), odpowiedzi trafiają na skrzynkę użytkownika. Każda wiadomość ma stopkę z informacją, skąd mamy adres, link do wypisania i nagłówek `List-Unsubscribe` z wypisaniem jednym kliknięciem. Przed każdą wiadomością panel sprawdza jeszcze raz, czy odbiorca się nie wypisał i czy zgoda nadal jest zapisana.
- **Limity:** przerwa 14 dni między kampaniami do tej samej firmy, dzienny limit z pakietu (startowo 100 w Podstawowym i 1000 w Rozszerzonym), wysyłka partiami po 20.
- **Bez pikseli śledzących.** Wynik kampanii to wysłane, odpowiedzi i wypisania.
- **Skąd adresy:** tylko z kontaktów firmy, czyli od firm, które same podały adres (zapytanie, zgoda, współpraca). Panel nie buduje bazy adresów z rejestrów ani stron.

### Widoczność w Google, w AI i u konkurencji

Zakładka „Widoczność” w module Audyt (24.11.2026). Odpowiada na trzy pytania: co poprawić najpierw w Google, czy stronę widać w odpowiedziach AI i co robi konkurencja.

**Start dla użytkownika.** Trzy kroki, bez przepisywania identyfikatorów:

1. Wpisuje adres witryny.
2. Podłącza dane z Google jedną z dwóch dróg:
   - **plik:** w Search Console klika „Eksportuj” i „Pobierz plik CSV”, a pobraną paczkę wgrywa bez rozpakowywania. Panel sam rozpoznaje raport (po nagłówkach, w języku polskim i angielskim) i okres (z tabeli dat). Można wgrać kilka plików naraz,
   - **stałe połączenie:** kopiuje adres e-mail konta usługi, dodaje go w Search Console jako użytkownika z uprawnieniami „Ograniczone” i klika „Połącz”. Panel sam znajduje usługę po domenie i od tej pory pobiera dane codziennie. Ten sam adres dodany w Google Analytics (rola „Wyświetlający”) daje wejścia z asystentów AI.
3. Opcjonalnie wpisuje do 5 domen konkurentów.

Usługę Search Console, usługę GA4 i strefę Cloudflare panel wykrywa po domenie. Pola ręczne są schowane w ustawieniach zaawansowanych.

**Skąd są dane.**

| Źródło | Co daje | Jak trafia do panelu | Co jest potrzebne |
|---|---|---|---|
| Search Console, raport „Skuteczność” | zapytania, strony, kliknięcia, wyświetlenia, pozycje | plik z eksportu albo Search Console API | dostęp do Search Console. Do API: klucz `GOOGLE_SERVICE_ACCOUNT` |
| Search Console, raport o generatywnej AI (od czerwca 2026) | wyświetlenia linków do witryny w Przeglądach od AI i Trybie AI, według stron i dni | tylko plik z eksportu | nic. Raport nie ma API i nie podaje kliknięć ani zapytań |
| Bing Webmaster Tools, raport „AI Performance” (od lutego 2026) | cytowania w Copilocie i podsumowaniach Binga, frazy, którymi AI szukało treści | tabela wklejona z raportu | bezpłatne konto Bing Webmaster Tools. Raport nie ma API |
| Google Analytics 4 | sesje z ChatGPT, Perplexity, Copilota, Gemini, Claude i innych asystentów, strony wejścia | Analytics Data API albo wklejona tabela źródeł | do API: ten sam klucz Google |
| Cloudflare | które boty AI pobierają strony, ile razy, ile dostało odmowę | GraphQL Analytics API | token `CF_ANALYTICS_TOKEN`, tylko dla witryn za Cloudflare |
| Mapy witryn konkurentów (sitemap.xml) | ile stron mają, co dodali i zmienili w 30 dni, jakie tematy opisują | panel pobiera publiczne pliki raz dziennie | nic |

**Co pokazują ekrany.**

- **Google:** sumy okresu ze zmianą do poprzedniego, wykres tygodniowy, zapytania tuż za czołówką (pozycje 4-15) z szacunkiem kliknięć do zyskania, zapytania z pierwszej piątki z klikalnością poniżej połowy zwykłej, spadki i wzrosty, dwie strony na jedno zapytanie (tylko ze stałego połączenia), podział kliknięć na zapytania z nazwą marki, pozostałe i ukryte przez Google.
- **Odpowiedzi AI:** strony pokazywane w odpowiedziach AI Google obok ich kliknięć z wyników, strony z kliknięciami, których AI nie pokazuje, pytania i długie zapytania, zapytania z utrzymaną pozycją i klikalnością niższą o 40% lub więcej, cytowania z Copilota, wejścia z asystentów, boty AI z podziałem na asystentów, wyszukiwarki AI i trening modeli. Gdy bot, który buduje odpowiedzi z linkami, dostaje odmowę w ponad połowie pobrań, ekran to zgłasza.
- **Konkurencja:** liczba stron w mapie, nowe i zmienione strony, lista ostatnich publikacji i tematy, których nie ma w adresach ani zapytaniach własnej witryny.
- **Dane i połączenia:** stan każdego źródła po ludzku, wgrywanie, wklejanie, ustawienia.

Progi są jawne i opisane na ekranie w części „Skąd te liczby”. Odniesieniem dla klikalności jest średnia tej samej witryny, a wartości umowne wchodzą dopiero przy zbyt małej liczbie danych i są oznaczone.

**Czego to nie robi.**

- Nie pokazuje pozycji konkurentów w Google ani ich ruchu. Tego nie ma w żadnym bezpłatnym źródle.
- Nie mówi, czy ChatGPT, Claude albo Perplexity cytują stronę. Te firmy nie udostępniają raportu cytowań. Widać tylko ślady: wejścia z ich stron i wizyty ich botów.
- Nie oddziela kliknięć z odpowiedzi AI Google od zwykłych: Google wlicza je do raportu „Skuteczność” bez osobnego filtra.
- Tematy konkurencji porównuje po słowach z adresów stron, bez odmiany i bez czytania treści. To podpowiedź, nie pełna analiza.

**Klucz Google dla stałego połączenia** (raz, około 10 minut, robi właściciel panelu):

1. W [Google Cloud](https://console.cloud.google.com) utwórz projekt o dowolnej nazwie.
2. W „Interfejsy API i usługi” włącz trzy interfejsy: Google Search Console API, Google Analytics Data API i Google Analytics Admin API.
3. W „Administracja” → „Konta usługi” utwórz konto usługi (bez ról), wejdź w nie, zakładka „Klucze” → „Dodaj klucz” → JSON. Pobierze się plik.
4. Treść pliku wklej w Render jako zmienną `GOOGLE_SERVICE_ACCOUNT` (całość, w jednej linii albo zakodowaną w base64).
5. W Ustawieniach panelu kliknij „Sprawdź połączenie z Google”. Panel pokaże adres konta usługi i witryny, które widzi.

Konto usługi ma tylko odczyt. Jeśli Google Cloud odmówi utworzenia klucza (reguła organizacji Google Workspace), projekt trzeba założyć na prywatnym koncie Google.

**Klucze platformy a inne firmy.** Klucz konta usługi Google i token Cloudflare z serwera są jedne na cały panel i widzą wszystko, do czego ktokolwiek dał im dostęp. Dlatego działają tylko w firmie administratora panelu (adresy z `ADMIN_EMAILS`). Inne firmy łączą własne konto Google i własny token Cloudflare albo wgrywają pliki (część „Platforma dla wielu firm”).

Źródła: [raport o generatywnej AI w pomocy Search Console](https://support.google.com/webmasters/answer/16984139), [zapowiedź raportu AI Performance w Bing Webmaster Tools](https://blogs.bing.com/webmaster/February-2026/Introducing-AI-Performance-in-Bing-Webmaster-Tools-Public-Preview), [Search Console API: zapytanie o dane](https://developers.google.com/webmaster-tools/v1/searchanalytics/query), [Cloudflare AI Crawl Control: GraphQL API](https://developers.cloudflare.com/ai-crawl-control/reference/graphql-api/) i [lista botów](https://developers.cloudflare.com/ai-crawl-control/reference/bots/).

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
- **Dane z kont klienta:** Search Console i Google Analytics zbudowane 24.11.2026 (plik z eksportu albo konto usługi Google, bez logowania Google i bez weryfikacji aplikacji). Raport „AI Performance” z Bing Webmaster Tools wkleja się ręcznie, bo nie ma API. Do zbudowania: dane o wyszukiwaniu z Binga (klucz API Bing Webmaster).

## Etap 4. Pozostałe moduły i sprzedaż platformy

Przetargi, oferty, kontrahenci, kontakty i wiadomości działają w silniku od razu po uruchomieniu (etap 0). Ten etap dodaje brakujące funkcje tych modułów i przygotowuje sprzedaż platformy innym firmom.

### 4a. Braki przed pilotażem

1. **E-maile:** zbudowane (poranny przegląd z przetargami, zapytaniami i alertami, reset hasła, zaproszenia).
2. **Konto i zespół:** zaproszenia e-mailem, logowanie dwuetapowe, eksport danych firmy i usunięcie konta firmy są. Do zrobienia: potwierdzenie adresu e-mail przy rejestracji otwartej dla wszystkich (przy zaproszeniach adres jest potwierdzony linkiem).
3. **AI:** każdy pakiet ma miesięczny przydział użyć AI i osobny przydział odpowiedzi czatu. Zużycie tokenów jest zapisywane w dzienniku i na ekranie administratora.
4. **Kopie i awarie:** kopie zapasowe bazy. Powiadomienie o nieudanym imporcie już działa (GitHub wysyła e-mail).
5. **Firmy spoza wykazu VAT:** firmy dodane z wyszukiwania w CEIDG mają już nazwę, adres i formę prawną z CEIDG, także gdy nie ma ich w wykazie VAT. Do zrobienia: sprawdzenie pojedynczej firmy w CEIDG po NIP-ie z karty kontrahenta.

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

1. **Wyniki przetargów z BZP:** zbudowane 20.11.2026. Kto wygrywa i za ile, zestawienie zwycięzców, historia zamawiającego na karcie przetargu, wygrane na karcie firmy.
2. **Baza Konkurencyjności** jako trzecie źródło. To zamówienia firm z dotacjami, często małe i dobre dla MŚP. Od 20.11.2026 jest w rejestrze zapytań jako źródło przeglądane ręcznie, a od 25.11.2026 lista firm z dotacjami UE pokazuje, kto ma budżet i będzie ogłaszał zapytania. Automatyczny import wymaga zgody operatora Bazy albo oficjalnego dostępu do danych.
3. **Ryzyko płatnicze:**
   - szacunek, po ilu dniach firma płaci dostawcom, ze sprawozdań finansowych w KRS: zbudowane 20.11.2026, liczby przepisuje użytkownik,
   - postępowania upadłościowe i restrukturyzacyjne z Krajowego Rejestru Zadłużonych: zbudowane 20.11.2026, wynik wpisuje użytkownik.
4. **Reklamy Google i Meta.** Wnioski warto złożyć wcześnie, bo zależą od zewnętrznych zgód:
   - Google: poziom dostępu Basic dla projektu Google Cloud, z weryfikacją marki,
   - Meta: weryfikacja firmy i przegląd aplikacji (App Review) dla uprawnienia ads_management.
5. **Wpis długu do BIG z platformy.** KRD ma API, ale wymaga umowy. Trzeba zapytać KRD i BIG InfoMonitor o współpracę partnerską.
6. **Wysyłka z Gmaila i Microsoft 365** zamiast pliku .eml. Uprawnienie Gmaila do wysyłania wymaga weryfikacji aplikacji przez Google.
7. **Serwer pośredniczący w Polsce** dla rejestru UOKiK, jeśli blokada adresów zagranicznych się potwierdzi.
8. **Pozyskiwanie klientów:**
   - zapytania ofertowe firm (strony firm, Baza Konkurencyjności, platformy zakupowe): rejestr z przewodnikiem po źródłach zbudowany 20.11.2026, zapytania wpisuje użytkownik,
   - opis idealnego klienta z treści strony firmy: zbudowane 20.11.2026,
   - lista potencjalnych klientów z treścią oferty i drogami kontaktu: zbudowana 23.11.2026, razem z listami, stroną zgody, lejkiem i wyszukiwaniem w CEIDG (po wpisaniu tokenu),
   - sekwencje z przypomnieniami i dziennym limitem: zbudowane 20.11.2026 w wersji, w której wysyłasz ze swojej skrzynki, a panel pilnuje terminów. Automatyczna wysyłka wymaga połączenia ze skrzynką (punkt 6),
   - plany zamówień z BZP i TED, firmy z dotacjami UE i kampanie e-mail z wersją dla każdego odbiorcy: zbudowane 25.11.2026,
   - kolejne źródła popytu do sprawdzenia: SUDOP, rejestr budowlany GUNB, Centralna Baza Ofert Pracy (część „Skąd brać popyt”).
9. **Widoczność w Google i w AI:** dane z Search Console, sygnały z odpowiedzi AI i konkurencja z map witryn zbudowane 24.11.2026. Do zrobienia: ocena wizytówki Google (wymaga zgody Google), pozycje konkurentów w wynikach (wymaga płatnej usługi z wynikami wyszukiwania) i test cytowań w AI.
10. **Dotacje:** ocena warunków naboru na wgranym regulaminie i lista obserwowanych naborów z przypomnieniami są zbudowane (20.11.2026). Wyszukiwarka naborów i pisanie całego wniosku jednym przyciskiem doszły 24.11.2026.
11. **Drobne uzupełnienia:** odhaczanie gotowych dokumentów w ofercie i podział sekcji między osoby są zbudowane (20.11.2026). Do zrobienia: szacowany czas pracy przy poprawkach z audytu technicznego.

## Co jest potrzebne od właściciela

Bez tego panel nie ruszy albo nie zrobi części pracy. Tych rzeczy nie da się zdobyć z kodu:

| Co | Do czego | Skąd |
|---|---|---|
| Konto Render i baza | uruchomienie panelu | render.com (albo Neon dla bazy) |
| `ANTHROPIC_API_KEY` | czat, ocena AI, szkice, wyszukiwarka naborów (web search musi być włączony w ustawieniach konta, domyślnie jest) | console.anthropic.com |
| `APP_SECRET` | szyfrowanie sekretów firm i logowania dwuetapowego. Render generuje go sam. Nie zmieniaj go po starcie: zaszyfrowanych danych nie da się wtedy odczytać | Render (automatycznie) |
| `GOOGLE_OAUTH_CLIENT_ID`, `GOOGLE_OAUTH_CLIENT_SECRET` | przycisk „Połącz z Google” dla firm klientów. Adres powrotu: `<adres panelu>/audyt/widocznosc/google/powrot`. Do pracy z wieloma firmami potrzebna weryfikacja aplikacji przez Google | console.cloud.google.com |
| Ceny i limity pakietów | Podstawowy i Rozszerzony mają limity startowe (30 i 400 użyć AI). Do decyzji: ceny, ostateczne limity, sposób płatności | decyzja właściciela |
| `SALES_EMAIL` | adres, na który firmy piszą o zmianie pakietu (pokazany w Ustawieniach) | własny adres |
| `RESEND_API_KEY`, `MAIL_FROM` i rekordy DNS | powiadomienia, reset hasła, poranny przegląd, kampanie e-mail do odbiorców ze zgodą | resend.com |
| `CAMPAIGN_FROM`, opcjonalnie | osobny adres kampanii (np. w subdomenie), żeby kampanie nie psuły reputacji powiadomień | resend.com, druga zweryfikowana domena |
| `PSI_KEY` | szybkość strony w audytach | Google Cloud, interfejs PageSpeed Insights |
| `CF_ACCOUNT_ID`, `CF_BROWSER_TOKEN` | wskaźniki A6 i E2 | Cloudflare, Browser Run |
| `GOOGLE_SERVICE_ACCOUNT`, opcjonalnie | stałe połączenie z Search Console i Google Analytics (bez niego działa wgrywanie plików) | Google Cloud, około 10 minut, raz. Kroki są w części „Widoczność w Google, w AI i u konkurencji” |
| `CF_ANALYTICS_TOKEN`, opcjonalnie | boty AI na witrynach za Cloudflare | Cloudflare → Mój profil → Tokeny API: uprawnienia „Zone → Analytics → Read” i „Zone → Zone → Read” dla domen, które panel ma widzieć. Ten sam token może mieć też uprawnienie Browser Rendering, wtedy wystarczy `CF_BROWSER_TOKEN` |
| Zgoda na kod widżetu i wpis w polityce prywatności | zapytania z perfectdata.pl | decyzja właściciela |
| Plik logo | nagłówek panelu i raporty | - |
| Własny adres panelu, opcjonalnie | krótszy adres strony zgody w listach | domena w Render i zmienna `APP_URL` |
| `CEIDG_TOKEN`, opcjonalnie | wyszukiwanie jednoosobowych działalności po branży i regionie | dane.biznes.gov.pl: logowanie profilem zaufanym i „Wniosek o dostęp do raportów CEIDG” na biznes.gov.pl. Token przychodzi e-mailem |
| Wnioski do Google i Meta | moduł Reklamy | Google Ads API (dostęp Basic), Meta App Review |
| Logowanie Google dla aplikacji | wizytówka Google | Google Cloud, ekran zgody OAuth i zgoda Google na interfejs wizytówek |

Decyzje:

1. **Adres panelu:** subdomena perfectdata.pl czy adres z Render.
2. **Pytania, punkty i progi kwalifikatora.** Startowe są w panelu. Do przejrzenia z osobą, która odbiera zapytania. Osobno: czy pytanie o budżet ma zostać.
3. **Progi umowne w wytycznych audytu.** Jest ich 11 oraz progi wyniku 85 i 60 pkt. Zespół SEO powinien je przejrzeć przed pracą z klientami, bo późniejsza zmiana to nowa wersja wytycznych.
4. **Zakres wskaźników C2 i C3.** W wersji 2026-11-20 daty i źródła punktujemy tylko na artykułach. Na stronie głównej, stronie produktu i ofercie raport pokazuje „nie dotyczy”. Powód: wymaganie daty i linków do źródeł na stronie głównej nie jest dobrym SEO.
5. **Raport dla klienta:** teraz pokazuje punkty. Do decyzji, czy mają zostać same stany „Dobrze”, „Do poprawy”, „Źle”.
6. **Czas odpowiedzi w podziękowaniu.** Widżet obiecuje kontakt w następnym dniu roboczym. Tekst zmienisz w ustawieniach widżetu.
7. **Test cytowań w AI:** które wyszukiwarki i jak często.
8. **Potwierdzanie adresu na stronie zgody.** Teraz zgoda jest ważna od razu. Przy literówce potwierdzenie i oferta trafią do obcej osoby. Potwierdzanie linkiem z e-maila to wyklucza, ale część firm nie kliknie.
9. **Koszt jednego listu** (druk, koperta, znaczek) do wpisania przy lejku. Bez niego panel nie liczy kosztu zgody.
10. **Tematy na stronie zgody.** Do sześciu, np. usługi agencji. Puste pole oznacza samą wiadomość.
11. **Źródło zapytania z asystenta AI w widżecie.** Widżet mógłby zapisać, że osoba przyszła z ChatGPT albo Perplexity. Bez pamięci przeglądarki działa to tylko wtedy, gdy ktoś otworzy widżet na pierwszej stronie wizyty. Pełne przypisanie wymaga zapisu w przeglądarce, a dziś widżet niczego w niej nie zapisuje. Do decyzji, czy warto to zmieniać.
12. **Pozycje konkurentów w Google.** Search Console pokazuje tylko własną witrynę. Cudze pozycje dają płatne usługi z wynikami wyszukiwania (opłata za każde sprawdzone zapytanie). Do decyzji: czy i dla ilu zapytań.
13. **Test cytowań w AI przez Claude.** Najprostsza wersja nie wymaga nowych kluczy: panel zadaje pytania klientów modelowi Claude z wyszukiwaniem w sieci i liczy, kogo cytuje. Koszt rośnie z liczbą pytań, więc potrzebny jest limit.
14. **Kampanie e-mail z adresu panelu.** Panel wysyła kampanie tylko do odbiorców ze zgodą, ale z domeny panelu. Do decyzji: osobna subdomena dla kampanii (`CAMPAIGN_FROM`) i dzienne limity w pakietach (startowo 100 i 1000).
15. **Kolejne źródła popytu.** Która branża klientów na start, bo od niej zależy, czy następne powinny być pozwolenia na budowę (GUNB), oferty pracy (CBOP), czy pomoc publiczna (SUDOP).

Potrzebne przed etapem 4:

- **Nazwa i domena** platformy zamiast „[Nazwa]”.
- **Branża na start.** Dane demo są pod roboty drogowe i budowlane.
- **5-10 firm do pilotażu.**
- **Prawnik.** Potrzebny do:
  - regulaminu i polityki prywatności (po ich przygotowaniu wpisz adresy w `TERMS_URL` i `PRIVACY_URL`),
  - umowy powierzenia przetwarzania danych: klienci wpisują dane osób kontaktowych, więc platforma przetwarza je w ich imieniu, a podwykonawcami są Render, Anthropic i usługa e-mail,
  - opinii o module wiadomości (art. 398 Prawa komunikacji elektronicznej) i o module należności (art. 15 i 15a ustawy o udostępnianiu informacji gospodarczych),
  - opinii o zbieraniu zgód: treść zgody i prośby o raport, raport jako zachęta, drugi list, wykorzystanie danych z CEIDG do listów i pytanie e-mailem o adres do ofert,
  - opinii o kampaniach e-mail: wysyłka przez panel do odbiorców ze zgodą (platforma jako pośrednik), stopka i wypisanie oraz wykorzystanie listy projektów UE i planów zamówień do kontaktu.

## Co zostało sprawdzone

### Biała lista VAT przed przelewem (27.11.2026)

- **211 testów** na SQLite (siedem nowych): suma kontrolna numeru rachunku, sprawdzenie rachunku z zapisem odpowiedzi i klucza MF (czas MF podany po polsku zapisany w UTC), opis wyniku zależny od kwoty, błędy wykrywane bez zapytania do MF (zła suma kontrolna, dzień z przyszłości, dzień sprzed 2020 r., zły format daty), błąd z API (dzień sprzed zakresu rejestru), blokada po odpowiedzi „za dużo zapytań”, dzienny przydział firmy i rezerwa na nocne odświeżanie, status VAT na dzień z przeszłości, karta firmy z formularzem i CSV (pole zaczynające się od znaku formuły dostaje apostrof), izolacja firm, sygnał o niedawnym przywróceniu do rejestru.
- **Na prawdziwym API Ministerstwa Finansów** (z przeglądarki, 27.11.2026, kilkanaście zapytań): rachunek z wykazu Urzędu m.st. Warszawy dał TAK z kluczem, poprawny rachunek spoza wykazu NIE, rachunek z błędną sumą kontrolną błąd WL-111, dzień z przyszłości WL-103, dzień z 2019 r. WL-118, błędny NIP WL-115. Odpowiedzi testowe mają dokładnie ten format. Nasza suma kontrolna zgadza się z oceną API na prawdziwym numerze i na tym samym numerze z błędem. Odpowiedź wyszukiwania ma nowe pole `exemptionSmeDate`, którego panel nie potrzebuje.
- **Testy w GitHub Actions nie były uruchamiane** do 1 grudnia, na prośbę właściciela. Zmiany są wypchnięte z oznaczeniem `[skip ci]`. Do zrobienia po 1 grudnia: testy na PostgreSQL i test kontraktu z API (doszło w nim sprawdzenie rachunku: TAK, NIE, WL-111, WL-103).
- **W przeglądarce** na danych przykładowych: sprawdzenie rachunku (TAK, NIE, zła suma kontrolna), status na dzień, pobranie CSV, karta w szerokościach 1280, 390 i 320 bez poziomego przewijania.
- **Mockup:** 41 ekranów w 4 szerokościach bez błędów i bez poziomego przewijania, sprawdzenie rachunku przeklikane, suma kontrolna w mockupie daje te same wyniki co silnik.

### Plany zamówień, firmy z dotacjami UE, kampanie e-mail (25.11.2026)

- **204 testy** na SQLite i PostgreSQL oraz test obrazu Docker (GitHub Actions). Nowe obejmują:
  - plany: odczyt treści planu o budowie jak w BZP (rok i wersja z tabelek, dwie sekcje progów, kategorie z rozstrzelonymi literami, wartości, terminy kwartalne i miesięczne, rezygnacja z pozycji), najnowszą wersję z jednego okresu, ponowienie planu, którego treści nie udało się pobrać, limit pobrań z kontynuacją, aktualizację pozycji w miejscu z zachowaniem zapisanych dopasowań, dopasowanie z odmianą słów, ekran z zapisem i ukrywaniem, izolację firm, zapis profilu z od razu policzonymi planami, zadanie dzienne,
  - firmy z dotacjami UE: rozpoznanie spółek (także zapisy z prawdziwego pliku, np. „sp z o.o.” i „SPÓŁKA Z OGRANICZONA ODPOWIEDZIALNOŚCIĄ”), pominięcie osób fizycznych, spółek cywilnych, gmin i dawno zakończonych projektów, pobranie pliku raz na miesiąc, usunięcie projektów, których nie ma w nowym pliku, zły plik bez utraty danych, dodanie firmy do potencjalnych klientów bez powtórek i z powodem kontaktu w punktacji,
  - kampanie: wersja z faktów dla każdego odbiorcy, ryzyko i trasa wysyłki dla każdego, wypisani poza kampanią, przerwa 14 dni, wysyłka przez panel tylko do zgód z nazwą firmy, adresem do odpowiedzi i nagłówkiem wypisania, zgoda cofnięta przed wysyłką, dzienny limit, pliki .eml w ZIP tylko po potwierdzeniu ryzyka, AI z licznikiem użyć i zamianą długich myślników, ekran i izolacja firm, wypisanie jednym kliknięciem.
- **Na prawdziwych danych (test kontraktu w GitHub Actions):** plany z BZP: 6 planów, 24 pozycje, w każdej odczytany rok, kategoria, wartość i termin. Zapowiedzi z TED: typy `pin-rtl`, `pin-only`, `pin-buyer`, województwo w każdej. Lista projektów UE: adres pliku znaleziony na stronie (link CSV ma atrybut w apostrofach, poprawione), w próbce 261 wierszy i 36 spółek z trwającymi projektami.
- **Silnik i mockup liczą plany tak samo:** 6 pozycji planów z mockupu, ta sama ocena albo odrzucenie w obu.
- **W przeglądarce** na danych przykładowych: przejście po 189 stronach w trzech szerokościach (1280, 390, 320) bez błędów i bez poziomego przewijania. Osobno: plany z zapisem pozycji, wyszukiwarka wszystkich planów, informacja na pulpicie, dodanie firmy z dotacją do potencjalnych klientów, kampania z wysyłką przez panel (3 wiadomości), przycisk potwierdzenia nieaktywny bez zaznaczenia oświadczenia i pobranie 9 wiadomości jako ZIP.
- **Mockup po zmianach:** 41 ekranów w 4 szerokościach bez błędów i bez poziomego przewijania. Przeklikane: plany (zapisz, ukryj, przywróć), firmy z dotacjami (szukaj, dodaj), kampania (AI, wysyłka przez panel, potwierdzenie ryzyka, pobranie).
- **Nie sprawdzono na prawdziwych usługach:** wysyłki kampanii przez Resend z prawdziwej domeny (format zapytania według dokumentacji Resend: `reply_to` i `headers`) ani pełnego importu listy projektów UE (150 MB) i wszystkich planów od stycznia. Pierwsze uruchomienie zadania dziennego po starcie panelu to sprawdzi.

### Platforma dla wielu firm, nabory, słowa klienta (24.11.2026, po południu)

- **183 testy** na SQLite i PostgreSQL oraz test obrazu Docker (GitHub Actions). Jedenaście nowych obejmuje:
  - pakiety: wyczerpany przydział nie wysyła zapytania do modelu, czat liczony osobno, limity osób i witryn, firma prowadząca panel bez limitów, tańszy model tylko w Podstawowym,
  - administrację: zwykła firma dostaje 404 na każdym adresie, zaproszenie z linkiem pokazanym raz i skrótem w bazie, adres z zaproszenia nie do podmiany, blokada kończy sesję i wyłącza widżet,
  - zabezpieczenia: kody TOTP zgodne z RFC 6238, kod i kod zapasowy działają raz, sześć błędów kończy próbę, unieważnianie sesji, szyfrowanie sekretów innym kluczem nie do odczytania,
  - dane firmy: eksport bez haseł i sekretów, usunięcie wszystkich tabel firmy bez naruszenia drugiej,
  - izolację firm na całym panelu,
  - własne konto Google firmy (znacznik jednorazowy, podrobiony i cudzy znacznik, odmowa na ekranie Google, zgoda bez Search Console, odświeżanie dostępu, cofnięty dostęp, odłączenie) i własny token Cloudflare,
  - wyszukiwarkę naborów (zapytanie według dokumentacji narzędzia, odrzucenie wymyślonego adresu, zakończonego naboru i złej daty, wstrzymane wyszukiwanie, brak duplikatów, cotygodniowe uruchomienie tylko w Rozszerzonym),
  - pisanie całego wniosku (gotowa sekcja nietknięta, podmieniona kolejka, zatrzymanie po wyczerpaniu przydziału),
  - „Co napisał klient” i przetarg jednym kliknięciem w ofertach,
  - menu główne w sześciu grupach: aktywna grupa i pozycja, Dotacje osobno od Ofert, drugi wiersz tylko przy kilku pozycjach, wyłączone moduły.
- **W przeglądarce** na danych przykładowych: przejście po 181 stronach w trzech szerokościach (1280, 390, 320) bez błędów i bez poziomego przewijania. Osobno: zaproszenie firmy z administracji, rejestracja z linku w drugiej przeglądarce, pakiet Podstawowy na nowym koncie, brak dostępu do administracji, przycisk „Połącz z Google” i przekierowanie na ekran zgody Google, pole tokenu Cloudflare, lista naborów, przycisk „Napisz cały wniosek”, rozwinięcie „Co napisał klient”.
- **Mockup po zmianach:** 38 ekranów w 4 szerokościach bez błędów i bez poziomego przewijania. Menu mieści się w jednym wierszu (wcześniej ostatnie pozycje wychodziły poza ekran). Nowe ekrany przeklikane: wyszukiwarka naborów (szukaj, obserwuj, odrzuć), pisanie całego wniosku sekcja po sekcji, „Do ofert” na liście przetargów, rozwinięcie „Co napisał klient”, pakiet, logowanie dwuetapowe, administracja (zmiana pakietu, blokada, zaproszenie).
- **Nie sprawdzono na prawdziwych usługach:** logowania Google (potrzebny klient OAuth), tokenu Cloudflare innej firmy, wyszukiwarki naborów na prawdziwym modelu (potrzebny klucz API z włączonym web search) ani jakości wniosku pisanego w całości. Te części są napisane według dokumentacji i sprawdzone na odpowiedziach w jej formacie.

### Widoczność w Google, w AI i u konkurencji (24.11.2026)

- **172 testy** na SQLite i PostgreSQL oraz test obrazu Docker (GitHub Actions). Pięć nowych obejmuje:
  - start: adres w dowolnej postaci staje się domeną, paczka z polskimi i angielskimi nagłówkami, liczby z odstępem i przecinkiem, okres z tabeli dat, pojedynczy CSV z okresem z formularza, eksport z porównaniem okresów (odmowa z instrukcją), plik, który nie jest eksportem,
  - wnioski: zapytania tuż za czołówką, niska klikalność na wysokiej pozycji, spadki, zapytanie, które zniknęło, marka rozpoznana z domeny, pytania, zmiana z danych dziennych,
  - raport o odpowiedziach AI rozpoznany bez pytania użytkownika, łączenie adresów mimo „www” i ukośnika, strony z ruchem, których AI nie pokazuje,
  - wklejanie tabel: tabulatory, odstępy, CSV, separatory tysięcy, źródła spoza listy asystentów, cztery rodzaje błędów,
  - konto usługi Google: podpis tokenu sprawdzony kluczem publicznym, tylko uprawnienia do odczytu, usługa znaleziona po domenie, treść każdego zapytania do Search Console i Analytics, drugie pobranie bez dublowania, zadanie dzienne tylko dla połączonych witryn, błędy opisane po ludzku (brak dostępu, wyłączony interfejs, usunięty klucz),
  - Cloudflare: treść zapytania, rozróżnianie podobnych nazw botów, podział na dni, odmowy, pliki techniczne poza listą, token bez uprawnienia, domena spoza konta,
  - konkurencja: mapa z robots.txt, indeks map, pierwsze pobranie jako punkt odniesienia, nowa i usunięta strona, tematy, zdjęcie konkurenta z listy,
  - bezpieczeństwo: druga firma nie widzi witryny, nie wgra do niej pliku i nie użyje kluczy platformy, kod HTML z pliku, z API, z mapy witryny i z wklejonej tabeli wyświetla się jako tekst.
- **Przeglądarka (Chromium), 1280, 390 i 320 px:** cała ścieżka na uruchomionym panelu: dodanie witryny, „Połącz” bez dostępu (komunikat z adresem do dodania), wgranie dwóch paczek naraz przez formularz, wklejenie tabeli, usunięcie witryny. Wykres: wartość pod kursorem, strzałki z klawiatury, dymek w granicach okna, tabela z liczbami. Bez błędów w konsoli i bez poziomego przewijania.
- **Cały panel po zmianach:** 178 stron i 48 pobrań w 3 szerokościach bez błędów, bez ostrzeżeń PHP w logu serwera i bez poziomego przewijania. Mockup: 34 ekrany w 4 szerokościach.
- **Stan usług** sprawdzony 24.11.2026 w źródłach podanych w części „Widoczność w Google, w AI i u konkurencji”: raport AI w Search Console jest dostępny dla wszystkich witryn od 31.08.2026 i ma tylko wyświetlenia, raport AI Performance w Bingu nie ma API, dane o żądaniach w Cloudflare są w każdym planie z historią 31 dni.

Czego nie dało się sprawdzić:

- prawdziwego pliku z eksportu Search Console. Nie mam do niego dostępu, więc nagłówki w testach odtworzyłem: angielskie są udokumentowane, polskich nie potwierdziłem. Dlatego panel rozpoznaje tabele po słowach w nagłówku (zapytania, strony, daty, kliknięcia, wyświetlenia, pozycja, po polsku i po angielsku), a nie po nazwie pliku ani dokładnym brzmieniu. Układ eksportu raportu o generatywnej AI znam tylko z opisu w pomocy Google. Do potwierdzenia przy pierwszym wgraniu: jeśli panel nie rozpozna pliku, powie to wprost,
- prawdziwych zapytań do Google i Cloudflare (wymagają kluczy). Wywołania są napisane według dokumentacji i sprawdzone na odpowiedziach w jej formacie,
- układu tabel w raporcie Binga. Dlatego panel przyjmuje dowolną tabelę: nazwa w pierwszej kolumnie, liczba w pierwszej kolumnie liczbowej,
- map witryn prawdziwych konkurentów (testy używają map przykładowych) i zachowania na Render.

### Listy i zgody: siedem dodatków (23.11.2026)

- **167 testów** na SQLite i PostgreSQL oraz test obrazu Docker (GitHub Actions). Jedenaście nowych obejmuje:
  - audyt jako zachętę: wynik w ofercie, liście i punktach, prośbę o sam raport (kontakt bez podstawy, ryzyko oferty bez zmian), raport i zgodę naraz, odpięcie raportu,
  - lejek: każde zdarzenie, wejście liczone raz na godzinę, podgląd z panelu poza lejkiem, okresy, koszt zgody, historię na karcie,
  - listy seryjnie: dwa listy z własnymi kodami QR w jednym pliku, adresy w CSV (z wartością zaczynającą się od znaku formuły), limit 50,
  - ogólną stronę zgody: błędy, firmę z listy o tym samym NIP-ie, firmę ze sprzeciwem, wypisany adres, dzienny limit, wymianę kodu,
  - tematy: zapis ustawień, odrzucenie obcych wartości, nawiązanie w szkicu oferty,
  - CEIDG: budowę zapytania, adres z pól rejestru, pominięcie zawieszonych i błędnych wpisów, zły token, limit, awarię, drugi zapis województwa,
  - drugi list: termin 21 dni, firmy z reakcją poza przypomnieniem, treść, koniec przypomnień po dwóch listach,
  - dobór rodzaju wiadomości do podstawy kontaktu i szkic pytania o adres do ofert.
- **Kod QR w pliku z trzema listami:** po wydruku do PDF niezależny czytnik (OpenCV) odczytał na każdej stronie adres właściwej firmy.
- **Przeglądarka (Chromium), 1280, 390 i 320 px:** każdy dodatek przeklikany na danych przykładowych, strony publiczne bez plików cookie, bez błędów w konsoli i bez poziomego przewijania.
- **Cały panel po zmianach:** 171 stron i 48 pobrań w 3 szerokościach bez błędów, bez ostrzeżeń PHP w logu serwera i bez poziomego przewijania. Mockup: 32 ekrany w 4 szerokościach.
- **Znalezione i poprawione przy okazji:** w mockupie opis drogi kontaktu zwężał się do jednego słowa w wierszu, gdy obok były dwa przyciski.

Czego nie dało się sprawdzić:

- prawdziwego zapytania do CEIDG (wymaga tokenu). Wywołanie jest napisane według dokumentacji dla integratorów i sprawdzone na odpowiedzi w jej formacie. Dokumentacja nie podaje zapisu nazw województw, dlatego panel pyta wielkimi literami, a przy pustym wyniku małymi. Do potwierdzenia przy pierwszym użyciu przyciskiem „Sprawdź połączenie z CEIDG” i jednym wyszukiwaniem,
- audytu prawdziwej strony uruchomionego z karty firmy (w testach audyt jest zapisanym wynikiem w formacie silnika, sam silnik audytu działa od początku),
- prawdziwej wysyłki raportu i potwierdzeń (wymaga klucza Resend), skanowania kodu z papieru telefonem i zachowania na Render.

Ocena prawna wymaga potwierdzenia przez prawnika. Najważniejsze pytania są w części „Co jest potrzebne od właściciela”.

### Zgoda na ofertę e-mailem (23.11.2026)

- **156 testów** (stan z tego etapu) na SQLite i PostgreSQL oraz test obrazu Docker (GitHub Actions). Pięć nowych obejmuje:
  - stronę zgody: błędny adres, brak zaznaczonej zgody, zgodę na telefon bez numeru, pole-pułapkę dla automatów, powtórne wysłanie formularza,
  - dowód zgody, kontakt z podstawą „zgoda”, powiadomienie zespołu, potwierdzenie dla firmy i niskie ryzyko oferty,
  - wycofanie linkiem z wiadomości, przyciskiem na stronie zgody i w panelu, odmowę jako sprzeciw,
  - zgodę zapisaną ręcznie (bez opisu, potwierdzenia albo z datą z przyszłości się nie zapisze), klienta z mocniejszą podstawą,
  - zły kod (404), limit 40 wejść na godzinę z jednego adresu, brak plików cookie, izolację między firmami i kod HTML w polach,
  - kod QR: rozmiary dla kolejnych długości tekstu, znaczniki narożne, poprawny plik PNG i kod w pliku .docx pod akapitem z adresem.
- **Kod QR** powstaje bez zewnętrznych bibliotek. 48 kodów o różnych długościach odczytał niezależny czytnik (OpenCV). Kod z listu wydrukowanego do PDF prowadzi na właściwą stronę zgody.
- **Przeglądarka (Chromium), 1280, 390 i 320 px:** karta firmy z adresem strony zgody, list z kodem QR, strona zgody bez logowania (błędy, zgoda, stan „zgoda już zapisana”, zły kod), karta i lista po zgodzie, ekran oferty z niskim ryzykiem, formularz ręcznej zgody. Bez błędów, bez plików cookie na stronie zgody i bez poziomego przewijania.
- **Cały panel po zmianach:** 167 stron w 3 szerokościach bez błędów i bez poziomego przewijania. Mockup: 32 ekrany w 4 szerokościach, z klikalnym podglądem strony zgody.

Czego nie dało się sprawdzić: prawdziwej wysyłki powiadomienia i potwierdzenia (wymaga klucza Resend), skanowania kodu z papieru prawdziwym telefonem i zachowania na Render. Ocena prawna wymaga potwierdzenia przez prawnika, zwłaszcza treść zgody i brak podwójnego potwierdzenia.

### Potencjalni klienci (23.11.2026)

- **151 testów** (stan z tego etapu) na SQLite i PostgreSQL oraz test obrazu Docker (GitHub Actions). Pięć nowych obejmuje:
  - wyszukanie wykonawców (bez własnej firmy, bez unieważnionych, bez firm już dodanych), wklejoną listę z błędnymi wierszami i limitem,
  - kolejkę rejestrów w przeglądarce i w zadaniu dziennym, firmę spoza wykazu VAT,
  - punkty dopasowania po zmianie kryteriów,
  - zaproszenie do ofert: zdanie w innym akapicie niż adres, zdanie w skrypcie strony, potwierdzenie, wpis ręczny z błędami,
  - treść oferty: z faktów, poprawioną, od AI (model dostaje tylko fakty z karty i bibliotekę), tę samą w liście i w wiadomości,
  - list .docx, informację RODO i termin dla osoby prowadzącej działalność, sprzeciw,
  - izolację między firmami i kod HTML w każdym polu.
- **Ryzyko wiadomości:** prośba o zgodę bez podstawy ma poziom bardzo wysoki, adres podany przez firmę do ofert daje poziom niski. Reguły w mockupie dają te same wyniki co silnik.
- **Przeglądarka (Chromium), 1280, 390 i 320 px:** wklejenie listy, kolejka rejestrów, dodanie firmy z wyników, sprawdzenie strony, potwierdzenie zaproszenia (bez zaznaczenia formularz nie wychodzi), treść oferty z AI, oferta na adres z zaproszenia, pobranie listu, e-mail bez zgody z zablokowanym przyciskiem do czasu potwierdzenia, sprzeciw, usunięcie. Bez błędów i bez poziomego przewijania.
- **Cały panel po zmianach:** 149 stron i 15 pobrań w 3 szerokościach (1280, 390 i 320 px) bez błędów, bez ostrzeżeń PHP w logu serwera i bez poziomego przewijania. Mockup: 32 ekrany w 4 szerokościach bez błędów.
- **Przepisy** sprawdzone 23.11.2026 w źródłach podanych w części „Potencjalni klienci i wysyłka”.

Czego nie dało się sprawdzić: wykazu VAT i KRS na dużej liście prawdziwych firm (wykaz ma dzienny limit zapytań, panel pyta zbiorczo po 30 firm), szukania zaproszeń na prawdziwych stronach firm (testy używają stron przykładowych) i prawdziwej odpowiedzi modelu AI. Ocena prawna dróg kontaktu wymaga potwierdzenia przez prawnika.

### Dane wpisywane ręcznie (20-23.11.2026)

- **146 testów** na SQLite i PostgreSQL oraz test obrazu Docker (GitHub Actions). Dziewięć nowych obejmuje:
  - zapis i walidację każdego formularza (błędna kwota, data, NIP, link, e-mail),
  - progi skali płatności (60 i 90 dni) i odczyt kwot pisanych po polsku,
  - kolejność według terminu, przypomnienia w wyznaczone dni i ciszę w pozostałe,
  - kod HTML wpisany w pola (wyświetla się jako tekst),
  - izolację między firmami: druga firma nie widzi, nie zmieni i nie usunie cudzych wpisów.
- **Przeglądarka (Chromium), świeża baza, 1280 i 390 px:**
  - karta firmy: wpis z KRZ, liczby ze sprawozdania, komunikat przy błędnej kwocie, kopiowanie NIP-u kliknięciem,
  - rejestr zapytań: wpis, poprawka terminu, odpowiedź o niskim ryzyku, wysyłka i przejście do zamkniętych, pominięcie i usunięcie,
  - nabory: wpis, wniosek z naboru, odrzucenie.
- **Cały panel po zmianach:** 154 strony i 17 pobrań bez błędów, 141 stron w 5 szerokościach (320-1280 px) bez poziomego przewijania.
- **Start z czystej kopii repozytorium (23.11.2026):** kroki z README (migracje, dane przykładowe, serwer), potem 140 stron panelu w 3 szerokościach (1280, 390 i 320 px) bez błędów, bez ostrzeżeń PHP w logu serwera i bez poziomego przewijania.
- **Mockup:** 32 ekrany w 4 szerokościach bez błędów i bez poziomego przewijania, nowe formularze na ekranach „Zapytania ofertowe”, „Dotacje” i „Karta firmy” działają.
- **Adresy źródeł** potwierdzone 20.11.2026 wyszukiwaniem i otwarciem stron.

Czego nie dało się sprawdzić: wyglądu i nazw przycisków w samych rejestrach (panel podaje adres i opisuje, czego szukać, ale nie cytuje ich przycisków, bo serwisy je zmieniają). Wyszukiwanie w KRZ po NIP nie ma potwierdzenia w oficjalnej instrukcji, dlatego karta podaje też numer KRS.

### Funkcje z etapu 4 (20.11.2026, po południu)

- **137 testów** na SQLite i PostgreSQL oraz test obrazu Docker (GitHub Actions).
- **Wyniki przetargów na prawdziwych danych:** test kontraktu z API pobrał 25 ogłoszeń o wyniku z BZP (126 części, 94 zawarte umowy). Wykonawca, cena i liczba ofert odczytane w 94 z 94 umów. Numery części z treści zgadzają się z wyszukiwarką w 25 z 25 ogłoszeń. Testy jednostkowe używają 6 prawdziwych ogłoszeń z 16.11.2026: jedna część, dwie części, konsorcjum, część nierozstrzygnięta, zamówienie z wolnej ręki, ogłoszenie o samej części 4.
- **Znalezione i poprawione przy okazji:**
  - profil z kodem CPV działu kończącego się zerem (30, 50, 60, 70, 80, 90) nigdy nie dopasowywał przetargów, a wyszukiwarka po takim kodzie zwracała przypadkowe wyniki,
  - w przeglądarce przycisk „Szkic AI” na ekranie wiadomości nie wysyłał swojej akcji (skrypt wyłączał go przed wysłaniem formularza). Testy na poziomie serwera tego nie widziały,
  - długi adres w treści wiadomości rozpychał stronę na telefonie.
- **Przeglądarka (Chromium), świeża baza z danymi przykładowymi:**
  - 144 strony panelu i 17 pobrań bez błędów, 157 stron w 5 szerokościach (320-1280 px) bez poziomego przewijania,
  - wyniki przetargów, karta przetargu z wynikiem i z historią zamawiającego, karta firmy z wygranymi,
  - oferta: przydzielenie sekcji, odhaczenie i dopisanie dokumentu, dwie karty zapisujące tę samą sekcję (druga dostaje komunikat i nie traci tekstu),
  - warunki naboru na przykładowym regulaminie,
  - przypomnienia: krok na dziś, napisanie i oznaczenie wysyłki, wypisanie linkiem bez logowania. Strona wypisania nie zapisuje plików cookie.
- **Odpowiedzi modelu AI w testach są zapisanymi przykładami.** Każdy test zawiera pozycję ze zmyślonym cytatem i sprawdza, że program ją odrzuca.

Czego nie dało się sprawdzić: prawdziwej odpowiedzi modelu w ocenie warunków naboru i w opisie idealnego klienta (wymaga klucza), prawdziwego e-maila o przydzielonej sekcji, zachowania nagłówka wypisania jednym kliknięciem w programach pocztowych (link w treści działa niezależnie od niego).

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
  - na świeżo założonej bazie z danymi przykładowymi: 108 stron panelu i 12 pobrań bez błędów, te same 108 stron w 5 szerokościach (320-1280 px) bez poziomego przewijania.
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
