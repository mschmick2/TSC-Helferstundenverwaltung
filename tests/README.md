# VAES Test-Prozeduren

Dieses Verzeichnis enthaelt alle automatisierten und dokumentierten Tests fuer das VAES-System.

## Verzeichnisstruktur

```
tests/
├── Unit/                      # Unit-Tests (isolierte Komponenten)
│   ├── Models/                # UserTest, WorkEntryTest, RoleTest, CategoryTest
│   ├── Services/              # AuthServiceTest, WorkflowServiceTest, RateLimitServiceTest, SettingsServiceTest
│   └── Helpers/               # SecurityHelperTest, ViewHelperTest, VersionHelperTest
│
├── Integration/               # Integrationstests (Zusammenspiel mehrerer Komponenten)
│   ├── Auth/                  # AuthFlowTest
│   ├── Email/                 # EmailTest (erfordert MailHog)
│   └── Workflow/              # WorkflowIntegrationTest
│
├── Support/                   # Test-Hilfsklassen
│   └── MailHogHelper.php      # MailHog REST-API-Client
│
├── bootstrap.php              # PHPUnit-Setup (Autoloader, Session-Init)
├── MANUAL_TESTS.md            # Manuelle Testszenarien (Browser-basiert)
└── README.md                  # Diese Datei
```

## Test-Framework

**PHPUnit 10.5** (bereits via Composer installiert)

### PHPUnit-Konfiguration

Die Konfiguration liegt im **Projekt-Root** (nicht in `src/`): `phpunit.xml`

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="tests/bootstrap.php"
         colors="true"
         cacheDirectory=".phpunit.cache"
         executionOrder="depends,defects"
         failOnRisky="true"
         failOnWarning="true"
         stopOnFailure="false">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>
    <coverage>
        <include>
            <directory suffix=".php">src/app</directory>
        </include>
        <exclude>
            <directory>src/app/Views</directory>
        </exclude>
    </coverage>
    <php>
        <env name="APP_ENV" value="testing"/>
    </php>
</phpunit>
```

## Tests ausfuehren

**Wichtig:** Alle Befehle vom **Projekt-Root-Verzeichnis** ausfuehren (nicht aus `src/`).

```bash
# Alle Tests (Unit + Integration)
vendor/bin/phpunit

# Nur Unit-Tests
vendor/bin/phpunit --testsuite Unit

# Nur Integrationstests
vendor/bin/phpunit --testsuite Integration

# Einzelne Test-Datei
vendor/bin/phpunit tests/Unit/Models/UserTest.php

# Einzelne Test-Methode
vendor/bin/phpunit --filter test_self_approval_is_prevented

# Mit Coverage-Report (erfordert Xdebug oder PCOV)
vendor/bin/phpunit --coverage-html coverage/
```

## Aktuelle Test-Abdeckung (212 Tests)

| Bereich | Dateien | Tests |
|---------|---------|-------|
| Models (User, WorkEntry, Role, Category) | 4 | 47 |
| Services (Auth, Workflow, RateLimit, Settings) | 4 | 77 |
| Helpers (Security, View, Version) | 3 | 65 |
| Integration (Auth-Flow, Workflow-Lifecycle) | 2 | 23 |
| Integration (Email, erfordert MailHog) | 1 | 8 |
| **Gesamt** | **14** | **220** |

## Test-Namenskonventionen

| Typ | Dateiname | Methodenname |
|-----|-----------|--------------|
| Unit | `{Klasse}Test.php` | `test_{beschreibung_snake_case}` |
| Integration | `{Feature}Test.php` | `test_{szenario_snake_case}` |

## Mock-Pattern

```php
// Standard: createMock() mit method() / expects()
$this->userRepo = $this->createMock(UserRepository::class);
$this->userRepo->method('findByEmail')->willReturn($user);
$this->userRepo->expects($this->once())->method('updateStatus');
```

## Deployment-Struktur beachten

Die Anwendung hat zwei Verzeichnisstrukturen:

| | Entwicklung (lokal) | Testserver |
|---|---|---|
| **Web-Root** | `src/public/` | `/var/www/html/TSC-Helferstundenverwaltung/` |
| **URL** | `http://localhost:8000` | `https://192.168.3.98/helferstunden` |
| **Struktur** | Verschachtelt (`src/public/index.php`) | Flat (`index.php` neben `vendor/`) |
| **base_path** | `` (leer) | `/helferstunden` |
| **SSL** | Kein | Selbstsigniertes Zertifikat |

Die `index.php` erkennt die Struktur automatisch:
```php
$appRoot = is_dir(__DIR__ . '/vendor') ? __DIR__ : dirname(__DIR__);
```

## E-Mail-Integrationstests (MailHog)

Die E-Mail-Tests erfordern einen laufenden **MailHog**-Server. MailHog faengt E-Mails ab und stellt sie ueber eine REST-API zur Verfuegung.

### MailHog starten

```bash
# Via Docker (empfohlen)
docker run -d -p 1025:1025 -p 8025:8025 mailhog/mailhog

# Oder direkt (wenn installiert)
mailhog
```

- SMTP-Port: **1025** (Tests senden hierueber)
- Web-UI / API: **http://localhost:8025**

### E-Mail-Tests ausfuehren

```bash
# Nur E-Mail-Tests
vendor/bin/phpunit tests/Integration/Email/

# Einzelner Test
vendor/bin/phpunit --filter test_can_send_and_receive_email
```

Tests werden automatisch uebersprungen (`markTestSkipped`), wenn MailHog nicht erreichbar ist. Auf dem Testserver (Telekom SMTP) werden diese Tests daher uebersprungen.

## Test-Datenbank (fuer zukuenftige DB-Integrationstests)

```bash
# Datenbank erstellen
mysql -u root -e "CREATE DATABASE vaes_test;"

# Schema importieren
mysql -u root vaes_test < scripts/database/create_database.sql
```

## Weitere Testdokumentation

| Dokument | Pfad | Beschreibung |
|----------|------|--------------|
| Manuelle Tests | `tests/MANUAL_TESTS.md` | Browser-basierte Testszenarien (100+) |
| Testprotokoll | `docs/Testprotokoll_VAES.md` | Rollenbasiertes Testprotokoll & Automatisierungsplan |

---

*Stand: 2026-02-11*
