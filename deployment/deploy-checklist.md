# VAES Deployment-Checkliste

## Vor dem Deployment

- [ ] Alle Tests erfolgreich durchgeführt (`tests/`)
- [ ] Versionsnummer aktualisiert in:
  - [ ] `src/config/config.php` → `app.version`
  - [ ] `scripts/database/create_database.sql` → Kommentar-Header
  - [ ] `docs/Pflichtenheft` → Versionshistorie
- [ ] CHANGELOG.md aktualisiert
- [ ] Git-Commit erstellt: `git commit -m "Release v1.x.x"`
- [ ] Git-Tag erstellt: `git tag -a v1.x.x -m "Release 1.x.x"`
- [ ] Backup des aktuellen Produktivsystems erstellt

---

## Dateien für Upload vorbereiten

### HOCHLADEN (via FileZilla):

```
src/public/           → /htdocs/
src/app/              → /htdocs/app/
src/config/           → /htdocs/config/
src/vendor/           → /htdocs/vendor/
src/storage/          → /htdocs/storage/
```

### NICHT HOCHLADEN:

```
.git/
docs/
scripts/
tests/
node_modules/
*.md (außer in docs/)
```

---

## Deployment-Schritte

### 1. Wartungsmodus aktivieren (falls vorhanden)
- [ ] `maintenance.flag` in Web-Root erstellen

### 2. Backup der Produktions-Datenbank
- [ ] phpMyAdmin → Export → SQL-Format

### 3. Dateien hochladen
- [ ] FileZilla öffnen
- [ ] Verbindung zu Strato herstellen
- [ ] Geänderte Dateien hochladen
- [ ] `.htaccess` Dateien prüfen (alle 5!)

### 4. Datenbank-Migrationen
- [ ] Neue SQL-Scripts in phpMyAdmin ausführen (falls vorhanden)
- [ ] `settings.app_version` aktualisieren:
  ```sql
  UPDATE settings SET setting_value = '1.x.x' WHERE setting_key = 'app_version';
  ```

### 5. Cache leeren
- [ ] `storage/cache/*` leeren (Dateien löschen, nicht Verzeichnis)

### 6. Konfiguration prüfen
- [ ] `config/config.php` → `debug` = `false`
- [ ] SMTP-Einstellungen korrekt

### 7. Funktionstest
- [ ] Login funktioniert
- [ ] 2FA funktioniert
- [ ] Antrag erstellen funktioniert
- [ ] E-Mail-Versand funktioniert

### 8. Wartungsmodus deaktivieren
- [ ] `maintenance.flag` löschen

---

## Nach dem Deployment

- [ ] Git-Push: `git push origin main --tags`
- [ ] Deployment in Projektdokumentation vermerken
- [ ] Team über neue Version informieren

---

## Rollback-Plan

Falls Probleme auftreten:

1. Wartungsmodus aktivieren
2. Backup-Dateien wiederherstellen
3. Datenbank-Backup wiederherstellen
4. Cache leeren
5. Wartungsmodus deaktivieren
6. Fehler analysieren und beheben

---

## Checkliste für .htaccess Dateien

| Pfad | Zweck | Vorhanden? |
|------|-------|------------|
| `/htdocs/.htaccess` | URL-Rewriting, Sicherheits-Header | ☐ |
| `/htdocs/app/.htaccess` | Zugriff verbieten | ☐ |
| `/htdocs/config/.htaccess` | Zugriff verbieten | ☐ |
| `/htdocs/vendor/.htaccess` | Zugriff verbieten | ☐ |
| `/htdocs/storage/.htaccess` | Zugriff verbieten | ☐ |

---

*Stand: 2025-02-09*
