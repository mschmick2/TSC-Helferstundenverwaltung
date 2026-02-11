# VAES - Installationsanleitung Testserver

## Deployment auf Testserver 192.168.3.98/helferstunden

**Version:** 1.3.0
**Stand:** 2026-02-10
**Zielumgebung:** Apache-Webserver mit PHP 8.3, MySQL 8.4
**Basis-URL:** `https://192.168.3.98/helferstunden`

---

## Inhaltsverzeichnis

1. [Voraussetzungen](#1-voraussetzungen)
2. [Apache HTTPS/SSL einrichten](#2-apache-httpsssl-einrichten)
3. [Unterverzeichnis-Installation (base_path)](#3-unterverzeichnis-installation-base_path)
4. [Verzeichnisse hochladen](#4-verzeichnisse-hochladen)
5. [Konfiguration erstellen](#5-konfiguration-erstellen)
6. [.htaccess pruefen](#6-htaccess-pruefen)
7. [Datenbank einrichten](#7-datenbank-einrichten)
8. [Datenbank-Admin anlegen](#8-datenbank-admin-anlegen)
9. [Berechtigungen pruefen](#9-berechtigungen-pruefen)
10. [Installationstest](#10-installationstest)
11. [Offene Aufgaben](#11-offene-aufgaben)
12. [Troubleshooting](#12-troubleshooting)

---

## 1. Voraussetzungen

| Komponente | Beschreibung |
|-----------|-------------|
| Webserver | Apache 2.4 mit `mod_rewrite`, `mod_headers`, `mod_deflate`, `mod_expires`, `mod_ssl` |
| PHP | 8.3 |
| MySQL | 8.4, erreichbar ueber `127.0.0.1:3306` |
| Zugang | SSH-Zugang zum Webserver (fuer Apache-Konfiguration und SSL) |
| Lokales Projekt | `E:\TSC-Helferstundenverwaltung\src\` mit installierten Composer-Dependencies |

### Apache-Module aktivieren

```bash
sudo a2enmod rewrite headers deflate expires ssl
sudo systemctl restart apache2
```

### Composer-Dependencies pruefen

Vor dem Upload muessen die Dependencies installiert sein:

```
cd E:\TSC-Helferstundenverwaltung\src
composer install --no-dev --optimize-autoloader
```

Die Option `--no-dev` schliesst PHPUnit und andere Entwicklungs-Dependencies aus, was den Upload-Umfang reduziert.

---

## 2. Apache HTTPS/SSL einrichten

### 2.1 Self-Signed SSL-Zertifikat erstellen (internes Netzwerk)

Fuer den Testserver im internen Netzwerk wird ein selbstsigniertes Zertifikat verwendet:

```bash
sudo mkdir -p /etc/ssl/private
sudo openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout /etc/ssl/private/helferstunden.key \
    -out /etc/ssl/certs/helferstunden.crt \
    -subj "/C=DE/ST=NRW/L=Stadt/O=TSC Mondial/CN=192.168.3.98"
```

Berechtigungen sichern:

```bash
sudo chmod 600 /etc/ssl/private/helferstunden.key
sudo chmod 644 /etc/ssl/certs/helferstunden.crt
```

### 2.2 Apache VirtualHost fuer HTTPS konfigurieren

Erstellen Sie die Datei `/etc/apache2/sites-available/helferstunden-ssl.conf`:

```apache
<VirtualHost *:443>
    ServerName 192.168.3.98
    DocumentRoot /var/www/html

    SSLEngine on
    SSLCertificateFile /etc/ssl/certs/helferstunden.crt
    SSLCertificateKeyFile /etc/ssl/private/helferstunden.key

    # Alias fuer /helferstunden
    Alias /helferstunden /var/www/html/helferstunden

    <Directory /var/www/html/helferstunden>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/helferstunden-ssl-error.log
    CustomLog ${APACHE_LOG_DIR}/helferstunden-ssl-access.log combined
</VirtualHost>
```

### 2.3 HTTP-zu-HTTPS-Weiterleitung (optional)

Falls gewuenscht, HTTP-Anfragen auf HTTPS umleiten. Erstellen oder ergaenzen Sie `/etc/apache2/sites-available/helferstunden-redirect.conf`:

```apache
<VirtualHost *:80>
    ServerName 192.168.3.98
    Redirect permanent /helferstunden https://192.168.3.98/helferstunden
</VirtualHost>
```

### 2.4 VirtualHost aktivieren und Apache neu starten

```bash
sudo a2ensite helferstunden-ssl.conf
# Optional: HTTP-Redirect aktivieren
# sudo a2ensite helferstunden-redirect.conf
sudo apache2ctl configtest
sudo systemctl restart apache2
```

### 2.5 SSL-Verbindung testen

```bash
# Zertifikat pruefen (-k = self-signed akzeptieren)
openssl s_client -connect 192.168.3.98:443 -servername 192.168.3.98 </dev/null 2>/dev/null | openssl x509 -noout -subject -dates

# HTTPS-Aufruf testen
curl -k https://192.168.3.98/helferstunden/
```

### 2.6 Hinweise zu selbstsignierten Zertifikaten

- Browser zeigen eine Warnung an ("Verbindung ist nicht sicher"). Diese kann dauerhaft akzeptiert werden.
- Fuer internes Netzwerk (192.168.x.x) ist dies akzeptabel.
- Fuer Produktivbetrieb (oeffentliche Domain): Let's Encrypt oder offizielles Zertifikat verwenden.
- Die VAES-Anwendung erkennt HTTPS automatisch in `index.php` und setzt das Session-Cookie `secure`-Flag entsprechend.

---

## 3. Unterverzeichnis-Installation (base_path)

Die Anwendung wird **nicht** im Web-Root installiert, sondern im Unterverzeichnis `/helferstunden`. Dies erfordert besondere Konfiguration:

### 3.1 Architektur-Uebersicht

```
https://192.168.3.98/helferstunden/           <-- Startseite (Login)
https://192.168.3.98/helferstunden/dashboard   <-- Dashboard
https://192.168.3.98/helferstunden/entries      <-- Stundeneintraege
https://192.168.3.98/helferstunden/admin/users  <-- Benutzerverwaltung
```

### 3.2 Beteiligte Konfigurationsparameter

| Parameter | Datei | Wert | Beschreibung |
|-----------|-------|------|-------------|
| `base_path` | `config/config.php` | `/helferstunden` | Unterpfad fuer URL-Generierung |
| `url` | `config/config.php` | `https://192.168.3.98/helferstunden` | Volle URL fuer E-Mail-Links |
| `RewriteBase` | `.htaccess` | `/helferstunden` | Apache URL-Rewriting Basis |
| `setBasePath()` | `index.php` | automatisch aus Config | Slim 4 Framework-Konfiguration |
| `ViewHelper::setBasePath()` | `index.php` | automatisch aus Config | URL-Generierung in Views |
| Session-Cookie `path` | `index.php` | `/helferstunden/` | Cookie-Scope begrenzen |

### 3.3 Wie URL-Generierung funktioniert

In allen View-Templates werden URLs ueber `ViewHelper::url()` generiert:

```php
// Beispiele in Views:
href="<?= ViewHelper::url('/entries') ?>"
//  --> /helferstunden/entries

action="<?= ViewHelper::url('/entries/' . $id . '/edit') ?>"
//  --> /helferstunden/entries/42/edit

src="<?= ViewHelper::url('/css/app.css') ?>"
//  --> /helferstunden/css/app.css
```

In Controllern wird `$this->redirect()` automatisch mit dem `base_path` versehen:

```php
return $this->redirect($response, '/dashboard');
//  --> Location: /helferstunden/dashboard
```

---

## 4. Verzeichnisse hochladen

### 4.1 Verzeichniszuordnung

Die Inhalte des lokalen `src/`-Verzeichnisses werden in das Unterverzeichnis `/helferstunden/` auf dem Webserver hochgeladen.

```
LOKAL (E:\TSC-Helferstundenverwaltung\src\)    SERVER (/helferstunden/)
========================================================================
public/.htaccess                        -->    /helferstunden/.htaccess
public/index.php                        -->    /helferstunden/index.php
public/css/                             -->    /helferstunden/css/
public/js/                              -->    /helferstunden/js/
app/                                    -->    /helferstunden/app/
app/.htaccess                           -->    /helferstunden/app/.htaccess
config/                                 -->    /helferstunden/config/
config/.htaccess                        -->    /helferstunden/config/.htaccess
vendor/                                 -->    /helferstunden/vendor/
vendor/.htaccess                        -->    /helferstunden/vendor/.htaccess
storage/                                -->    /helferstunden/storage/
storage/.htaccess                       -->    /helferstunden/storage/.htaccess
```

### 4.2 Upload-Reihenfolge

**Schritt 1: Unterverzeichnis anlegen**

Falls `/helferstunden/` noch nicht existiert, auf dem Server erstellen.

**Schritt 2: Geschuetzte Verzeichnisse zuerst**

1. Navigieren Sie lokal zu `E:\TSC-Helferstundenverwaltung\src\`
2. Navigieren Sie auf dem Server zu `/helferstunden/`
3. Laden Sie diese Verzeichnisse hoch:
   - `app/` (inkl. `.htaccess`)
   - `config/` (inkl. `.htaccess`, **ohne** `config.php` - wird in Schritt 5 erstellt)
   - `vendor/` (inkl. `.htaccess`)
   - `storage/` (inkl. `.htaccess`)

**Schritt 3: Oeffentliche Dateien**

1. Navigieren Sie lokal zu `E:\TSC-Helferstundenverwaltung\src\public\`
2. Auf dem Server zu `/helferstunden/`
3. Laden Sie **den Inhalt** von `public/` direkt nach `/helferstunden/`:
   - `.htaccess`
   - `index.php`
   - `css/` (gesamtes Verzeichnis)
   - `js/` (gesamtes Verzeichnis)

### 4.3 Was NICHT hochgeladen wird

| Verzeichnis/Datei | Grund |
|-------------------|-------|
| `.git/` | Versionskontrolle, nicht fuer Server |
| `docs/` | Dokumentation, nicht fuer Server |
| `scripts/` | Entwicklungs-Scripts, nicht fuer Server |
| `tests/` | PHPUnit-Tests, nicht fuer Server |
| `phpunit.xml` | Test-Konfiguration, nicht fuer Server |
| `CLAUDE.md` | Entwicklungs-Kontext, nicht fuer Server |
| `README.md` | Projekt-Readme, nicht fuer Server |
| `src/config/config.php` | Enthaelt lokale Zugangsdaten, wird auf Server neu erstellt |
| `src/config/config.example.php` | Nur Vorlage, kann optional hochgeladen werden |
| `src/storage/version.json` | Wird auf Server neu erstellt |

### 4.4 Erwartete Serverstruktur nach Upload

```
/helferstunden/               <-- Unterverzeichnis auf dem Webserver
|-- .htaccess                 <-- aus src/public/.htaccess (mit RewriteBase)
|-- index.php                 <-- aus src/public/index.php
|-- css/
|   |-- app.css
|   |-- ...
|-- js/
|   |-- app.js
|   |-- ...
|-- app/                      <-- aus src/app/
|   |-- .htaccess             <-- WICHTIG: Zugriff verbieten
|   |-- Controllers/
|   |-- Models/
|   |-- Views/
|   |-- Middleware/
|   |-- Services/
|   |-- Helpers/
|   |-- Repositories/
|   |-- Exceptions/
|-- config/                   <-- aus src/config/
|   |-- .htaccess             <-- WICHTIG: Zugriff verbieten
|   |-- config.php            <-- wird manuell erstellt (Schritt 4)
|   |-- config.example.php
|   |-- dependencies.php
|   |-- routes.php
|-- vendor/                   <-- aus src/vendor/
|   |-- .htaccess             <-- WICHTIG: Zugriff verbieten
|   |-- autoload.php
|   |-- composer/
|   |-- slim/
|   |-- php-di/
|   |-- phpmailer/
|   |-- tecnickcom/
|   |-- ...
|-- storage/                  <-- aus src/storage/
|   |-- .htaccess             <-- WICHTIG: Zugriff verbieten
|   |-- logs/
|   |-- cache/
```

---

## 5. Konfiguration erstellen

### 5.1 config.php auf dem Server anlegen

Die Konfigurationsdatei wird direkt auf dem Server erstellt und enthaelt die realen Zugangsdaten.

**Option A: Ueber FileZilla/FTP**

1. Lokale Kopie erstellen: `config.example.php` als `config.php` kopieren
2. In einem Texteditor oeffnen und anpassen (siehe 5.2)
3. Ueber FTP nach `/helferstunden/config/config.php` hochladen

**Option B: Ueber Online-Dateimanager**

1. Zu `/helferstunden/config/` navigieren
2. `config.example.php` kopieren und als `config.php` umbenennen
3. Bearbeiten und Werte anpassen

### 5.2 Konfigurationswerte fuer Testserver

**WICHTIG:** Die Felder `base_path` und `url` muessen korrekt gesetzt sein, damit alle internen Links und Redirects funktionieren.

```php
<?php
return [
    'app' => [
        'name' => 'VAES',
        'version' => '1.3.0',
        'url' => 'https://192.168.3.98/helferstunden',  // Volle URL inkl. Unterpfad
        'base_path' => '/helferstunden',                  // WICHTIG: Unterpfad
        'debug' => true,                                   // Fuer Testphase: true
        'timezone' => 'Europe/Berlin',
        'locale' => 'de_DE',
    ],

    'database' => [
        'host' => '127.0.0.1',                            // Lokaler MySQL-Server
        'port' => 3306,
        'name' => 'helferstunden',                         // Datenbankname
        'user' => 'uhelferstunden',                        // DB-Benutzer
        'password' => '***REMOVED***',                     // DB-Passwort
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    ],

    // Session: 'secure' wird in index.php automatisch an HTTPS/HTTP angepasst
    'session' => [
        'name' => 'VAES_SESSION',
        'lifetime' => 1800,
        'path' => '/',
        'domain' => '',
        'secure' => true,                                 // Auto-Erkennung in index.php
        'httponly' => true,
        'samesite' => 'Lax',
    ],

    'security' => [
        'bcrypt_cost' => 12,
        'max_login_attempts' => 5,
        'lockout_duration' => 900,
        'csrf_token_lifetime' => 3600,
        'require_2fa' => true,
    ],

    // E-Mail: Telekom SMTP
    // WICHTIG: from.address MUSS eine bei T-Online registrierte Adresse sein!
    'mail' => [
        'driver' => 'smtp',
        'host' => 'securesmtp.t-online.de',               // Telekom SMTP (Port 587/TLS)
        'port' => 587,
        'username' => '***REMOVED***',                      // Telekom Zugangsnummer
        'password' => '***REMOVED***',                          // E-Mail-Passwort
        'encryption' => 'tls',
        'from' => [
            'address' => '***REMOVED***@t-online.de',       // Registrierte T-Online Adresse
            'name' => 'VAES System',
        ],
    ],

    '2fa' => [
        'totp' => [
            'issuer' => 'VAES',
            'digits' => 6,
            'period' => 30,
            'algorithm' => 'sha1',
        ],
        'email' => [
            'code_length' => 6,
            'expiry_minutes' => 10,
        ],
    ],

    'reminders' => [
        'enabled' => true,
        'days_before_reminder' => 7,
    ],

    'locks' => [
        'timeout_minutes' => 5,
        'check_interval_seconds' => 30,
    ],

    'paths' => [
        'storage' => __DIR__ . '/../storage',
        'logs' => __DIR__ . '/../storage/logs',
        'cache' => __DIR__ . '/../storage/cache',
        'uploads' => __DIR__ . '/../storage/uploads',
    ],

    'logging' => [
        'level' => 'warning',                             // debug/info/warning/error
        'max_files' => 30,
    ],

    'verein' => [
        'name' => 'TSC Mondial',
        'logo_path' => null,
        'address' => [
            'street' => 'Vereinsstrasse 1',               // <-- Anpassen
            'zip' => '12345',                              // <-- Anpassen
            'city' => 'Vereinsstadt',                      // <-- Anpassen
        ],
    ],
];
```

### 5.3 Wichtige Konfigurationsparameter erklaert

| Parameter | Wert | Beschreibung |
|-----------|------|-------------|
| `app.url` | `https://192.168.3.98/helferstunden` | Wird fuer E-Mail-Links (Einladungen, Passwort-Reset) verwendet |
| `app.base_path` | `/helferstunden` | Wird intern fuer alle URL-Generierung und Redirects verwendet. Muss mit `RewriteBase` in `.htaccess` uebereinstimmen |
| `app.debug` | `true` | Zeigt detaillierte Fehler an. Vor Produktivbetrieb auf `false` setzen |
| `database.host` | `127.0.0.1` | Lokaler MySQL-Server (fuer Strato: `rdbms.strato.de`) |
| `session.secure` | `true` | Wird in `index.php` automatisch an HTTPS/HTTP angepasst (kein manuelles Umschalten noetig) |

### 5.4 version.json erstellen

Fuer die Versionsanzeige im Footer erstellen Sie die Datei `/helferstunden/storage/version.json`:

```json
{
    "hash": "e6a7d36",
    "date": "2026-02-10"
}
```

Den aktuellen Git-Hash ermitteln Sie lokal mit:

```
git rev-parse --short HEAD
```

---

## 6. .htaccess pruefen

Die `.htaccess`-Datei im Root-Verzeichnis `/helferstunden/` muss folgende Eintraege enthalten:

### 6.1 PHP-Version

```apache
# PHP-Version (NUR fuer Hosting-Provider aktivieren, die dies erfordern, z.B. Strato)
# Bei Standard-Apache-Installationen NICHT aktivieren (verursacht 500 Error)
# AddType application/x-httpd-php83 .php
```

**HINWEIS:** Auf Standard-Apache (z.B. Ubuntu-Server) ist diese Zeile **auskommentiert**. Nur auf Strato Shared Hosting aktivieren!

### 6.2 RewriteBase und statische Dateien (zwingend fuer Unterverzeichnis)

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /helferstunden

    # Statische Dateien direkt ausliefern (fuer Apache Alias-Kompatibilitaet)
    RewriteCond %{REQUEST_URI} \.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot|map)$ [NC]
    RewriteRule ^ - [L]

    # Existierende Dateien und Verzeichnisse direkt ausliefern
    RewriteCond %{REQUEST_FILENAME} -f [OR]
    RewriteCond %{REQUEST_FILENAME} -d
    RewriteRule ^ - [L]

    # Alles andere an index.php weiterleiten
    RewriteRule ^(.*)$ index.php [QSA,L]
</IfModule>
```

**WICHTIG:**
- Der `RewriteBase`-Wert muss exakt mit `app.base_path` in `config.php` uebereinstimmen.
- Die explizite Regel fuer statische Dateien ist noetig, damit CSS/JS/Bilder bei Apache Alias korrekt ausgeliefert werden.

### 6.3 Sicherheits-Header

Die `.htaccess` enthaelt bereits alle empfohlenen Sicherheits-Header:

| Header | Wert | Zweck |
|--------|------|-------|
| X-Frame-Options | SAMEORIGIN | Verhindert Clickjacking |
| X-Content-Type-Options | nosniff | Verhindert MIME-Type-Sniffing |
| X-XSS-Protection | 1; mode=block | XSS-Schutz |
| Referrer-Policy | strict-origin-when-cross-origin | Referrer-Datenschutz |
| Content-Security-Policy | (siehe .htaccess) | Einschraenkung externer Ressourcen |

---

## 7. Datenbank einrichten

### 7.1 Datenbank erstellen

Falls die Datenbank `helferstunden` noch nicht existiert:

```sql
CREATE DATABASE IF NOT EXISTS helferstunden
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
```

### 7.2 SQL-Script ausfuehren

Oeffnen Sie phpMyAdmin (oder MySQL-CLI) und fuehren Sie das Script aus:

```
scripts/database/create_database.sql
```

Dieses Script erstellt alle Tabellen und legt einen Standard-Admin an.

### 7.3 Datenbankzugangsdaten

| Parameter | Wert |
|-----------|------|
| Host | `127.0.0.1` |
| Port | `3306` |
| Datenbank | `helferstunden` |
| Benutzer | `uhelferstunden` |
| Passwort | `***REMOVED***` |

---

## 8. Datenbank-Admin anlegen

### 8.1 Standard-Admin

Das SQL-Script `create_database.sql` legt einen Standard-Admin an:

| Feld | Wert |
|------|------|
| E-Mail | `admin@example.com` |
| Passwort | `Admin123!` |
| Mitgliedsnummer | `ADMIN001` |

### 8.2 Admin individualisieren

Oeffnen Sie phpMyAdmin und fuehren Sie folgende SQL-Befehle aus:

```sql
-- Admin-E-Mail auf echte Adresse aendern
UPDATE users
SET email = 'admin@ihre-echte-domain.de'
WHERE mitgliedsnummer = 'ADMIN001';

-- Admin-Namen anpassen
UPDATE users
SET vorname = 'Max', nachname = 'Mustermann'
WHERE mitgliedsnummer = 'ADMIN001';
```

### 8.3 Admin-Passwort aendern

**Empfohlen:** Ueber die Login-Seite:

1. `https://192.168.3.98/helferstunden/login` aufrufen
2. Mit `admin@example.com` / `Admin123!` einloggen
3. Falls 2FA: TOTP einrichten oder E-Mail-Code verwenden
4. Passwort sofort im Profil aendern
5. Ein sicheres Passwort waehlen (mind. 12 Zeichen, Gross/Klein/Zahl/Sonderzeichen)

---

## 9. Berechtigungen pruefen

### 9.1 Verzeichnisberechtigungen

| Verzeichnis | Berechtigung | Beschreibung |
|-------------|-------------|-------------|
| `/helferstunden/storage/` | 755 | Lesbar und navigierbar |
| `/helferstunden/storage/logs/` | 775 | Schreibbar fuer PHP |
| `/helferstunden/storage/cache/` | 775 | Schreibbar fuer PHP |
| `/helferstunden/storage/uploads/` | 775 | Schreibbar fuer PHP (falls vorhanden) |
| Alle `.htaccess` | 644 | Lesbar, nicht ausfuehrbar |
| Alle `.php` | 644 | Lesbar, nicht ausfuehrbar |

### 9.2 Verzeichnisse anlegen (falls nicht vorhanden)

Falls `storage/logs/` oder `storage/cache/` auf dem Server noch nicht existieren, diese ueber FTP/Dateimanager erstellen und Berechtigungen auf 775 setzen.

---

## 10. Installationstest

### 10.1 Rauchtest (Smoke Test)

#### Test 1: Webseite erreichbar

| Schritt | Aktion | Erwartung |
|---------|--------|-----------|
| 1 | Browser oeffnen, `https://192.168.3.98/helferstunden` aufrufen | Login-Seite wird angezeigt |
| 2 | Pruefe: VAES-Logo/Name sichtbar | "VAES" erscheint auf der Seite |
| 3 | Pruefe: CSS und JS geladen (DevTools Netzwerk-Tab) | Keine 404-Fehler fuer `/helferstunden/css/app.css` und `/helferstunden/js/app.js` |
| 4 | Pruefe: Versionsanzeige im Footer | "VAES v1.3.0" |

#### Test 2: Sicherheits-Header

| Schritt | Aktion | Erwartung |
|---------|--------|-----------|
| 1 | Browser DevTools oeffnen (F12) > Netzwerk-Tab | - |
| 2 | Seite neu laden, erste Anfrage anklicken | - |
| 3 | Response-Header pruefen | `X-Frame-Options: SAMEORIGIN` vorhanden |
| 4 | | `X-Content-Type-Options: nosniff` vorhanden |
| 5 | | `Content-Security-Policy` vorhanden |
| 6 | | `Referrer-Policy` vorhanden |

#### Test 3: Verzeichnisschutz

| Schritt | Aktion | Erwartung |
|---------|--------|-----------|
| 1 | `https://192.168.3.98/helferstunden/config/config.php` aufrufen | 403 oder 404 |
| 2 | `https://192.168.3.98/helferstunden/app/` aufrufen | 403 oder 404 |
| 3 | `https://192.168.3.98/helferstunden/vendor/autoload.php` aufrufen | 403 oder 404 |
| 4 | `https://192.168.3.98/helferstunden/storage/logs/` aufrufen | 403 oder 404 |

#### Test 4: URL-Routing (base_path)

| Schritt | Aktion | Erwartung |
|---------|--------|-----------|
| 1 | `https://192.168.3.98/helferstunden/login` aufrufen | Login-Seite (keine 404) |
| 2 | Quelltext pruefen: alle `href`-Links | Beginnen mit `/helferstunden/...` |
| 3 | Quelltext pruefen: CSS/JS `src`-Links | Beginnen mit `/helferstunden/css/...` bzw. `/helferstunden/js/...` |
| 4 | Login-Formular: `action`-Attribut | Zeigt auf `/helferstunden/login` |

#### Test 5: Admin-Login

| Schritt | Aktion | Erwartung |
|---------|--------|-----------|
| 1 | E-Mail: `admin@example.com`, Passwort: `Admin123!` | Login erfolgreich oder 2FA-Aufforderung |
| 2 | Falls 2FA: TOTP einrichten oder E-Mail-Code | Dashboard wird angezeigt |
| 3 | URL in der Adressleiste pruefen | `https://192.168.3.98/helferstunden/dashboard` |
| 4 | Passwort sofort aendern (Profil > Passwort aendern) | Bestaetigung angezeigt |

#### Test 6: Navigation pruefen

| Schritt | Aktion | Erwartung |
|---------|--------|-----------|
| 1 | Navbar: "Stundenerfassung" klicken | URL: `/helferstunden/entries` |
| 2 | Navbar: "Berichte" klicken | URL: `/helferstunden/reports` |
| 3 | Navbar: "Admin > Benutzer" klicken | URL: `/helferstunden/admin/users` |
| 4 | Navbar: "Admin > Kategorien" klicken | URL: `/helferstunden/admin/categories` |
| 5 | "Neuer Eintrag" Button klicken | URL: `/helferstunden/entries/create` |
| 6 | Logout klicken | Redirect zu `/helferstunden/login` |

#### Test 7: Grundfunktionen pruefen

| Schritt | Aktion | Erwartung |
|---------|--------|-----------|
| 1 | Dashboard aufrufen | Uebersicht wird angezeigt |
| 2 | Stundenerfassung > Neuer Eintrag | Formular wird geladen |
| 3 | Kategorie-Dropdown pruefen | Kategorien sichtbar |
| 4 | Benutzerverwaltung aufrufen (Admin-Bereich) | Liste der Benutzer angezeigt |
| 5 | Einstellungen aufrufen (Admin-Bereich) | Einstellungsformular geladen |

#### Test 8: E-Mail-Versand

| Schritt | Aktion | Erwartung |
|---------|--------|-----------|
| 1 | Neues Mitglied einladen (Admin > Benutzer > Einladen) | E-Mail wird versendet |
| 2 | E-Mail pruefen | Einladungslink enthaelt `/helferstunden/setup-password/...` |

#### Test 9: Fehlerprotokollierung

| Schritt | Aktion | Erwartung |
|---------|--------|-----------|
| 1 | Eine ungueltige URL aufrufen (z.B. `/helferstunden/nicht-vorhanden`) | Fehlerbehandlung greift |
| 2 | In `/helferstunden/storage/logs/` pruefen | Log-Dateien werden geschrieben |

### 10.2 Erweiterter Funktionstest

Nach bestandenem Rauchtest die vollstaendige Checkliste aus `tests/MANUAL_TESTS.md` durcharbeiten. Die wichtigsten Szenarien:

| Prioritaet | Bereich | Tests |
|-----------|---------|-------|
| Hoch | Authentifizierung | Login, Logout, Brute-Force-Sperre |
| Hoch | Workflow | Eintrag erstellen, einreichen, freigeben |
| Hoch | Selbstgenehmigung | Eigene Antraege koennen nicht genehmigt werden |
| Hoch | Sicherheit | Verzeichnisschutz, Header |
| Hoch | URL-Routing | Alle Links funktionieren mit `/helferstunden`-Prefix |
| Mittel | Dialog | Rueckfrage stellen und beantworten |
| Mittel | Reports | Bericht generieren und als PDF exportieren |
| Mittel | E-Mail | Benachrichtigungen bei Statusaenderungen |
| Niedrig | Import | CSV-Import testen |

---

## 11. Offene Aufgaben

### 11.1 Vor dem ersten produktiven Einsatz

| # | Aufgabe | Beschreibung | Status |
|---|---------|-------------|--------|
| 1 | **Admin-Passwort aendern** | Standard-Passwort `Admin123!` durch sicheres Passwort ersetzen | Offen |
| 2 | **Admin-E-Mail aendern** | `admin@example.com` durch echte E-Mail-Adresse ersetzen | Offen |
| 3 | **SMTP-Absender konfigurieren** | `from.address` in `config.php` muss bei T-Online registriert sein (z.B. `zugangsnummer@t-online.de`) | Erledigt |
| 4 | **SSL/HTTPS einrichten** | Self-Signed-Zertifikat erstellen und Apache VirtualHost konfigurieren (siehe Abschnitt 2) | Offen |
| 5 | **Debug-Modus deaktivieren** | `'debug' => false` in `config.php` setzen vor Produktivbetrieb | Offen |
| 6 | **Log-Level anpassen** | `'level' => 'warning'` ist bereits gesetzt - bei Bedarf anpassen | Offen |
| 7 | **Vereinsdaten eintragen** | Vereinsadresse in `config.php` vervollstaendigen | Offen |
| 8 | **Kategorien anpassen** | Standard-Kategorien in der Admin-Oberflaeche anpassen | Offen |
| 9 | **Testbenutzer anlegen** | Testbenutzer fuer jede Rolle (Mitglied, Pruefer, Erfasser) | Offen |
| 10 | **Backup einrichten** | Datenbank-Backup konfigurieren | Offen |

### 11.2 Bei Migration auf Strato (Produktion)

Wenn die Anwendung spaeter auf Strato Shared Webhosting migriert wird, muessen folgende Werte in `config.php` angepasst werden:

| Parameter | Testserver | Strato (Produktion) |
|-----------|-----------|---------------------|
| `app.url` | `https://192.168.3.98/helferstunden` | `https://ihre-domain.de` oder `https://ihre-domain.de/helferstunden` |
| `app.base_path` | `/helferstunden` | `/helferstunden` oder `''` (bei Root-Installation) |
| `database.host` | `127.0.0.1` | `rdbms.strato.de` |
| `database.name` | `helferstunden` | `DBxxxxxxxx` |
| `database.user` | `uhelferstunden` | `Uxxxxxxxx` |
| `mail.host` | `securesmtp.t-online.de` | `smtp.strato.de` |
| `mail.from.address` | `***REMOVED***@t-online.de` | E-Mail-Postfach bei Strato |
| `app.debug` | `true` | `false` |

Die `.htaccess`-Datei muss ebenfalls angepasst werden:
- `RewriteBase` auf den neuen Pfad setzen (oder auskommentieren bei Root-Installation)
- `AddType application/x-httpd-php83 .php` einkommentieren (nur auf Strato erforderlich, auf Standard-Apache auskommentiert lassen)

---

## 12. Troubleshooting

### 500 Internal Server Error

1. **Debug-Modus aktivieren:** In `/helferstunden/config/config.php` den Wert `'debug' => true` setzen
2. **Fehlerlog pruefen:** In `/helferstunden/storage/logs/` und `/var/log/apache2/helferstunden-ssl-error.log` nachsehen
3. **PHP-Version pruefen:** Muss PHP 8.3 sein. NICHT `AddType application/x-httpd-php83` in `.htaccess` aktivieren (verursacht 500 auf Standard-Apache)
4. **Berechtigungen pruefen:** `storage/logs/` muss beschreibbar sein (775)

### Login-Seite laedt nicht (weisse Seite)

1. `index.php` vorhanden in `/helferstunden/`?
2. `.htaccess` vorhanden in `/helferstunden/`?
3. `vendor/autoload.php` vorhanden?
4. `config/config.php` vorhanden und syntaktisch korrekt?
5. `base_path` in `config.php` korrekt auf `/helferstunden` gesetzt?
6. Datenbankverbindung korrekt? (`127.0.0.1`, Port 3306)

### 404 Not Found auf allen Seiten (ausser Startseite)

1. **RewriteBase pruefen:** In `.htaccess` muss `RewriteBase /helferstunden` stehen
2. **mod_rewrite aktiviert?** `a2enmod rewrite` auf dem Server ausfuehren
3. **AllowOverride:** Muss auf `All` stehen (Apache-Konfiguration)
4. **base_path pruefen:** `config.php` Wert `app.base_path` muss `/helferstunden` sein

### CSS/JS laden nicht (404 fuer Assets)

1. Dateien in `/helferstunden/css/` und `/helferstunden/js/` vorhanden?
2. Quelltext der Seite pruefen: Links muessen `/helferstunden/css/app.css` enthalten
3. Falls Links nur `/css/app.css` enthalten: `base_path` in `config.php` fehlt oder ist leer

### Redirects fuehren zum falschen Pfad

1. **Symptom:** Login leitet zu `/login` statt `/helferstunden/login`
2. **Ursache:** `base_path` in `config.php` nicht gesetzt oder leer
3. **Loesung:** `'base_path' => '/helferstunden'` in `config.php` eintragen

### 403 Forbidden auf Login-Seite

1. Die `.htaccess` aus `src/public/` muss in `/helferstunden/` liegen
2. `AllowOverride All` muss fuer das Verzeichnis aktiviert sein

### Datenbankfehler "Access denied"

1. Zugangsdaten in `/helferstunden/config/config.php` pruefen
2. Datenbank-Host: `127.0.0.1` (fuer Testserver, `rdbms.strato.de` fuer Strato)
3. Datenbankname und Benutzername exakt pruefen
4. Passwort ohne fuehrende/nachfolgende Leerzeichen eintragen

### E-Mail-Versand funktioniert nicht

1. SMTP-Daten in `config.php` pruefen
2. SMTP: `securesmtp.t-online.de`, Port 587, TLS
3. Benutzername: T-Online Kennung (`***REMOVED***`)
4. Log-Dateien unter `/helferstunden/storage/logs/` pruefen

### Session-Cookie-Probleme (staendiger Logout)

1. **Symptom:** Nach Login sofort wieder auf Login-Seite
2. **Ursache:** Session-Cookie wird fuer falschen Pfad gesetzt
3. **Loesung:** `index.php` setzt Cookie-Pfad automatisch basierend auf `base_path`. Pruefen ob `base_path` korrekt gesetzt ist.
4. DevTools > Application > Cookies: Cookie-Pfad sollte `/helferstunden/` sein

---

*Erstellt: 2026-02-10 | VAES v1.3.0 | Testserver 192.168.3.98/helferstunden*
