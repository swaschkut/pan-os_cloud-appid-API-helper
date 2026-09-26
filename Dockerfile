# ==========================================
# STAGE 1: Datenbank aus XML-Dateien bauen
# ==========================================
FROM php:8.2-cli AS builder

# SQLite-Abhängigkeiten installieren
RUN apt-get update && apt-get install -y \
    sqlite3 \
    libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite pdo

WORKDIR /build

# Quellcode & XML-Dateien kopieren
COPY . /build

# Erstelle die cloud_appid.db aus den vorhanden XML-Dateien
# Speicherlimit für den Builder auf unbegrenzt (-1) oder 2G setzen
RUN php -d memory_limit=-1 import_existing.php


# ==========================================
# STAGE 2: Schlankes Server-Image bereitstellen
# ==========================================
FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    sqlite3 \
    libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_sqlite pdo

WORKDIR /var/www/html

# Anwendungscode kopieren
COPY . /var/www/html/

# Die im ersten Schritt gebaute cloud_appid.db rüberkopieren
COPY --from=builder /build/cloud_appid.db /var/www/html/cloud_appid.db

# Aus Performance-Gründen: Die 100.000 XML-Dateien im Container wieder löschen,
# da die API jetzt nur noch die DB nutzt
RUN rm -rf /var/www/html/data

# Apache Konfiguration & Schreibrechte
RUN sed -i 's|/var/www/html|/var/www/html/src|g' /etc/apache2/sites-available/000-default.conf
RUN chown -R www-data:www-data /var/www/html && chmod -R 775 /var/www/html

EXPOSE 80