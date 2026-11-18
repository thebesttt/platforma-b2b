# Platforma B2B: przetargi, oferty, kontrahenci

Aplikacja SaaS dla małych i średnich firm, które startują w zamówieniach publicznych i sprzedają innym firmom. Nazwa robocza produktu to `[Nazwa]`, ustawiana zmienną `APP_NAME`.

## Moduły

| Moduł | Co robi | Źródła danych |
|---|---|---|
| Przetargi | Codzienny import ogłoszeń i dopasowanie do profili firmy (CPV, słowa kluczowe, województwa, wartość) z uzasadnieniem oceny. Karta przetargu pokazuje wadium, kryteria, terminy i warunki z treści ogłoszenia, każdy punkt ze wskazaniem źródła. Terminy można dodać do kalendarza (.ics). | BZP (API e-Zamówień), TED API v3 |
| Analiza SWZ (AI) | Wgrywasz SWZ, OPZ i wzór umowy (PDF, DOCX, TXT). AI wypisuje warunki udziału, kary, płatności i ryzyka ze wskazaniem rozdziału. Odpowiada też na pytania do dokumentacji. | Dokumenty użytkownika, Claude API |
| Oferty i wnioski | Szkic oferty: formularz, sposób realizacji, doświadczenie, zespół i lista dokumentów, z danych ogłoszenia i biblioteki firmy. Wnioski o dotacje mają osobne sekcje i korzystają z regulaminu naboru. Zdania bez źródła są oznaczone ⟦sprawdź⟧. Eksport do .docx. | Ogłoszenie, SWZ, regulamin, biblioteka firmy |
| Kontrahenci | Karta firmy po NIP z oceną ryzyka płatniczego 0–100 i listą sygnałów. Firmę można obserwować, a zmiany w rejestrach wywołują alerty. Przy należnościach są wezwanie do zapłaty (.docx) i lista warunków wpisu do BIG. | Biała lista VAT, KRS, rejestr decyzji UOKiK (zatory płatnicze) |
| Klienci i wiadomości | Kontakty z zapisaną podstawą kontaktu, szkice wiadomości (regułowe lub AI z treści strony odbiorcy) oraz ocena ryzyka prawnego przy każdym rodzaju wiadomości. Przy ryzyku wysokim użytkownik musi świadomie je potwierdzić, a potwierdzenie trafia do historii. Wysyłka idzie z własnej skrzynki użytkownika (mailto lub .eml). | Dane użytkownika |
| Audyt strony | Bezpieczeństwo, szybkość serwera, SEO techniczne, analityka i RODO. Możesz zestawić swoją stronę z konkurentami. To ten sam silnik co w PrestaCheck (`lib/prestacheck`). | Publiczne strony, opcjonalnie Google PageSpeed |
| Reklamy | Ekran informacyjny. Integracja z Google Ads i Meta czeka na zatwierdzenie dostępu do API. | – |

### Zasady wbudowane w produkt

- **Wiadomości.** Platforma pokazuje ryzyko prawne każdego rodzaju wiadomości. Podstawą jest art. 398 Prawa komunikacji elektronicznej, kary to art. 444 i 448. Decyzję podejmuje klient.
  - Przy ryzyku wysokim wymagamy potwierdzenia. Zapisujemy wtedy, kto potwierdził, kiedy, z jakiego IP i jakim tekstem (`messages.ack_*` i `audit_log`).
  - Jedyna twarda blokada dotyczy kontaktu, który zgłosił sprzeciw (art. 21 RODO). Takiemu kontaktowi nie da się wysłać ofert ani próśb o zgodę.
- **Należności.** Długi zgłoszone przez użytkownika są widoczne tylko dla jego firmy. Platforma nie udostępnia ich innym użytkownikom, bo zakazuje tego ustawa o udostępnianiu informacji gospodarczych. Wpisu do BIG dokonuje się u biura, a aplikacja sprawdza tylko warunki z art. 14:
  - co najmniej 500 zł,
  - co najmniej 30 dni po terminie,
  - wezwanie wysłane co najmniej miesiąc wcześniej, z nazwą i adresem biura.
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
2. W ustawieniach usługi `platforma-b2b` uzupełnij zmienne:
   - `APP_URL` (adres usługi),
   - `ANTHROPIC_API_KEY` (funkcje AI),
   - opcjonalnie `PSI_KEY` (PageSpeed) i `APP_NAME`.
3. Po pierwszym wdrożeniu załóż konto przez `/rejestracja`. Jeśli wolisz zamknąć rejestrację, ustaw `SIGNUP_OPEN=0` i dodaj konto poleceniem `php bin/console user:create` w zakładce Shell.
4. Włącz codzienny import w GitHub: **Settings → Secrets and variables → Actions**. Wybierz jeden wariant:
   - `DATABASE_URL`: zewnętrzny adres bazy z Render („External Database URL”). Import działa wtedy w GitHub Actions. Ten wariant jest zalecany.
   - `APP_URL` i `CRON_TOKEN` (wartość z Render). Workflow wywoła wtedy `POST /cron/run`.

Darmowa baza PostgreSQL na Render ma ograniczony czas życia. Przed pierwszymi klientami przejdź na płatny plan albo podłącz bazę z Neon (`DATABASE_URL=postgres://…?sslmode=require`). Darmowa instancja aplikacji usypia po okresie bezczynności, więc pierwsze wejście trwa dłużej.

### Zmienne środowiskowe

| Zmienna | Opis |
|---|---|
| `DATABASE_URL` | `postgres://user:haslo@host:5432/baza` (opcjonalnie `?sslmode=require`). Pusta oznacza SQLite. |
| `APP_SECRET` | Losowy sekret (Render generuje go sam). |
| `APP_NAME`, `APP_URL` | Nazwa produktu i adres publiczny. |
| `ANTHROPIC_API_KEY`, `AI_MODEL` | Claude API, domyślnie `claude-sonnet-5-5`. `AI_MAX_INPUT_CHARS` ogranicza długość dokumentacji wysyłanej do modelu. |
| `CRON_TOKEN` | Token do `/cron/run`, przekazywany w nagłówku `Authorization: Bearer …`. |
| `PSI_KEY` | Klucz Google PageSpeed Insights (audyt szybkości). |
| `SIGNUP_OPEN` | `1` oznacza otwartą rejestrację, `0` zamkniętą. |
| `UPLOAD_MAX_MB` | Limit rozmiaru dokumentu, domyślnie 15. |

## Zadania cykliczne

Komenda `php bin/console daily`, endpoint `/cron/run?task=daily` i workflow `.github/workflows/daily.yml` uruchamiają to samo zadanie:

1. synchronizację decyzji UOKiK (raz w tygodniu),
2. import ogłoszeń z BZP i TED od ostatniego importu,
3. dopasowanie ogłoszeń do profili,
4. odświeżenie obserwowanych kontrahentów (alerty o zmianach),
5. sprzątanie: sesje, wygasłe wpisy i ogłoszenia po terminie starsze niż 120 dni bez ofert.

## Testy

```bash
php tests/run.php                                           # 40 testów, SQLite, bez sieci
TEST_DATABASE_URL=postgres://… php tests/run.php            # te same testy na PostgreSQL
php tests/live_contract.php                                 # prawdziwe API: BZP, TED, VAT, KRS, UOKiK
sh tests/smoke.sh http://localhost:10000                    # test dymny działającej instancji
```

Workflow `ci.yml` uruchamia testy na SQLite i PostgreSQL oraz buduje obraz Docker i robi test dymny kontenera z bazą. Workflow `live-api.yml` co tydzień sprawdza, czy publiczne API nie zmieniły formatu.

## Plan rozwoju

- Szacunek terminu płacenia dostawcom (rotacja zobowiązań) ze sprawozdań finansowych w Repozytorium Dokumentów Finansowych KRS.
- Krajowy Rejestr Zadłużonych (postępowania upadłościowe i restrukturyzacyjne) jako dodatkowy sygnał.
- Integracja z biurem informacji gospodarczej przez partnera (wpis długu, raport o kontrahencie).
- Google Ads API i Meta Marketing API po zatwierdzeniu dostępu.
- Wysyłka wiadomości przez skrzynkę użytkownika (Gmail i Microsoft 365 przez OAuth) oraz przypomnienia o follow-upie.
- Płatności i plany abonamentowe.
