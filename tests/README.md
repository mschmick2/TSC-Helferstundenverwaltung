# VAES Test-Prozeduren

Dieses Verzeichnis enthält alle Test-Prozeduren für das VAES-System.

## Verzeichnisstruktur

```
tests/
├── Unit/                 # Unit-Tests (einzelne Funktionen/Klassen)
│   ├── Models/
│   ├── Services/
│   └── Helpers/
│
├── Integration/          # Integrationstests (Zusammenspiel mehrerer Komponenten)
│   ├── Auth/
│   ├── Workflow/
│   └── Database/
│
├── Functional/           # Funktionale Tests (End-to-End)
│   ├── Login/
│   ├── WorkEntry/
│   └── Reporting/
│
├── fixtures/             # Test-Daten
│   ├── users.json
│   ├── categories.json
│   └── work_entries.json
│
└── README.md             # Diese Datei
```

## Test-Framework

Empfohlen: **PHPUnit** (https://phpunit.de)

### Installation

```bash
composer require --dev phpunit/phpunit ^10
```

### phpunit.xml

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php"
         colors="true"
         verbose="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>tests/Integration</directory>
        </testsuite>
        <testsuite name="Functional">
            <directory>tests/Functional</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

## Tests ausführen

```bash
# Alle Tests
./vendor/bin/phpunit

# Nur Unit-Tests
./vendor/bin/phpunit --testsuite Unit

# Einzelne Test-Datei
./vendor/bin/phpunit tests/Unit/Models/UserTest.php

# Mit Coverage-Report
./vendor/bin/phpunit --coverage-html coverage/
```

## Test-Namenskonventionen

| Typ | Dateiname | Klassenname |
|-----|-----------|-------------|
| Unit | `UserTest.php` | `UserTest` |
| Integration | `AuthFlowTest.php` | `AuthFlowTest` |
| Functional | `LoginProcessTest.php` | `LoginProcessTest` |

## Beispiel Unit-Test

```php
<?php
// tests/Unit/Models/UserTest.php

namespace Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use App\Models\User;

class UserTest extends TestCase
{
    public function test_user_has_full_name(): void
    {
        $user = new User([
            'vorname' => 'Max',
            'nachname' => 'Mustermann'
        ]);
        
        $this->assertEquals('Max Mustermann', $user->getFullName());
    }
    
    public function test_user_can_have_multiple_roles(): void
    {
        $user = new User();
        $user->addRole('mitglied');
        $user->addRole('erfasser');
        
        $this->assertTrue($user->hasRole('mitglied'));
        $this->assertTrue($user->hasRole('erfasser'));
        $this->assertFalse($user->hasRole('administrator'));
    }
}
```

## Test-Datenbank

Für Integrationstests wird eine separate Test-Datenbank empfohlen:

```php
// config/config.test.php
return [
    'database' => [
        'name' => 'vaes_test',
        // ...
    ],
];
```

### Test-Datenbank einrichten

```bash
# Datenbank erstellen
mysql -u root -e "CREATE DATABASE vaes_test;"

# Schema importieren
mysql -u root vaes_test < scripts/database/create_database.sql
```

## Manuelle Test-Checklisten

Für Features, die schwer automatisiert zu testen sind:

### Login-Tests
- [ ] Normaler Login mit korrektem Passwort
- [ ] Login mit falschem Passwort (Fehlermeldung)
- [ ] Login nach 5 Fehlversuchen (Sperrung)
- [ ] 2FA mit TOTP (Authenticator-App)
- [ ] 2FA mit E-Mail-Code
- [ ] Session-Timeout nach Inaktivität

### Workflow-Tests
- [ ] Antrag erstellen (Entwurf)
- [ ] Antrag einreichen
- [ ] Antrag zurückziehen
- [ ] Prüfer stellt Rückfrage
- [ ] Mitglied beantwortet Rückfrage
- [ ] Prüfer gibt frei
- [ ] Prüfer lehnt ab
- [ ] Prüfer gibt zurück zur Überarbeitung

### Multisession-Tests
- [ ] Login von zwei Geräten gleichzeitig
- [ ] Gleicher Antrag in zwei Tabs öffnen
- [ ] Bearbeitungssperre wird angezeigt
- [ ] Automatische Aktualisierung bei Tab-Wechsel

---

*Stand: 2025-02-09*
