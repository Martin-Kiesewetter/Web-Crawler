# PHP Docker Anwendung

Eine PHP-Anwendung mit MariaDB, die in Docker läuft.

## Anforderungen

- Docker
- Docker Compose

## Installation & Start

1. Container starten:
```bash
docker-compose up -d
```

2. Container stoppen:
```bash
docker-compose down
```

3. Container neu bauen:
```bash
docker-compose up -d --build
```

## Services

- **PHP Anwendung**: http://localhost:8080
- **phpMyAdmin**: http://localhost:8081
- **MariaDB**: Port 3306

## Datenbank Zugangsdaten

- **Host**: mariadb
- **Datenbank**: app_database
- **Benutzer**: app_user
- **Passwort**: app_password
- **Root Passwort**: root_password

## Struktur

```
.
├── docker-compose.yml      # Docker Compose Konfiguration
├── Dockerfile             # PHP Container Image
├── start.sh               # Container Start-Script
├── init.sql               # Datenbank Initialisierung
├── config/
│   └── nginx/
│       └── default.conf   # Nginx Konfiguration
└── src/
    └── index.php          # Hauptanwendung
```

## Entwicklung

Die Anwendungsdateien befinden sich im `src/` Verzeichnis und werden als Volume in den Container gemountet, sodass Änderungen sofort sichtbar sind.
