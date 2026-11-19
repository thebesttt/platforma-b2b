#!/bin/sh
# Render podaje port w zmiennej PORT - Apache nasłuchuje właśnie na nim. Przed startem aktualizujemy schemat bazy.
set -e
PORT="${PORT:-10000}"
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

if [ -z "${DATABASE_URL:-}" ] && [ "${APP_ENV:-production}" = "production" ]; then
  echo "BŁĄD: brak zmiennej DATABASE_URL. Podłącz bazę PostgreSQL (w Render: Environment → DATABASE_URL)." >&2
  exit 1
fi

# Baza może startować chwilę dłużej niż aplikacja - kilka prób migracji.
i=0
until php /var/www/app/bin/console migrate; do
  i=$((i + 1))
  if [ "$i" -ge 10 ]; then
    echo "Migracja bazy nie powiodła się - sprawdź DATABASE_URL." >&2
    exit 1
  fi
  sleep 3
done
chown -R www-data:www-data /var/www/app/storage
exec "$@"
