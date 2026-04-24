# VAES — DSGVO- und Security-Nachweis

> **Adressat:** Datenschutzbeauftragter / externer DSGVO-Auditor des TSC Mondial e.V.
> **Projekt:** VAES — Vereins-Arbeitsstunden-Erfassungssystem, Version 1.4.0
> **Stand:** 2026-04-21
> **Zweck dieses Dokuments:** Nachweis der technischen und organisatorischen
> Massnahmen (TOM) nach Art. 32 DSGVO sowie der umgesetzten Betroffenenrechte
> nach Art. 15–21 DSGVO. Jede Aussage ist mit Quellen im Repository belegt.

---

## 1. Zweckbestimmung des Systems

VAES erfasst und verwaltet Helferstunden der Mitglieder des TSC Mondial e.V.
Die Verarbeitung personenbezogener Daten dient:

- der Vertragserfuellung der Vereinsmitgliedschaft (Art. 6 Abs. 1 lit. b DSGVO),
- der Erfuellung steuerlicher und vereinsrechtlicher Aufbewahrungspflichten
  (Art. 6 Abs. 1 lit. c DSGVO),
- berechtigten Interessen des Vereins an Revisionssicherheit und Missbrauchs-
  schutz (Art. 6 Abs. 1 lit. f DSGVO).

Daten, die darueber hinaus freiwillig erhoben werden (z. B. Telefonnummer),
beruhen auf Einwilligung nach Art. 6 Abs. 1 lit. a DSGVO und sind optional.

---

## 2. Datenverarbeitung – PII-Inventar

Alle personenbezogenen Felder, ihre Rechtsgrundlage und ihre geplante
Aufbewahrungsfrist sind im Projekt-Regelwerk tabellarisch gefuehrt (siehe
[`.claude/rules/02-dsgvo.md`](../.claude/rules/02-dsgvo.md), Abschnitt
„PII-Felder im System"). Die wichtigsten Kategorien:

| Kategorie | Felder | Aufbewahrung |
|-----------|--------|--------------|
| Stammdaten Mitglied | `users.vorname`, `nachname`, `email`, `mitgliedsnummer`, `strasse`, `plz`, `ort`, `telefon`, `eintrittsdatum` | Mitgliedschaft + 10 Jahre (Abgabenordnung) |
| Authentifizierung | `users.password_hash`, `users.totp_secret` | Dauer Mitgliedschaft |
| Antragsinhalte | `work_entries.description`, `dialog_messages.message` | Mitgliedschaft + 10 Jahre |
| Sicherheits-Logs | `login_attempts.*`, `rate_limits.*` | 90 Tage bzw. max. zweimal groesstes Fenster |
| Audit-Trail | `audit_log.*` | 10 Jahre, revisionssicher |
| Event-Helferdaten | `event_task_assignments.user_id`, `event_organizers.*` | 10 Jahre (Helferstunden-Nachweis) |
| Edit-Session-Tracking | `edit_sessions.user_id`, `event_id`, `browser_session_id`, Zeitstempel | max. 1 Stunde (Lazy-Cleanup, kein Audit-Eintrag) |

Das System-Benutzerkonto (`users.mitgliedsnummer = 'SYSTEM'`) ist als
Automationsaccount fuer auto-generierte `work_entries` vorgesehen und ist
ausdruecklich kein personenbezogener Datensatz; es wird durch das
Anonymisierungs-Skript explizit ausgespart
([`scripts/anonymize-db.sql`](../scripts/anonymize-db.sql)).

---

## 3. Technische und organisatorische Massnahmen (Art. 32 DSGVO)

### 3.1 Passwort-Hashing

- Algorithmus: **bcrypt** mit **Cost-Faktor 12**.
- Quelle: [`SecurityHelper.php:40`](../src/app/Helpers/SecurityHelper.php#L40)
  (`password_hash($password, PASSWORD_BCRYPT, ['cost' => 12])`).
- Passwort-Verifikation via `password_verify()` (konstante Laufzeit).
- Automatisches Rehashing, wenn der Cost-Faktor spaeter angehoben wird
  (`password_needs_rehash()`-Muster, dokumentiert in
  [`01-security.md`](../.claude/rules/01-security.md)).
- Klartext-Passwoerter werden nie geloggt. Die Passwort-Aenderung
  invalidiert alle anderen Sessions des Benutzers (siehe 3.3).

### 3.2 Zwei-Faktor-Authentifizierung

Das System unterstuetzt zwei 2FA-Verfahren:

**TOTP (Authenticator-App)**
- Implementierung via `spomky-labs/otphp`, Wrapper
  [`TotpService.php:30-82`](../src/app/Services/TotpService.php#L30-L82).
- Geheimnis wird bei Setup generiert (`TOTP::generate()`), QR-Code serverseitig
  mit `chillerlan/php-qrcode` als Data-URI gerendert, also **ohne externen
  Service**.
- Verifikationsfenster ±30 Sekunden (`$totp->verify($code, null, 1)` an
  [`TotpService.php:81`](../src/app/Services/TotpService.php#L81)).
- Ausgabe: 6 Stellen, Periode 30 s, Algorithmus SHA-1 (RFC 6238). Konfiguration
  in [`config.example.php`](../src/config/config.example.php).

**E-Mail-Code (Fallback)**
- 6-stelliger numerischer Code, Gueltigkeit 10 Minuten
  ([`TotpService.php:91-118`](../src/app/Services/TotpService.php#L91-L118)).
- Der Code wird in `email_verification_codes` mit Verfallszeit und
  Einmal-Kennzeichnung (`used_at`) gespeichert. Frueher generierte, ungenutzte
  Codes werden beim Anlegen eines neuen Codes invalidiert.

**Offene Punkte siehe Abschnitt 7.**

### 3.3 Session-Management

- Session-Daten werden server-seitig in der Tabelle `sessions` gehalten
  (`SessionRepository`), **nicht** im Default-File-Handler. Damit lassen sich
  Sessions gezielt invalidieren (z. B. Logout von allen Geraeten).
- Session-ID wird nach erfolgreichem Login und nach 2FA-Freigabe regeneriert
  (`session_regenerate_id(true)` in
  [`AuthService.php:109`](../src/app/Services/AuthService.php#L109)).
- Bei Passwort-Aenderung ruft der Service
  `SessionRepository::invalidateAllForUser()` auf
  ([`AuthService.php:177-183`](../src/app/Services/AuthService.php#L177-L183)),
  wodurch alle vorhandenen Sessions des Benutzers ungueltig werden.
- Session-Cookie-Flags (Produktion): `HttpOnly=true`, `SameSite=Strict`,
  `Secure=true` sobald HTTPS erkannt wird. Die Empfehlung ist in
  [`config.example.php`](../src/config/config.example.php) dokumentiert;
  die Umsetzung ist in der Master-Config [`CLAUDE.md`](../CLAUDE.md) §8 Nr. 1
  als erledigt ausgewiesen (2026-04-20).
- Session-TTL: 30 Minuten Inaktivitaet.

### 3.4 Rate-Limiting

Zum Schutz gegen Brute-Force-Angriffe und Credential-Stuffing sind mehrere
Bucket-basierte Rate-Limits aktiv. Die Zaehlung laeuft ueber die Tabelle
`rate_limits` (IP-Adresse als Schluessel; fuer Passwort-Reset zusaetzlich die
Email-Adresse als zweiter Bucket).

| Endpunkt | Limit (Standard) | Fenster | Reaktion |
|----------|------------------|---------|----------|
| `POST /login` pro IP | 20 Versuche | 60 s | HTTP 429 |
| `POST /login` pro Benutzer | 5 Fehlversuche | 15 min | Account-Lockout |
| `POST /forgot-password` pro IP | 5 Versuche | 15 min | sichtbarer HTTP 429 |
| `POST /forgot-password` pro Email | 3 Versuche | 60 min | stilles Drop (Anti-Flood) |
| `POST /reset-password` pro IP | 10 Versuche | 15 min | HTTP 429 |

- Zwei-Bucket-Design: IP-Bucket verhindert Bruteforce, Email-Bucket verhindert
  Flooding eines fremden Postfachs mit Reset-Mails. Dokumentiert in
  [`CLAUDE.md`](../CLAUDE.md) §8 Nr. 2, umgesetzt am 2026-04-21.
- Automatisches Cleanup veralteter Eintraege in
  [`RateLimitService.php:122-128`](../src/app/Services/RateLimitService.php#L122-L128).
- Rueckweisungen mit `429 Too Many Requests` werden im Audit-Log
  protokolliert.

### 3.5 Transportverschluesselung und HTTP-Sicherheitsheader

Die Sicherheitsheader werden in zwei Ebenen gesetzt:

**Apache (`.htaccess`)**
- [`src/public/.htaccess:27-47`](../src/public/.htaccess#L27-L47) setzt die
  vollstaendige Policy fuer den Produktionsbetrieb.

**PHP-Middleware (Defense in Depth)**
- [`SecurityHeadersMiddleware.php:45-67`](../src/app/Middleware/SecurityHeadersMiddleware.php#L45-L67)
  setzt dieselben Header auch auf Responses, die nicht ueber die `.htaccess`
  laufen (z. B. Fehlerseiten, Dev-Server).
- Motivation: Der PHP-Built-in-Dev-Server liest keine `.htaccess`, d. h. ohne
  diese Middleware wuerden Policy-Verstoesse lokal erst in Produktion auffallen.

Die gesetzten Header:

| Header | Wert | Zweck |
|--------|------|-------|
| `Content-Security-Policy` | `default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; font-src 'self' https://cdn.jsdelivr.net; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; upgrade-insecure-requests` | XSS-/Injection-Eindaemmung |
| `Strict-Transport-Security` | `max-age=63072000; includeSubDomains` (nur bei HTTPS) | Erzwingt HTTPS fuer 2 Jahre |
| `X-Frame-Options` | `SAMEORIGIN` | Clickjacking-Schutz (Legacy-Browser) |
| `X-Content-Type-Options` | `nosniff` | Verhindert MIME-Sniffing |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Verhindert Datenabfluss via Referer |
| `Permissions-Policy` | `geolocation=(), microphone=(), camera=(), payment=(), usb=()` | Deaktiviert Browser-APIs, die VAES nicht nutzt |

HSTS wird nur gesetzt, wenn HTTPS tatsaechlich erkannt wird (siehe
[`SecurityHeadersMiddleware.php:79-92`](../src/app/Middleware/SecurityHeadersMiddleware.php#L79-L92)),
damit ein versehentlich gestarteter HTTP-Dev-Server die Domain nicht dauerhaft
auf HTTPS locked.

**CSP-Einschraenkung:** `script-src` erlaubt aktuell `'unsafe-inline'`. Der
Umbau auf Per-Request-Nonce ist als eigene Iteration eingeplant
(siehe [`CLAUDE.md`](../CLAUDE.md) §8 Nr. 3 und Abschnitt 7 unten).

### 3.6 Schutz vor Injection-Angriffen

**SQL-Injection**
- Alle DB-Zugriffe laufen ueber PDO mit **Prepared Statements und Named
  Parameters**. Die Richtlinie ist in
  [`04-database.md`](../.claude/rules/04-database.md) festgelegt und durch
  Code-Review-Gate G4 abgesichert.
- PDO-Konfiguration: `PDO::ATTR_EMULATE_PREPARES => false` (siehe
  [`src/config/config.php:36`](../src/config/config.php#L36)). Damit werden
  Parameter binaer an den Server uebergeben und nicht clientseitig in den
  Query-String interpoliert.
- Eine rekursive Grep-Pruefung des Repositorys auf `$pdo->query(`-Muster mit
  Variablen-Konkatenation ergibt **keine Treffer** im Anwendungscode.

**Cross-Site-Scripting (XSS)**
- Alle View-Templates verwenden den Helfer `ViewHelper::e()`, der
  `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` kapselt. Eine direkte Ausgabe
  von Benutzereingaben (`<?= $foo ?>`) ist im Produktionscode nicht
  nachweisbar.
- JSON-Ausgaben in `<script>`-Bloecken werden mit `JSON_THROW_ON_ERROR |
  JSON_HEX_TAG` serialisiert, um `</script>`-Breakouts zu verhindern.
- Die CSP (s. o.) blockiert externe Script-Quellen; `base-uri 'self'`
  verhindert `<base href="…">`-Angriffe.

**Cross-Site-Request-Forgery (CSRF)**
- Alle state-aendernden Requests (POST/PUT/DELETE) pruefen das CSRF-Token via
  `CsrfMiddleware`. Vergleich mit `hash_equals()` (timing-safe).
- Token wird pro Session erzeugt (`bin2hex(random_bytes(32))`, 64 Zeichen
  Hex).
- AJAX-Requests senden das Token im Header `X-CSRF-Token`, der aus einem
  `<meta name="csrf-token">`-Tag gelesen wird.

### 3.7 Zugriffskontrolle und Rollenmodell

- Fuenf Rollen: **mitglied**, **erfasser**, **pruefer**, **auditor**,
  **administrator** (Tabelle `roles`, Verknuepfung ueber `user_roles`).
- Rollen-Pruefung erfolgt **doppelt**:
  1. `RoleMiddleware` bei der Route-Registrierung (HTTP-Schicht),
  2. Service-interne Assertions (Business-Schicht), damit auch
     programmatische Aufrufe abgesichert sind.

**Selbstgenehmigungs-Sperre (REQ-WF-004)**
- In [`WorkflowService.php:441-449`](../src/app/Services/WorkflowService.php#L441-L449)
  wird bei jeder Pruefer-Aktion geprueft, ob
  (a) der Pruefer die Rolle hat und
  (b) der Antrag weder vom Pruefer selbst eingereicht noch von ihm erfasst
  wurde. Wird einer der Checks verletzt, schlaegt die Aktion mit
  `BusinessRuleException` fehl.
- Die Sperre umfasst alle vier Status-Uebergaenge: Freigeben, Ablehnen,
  Rueckfrage stellen, Zurueck zur Ueberarbeitung.

### 3.8 Eingabevalidierung

- Jeder Input wird im Controller in den passenden Typ gecastet
  (`(int)`, `(float)`, `htmlspecialchars`), bevor er an die Service-Schicht
  uebergeben wird.
- Lange Freitext-Felder (Dialog-Nachrichten, Beschreibungen) sind auf
  sinnvolle Maximallaengen begrenzt (Form-Validierung via HTML5 + serverseitig
  `ValidationException`).
- Upload-Validierung waere bei kuenftigen Datei-Features via
  `finfo_file()`-MIME-Check + Zufallsdateinamen + Speicherort ausserhalb der
  Web-Root vorgeschrieben (siehe [`01-security.md`](../.claude/rules/01-security.md),
  Abschnitt „Uploads"). Aktuell (Version 1.4.0) nimmt VAES keine Uploads
  entgegen.

### 3.9 Email-Versand

- SMTP ueber PHPMailer v6.9 mit STARTTLS.
- Admin-Benachrichtigungen verwenden BCC, um Empfaenger-Adressen nicht
  gegenseitig offenzulegen (vgl. [`02-dsgvo.md`](../.claude/rules/02-dsgvo.md)).
- Das `Subject` enthaelt keine personenbezogenen Daten (z. B. keine Namen im
  Mail-Betreff).
- Mail-Zugangsdaten liegen ausschliesslich in `src/config/config.php`, die nicht
  versioniert wird (`.gitignore`).

---

## 4. Audit-Trail (Rechenschaftspflicht, Art. 5 Abs. 2 / Art. 30)

### 4.1 Unveraenderlichkeit (Append-Only)

Die Tabelle `audit_log` ist **technisch** gegen Veraenderung geschuetzt — nicht
nur durch Applikations-Konvention:

```sql
CREATE TRIGGER audit_log_no_update
BEFORE UPDATE ON audit_log
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Audit-Log darf nicht verändert werden.';
END //

CREATE TRIGGER audit_log_no_delete
BEFORE DELETE ON audit_log
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Audit-Log darf nicht gelöscht werden.';
END //
```

Quelle: [`scripts/database/create_database.sql:478-492`](../scripts/database/create_database.sql#L478-L492).
Jeder `UPDATE`- oder `DELETE`-Versuch — auch mit DB-Admin-Rechten — wird mit
MySQL-Fehler `45000` abgewiesen. Nur `TRUNCATE` (DDL) kann die Tabelle leeren,
was im produktiven Betrieb nicht vorkommt; das Anonymisierungsskript nutzt
diesen Weg ausschliesslich fuer die lokale Test-DB und dokumentiert dies
ausdruecklich ([`scripts/anonymize-db.sql:110-114`](../scripts/anonymize-db.sql#L110-L114)).

### 4.2 Inhalt der Audit-Eintraege

Jeder Audit-Eintrag enthaelt:

- `user_id`, `session_id`, `ip_address`, `user_agent` (User-Agent ist auf 500
  Zeichen begrenzt, siehe
  [`AuditService.php:66`](../src/app/Services/AuditService.php#L66)),
- `action` — 12 feste Werte als MySQL-ENUM: `create`, `update`, `delete`,
  `restore`, `login`, `logout`, `login_failed`, `status_change`, `export`,
  `import`, `config_change`, `dialog_message`,
- `table_name`, `record_id`, `entry_number` (bei work_entries),
- `old_values` / `new_values` als JSON (nur geaenderte Felder),
- `description` (menschlich lesbar),
- `metadata` als JSON (kontextabhaengig).

Siehe [`AuditService.php:43-76`](../src/app/Services/AuditService.php#L43-L76).

### 4.3 Protokollierte Ereignisse

- **Login / Logout:** erfolgreich und fehlgeschlagen
  (`AuditService::logLogin`, `logLoginFailed`, `logLogout`).
- **Antrags-Lebenszyklus:** jeder Statuswechsel wird als `status_change`
  protokolliert, inklusive alt/neu-Wert und Akteur.
- **Rueckfragen-Dialog:** jede Nachricht als `dialog_message`.
- **Rollenaenderungen:** `config_change` auf `user_roles`.
- **Konfigurationsaenderungen:** `config_change` auf `settings`.
- **Exporte und Importe:** `export` / `import` mit Metadaten
  (Zeilen-Anzahl, Filter, Zielformat).
- **Anonymisierung / Soft-Delete:** `delete` mit Beschreibung.

### 4.4 Transaktionssicherheit

Die Audit-Eintragung ist Bestandteil derselben Transaktion wie die
Business-Aenderung. Faellt die Audit-Schreibung aus, wird auch die
Business-Aenderung zurueckgerollt (Regel [`07-audit.md`](../.claude/rules/07-audit.md),
Abschnitt „Fehlerbehandlung").

---

## 5. Betroffenenrechte (Art. 15–21 DSGVO)

### 5.1 Auskunft (Art. 15)

- Jedes Mitglied sieht im eigenen Profil die eigenen Stammdaten, alle eigenen
  Antraege und alle Dialog-Verlaeufe.
- CSV-Export der eigenen Antraege ist ueber den `CsvExportService`
  implementiert; die Export-Felder sind auf Mitgliedsnummer, Name, Datum,
  Kategorie, Stunden, Status und Begruendung begrenzt, siehe
  [`CsvExportService.php:21-84`](../src/app/Services/CsvExportService.php#L21-L84).
- Ein Administrator kann im Auftrag des Mitglieds einen vollstaendigen
  Daten-Auszug erzeugen.

### 5.2 Berichtigung (Art. 16)

- Mitglieder koennen eigene Stammdaten im Profil selbst aktualisieren.
- Administrative Korrekturen werden als `update` ins Audit-Log geschrieben —
  inklusive `old_values`/`new_values` — und sind damit nachvollziehbar.
- Freigegebene Antraege koennen vom Pruefer/Admin mit **Pflicht-Begruendung**
  korrigiert werden. Die Begruendung landet im Audit-Trail.

### 5.3 Loeschung (Art. 17) / Anonymisierung

- **Soft-Delete als Standard:** Business-Datensaetze werden nie physisch
  geloescht, sondern ueber `deleted_at` + `deleted_by` markiert. Das
  Repository
  [`UserRepository::softDeleteUser` bei Zeile 575-580](../src/app/Repositories/UserRepository.php#L575-L580)
  ist das Muster; Regel siehe
  [`04-database.md`](../.claude/rules/04-database.md).
- **Austritt aus dem Verein:** Soft-Delete sofort, personenbezogene Daten
  bleiben 10 Jahre zugriffsbeschraenkt zur Erfuellung steuerlicher Pflichten
  gespeichert.
- **Nach Ablauf der Aufbewahrungsfrist** kann der Administrator per
  Anonymisierungsskript
  ([`scripts/anonymize-db.sql`](../scripts/anonymize-db.sql))
  die personenbezogenen Felder durch Platzhalter ersetzen. Der Audit-Trail
  bleibt strukturell erhalten, verweist aber nur noch auf den
  anonymisierten User.
- Der Systemaccount (`mitgliedsnummer = 'SYSTEM'`) wird vom
  Anonymisierungs-Lauf ausgenommen, weil er keine natuerliche Person
  repraesentiert.

### 5.4 Datenuebertragbarkeit (Art. 20)

- CSV-Export der eigenen Antraege und Stammdaten wird vom Benutzer selbst
  ausgeloest (maschinenlesbares Format).
- Export durch Pruefer/Admin enthaelt nur die fuer den Pruef-/Reporting-Zweck
  noetigen Felder und ist auf Datensparsamkeit getrimmt (keine Passwort-Hashes,
  keine TOTP-Geheimnisse, keine technische Stammdaten).

### 5.5 Widerspruch (Art. 21)

- Nicht-transaktionale Benachrichtigungen sind ueber die `settings`-Tabelle
  opt-out-faehig.
- Transaktionale E-Mails (Passwort-Reset, 2FA-Code, Rechtspflicht-Mails)
  sind nicht abwaehlbar.

### 5.6 Recht auf Einschraenkung (Art. 18)

- Ist implizit durch Soft-Delete + Rollen-Entzug abgebildet: Ein Account kann
  deaktiviert werden (`is_active = 0`), wodurch Login und Antragsbearbeitung
  gesperrt werden, die Daten aber revisionssicher bestehen bleiben.

---

## 6. Loesch- und Anonymisierungs-Routinen

| Datenkategorie | Automatisch | Durch | Frist |
|----------------|-------------|-------|-------|
| `rate_limits` | Ja | `RateLimitService::cleanup()` beim Schreibzugriff | max. 2x groesstes Fenster |
| `email_verification_codes` | Nein (manuell per Skript) | Admin | 30 Tage ausreichend |
| `login_attempts` | Nein (manuell per Skript) | Admin | 90 Tage |
| `sessions` | Ja | `SessionRepository::cleanup()` bei Login | nach TTL + 1 Tag |
| `users` (Austritt) | Nein | Admin ueber UI | Sofort Soft-Delete |
| Anonymisierung nach Ablauf | Nein (Skript) | Admin, CLI | Nach 10 Jahren |
| `audit_log` | **Nein** | — | **10 Jahre, nicht loeschbar** |
| `edit_sessions` | Ja | `EditSessionRepository::cleanupStale()` beim Insert | sofort nach Close, max. 1 Stunde dangling |

**Offener Punkt:** Eine fully-automated Cron-basierte Loeschroutine existiert
nicht, weil die Produktionsumgebung (Strato Shared Hosting) keine Cron-Jobs
erlaubt (siehe Abschnitt 8). Die Einhaltung der Fristen geschieht
organisatorisch durch einen **jaehrlichen Admin-Review**.

**Detail Edit-Session-Tracking** (Modul 6 I7e-C.1, eingefuehrt 2026-04-24):
- **Zweck:** Anzeige, dass ein anderer Nutzer gerade dasselbe Event
  bearbeitet, um Parallel-Arbeit zu koordinieren. Der eigentliche
  Daten-Integritaets-Schutz bleibt der Optimistic Lock aus Modul 6 I7e-B
  (`event_tasks.version`).
- **Rechtsgrundlage:** Art. 6(1)(f) berechtigtes Interesse (Koordinations-
  Bedarf bei gemeinsamer Vereins-Verwaltungsaufgabe).
- **Verarbeitete Daten:** `user_id` (interne ID), `event_id` (interne ID),
  `browser_session_id` (clientseitig generierter zufaelliger Identifier,
  nicht PII), Zeitstempel `started_at`, `last_seen_at`, `closed_at`. Fuer
  die UI-Anzeige wird abgeleitet `display_name = users.vorname + ' ' +
  users.nachname` per JOIN — DSGVO-relevante Klartext-PII wird zur
  Anzeige nur in den `EditSessionView`-Response gemappt, nicht in der
  Tabelle persistiert.
- **Empfaenger-Kreis:** Alle Editor-Berechtigten desselben Events
  (Event-Administratoren mit `event_admin`/`administrator`-Rolle und
  eingetragene Event-Organisatoren). Keine Externen, keine API-
  Integrationen.
- **Audit-Log:** **Keine** Eintraege fuer Session-Open/Close. Die Tabelle
  `edit_sessions` ist selbst der kurzlebige Record (Architect-
  Entscheidung C5 aus G1-I7e-C). Der `audit_log` bleibt fuer Business-
  Events (Antrags-Statusaenderungen, Aufgaben-Aenderungen,
  Konfigurations-Aenderungen).
- **Feature-Flag:** `events.edit_sessions_enabled`, hart gekoppelt an
  `events.tree_editor_enabled`. Auf Produktion derzeit beide auf `0` —
  das Feature ist dort nicht aktiv.

---

## 7. Offen dokumentierte Sicherheitsschuld

Die folgenden Punkte sind dem Entwicklungsteam bekannt, dokumentiert und im
Backlog gefuehrt. Sie stellen keine akute Verletzung der DSGVO dar, sollen
aber dem Auditor vollstaendig transparent gemacht werden.

### 7.1 TOTP-Secret im Klartext gespeichert

- **Befund:** Das TOTP-Geheimnis wird in `users.totp_secret` als Base32-Klartext
  abgelegt. Siehe
  [`UserRepository.php:160-166`](../src/app/Repositories/UserRepository.php#L160-L166).
- **Risiko-Einschaetzung:** Bei kompromittierter Datenbank koennten Angreifer
  TOTP-Codes erzeugen und damit 2FA aushebeln. Passwort-Hash (bcrypt) und
  TOTP-Secret muessten beide kompromittiert sein, um Accounts vollstaendig
  zu uebernehmen.
- **Geplante Massnahme:** Umstellung auf verschluesselte Ablage mit einem
  aus `config.php` gelesenen App-Key (`sodium_crypto_secretbox`). Der Key
  liegt nicht in der DB, wodurch ein reiner DB-Dump allein nicht mehr
  ausreicht.

### 7.2 Passwort-Reset-Token im Klartext gespeichert

- **Befund:** In der Tabelle `password_resets` wird der Token unmittelbar
  abgelegt und per Gleichheitsvergleich gesucht:
  [`UserRepository.php:227-253`](../src/app/Repositories/UserRepository.php#L227-L253).
- **Risiko-Einschaetzung:** Bei DB-Leak koennte ein Angreifer, waehrend das
  Token noch nicht abgelaufen oder genutzt ist, den Passwort-Reset abschliessen.
  Das Zeitfenster ist durch die TTL (1 Stunde) begrenzt, und das
  zwei-Bucket-Rate-Limit (siehe 3.4) limitiert zusaetzlich die Frequenz
  erfolgreicher Reset-Anforderungen.
- **Geplante Massnahme:** Speicherung von `sha256($token)` statt `$token`;
  Benutzer erhaelt weiterhin das unveraenderte Token per Mail, die
  Datenbank kennt nur den Hash. Der Vergleich laeuft dann zeitkonstant gegen
  den Hash.

### 7.3 E-Mail-2FA-Code im Klartext

- **Befund:** Der per Mail versendete 6-stellige Code wird ebenfalls
  unverschluesselt in `email_verification_codes` gespeichert
  ([`TotpService.php:107-115`](../src/app/Services/TotpService.php#L107-L115)).
- **Risiko-Einschaetzung:** Kleiner Schluesselraum (10^6) und enger
  Geltungszeitraum (10 Minuten), Einmal-Verwendung erzwungen. Bei DB-Leak waere
  der Code bereits ueber Mail versandt, der Risiko-Zusatz durch Klartext-Ablage
  ist marginal.
- **Geplante Massnahme:** Analog zu 7.2 als Hash speichern; sinnvoll im
  Zusammenhang mit der Reset-Token-Aenderung umzusetzen.

### 7.4 Audit-Log enthaelt potenziell Passwort-Hashes

- **Befund:** Der zentrale `AuditService::log()` kodiert `old_values` /
  `new_values` unveraendert als JSON
  ([`AuditService.php:71-72`](../src/app/Services/AuditService.php#L71-L72)).
  Es existiert **keine zentrale Whitelist**, die sensible Spalten
  (`password_hash`, `totp_secret`, Session-IDs) vor dem Serialisieren entfernt.
  Diese Filterung liegt aktuell bei jedem Aufrufer.
- **Konkreter Pfad im Code:**
  [`UserRepository::updatePassword` bei Zeile 102](../src/app/Repositories/UserRepository.php#L102)
  schreibt den neuen Hash in die Tabelle `users` **ohne** einen begleitenden
  Audit-Aufruf; damit gelangt der Hash im Normalpfad nicht ins Log. Wird aber
  kuenftig ein generisches User-Update mit Diff-Audit eingefuehrt, ohne
  Filter-Liste, gelangt der Hash potenziell in das Log.
- **Risiko-Einschaetzung:** Bei einem DB-Leak, in dem `audit_log` mit
  enthalten ist, waere neben dem aktuellen `password_hash` auch die
  Hash-Historie sichtbar. Bcrypt bietet weiterhin Schutz, aber die Angriffs-
  flaeche wird vergroessert.
- **Geplante Massnahme:** Zentrale Blacklist im `AuditService::log()`
  einfuehren: Spalten `password_hash`, `totp_secret`, `remember_token`,
  `session_id` werden vor dem JSON-Encode aus `old_values` und `new_values`
  entfernt. Damit wird die Disziplin nicht mehr pro Caller erzwungen.

### 7.5 E-Mail-Adressen in Applikationslogs (Monolog)

- **Befund:** Im `EmailService` werden Empfaenger-Adressen im Info-Log
  protokolliert (z. B. „Mail an mitglied@example.org gesendet"). Der
  Projekt-Standard in [`02-dsgvo.md`](../.claude/rules/02-dsgvo.md) verlangt,
  stattdessen `user_id` zu loggen.
- **Risiko-Einschaetzung:** Applikationslogs werden 30 Tage aufbewahrt und
  sind fuer Admins einsehbar. Adressen stellen personenbezogene Kontaktdaten
  dar; ihre Sichtbarkeit im Log sollte reduziert werden.
- **Geplante Massnahme:** Logging-Aufrufe auf `user_id` umstellen; bei
  Mailversand-Fehlern nur noch ein maskiertes Praefix (`m***@example.org`)
  oder ausschliesslich die User-ID protokollieren.

### 7.6 CSP erlaubt noch `'unsafe-inline'` fuer Scripts

- **Befund:** Die CSP (Abschnitt 3.5) enthaelt `script-src 'self'
  'unsafe-inline' …`. Eine vollstaendige Inline-Script-Freiheit ist damit
  nicht erreicht.
- **Risiko-Einschaetzung:** Im Zusammenspiel mit konsequentem Output-Escaping
  (3.6, XSS) ist das Restrisiko ueberschaubar, aber die Defense-in-Depth-Schutz-
  schicht der CSP ist fuer Inline-Scripts geschwaecht.
- **Geplante Massnahme:** Per-Request-Nonce-Rollout, das alle Inline-Scripts
  mit einer dynamischen Nonce versieht. In [`CLAUDE.md`](../CLAUDE.md) §8 Nr. 3
  als eigener Iterationsschritt angekuendigt.

---

## 8. Betriebliche Rahmenbedingungen

VAES wird auf **Strato Shared Webhosting** betrieben. Daraus ergeben sich
folgende Einschraenkungen und Konsequenzen fuer den Datenschutz:

- **Kein SSH-Zugang.** Ein Deployment erfolgt per FTP/SFTP, was das Risiko
  kompromittierter Shell-Schluessel eliminiert, aber auch automatisierte
  Aufraeumjobs unmoeglich macht.
- **Keine Cron-Jobs.** Aufbewahrungsfristen koennen nicht automatisch durch
  Cron durchgesetzt werden; Loeschungen werden organisatorisch durch einen
  jaehrlichen Admin-Review umgesetzt (vgl. Abschnitt 6).
- **Kein Node.js / keine Shell-Execs.** Build- und Post-Processing-Schritte
  laufen ausschliesslich lokal auf dem Entwicklungsrechner; der Server fuehrt
  nur interpretierten PHP-Code aus, was die Angriffsflaeche reduziert.
- **Auftragsverarbeitungs-Vertrag.** Mit Strato existiert ein AVV nach Art. 28
  DSGVO (organisatorisch, nicht im Code pruefbar).
- **SMTP-Provider.** Der AVV mit dem Mail-Provider ist ebenfalls
  organisatorisch zu fuehren.
- **PHPMailer und TCPDF** arbeiten rein lokal auf dem Anwendungsserver, es
  findet kein Drittlanddatentransfer statt.

---

## 9. Datenbank-Datentypen und Konsistenz

- Alle Datum-Felder verwenden `DATETIME` (nicht `TIMESTAMP`), um
  Zeitzonenverschiebungen zu vermeiden.
- Stundenwerte sind `DECIMAL(6,2)`, nicht Float, um Rundungsfehler in
  aggregierten Reports auszuschliessen.
- Charset ist durchgaengig `utf8mb4` mit `utf8mb4_unicode_ci`, damit Namen mit
  Umlauten/Sonderzeichen verlustfrei gespeichert werden.
- Foreign-Keys mit `ON DELETE RESTRICT` fuer `event_organizers.user_id` —
  bewusst, um die historische Integritaet nicht durch versehentliches
  Mitloeschen zu brechen.

---

## 10. Entwicklungs- und Reviewprozess

Jede Aenderung am Code durchlaeuft die dokumentierte 9-Gate-Review-Pipeline:

| Gate | Thema |
|------|-------|
| G1 | Architektur |
| G2 | Implementierung |
| G3 | Code-Review |
| G3.5 | UI/UX (wenn UI beruehrt) |
| G4 | Security (OWASP, CSP, Input-Validation) |
| G5 | DSGVO (wenn PII beruehrt) |
| G6 | Audit-Trail |
| G7 | Tests (PHPUnit + E2E) |
| G8 | Integration |
| G9 | Dokumentation |

Die Gates sind im Master-Regelwerk [`CLAUDE.md`](../CLAUDE.md) § 4 definiert;
ueberspringbare Gates (G3.5, G5) werden im Commit-Trailer explizit
ausgewiesen. Die DSGVO-Regeln selbst sind in
[`.claude/rules/02-dsgvo.md`](../.claude/rules/02-dsgvo.md) versioniert und
werden bei Aenderungen am Datenmodell automatisch angezogen.

**Tests:**
- PHPUnit deckt Unit- und Integration-Pfade ab (u. a.
  `self_approval_is_prevented`, Audit-Log-Pflichteintraege).
- Playwright-E2E-Suite verifiziert u. a. Passwort-Reset-Rate-Limit
  (`tests/e2e/specs/09-password-reset-rate-limit.spec.ts`) als sichtbaren
  Nachweis der zwei-Bucket-Schutzlogik.

---

## 11. Zusammenfassung fuer den Auditor

**Umgesetzt und nachweisbar:**
- Zweckbindung und Rechtsgrundlagen pro Feld dokumentiert (Abschnitt 2).
- bcrypt-Passwort-Hashing (Cost 12), optionales 2FA (TOTP + E-Mail-Fallback),
  Session-Regeneration, serverseitige Session-Invalidierung bei
  Passwortwechsel (3.1–3.3).
- Zwei-Bucket-Rate-Limiting gegen Brute-Force und Mail-Flooding (3.4).
- CSP inklusive `object-src 'none'`, `frame-ancestors 'self'`,
  `upgrade-insecure-requests`, HSTS 2 Jahre, Referrer-Policy,
  Permissions-Policy (3.5).
- CSRF per `hash_equals`, XSS per durchgaengigem Output-Escaping, SQLi per
  PDO-Prepared-Statements mit `EMULATE_PREPARES=false` (3.6).
- Zweistufige Rollenpruefung (HTTP-Middleware + Service-Assertion), technisch
  erzwungene Sperre gegen Selbstgenehmigung (3.7).
- Revisionssicheres Audit-Log mit DB-Trigger-Schutz, 12-Wert-ENUM fuer
  Aktionen, Kontextfelder (IP, User-Agent, Session) pro Eintrag (4).
- Soft-Delete als Standard fuer Business-Daten, Anonymisierungsskript mit
  Systemaccount-Ausnahme (5.3).
- Betroffenenrechte (Auskunft, Berichtigung, Loeschung, Uebertragbarkeit)
  ueber UI-Funktionen und CSV-Export implementiert (5.1–5.5).

**Transparent gefuehrte Sicherheitsschuld (Abschnitt 7):**
- TOTP-Secret im Klartext (7.1)
- Passwort-Reset-Token im Klartext (7.2)
- E-Mail-2FA-Code im Klartext (7.3)
- Audit-Log hat keine zentrale Redaktion sensibler Felder (7.4)
- E-Mail-Adressen in Applikationslogs (7.5)
- CSP erlaubt `'unsafe-inline'` fuer Scripts (7.6)

Diese Punkte sind bekannt, priorisiert und werden in kommenden Iterationen
geschlossen. Keiner der Punkte fuehrt bei bestimmungsgemaessem Betrieb des
Systems zu einer direkten Datenschutzverletzung; sie schraenken jedoch die
Defense-in-Depth-Schutzschichten ein und sollten vor einer
Produktions-Inbetriebnahme an einer exponierten Domain adressiert werden.

---

## Anhang – Quellen im Repository

| Thema | Datei |
|-------|-------|
| Master-Regelwerk | [`CLAUDE.md`](../CLAUDE.md) |
| DSGVO-Regeln | [`.claude/rules/02-dsgvo.md`](../.claude/rules/02-dsgvo.md) |
| Security-Regeln | [`.claude/rules/01-security.md`](../.claude/rules/01-security.md) |
| Audit-Regeln | [`.claude/rules/07-audit.md`](../.claude/rules/07-audit.md) |
| Datenbank-Regeln | [`.claude/rules/04-database.md`](../.claude/rules/04-database.md) |
| DB-Schema inkl. Audit-Trigger | [`scripts/database/create_database.sql`](../scripts/database/create_database.sql) |
| Anonymisierungs-Skript | [`scripts/anonymize-db.sql`](../scripts/anonymize-db.sql) |
| Passwort-Hashing | [`src/app/Helpers/SecurityHelper.php`](../src/app/Helpers/SecurityHelper.php) |
| TOTP- und E-Mail-2FA | [`src/app/Services/TotpService.php`](../src/app/Services/TotpService.php) |
| Auth-Session-Flow | [`src/app/Services/AuthService.php`](../src/app/Services/AuthService.php) |
| Rate-Limiting | [`src/app/Services/RateLimitService.php`](../src/app/Services/RateLimitService.php) |
| Audit-Trail-Service | [`src/app/Services/AuditService.php`](../src/app/Services/AuditService.php) |
| Workflow + Self-Approval-Sperre | [`src/app/Services/WorkflowService.php`](../src/app/Services/WorkflowService.php) |
| User-Repository (PW-Reset, TOTP-Speicher) | [`src/app/Repositories/UserRepository.php`](../src/app/Repositories/UserRepository.php) |
| Security-Header-Middleware | [`src/app/Middleware/SecurityHeadersMiddleware.php`](../src/app/Middleware/SecurityHeadersMiddleware.php) |
| Apache-Headerkonfiguration | [`src/public/.htaccess`](../src/public/.htaccess) |
| CSV-Export | [`src/app/Services/CsvExportService.php`](../src/app/Services/CsvExportService.php) |

---

*Dokument erstellt am 2026-04-21 im Rahmen der Vorbereitung auf die
DSGVO-Auditierung von VAES 1.4.0.*
