#!/bin/sh
# Test dymny działającej aplikacji: sh tests/smoke.sh http://localhost:10000
set -eu
BASE="${1:-http://localhost:10000}"
JAR=$(mktemp)
fail() { echo "BŁĄD: $1" >&2; exit 1; }

curl -fsS "$BASE/zdrowie" | grep -q ok || fail "/zdrowie"
curl -fsS "$BASE/" | grep -q "Przetargi, oferty" || fail "strona główna"
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/cron/run")
[ "$code" = "401" ] || fail "cron bez tokenu powinien zwrócić 401 (jest $code)"

TOKEN=$(curl -fsS -c "$JAR" -b "$JAR" "$BASE/rejestracja" | sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' | head -1)
[ -n "$TOKEN" ] || fail "brak tokenu CSRF"
EMAIL="smoke$(date +%s)@example.com"
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -c "$JAR" -b "$JAR" \
  --data-urlencode "_csrf=$TOKEN" --data-urlencode "company=Smoke Test sp. z o.o." --data-urlencode "email=$EMAIL" \
  --data-urlencode "password=haslo-smoke-test" --data-urlencode "terms=1" "$BASE/rejestracja")
echo "$loc" | grep -q "/pulpit" || fail "rejestracja (przekierowanie: $loc)"
for p in /pulpit /przetargi /profile/nowy /oferty /biblioteka /kontrahenci /klienci /audyt /reklamy /ustawienia; do
  code=$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" "$BASE$p")
  [ "$code" = "200" ] || fail "$p zwraca $code"
done
rm -f "$JAR"
echo "Test dymny OK: $BASE"
