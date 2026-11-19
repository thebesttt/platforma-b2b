# Platforma B2B - obraz dla Render.com (działa też na każdym hostingu z Dockerem).
FROM php:8.3-apache

# pdo_pgsql (PostgreSQL), zip (eksport .docx, odczyt .docx), poppler-utils (tekst z PDF - pdftotext).
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev libzip-dev poppler-utils \
 && docker-php-ext-install pdo_pgsql zip \
 && rm -rf /var/lib/apt/lists/*

RUN { \
      echo 'expose_php = Off'; \
      echo 'max_execution_time = 300'; \
      echo 'memory_limit = 384M'; \
      echo 'upload_max_filesize = 16M'; \
      echo 'post_max_size = 100M'; \
      echo 'max_file_uploads = 12'; \
      echo 'display_errors = Off'; \
      echo 'log_errors = On'; \
      echo 'error_log = /dev/stderr'; \
      echo 'variables_order = "EGPCS"'; \
      echo 'session.use_strict_mode = 1'; \
    } > /usr/local/etc/php/conf.d/platforma.ini \
 && a2enmod rewrite headers >/dev/null \
 && sed -ri 's#/var/www/html#/var/www/app/public#g' /etc/apache2/sites-available/000-default.conf \
 && { \
      echo 'ServerTokens Prod'; \
      echo 'ServerSignature Off'; \
      echo '<Directory /var/www/app/public>'; \
      echo '  Options -Indexes'; \
      echo '  AllowOverride All'; \
      echo '  Require all granted'; \
      echo '  DirectoryIndex index.php'; \
      echo '</Directory>'; \
      echo '# Mała instancja (512 MB RAM): przy natłoku zapytań kolejka zamiast dziesiątek procesów PHP i braku pamięci.'; \
      echo '<IfModule mpm_prefork_module>'; \
      echo '  StartServers 2'; \
      echo '  MinSpareServers 2'; \
      echo '  MaxSpareServers 4'; \
      echo '  MaxRequestWorkers ${APACHE_MAX_WORKERS}'; \
      echo '  MaxConnectionsPerChild 300'; \
      echo '</IfModule>'; \
    } > /etc/apache2/conf-enabled/platforma.conf

WORKDIR /var/www/app
COPY bin ./bin
COPY lib ./lib
COPY migrations ./migrations
COPY public ./public
COPY src ./src
COPY views ./views
COPY docker-entrypoint.sh /usr/local/bin/platforma-entrypoint
RUN chmod +x /usr/local/bin/platforma-entrypoint bin/console \
 && mkdir -p storage && chown www-data:www-data storage

# APACHE_MAX_WORKERS: ile zapytań obsługujemy jednocześnie. Na większej instancji można podnieść (ok. 1 na każde 40 MB RAM).
ENV PORT=10000 APP_ENV=production APACHE_MAX_WORKERS=12
EXPOSE 10000
ENTRYPOINT ["platforma-entrypoint"]
CMD ["apache2-foreground"]
