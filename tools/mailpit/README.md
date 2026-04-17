# MailPit — Lokaler Test-SMTP-Server

[MailPit](https://github.com/axllent/mailpit) ist ein zero-dependency SMTP-Server
fuer lokales Email-Testing. Er laeuft als Single-Binary und wird von E2E-/Feature-Tests
als Drop-in-Replacement fuer den echten Mailserver verwendet.

## Installation

### Windows

1. Neueste Version von https://github.com/axllent/mailpit/releases herunterladen
   (z.B. `mailpit-windows-amd64.zip`)
2. `mailpit.exe` aus dem ZIP in dieses Verzeichnis (`tools/mailpit/`) extrahieren
3. NICHT im Git committen — die Binary ist zu gross. `.gitignore` blockiert sie.

### Linux / macOS

```bash
# macOS (Homebrew)
brew install mailpit

# Linux (Snap)
snap install mailpit

# Oder manuell: Binary aus Release-Page herunterladen
```

## Starten

```bash
# Windows
tools\mailpit\mailpit.exe

# Linux/Mac
mailpit
```

Ausgabe:
```
[mailpit] v1.x.x listening on SMTP 0.0.0.0:1025
[mailpit] Web UI at http://0.0.0.0:8025/
```

## Ports

| Port | Protokoll | Zweck |
|------|-----------|-------|
| 1025 | SMTP | Anwendung sendet E-Mails hierher |
| 8025 | HTTP | Web-UI + REST-API |

## Konfiguration in VAES

`phpunit.xml` und `.env.example` setzen bereits:

```env
SMTP_HOST=127.0.0.1
SMTP_PORT=1025
SMTP_USERNAME=
SMTP_PASSWORD=
SMTP_ENCRYPTION=
MAILPIT_URL=http://127.0.0.1:8025
```

Die App-Config (`src/config/config.php`) muss bei Tests entsprechend ueberschrieben
werden. Einfachster Weg: Lokale `config.php` anlegen mit Mail-Block:

```php
'mail' => [
    'driver'   => 'smtp',
    'host'     => '127.0.0.1',
    'port'     => 1025,
    'username' => '',
    'password' => '',
    'encryption' => '',
    'from' => [
        'address' => 'noreply@vaes.test',
        'name'    => 'VAES Test',
    ],
],
```

## Port-Konflikte pruefen

```powershell
# PowerShell
Get-NetTCPConnection -LocalPort 1025,8025 -ErrorAction SilentlyContinue
```

```bash
# Linux/Mac
netstat -an | grep -E ':(1025|8025)\s'
```

## REST-API (Beispiel)

```bash
# Alle Nachrichten
curl http://127.0.0.1:8025/api/v1/messages

# Info / Count
curl http://127.0.0.1:8025/api/v1/info

# Alle loeschen
curl -X DELETE http://127.0.0.1:8025/api/v1/messages

# Suche
curl "http://127.0.0.1:8025/api/v1/search?query=subject:Passwort"
```

## PHP-Integration

`tests/Support/MailPitClient.php` kapselt die API fuer PHPUnit:

```php
$mail = \Tests\Support\MailPitClient::fromEnv();
$mail->deleteAll();
// ... Aktion, die Mail triggert ...
$msg = $mail->waitForMessage(['to' => 'user@test.local', 'subject' => 'Passwort']);
self::assertNotNull($msg);
```

## Playwright-Integration

`e2e-tests/helpers/mailpit.js` fuer Browser-Tests:

```javascript
const { waitForMessage, deleteAllMessages } = require('./helpers/mailpit');
await deleteAllMessages();
// ... Trigger-Aktion ...
const msg = await waitForMessage({ to: 'user@test.local', subject: 'Passwort' });
```
