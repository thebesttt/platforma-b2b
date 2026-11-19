# Audyt treści pod wyszukiwarki i AI: plan integracji

Stan na 19.11.2026. Moduł „Audyt AI” w panelu PerfectData. Mockup w wyglądzie perfectdata.pl jest w `mockup/audyt-ai`, wytyczne w `mockup/audyt-ai/WYTYCZNE.md`. Kolejność prac w całym projekcie jest w `PLAN.md` (audyt to etap 2).

## 1. Co bierzemy z CitationOne, a czego nie

CitationOne ocenia jeden artykuł pod kątem cytowań w ChatGPT i Google AI. Daje wynik 0-100 z 11 wymiarów, ale nie publikuje wag ani progów. Dla firmy, która ma potem wykonać prace i pokazać klientowi poprawę, to za mało: nie wiadomo, co dokładnie trzeba zrobić, żeby wynik był dobry.

| Funkcja CitationOne | U nas | Uwagi |
|---|---|---|
| Wynik 0-100 z rozbiciem | Wynik 0-100 w 5 grupach | Wagi, progi i podstawa każdego wskaźnika są jawne. |
| BLUF (odpowiedź na początku) | D1 Odpowiedź na początku | Próg: odpowiedź w pierwszych 100 słowach, z cytatem jako dowodem. |
| Fan-Out i pokrycie AI Overview | D2 Pytania poboczne | Lista 10 pytań zamrożona przy pierwszym audycie, żeby cel się nie zmieniał. |
| Cost of Retrieval (nagłówki, listy, tabele) | B4, B5, B6 | Mierzone programem, bez oceny AI. |
| E-E-A-T | C1-C5 | Tylko sygnały, które da się sprawdzić w kodzie strony: autor, daty, źródła, dane firmy. |
| Information Density | C4 Konkretne dane | Zdania z liczbą i jednostką na 1000 słów. |
| Audyt schema.org | B7 Dane strukturalne | Sprawdzamy też zgodność z widoczną treścią, bo tego wymaga Google. |
| Quick Wins | „Najpierw to” | Zadania z liczbą punktów i warunkiem zaliczenia. |
| Before / After | „Przed i po” | Porównanie dwóch audytów tej samej strony. |
| Cytowania w ChatGPT i Google AI | „Cytowania w AI” | Jako wynik, bez punktów. Każde pytanie 3 razy. |
| Audyt wielu stron z mapy witryny | „Strony witryny” | Każda strona ma swoją frazę. |
| Publiczny link do raportu | „Podgląd dla klienta” | Link tylko do odczytu, bez menu platformy. |
| Chunk Optimization (sekcje 200-500 słów) | Bez punktów | Google: dzielenie treści na małe fragmenty nie jest potrzebne. |
| TF-IDF na tle TOP 10 | Bez punktów | Google rozumie synonimy. Bing zalicza sztucznie konstruowany język do nadużyć. |
| Semantic Roles (strona czynna) | Pominięte | Brak źródła lub badania. |
| Długość na tle konkurencji | Bez punktów | Google: nie ma idealnej długości strony. |
| Przepisywanie treści przez AI | Na później | Najpierw pomiar. Google ostrzega przed masowym tworzeniem treści pod wyszukiwarki. |

Dodajemy to, czego CitationOne nie ma, a co decyduje o widoczności: dostęp botów AI (robots.txt, zapora CDN, treść bez JavaScript), możliwość pokazania fragmentu i Core Web Vitals.

## 2. Zasada, która rozwiązuje problem „dobrze ogólnie i dobrze w narzędziu”

Punkty są tylko za to, co ma podstawę w dokumentacji Google, Microsoftu, dostawców botów AI albo w opublikowanym badaniu. Dzięki temu praca wykonana zgodnie z wytycznymi jest jednocześnie dobrym SEO i dobrym wynikiem w narzędziu. Nie ma wskaźnika, który trzeba „zrobić pod narzędzie”.

Trzy zabezpieczenia:

- **Jedno źródło prawdy.** Plik `rules.json` zawiera progi, punkty i podstawę. Z niego powstaje strona „Wytyczne”, dokument dla zespołu i punktacja w silniku. Nie da się zmienić progu w jednym miejscu i zapomnieć o drugim.
- **Wersje.** Każdy audyt zapisuje wersję wytycznych. Zmiana progu to nowa wersja, a stare audyty zostają przy swojej. Porównanie „przed i po” zawsze używa jednej wersji.
- **Powtarzalność.** Wskaźniki liczy program. Dwa wskaźniki ocenia model AI (D1, D2): zawsze z cytatem, który program sprawdza w treści strony, i z zamrożoną listą pytań. Jeśli treść i lista pytań się nie zmieniły, bierzemy poprzednią ocenę AI zamiast pytać drugi raz.

Czego wynik nie obiecuje: pozycji w Google ani cytowań w AI. Google pisze, że żadne zewnętrzne narzędzie nie ma dostępu do jego systemów rankingowych i AI. Cytowania pokazujemy osobno jako wynik.

## 3. Gdzie to działa

**Zalecenie: moduł platformy (PHP i PostgreSQL), a Cloudflare do mockupu i jako pomocnik.**

Powody:

1. Platforma ma już konta, firmy, listę klientów, audyt techniczny, bezpieczne pobieranie stron, klienta AI z limitem i 68 testów. Nowy audyt korzysta z tego wprost.
2. Jedna baza. Raporty, klienci i użytkownicy są w jednym miejscu, bez synchronizacji.
3. Jeden sposób wdrażania i jedno miejsce na kopie zapasowe.

Rola Cloudflare:

- **Teraz:** mockup jako strona statyczna (bez bazy).
- **Docelowo:** usługa Browser Run (dawniej Browser Rendering) do wskaźników A6 i E2. Platforma wysyła adres, Cloudflare zwraca stronę po uruchomieniu skryptów. Plan darmowy daje 10 minut pracy przeglądarki dziennie, płatny 10 godzin miesięcznie.
- **Opcjonalnie później:** własna domena narzędzia przez Cloudflare przed platformą.

Wariant „wszystko na Cloudflare” (Workers i D1) jest możliwy, ale oznacza przepisanie silnika i kont na JavaScript. Darmowy plan Workers ma limit 10 ms procesora i 50 zapytań zewnętrznych na wywołanie, więc audyt wymaga planu płatnego (od 5 USD miesięcznie). Ma sens tylko wtedy, gdy audyt ma być osobnym produktem bez reszty platformy.

## 4. Baza danych (migracja 004)

| Tabela | Co trzyma | Najważniejsze pola |
|---|---|---|
| `content_targets` | Cel audytu: strona i fraza | `org_id`, `contact_id` (klient z listy „Klienci”, opcjonalnie), `url`, `host`, `phrase`, `page_type`, `questions_json` (10 pytań), `questions_locked_at` |
| `content_audits` | Jedno wykonanie audytu | `target_id`, `status` (w kolejce, w toku, gotowy, błąd), `step`, `steps_json`, `ruleset` (wersja wytycznych), `score`, `blocked`, `text_hash` (skrót treści), `raw_json` (nagłówki, robots.txt, metadane) |
| `content_audit_checks` | Wynik jednego wskaźnika | `audit_id`, `code` (A1...E3), `status` (dobrze, do poprawy, źle, brak pomiaru), `points`, `max_points`, `value`, `evidence_json` |
| `content_audit_citations` | Jedna próba zapytania do wyszukiwarki AI | `audit_id`, `engine`, `query`, `run_no`, `cited`, `position`, `sources_json` |
| `content_audit_shares` | Publiczny link dla klienta | `target_id`, `token_hash`, `expires_at`, `revoked_at` |

Zasady:

- Wszystkie tabele mają `org_id` i ten sam mechanizm izolacji danych między firmami co reszta platformy.
- Pełnego kodu HTML nie zapisujemy. Zostaje skrót treści, metadane i dowody (cytaty, wiersze robots.txt).
- Unikalność: jedna para (strona, fraza) na firmę i jeden wynik wskaźnika na audyt.
- Stara tabela `site_audits` (audyt techniczny sklepu) zostaje bez zmian.

## 5. Jak przebiega audyt

Audyt to lista kroków zapisana w bazie. Przeglądarka użytkownika prosi o kolejny krok, aż wszystkie się skończą. Każdy krok trwa do 30 s. Nie potrzeba osobnego procesu w tle, a przerwany audyt wznawia się od ostatniego kroku. Audyt wielu stron dokańcza zadanie cykliczne.

| Krok | Co robi | Wskaźniki | Usługa zewnętrzna |
|---|---|---|---|
| 1. Pobranie | HTML, nagłówki, przekierowania, robots.txt | A1, A2, A3, A4, E3 | brak |
| 2. Boty | 5 zapytań z nagłówkami botów | A5 | brak |
| 3. Treść | Analiza HTML: tytuł, nagłówki, akapity, dane strukturalne, autor, daty, źródła | B1-B7, C1-C5, D3 | brak |
| 4. Render | Strona po uruchomieniu skryptów | A6, E2 | Cloudflare Browser Run |
| 5. Szybkość | Dane użytkowników Chrome | E1 | PageSpeed Insights (klucz już jest) |
| 6. Ocena AI | Odpowiedź na początku i pytania poboczne, z cytatami | D1, D2 | Claude (klucz już jest) |
| 7. Cytowania | 4 pytania po 3 próby w każdej wyszukiwarce | bez punktów | OpenAI, Perplexity, Gemini (opcjonalnie) |
| 8. Wynik | Punkty według `rules.json`, zapis | wszystkie | brak |

Gdy kroku nie da się wykonać (brak klucza, usługa nie odpowiada), wskaźnik dostaje stan „brak pomiaru”, a wynik liczymy proporcjonalnie z pozostałych punktów. Raport to pokazuje.

Bezpieczeństwo:

- Pobieranie przez istniejący silnik, który blokuje adresy wewnętrzne.
- Treść strony trafia do modelu jako dane. Cytat zwrócony przez model musi istnieć w treści, inaczej ocena jest odrzucana.
- Limit audytów na firmę i godzinę.
- Publiczny link ma losowy token (w bazie tylko skrót), nagłówek noindex i można go wyłączyć.

## 6. Ekrany i adresy

| Adres | Ekran |
|---|---|
| `/audyt/tresc` | Lista stron i fraz z ostatnim wynikiem („Strony witryny”) |
| `/audyt/tresc/{id}` | Raport z przełącznikiem audytów |
| `/audyt/tresc/{id}/porownanie` | „Przed i po” |
| `/audyt/tresc/{id}/cytowania` | „Cytowania w AI” |
| `/audyt/wytyczne` | „Wytyczne wskaźników” (z `rules.json`) |
| `/r/{token}` | Raport dla klienta, bez logowania |

W panelu PerfectData moduł ma w nagłówku pozycję „Audyt AI”, obok „Zapytania”. Zakładki modułu: Raport, Wytyczne wskaźników, Przed i po, Cytowania w AI, Strony witryny. Stronę można przypisać do klienta z listy „Klienci”, wtedy raporty są też w jego karcie. W platformie dla innych firm audyt treści jest zakładką modułu „Audyt i reklamy”, obok audytu technicznego sklepu.

### Wygląd raportu

Raport ma wygląd perfectdata.pl (kolory, czcionka i przyciski ze strony, opis w `PLAN.md`). Klient agencji dostaje link `/r/{token}` i widzi markę PerfectData, bez panelu agencji.

- **Wynik i linijka 100 punktów.** Duża złota liczba, pod nią 24 odcinki w 5 grupach. Szerokość odcinka to waga wskaźnika, złote wypełnienie to zdobyte punkty, czerwona ramka to zero. Suma złota na linijce równa się liczbie nad nią. Kliknięcie odcinka otwiera wskaźnik.
- **„Najpierw to”.** Do 6 zadań z liczbą punktów do zdobycia i warunkiem zaliczenia. Najpierw warunki konieczne, potem dostęp botów, potem według punktów.
- **Wskaźniki w wierszach:** kod, nazwa, zmierzona wartość, stan, punkty. Po rozwinięciu: co mierzymy, jak, dowód, co zrobić i trzy progi z zaznaczonym obecnym.
- **„Przed i po”.** Dwie linijki jedna pod drugą i tabela zmian. To ekran do pokazania klientowi po miesiącu pracy.
- **Stan zawsze jako kształt i słowo** (pełne koło, pół koła, pierścień), żeby raport był czytelny także w druku czarno-białym i dla osób nierozróżniających barw.

## 7. Usługi zewnętrzne

| Usługa | Do czego | Stan |
|---|---|---|
| PageSpeed Insights | Core Web Vitals | Klucz `PSI_KEY` jest w platformie. |
| Claude (Anthropic) | D1, D2, propozycja 10 pytań | Klucz jest. 2-3 zapytania na audyt, mniej przy niezmienionej treści. |
| Cloudflare Browser Run | A6, E2 | Wymaga tokenu API z konta Cloudflare. Limity: 10 minut dziennie na planie darmowym. |
| OpenAI, Perplexity, Gemini | Test cytowań | Opcjonalne. Jeden audyt to 12 zapytań na wyszukiwarkę. Ceny trzeba sprawdzić przed włączeniem. |
| Google Search Console, Bing Webmaster Tools | Dane o cytowaniach od wyszukiwarek | Na start link do raportu na koncie klienta. Import przez API po sprawdzeniu, czy raporty AI są w API. |

## 8. Etapy

1. **Pomiar bez AI.** Kroki 1, 2, 3, 5 i 8. Raport, „Wytyczne”, „Przed i po”, link dla klienta. To 20 z 24 wskaźników i 82 ze 100 punktów.
2. **Render i ocena AI.** Kroki 4 i 6: wskaźniki A6, E2, D1, D2. Zatwierdzanie listy pytań przez opiekuna klienta.
3. **Wiele stron i cytowania.** Lista stron z mapy witryny, zadanie cykliczne, krok 7.
4. **Dane z kont klienta.** Search Console i Bing Webmaster Tools.

Po etapie 1 narzędzie nadaje się do pracy z klientem. Kolejne etapy dodają wskaźniki, nie zmieniają już istniejących.

Etap 1 zaczyna się po uruchomieniu panelu PerfectData i kwalifikatora zapytań (etap 1 w `PLAN.md`), bo korzysta z tego samego wyglądu i tej samej listy klientów.

## 9. Testy, które pilnują zgodności z wytycznymi

- Dla każdego progu test graniczny, np. LCP 2,5 s to „Dobrze”, 2,6 s to „Do poprawy”.
- Strona wzorcowa, która spełnia wszystko, ma 100 pkt. Strona, która nie spełnia niczego, ma 0 pkt.
- Suma punktów w `rules.json` to 100, każdy wskaźnik ma trzy progi i podstawę (to już sprawdza `build.py`).
- Strona „Wytyczne” zawiera każdy wskaźnik, który liczy silnik.
- Ten sam audyt niezmienionej strony daje ten sam wynik.
- Cytat z oceny AI musi być w treści strony.
- Izolacja danych między firmami i próba wstrzyknięcia HTML na nowych ekranach, jak w reszcie platformy.

## 10. Zastosowanie na perfectdata.pl

Audyt pasuje do usługi „SEO i GEO” z perfectdata.pl na trzy sposoby:

- **Przed sprzedażą.** Automatyczne sprawdzenie strony przy zapytaniu (moduł „Zapytania ze strony”). Handlowiec dzwoni z wynikiem i trzema najważniejszymi brakami.
- **W trakcie współpracy.** Raport „Przed i po” jako dowód wykonanej pracy co miesiąc. Lista „Najpierw to” jako plan prac.
- **Jako zasady dla zespołu.** Wytyczne mówią wykonawcy, co dokładnie ma być zrobione, żeby wskaźnik był „Dobrze”.

Strona usługi mówi wprost, że SEO i GEO nie gwarantują pozycji ani cytowań w odpowiedziach AI. Audyt jest z tym zgodny: punkty są za spełnienie warunków, a cytowania pokazujemy osobno jako wynik.

Audyt nie zastępuje płatnych audytów z perfectdata.pl (Express i Data-First). Tamte ocenia człowiek i dotyczą danych, pomiaru i kampanii. Ten mierzy automatycznie jedną stronę pod kątem wyszukiwarek i AI.

Pierwszą stroną do sprawdzenia po uruchomieniu silnika powinna być sama perfectdata.pl. Firma, która sprzedaje GEO, powinna mieć u siebie komplet punktów w grupie A (dostęp botów AI), tym bardziej że strona stoi za Cloudflare, a Cloudflare ma własne ustawienia blokowania botów AI.

## 11. Decyzje dla właściciela

1. **Gdzie działa audyt:** moduł platformy (zalecane) czy osobny produkt na Cloudflare.
2. **Progi umowne.** W wytycznych jest 11 wskaźników z dopiskiem o progu umownym (A3, A5, A6, B3, B4, B5, B6, C3, C4, D1, D2) oraz progi wyniku (85 i 60 pkt). Zespół SEO powinien je przejrzeć przed wdrożeniem, bo potem zmiana to nowa wersja.
3. **Wyszukiwarki w teście cytowań:** które z trzech i jak często, bo to jedyny koszt zależny od liczby audytów.
4. **Raport dla klienta:** z punktami czy tylko ze stanami „Dobrze”, „Do poprawy”, „Źle”.
