# Zapytania ze strony: plan integracji

Stan na 19.11.2026. Moduł „Zapytania” w panelu PerfectData. Mockup w wyglądzie perfectdata.pl jest w `mockup/audyt-ai` (pozycja „Zapytania” w nagłówku), zasady kwalifikacji w `mockup/audyt-ai/src/leads.json`. Kolejność prac w całym projekcie jest w `PLAN.md` (zapytania to etap 1).

Cel: więcej zapytań ze strony i szybsza ocena, które z nich są warte telefonu jeszcze dziś. Pierwszym użytkownikiem jest PerfectData (perfectdata.pl), potem moduł trafia do klientów platformy.

## 1. Co bierzemy z LeadTok, a czego nie

LeadTok zastępuje formularz kontaktowy rozmową z AI: kwalifikator, czat i formularz, wklejane na stronę jednym kodem. Deklaruje ok. 30% więcej wartościowych zapytań. To deklaracja producenta, nie znaleźliśmy niezależnego potwierdzenia.

| Funkcja LeadTok | U nas | Uwagi |
|---|---|---|
| Kwalifikator zamiast formularza | Tak, etap 1 | Pytania i punkty ustawia firma. Ocenę liczy program, nie AI. |
| Baza wiedzy z adresu strony | Tak, etap 2 | Łączymy „Bibliotekę firmy” z treścią strony pobraną istniejącym silnikiem. |
| Czat AI | Tak, etap 2 | Odpowiada tylko z bazy wiedzy, z limitem wiadomości i budżetem. |
| CRM | Już jest | Zapytanie staje się kontaktem w „Klienci i wiadomości”. |
| Powiadomienie o nowym zapytaniu | Tak, etap 1 | Wymaga wysyłki e-maili z platformy (jest w głównym planie). |
| Testy A/B | Tak, etap 3 | Z jawną regułą, kiedy wynik jest pewny. |
| Demo po wpisaniu adresu | Tak, etap 4 | Wpisujesz adres, dostajesz audyt i gotowy kwalifikator do obejrzenia. |
| Formularze na danych behawioralnych | Nie | Wymagają śledzenia zachowania, czyli zgody na pliki cookie. Korzyść jest słabo opisana. |
| Nacisk na zdobycie numeru telefonu | Nie | Telefon jest opcjonalny i daje punkty, ale rozmowa nie jest od niego uzależniona. |
| SMS i Messenger | Później | Zależy od potrzeb po pilotażu. |

Czego LeadTok nie ma, a my mamy od razu:

- **Sprawdzenie firmy.** Gdy zapytanie zawiera NIP, platforma sprawdza wykaz VAT, KRS i decyzje UOKiK. Handlowiec widzi ostrzeżenie przed wyceną.
- **Audyt sklepu przy zapytaniu.** Gdy zapytanie zawiera adres sklepu, platforma robi audyt i dołącza wynik. Handlowiec dzwoni z konkretem.
- **Podstawa prawna kontaktu.** Zapytanie zapisuje się z podstawą „zapytanie od tej firmy”, więc odpowiedź ma w module wiadomości niskie ryzyko.
- **Jawne zasady.** Punkty za każdą odpowiedź widać przy zapytaniu.

## 2. Jak to działa

1. Firma ustawia pytania, odpowiedzi, punkty i progi (domyślnie: od 70 pkt „Pilne”, od 40 pkt „Do rozmowy”).
2. Wkleja jeden wiersz kodu na stronę. Widżet działa tylko na adresach wpisanych w ustawieniach.
3. Odwiedzający klika odpowiedzi, może podać adres sklepu i zostawia kontakt. Na każdym etapie może zadać własne pytanie.
4. Platforma liczy ocenę, sprawdza NIP, zleca audyt sklepu i zapisuje kontakt z podstawą.
5. Handlowiec dostaje powiadomienie i jednym kliknięciem otwiera szkic odpowiedzi.

Pytania kwalifikujące nie używają AI. Działają szybko, nic nie kosztują i dają zawsze tę samą ocenę za te same odpowiedzi. AI pracuje tylko w czacie i przy streszczeniu zapytania.

## 3. Baza danych (migracja 005)

| Tabela | Co trzyma | Najważniejsze pola |
|---|---|---|
| `inbound_widgets` | Ustawienia widżetu | `org_id`, `public_id` (w adresie kodu), `name`, `origins` (dozwolone adresy stron), `rules_json` (pytania, punkty, progi), `rules_version`, `texts_json` (powitanie, podziękowanie, klauzula), `limits_json`, `active` |
| `inbound_conversations` | Jedna rozmowa odwiedzającego | `widget_id`, `variant` (A lub B), `page_url`, `answers_json`, `messages` (licznik), `status`, `started_at`, `ip_hash` |
| `inbound_messages` | Pytania do czatu i odpowiedzi AI | `conversation_id`, `role`, `text`, `created_at` |
| `inbound_leads` | Zapytanie z kontaktem | `conversation_id`, `contact_id` (wiersz w `contacts`), `score`, `level`, `points_json`, `shop_url`, `site_audit_id`, `marketing_consent`, `consent_text`, `summary`, `status` |
| `inbound_stats` | Liczniki dzienne do lejka i testu | `widget_id`, `day`, `variant`, `views`, `started`, `completed`, `leads` |

Powiązania z tym, co już jest:

- Zapytanie tworzy albo uzupełnia wiersz w `contacts`: `basis = 'rfq'`, `basis_detail` z odpowiedzi, `basis_url` ze strony, `basis_at` z daty, `website` z adresu sklepu.
- NIP trafia do modułu kontrahentów (`companies`), wynik jest przy zapytaniu.
- Adres sklepu trafia do audytu (`site_audits`), a po wdrożeniu audytu treści także tam.
- Zgoda na oferty jest zapisana z treścią, datą i adresem strony. Bez niej platforma pozwala na odpowiedź na zapytanie, a kolejne oferty oznacza jako ryzykowne (tak jak dziś w module wiadomości).

Czego nie zapisujemy: adresu IP w postaci jawnej (tylko skrót do limitów), plików cookie, historii przeglądania.

## 4. Publiczne adresy widżetu

| Adres | Do czego |
|---|---|
| `GET /w/{id}.js` | Kod widżetu. Mały plik, bez zewnętrznych bibliotek. |
| `POST /w/{id}/start` | Początek rozmowy. Zwraca pytania i wariant testu. |
| `POST /w/{id}/answer` | Zapis odpowiedzi na pytanie. |
| `POST /w/{id}/ask` | Pytanie do czatu (etap 2). |
| `POST /w/{id}/lead` | Zapis kontaktu i zgód. |

To pierwsze adresy platformy dostępne bez logowania z cudzych stron, więc wymagają osobnych zabezpieczeń:

- Odpowiedź tylko dla adresów stron wpisanych w ustawieniach widżetu.
- Limit zapytań na skrót adresu IP i dzienny limit rozmów na widżet.
- Ukryte pole i minimalny czas wypełnienia przeciw botom. Opcjonalnie Cloudflare Turnstile.
- Limity długości każdego pola. Treść od odwiedzającego jest zawsze danymi, nigdy poleceniem dla AI.
- Widżet rysuje się w odizolowanym elemencie, żeby style strony klienta go nie psuły i odwrotnie.

Cloudflare może tu pomóc: plik `/w/{id}.js` nadaje się do serwowania z ich sieci, a limity zapytań można ustawić przed platformą. To opcja, nie warunek.

## 5. Czat AI: zasady i koszt

- Odpowiada tylko na podstawie bazy wiedzy firmy. Gdy odpowiedzi tam nie ma, mówi to wprost i proponuje kontakt z człowiekiem.
- Nie podaje cen ani terminów, których nie ma w bazie. Niczego nie obiecuje w imieniu firmy.
- Limit wiadomości na rozmowę (domyślnie 12) i miesięczny budżet na widżet. Po przekroczeniu działają same pytania kwalifikujące.
- Baza wiedzy trafia do modelu jako blok z pamięcią podręczną, tak jak dziś materiały do ofert. To obniża koszt kolejnych pytań.
- Każda rozmowa jest zapisana, żeby firma mogła sprawdzić, co asystent odpowiedział.

Kosztu jednej rozmowy nie znamy przed testem. Zmierzymy go w pilotażu na perfectdata.pl i dopiero wtedy ustawimy domyślny budżet.

## 6. Wymogi prawne

- **AI Act.** Od 2.08.2026 użytkownik musi wiedzieć, że rozmawia z systemem AI. Widżet pokazuje to na początku rozmowy, nie da się tego wyłączyć.
- **RODO.** Klauzula informacyjna przy podaniu kontaktu i link do polityki prywatności. Firma jest administratorem danych, platforma przetwarza je w jej imieniu (umowa powierzenia jest w głównym planie, do przygotowania z prawnikiem).
- **Prawo komunikacji elektronicznej.** Odpowiedź na zapytanie nie wymaga zgody marketingowej. Zgoda na kolejne oferty jest osobnym, domyślnie odznaczonym polem.
- **Bez plików cookie.** Wariant testu przydziela serwer. Rozmowa żyje w pamięci strony.
- **Retencja.** Rozmowy bez kontaktu usuwamy po 30 dniach. Okres dla zapytań ustawia firma.

To podsumowanie do pracy, nie opinia prawna. Treść klauzul powinien zatwierdzić prawnik razem z regulaminem.

## 7. Wyniki i test A/B

- Lejek: odsłony stron z widżetem, rozpoczęte rozmowy, komplet odpowiedzi, zostawiony kontakt, zapytania pilne.
- Test: połowa odwiedzających widzi zwykły formularz, połowa kwalifikator. Konwersja to zapytania z kontaktem podzielone przez odsłony.
- Zwycięzcę ogłaszamy, gdy wynik testu z wynosi co najmniej 1,96 (pewność 95%). Wcześniej raport pisze „za mało danych”.
- Nie obiecujemy klientom procentu wzrostu. Pokazujemy wynik testu na ich stronie.

## 8. Etapy

1. **Kwalifikator bez AI.** Widżet, ustawienia pytań i punktów, lista zapytań z oceną, zapis do kontaktów z podstawą, sprawdzenie NIP, audyt sklepu, powiadomienie e-mail. Zależność: wysyłka e-maili z platformy.
2. **Czat AI i baza wiedzy.** Odpowiedzi z bazy, limity, streszczenie zapytania.
3. **Wyniki.** Lejek i test A/B.
4. **Demo z adresu.** Wpisujesz adres strony, platforma buduje bazę wiedzy i pokazuje gotowy kwalifikator. To też sposób na sprzedaż samej platformy.

Pilotaż na perfectdata.pl jest możliwy po etapie 1. Kod widżetu trafi na stronę dopiero po wyraźnej zgodzie właściciela.

Przed kwalifikatorem powstają dwie rzeczy wspólne dla całego panelu: wygląd PerfectData w platformie i wysyłka e-maili (powiadomienie o nowym zapytaniu).

## 9. Jak to wpiąć w perfectdata.pl

Stan strony na 19.11.2026: formularz „Umów bezpłatną konsultację” (imię i nazwisko, e-mail, telefon opcjonalnie, opis firmy), przycisk WhatsApp, bezpłatna diagnoza z 21 pytań liczona w przeglądarce, dwa płatne audyty (Express 249 zł netto, Data-First 1490 zł netto) i cennik usług.

| Miejsce na stronie | Co się zmienia | Uwagi |
|---|---|---|
| Formularz „Umów bezpłatną konsultację” | Kwalifikator zamiast formularza albo test A/B obu wersji | Pole „opis firmy” zastępują 4 pytania. WhatsApp zostaje jako druga droga. |
| Strony usług (strony WWW, Google Ads, Meta Ads, SEO i GEO) | Kwalifikator z zaznaczoną z góry potrzebą | Mniej kliknięć, bo wiadomo, o którą usługę chodzi. |
| Bezpłatna diagnoza | Po wyniku propozycja rozmowy o najsłabszym obszarze | Diagnoza obiecuje wynik bez podawania e-maila. Ta obietnica zostaje: kwalifikator jest dobrowolnym krokiem po wyniku. |
| Płatne audyty | Bez zmian | Automatyczne sprawdzenie strony przy zapytaniu nie zastępuje audytu wykonywanego przez człowieka. Może do niego prowadzić. |

Progi budżetu w pytaniach wynikają z cennika na stronie: Google Ads i Meta Ads od 997 zł miesięcznie, SEO od 1900 zł miesięcznie, GEO 1800 zł albo 900 zł jako dodatek, strony od 3500 zł. Po zmianie cennika trzeba je poprawić.

Kolejność: najpierw test A/B na jednej stronie usługi, potem reszta. Dzięki temu widać wynik na liczbach, zanim zniknie sprawdzony formularz.

### Wygląd widżetu i panelu

- **Widżet wygląda jak część strony.** Używa jej kolorów i czcionki: ciemny panel, ramka w odcieniu złota, złoty przycisk wysyłki, pola formularza jak w „Umów bezpłatną konsultację”. Odpowiedzi odwiedzającego mają złotą ramkę, wiadomości asystenta są na ciemniejszym tle.
- **Informacja o AI jest stałym paskiem pod nazwą firmy**, a nie drobnym drukiem na dole.
- **Style widżetu są odizolowane.** Nie zmieniają strony i strona nie zmienia ich.
- **Lista zapytań w panelu** to wiersze z dużą oceną z lewej: firma, potrzeba, poziom („Pilne”, „Do rozmowy”, „Niski priorytet”) i stan. Ostrzeżenie o kontrahencie jest widoczne bez rozwijania wiersza.
- **Ekran „Co zobaczy handlowiec”** pokazuje obok widżetu, jak ocena rośnie z każdą odpowiedzią. Służy do ustawiania pytań i punktów.

## 10. Testy

- Każda kombinacja odpowiedzi daje ocenę zgodną z tabelą zasad. Komplet punktów to 100 (sprawdza `build.py` w mockupie, potem test w platformie).
- Publiczne adresy: obca strona nie dostaje odpowiedzi, przekroczony limit zwraca błąd, za długie pola są odrzucane.
- Próba wstrzyknięcia HTML w każdym polu widżetu i jej wyświetlenie w panelu.
- Izolacja danych między firmami.
- Czat: pytanie spoza bazy wiedzy kończy się odmową, a tekst odwiedzającego nie zmienia zasad asystenta.

## 11. Decyzje dla właściciela

1. **Pytania i punkty dla PerfectData.** W mockupie są przykładowe: potrzeba, rodzaj firmy, termin, budżet, telefon. Punkty za poszczególne usługi to propozycja. Do przejrzenia z osobą, która odbiera zapytania.
2. **Pytanie o budżet.** Daje dobrą ocenę, ale część osób zniechęca. Można je zostawić jako opcjonalne.
3. **Zgoda na kod na perfectdata.pl** i wybór stron, na których widżet ma się pojawić.
4. **Czas odpowiedzi w podziękowaniu.** Widżet obiecuje kontakt w następnym dniu roboczym. Obietnica musi być realna.
