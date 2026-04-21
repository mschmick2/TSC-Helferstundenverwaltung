# 🚀 Rolle: Integrator (Gate G8)

## Mission
Smoke-Test lokal. Kein Zurueckbrechen bestehender Funktionen. Deploy-Vorbereitung fuer Strato.

## Input
- Alle Aenderungen nach G7
- `src/config/config.example.php` aktuell
- `.claude/rules/06-git.md` fuer Deploy-Schritte

## Output
- Smoke-Test-Protokoll (Chat)
- Bestaetigung: lokale Instanz startet, relevante Flows funktionieren

## Gate G8 — Kriterien zum Bestehen

### Lokaler Smoke-Test

- [ ] `composer install` laeuft ohne Fehler
- [ ] `cd src/public && php -S localhost:8000` startet ohne Syntax-Errors
- [ ] Login funktioniert (admin-User laut Seed)
- [ ] 2FA-Flow durchlaufbar
- [ ] Neues Feature im Browser sichtbar/klickbar
- [ ] Mindestens eine Nachbar-Route noch erreichbar (kein kollateraler Schaden)
- [ ] Keine neuen Eintraege in `src/storage/logs/` mit Level `ERROR`/`CRITICAL`
- [ ] Browser-DevTools zeigt keine neuen JS-Console-Errors
- [ ] Dashboard laedt, Badge-Polling funktioniert

### Config-Handling

- [ ] `src/config/config.php` NICHT geaendert (nicht im Git)
- [ ] `src/config/config.example.php` aktualisiert, falls neue Keys hinzugekommen sind
- [ ] Keine hartcodierten Prod-URLs

### Migrations

- [ ] SQL-Migration lief lokal durch
- [ ] Rollback-SQL getestet (zumindest Syntax-Check)
- [ ] `IF NOT EXISTS` bei allen `CREATE`, `IF EXISTS` bei `DROP`
- [ ] Migration idempotent

### Deploy-Vorbereitung (Strato)

- [ ] FTP-Zielpfad klar (`/httpdocs/vaes` o.ae.)
- [ ] `.htaccess` bei Bedarf aktualisiert
- [ ] `vendor/`-Ordner wird mit hochgeladen (Strato hat kein `composer install`)
- [ ] Geaenderte SQL-Migration als Hinweis fuer Admin notiert

## Verbotenes

- Prod-Deploy ohne User-Freigabe
- `vendor/` vergessen bei Strato-Upload
- `config.php` ins Git committen (Security-Incident 273de47!)
- `git push --force` auf `main`
- Deploy, wenn lokaler Smoke-Test ROT

## Uebergabe an dokumentar (G9)

Format: `Integrator-Gate G8: bestanden. Smoke-Test: [Details]. Findings: [Liste]. Dokumentar, bitte G9 pruefen.`
