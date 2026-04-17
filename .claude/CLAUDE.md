# .claude/CLAUDE.md — Stack-Supplement (VAES)

> Ergaenzt `/CLAUDE.md`. Wird geladen, sobald Arbeit in `src/app/` oder `src/public/` beginnt.
> Der Root-CLAUDE.md gilt immer. Hier stehen nur stack-spezifische Details.

---

## PHP-Stack (Composer-Spiegel)

| Paket | Version | Zweck |
|-------|---------|-------|
| `slim/slim` | ^4.12 | HTTP-Framework (PSR-7/15) |
| `slim/psr7` | ^1.6 | PSR-7-Implementation |
| `php-di/slim-bridge` | ^3.4 | DI-Container |
| `php-di/php-di` | ^7.0 | Autowiring |
| `monolog/monolog` | ^3.5 | Logging |
| `phpmailer/phpmailer` | ^6.9 | SMTP-Versand |
| `spomky-labs/otphp` | ^11.0 | TOTP fuer 2FA |
| `tecnickcom/tcpdf` | ^6.10 | PDF-Export |
| `chillerlan/php-qrcode` | ^5.0 | QR-Code fuer TOTP-Setup |
| `phpunit/phpunit` | ^10.5 | Tests (dev) |

---

## Coding-Standards (PSR-12 + strict_types)

- `declare(strict_types=1);` am Dateianfang IMMER
- Namespaces: `App\Controllers`, `App\Services`, `App\Repositories`, `App\Models`, `App\Middleware`, `App\Helpers`, `App\Exceptions`
- Klassen: `PascalCase`, Methoden: `camelCase`, Konstanten: `UPPER_SNAKE`
- Properties mit `private` + Type-Hint. Nur bewusst `protected`/`public`.
- Konstruktor-Promotion bevorzugt (PHP 8+)
- `readonly` wo moeglich (PHP 8.1+)
- Enums statt Status-Strings wo Refactoring sicher moeglich

---

## Composer-Scripts

```bash
# Dependencies
cd src && composer install
composer update
composer dump-autoload

# Syntax-Check einzelner Datei
php -l src/app/Controllers/WorkEntryController.php

# PHPUnit (alle, Unit, Integration)
src/vendor/bin/phpunit
src/vendor/bin/phpunit --testsuite Unit
src/vendor/bin/phpunit --testsuite Integration

# Einzelner Test
src/vendor/bin/phpunit --filter self_approval_is_prevented

# Lokaler Dev-Server
cd src/public && php -S localhost:8000
```

---

## Architektur-Schichten (Dependency Direction)

```
  Controllers (HTTP-I/O, thin)
       │
       ▼
  Services (Business-Logik, Transaktionsgrenzen)
       │
       ▼
  Repositories (SQL, Prepared Statements)
       │
       ▼
  Models (reine Daten/Value-Objects)
```

- Controllers haben KEIN SQL. Sie orchestrieren Services.
- Services haben KEIN `$_POST`/`$_GET`. Sie erhalten validierte Inputs.
- Repositories haben KEIN Business-Wissen. Sie mappen SQL ↔ Models.
- Models sind `readonly` wo moeglich, keine DB-Calls.

---

## Wichtige Services (Orientierung)

| Service | Zweck |
|---------|-------|
| `AuthService` | Login, 2FA, Session, Passwort-Reset |
| `WorkflowService` | Status-Uebergaenge, Selbstgenehmigungs-Check |
| `AuditService` | Audit-Log-Eintraege schreiben |
| `EmailService` | SMTP via PHPMailer, Templates |
| `TotpService` | TOTP-Geheimnis, QR-Code |
| `ImportService` | CSV-Import fuer Mitglieder |
| `ReportService` | Aggregationen, Export-Vorbereitung |
| `PdfService` | TCPDF-Wrapping |
| `CsvExportService` | CSV-Export-Helper |
| `RateLimitService` | Login-Versuche tracken |
| `SettingsService` | Key/Value-Systemsettings |
| `TargetHoursService` | Soll-Stunden-Verwaltung |

---

## Wichtige Repositories

`UserRepository`, `WorkEntryRepository`, `CategoryRepository`, `DialogRepository`, `DialogReadStatusRepository`, `AuditRepository`, `SessionRepository`, `SettingsRepository`, `YearlyTargetRepository`, `ReportRepository`.

Alle verwenden `PDO` per Konstruktor-Injection.

---

## Agents — wann welcher?

- **Coding in `src/app/`** → `.claude/agents/developer.md` konsultieren
- **Review-Aufgabe** → `.claude/agents/reviewer.md` konsultieren
- **Security-Check** → `.claude/agents/security.md` konsultieren

Agents ersetzen die Gates nicht — sie liefern die Detail-Techniken.

---

*Letzte Aktualisierung: 2026-04-17*
