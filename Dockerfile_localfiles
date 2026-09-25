FROM php:8.2-cli-alpine

# Arbeitsverzeichnis festlegen
WORKDIR /app

# Die Index-Datei (cloud-appid.txt) sowie den Quellcode und die Daten kopieren
COPY cloud-appid.txt /app/cloud-appid.txt
COPY src/ /app/src/
COPY data/ /app/data/

# Port freigeben
EXPOSE 8080

# PHP built-in Server starten
CMD ["php", "-S", "0.0.0.0:8080", "-t", "src"]