# REQUIREMENTS.md - VAES Anforderungsspezifikation

**Version:** 1.3  
**Stand:** 2025-02-09  
**Status:** Review abgeschlossen, bereit für Entwicklung

---

## Inhaltsverzeichnis

1. [Projektübersicht](#1-projektübersicht)
2. [Benutzerrollen](#2-benutzerrollen)
3. [Authentifizierung](#3-authentifizierung)
4. [Mitgliederverwaltung](#4-mitgliederverwaltung)
5. [Arbeitsstunden-Erfassung](#5-arbeitsstunden-erfassung)
6. [Kategorien-Verwaltung](#6-kategorien-verwaltung)
7. [Antrags-Workflow](#7-antrags-workflow)
8. [Dialog-System](#8-dialog-system)
9. [E-Mail-Benachrichtigungen](#9-e-mail-benachrichtigungen)
10. [Reporting](#10-reporting)
11. [Soll-Stunden-Verwaltung](#11-soll-stunden-verwaltung)
12. [Datenintegrität](#12-datenintegrität)
13. [Technische Anforderungen](#13-technische-anforderungen)
14. [Admin-Konfiguration](#14-admin-konfiguration)
15. [Datenmodell](#15-datenmodell)

---

## 1. Projektübersicht

### 1.1 Ziel
Webbasiertes System zur Erfassung und Verwaltung von ehrenamtlichen Arbeitsstunden für Vereinsmitglieder.

### 1.2 Zielumgebung

| Komponente | Spezifikation |
|------------|---------------|
| Hosting | Strato Shared Webhosting |
| Webserver | Apache |
| PHP | 8.x |
| Datenbank | MySQL 8.4 |
| **Einschränkungen** | Kein SSH, keine Cron-Jobs, kein Node.js |

### 1.3 Technologie-Stack

| Komponente | Technologie |
|------------|-------------|
| Backend | Slim 4 (PHP Micro-Framework) |
| Frontend CSS | Bootstrap 5 |
| Frontend JS | Vanilla JavaScript + Fetch API |
| DB-Zugriff | PDO mit Prepared Statements |
| 2FA | OTPHP Library |
| E-Mail | PHPMailer |
| PDF | TCPDF |

---

## 2. Benutzerrollen

### 2.1 Rollendefinition

| Rolle | Beschreibung |
|-------|--------------|
| **Mitglied** | Reguläres Vereinsmitglied - kann eigene Arbeitsstunden erfassen |
| **Erfasser** | Kann zusätzlich Stunden für ALLE anderen Mitglieder eintragen |
| **Prüfer** | Kann Anträge freigeben/ablehnen, Rückfragen stellen. **KANN EIGENE ANTRÄGE NICHT GENEHMIGEN!** |
| **Auditor** | Lesender Zugriff auf alle Vorgänge inkl. gelöschter Daten |
| **Administrator** | Vollzugriff auf alle Funktionen |

### 2.2 Berechtigungsmatrix

| Funktion | Mitglied | Erfasser | Prüfer | Auditor | Admin |
|----------|:--------:|:--------:|:------:|:-------:|:-----:|
| Eigene Stunden erfassen | ✅ | ✅ | ✅ | ❌ | ✅ |
| Stunden für andere erfassen | ❌ | ✅ | ✅ | ❌ | ✅ |
| Eigene Anträge einsehen | ✅ | ✅ | ✅ | ✅ | ✅ |
| Alle Anträge einsehen | ❌ | ❌ | ✅ | ✅ | ✅ |
| Anträge freigeben/ablehnen | ❌ | ❌ | ✅* | ❌ | ✅* |
| Gelöschte Daten einsehen | ❌ | ❌ | ❌ | ✅ | ✅ |
| Kategorien verwalten | ❌ | ❌ | ❌ | ❌ | ✅ |
| Benutzer verwalten | ❌ | ❌ | ❌ | ❌ | ✅ |
| Systemkonfiguration | ❌ | ❌ | ❌ | ❌ | ✅ |

*\* Außer eigene Anträge!*

### 2.3 Mehrfach-Rollen

- Ein Benutzer kann mehrere Rollen gleichzeitig haben
- Beispiel: Ein Mitglied kann auch Prüfer sein
- Die Berechtigungen addieren sich

---

## 3. Authentifizierung

### 3.1 Login-Verfahren

```
REQ-AUTH-001: Anmeldung mit Benutzername/E-Mail und Passwort
REQ-AUTH-002: 2FA ist verpflichtend für alle Benutzer
REQ-AUTH-003: Passwort-Anforderungen: Min. 8 Zeichen, Groß-/Kleinbuchstaben, Ziffern
```

### 3.2 Zwei-Faktor-Authentifizierung

```
REQ-2FA-001: Benutzer wählt bei Ersteinrichtung zwischen TOTP oder E-Mail-Code
REQ-2FA-002: TOTP: QR-Code für Authenticator-Apps
REQ-2FA-003: E-Mail-Code: 6-stellig, gültig für 10 Minuten
```

### 3.3 Session-Management

```
REQ-SESSION-001: Unbegrenzte gleichzeitige Sessions pro Benutzer (Multi-Device)
REQ-SESSION-002: Multitab-Unterstützung innerhalb einer Browser-Session
REQ-SESSION-003: CSRF-Token für alle schreibenden Operationen
REQ-SESSION-004: Session-Timeout konfigurierbar (Standard: 30 Minuten)
REQ-SESSION-005: Bei Passwortänderung: ALLE Sessions beenden
```

### 3.4 Account-Sperrung

```
REQ-LOCK-001: Nach 5 Fehlversuchen: Account für 15 Minuten sperren
REQ-LOCK-002: Fehlversuche werden protokolliert (IP, Zeitstempel)
```

### 3.5 Passwort-Reset

```
REQ-RESET-001: "Passwort vergessen"-Link auf Login-Seite
REQ-RESET-002: Reset-Link per E-Mail (Token, gültig 1 Stunde)
REQ-RESET-003: Nach Reset: Alle Sessions beenden
REQ-RESET-004: Vorgang im Audit-Trail protokollieren
```

---

## 4. Mitgliederverwaltung

### 4.1 Import

```
REQ-IMPORT-001: Mitglieder werden ausschließlich per CSV-Import angelegt
REQ-IMPORT-002: Keine Selbstregistrierung
REQ-IMPORT-003: Import nur durch Administrator
```

### 4.2 CSV-Format

| Feld | Typ | Pflicht |
|------|-----|:-------:|
| mitgliedsnummer | String | ✅ |
| nachname | String | ✅ |
| vorname | String | ✅ |
| email | String | ✅ |
| strasse | String | ❌ |
| plz | String | ❌ |
| ort | String | ❌ |
| telefon | String | ❌ |
| eintrittsdatum | Datum | ❌ |

### 4.3 Import-Verhalten

```
REQ-IMPORT-004: Dublettenerkennung anhand Mitgliedsnummer
REQ-IMPORT-005: Bei vorhandener Mitgliedsnummer: Update der Stammdaten
REQ-IMPORT-006: Bei neuer Mitgliedsnummer: Neues Mitglied anlegen
REQ-IMPORT-007: E-Mail-Validierung
REQ-IMPORT-008: Import-Protokoll mit Erfolgen und Fehlern
```

### 4.4 Einladungsprozess

```
REQ-INVITE-001: Nach Import: System generiert Einladungslink (Token)
REQ-INVITE-002: E-Mail mit Einladungslink automatisch senden
REQ-INVITE-003: Token gültig für 7 Tage (konfigurierbar)
REQ-INVITE-004: Mitglied setzt eigenes Passwort über Link
REQ-INVITE-005: Nach Passwort: 2FA-Einrichtung erforderlich
REQ-INVITE-006: Link nur einmal verwendbar
REQ-INVITE-007: Admin kann neue Einladung auslösen
```

---

## 5. Arbeitsstunden-Erfassung

### 5.1 Erfassungsfelder

| Feld | Typ | Konfigurierbar |
|------|-----|:--------------:|
| Datum | Datum | Pflicht/Optional/Ausgeblendet |
| Uhrzeit von | Zeit | Pflicht/Optional/Ausgeblendet |
| Uhrzeit bis | Zeit | Pflicht/Optional/Ausgeblendet |
| Stundenzahl | Dezimal | Pflicht/Optional/Ausgeblendet |
| Tätigkeitskategorie | Auswahl | Pflicht/Optional/Ausgeblendet |
| Projekt/Arbeitsgruppe | Text | Pflicht/Optional/Ausgeblendet |
| Beschreibung | Freitext | Pflicht/Optional/Ausgeblendet |

### 5.2 Erfassungslogik

```
REQ-ENTRY-001: Flexible Zeiteingabe: Uhrzeit von-bis ODER direkte Stundenzahl
REQ-ENTRY-002: Bei Uhrzeit von-bis: Automatische Berechnung der Stundenzahl
REQ-ENTRY-003: Kennzeichnung: Selbsteintrag vs. Fremdeintrag
REQ-ENTRY-004: Erfassung rückwirkend unbegrenzt möglich
REQ-ENTRY-005: Mindestens eine Zeiteingabe muss aktiv sein
```

---

## 6. Kategorien-Verwaltung

### 6.1 Admin-Funktionen

```
REQ-CAT-001: Anlegen neuer Kategorien
REQ-CAT-002: Bearbeiten (Umbenennung)
REQ-CAT-003: Deaktivieren (Soft-Delete)
REQ-CAT-004: Sortierung festlegen
```

### 6.2 Referenzielle Integrität

```
REQ-CAT-005: Deaktivierte Kategorien nicht in Auswahl für neue Anträge
REQ-CAT-006: Bestehende Anträge behalten Kategorie (auch wenn inaktiv)
REQ-CAT-007: Anzeige: Inaktive Kategorien als "(nicht mehr verfügbar)"
REQ-CAT-008: Prüfer kann Kategorie während Freigabe ändern
```

---

## 7. Antrags-Workflow

### 7.1 Prüfer-Zuordnung

```
REQ-WF-001: Pool-Prinzip: Alle Prüfer sehen alle Anträge
REQ-WF-002: Kein fester Prüfer pro Antrag
REQ-WF-003: KRITISCH: Prüfer dürfen EIGENE Anträge NICHT genehmigen/ablehnen
REQ-WF-004: System muss Selbstgenehmigung technisch verhindern
REQ-WF-005: Fehlermeldung bei Selbstgenehmigungs-Versuch
```

### 7.2 Antragsstatus

| Status | Beschreibung |
|--------|--------------|
| `entwurf` | Angelegt, noch nicht eingereicht. Bearbeitbar. |
| `eingereicht` | Wartet auf Prüfung. |
| `in_klaerung` | Prüfer hat Rückfrage gestellt. |
| `freigegeben` | Genehmigt. Endstatus. |
| `abgelehnt` | Abgelehnt. Endstatus. |
| `storniert` | Vom Mitglied zurückgezogen. |

### 7.3 Erlaubte Status-Übergänge

```
entwurf         → eingereicht
eingereicht     → in_klaerung, freigegeben, abgelehnt, entwurf*, storniert
in_klaerung     → freigegeben, abgelehnt, entwurf*, storniert
storniert       → entwurf (Reaktivierung)
freigegeben     → (keine Übergänge, aber Korrektur möglich)
abgelehnt       → (keine Übergänge)

* = "Zurück zur Überarbeitung" durch Prüfer mit Pflichtbegründung
```

### 7.4 Antragsnummer

```
REQ-WF-006: Format: JJJJ-NNNNN (z.B. 2025-00001)
REQ-WF-007: Fortlaufend, keine Wiederverwendung
REQ-WF-008: Bleibt bei Statusänderungen erhalten
```

### 7.5 Korrektur nach Freigabe

```
REQ-KORR-001: Freigegebene Anträge können nachträglich korrigiert werden
REQ-KORR-002: Nur durch Prüfer oder Administrator
REQ-KORR-003: Begründung ist Pflichtfeld
REQ-KORR-004: Alle Änderungen im Audit-Trail (alte/neue Werte)
REQ-KORR-005: Korrektur-Vermerk am Antrag sichtbar
REQ-KORR-006: E-Mail an betroffenes Mitglied
```

### 7.6 Bearbeitungsrechte nach Status

| Status | Mitglied darf |
|--------|--------------|
| Entwurf | Alles bearbeiten, löschen, einreichen |
| Eingereicht | Nur zurückziehen |
| In Klärung | Nur Dialog führen, zurückziehen |
| Freigegeben | Nur ansehen |
| Abgelehnt | Nur ansehen |
| Storniert | Reaktivieren |

---

## 8. Dialog-System

### 8.1 Funktionsumfang

```
REQ-DLG-001: Chronologische Nachrichtenliste zu jedem Antrag
REQ-DLG-002: Prüfer kann Rückfragen stellen → Status "in_klaerung"
REQ-DLG-003: Mitglied kann antworten
REQ-DLG-004: Bidirektionaler Dialog
REQ-DLG-005: Nachrichten sind unveränderlich (Revisionssicherheit)
REQ-DLG-006: Zeitstempel und Autor bei jeder Nachricht
```

### 8.2 Dialog-Erhalt (KRITISCH)

```
REQ-DLG-007: Dialog bleibt bei ALLEN Statusänderungen vollständig erhalten
REQ-DLG-008: Bei "Zurück zur Überarbeitung" → Dialog bleibt
REQ-DLG-009: Bei Stornierung/Reaktivierung → Dialog bleibt
REQ-DLG-010: Bei erneuter Einreichung → Dialog wird fortgesetzt
```

### 8.3 Offene-Fragen-Indikator

```
REQ-DLG-011: Badge in Übersichtsliste bei offener Frage
REQ-DLG-012: Erinnerungs-E-Mail nach X Tagen (Admin konfiguriert)
```

---

## 9. E-Mail-Benachrichtigungen

| Ereignis | Empfänger | Trigger |
|----------|-----------|---------|
| Einladung | Neues Mitglied | Nach CSV-Import |
| Neuer Antrag | Alle Prüfer | Nach Einreichung |
| Freigabe | Mitglied | Nach Genehmigung |
| Ablehnung | Mitglied | Nach Ablehnung (mit Begründung) |
| Zur Überarbeitung | Mitglied | Nach Rückgabe (mit Begründung) |
| Korrektur | Mitglied | Nach nachträglicher Korrektur |
| Dialog-Nachricht | Gegenseite | Bei neuer Nachricht |
| Erinnerung | Mitglied | Nach X Tagen ohne Antwort |
| 2FA-Code | Benutzer | Bei E-Mail-2FA |
| Passwort-Reset | Benutzer | Bei Reset-Anforderung |

---

## 10. Reporting

### 10.1 Mitglied

```
REQ-REP-001: Übersicht eigener Arbeitsstunden (alle Status)
REQ-REP-002: Filter: Zeitraum, Kategorie, Projekt, Status
REQ-REP-003: Summen nach Zeitraum
REQ-REP-004: Export: PDF, CSV
```

### 10.2 Erfasser

```
REQ-REP-005: Alle Mitglied-Reports
REQ-REP-006: Zusätzlich: Für andere erfasste Einträge
```

### 10.3 Prüfer

```
REQ-REP-007: Alle Anträge (unabhängig vom Mitglied)
REQ-REP-008: Filter: Status, Mitglied, Kategorie, Zeitraum
REQ-REP-009: Offene Anträge mit Wartezeit
REQ-REP-010: Summenreport pro Mitglied/Kategorie/Projekt
```

### 10.4 Auditor

```
REQ-REP-011: Alle Prüfer-Reports
REQ-REP-012: Gelöschte/stornierte Vorgänge
REQ-REP-013: Komplette Dialog-Historien
REQ-REP-014: Audit-Trail-Einsicht
```

### 10.5 Export-Protokollierung

```
REQ-REP-015: Jeder Export im Audit-Trail
REQ-REP-016: Protokolliert: Wer, Wann, Report-Typ, Filter, Format
```

---

## 11. Soll-Stunden-Verwaltung

### 11.1 Aktivierung

```
REQ-SOLL-001: Funktion standardmäßig deaktiviert
REQ-SOLL-002: Admin kann global aktivieren
REQ-SOLL-003: Bei Aktivierung: Soll-Stunden definierbar
```

### 11.2 Konfiguration

```
REQ-SOLL-004: Standard-Soll für alle Mitglieder (z.B. 20h/Jahr)
REQ-SOLL-005: Individuelles Soll pro Mitglied möglich
REQ-SOLL-006: Befreiung einzelner Mitglieder (z.B. Ehrenmitglieder)
REQ-SOLL-007: Bezug auf Kalenderjahr
```

### 11.3 Anzeige

```
REQ-SOLL-008: Mitglied sieht eigenen Soll/Ist-Stand
REQ-SOLL-009: Fortschrittsanzeige ("15 von 20 Stunden")
REQ-SOLL-010: Prüfer/Admin: Übersicht aller Mitglieder
REQ-SOLL-011: Report: Mitglieder mit nicht erfülltem Soll
```

---

## 12. Datenintegrität

### 12.1 Soft-Delete

```
REQ-INT-001: Keine physische Löschung von Daten
REQ-INT-002: Jeder Datensatz hat deleted_at (NULL = aktiv)
REQ-INT-003: Gelöschte Datensätze ausgeblendet (außer für Auditor/Admin)
REQ-INT-004: Admin kann reaktivieren
```

### 12.2 Datenaufbewahrung

```
REQ-INT-005: Aufbewahrungsfrist konfigurierbar (Standard: 10 Jahre)
REQ-INT-006: Minimum: 3 Jahre, Maximum: unbegrenzt
REQ-INT-007: Nach Ablauf: Löschvormerkung (Admin-Bestätigung)
```

### 12.3 Audit-Trail

```
REQ-AUDIT-001: JEDE Datenänderung wird protokolliert
REQ-AUDIT-002: Protokolliert: Wer, Wann, Was, Alte Werte, Neue Werte
REQ-AUDIT-003: Login-Versuche (erfolgreich/fehlgeschlagen)
REQ-AUDIT-004: Datenexporte
REQ-AUDIT-005: Konfigurationsänderungen
REQ-AUDIT-006: Dialog-Nachrichten
REQ-AUDIT-007: Audit-Trail ist unveränderlich
```

---

## 13. Technische Anforderungen

### 13.1 Sicherheit

```
REQ-SEC-001: HTTPS (vom Hosting bereitgestellt)
REQ-SEC-002: Prepared Statements für ALLE SQL-Queries
REQ-SEC-003: XSS-Schutz durch Output-Escaping
REQ-SEC-004: CSRF-Token für alle Formulare
REQ-SEC-005: Passwort-Hashing mit bcrypt (cost ≥ 12)
REQ-SEC-006: Rate-Limiting für Login (5 Versuche, 15 Min Sperre)
```

### 13.2 Multisession/Multitab

```
REQ-MULTI-001: Unbegrenzte Sessions pro Benutzer
REQ-MULTI-002: Mehrere Tabs teilen sich eine Session
REQ-MULTI-003: Optimistic Locking bei Bearbeitungskonflikten
REQ-MULTI-004: Bearbeitungssperre mit Timeout (5 Min)
```

### 13.3 Responsive Design

```
REQ-UI-001: Desktop, Tablet, Smartphone
REQ-UI-002: Mobile-First-Ansatz
REQ-UI-003: Touch-optimiert
```

### 13.4 Browser-Support

- Chrome (aktuell + Vorversion)
- Firefox (aktuell + Vorversion)
- Edge (aktuell + Vorversion)
- Safari (aktuell + Vorversion)
- Samsung Internet (Mobile)

### 13.5 Performance

```
REQ-PERF-001: Seitenladezeit < 3 Sekunden
REQ-PERF-002: Pagination ab 50 Einträgen
REQ-PERF-003: Indizes auf gefilterte Spalten
```

---

## 14. Admin-Konfiguration

### 14.1 System-Einstellungen

| Setting | Beschreibung | Standard |
|---------|--------------|----------|
| `vereinsname` | Name für Anzeige/PDF | - |
| `vereinslogo_path` | Logo für PDF-Export | null |
| `session_timeout_minutes` | Session-Timeout | 30 |
| `max_login_attempts` | Fehlversuche bis Sperre | 5 |
| `lockout_duration_minutes` | Sperrdauer | 15 |
| `require_2fa` | 2FA Pflicht | true |
| `reminder_days` | Tage bis Erinnerung | 7 |
| `reminder_enabled` | Erinnerungen aktiv | true |
| `target_hours_enabled` | Soll-Stunden aktiv | false |
| `target_hours_default` | Standard-Soll | 20 |
| `data_retention_years` | Aufbewahrungsfrist | 10 |
| `invitation_expiry_days` | Einladungslink-Gültigkeit | 7 |

### 14.2 Pflichtfeld-Konfiguration

Für jedes Erfassungsfeld: `required` | `optional` | `hidden`

### 14.3 Versionsanzeige

```
REQ-VER-001: Anzeige auf Login-Seite (unten)
REQ-VER-002: Anzeige im Footer (nach Login)
REQ-VER-003: Format: "VAES v1.3.0 (2025-02-09) [abc123f]"
```

---

## 15. Datenmodell

### 15.1 Kerntabellen

| Tabelle | Beschreibung |
|---------|--------------|
| `users` | Benutzer/Mitglieder |
| `roles` | Rollendefinitionen |
| `user_roles` | Benutzer-Rollen-Zuordnung |
| `user_invitations` | Einladungslinks |
| `categories` | Tätigkeitskategorien |
| `work_entries` | Arbeitsstunden-Einträge |
| `work_entry_dialogs` | Dialog-Nachrichten |
| `yearly_targets` | Soll-Stunden pro Jahr |
| `sessions` | Aktive Sessions |
| `audit_log` | Audit-Trail |
| `settings` | Systemeinstellungen |
| `entry_locks` | Bearbeitungssperren |

### 15.2 Vollständiges Schema

Siehe: `scripts/database/create_database.sql`

---

## Abnahmekriterien

### Funktional

- [ ] Alle 5 Benutzerrollen implementiert
- [ ] Vollständiger Workflow mit allen Status
- [ ] Selbstgenehmigung technisch verhindert
- [ ] Dialog-System funktioniert bidirektional
- [ ] Dialog bleibt bei Statusänderungen erhalten
- [ ] Korrektur nach Freigabe möglich
- [ ] CSV-Import funktioniert
- [ ] Einladungslink-Prozess funktioniert
- [ ] Alle Reports verfügbar
- [ ] PDF/CSV-Export funktioniert
- [ ] 2FA funktioniert (TOTP + E-Mail)
- [ ] Soll-Stunden-Funktion (konfigurierbar)

### Technisch

- [ ] Deployment auf Strato erfolgreich
- [ ] Responsive auf allen Geräten
- [ ] Keine SQL-Injection möglich
- [ ] XSS-Schutz implementiert
- [ ] CSRF-Schutz implementiert
- [ ] Soft-Delete durchgängig
- [ ] Audit-Trail vollständig
- [ ] Multisession funktioniert
- [ ] Bearbeitungssperren funktionieren

### Sicherheit

- [ ] OWASP Top 10 geprüft
- [ ] Penetrationstest bestanden
- [ ] Alle Sessions bei Passwortänderung beendet
- [ ] Sensible Verzeichnisse geschützt

---

*Letzte Aktualisierung: 2025-02-09*
