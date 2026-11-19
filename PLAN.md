# Plan działania

Stan na 19.11.2026. Kolejność wynika z jednej zasady: najpierw to, bez czego nie można pokazać aplikacji klientom, potem pilotaż, sprzedaż i rozbudowa.

## Etap 0. Uruchomienie (ok. 2 godziny pracy właściciela)

1. W Render utwórz usługę przez New → Blueprint, z repozytorium `platforma-b2b`.
2. W Render uzupełnij zmienne `APP_URL`, `ANTHROPIC_API_KEY` i `ADMIN_EMAILS` (swój e-mail).
3. Zdecyduj o bazie. Darmowa baza Render wygasa po 30 dniach, potem jest 14 dni na przejście na płatny plan, a po tym czasie dane są kasowane. Od razu wybierz płatny plan Render albo bazę w Neon.
4. Załóż konto z linku `/rejestracja?kod=<SIGNUP_CODE>`. Kod jest w Render, w zakładce Environment. Rejestracja bez kodu jest zamknięta.
5. W Ustawieniach kliknij „Sprawdź połączenie z AI”. To pierwsze prawdziwe zapytanie do modelu.
6. W GitHubie dodaj sekret `DATABASE_URL` (zewnętrzny adres bazy). Włącza codzienny import.
7. Uruchom ręcznie workflow „Codzienny import” i sprawdź, czy przetargi pojawiły się w aplikacji.

Do sprawdzenia po wdrożeniu: czy rejestr decyzji UOKiK przyjmuje połączenia z serwera we Frankfurcie. Jeśli nie, decyzje wgrywa się ręcznie w Ustawienia → Decyzje UOKiK (minuta raz w tygodniu).

## Etap 1. Braki przed pilotażem (2-3 tygodnie)

Prace programistyczne:

1. **E-maile z platformy.** Dziś aplikacja nie wysyła żadnego e-maila. Potrzebne: codzienny mail z nowymi przetargami, alerty o kontrahentach, reset hasła. Wymaga domeny i usługi wysyłkowej ze zweryfikowaną domeną.
2. **Konto i zespół:** reset hasła, zaproszenia do zespołu e-mailem, potwierdzenie adresu e-mail przy rejestracji, usunięcie konta i eksport danych (RODO).
3. **AI:** limit wydatków na AI dla każdej firmy. Zużycie tokenów jest już zapisywane w dzienniku.
4. **Kopie i awarie:** kopie zapasowe bazy. Powiadomienie o nieudanym imporcie już działa (GitHub wysyła e-mail).
5. **Firmy spoza wykazu VAT:** integracja z CEIDG, żeby dało się sprawdzić jednoosobowe firmy zwolnione z VAT.

Decyzje właściciela, które blokują dalsze kroki:

- **Nazwa i domena** zamiast „[Nazwa]”.
- **Branża na start.** Dane demo są pod roboty drogowe i budowlane. Jedna branża ułatwia dopracowanie dopasowań i sprzedaż.
- **5-10 firm do pilotażu.**
- **Prawnik.** Potrzebny do:
  - regulaminu i polityki prywatności (po ich przygotowaniu wpisz adresy w `TERMS_URL` i `PRIVACY_URL`),
  - umowy powierzenia przetwarzania danych: klienci wpisują dane osób kontaktowych, więc platforma przetwarza je w ich imieniu, a podwykonawcami są Render, Anthropic i usługa e-mail,
  - opinii o module wiadomości (art. 398 Prawa komunikacji elektronicznej) i o module należności (art. 15 i 15a ustawy o udostępnianiu informacji gospodarczych).

## Etap 2. Pilotaż (4-6 tygodni)

- Bezpłatny dostęp w zamian za krótką rozmowę co tydzień. Konta z linku z kodem zaproszenia.
- Co mierzyć:
  - ile dopasowanych przetargów klient otwiera,
  - ile jest trafień nietrafionych,
  - ile ofert powstaje i ile czasu oszczędza szkic,
  - których modułów nikt nie używa.
- Poprawki co tydzień.
- Pod koniec pytanie, ile klient zapłaciłby za to miesięcznie.

## Etap 3. Start sprzedaży (od ok. 8. tygodnia)

1. **Cennik i pakiety** z limitami: profile przetargowe, analizy AI, obserwowane firmy, osoby w zespole.
2. **Płatności:** bramka z płatnościami cyklicznymi i automatyczne faktury VAT.
3. **Strona sprzedażowa:** cennik, 2-minutowe nagranie demo i opinie z pilotażu (prawdziwe, za zgodą firm).
4. **Darmowe sprawdzenie kontrahenta po NIP** jako sposób na zdobywanie kontaktów, na wzór PrestaCheck.
5. **Kanały:** LinkedIn, stowarzyszenia branżowe i biura rachunkowe jako partnerzy polecający.
6. **Otwarta rejestracja** (`SIGNUP_OPEN=1`) dopiero z regulaminem i polityką prywatności.

## Etap 4. Rozwój produktu (kolejność według wartości)

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

## Co zostało sprawdzone przed uruchomieniem (19.11.2026)

- 61 testów automatycznych na SQLite i PostgreSQL, w tym próba wstrzyknięcia HTML w każdym polu na każdej stronie, wszystkie akcje zapisu i izolacja danych między firmami.
- Obraz Docker z bazą PostgreSQL: test dymny w GitHub Actions (nagłówki bezpieczeństwa, pliki statyczne, rejestracja z kodem, zapis formularza, zadania cykliczne, brak startu bez bazy).
- Próba generalna na prawdziwych danych: import 1331 ogłoszeń z BZP i 861 z TED z 2 dni bez błędów i duplikatów, parser na 40 losowych ogłoszeniach, 75 stron aplikacji i 6 szkiców ofert na prawdziwych ogłoszeniach, karty 10 prawdziwych firm (w tym poprawnie wykryta spółka w likwidacji).
- Test w przeglądarce całej ścieżki użytkownika na danych demonstracyjnych.
- Przepisy cytowane w aplikacji sprawdzone w źródłach.

Czego nie dało się sprawdzić przed wdrożeniem:

- prawdziwego zapytania do modelu AI (wymaga klucza API),
- połączenia z rejestrem UOKiK z serwera Render,
- zachowania na samej platformie Render (obraz jest ten sam co w teście, ale infrastruktura inna).
