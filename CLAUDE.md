# CLAUDE.md - VAES Projektkontext

## Projekt: Vereins-Arbeitsstunden-Erfassungssystem (VAES)
## Repository: TSC-Helferstundenverwaltung

**Version:** 1.3  
**Technologie:** PHP 8.x, MySQL 8.4, Slim 4, Bootstrap 5  
**Zielumgebung:** Strato Shared Webhosting  
**Git:** https://github.com/mschmick2/TSC-Helferstundenverwaltung.git

---

## 🎯 Projektziel

Webbasiertes System zur Erfassung und Verwaltung von ehrenamtlichen Arbeitsstunden für Vereinsmitglieder mit Freigabe-Workflow, Dialog-System und vollständigem Audit-Trail.

---

## 📁 Projektstruktur

```
E:\TSC-Helferstundenverwaltung\
├── CLAUDE.md                 # Diese Datei
├── README.md                 # Projekt-Readme
├── .gitignore
│
├── docs/                     # Dokumentation
│   ├── REQUIREMENTS.md       # Vollständige Anforderungen
│   ├── ARCHITECTURE.md       # Technische Architektur
│   ├── Setup-Anleitung.md
│   └── Pflichtenheft_VAES_v1.3.docx
│
├── .claude/                  # Claude Code Konfiguration
│   └── roles/                # Rollendefinitionen
│       ├── developer.md
│       ├── reviewer.md
│       ├── tester.md
│       └── security-auditor.md
│
├── scripts/                  # Entwicklungs-Scripts
│   ├── backup.ps1
│   └── database/
│       └── create_database.sql
│
├── tests/                    # Test-Prozeduren
│   ├── Unit/
│   ├── Integration/
│   └── README.md
│
└── src/                      # Quellcode
    ├── public/               # Web-Root
    │   ├── index.php
    │   ├── css/
    │   ├── js/
    │   └── .htaccess
    ├── app/                  # Anwendungslogik
    │   ├── Controllers/
    │   ├── Models/
    │   ├── Views/
    │   ├── Middleware/
    │   └── Services/
    ├── config/               # Konfiguration
    ├── vendor/               # Composer
    └── storage/              # Logs, Cache
```

---

## 🛠️ Technologie-Stack

| Komponente | Technologie | Version |
|------------|-------------|---------|
| Backend | PHP | 8.x |
| Framework | Slim | 4.x |
| Datenbank | MySQL | 8.4 |
| Frontend CSS | Bootstrap | 5.x |
| Frontend JS | Vanilla JavaScript | ES6+ |
| 2FA | OTPHP Library | - |
| E-Mail | PHPMailer | - |
| PDF | TCPDF | - |

---

## 🔧 Entwicklungsrichtlinien

### Coding Standards

- **PHP:** PSR-12 Coding Standard
- **Namespaces:** `App\Controllers`, `App\Models`, `App\Services`
- **Dateibenennung:** PascalCase für Klassen, snake_case für Konfiguration
- **Datenbank:** Prepared Statements IMMER verwenden
- **Kommentare:** PHPDoc für alle öffentlichen Methoden

### Sicherheitsrichtlinien

- Alle Benutzereingaben validieren und escapen
- Passwörter mit `password_hash()` (bcrypt, cost 12)
- CSRF-Token für alle POST-Requests
- SQL-Injection: NUR Prepared Statements
- XSS: Output mit `htmlspecialchars()` escapen

### Git Workflow

```bash
# Feature-Branch erstellen
git checkout -b feature/[feature-name]

# Commits mit aussagekräftigen Messages
git commit -m "feat: [Beschreibung]"
git commit -m "fix: [Beschreibung]"
git commit -m "docs: [Beschreibung]"

# Pull Request erstellen
git push origin feature/[feature-name]
```

---

## 📋 Rollen für Claude Code

Verwende die entsprechende Rolle je nach Aufgabe:

| Rolle | Datei | Verwendung |
|-------|-------|------------|
| **Developer** | `.claude/roles/developer.md` | Feature-Entwicklung, Code schreiben |
| **Reviewer** | `.claude/roles/reviewer.md` | Code-Review, Best Practices prüfen |
| **Tester** | `.claude/roles/tester.md` | Tests schreiben, Testfälle definieren |
| **Security Auditor** | `.claude/roles/security-auditor.md` | Sicherheitsprüfung |

### Rolle aktivieren

```
@role developer
```
oder
```
Lies .claude/roles/developer.md und agiere entsprechend.
```

---

## 🚀 Schnellstart für Entwicklung

### 1. Datenbank einrichten
```sql
-- In phpMyAdmin ausführen:
-- scripts/database/create_database.sql
```

### 2. Konfiguration
```bash
cp src/config/config.example.php src/config/config.php
# Datenbankzugangsdaten eintragen
```

### 3. Composer installieren
```bash
cd src
composer install
```

### 4. Lokaler Test
```bash
cd src/public
php -S localhost:8000
```

---

## 📚 Wichtige Dokumente

| Dokument | Pfad | Beschreibung |
|----------|------|--------------|
| Requirements | `docs/REQUIREMENTS.md` | Vollständige Anforderungen |
| Architektur | `docs/ARCHITECTURE.md` | Technische Architektur |
| Pflichtenheft | `docs/Pflichtenheft_VAES_v1.3.docx` | Formales Pflichtenheft |
| DB-Schema | `scripts/database/create_database.sql` | Datenbank-Struktur |

---

## ⚠️ Wichtige Regeln

1. **Keine Selbstgenehmigung:** Prüfer dürfen eigene Anträge NICHT genehmigen
2. **Dialog bleibt erhalten:** Bei Statusänderungen IMMER kompletten Dialog behalten
3. **Soft-Delete:** NIEMALS physisch löschen, nur `deleted_at` setzen
4. **Audit-Trail:** JEDE Änderung muss protokolliert werden
5. **Strato-Kompatibilität:** Kein SSH, keine Cron-Jobs, kein Node.js

---

## 🔗 Backup

**Quelle:** `E:\TSC-Helferstundenverwaltung`  
**Ziel:** `Y:\software_mondial\TSC-Helferstundenverwaltung`  
**Frequenz:** Stündlich (Windows Task Scheduler)

---

*Letzte Aktualisierung: 2025-02-09*
