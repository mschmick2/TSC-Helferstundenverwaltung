# VAES E2E-Tests (Playwright)

End-to-End-Tests fuer das VAES-System.

## Einrichtung

```bash
cd e2e-tests
npm install
node install-browser.js
```

## Konfiguration

### Testbenutzer anlegen

Die Tests erwarten einen Admin-User in der **Dev-DB** (nicht Test-DB — die E2E-Tests laufen gegen den laufenden Dev-Server):

```sql
INSERT INTO users (vorname, nachname, email, mitgliedsnummer, password_hash, active, ...)
VALUES ('Test', 'Admin', 'admin@vaes.test', 'ADMIN001',
        '$2y$12$...', 1, ...);
```

Passwort-Hash erzeugen:
```bash
php -r "echo password_hash('AdminPass123!', PASSWORD_BCRYPT, ['cost' => 12]), PHP_EOL;"
```

2FA fuer diesen User deaktivieren oder Test-Secret einplanen.

### BASE_URL

Default: `http://localhost:8000/`

Fuer WAMP-Setup mit `base_path = /helferstunden`:
```bash
BASE_URL=http://localhost/helferstunden/ npm test
```

### Alternative Zugangsdaten

```bash
TEST_USER_EMAIL=custom@test.local \
TEST_USER_PASSWORD=CustomPass! \
npm test
```

## Ausfuehrung

```bash
# Dev-Server starten
cd ../src/public && php -S localhost:8000

# In separatem Terminal: MailPit
../tools/mailpit/mailpit.exe      # Windows
# oder:  mailpit                   # Linux/Mac via brew/apt

# In separatem Terminal: E2E-Tests
cd e2e-tests
npm test                    # headless
npm run test:headed         # mit Browser-UI
npm run test:report         # HTML-Report anzeigen
node run-tests.js tests/03-dashboard.spec.js
```

## Drei-Projekt-Strategie

| Projekt | Tests | Zweck |
|---------|-------|-------|
| setup | `01-login.spec.js` | Login, speichert auth-state.json |
| no-auth | `02-session-security.spec.js` | Tests OHNE Login |
| authenticated | `03..19-*.spec.js` | Tests MIT Session (depends on setup) |

## Screenshots

Landen in `screenshots/` (gitignored). Werden vom UX-Analyzer unter
`tools/ux-analyzer/` weiterverarbeitet.
