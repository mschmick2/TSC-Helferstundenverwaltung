# VAES - Vereins-Arbeitsstunden-Erfassungssystem
## (TSC-Helferstundenverwaltung)

![Version](https://img.shields.io/badge/version-1.3.0-blue)
![PHP](https://img.shields.io/badge/PHP-8.x-purple)
![MySQL](https://img.shields.io/badge/MySQL-8.4-orange)

Web-basiertes System zur Erfassung und Verwaltung von ehrenamtlichen Arbeitsstunden für Vereinsmitglieder.

**Repository:** https://github.com/mschmick2/TSC-Helferstundenverwaltung.git

## Features

- ✅ **Arbeitsstunden-Erfassung** durch Mitglieder oder Erfasser
- ✅ **Flexibler Workflow** mit Freigabeprozess
- ✅ **Dialog-System** (Ticket-artig) für Rückfragen
- ✅ **2-Faktor-Authentifizierung** (TOTP + E-Mail)
- ✅ **Rollenbasierte Berechtigungen** (5 Rollen)
- ✅ **Vollständiger Audit-Trail**
- ✅ **Reporting & Export** (PDF, CSV)
- ✅ **Responsive Design** (Desktop, Tablet, Smartphone)
- ✅ **Multisession & Multitab** fähig

## Technologie-Stack

| Komponente | Technologie |
|------------|-------------|
| Backend | PHP 8.x + Slim 4 Framework |
| Frontend | Bootstrap 5 + Vanilla JavaScript |
| Datenbank | MySQL 8.4 |
| Hosting | Strato Shared Hosting kompatibel |

## Schnellstart

### 1. Repository klonen

```bash
git clone https://github.com/mschmick2/TSC-Helferstundenverwaltung.git
cd TSC-Helferstundenverwaltung
```

### 2. Abhängigkeiten installieren

```bash
composer install
```

### 3. Konfiguration

```bash
cp src/config/config.example.php src/config/config.php
# Datei bearbeiten und Zugangsdaten eintragen
```

### 4. Datenbank einrichten

SQL-Script in phpMyAdmin ausführen:
```
scripts/database/create_database.sql
```

### 5. Deployment

Siehe `docs/Setup-Anleitung.md` für detaillierte Anweisungen.

## Dokumentation

| Dokument | Beschreibung |
|----------|--------------|
| [Pflichtenheft](docs/Pflichtenheft_VAES_v1.1.docx) | Vollständige Anforderungsspezifikation |
| [Setup-Anleitung](docs/Setup-Anleitung.md) | Entwicklungs- und Deployment-Guide |
| [Deployment-Checkliste](deployment/deploy-checklist.md) | Checkliste für Releases |
| [Test-Dokumentation](tests/README.md) | Test-Struktur und Anleitung |

## Verzeichnisstruktur

```
├── docs/           # Dokumentation
├── scripts/        # Entwicklungs- und Backup-Scripts
├── tests/          # Test-Prozeduren
├── src/
│   ├── public/     # Web-Root (öffentlich)
│   ├── app/        # Anwendungslogik (geschützt)
│   ├── config/     # Konfiguration (geschützt)
│   ├── vendor/     # Composer-Abhängigkeiten
│   └── storage/    # Logs, Cache (geschützt)
└── deployment/     # Deployment-Checklisten
```

## Benutzerrollen

| Rolle | Beschreibung |
|-------|--------------|
| Mitglied | Eigene Stunden erfassen |
| Erfasser | Stunden für andere eintragen |
| Prüfer | Anträge freigeben/ablehnen |
| Auditor | Alle Vorgänge einsehen (nur lesen) |
| Administrator | Vollzugriff, Systemkonfiguration |

## Workflow

```
Entwurf → Eingereicht → In Klärung → Freigegeben
                ↓             ↓
            Storniert     Abgelehnt
```

## Backup

Automatisches stündliches Backup auf NAS:

```
E:\TSC-Helferstundenverwaltung\  →  Y:\software_mondial\TSC-Helferstundenverwaltung\
```

Siehe `scripts/backup.ps1` und `scripts/backup.bat`.

## Lizenz

Proprietär - Alle Rechte vorbehalten.

## Kontakt

Bei Fragen zur Entwicklung: Claude Code in VSCode verwenden

---

*Erstellt mit Unterstützung von Claude AI*
