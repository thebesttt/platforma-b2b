#!/bin/sh
# Test dymny działającej aplikacji: sh tests/smoke.sh http://localhost:10000 [kod-zaproszenia] [token-cron]
set -eu
BASE="${1:-http://localhost:10000}"
CODE="${2:-}"
CRON="${3:-}"
JAR=$(mktemp)
HDR=$(mktemp)
fail() { echo "BŁĄD: $1" >&2; exit 1; }

curl -fsS "$BASE/zdrowie" | grep -q ok || fail "/zdrowie"
curl -fsS -D "$HDR" "$BASE/" | grep -q "Przetargi, oferty" || fail "strona główna"
grep -qi "^content-security-policy:" "$HDR" || fail "brak nagłówka Content-Security-Policy"
grep -qi "^x-frame-options: DENY" "$HDR" || fail "brak nagłówka X-Frame-Options"

# Pliki statyczne: adres z wersją ze strony głównej, poprawny typ.
CSS=$(curl -fsS "$BASE/" | sed -n 's/.*href="\(\/assets\/app\.css[^"]*\)".*/\1/p' | head -1)
[ -n "$CSS" ] || fail "brak arkusza stylów na stronie głównej"
curl -fsS -D "$HDR" -o /dev/null "$BASE$CSS" || fail "arkusz stylów nie ładuje się ($CSS)"
grep -qi "^content-type: text/css" "$HDR" || fail "arkusz stylów ma zły typ"
curl -fsS -o /dev/null "$BASE/assets/app.js" || fail "skrypt nie ładuje się"

# Pliki aplikacji nie mogą być dostępne z zewnątrz.
for p in /.env /../src/bootstrap.php /src/bootstrap.php /migrations/001_init.php /bin/console /storage/app.sqlite /.git/config; do
  code=$(curl -s -o /dev/null -w '%{http_code}' --path-as-is "$BASE$p")
  [ "$code" = "404" ] || [ "$code" = "400" ] || [ "$code" = "403" ] || fail "$p zwraca $code (powinno być niedostępne)"
done

# Zadania cykliczne tylko z tokenem.
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/cron/run")
[ "$code" = "401" ] || fail "cron bez tokenu powinien zwrócić 401 (jest $code)"
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/cron/run?token=zly")
[ "$code" = "401" ] || fail "cron ze złym tokenem powinien zwrócić 401 (jest $code)"

# Strony aplikacji wymagają logowania.
loc=$(curl -s -o /dev/null -w '%{redirect_url}' "$BASE/pulpit")
echo "$loc" | grep -q "/logowanie" || fail "/pulpit bez logowania powinien przekierować na logowanie"

# Rejestracja: zamknięta bez kodu (gdy podano kod), otwarta z kodem.
REG="$BASE/rejestracja"
if [ -n "$CODE" ]; then
  code=$(curl -s -o /dev/null -w '%{http_code}' "$REG")
  [ "$code" = "403" ] || fail "rejestracja bez kodu powinna zwrócić 403 (jest $code)"
  REG="$REG?kod=$CODE"
fi
TOKEN=$(curl -fsS -c "$JAR" -b "$JAR" "$REG" | sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' | head -1)
[ -n "$TOKEN" ] || fail "brak tokenu CSRF"

# Formularz bez tokenu CSRF jest odrzucany.
code=$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" --data-urlencode "email=x@example.com" "$BASE/logowanie")
[ "$code" = "403" ] || fail "POST bez tokenu CSRF powinien zwrócić 403 (jest $code)"

EMAIL="smoke$(date +%s)@example.com"
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -c "$JAR" -b "$JAR" \
  --data-urlencode "_csrf=$TOKEN" --data-urlencode "kod=$CODE" --data-urlencode "company=Smoke Test sp. z o.o." --data-urlencode "email=$EMAIL" \
  --data-urlencode "password=haslo-smoke-test" --data-urlencode "terms=1" "$BASE/rejestracja")
echo "$loc" | grep -q "/pulpit" || fail "rejestracja (przekierowanie: $loc)"
for p in /pulpit /przetargi /przetargi/szukaj /profile/nowy /oferty /biblioteka /kontrahenci /klienci /klienci/nowy /audyt /audyt/porownanie /reklamy /ustawienia; do
  code=$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" "$BASE$p")
  [ "$code" = "200" ] || fail "$p zwraca $code"
done

# Zapis przez formularz: profil przetargowy i pozycja biblioteki (pełna ścieżka przez Apache, PHP i PostgreSQL).
TOKEN=$(curl -fsS -c "$JAR" -b "$JAR" "$BASE/profile/nowy" | sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' | head -1)
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -c "$JAR" -b "$JAR" --data-urlencode "_csrf=$TOKEN" --data-urlencode "name=Roboty drogowe" \
  --data-urlencode "cpv=45233" --data-urlencode "keywords=chodnik" --data-urlencode "zrodla[]=bzp" --data-urlencode "active=1" "$BASE/profile")
echo "$loc" | grep -q "/przetargi?profil=" || fail "zapis profilu (przekierowanie: $loc)"
curl -fsS -c "$JAR" -b "$JAR" "$BASE/profile" | grep -q "Roboty drogowe" || fail "profil nie zapisał się z polskimi znakami"

# Import z tokenem (tylko gdy podano token): sprawdza połączenie z BZP/TED z tego serwera.
if [ -n "$CRON" ]; then
  code=$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $CRON" "$BASE/cron/run?task=watchlist")
  [ "$code" = "200" ] || fail "cron z tokenem zwraca $code"
fi
rm -f "$JAR" "$HDR"
echo "Test dymny OK: $BASE"
