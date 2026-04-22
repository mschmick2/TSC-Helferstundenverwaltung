# Testskript VAES — Gesamtüberblick

**Projekt:** VAES — Vereins-Arbeitsstunden-Erfassungssystem (TSC Mondial e.V.)
**Version:** 1.4.0
**Zweck dieses Skripts:** Einmaliges Durchspielen aller Hauptfunktionen, um einen vollständigen Überblick über Umfang, Bedienung und Zusammenspiel der Module zu bekommen.
**Zielgruppe:** Tester, die das System zum ersten Mal durchgehen (Funktionsabnahme, Einarbeitung, Release-Smoke-Test).
**Zeitaufwand:** 2–3 Stunden, wenn ohne Pause abgearbeitet.

---

## Inhaltsverzeichnis

1. Voraussetzungen und Setup
2. Test-Benutzer
3. Modul A — Anmeldung, 2FA und Passwort-Reset
4. Modul B — Dashboard
5. Modul C — Arbeitsstunden erfassen (Mitglied)
6. Modul D — Anträge prüfen (Prüfer)
7. Modul E — Geschäftsregeln (Selbstgenehmigung, Dialog, Mehrfach-Edit)
8. Modul F — Mitgliederverwaltung (Administrator)
9. Modul G — CSV-Import von Mitgliedern
10. Modul H — Kategorien, Soll-Stunden, Einstellungen
11. Modul I — Berichte und Export (CSV, PDF)
12. Modul J — Audit-Trail
13. Modul K — Events (Admin, Mitglied, Organisator)
14. Modul L — Event-Vorlagen (Templates)
15. Modul M — iCal-Abonnement
16. Modul N — Profil und eigenes Passwort
17. Abschluss-Checkliste

---

## 1. Voraussetzungen und Setup

### 1.1 Lokale Testumgebung starten

Folgende Komponenten müssen laufen:

| Komponente | URL / Port | Zweck |
|------------|------------|-------|
| VAES-Anwendung | `http://localhost:8000/` | Haupt-Web-Oberfläche |
| MySQL-Server | `127.0.0.1:3306` | Datenbank `vaes` |
| Mailpit (Fake-SMTP) | `http://localhost:8025/` | Abgehende Mails einsehen |

**Start-Sequenz (PowerShell im Projekt-Root):**

```powershell
cd E:\TSC-Helferstundenverwaltung
.\scripts\run-tests.ps1 -Action services
```

**Test-Daten zurücksetzen (optional, bei frischem Anfang empfohlen):**

```powershell
php scripts\seed-test-users.php
```

Das Skript legt die drei Standard-Test-User wieder an oder setzt deren Passwörter zurück. Es läuft **nur gegen lokale Datenbanken** (Schutz gegen Prod-Unfall).

### 1.2 Browser-Hinweise

- Empfohlen: Chrome, Edge oder Firefox (aktuelle Version).
- Für den Multitab-Test (T-E3) werden **zwei unabhängige Browser-Fenster** gebraucht.
- Der Mailpit-Reiter sollte parallel offen bleiben — dort landen alle Mails.

---

## 2. Test-Benutzer

Alle drei haben dasselbe Initialpasswort. 2FA ist deaktiviert, damit der Login-Flow durchgetestet werden kann, ohne dass ein Authenticator-App-Setup erzwungen wird.

| E-Mail | Passwort | Rolle(n) | Zweck im Skript |
|--------|----------|----------|-----------------|
| `admin@vaes.test` | `TestPass123!` | administrator, mitglied | Adminaufgaben, Mitglieder anlegen, Settings, Audit |
| `pruefer@vaes.test` | `TestPass123!` | pruefer, mitglied | Anträge freigeben/ablehnen, Rückfragen |
| `mitglied@vaes.test` | `TestPass123!` | mitglied | Standardmitglied, erfasst eigene Stunden |

Zusätzliche User werden im Laufe der Tests **angelegt** (siehe Modul F und G).

---

## 3. Modul A — Anmeldung, 2FA und Passwort-Reset

### T-A1: Erfolgreicher Login

- **Als:** `mitglied@vaes.test`
- **Ziel:** Basisflow Login → Dashboard.
- **Vorbereitung:** Ausgeloggt. Browser-Cookies für `localhost` leer.

**Schritte:**
1. `http://localhost:8000/login` öffnen.
2. E-Mail und Passwort eintragen, „Anmelden" klicken.

**Erwartetes Ergebnis:**
- Weiterleitung auf `/` (Dashboard).
- Grüne Erfolgsmeldung oder direkt der Dashboard-Inhalt.
- In der Navigationsleiste oben erscheint der Name des Mitglieds.

**Tester-Notiz:**

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

### T-A2: Fehlversuch und Rate-Limit

- **Als:** beliebig (Login-Seite, nicht angemeldet)
- **Ziel:** Brute-Force-Schutz greift nach fünf Fehlversuchen.

**Schritte:**
1. Auf `/login` fünfmal nacheinander `mitglied@vaes.test` mit falschem Passwort anmelden.
2. Beim sechsten Versuch das **richtige** Passwort eingeben.

**Erwartetes Ergebnis:**
- Fehler 1–5: rote Meldung „Anmeldung fehlgeschlagen".
- Ab Versuch 6 Meldung, dass das Konto kurzzeitig gesperrt ist (15 Minuten Lockout).
- Nach Ablauf der Sperre oder Reset über Adminkonto wieder normaler Login.

> **Hinweis für den Tester:** Falls du den Lockout nicht abwarten willst, lies den Admin-Reset-Hinweis in T-F3 — der Administrator kann ein Konto reaktivieren.

**Tester-Notiz:**

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

### T-A3: Passwort vergessen, Reset-Link per Mail

- **Als:** nicht angemeldet
- **Ziel:** Passwort-Reset-Flow bis zum neuen Login.

**Schritte:**
1. Auf der Login-Seite „Passwort vergessen?" klicken.
2. `pruefer@vaes.test` eingeben, absenden.
3. Zu Mailpit (`http://localhost:8025/`) wechseln, neueste Mail „Passwort zurücksetzen" öffnen.
4. Den Reset-Link in der Mail klicken.
5. Neues Passwort zweimal eintragen (z. B. `NeuesPasswort456!`).
6. Auf `/login` mit dem neuen Passwort einloggen.

**Erwartetes Ergebnis:**
- Flash-Meldung „Falls das Konto existiert, wurde ein Link versendet" (absichtlich neutral, verrät keine Accounts).
- In Mailpit liegt eine Mail mit Reset-Link (gültig 1 Stunde).
- Reset-Formular akzeptiert das neue Passwort.
- Login mit dem neuen Passwort funktioniert.

**Tester-Notiz:**

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

> **Am Ende dieses Tests:** Mit `php scripts\seed-test-users.php` das Standardpasswort wiederherstellen, damit die weiteren Tests den dokumentierten Login benutzen können.

---

### T-A4: Logout

- **Als:** angemeldet als beliebiger User
- **Ziel:** Session wird vollständig beendet.

**Schritte:**
1. Oben rechts auf den Benutzernamen → „Abmelden".
2. Danach direkt `http://localhost:8000/entries` in die Adresszeile eingeben.

**Erwartetes Ergebnis:**
- Weiterleitung auf Login-Seite.
- Kein Zugriff auf die Antragsliste ohne erneutes Anmelden.

**Tester-Notiz:**

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

## 4. Modul B — Dashboard

### T-B1: Dashboard als Mitglied

- **Als:** `mitglied@vaes.test`
- **Ziel:** Kennzahlen und Navigations-Elemente sichtbar.

**Schritte:**
1. Einloggen, `/` prüfen.

**Erwartetes Ergebnis:**
- Übersichtskacheln: Ist-Stunden, Anzahl Anträge, Kategorien-Aufteilung.
- Wenn Soll-Stunden konfiguriert: Fortschrittsbalken.
- Glockensymbol in der Navigation zeigt Anzahl ungelesener Dialog-Nachrichten (zu Beginn meist 0).

**Tester-Notiz:**

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

### T-B2: Dashboard-Polling (AJAX)

- **Als:** `mitglied@vaes.test`
- **Ziel:** Die ungelesen-Zahl wird automatisch aktualisiert, ohne die Seite neu zu laden.

**Schritte:**
1. Dashboard offen lassen.
2. In zweitem Browser-Fenster als `pruefer@vaes.test` eine Rückfrage zu einem Antrag stellen (siehe T-E2 für den Ablauf — oder diesen Test auf später verschieben).
3. Im Dashboard-Fenster maximal 60 Sekunden warten.

**Erwartetes Ergebnis:**
- Glockensymbol wechselt von 0 auf die Anzahl neuer Nachrichten, ohne Seiten-Reload.

**Tester-Notiz:**

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

## 5. Modul C — Arbeitsstunden erfassen (Mitglied)

### T-C1: Neuen Antrag anlegen (Entwurf)

- **Als:** `mitglied@vaes.test`
- **Ziel:** Eintrag im Status „Entwurf" erzeugen.

**Schritte:**
1. Menü „Meine Anträge" → „Neuer Antrag".
2. Formular ausfüllen:
   - Datum: heute
   - Stunden: 2,5
   - Kategorie: beliebig
   - Projekt/Tätigkeit: „Testlauf"
   - Beschreibung: „Manueller Test T-C1"
3. „Als Entwurf speichern" klicken.

**Erwartetes Ergebnis:**
- Erfolgsmeldung, Weiterleitung auf Detailseite.
- Antragsnummer im Format `JJJJ-NNNNN` (z. B. `2026-00001`).
- Status: „Entwurf".
- Buttons „Bearbeiten", „Löschen", „Einreichen" sichtbar.

**Tester-Notiz (Antragsnummer merken!):**  **Nr.: _______________**

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

### T-C2: Antrag bearbeiten

- **Als:** `mitglied@vaes.test`
- **Ziel:** Bearbeitung eines Entwurfs funktioniert.

**Schritte:**
1. Antrag aus T-C1 öffnen, „Bearbeiten" klicken.
2. Stundenwert ändern auf 3,0.
3. Speichern.

**Erwartetes Ergebnis:**
- Formular akzeptiert den neuen Wert.
- Detailseite zeigt 3,0 Stunden, Status weiterhin „Entwurf".

**Tester-Notiz:**

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

### T-C3: Antrag einreichen

- **Als:** `mitglied@vaes.test`
- **Ziel:** Statuswechsel `entwurf` → `eingereicht`.

**Schritte:**
1. Antrag aus T-C2 öffnen.
2. „Einreichen" klicken, Bestätigungs-Dialog bestätigen.

**Erwartetes Ergebnis:**
- Status wechselt auf „Eingereicht".
- „Bearbeiten"-Button verschwindet oder ist deaktiviert (Eingereichte Anträge sind nicht direkt editierbar).
- In Mailpit landet eine Mail an den Prüfer (Benachrichtigung „Neuer Antrag zur Prüfung").

**Tester-Notiz:**

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

### T-C4: Antrag stornieren (nach Freigabe)

- **Voraussetzung:** T-D1 (Freigabe) bereits erledigt — oder erst nach Modul D durchführen.
- **Als:** `mitglied@vaes.test`

**Schritte:**
1. Einen **freigegebenen** Antrag öffnen.
2. „Stornieren" klicken, Begründung eintragen.

**Erwartetes Ergebnis:**
- Status wechselt auf „Storniert".
- Der Antrag zählt nicht mehr in den Ist-Stunden.
- Im Audit-Log (Modul J) ist der Stornierungs-Eintrag sichtbar.

**Tester-Notiz:**

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

## 6. Modul D — Anträge prüfen (Prüfer)

### T-D1: Antrag freigeben

- **Als:** `pruefer@vaes.test`
- **Ziel:** Eingereichter Antrag wird vom Prüfer freigegeben.

**Schritte:**
1. Menü „Prüfung" (oder `/review`) öffnen.
2. Den Antrag aus T-C3 auswählen.
3. „Freigeben" klicken, ggf. Kommentar eintragen.

**Erwartetes Ergebnis:**
- Status wechselt auf „Freigegeben".
- Das Mitglied erhält eine Benachrichtigungs-Mail (in Mailpit prüfen).
- Im Dashboard des Mitglieds erhöhen sich die Ist-Stunden.

**Tester-Notiz:**

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

### T-D2: Antrag ablehnen

- **Als:** `pruefer@vaes.test`
- **Vorbereitung:** Zweiter eingereichter Antrag (als Mitglied anlegen und einreichen).

**Schritte:**
1. Antrag in der Prüfliste öffnen.
2. „Ablehnen" wählen, Begründung eintragen (Pflicht).

**Erwartetes Ergebnis:**
- Status „Abgelehnt".
- Mitglied bekommt Mail mit Begründung.
- Endstatus — Mitglied kann den Antrag nicht mehr bearbeiten, nur neue Anträge anlegen.

**Tester-Notiz:**

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

### T-D3: Rückfrage stellen (Status „In Klärung")

- **Als:** `pruefer@vaes.test`
- **Vorbereitung:** Dritter eingereichter Antrag.

**Schritte:**
1. Antrag öffnen, „Rückfrage stellen" klicken.
2. Frage-Text eintippen, absenden.

**Erwartetes Ergebnis:**
- Status wechselt auf „In Klärung".
- Dialog-Thread am Antrag erhält einen neuen Eintrag.
- Mitglied erhält Mail-Benachrichtigung.

**Tester-Notiz:**

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

### T-D4: Antrag korrigieren (nach Freigabe)

- **Als:** `pruefer@vaes.test`
- **Ziel:** Nachträgliche Korrektur mit Audit-Spur.

**Schritte:**
1. Freigegebenen Antrag aus T-D1 öffnen.
2. „Korrigieren" klicken.
3. Stundenzahl ändern (z. B. 3,0 → 2,0), Begründung eintragen, speichern.

**Erwartetes Ergebnis:**
- Formular akzeptiert die Änderung, Antrag bleibt im Status „Freigegeben".
- Im Detail werden die alten und neuen Werte sichtbar markiert.
- Im Audit-Log (Modul J) findet sich ein `update`-Eintrag mit alter und neuer Stundenzahl.
- Mitglied bekommt Benachrichtigungs-Mail.

**Tester-Notiz:**

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

## 7. Modul E — Geschäftsregeln

### T-E1: Selbstgenehmigung ist blockiert

- **Als:** `pruefer@vaes.test`
- **Ziel:** Der Prüfer kann einen **eigenen** Antrag nicht freigeben.

**Schritte:**
1. Als Prüfer einen eigenen Arbeitsstunden-Antrag anlegen und einreichen (Menü „Neuer Antrag", wie in T-C1).
2. Anschließend in der Prüfliste `/review` denselben Antrag öffnen.

**Erwartetes Ergebnis:**
- Variante A: Freigeben-/Ablehnen-/Rückfrage-Buttons sind gar nicht sichtbar.
- Variante B: Falls sichtbar, bricht der Klick mit Fehlermeldung ab: „Eigene Anträge können nicht selbst genehmigt werden."
- Ein zweiter Prüfer (oder der Administrator) könnte den Antrag freigeben — die Regel gilt nur für die eigene Person.

**Tester-Notiz:**

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

### T-E2: Dialog bleibt über Statuswechsel erhalten

- **Als:** abwechselnd `mitglied@vaes.test` und `pruefer@vaes.test`
- **Ziel:** Die komplette Nachrichten-Historie überlebt jeden Statuswechsel.

**Schritte:**
1. Mitglied legt einen Antrag an, reicht ihn ein.
2. Prüfer stellt eine Rückfrage (wie T-D3), Status „In Klärung".
3. Mitglied öffnet den Antrag, antwortet im Dialog.
4. Prüfer schickt den Antrag „Zurück zur Überarbeitung" (Status „Entwurf").
5. Mitglied bearbeitet den Antrag und reicht erneut ein.
6. Prüfer gibt frei.
7. Nach Freigabe: Detailansicht des Antrags prüfen.

**Erwartetes Ergebnis:**
- Alle Dialog-Nachrichten aus Schritt 2–5 sind weiterhin chronologisch sichtbar.
- Kein Nachrichten-Verlust trotz mehrfachen Statuswechseln.

**Tester-Notiz:**

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

### T-E3: Multitab-Schutz (gleichzeitige Bearbeitung)

- **Als:** `mitglied@vaes.test`
- **Ziel:** Zweites Browser-Fenster erkennt, dass ein Antrag in einem anderen Tab gerade bearbeitet wird.

**Schritte:**
1. In Fenster 1: Antrag im Status „Entwurf" im Bearbeitungsmodus öffnen.
2. In Fenster 2 (zweiter Browser oder Inkognito, aber selber Login): denselben Antrag bearbeiten wollen.

**Erwartetes Ergebnis:**
- Fenster 2 zeigt einen Hinweis, dass der Antrag gerade in einer anderen Session editiert wird.
- Entweder Lesemodus oder Abbruch, nicht: zwei parallele Speicher-Konflikte.
- Wenn Fenster 1 geschlossen wird, gibt Fenster 2 nach kurzem Warten die Bearbeitung frei.

**Tester-Notiz:**

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

## 8. Modul F — Mitgliederverwaltung (Administrator)

### T-F1: Mitgliederliste

- **Als:** `admin@vaes.test`
- **Schritte:** Menü „Verwaltung → Mitglieder", `/admin/users`.

**Erwartetes Ergebnis:**
- Tabelle mit allen Usern, Spalten: Name, Mitgliedsnummer, E-Mail, Rollen, Status.
- Filter/Suche funktioniert.

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

### T-F2: Neues Mitglied anlegen + Einladungs-Mail

- **Als:** `admin@vaes.test`

**Schritte:**
1. „Neues Mitglied" klicken.
2. Pflichtfelder ausfüllen: Vorname „Tina", Nachname „Test", E-Mail `tina.test@vaes.test`, Mitgliedsnummer `T-001`.
3. Rolle „Mitglied" ankreuzen.
4. Speichern.
5. Zu Mailpit wechseln.

**Erwartetes Ergebnis:**
- Neuer User erscheint in der Liste als „Inaktiv" (weil Passwort noch nicht gesetzt).
- In Mailpit liegt eine Einladungs-Mail mit Link zu `/setup-password/{token}`.

**Tester-Notiz:**

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

### T-F3: Erstes Passwort setzen (Einladungs-Flow)

- **Als:** unauthentifiziert (neuer Browsertab oder Inkognito)

**Schritte:**
1. In Mailpit den Einladungs-Link aus T-F2 öffnen.
2. Passwort `StartPass789!` zweimal eintragen, speichern.
3. Danach auf `/login` mit `tina.test@vaes.test` + `StartPass789!` einloggen.

**Erwartetes Ergebnis:**
- Formular akzeptiert das Passwort.
- Login funktioniert, Dashboard erscheint.

**Tester-Notiz:**

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

### T-F4: Rollen ändern

- **Als:** `admin@vaes.test`

**Schritte:**
1. Auf `/admin/users` den neuen User „Tina Test" öffnen.
2. Rolle „Prüfer" zusätzlich aktivieren, speichern.

**Erwartetes Ergebnis:**
- Zuweisung bleibt bestehen.
- Beim nächsten Login sieht Tina das Menü „Prüfung".

**Tester-Notiz:**

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

### T-F5: Mitglied deaktivieren und reaktivieren

- **Als:** `admin@vaes.test`

**Schritte:**
1. User „Tina Test" öffnen, „Deaktivieren" klicken.
2. Versuchen, sich als Tina einzuloggen.
3. Wieder als Admin „Aktivieren" klicken.
4. Nochmal als Tina einloggen.

**Erwartetes Ergebnis:**
- Nach Deaktivieren: Login scheitert mit Hinweis auf inaktives Konto.
- Nach Aktivierung: Login klappt wieder.

**Tester-Notiz:**

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

## 9. Modul G — CSV-Import

### T-G1: CSV-Import von Mitgliedern

- **Als:** `admin@vaes.test`
- **Vorbereitung:** Kleine Test-CSV mit 2–3 Zeilen anlegen. Vorlage siehe `docs/Anleitung_CSV-Import.md`.
- **Ziel:** Import-Report zeigt Erfolg und Fehler.

**Schritte:**
1. Menü „Mitglieder → Import".
2. CSV-Datei auswählen, hochladen.
3. Import-Report ansehen.

**Erwartetes Ergebnis:**
- Report zählt importierte Zeilen, Dubletten (Mitgliedsnummer/E-Mail vorhanden) und Fehler.
- Erfolgreich importierte Mitglieder erscheinen in der User-Liste als „Inaktiv".
- Für jeden neu angelegten User wurde eine Einladungs-Mail versendet (Mailpit prüfen).

**Tester-Notiz:**

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

## 10. Modul H — Kategorien, Soll-Stunden, Einstellungen

### T-H1: Kategorie neu anlegen und sortieren

- **Als:** `admin@vaes.test`
- **Schritte:**
1. `/admin/categories` öffnen.
2. Neue Kategorie „Testkategorie Zeitplanung" anlegen.
3. Per Drag-and-Drop (oder Pfeil-Buttons) nach oben/unten verschieben.

**Erwartetes Ergebnis:**
- Kategorie erscheint in der Liste.
- Reihenfolge bleibt nach Seiten-Reload erhalten.
- Mitglieder sehen die neue Kategorie beim nächsten Antrags-Formular.

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

### T-H2: Soll-Stunden pflegen

- **Als:** `admin@vaes.test`

**Schritte:**
1. `/admin/targets` öffnen.
2. Für `mitglied@vaes.test` im aktuellen Jahr Soll-Stunden 20,0 eintragen, speichern.
3. Als Mitglied einloggen, Dashboard prüfen.

**Erwartetes Ergebnis:**
- Soll-Stunden-Balken auf dem Dashboard zeigt aktuellen Ist/Soll-Wert (z. B. „3,0 / 20,0 Std").

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

### T-H3: System-Einstellungen & Test-Mail

- **Als:** `admin@vaes.test`

**Schritte:**
1. `/admin/settings` öffnen.
2. Absender-Adresse und Absender-Name prüfen/anpassen.
3. „Test-Mail senden" klicken.
4. Mailpit prüfen.

**Erwartetes Ergebnis:**
- In Mailpit erscheint eine Mail mit dem Betreff Test-Mail.
- Absender entspricht den Einstellungen.

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

## 11. Modul I — Berichte und Export

### T-I1: Bericht filtern

- **Als:** `admin@vaes.test` oder `pruefer@vaes.test`
- **Schritte:**
1. `/reports` öffnen.
2. Zeitraum „Aktuelles Jahr", Status „Freigegeben".
3. Ergebnisliste sichten.

**Erwartetes Ergebnis:**
- Tabelle mit Mitgliedsdaten, Stundensummen, Kategorien-Aufteilung.
- Summe passt zu bereits angelegten Testanträgen.

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

### T-I2: CSV-Export

**Schritte:**
1. Auf der Report-Seite „CSV-Export" klicken.
2. Datei öffnen (Excel, LibreOffice, Texteditor).

**Erwartetes Ergebnis:**
- UTF-8-CSV, Trennzeichen konsistent.
- Umlaute und Sonderzeichen korrekt dargestellt.

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

### T-I3: PDF-Export

**Schritte:**
1. Auf der Report-Seite „PDF-Export" klicken.
2. PDF öffnen.

**Erwartetes Ergebnis:**
- PDF zeigt Titel, Filter-Parameter, Tabelle, Summen.
- Kein Zeichensalat bei Umlauten.

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

## 12. Modul J — Audit-Trail

### T-J1: Audit-Log durchsuchen

- **Als:** `admin@vaes.test`

**Schritte:**
1. `/admin/audit` öffnen.
2. Filter setzen: Zeitraum „Heute", Aktion „status_change".

**Erwartetes Ergebnis:**
- Alle Status-Wechsel aus den vorherigen Tests (T-C3, T-D1, T-D2, T-D3, T-C4) sind als Zeilen sichtbar.
- Spalten: Datum, User, Aktion, Tabelle, Antragsnummer, Beschreibung.

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

### T-J2: Audit-Detail

**Schritte:**
1. Einen Eintrag aus T-D4 (Korrektur-Action `update` auf Antrag) öffnen.

**Erwartetes Ergebnis:**
- Alt-Werte und Neu-Werte als JSON sichtbar.
- IP-Adresse und Browser des Prüfers sind protokolliert.
- Kein Passwort-Hash oder Secret im Log.

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

### T-J3: Audit-Unveränderlichkeit

- **Ziel:** Prüfen, dass das Audit-Log wirklich append-only ist (keine UI-Option zum Löschen oder Bearbeiten).

**Schritte:**
1. In `/admin/audit` nach Buttons „Bearbeiten" oder „Löschen" suchen.
2. Optional: Im Audit-Detail nach Bearbeiten-Aktion suchen.

**Erwartetes Ergebnis:**
- Keine UI-Möglichkeit, Einträge zu ändern oder zu löschen.
- Nur Filter, Anzeige, Export.

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

## 13. Modul K — Events

Die Event-Verwaltung ist das jüngste Modul. Sie besteht aus drei Perspektiven: Administrator, Organisator, Mitglied.

### T-K1: Event als Administrator anlegen

- **Als:** `admin@vaes.test` (Admin hat implizit Event-Admin-Rechte)

**Schritte:**
1. Menü „Events" → „Event-Verwaltung" (`/admin/events`).
2. „Neues Event" klicken.
3. Ausfüllen: Titel „Testfest", Datum zwei Wochen in der Zukunft, Start 10:00, Ende 18:00, Ort „Vereinsheim".
4. Speichern.
5. Zwei Aufgaben anlegen: „Auf- und Abbau" (2 Plätze, 2,0 Std) und „Theke" (3 Plätze, 4,0 Std).
6. Einen Mitglieder-Account als Organisator hinzufügen (z. B. `pruefer@vaes.test`).
7. Event veröffentlichen („Publish").

**Erwartetes Ergebnis:**
- Event erscheint in `/events` für alle Mitglieder.
- In der Kalender-Ansicht `/events/calendar` ist das Event platziert.

**Tester-Notiz (Event-ID):**

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

### T-K2: Event als Mitglied ansehen und Aufgabe übernehmen

- **Als:** `mitglied@vaes.test`

**Schritte:**
1. Menü „Events" (`/events`).
2. Das Event „Testfest" öffnen.
3. Bei der Aufgabe „Theke" auf „Zusagen" klicken.
4. „Meine Events" (`/my-events`) öffnen.

**Erwartetes Ergebnis:**
- Aufgabe erscheint bei „Meine Events" als zugesagt.
- Freie Plätze der Aufgabe haben sich um 1 reduziert.

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

### T-K3: Zusage zurückziehen

- **Als:** `mitglied@vaes.test`

**Schritte:**
1. Auf `/my-events` die Zusage aus T-K2 öffnen.
2. „Zurückziehen" klicken.

**Erwartetes Ergebnis:**
- Platz in der Aufgabe ist wieder frei.
- Aufgabe verschwindet aus „Meine Events".

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

### T-K4: Organisator gibt Teilnehmer-Zeit frei

- **Voraussetzung:** Event ist vorbei (Datum in der Vergangenheit) oder T-K5 wurde durchgespielt. Alternativ kann der Admin ein bereits vergangenes Event anlegen und das Mitglied direkt als Teilnehmer vermerken.
- **Als:** `pruefer@vaes.test` (fungiert als Organisator, falls zugewiesen)

**Schritte:**
1. `/organizer/events` öffnen.
2. Beim abgeschlossenen Event den Teilnehmer anklicken, „Zeit freigeben" wählen.

**Erwartetes Ergebnis:**
- Aus der Event-Teilnahme entsteht ein Arbeitsstunden-Eintrag im Status „Freigegeben" mit dem hinterlegten Stundenwert der Aufgabe.
- Der Eintrag ist in `/entries` des Mitglieds sichtbar (Herkunft „Event").

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

### T-K5: Event abschließen

- **Als:** `admin@vaes.test`

**Schritte:**
1. Das Event „Testfest" öffnen.
2. „Event abschließen" (Complete) klicken.

**Erwartetes Ergebnis:**
- Status wechselt auf „Abgeschlossen".
- Offene Zusagen können je nach Logik automatisch Stunden gutgeschrieben bekommen (falls der Organisator in T-K4 nicht manuell freigegeben hat).

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

## 14. Modul L — Event-Vorlagen (Templates)

### T-L1: Vorlage anlegen

- **Als:** `admin@vaes.test`

**Schritte:**
1. Menü „Event-Vorlagen" (`/admin/event-templates`).
2. „Neue Vorlage": Titel „Sommerfest (Vorlage)".
3. Zwei Standard-Aufgaben hinzufügen (z. B. „Grill", „Getränke").
4. Speichern.

**Erwartetes Ergebnis:**
- Vorlage erscheint in der Liste.

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

### T-L2: Event aus Vorlage ableiten

**Schritte:**
1. Vorlage aus T-L1 öffnen, „Event daraus ableiten".
2. Datum in der Zukunft, Titel-Suffix, speichern.

**Erwartetes Ergebnis:**
- Neues Event ist angelegt, mit den Standard-Aufgaben aus der Vorlage vorbelegt.
- Aufgaben der Vorlage bleiben in der Vorlage unverändert.

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

## 15. Modul M — iCal-Abonnement

### T-M1: Persönlicher iCal-Feed

- **Als:** `mitglied@vaes.test`

**Schritte:**
1. Menü „Meine Events → Kalender-Abo" (`/my-events/ical`).
2. Den Feed-Link kopieren (Token-basierte URL).
3. URL in einem Kalender-Client abonnieren (Outlook, Google Calendar, Thunderbird) — oder mit `curl` abrufen.
4. „Neuen Token generieren" — alter Token wird ungültig.

**Erwartetes Ergebnis:**
- Der Feed zeigt alle Zusagen des Mitglieds.
- Ein regenerierter Token macht den alten Link unbrauchbar (HTTP 403 oder leerer Feed).

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

### T-M2: Einzel-Event-iCal

**Schritte:**
1. In `/events/{id}` auf „iCal herunterladen" klicken.

**Erwartetes Ergebnis:**
- `.ics`-Datei wird geladen, lässt sich in jedem Kalender öffnen.

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

## 16. Modul N — Profil und eigenes Passwort

### T-N1: Eigene Daten ansehen

- **Als:** beliebig angemeldet
- **Schritte:**
1. Oben rechts auf den Namen → „Profil".

**Erwartetes Ergebnis:**
- Stammdaten (Name, E-Mail, Mitgliedsnummer, Rollen) sind sichtbar.
- Je nach Konfiguration bearbeitbar oder schreibgeschützt.

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

### T-N2: Passwort ändern

**Schritte:**
1. Profil → „Passwort ändern".
2. Aktuelles Passwort und neues Passwort eingeben (z. B. `NeuerTest456!`).
3. Speichern.
4. Ausloggen und mit dem neuen Passwort anmelden.

**Erwartetes Ergebnis:**
- Flash-Meldung, neues Passwort funktioniert, altes nicht mehr.

> Nach dem Test: mit `php scripts\seed-test-users.php` das Standardpasswort wiederherstellen.

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

### T-N3: 2FA aktivieren (optional)

**Schritte:**
1. Profil → „Zwei-Faktor-Authentifizierung aktivieren" (`/2fa-setup`).
2. QR-Code mit einem Authenticator-App-Gerät (Google Authenticator, Authy, Aegis) scannen.
3. Einmaligen Code zur Bestätigung eingeben.
4. Ausloggen, erneut einloggen — bei der Login-Abfrage erscheint die 2FA-Seite.

**Erwartetes Ergebnis:**
- Nach dem Passwort wird der 6-stellige TOTP-Code abgefragt.
- Nur mit gültigem Code geht es weiter aufs Dashboard.

> Anschließend 2FA wieder deaktivieren, damit die anderen Testflows nicht gestört sind — falls der Test-User dafür vorgesehen ist.

**Bestanden:** [  ]    **Nicht bestanden:** [  ]    **Datum:** _______

---

## 17. Abschluss-Checkliste

Nach dem Durchlauf bitte die folgenden Punkte querprüfen:

| Bereich | Erwartung | Erfüllt |
|---------|-----------|---------|
| Anmeldung, Passwort-Reset, Lockout | Alle drei Flows gelaufen | [  ] |
| Antrags-Lebenszyklus | Entwurf → Eingereicht → Freigegeben → Storniert ohne Fehler | [  ] |
| Alle sechs Prüfer-Aktionen | Freigeben, Ablehnen, Rückfragen, Zurück, Korrigieren, Stornieren | [  ] |
| Selbstgenehmigungs-Verbot | Keine Freigabe auf eigenem Antrag möglich | [  ] |
| Dialog-Integrität | Nachrichten nach allen Statuswechseln erhalten | [  ] |
| Mitgliederverwaltung | Anlage, Einladung, Aktivieren/Deaktivieren | [  ] |
| CSV-Import | Report zeigt Erfolg und Fehler | [  ] |
| Kategorien, Soll-Stunden, Einstellungen | Pflege funktioniert, Test-Mail kommt an | [  ] |
| Reports | Filter, CSV, PDF funktionieren | [  ] |
| Audit-Log | Alle Test-Aktionen sichtbar, nicht editierbar | [  ] |
| Events (End-to-End) | Anlage, Zusage, Freigabe, Abschluss | [  ] |
| Event-Vorlagen | Ableitung eines Events aus Vorlage | [  ] |
| iCal | Feed-Abo und Einzel-Export liefern Daten | [  ] |
| Profil | Passwort-Änderung, optional 2FA | [  ] |

---

### Zusammenfassung des Testers

**Durchlaufen am:** ____________

**Getestet von:** ____________

**Gesamteindruck / kritische Befunde:**

```
(Freitext für Anmerkungen, z. B. gefundene Fehler, Unklarheiten in der Bedienung,
fehlende Sprach-Konsistenz, Performance-Auffälligkeiten.)
```

**Empfehlung:**

- [ ] Abnahme empfohlen
- [ ] Abnahme mit Auflagen (siehe Notizen)
- [ ] Abnahme verweigert

**Unterschrift / Kürzel:** ____________

---

*Stand: Version 1.4.0 — Stand des Testskripts entspricht dem Funktionsumfang nach Merge von Modul 6–8 (Events, Multitab-Härtung, E2E-Suite).*
