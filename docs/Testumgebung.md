# VAES — Testumgebung

Komplett-Workflow fuer die lokale End-to-End-Testumgebung mit
anonymisierter DB-Kopie, Playwright und UX-Analyzer (RTX-GPU).

## Architektur

```
Strato phpMyAdmin               Entwickler-PC (Win11 + WAMP/XAMPP)
┌─────────────────────┐         ┌──────────────────────────────────┐
│ Produktions-DB      │  Export │ storage/strato-dumps/*.sql       │
│ (rdbms.strato.de)   │ ──────> │                                  │
└─────────────────────┘         │                                  │
                                │  php scripts/import-strato-db.php │
                                │          │                        │
                                │          v                        │
                                │     vaes (MySQL 8, lokal)         │
                                │          │                        │
                                │   mysql < anonymize-db.sql        │
                                │   php scripts/seed-test-users.php │
                                │          │                        │
                                │          v                        │
                                │   composer setup:test-db -> vaes_test │
                                │                                  │
                                │   run-tests.ps1 orchestriert:    │
                                │     - MailPit  (1025/8025)        │
                                │     - PHP-Server (8000)           │
                                │     - Playwright -> Screenshots   │
                                │     - UX-Analyzer (RTX) -> JSON   │
                                └──────────────────────────────────┘
```

## Voraussetzungen

| Komponente | Version | Hinweis |
|------------|---------|---------|
| Windows | 10/11 | PowerShell 5.1+ |
| WAMPServer / XAMPP | aktuell | MySQL 8 + PHP 8.1+ |
| PHP | 8.1+ | Extensions: pdo_mysql, mbstring, openssl, json, fileinfo |
| Composer | 2.x | `cd src && composer install` |
| Node.js | 18+ LTS | `cd e2e-tests && npm install` |
| Python | **3.13** (NICHT 3.14) | CUDA-Wheels fehlen fuer 3.14 |
| NVIDIA-Treiber | 550+ | `nvidia-smi` pruefen |
| NVIDIA GPU | ≥ 12 GB VRAM (empfohlen), ≥ 4 GB (Minimum) | RTX 3060 aufwaerts; RTX 4090 ideal |

## Einmaliges Setup (erste Installation)

### 1. Repository + Composer
```powershell
git clone https://github.com/mschmick2/TSC-Helferstundenverwaltung.git
cd TSC-Helferstundenverwaltung
Copy-Item src\config\config.example.php src\config\config.php
# Zugangsdaten in config.php eintragen (nur DB-Host 127.0.0.1!)
cd src
composer install
cd ..
```

### 2. .env anlegen
```powershell
Copy-Item .env.example .env
# Werte pruefen
```

### 3. Node-Abhaengigkeiten (Playwright)
```powershell
cd e2e-tests
npm install
node install-browser.js
cd ..
```

### 4. Python-UX-Analyzer
```powershell
cd tools\ux-analyzer
.\setup.bat
.\venv\Scripts\activate.bat
python verify-gpu.py
cd ..\..
```

Beispiel-Ausgabe bei RTX 4090:
```
Device 0:     NVIDIA GeForce RTX 4090
VRAM:         24.0 GB
✓ GPU-Verifikation erfolgreich.
```

Beispiel-Ausgabe bei RTX 3060 (12 GB):
```
Device 0:     NVIDIA GeForce RTX 3060
VRAM:         12.0 GB
  Hinweis: 12.0 GB VRAM unter Empfehlung 12 GB - CLIP laeuft, aber langsamer.
✓ GPU-Verifikation erfolgreich.
```

### 5. MailPit
Wird automatisch durch `run-tests.ps1` heruntergeladen.
Manuell: https://github.com/axllent/mailpit/releases → `mailpit-windows-amd64.zip`
→ entpacken nach `tools/mailpit/mailpit.exe`.

## Strato-DB-Kopie lokal einspielen

### Schritt 1 — Export aus phpMyAdmin

1. Strato-Kundencenter → phpMyAdmin der VAES-Datenbank oeffnen
2. Links die Datenbank auswaehlen (z.B. `DBxxxxxxxx`)
3. Oben den Reiter **Exportieren** waehlen
4. Exportmethode **Angepasst** auswaehlen
5. Folgende Optionen setzen:
   - Format: **SQL**
   - Kompression: keine (oder gzip)
   - **"Add DROP TABLE / VIEW / PROCEDURE / FUNCTION / EVENT"** aktivieren
   - **"Struktur und Daten"** waehlen (nicht nur Struktur)
   - Unter "Objekterstellung": *CREATE PROCEDURE / FUNCTION / EVENT* aktiv,
     *CREATE TRIGGER* aktiv
   - Zeichensatz: **utf-8**
6. Klick auf **Exportieren** → `vaes-prod.sql` wird heruntergeladen

### Schritt 2 — Datei ablegen

```powershell
New-Item -ItemType Directory -Force storage\strato-dumps
Move-Item $HOME\Downloads\vaes-prod.sql storage\strato-dumps\
```

### Schritt 3 — Import + Anonymisierung + Seed

```powershell
# WAMP/XAMPP gestartet? Dev-Server gestoppt?
php scripts\import-strato-db.php storage\strato-dumps\vaes-prod.sql
php scripts\anonymize-db.php
php scripts\seed-test-users.php
```

Anschliessend: Test-DB klonen.
```powershell
cd src
composer setup:test-db
cd ..
```

**Ergebnis:**
- `vaes` enthaelt anonymisierte Prod-Daten + 3 Testbenutzer
- `vaes_test` ist leeres Schema-Klon (fuer PHPUnit Integration/Feature)

## Testbenutzer nach Seed

| Email | Passwort | Rollen |
|-------|----------|--------|
| admin@vaes.test | `TestPass123!` | administrator, mitglied |
| pruefer@vaes.test | `TestPass123!` | pruefer, mitglied |
| mitglied@vaes.test | `TestPass123!` | mitglied |

Alle mit deaktiviertem 2FA.
Anderes Passwort setzen: `TEST_USER_PASSWORD=Foo! php scripts\seed-test-users.php`

## Taeglicher Workflow

### Voll-Run (Services starten → E2E → UX → stoppen)
```powershell
.\scripts\run-tests.ps1
```

### Interaktive Entwicklung (Services starten, Tests manuell triggern)
```powershell
.\scripts\run-tests.ps1 -Action services      # MailPit + PHP-Server
# Terminal 2:
cd e2e-tests
npm run test:headed                             # Browser sichtbar
# Fertig:
.\scripts\run-tests.ps1 -Action stop
```

### Einzelne Tests
```powershell
# PHPUnit
cd src
composer test:unit
composer test:feature
composer test:security

# Playwright Einzelspec
cd e2e-tests
node run-tests.js tests/03-dashboard.spec.js

# UX auf existierenden Screenshots
.\scripts\run-tests.ps1 -Action ux
```

### Umgebungspruefung
```powershell
.\scripts\run-tests.ps1 -Action verify
```

## Go-Live-Umschaltung (Produktivbetrieb)

Wenn die Strato-DB vom Test- zum Produktivbetrieb umgeschaltet wird:

1. **Backup** der aktuellen Prod-DB via phpMyAdmin-Export sichern
2. In phpMyAdmin: Datenbank waehlen → SQL-Tab → Inhalt von
   `scripts/prod-reset-for-golive.sql` einfuegen → **Go**
3. Nach dem Reset sind geloescht/geleert:
   - Alle Nicht-Admin-User inkl. Rollen-Zuweisungen
   - Alle Antraege (`work_entries`), Dialoge (`work_entry_dialogs`,
     `dialog_read_status`) und Entry-Locks
   - **Jahresziele (`yearly_targets`)** — Admin muss fuer das laufende
     Vereinsjahr die Soll-Stunden neu eintragen
   - Antrags-Nummern-Sequenz (`entry_number_sequence`) — naechster Antrag
     beginnt wieder bei 0001
   - Alle Sessions, Passwort-Resets, E-Mail-Verifikationen, Einladungen,
     Rate-Limits
   - Das komplette `audit_log` — ab hier beginnt die Compliance-relevante
     Historie bei null (append-only-Trigger bleibt aktiv)
4. Erhalten bleiben:
   - Administrator-User + deren Rollen-Zuweisungen
   - `roles` (Rollenkatalog), `categories` (Kategorien), `settings`
     (Systemeinstellungen)
5. Nachbereitung durch Admin:
   - Admin-Passwoerter rotieren
   - Jahresziele fuer das aktuelle Jahr in Admin-UI neu setzen
   - Kategorien pruefen und ggf. anpassen
   - Mitglieder-Import starten (Admin → Mitglieder → CSV-Import oder
     manuelle Anlage)

## Troubleshooting

### "Port 8000 belegt"
```powershell
Get-NetTCPConnection -LocalPort 8000
# PID ermitteln, dann:
Stop-Process -Id <PID> -Force
# Oder:
.\scripts\run-tests.ps1 -Action stop
```

### "MySQL-CLI nicht gefunden"
WAMP-Installation pruefen. Oder `C:\wamp64\bin\mysql\mysql8.x\bin`
ins PATH eintragen.

### MailPit startet nicht
- Port-Kollision: `Get-NetTCPConnection -LocalPort 1025,8025`
- Logs: `storage\mailpit.log`
- Manueller Start: `tools\mailpit\mailpit.exe`

### `torch.cuda.is_available()` = False
- NVIDIA-Treiber: `nvidia-smi` muss laufen
- Python-Version: `py -3.13 --version` (NICHT 3.14!)
- PyTorch neu installieren:
  ```powershell
  cd tools\ux-analyzer
  .\venv\Scripts\activate.bat
  pip uninstall -y torch torchvision
  pip install torch torchvision --index-url https://download.pytorch.org/whl/cu126
  python verify-gpu.py
  ```

### Playwright-Login scheitert
- Seed-Script gelaufen? `php scripts\seed-test-users.php`
- 2FA am Testuser deaktiviert? (Seed macht das automatisch)
- Browser-Cookie veraltet? `rm e2e-tests/auth-state.json`

### Anonymize-SQL schlaegt fehl
- Prod-Hostname-Check triggert: Script verweigert gegen `DB*`-benannte DBs.
  DB heisst lokal `vaes`, nicht `DBxxxxxxxx` → pruefen.

## Sicherheitshinweise

- `storage/strato-dumps/` ist gitignored — **niemals committen** (PII)
- `config.php` ist gitignored — **niemals committen** (Credentials)
- Test-Benutzer-Passwort `TestPass123!` ist **bewusst schwach** und nur fuer
  lokale Tests. Auf Produktion wird es durch den Reset + manuellen
  Passwortwechsel des Admins ueberschrieben.
- MailPit faengt alle SMTP-Nachrichten ab — keine E-Mail verlaesst den
  Rechner waehrend der Testumgebung laeuft (Bind 127.0.0.1:1025/8025).

## DSGVO / PII-Handhabung

Der Export-Workflow transportiert Produktionsdaten auf die Entwicklerhardware.
Folgende Regeln sind einzuhalten:

**Rechtsgrundlage:** Die Verarbeitung erfolgt auf Basis von Art. 6 Abs. 1
lit. f DSGVO (berechtigtes Interesse an funktionierender Qualitaetssicherung).
Die Anonymisierung laeuft **unmittelbar** nach dem Import, sodass ab diesem
Zeitpunkt kein Personenbezug mehr besteht.

**Prozess-Disziplin:**

1. Der Import-Schritt (`import-strato-db.php`) erzeugt ein **temporaeres
   PII-Fenster** in der lokalen `vaes`-DB. Dieses Fenster sollte **so kurz wie
   moeglich** gehalten werden.
2. Direkt nach `import-strato-db.php` **muss** `anonymize-db.php` laufen.
   Die drei Scripts werden nach Moeglichkeit in einem Rutsch ausgefuehrt:
   ```powershell
   php scripts\import-strato-db.php storage\strato-dumps\vaes-prod.sql `
     ; php scripts\anonymize-db.php `
     ; php scripts\seed-test-users.php
   ```
3. Nach erfolgreichem Import + Anonymisierung **Dump-Datei loeschen**
   (sofern kein erneuter Re-Import geplant ist):
   ```powershell
   Remove-Item storage\strato-dumps\vaes-prod.sql
   ```
4. Was anonymisiert wird (vollstaendige Liste):
   - `users`: email, vorname, nachname, mitgliedsnummer, strasse, plz, ort,
     telefon, eintrittsdatum, password_hash, totp_secret, 2FA-Flags,
     Session-Metadaten
   - `work_entries.description`
   - `work_entry_dialogs.message`
   - `audit_log.description` / `details` / `ip_address` / `user_agent`
   - `sessions`, `password_resets`, `email_verification_codes`,
     `user_invitations`, `rate_limits` werden **geloescht** (nicht
     ueberschrieben)

**Was NICHT weitergegeben werden darf:**

- Strato-Dump-Dateien an Kollegen (weder per E-Mail, Teams, Cloud-Sync)
- Screenshots mit Pre-Anonymize-Daten (falls welche im Import-Fenster
  entstehen — normalerweise kein Grund dafuer)
- Backups der `vaes_backup_*`-DBs (bleiben auf der Maschine)

**Auftragsverarbeitung (Art. 28):** Die lokale Verarbeitung auf
Entwicklerhardware ist keine Auftragsverarbeitung im Sinne der DSGVO
(gleiche verantwortliche Stelle). Der Strato-Hostingvertrag deckt die
Produktionsseite ab.
