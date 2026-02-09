# VAES - Setup-Anleitung

## Vereins-Arbeitsstunden-Erfassungssystem

**Version:** 1.1.0  
**Stand:** 2025-02-09

---

## Inhaltsverzeichnis

1. [Entwicklungsumgebung einrichten](#1-entwicklungsumgebung-einrichten)
2. [Git-Repository initialisieren](#2-git-repository-initialisieren)
3. [Backup-System konfigurieren](#3-backup-system-konfigurieren)
4. [Datenbank einrichten](#4-datenbank-einrichten)
5. [Deployment auf Strato](#5-deployment-auf-strato)
6. [Verzeichnisstruktur](#6-verzeichnisstruktur)
7. [Konfiguration](#7-konfiguration)
8. [Troubleshooting](#8-troubleshooting)

---

## 1. Entwicklungsumgebung einrichten

### Voraussetzungen

- Windows 10/11
- Visual Studio Code mit Claude Code Extension
- Git für Windows
- PHP 8.x lokal installiert (optional, für lokales Testen)
- Zugriff auf NAS-Laufwerk `Y:\software_mondial`

### Projekt-Verzeichnis erstellen

```powershell
# PowerShell als Administrator öffnen
New-Item -ItemType Directory -Path "E:\TSC-Helferstundenverwaltung" -Force
cd E:\TSC-Helferstundenverwaltung
```

### VSCode öffnen

```powershell
code E:\TSC-Helferstundenverwaltung
```

---

## 2. Git-Repository initialisieren

### Lokales Repository

```bash
cd E:\TSC-Helferstundenverwaltung
git init
git config user.name "Dein Name"
git config user.email "deine@email.de"
```

### .gitignore erstellen

Die Datei `.gitignore` sollte folgende Einträge enthalten:

```gitignore
# Abhängigkeiten
/vendor/
/node_modules/

# Konfiguration mit sensiblen Daten
/src/config/config.local.php
/src/config/.env

# Logs und Cache
/src/storage/logs/*
/src/storage/cache/*
!.gitkeep

# IDE
.vscode/
.idea/
*.swp
*.swo

# OS
.DS_Store
Thumbs.db

# Backups
*.bak
*.backup

# Temporäre Dateien
*.tmp
*.temp
```

### Erster Commit

```bash
git add .
git commit -m "Initial commit: Projektstruktur und Pflichtenheft"
```

### Remote Repository verbinden

```bash
# Repository ist bereits eingerichtet:
git remote add origin https://github.com/mschmick2/TSC-Helferstundenverwaltung.git
git branch -M main
git push -u origin main
```

---

## 3. Backup-System konfigurieren

### Backup-Verzeichnis auf NAS vorbereiten

Stellen Sie sicher, dass das Laufwerk Y: verbunden ist und der Pfad erreichbar ist:

```powershell
# Testen der Verbindung
Test-Path "Y:\software_mondial\TSC-Helferstundenverwaltung"

# Falls nicht vorhanden, Verzeichnis erstellen (benötigt Schreibrechte)
New-Item -ItemType Directory -Path "Y:\software_mondial\TSC-Helferstundenverwaltung" -Force
New-Item -ItemType Directory -Path "Y:\software_mondial\TSC-Helferstundenverwaltung\current" -Force
New-Item -ItemType Directory -Path "Y:\software_mondial\TSC-Helferstundenverwaltung\daily" -Force
New-Item -ItemType Directory -Path "Y:\software_mondial\TSC-Helferstundenverwaltung\logs" -Force
```

**Hinweis:** Falls Laufwerk Y: nicht gemappt ist:
```powershell
# NAS-Share als Laufwerk Y: verbinden
net use Y: \\NAS-NAME\software_mondial /persistent:yes
```

### Windows Task Scheduler einrichten

1. **Task Scheduler öffnen:**
   - `Win + R` → `taskschd.msc` → Enter

2. **Neue Aufgabe erstellen:**
   - Rechtsklick auf "Aufgabenplanungsbibliothek" → "Aufgabe erstellen..."

3. **Registerkarte "Allgemein":**
   - Name: `VAES Backup`
   - Beschreibung: `Stündliches Backup des VAES-Projekts auf NAS`
   - "Unabhängig von der Benutzeranmeldung ausführen" aktivieren
   - "Mit höchsten Privilegien ausführen" aktivieren

4. **Registerkarte "Trigger":**
   - "Neu..." klicken
   - "Täglich" auswählen
   - Startzeit: `00:00:00`
   - "Wiederholen alle:" aktivieren → `1 Stunde`
   - "Für die Dauer von:" → `Unbegrenzt`
   - "Aktiviert" anhaken

5. **Registerkarte "Aktionen":**
   - "Neu..." klicken
   - Programm/Skript: `E:\TSC-Helferstundenverwaltung\scripts\backup.bat`
   - Starten in: `E:\TSC-Helferstundenverwaltung\scripts`

6. **Registerkarte "Bedingungen":**
   - "Nur starten, wenn Netzwerkverbindung verfügbar" aktivieren

7. **Registerkarte "Einstellungen":**
   - "Aufgabe so schnell wie möglich nach einem verpassten Start ausführen" aktivieren

8. **OK klicken** und Windows-Passwort eingeben

### Backup manuell testen

```powershell
# PowerShell als Administrator
E:\TSC-Helferstundenverwaltung\scripts\backup.bat

# Oder direkt PowerShell-Script
powershell -ExecutionPolicy Bypass -File "E:\TSC-Helferstundenverwaltung\scripts\backup.ps1"
```

---

## 4. Datenbank einrichten

### Schritt 1: phpMyAdmin öffnen

1. Loggen Sie sich in Ihr Strato-Kundenmenü ein
2. Navigieren Sie zu "Datenbanken" → "phpMyAdmin"
3. Wählen Sie Ihre MySQL-Datenbank aus

### Schritt 2: SQL-Script ausführen

1. Klicken Sie auf den Tab **"SQL"**
2. Öffnen Sie die Datei `scripts/database/create_database.sql`
3. Kopieren Sie den gesamten Inhalt
4. Fügen Sie ihn in das SQL-Eingabefeld ein
5. Klicken Sie auf **"OK"** zum Ausführen

### Schritt 3: Admin-Zugangsdaten

Nach erfolgreicher Ausführung:

| Feld | Wert |
|------|------|
| E-Mail | `admin@example.com` |
| Passwort | `Admin123!` |

**⚠️ WICHTIG:** Ändern Sie das Admin-Passwort sofort nach dem ersten Login!

### Schritt 4: Admin-E-Mail anpassen

```sql
-- In phpMyAdmin ausführen:
UPDATE users 
SET email = 'ihre-echte@email.de' 
WHERE mitgliedsnummer = 'ADMIN001';
```

---

## 5. Deployment auf Strato

### Voraussetzungen

- FileZilla (oder anderer FTP-Client)
- Strato FTP-Zugangsdaten

### FTP-Verbindung einrichten

| Einstellung | Wert |
|-------------|------|
| Server | `ftp.strato.de` oder Ihre Domain |
| Benutzername | Aus Strato-Kundenmenü |
| Passwort | Aus Strato-Kundenmenü |
| Port | 21 (oder 22 für SFTP) |

### Verzeichnisse übertragen

**Diese Verzeichnisse auf den Webserver hochladen:**

```
src/
├── public/          ← In das Web-Root (z.B. /htdocs oder /public_html)
│   ├── .htaccess    ✅ HOCHLADEN
│   ├── index.php    ✅ HOCHLADEN
│   ├── css/         ✅ HOCHLADEN
│   ├── js/          ✅ HOCHLADEN
│   └── assets/      ✅ HOCHLADEN
│
├── app/             ✅ HOCHLADEN (mit .htaccess!)
├── config/          ✅ HOCHLADEN (mit .htaccess!)
├── vendor/          ✅ HOCHLADEN (mit .htaccess!)
└── storage/         ✅ HOCHLADEN (mit .htaccess!)
```

**Diese Verzeichnisse NICHT hochladen:**

```
❌ .git/
❌ docs/
❌ scripts/
❌ tests/
❌ node_modules/
```

### Verzeichnisstruktur auf dem Server

```
/htdocs/ (oder /public_html/)
├── .htaccess           ← Haupt-Rewrite-Regeln
├── index.php           ← Front Controller
├── css/
├── js/
├── assets/
├── app/
│   └── .htaccess       ← Zugriff verbieten!
├── config/
│   └── .htaccess       ← Zugriff verbieten!
├── vendor/
│   └── .htaccess       ← Zugriff verbieten!
└── storage/
    ├── .htaccess       ← Zugriff verbieten!
    ├── logs/
    └── cache/
```

### Berechtigungen setzen (über FTP oder SSH falls verfügbar)

| Verzeichnis | Berechtigung |
|-------------|--------------|
| `storage/` | 755 |
| `storage/logs/` | 775 |
| `storage/cache/` | 775 |
| Alle `.htaccess` | 644 |
| Alle `.php` | 644 |

### Konfiguration anpassen

1. Kopieren Sie `config/config.example.php` zu `config/config.php`
2. Passen Sie die Datenbankverbindung an:

```php
<?php
return [
    'database' => [
        'host' => 'rdbms.strato.de',  // Strato MySQL-Server
        'port' => 3306,
        'name' => 'DB12345678',        // Ihre Datenbank
        'user' => 'U12345678',         // Ihr DB-Benutzer
        'password' => 'IhrPasswort',   // Ihr DB-Passwort
        'charset' => 'utf8mb4',
    ],
    
    'app' => [
        'name' => 'VAES',
        'version' => '1.1.0',
        'debug' => false,  // Auf Produktion: false!
        'url' => 'https://ihre-domain.de',
    ],
    
    // ... weitere Einstellungen
];
```

---

## 6. Verzeichnisstruktur

### Komplette Projektstruktur

```
E:\TSC-Helferstundenverwaltung\
│
├── .git/                       # Git-Repository
├── .gitignore                  # Git-Ignore-Regeln
├── README.md                   # Projekt-Readme
│
├── docs/                       # Dokumentation
│   ├── Pflichtenheft_VAES_v1.1.docx
│   └── Setup-Anleitung.md
│
├── scripts/                    # Entwicklungs-Scripts
│   ├── backup.ps1              # PowerShell Backup-Script
│   ├── backup.bat              # Batch-Wrapper
│   └── database/
│       └── create_database.sql # DB-Erstellungsscript
│
├── tests/                      # Test-Prozeduren
│   ├── Unit/
│   ├── Integration/
│   └── README.md
│
├── src/                        # Quellcode
│   ├── public/                 # Web-Root (öffentlich)
│   │   ├── .htaccess
│   │   ├── index.php
│   │   ├── css/
│   │   ├── js/
│   │   └── assets/
│   │
│   ├── app/                    # Anwendungslogik (geschützt)
│   │   ├── .htaccess
│   │   ├── Controllers/
│   │   ├── Models/
│   │   ├── Views/
│   │   ├── Middleware/
│   │   └── Services/
│   │
│   ├── config/                 # Konfiguration (geschützt)
│   │   ├── .htaccess
│   │   ├── config.php
│   │   ├── config.example.php
│   │   └── routes.php
│   │
│   ├── vendor/                 # Composer (geschützt)
│   │   └── .htaccess
│   │
│   └── storage/                # Logs & Cache (geschützt)
│       ├── .htaccess
│       ├── logs/
│       └── cache/
│
└── deployment/                 # Deployment-Checklisten
    └── deploy-checklist.md
```

---

## 7. Konfiguration

### Versionsanzeige

Die Version wird aus der Konfiguration gelesen und angezeigt:

- **Login-Seite:** Unten zentriert
- **Footer (nach Login):** Rechts unten

Format: `VAES v1.1.0 (2025-02-09) [abc123f]`

Komponenten:
- Semantic Version aus `config/config.php`
- Datum aus Git oder Build-Prozess
- Git-Commit-Hash (kurz)

### Version aktualisieren

Bei neuen Releases:

1. `config/config.php` → `app.version` anpassen
2. `settings` Tabelle → `app_version` anpassen
3. Git-Tag erstellen: `git tag -a v1.2.0 -m "Release 1.2.0"`

---

## 8. Troubleshooting

### Backup funktioniert nicht

**Problem:** Netzlaufwerk nicht erreichbar

```powershell
# Prüfen
Test-Path "Y:\software_mondial\TSC-Helferstundenverwaltung"

# Falls Laufwerk Y: nicht verbunden:
net use Y: \\NAS-NAME\software_mondial /persistent:yes
```

**Problem:** Keine Berechtigung

- Prüfen Sie die NAS-Freigabeberechtigungen
- Stellen Sie sicher, dass der Windows-Benutzer Schreibrechte hat

### Datenbank-Fehler

**Problem:** `Access denied for user`

- Prüfen Sie die Zugangsdaten in `config/config.php`
- Strato MySQL-Server: `rdbms.strato.de`

**Problem:** `Table doesn't exist`

- Führen Sie das SQL-Script erneut aus
- Prüfen Sie auf Fehlermeldungen in phpMyAdmin

### .htaccess wird ignoriert

**Problem:** Verzeichnisse sind trotzdem erreichbar

- Prüfen Sie, ob `AllowOverride All` auf dem Server aktiv ist
- Bei Strato sollte dies standardmäßig aktiv sein
- Kontaktieren Sie ggf. den Strato-Support

### 500 Internal Server Error

1. Prüfen Sie die PHP-Fehlerprotokolle (`storage/logs/`)
2. Aktivieren Sie Debug-Modus temporär: `'debug' => true`
3. Prüfen Sie die PHP-Version (muss 8.x sein)
4. Prüfen Sie Dateiberechtigungen

---

## Kontakt & Support

Bei Fragen zur Entwicklung: [Claude Code in VSCode verwenden]

Bei technischen Problemen mit Strato: [Strato Support kontaktieren]

---

*Letzte Aktualisierung: 2025-02-09*
