# VAES - Funktionsbeschreibung

**Vereins-Arbeitsstunden-Erfassungssystem (VAES)**
Version 1.3 | Februar 2026

---

## 1. Systemueberblick

VAES ist eine webbasierte Anwendung zur Erfassung, Verwaltung und Freigabe von ehrenamtlichen Arbeitsstunden fuer Vereinsmitglieder. Das System bietet einen mehrstufigen Freigabe-Workflow, ein integriertes Dialog-System und einen lueckenlosen Audit-Trail.

**Technologie:** PHP 8.x, Slim 4, MySQL 8.4, Bootstrap 5
**Zielumgebung:** Strato Shared Webhosting

---

## 2. Benutzerrollen

| Rolle | Beschreibung |
|-------|--------------|
| **Mitglied** | Basisrolle. Erfasst und verwaltet eigene Arbeitsstunden. |
| **Erfasser** | Erfasst Stunden stellvertretend fuer andere Mitglieder. |
| **Pruefer** | Prueft eingereichte Eintraege, gibt frei oder lehnt ab. Kann Rueckfragen stellen und Stunden korrigieren. |
| **Auditor** | Lesezugriff auf alle Daten und den Audit-Trail. Kein Schreibzugriff. |
| **Administrator** | Vollzugriff. Verwaltet Mitglieder, Kategorien, Einstellungen und Soll-Stunden. |

Jedem Benutzer koennen mehrere Rollen gleichzeitig zugewiesen werden.

---

## 3. Authentifizierung und Sicherheit

- **Anmeldung** mit E-Mail und Passwort (min. 8 Zeichen, Gross-/Kleinbuchstaben, Ziffer)
- **Zwei-Faktor-Authentifizierung (2FA)** ist fuer alle Benutzer verpflichtend
  - Methode 1: Authenticator-App (TOTP, z.B. Google Authenticator)
  - Methode 2: 6-stelliger Code per E-Mail (10 Min. gueltig)
- **Kontosperre** nach 5 Fehlversuchen (15 Min. Sperrzeit)
- **Passwort-Reset** per E-Mail-Link (1 Stunde gueltig)
- **Einladungssystem:** Neue Mitglieder erhalten einen personalisierten Einladungslink per E-Mail

---

## 4. Arbeitsstunden-Erfassung

### 4.1 Erfassbare Felder

| Feld | Beschreibung |
|------|--------------|
| Datum | Tag der geleisteten Arbeit |
| Uhrzeit von / bis | Optionaler Arbeitszeitraum |
| Stunden | Dezimale Stundenangabe (0,25 - 24) |
| Kategorie | Art der Taetigkeit (z.B. Vereinsheim, Sportplatz) |
| Projekt / Taetigkeit | Kurzbeschreibung der Arbeit |
| Beschreibung | Detaillierte Taetigkeitsbeschreibung |

Felder sind vom Administrator als Pflichtfeld, optional oder ausgeblendet konfigurierbar.

### 4.2 Eintragsnummer

Jeder Eintrag erhaelt automatisch eine eindeutige Nummer im Format **JJJJ-NNNNN** (z.B. 2026-00042).

---

## 5. Freigabe-Workflow

Jeder Eintrag durchlaeuft einen definierten Workflow:

```
Entwurf --> Eingereicht --> Freigegeben (Endstatus)
                        --> Abgelehnt (Endstatus)
                        --> In Klaerung --> Freigegeben / Abgelehnt
                        --> Storniert --> Entwurf (Reaktivierung)
```

| Status | Bedeutung |
|--------|-----------|
| **Entwurf** | In Bearbeitung, noch nicht eingereicht |
| **Eingereicht** | Zur Pruefung vorgelegt |
| **In Klaerung** | Pruefer hat Rueckfrage gestellt |
| **Freigegeben** | Genehmigt (nur diese Stunden zaehlen als Ist-Stunden) |
| **Abgelehnt** | Endgueltig abgelehnt |
| **Storniert** | Vom Mitglied zurueckgezogen, reaktivierbar |

**Wichtig:** Pruefer koennen eigene Eintraege nicht selbst genehmigen (Vier-Augen-Prinzip).

---

## 6. Dialog-System

Das Dialog-System ermoeglicht die direkte Kommunikation zwischen Mitglied und Pruefer innerhalb eines Eintrags:

- Chronologischer Nachrichtenverlauf als Chat-Ansicht
- Rueckfragen des Pruefers setzen den Status auf "In Klaerung"
- Antworten des Mitglieds markieren die Rueckfrage als beantwortet
- E-Mail-Benachrichtigung bei neuen Nachrichten
- Ungelesen-Anzeige im Dashboard und in der Navigation (Glocken-Symbol)
- Der Dialog bleibt bei allen Statusaenderungen vollstaendig erhalten

---

## 7. Reports und Export

- **Filterfunktionen:** Zeitraum, Status, Kategorie, Mitglied (rollenabhaengig)
- **Zusammenfassung:** Gesamtstunden, Anzahl Eintraege, Aufschluesselung nach Kategorie und Status
- **PDF-Export:** Professionelles Layout mit Vereinsname, Filtern und Detailtabelle
- **CSV-Export:** Maschinenlesbares Format fuer Tabellenkalkulation (UTF-8)
- Jeder Export wird im Audit-Trail protokolliert

---

## 8. Administration

### 8.1 Mitgliederverwaltung
- Einzelanlage und CSV-Massenimport von Mitgliedern
- Rollenzuweisung, Aktivierung/Deaktivierung
- Automatischer Einladungsversand per E-Mail
- Erneutes Senden abgelaufener Einladungen

### 8.2 Kategorieverwaltung
- Erstellen, Bearbeiten, Aktivieren und Deaktivieren von Arbeitskategorien
- Sortierreihenfolge konfigurierbar
- Deaktivierte Kategorien bleiben bestehenden Eintraegen zugeordnet

### 8.3 Soll-Stunden
- Optional aktivierbare Funktion fuer jaehrliche Stundenziele
- Individuell anpassbare Ziele pro Mitglied
- Befreiungsmoeglichkeit (z.B. Ehrenmitglieder)
- Fortschrittsanzeige im Dashboard (nur freigegebene Stunden zaehlen)

### 8.4 Systemeinstellungen
- Allgemein: Vereinsname, Logo
- Sicherheit: Session-Timeout, Fehlversuche, Sperrdauer, 2FA-Pflicht
- E-Mail: SMTP-Konfiguration mit Test-Funktion
- Feldkonfiguration: Pflichtfelder im Erfassungsformular
- Erinnerungen: Automatische E-Mails bei unbeantworteten Rueckfragen

### 8.5 Audit-Trail
- Lueckenlose Protokollierung aller Systemaktionen
- Filterbar nach Aktion, Benutzer, Tabelle, Zeitraum und Eintragsnummer
- Detailansicht mit alten/neuen Werten, IP-Adresse und Session-Daten
- Unveraenderbar durch Datenbank-Trigger geschuetzt

---

## 9. Datensicherheit

| Massnahme | Beschreibung |
|-----------|--------------|
| SQL-Injection-Schutz | Ausschliesslich Prepared Statements |
| XSS-Schutz | Ausgabe-Escaping mit htmlspecialchars |
| CSRF-Schutz | Token-Validierung bei allen Formularen |
| Passwoerter | Bcrypt-Hashing (Cost-Faktor 12) |
| Soft-Delete | Keine physische Loeschung, nur Markierung |
| Optimistic Locking | Versionierung verhindert gleichzeitige Bearbeitung |
| Rate Limiting | Max. 20 Anfragen pro IP in 15 Minuten |

---

## 10. Seitenuebersicht

| Seite | Pfad | Zugriff |
|-------|------|---------|
| Dashboard | `/` | Alle |
| Anmeldung | `/login` | Oeffentlich |
| 2FA-Verifizierung | `/2fa` | Anmeldeprozess |
| Eintragsliste | `/entries` | Mitglied, Erfasser, Admin |
| Eintrag erstellen | `/entries/create` | Mitglied, Erfasser, Admin |
| Eintrag anzeigen | `/entries/{id}` | Eigentuemer, Pruefer, Auditor, Admin |
| Pruefliste | `/review` | Pruefer, Admin |
| Reports | `/reports` | Alle |
| Mitgliederverwaltung | `/admin/users` | Admin |
| Kategorien | `/admin/categories` | Admin |
| Soll-Stunden | `/admin/targets` | Admin |
| Einstellungen | `/admin/settings` | Admin |
| Audit-Trail | `/admin/audit` | Admin, Auditor |

---

*VAES Funktionsbeschreibung - Version 1.3*
