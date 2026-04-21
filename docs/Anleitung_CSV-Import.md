# Anleitung: Mitgliederliste per CSV aus Excel importieren

**VAES - Vereins-Arbeitsstunden-Erfassungssystem**
Version 1.3

---

## 1. Ueberblick

Mit der CSV-Import-Funktion koennen Sie mehrere Mitglieder gleichzeitig in VAES anlegen. Die Mitgliederdaten werden in Microsoft Excel vorbereitet, als CSV-Datei gespeichert und anschliessend in VAES hochgeladen.

**Was passiert beim Import?**

- Neue Mitglieder werden automatisch angelegt und erhalten die Rolle **Mitglied**.
- Jedes neue Mitglied erhaelt eine **Einladungs-E-Mail** mit einem Link zum Setzen des Passworts.
- Bereits vorhandene Mitgliedsnummern werden **nicht doppelt angelegt**, sondern die Stammdaten (Name, Adresse, etc.) werden aktualisiert.

---

## 2. CSV-Datei in Excel vorbereiten

### Schritt 1: Neue Excel-Arbeitsmappe oeffnen

Oeffnen Sie Microsoft Excel und erstellen Sie eine neue, leere Arbeitsmappe.

### Schritt 2: Spaltenkoepfe in Zeile 1 eintragen

Tragen Sie in die **erste Zeile** exakt folgende Spaltenkoepfe ein:

| Spalte | Zelle | Spaltenname | Pflichtfeld |
|--------|-------|-------------|-------------|
| A | A1 | `mitgliedsnummer` | Ja |
| B | B1 | `nachname` | Ja |
| C | C1 | `vorname` | Ja |
| D | D1 | `email` | Ja |
| E | E1 | `strasse` | Nein |
| F | F1 | `plz` | Nein |
| G | G1 | `ort` | Nein |
| H | H1 | `telefon` | Nein |
| I | I1 | `eintrittsdatum` | Nein |

**Wichtig:**
- Die Spaltennamen muessen **exakt** wie angegeben geschrieben werden (Kleinschreibung, keine Umlaute, keine Leerzeichen).
- Die **Reihenfolge** der Spalten ist beliebig.
- Optionale Spalten koennen weggelassen werden.

### Schritt 3: Mitgliederdaten eintragen

Tragen Sie ab **Zeile 2** die Daten Ihrer Mitglieder ein. Jede Zeile entspricht einem Mitglied.

**Beispiel:**

| A | B | C | D | E | F | G | H | I |
|---|---|---|---|---|---|---|---|---|
| mitgliedsnummer | nachname | vorname | email | strasse | plz | ort | telefon | eintrittsdatum |
| M-001 | Mueller | Max | max.mueller@example.com | Hauptstr. 1 | 12345 | Berlin | 0170-1234567 | 2024-01-15 |
| M-002 | Schmidt | Anna | anna.schmidt@example.com | Bahnhofstr. 5 | 54321 | Hamburg | 0171-9876543 | 2024-03-01 |
| M-003 | Weber | Thomas | t.weber@example.com | | | | | |

```
  ┌──────────────────────────────────────────────────────────────────────────────────────┐
  │  A              B           C         D                        E            ...      │
  ├──────────────────────────────────────────────────────────────────────────────────────┤
  │1 mitgliedsnummer nachname    vorname   email                   strasse       ...     │
  │2 M-001           Mueller     Max       max.mueller@example.com Hauptstr. 1   ...     │
  │3 M-002           Schmidt     Anna      anna.schmidt@example.com Bahnhofstr. 5 ...    │
  │4 M-003           Weber       Thomas    t.weber@example.com                   ...     │
  └──────────────────────────────────────────────────────────────────────────────────────┘
```

**Hinweise zu den Feldern:**

| Feld | Format / Regeln | Beispiel |
|------|-----------------|---------|
| `mitgliedsnummer` | Frei waehlbar, muss eindeutig sein | M-001, 1001, TSC-042 |
| `nachname` | Text | Mueller |
| `vorname` | Text | Max |
| `email` | Gueltige E-Mail-Adresse, muss eindeutig sein | max@example.com |
| `strasse` | Text (optional) | Hauptstr. 1 |
| `plz` | Text (optional) | 12345 |
| `ort` | Text (optional) | Berlin |
| `telefon` | Text (optional) | 0170-1234567 |
| `eintrittsdatum` | Format: **JJJJ-MM-TT** (optional) | 2024-01-15 |

**Haeufige Fehlerquellen:**
- Das Eintrittsdatum muss im Format **JJJJ-MM-TT** stehen (z.B. `2024-01-15`), nicht im deutschen Format (15.01.2024).
- E-Mail-Adressen muessen gueltig sein (mit @-Zeichen und Domain).
- Mitgliedsnummern und E-Mail-Adressen duerfen nicht doppelt vorkommen.

---

## 3. Excel-Datei als CSV speichern

### Option A: CSV UTF-8 (empfohlen)

1. Klicken Sie auf **Datei > Speichern unter** (oder **Datei > Kopie speichern**).
2. Waehlen Sie den gewuenschten Speicherort.
3. Waehlen Sie als Dateityp: **CSV UTF-8 (durch Trennzeichen getrennt) (*.csv)**.
4. Vergeben Sie einen Dateinamen (z.B. `Mitgliederliste.csv`).
5. Klicken Sie auf **Speichern**.
6. Bestaetigen Sie eventuelle Warnmeldungen mit **Ja**.

```
  ┌────────────────────────────────────────────────────────────┐
  │  Speichern unter                                           │
  │                                                            │
  │  Dateiname:  [Mitgliederliste.csv              ]           │
  │                                                            │
  │  Dateityp:   [CSV UTF-8 (durch Trennzeichen    ▼]         │
  │               getrennt) (*.csv)                            │
  │                                            [Speichern]     │
  └────────────────────────────────────────────────────────────┘
```

### Option B: CSV mit Semikolon-Trennung

Falls Ihre Excel-Version kein "CSV UTF-8" anbietet:

1. Klicken Sie auf **Datei > Speichern unter**.
2. Waehlen Sie als Dateityp: **CSV (Trennzeichen-getrennt) (*.csv)**.
3. Klicken Sie auf **Speichern**.

Bei deutschen Excel-Versionen wird automatisch das **Semikolon** als Trennzeichen verwendet. VAES erkennt beide Trennzeichen (Komma und Semikolon) automatisch.

### Ergebnis pruefen (optional)

Oeffnen Sie die gespeicherte CSV-Datei mit einem Texteditor (z.B. Notepad), um das Ergebnis zu pruefen. Der Inhalt sollte so aussehen:

**Komma-getrennt:**
```
mitgliedsnummer,nachname,vorname,email,strasse,plz,ort,telefon,eintrittsdatum
M-001,Mueller,Max,max.mueller@example.com,Hauptstr. 1,12345,Berlin,0170-1234567,2024-01-15
M-002,Schmidt,Anna,anna.schmidt@example.com,Bahnhofstr. 5,54321,Hamburg,0171-9876543,2024-03-01
```

**Semikolon-getrennt:**
```
mitgliedsnummer;nachname;vorname;email;strasse;plz;ort;telefon;eintrittsdatum
M-001;Mueller;Max;max.mueller@example.com;Hauptstr. 1;12345;Berlin;0170-1234567;2024-01-15
M-002;Schmidt;Anna;anna.schmidt@example.com;Bahnhofstr. 5;54321;Hamburg;0171-9876543;2024-03-01
```

Beide Varianten werden von VAES akzeptiert.

---

## 4. CSV-Datei in VAES importieren

### Schritt 1: Import-Seite oeffnen

1. Melden Sie sich in VAES als **Administrator** an.
2. Navigieren Sie zu **Verwaltung > Mitglieder**.
3. Klicken Sie auf den Button **CSV-Import**.

### Schritt 2: Datei hochladen

1. Klicken Sie auf **Durchsuchen** (oder **Datei auswaehlen**).
2. Waehlen Sie die vorbereitete CSV-Datei aus.
3. Klicken Sie auf **Import starten**.

```
  ┌─────────────────────────────────────────────────────────────┐
  │  CSV-Import                                                 │
  │                                                             │
  │  CSV-Datei:                                                 │
  │  ┌──────────────────────────────────┐                       │
  │  │  Mitgliederliste.csv             │  [Durchsuchen]        │
  │  └──────────────────────────────────┘                       │
  │                                                             │
  │            [Import starten]                                 │
  └─────────────────────────────────────────────────────────────┘
```

### Schritt 3: Ergebnis pruefen

Nach dem Import zeigt VAES eine Ergebnis-Uebersicht mit vier Statusanzeigen:

| Anzeige | Bedeutung |
|---------|-----------|
| **Neu erstellt** (gruen) | Anzahl erfolgreich angelegter Mitglieder |
| **Aktualisiert** (blau) | Anzahl aktualisierter bestehender Mitglieder |
| **Fehler** (rot) | Anzahl fehlerhafter Zeilen (mit Detailangabe) |
| **Uebersprungen** (grau) | Anzahl uebersprungener Zeilen |

```
  ┌─────────────────────────────────────────────────────────────┐
  │  Import-Ergebnis                                            │
  │                                                             │
  │  ┌────────────┐ ┌────────────┐ ┌────────────┐ ┌──────────┐ │
  │  │     8      │ │     2      │ │     1      │ │    0     │ │
  │  │ Neu erstellt│ │Aktualisiert│ │  Fehler    │ │Ueberspr. │ │
  │  │   (gruen)  │ │   (blau)   │ │   (rot)    │ │  (grau)  │ │
  │  └────────────┘ └────────────┘ └────────────┘ └──────────┘ │
  │                                                             │
  │  Fehler-Details:                                            │
  │  ┌──────────────────────────────────────────────────────┐   │
  │  │ Zeile 5: Ungueltige E-Mail-Adresse                  │   │
  │  └──────────────────────────────────────────────────────┘   │
  │                                                             │
  │  [Neuer Import]            [Zur Mitgliederliste]            │
  └─────────────────────────────────────────────────────────────┘
```

Bei Fehlern wird unterhalb der Statistik eine Tabelle mit Zeilennummer und Fehlerbeschreibung angezeigt. Korrigieren Sie die betroffenen Zeilen in Excel und fuehren Sie den Import erneut durch - bereits angelegte Mitglieder werden dabei nicht doppelt erstellt, sondern nur aktualisiert.

---

## 5. Was passiert nach dem Import?

| Aktion | Beschreibung |
|--------|--------------|
| **Rolle** | Alle neuen Mitglieder erhalten automatisch die Rolle **Mitglied**. Weitere Rollen (Pruefer, Erfasser, etc.) muessen einzeln in der Mitgliederverwaltung zugewiesen werden. |
| **Einladungs-E-Mail** | Jedes neue Mitglied erhaelt eine E-Mail mit einem persoenlichen Einladungslink. Dieser Link ist standardmaessig **7 Tage** gueltig. |
| **Passwort** | Das Mitglied setzt sein eigenes Passwort ueber den Einladungslink. |
| **2FA** | Nach dem Setzen des Passworts wird das Mitglied zur Einrichtung der Zwei-Faktor-Authentifizierung weitergeleitet. |
| **Aktualisierte Mitglieder** | Bestehende Mitglieder (erkannt an der Mitgliedsnummer) erhalten **keine** erneute Einladungs-E-Mail. Nur die Stammdaten werden aktualisiert. |
| **Audit-Trail** | Der gesamte Import wird im Audit-Trail protokolliert (wer, wann, wie viele Datensaetze). |

---

## 6. Tipps und Fehlerbehebung

### Datumsformat in Excel korrigieren

Excel formatiert Datumswerte oft automatisch im deutschen Format (TT.MM.JJJJ). VAES erwartet jedoch das Format **JJJJ-MM-TT**. So stellen Sie das richtige Format ein:

1. Markieren Sie die Spalte **eintrittsdatum** (Spalte I).
2. Klicken Sie mit der rechten Maustaste und waehlen Sie **Zellen formatieren**.
3. Waehlen Sie die Kategorie **Benutzerdefiniert**.
4. Geben Sie als Typ ein: `JJJJ-MM-TT`
5. Klicken Sie auf **OK**.

Alternativ koennen Sie das Eintrittsdatum direkt als Text eingeben, indem Sie die Zelle vorher als **Text** formatieren oder ein Apostroph voranstellen: `'2024-01-15`

### Umlaute und Sonderzeichen

VAES unterstuetzt Umlaute (ae, oe, ue, ss) und andere Sonderzeichen. Speichern Sie die Datei als **CSV UTF-8**, damit alle Zeichen korrekt uebertragen werden. Falls Sie die Datei als normales CSV speichern (ISO-8859-1 / Windows-1252), konvertiert VAES die Zeichenkodierung automatisch.

### Haeufige Fehlermeldungen

| Fehlermeldung | Ursache | Loesung |
|---------------|---------|---------|
| Pflichtfeld 'email' fehlt im CSV-Header | Spaltenname falsch geschrieben | Spaltenkoepfe pruefen (exakte Schreibweise, Kleinbuchstaben) |
| Pflichtfeld 'nachname' ist leer | Zelle ohne Inhalt | Fehlende Daten in der betroffenen Zeile nachtragen |
| Ungueltige E-Mail-Adresse | E-Mail-Format fehlerhaft | Pruefen Sie @-Zeichen und Domain (z.B. .de, .com) |
| Ungueltiges Datumsformat fuer 'eintrittsdatum' | Nicht im Format JJJJ-MM-TT | Datum im Format 2024-01-15 eingeben |
| CSV-Datei enthaelt keine Daten | Nur Kopfzeile vorhanden | Mindestens eine Datenzeile unterhalb der Kopfzeile eintragen |

### Wiederholter Import

Sie koennen den Import beliebig oft wiederholen. VAES erkennt anhand der **Mitgliedsnummer**, ob ein Mitglied bereits existiert:

- **Neue Mitgliedsnummer:** Mitglied wird angelegt + Einladung versendet.
- **Vorhandene Mitgliedsnummer:** Stammdaten werden aktualisiert, keine erneute Einladung.

Dies ermoeglicht es, eine korrigierte CSV-Datei erneut zu importieren, ohne dass Duplikate entstehen.

---

## 7. Kurzanleitung (Zusammenfassung)

1. **Excel oeffnen** und Spaltenkoepfe in Zeile 1 eintragen:
   `mitgliedsnummer`, `nachname`, `vorname`, `email` (Pflicht) sowie optional `strasse`, `plz`, `ort`, `telefon`, `eintrittsdatum`.

2. **Mitgliederdaten** ab Zeile 2 eintragen.

3. **Speichern unter** > Dateityp: **CSV UTF-8 (durch Trennzeichen getrennt)**.

4. In VAES einloggen > **Verwaltung > Mitglieder > CSV-Import**.

5. CSV-Datei auswaehlen und **Import starten** klicken.

6. **Ergebnis pruefen** - bei Fehlern: CSV korrigieren und erneut importieren.

---

*VAES - Anleitung CSV-Import | Version 1.3 | Februar 2026*
