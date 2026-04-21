# VAES - Manuelle Testszenarien

Dieses Dokument beschreibt manuelle Testszenarien, die nicht oder nur schwer automatisiert getestet werden koennen (Browser-basierte UI-Tests, E-Mail-Versand, Multi-Session etc.).

**Version:** 1.3
**Stand:** 2026-02-11

---

## Testserver

| Eigenschaft | Wert |
|-------------|------|
| **URL** | `https://192.168.3.98/helferstunden` |
| **Verzeichnis** | `/var/www/html/TSC-Helferstundenverwaltung` (Flat-Struktur) |
| **SSL** | Selbstsigniertes Zertifikat (Browser-Warnung akzeptieren) |
| **E-Mail (SMTP)** | Telekom: `securesmtp.t-online.de`, Port 587, TLS |

**Hinweis:** Alle URLs in diesem Dokument (z.B. `/login`) sind relativ zum base_path. Vollstaendige URL: `https://192.168.3.98/helferstunden/login`

---

## Test-Accounts

| Rolle | E-Mail | Mitgliedsnr. | Passwort |
|-------|--------|-------------|----------|
| Administrator | admin@test.de | ADMIN001 | Test123! |
| Pruefer 1 | pruefer1@test.de | M001 | Test123! |
| Pruefer 2 | pruefer2@test.de | M002 | Test123! |
| Mitglied | mitglied@test.de | M003 | Test123! |
| Erfasser | erfasser@test.de | M004 | Test123! |
| Auditor | auditor@test.de | M005 | Test123! |

---

## 1. Authentifizierung & Sicherheit

### AUTH-01: Login mit korrekten Daten
- **Voraussetzung:** Benutzer hat Passwort gesetzt und 2FA eingerichtet
- **Schritte:**
  1. `/login` aufrufen
  2. E-Mail und Passwort eingeben
  3. Absenden
- **Erwartet:** 2FA-Code-Eingabe erscheint
- **Status:** [ ]

### AUTH-02: Login mit falschem Passwort
- **Schritte:**
  1. `/login` aufrufen
  2. Korrekte E-Mail, falsches Passwort eingeben
- **Erwartet:** "Ungültige Anmeldedaten. Noch 4 Versuch(e) verbleibend."
- **Status:** [ ]

### AUTH-03: Account-Sperre nach 5 Fehlversuchen
- **Schritte:**
  1. 5x Login mit falschem Passwort versuchen
- **Erwartet:** "Ihr Account wurde nach zu vielen Fehlversuchen vorübergehend gesperrt."
- **Status:** [ ]

### AUTH-04: TOTP-2FA mit Authenticator-App
- **Voraussetzung:** TOTP in Authenticator-App eingerichtet
- **Schritte:**
  1. Login mit korrekten Daten
  2. TOTP-Code aus App eingeben
- **Erwartet:** Login erfolgreich, Dashboard erscheint
- **Status:** [ ]

### AUTH-05: TOTP-Code falsch
- **Schritte:**
  1. Login mit korrekten Daten
  2. Falschen 6-stelligen Code eingeben
- **Erwartet:** "Ungültiger oder abgelaufener Code. Noch X Versuch(e)."
- **Status:** [ ]

### AUTH-06: E-Mail-2FA-Code
- **Voraussetzung:** E-Mail-2FA aktiviert
- **Schritte:**
  1. Login mit korrekten Daten
  2. E-Mail prüfen, Code eingeben
- **Erwartet:** Login erfolgreich
- **Status:** [ ]

### AUTH-07: Einladungslink gültig
- **Schritte:**
  1. Admin importiert neuen Benutzer via CSV
  2. Benutzer öffnet Einladungs-E-Mail
  3. Link anklicken, Passwort setzen
- **Erwartet:** Passwort-Setup-Formular, nach Setzen Weiterleitung zu Login
- **Status:** [ ]

### AUTH-08: Einladungslink abgelaufen
- **Voraussetzung:** Link älter als konfigurierte Tage
- **Schritte:**
  1. Abgelaufenen Link aufrufen
- **Erwartet:** "Ungültiger oder abgelaufener Einladungslink."
- **Status:** [ ]

### AUTH-09: Passwort-Reset
- **Schritte:**
  1. `/forgot-password` aufrufen
  2. E-Mail eingeben
  3. Reset-E-Mail prüfen, Link anklicken
  4. Neues Passwort setzen
- **Erwartet:** Passwort geändert, alle bestehenden Sessions beendet
- **Status:** [ ]

### AUTH-10: Rate-Limiting Passwort-Reset
- **Schritte:**
  1. 6x "Passwort vergessen" innerhalb von 15 Minuten absenden
- **Erwartet:** "Zu viele Anfragen. Bitte versuchen Sie es später erneut."
- **Status:** [ ]

### AUTH-11: Session-Timeout
- **Schritte:**
  1. Einloggen
  2. Session-Lifetime abwarten (Standard: 30 Min)
  3. Seite neu laden
- **Erwartet:** Weiterleitung zu `/login?reason=expired`
- **Status:** [ ]

### AUTH-12: CSRF-Schutz
- **Schritte:**
  1. Einloggen, Formular öffnen
  2. CSRF-Token im HTML manuell ändern
  3. Formular absenden
- **Erwartet:** CSRF-Validierungsfehler, Formular wird nicht verarbeitet
- **Status:** [ ]

---

## 2. Workflow-Statusübergänge

### WF-01: Antrag erstellen (Entwurf)
- **Rolle:** Mitglied
- **Schritte:**
  1. `/entries/create` aufrufen
  2. Pflichtfelder ausfüllen (Datum, Stunden, Kategorie)
  3. "Speichern" klicken
- **Erwartet:** Eintrag im Status "Entwurf" erstellt, Antragsnummer zugewiesen
- **Status:** [ ]

### WF-02: Antrag einreichen
- **Rolle:** Mitglied
- **Schritte:**
  1. Entwurf öffnen
  2. "Einreichen" klicken
- **Erwartet:** Status wechselt zu "Eingereicht", E-Mail an Prüfer gesendet
- **Status:** [ ]

### WF-03: Antrag zurückziehen
- **Rolle:** Mitglied
- **Schritte:**
  1. Eingereichten Antrag öffnen
  2. "Zurückziehen" klicken
- **Erwartet:** Status wechselt zurück zu "Entwurf"
- **Status:** [ ]

### WF-04: Prüfer genehmigt Antrag
- **Rolle:** Prüfer
- **Schritte:**
  1. Eingereichten Antrag eines **anderen** Mitglieds öffnen
  2. "Freigeben" klicken
- **Erwartet:** Status "Freigegeben", Mitglied erhält Benachrichtigungs-E-Mail
- **Status:** [ ]

### WF-05: Prüfer lehnt ab
- **Rolle:** Prüfer
- **Schritte:**
  1. Eingereichten Antrag öffnen
  2. Ablehnungsgrund eingeben
  3. "Ablehnen" klicken
- **Erwartet:** Status "Abgelehnt", Begründung gespeichert, E-Mail an Mitglied
- **Status:** [ ]

### WF-06: Ablehnung ohne Begründung
- **Rolle:** Prüfer
- **Schritte:**
  1. Eingereichten Antrag öffnen
  2. Ohne Begründung "Ablehnen" klicken
- **Erwartet:** Fehlermeldung: "Bei einer Ablehnung muss eine Begründung angegeben werden."
- **Status:** [ ]

### WF-07: Rückfrage stellen (In Klärung)
- **Rolle:** Prüfer
- **Schritte:**
  1. Eingereichten Antrag öffnen
  2. Rückfrage-Text eingeben
  3. "Rückfrage" klicken
- **Erwartet:** Status "In Klärung", Dialog-Nachricht erstellt, E-Mail an Mitglied
- **Status:** [ ]

### WF-08: Stornierung und Reaktivierung
- **Rolle:** Mitglied
- **Schritte:**
  1. Eingereichten Antrag stornieren
  2. Stornierten Antrag wieder reaktivieren
- **Erwartet:** Status wechselt zu "Storniert", dann zurück zu "Entwurf"
- **Status:** [ ]

---

## 3. Selbstgenehmigung (KRITISCH)

### SELF-01: Prüfer genehmigt eigenen Antrag
- **Rolle:** Prüfer (gleichzeitig Mitglied)
- **Schritte:**
  1. Eigenen Antrag erstellen und einreichen
  2. Eigenen Antrag öffnen, "Freigeben" versuchen
- **Erwartet:** "Eigene Anträge können nicht selbst genehmigt werden."
- **Status:** [ ]

### SELF-02: Prüfer lehnt eigenen Antrag ab
- **Schritte:** Wie SELF-01, aber "Ablehnen"
- **Erwartet:** Fehlermeldung, Ablehnung blockiert
- **Status:** [ ]

### SELF-03: Admin genehmigt eigenen Antrag
- **Rolle:** Administrator
- **Schritte:**
  1. Eigenen Antrag einreichen
  2. "Freigeben" versuchen
- **Erwartet:** Fehlermeldung - auch Admins dürfen nicht selbst genehmigen!
- **Status:** [ ]

### SELF-04: Erfasser prüft eigenen Fremdeintrag
- **Rolle:** Erfasser + Prüfer
- **Schritte:**
  1. Eintrag für anderes Mitglied erstellen
  2. Den selbst erstellten Eintrag als Prüfer öffnen
  3. "Freigeben" versuchen
- **Erwartet:** "Von Ihnen erstellte Anträge können nicht von Ihnen selbst geprüft werden."
- **Status:** [ ]

---

## 4. Dialog-System

### DLG-01: Prüfer stellt Frage
- **Schritte:**
  1. Prüfer stellt Rückfrage an einen Antrag
- **Erwartet:** Dialog-Eintrag mit `is_question=true`, Status "In Klärung"
- **Status:** [ ]

### DLG-02: Mitglied beantwortet Frage
- **Schritte:**
  1. Mitglied öffnet Antrag in Klärung
  2. Antwort in Dialog eingeben
- **Erwartet:** Frage als `is_answered=true` markiert, E-Mail an Prüfer
- **Status:** [ ]

### DLG-03: Dialog bei Statusänderung erhalten
- **Schritte:**
  1. Antrag mit Dialog-Verlauf öffnen
  2. Status ändern (z.B. Freigeben)
  3. Antrag erneut öffnen
- **Erwartet:** Gesamter Dialog-Verlauf bleibt sichtbar
- **Status:** [ ]

### DLG-04: Dialog-Nachrichten nicht löschbar
- **Schritte:**
  1. Versuchen, eine Dialog-Nachricht zu löschen
- **Erwartet:** Keine Lösch-Option vorhanden (Revisionssicherheit)
- **Status:** [ ]

---

## 5. Soft-Delete

### SD-01: Antrag löschen
- **Schritte:**
  1. Antrag im Entwurf löschen
  2. Datenbank prüfen
- **Erwartet:** `deleted_at` gesetzt, Eintrag nicht physisch gelöscht
- **Status:** [ ]

### SD-02: Gelöschter Antrag unsichtbar für Mitglied
- **Schritte:**
  1. Antrag löschen
  2. Eigene Antragsliste prüfen
- **Erwartet:** Antrag nicht mehr sichtbar
- **Status:** [ ]

### SD-03: Gelöschter Antrag sichtbar für Auditor
- **Rolle:** Auditor
- **Schritte:**
  1. Audit-Log durchsuchen
- **Erwartet:** Gelöschte Einträge sind sichtbar (mit Kennzeichnung)
- **Status:** [ ]

---

## 6. Korrektur nach Freigabe

### KORR-01: Prüfer korrigiert Stundenzahl
- **Schritte:**
  1. Freigegebenen Antrag eines anderen Mitglieds öffnen
  2. Neue Stundenzahl und Begründung eingeben
  3. Korrektur speichern
- **Erwartet:** Stunden geändert, `is_corrected=true`, Original-Stunden gespeichert, E-Mail an Mitglied
- **Status:** [ ]

### KORR-02: Korrektur ohne Begründung
- **Schritte:**
  1. Korrektur ohne Begründung versuchen
- **Erwartet:** Fehlermeldung: "Bei einer Korrektur muss eine Begründung angegeben werden."
- **Status:** [ ]

### KORR-03: Mitglied versucht eigene Korrektur
- **Schritte:**
  1. Freigegebenen eigenen Antrag öffnen
  2. Korrektur-Option prüfen
- **Erwartet:** Keine Korrektur-Möglichkeit (UI) oder Fehlermeldung (API)
- **Status:** [ ]

---

## 7. CSV-Import

### IMP-01: Gültiger CSV-Import
- **Rolle:** Administrator
- **Schritte:**
  1. CSV-Datei mit Pflichtfeldern vorbereiten
  2. `/admin/users/import` aufrufen, Datei hochladen
- **Erwartet:** Mitglieder erstellt, Einladungs-E-Mails gesendet, Ergebnis angezeigt
- **Status:** [ ]

### IMP-02: CSV mit fehlenden Pflichtfeldern
- **Schritte:**
  1. CSV ohne `mitgliedsnummer`-Spalte hochladen
- **Erwartet:** "Pflichtfeld 'mitgliedsnummer' fehlt im CSV-Header."
- **Status:** [ ]

### IMP-03: CSV mit ungültiger E-Mail
- **Schritte:**
  1. CSV mit `email: "nicht-gültig"` hochladen
- **Erwartet:** Zeile wird als Fehler gemeldet, andere Zeilen werden verarbeitet
- **Status:** [ ]

### IMP-04: CSV-Update bestehender Mitglieder
- **Schritte:**
  1. CSV mit bereits vorhandenen Mitgliedsnummern hochladen
- **Erwartet:** Stammdaten aktualisiert (kein neuer Account), Audit-Log-Eintrag
- **Status:** [ ]

### IMP-05: CSV mit Semikolon-Delimiter
- **Schritte:**
  1. CSV mit Semikolon als Trennzeichen hochladen (typisch für Excel-Export)
- **Erwartet:** Automatische Erkennung, Import funktioniert
- **Status:** [ ]

---

## 8. Berichte & Export

### REP-01: PDF-Export Jahresbericht
- **Rolle:** Administrator/Prüfer
- **Schritte:**
  1. `/reports` aufrufen
  2. Jahr und Filter wählen
  3. "PDF exportieren" klicken
- **Erwartet:** PDF wird generiert und heruntergeladen
- **Status:** [ ]

### REP-02: CSV-Export
- **Schritte:**
  1. Bericht generieren
  2. "CSV exportieren" klicken
- **Erwartet:** CSV mit korrekten Daten, UTF-8-BOM für Excel-Kompatibilität
- **Status:** [ ]

### REP-03: Audit-Trail Export
- **Rolle:** Auditor
- **Schritte:**
  1. Audit-Log mit Filtern durchsuchen
- **Erwartet:** Alle Aktionen werden chronologisch angezeigt
- **Status:** [ ]

---

## 9. E-Mail-Benachrichtigungen

### EMAIL-01: Einreichung → Prüfer-Benachrichtigung
- **Trigger:** Mitglied reicht Antrag ein
- **Erwartet:** Alle aktiven Prüfer erhalten E-Mail mit Antragsnummer und Link
- **Status:** [ ]

### EMAIL-02: Freigabe → Mitglied-Benachrichtigung
- **Trigger:** Prüfer genehmigt Antrag
- **Erwartet:** Mitglied erhält E-Mail mit Freigabe-Bestätigung
- **Status:** [ ]

### EMAIL-03: Ablehnung → Mitglied-Benachrichtigung
- **Trigger:** Prüfer lehnt ab
- **Erwartet:** Mitglied erhält E-Mail mit Ablehnungsgrund
- **Status:** [ ]

### EMAIL-04: Rückfrage → Mitglied-Benachrichtigung
- **Trigger:** Prüfer stellt Rückfrage
- **Erwartet:** Mitglied erhält E-Mail mit Rückfrage-Text
- **Status:** [ ]

### EMAIL-05: Korrektur → Mitglied-Benachrichtigung
- **Trigger:** Prüfer korrigiert Stundenzahl
- **Erwartet:** E-Mail mit alten/neuen Stunden und Begründung
- **Status:** [ ]

### EMAIL-06: Dialog-Nachricht → Gegenseite
- **Trigger:** Neue Nachricht im Dialog
- **Erwartet:** E-Mail an die Gegenseite (Prüfer→Mitglied oder Mitglied→Prüfer)
- **Status:** [ ]

### EMAIL-07: E-Mail-Fehler blockiert Workflow nicht
- **Schritte:**
  1. SMTP-Server vorübergehend deaktivieren
  2. Antrag freigeben
- **Erwartet:** Freigabe gelingt, E-Mail-Fehler wird geloggt aber nicht angezeigt
- **Status:** [ ]

---

## 10. Pflichtfeld-Konfiguration

### FIELD-01: Default-Konfiguration
- **Schritte:**
  1. Erfassungsformular öffnen
- **Erwartet:** Datum, Stunden, Kategorie sind Pflicht (*), Rest optional
- **Status:** [ ]

### FIELD-02: Feld auf "hidden" setzen
- **Rolle:** Administrator
- **Schritte:**
  1. Admin → Einstellungen → Feld "Projekt" auf "hidden" setzen
  2. Erfassungsformular öffnen
- **Erwartet:** Feld "Projekt" ist nicht mehr sichtbar
- **Status:** [ ]

### FIELD-03: Feld auf "required" setzen
- **Schritte:**
  1. "Beschreibung" auf "required" setzen
  2. Formular ohne Beschreibung absenden
- **Erwartet:** Serverseitige Validierungsfehler
- **Status:** [ ]

### FIELD-04: Serverseitige Validierung (nicht nur HTML)
- **Schritte:**
  1. HTML-`required`-Attribut per DevTools entfernen
  2. Pflichtfeld leer lassen, absenden
- **Erwartet:** Server gibt Fehler zurück (nicht nur Client-Validierung)
- **Status:** [ ]

---

## 11. Versionsanzeige

### VER-01: Login-Seite
- **Schritte:**
  1. `/login` aufrufen
- **Erwartet:** Footer zeigt "VAES v1.3.0 (DATUM) [GIT-HASH]"
- **Status:** [ ]

### VER-02: Dashboard-Footer
- **Schritte:**
  1. Einloggen
  2. Footer prüfen
- **Erwartet:** Gleiche Versionsanzeige wie auf Login-Seite
- **Status:** [ ]

---

## 12. Sicherheits-Header

### SEC-01: CSP-Header aktiv
- **Schritte:**
  1. Seite aufrufen
  2. Browser-DevTools → Network → Response Headers prüfen
- **Erwartet:** `Content-Security-Policy` Header mit `default-src 'self'`
- **Status:** [ ]

### SEC-02: X-Frame-Options
- **Schritte:**
  1. Response Headers prüfen
- **Erwartet:** `X-Frame-Options: SAMEORIGIN`
- **Status:** [ ]

### SEC-03: Session-Cookie Flags
- **Schritte:**
  1. Cookies im Browser prüfen
- **Erwartet:** HttpOnly, Secure, SameSite=Lax
- **Status:** [ ]

---

## 13. HTTPS & SSL (Testserver)

### SSL-01: HTTPS erreichbar
- **Schritte:**
  1. `curl -k -I https://192.168.3.98/helferstunden/` ausfuehren
- **Erwartet:** HTTP 200 oder 302, SSL-Verbindung erfolgreich
- **Status:** [ ]

### SSL-02: SSL-Zertifikat vorhanden
- **Schritte:**
  1. `openssl s_client -connect 192.168.3.98:443` ausfuehren
- **Erwartet:** Zertifikat angezeigt, CN=192.168.3.98
- **Status:** [ ]

### SSL-03: Session-Cookie nur ueber HTTPS
- **Schritte:**
  1. Einloggen
  2. Browser DevTools > Application > Cookies pruefen
- **Erwartet:** `Secure`-Flag gesetzt auf dem Session-Cookie `VAES_SESSION`
- **Status:** [ ]

### SSL-04: Statische Dateien laden
- **Schritte:**
  1. Einloggen
  2. Browser DevTools > Network-Tab pruefen
- **Erwartet:** CSS und JS Dateien laden korrekt, kein 404, kein Mixed Content
- **Status:** [ ]

### SSL-05: Bootstrap CDN ueber HTTPS
- **Schritte:**
  1. Network-Tab pruefen
- **Erwartet:** Bootstrap CSS/JS laden von `https://cdn.jsdelivr.net`, SRI-Hash korrekt (kein Blockieren)
- **Status:** [ ]

### SSL-06: Base-Path korrekt
- **Schritte:**
  1. `https://192.168.3.98/helferstunden/login` aufrufen
  2. Einloggen
  3. Interne Links pruefen (Arbeitsstunden, Berichte, etc.)
- **Erwartet:** Alle Links enthalten `/helferstunden/` Prefix, keine 404-Fehler
- **Status:** [ ]

---

## 14. Multi-Session / Concurrent Access

### MULTI-01: Gleicher Antrag in zwei Tabs
- **Schritte:**
  1. Antrag in Tab A und Tab B öffnen
  2. In Tab A bearbeiten und speichern
  3. In Tab B bearbeiten und speichern
- **Erwartet:** Optimistic Locking: Tab B erhält Fehlermeldung "zwischenzeitlich geändert"
- **Status:** [ ]

### MULTI-02: Login von zwei Geräten
- **Schritte:**
  1. In Browser 1 einloggen
  2. In Browser 2 einloggen
- **Erwartet:** Beide Sessions funktionieren parallel
- **Status:** [ ]

### MULTI-03: Passwortänderung beendet alle Sessions
- **Schritte:**
  1. In Browser 1 und Browser 2 einloggen
  2. In Browser 1 Passwort ändern
  3. In Browser 2 Seite neu laden
- **Erwartet:** Browser 2 wird zum Login weitergeleitet
- **Status:** [ ]

---

*Zuletzt aktualisiert: 2026-02-11*
