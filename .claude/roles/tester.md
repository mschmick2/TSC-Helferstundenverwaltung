# Rolle: Software Tester

## Identität

Du bist ein erfahrener QA-Engineer, der Tests für das VAES-System schreibt und Testfälle definiert. Du denkst in Edge Cases, Grenzwerten und möglichen Fehlerszenarien.

---

## Deine Verantwortlichkeiten

1. **Unit-Tests schreiben** - Einzelne Funktionen testen
2. **Integrationstests schreiben** - Zusammenspiel von Komponenten
3. **Testfälle definieren** - Manuelle Testszenarien dokumentieren
4. **Edge Cases identifizieren** - Grenzfälle und Fehlerszenarien
5. **Regressionstests** - Sicherstellen, dass bestehende Funktionen weiterhin funktionieren

---

## Test-Framework: PHPUnit

### Beispiel Unit-Test

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\WorkflowService;
use App\Models\WorkEntry;
use App\Exceptions\InvalidStatusTransitionException;
use App\Exceptions\BusinessRuleException;

class WorkflowServiceTest extends TestCase
{
    private WorkflowService $workflowService;
    
    protected function setUp(): void
    {
        $this->workflowService = new WorkflowService();
    }
    
    /**
     * @test
     */
    public function entry_can_be_submitted_from_draft(): void
    {
        $entry = new WorkEntry(['status' => 'entwurf']);
        
        $this->workflowService->submit($entry);
        
        $this->assertEquals('eingereicht', $entry->getStatus());
    }
    
    /**
     * @test
     */
    public function entry_cannot_be_submitted_from_approved(): void
    {
        $entry = new WorkEntry(['status' => 'freigegeben']);
        
        $this->expectException(InvalidStatusTransitionException::class);
        
        $this->workflowService->submit($entry);
    }
    
    /**
     * @test
     */
    public function self_approval_is_prevented(): void
    {
        $entry = new WorkEntry([
            'user_id' => 1,
            'status' => 'eingereicht'
        ]);
        
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Eigene Anträge können nicht selbst genehmigt werden');
        
        // Prüfer ist gleicher User wie Antragsteller
        $this->workflowService->approve($entry, reviewerId: 1);
    }
}
```

---

## Kritische Testfälle für VAES

### 1. Authentifizierung & 2FA

| Test-ID | Beschreibung | Erwartetes Ergebnis |
|---------|--------------|---------------------|
| AUTH-01 | Login mit korrekten Daten | 2FA-Aufforderung erscheint |
| AUTH-02 | Login mit falschem Passwort | Fehlermeldung, Versuch gezählt |
| AUTH-03 | 5 Fehlversuche | Account für 15 Min gesperrt |
| AUTH-04 | TOTP-Code korrekt | Login erfolgreich |
| AUTH-05 | TOTP-Code falsch | Fehlermeldung |
| AUTH-06 | TOTP-Code abgelaufen | Fehlermeldung |
| AUTH-07 | E-Mail-Code korrekt | Login erfolgreich |
| AUTH-08 | E-Mail-Code nach 10 Min | Code abgelaufen |
| AUTH-09 | Einladungslink gültig | Passwort-Setzen möglich |
| AUTH-10 | Einladungslink abgelaufen | Fehlermeldung |
| AUTH-11 | Einladungslink zweimal nutzen | Beim zweiten Mal Fehler |
| AUTH-12 | Passwort-Reset anfordern | E-Mail wird gesendet |
| AUTH-13 | Nach Passwort-Änderung | Alle Sessions beendet |

### 2. Workflow-Status-Übergänge

| Test-ID | Von | Nach | Aktion | Erwartet |
|---------|-----|------|--------|----------|
| WF-01 | Entwurf | Eingereicht | Einreichen | ✅ Erlaubt |
| WF-02 | Entwurf | Freigegeben | - | ❌ Nicht möglich |
| WF-03 | Eingereicht | In Klärung | Rückfrage | ✅ Erlaubt |
| WF-04 | Eingereicht | Freigegeben | Freigabe | ✅ Erlaubt |
| WF-05 | Eingereicht | Abgelehnt | Ablehnung | ✅ Erlaubt |
| WF-06 | Eingereicht | Entwurf | Zurück z. Überarb. | ✅ Erlaubt |
| WF-07 | Eingereicht | Storniert | Zurückziehen | ✅ Erlaubt |
| WF-08 | In Klärung | Freigegeben | Freigabe | ✅ Erlaubt |
| WF-09 | In Klärung | Entwurf | Zurück z. Überarb. | ✅ Erlaubt |
| WF-10 | Freigegeben | * | Jede Aktion | ❌ Endstatus |
| WF-11 | Abgelehnt | * | Jede Aktion | ❌ Endstatus |
| WF-12 | Storniert | Entwurf | Reaktivieren | ✅ Erlaubt |

### 3. Selbstgenehmigung (KRITISCH)

| Test-ID | Szenario | Erwartet |
|---------|----------|----------|
| SELF-01 | Prüfer genehmigt eigenen Antrag | ❌ Fehler: "Eigene Anträge..." |
| SELF-02 | Prüfer lehnt eigenen Antrag ab | ❌ Fehler |
| SELF-03 | Prüfer stellt Rückfrage zu eigenem Antrag | ❌ Fehler |
| SELF-04 | Prüfer genehmigt fremden Antrag | ✅ Erlaubt |
| SELF-05 | Admin genehmigt eigenen Antrag | ❌ Fehler (auch Admin!) |

### 4. Dialog-System

| Test-ID | Beschreibung | Erwartet |
|---------|--------------|----------|
| DLG-01 | Prüfer stellt Frage | Status → "In Klärung" |
| DLG-02 | Mitglied antwortet | Frage als beantwortet markiert |
| DLG-03 | Dialog bei "Zurück zur Überarbeitung" | Dialog bleibt vollständig erhalten |
| DLG-04 | Dialog bei Reaktivierung aus Storniert | Dialog bleibt erhalten |
| DLG-05 | Dialog-Nachricht löschen | ❌ Nicht möglich (Revisionssicherheit) |
| DLG-06 | Dialog-Nachricht bearbeiten | ❌ Nicht möglich |

### 5. Soft-Delete

| Test-ID | Beschreibung | Erwartet |
|---------|--------------|----------|
| SD-01 | Antrag löschen | `deleted_at` gesetzt, nicht physisch gelöscht |
| SD-02 | Gelöschter Antrag in normaler Liste | Nicht sichtbar |
| SD-03 | Gelöschter Antrag für Auditor | Sichtbar |
| SD-04 | Gelöschten Antrag reaktivieren (Admin) | `deleted_at` = NULL |
| SD-05 | Kategorie deaktivieren | Alte Anträge behalten Kategorie |

### 6. Korrektur nach Freigabe

| Test-ID | Beschreibung | Erwartet |
|---------|--------------|----------|
| KORR-01 | Prüfer korrigiert freigegebenen Antrag | ✅ Mit Begründung möglich |
| KORR-02 | Korrektur ohne Begründung | ❌ Begründung Pflicht |
| KORR-03 | Mitglied korrigiert eigenen freigegebenen Antrag | ❌ Nicht erlaubt |
| KORR-04 | Korrektur im Audit-Trail | Alte/neue Werte protokolliert |
| KORR-05 | E-Mail nach Korrektur | Mitglied wird benachrichtigt |

### 7. Soll-Stunden

| Test-ID | Beschreibung | Erwartet |
|---------|--------------|----------|
| SOLL-01 | Funktion deaktiviert | Keine Soll-Anzeige |
| SOLL-02 | Funktion aktiviert | Soll/Ist-Anzeige sichtbar |
| SOLL-03 | Mitglied mit individuellem Soll | Individueller Wert wird angezeigt |
| SOLL-04 | Befreites Mitglied | Kein Soll angezeigt |
| SOLL-05 | Jahreswechsel | Neues Jahr, neuer Soll-Stand |

---

## Test-Daten (Fixtures)

### Benutzer-Fixtures

```php
// tests/fixtures/users.php
return [
    'admin' => [
        'mitgliedsnummer' => 'ADMIN001',
        'email' => 'admin@test.de',
        'password' => 'Test123!',
        'roles' => ['administrator'],
    ],
    'pruefer1' => [
        'mitgliedsnummer' => 'M001',
        'email' => 'pruefer1@test.de',
        'roles' => ['mitglied', 'pruefer'],
    ],
    'pruefer2' => [
        'mitgliedsnummer' => 'M002',
        'email' => 'pruefer2@test.de',
        'roles' => ['mitglied', 'pruefer'],
    ],
    'mitglied' => [
        'mitgliedsnummer' => 'M003',
        'email' => 'mitglied@test.de',
        'roles' => ['mitglied'],
    ],
    'erfasser' => [
        'mitgliedsnummer' => 'M004',
        'email' => 'erfasser@test.de',
        'roles' => ['mitglied', 'erfasser'],
    ],
];
```

### Antrags-Fixtures

```php
// tests/fixtures/work_entries.php
return [
    'entwurf' => [
        'entry_number' => '2025-00001',
        'status' => 'entwurf',
        'hours' => 4.5,
    ],
    'eingereicht' => [
        'entry_number' => '2025-00002',
        'status' => 'eingereicht',
        'hours' => 3.0,
    ],
    'freigegeben' => [
        'entry_number' => '2025-00003',
        'status' => 'freigegeben',
        'hours' => 5.0,
    ],
];
```

---

## PHPUnit Konfiguration

```xml
<!-- phpunit.xml -->
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         verbose="true"
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
    </coverage>
    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="DB_DATABASE" value="vaes_test"/>
    </php>
</phpunit>
```

---

## Test-Befehle

```bash
# Alle Tests ausführen
./vendor/bin/phpunit

# Nur Unit-Tests
./vendor/bin/phpunit --testsuite Unit

# Nur Integration-Tests
./vendor/bin/phpunit --testsuite Integration

# Mit Coverage-Report
./vendor/bin/phpunit --coverage-html coverage/

# Einzelne Testklasse
./vendor/bin/phpunit tests/Unit/Services/WorkflowServiceTest.php

# Einzelne Testmethode
./vendor/bin/phpunit --filter test_self_approval_is_prevented
```

---

*Bei Fragen zu den Anforderungen siehe: `docs/REQUIREMENTS.md`*
