# Web Crawler

Eine PHP-Anwendung mit MariaDB, die in Docker läuft.

## Copyright & Lizenz

**Copyright © 2025 Martin Kiesewetter**

- **Autor:** Martin Kiesewetter
- **E-Mail:** mki@kies-media.de
- **Website:** [https://kies-media.de](https://kies-media.de)

---

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
├── Dockerfile              # PHP Container Image
├── config/                 # Konfigurationsdateien
│   ├── docker/
│   │   ├── init.sql        # Datenbank Initialisierung
│   │   └── start.sh        # Container Start-Script (unused)
│   └── nginx/
│       └── default.conf    # Nginx Konfiguration
├── src/                    # Anwendungscode
│   ├── api.php
│   ├── index.php
│   ├── classes/
│   └── crawler-worker.php
├── tests/                  # Test Suite
│   ├── Unit/
│   └── Integration/
├── phpstan.neon            # PHPStan Konfiguration
└── phpcs.xml               # PHPCS Konfiguration
```

## Entwicklung

Die Anwendungsdateien befinden sich im `src/` Verzeichnis und werden als Volume in den Container gemountet, sodass Änderungen sofort sichtbar sind.

## Tests & Code-Qualität

### Unit Tests ausführen

Die Anwendung verwendet PHPUnit für Unit- und Integrationstests:

```bash
# Alle Tests ausführen
docker-compose exec php sh -c "php /var/www/html/vendor/bin/phpunit /var/www/tests/"

# Alternative mit Composer-Script
docker-compose exec php composer test
```

Die Tests befinden sich in:
- `tests/Unit/` - Unit Tests
- `tests/Integration/` - Integration Tests

### Statische Code-Analyse mit PHPStan

PHPStan ist auf Level 8 (höchstes Level) konfiguriert und analysiert den gesamten Code:

```bash
# PHPStan ausführen
docker-compose exec php sh -c "php -d memory_limit=512M /var/www/html/vendor/bin/phpstan analyse -c /var/www/phpstan.neon"

# Alternative mit Composer-Script
docker-compose exec php composer phpstan
```

**PHPStan Konfiguration:**
- Level: 8 (strictest)
- Analysierte Pfade: `src/` und `tests/`
- Ausgeschlossen: `vendor/` Ordner
- Konfigurationsdatei: `phpstan.neon`

### Code Style Prüfung mit PHP_CodeSniffer

PHP_CodeSniffer (PHPCS) prüft den Code gegen PSR-12 Standards:

```bash
# Code Style prüfen
docker-compose exec php composer phpcs

# Code Style automatisch korrigieren
docker-compose exec php composer phpcbf
```

**PHPCS Konfiguration:**
- Standard: PSR-12
- Analysierte Pfade: `src/` und `tests/`
- Ausgeschlossen: `vendor/` Ordner
- Auto-Fix verfügbar mit `phpcbf`
