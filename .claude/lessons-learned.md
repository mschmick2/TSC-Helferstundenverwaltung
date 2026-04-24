# Lessons Learned — VAES

Wachsende Sammlung von Erkenntnissen, die in diesem Projekt teuer waren. Jeder Eintrag muss in kuenftigen Sessions zugaenglich sein — deshalb kein `git rm`, nur `APPEND`.

## Wann Eintrag anlegen?

- Bug hat > 30 Min gedauert
- Library/Framework verhielt sich unerwartet
- Annahme ueber bestehenden Code war falsch
- Security-Fix, der haette verhindert werden koennen
- Deploy scheiterte an Umgebungs-Differenz

## Wann NICHT?

- Normale Iterationen
- Tippfehler
- Allgemein-Wissen nicht projektbezogen

---

## Eintrags-Format

```
### YYYY-MM-DD — [Kurz-Titel]

**Kontext:**
Was war das Ziel? Welche Rolle/Gate?

**Problem / Ueberraschung:**
Was ging schief? Welche Annahme war falsch?

**Loesung:**
Wie wurde es gefixt? Was war der korrekte Weg?

**Praevention:**
Welche Rule ist zu ergaenzen/updaten?
→ konkreter Hinweis in .claude/rules/XX-...md
```

---

## Eintraege

### 2026-04-17 — Zugangsdaten im Repo (Initialer Eintrag aus Commit 273de47)

**Kontext:**
Bei einer fruehen Commit-Welle wurde `src/config/config.php` versehentlich mit realen Zugangsdaten committet. Der spaetere Fix-Commit `273de47` hat sie entfernt.

**Problem / Ueberraschung:**
`config.php` stand im Git-Tracking, obwohl `.gitignore` sie ausschliessen sollte. `.gitignore` greift nicht rueckwirkend — wenn eine Datei bereits getrackt ist, muss sie mit `git rm --cached` entfernt werden.

**Loesung:**
- `git filter-repo` zum nachtraeglichen Entfernen der Secrets aus der History
- Zugangsdaten rotiert (DB, SMTP)
- `config.example.php` als Template, nur diese gehoert in Git

**Praevention:**
- `.gitignore` beinhaltet `src/config/config.php` (bereits aktiv)
- Integrator-Gate G8 prueft explizit, dass `config.php` nicht im Diff ist
- Dokumentar-Gate G9 prueft "Kein `config.php` / Secrets im Diff"
- Vor `git add -A` IMMER `git status` lesen — nie blind alles stagen
→ Rule `01-security.md` — Section "Secrets / Config"

---

### 2026-04-17 — Rules-Doku divergiert vom DB-Schema (Audit-Log)

**Kontext:**
Im Rahmen der Test-Environment-Pipeline (Gates G2-G8) wurde in `scripts/anonymize-db.sql`
ein `UPDATE audit_log SET description=..., details=NULL, ...` geschrieben. Der Code kam
problemlos durch Syntax-Check und Code-Review (G3). Erst im **G6 Auditor-Gate** fielen
zwei Runtime-Bugs auf:

1. Der DB-Trigger `audit_log_no_update` blockiert JEDES UPDATE auf audit_log mit
   `SIGNAL SQLSTATE '45000'`. Das Script wuerde beim ersten Lauf hart scheitern.
2. Die Spalte `details` existiert im Schema gar nicht — sie heisst `metadata`.

**Problem / Ueberraschung:**
Ich hatte mich beim SQL-Schreiben auf `.claude/rules/07-audit.md` verlassen. Die Rule
dokumentierte einen Action-Katalog mit ~30 Werten (z.B. `entry_approve`, `role_assign`,
`entry_submit`) und ein Feld `details`. Beides war **fiktiv**: Schema-ENUM hat nur 12
Werte, Feld heisst `metadata`.

Die Rule-Doku war offenbar aus einem anderen Projekt uebertragen worden (Harness-
Template), ohne Schema-Abgleich. Syntax-Check + Code-Review konnten das nicht fangen,
weil das SQL syntaktisch valide ist — erst an der Datenbank bricht es.

**Loesung:**
- `UPDATE audit_log` durch `TRUNCATE TABLE audit_log` ersetzt (DDL bypasst Row-Level-
  Trigger; fuer Test-DB ist eine leere Audit-Historie vertretbar).
- `.claude/rules/07-audit.md` komplett an echtes Schema angepasst: ENUM-Katalog,
  Mapping-Tabelle Business-Aktion → action-Wert, Signatur `AuditService::log()` aus
  `src/app/Services/AuditService.php:43-52` uebernommen.
- `.claude/auditor.md`: `details` → `metadata` in Checklisten-Items.
- Neuer Regressions-Test `tests/Unit/Scripts/ScriptInvariantsTest.php` mit 14
  Checks, der kuenftig solche Rueckfaelle automatisch fangen wuerde.

**Praevention:**
Vor jedem SQL/Service-Code, der eine konkrete Tabelle beruehrt, **immer zuerst**
das wahre Schema grep'en:

```
scripts/database/create_database.sql   (Wahrheitsquelle Schema)
src/app/Services/AuditService.php      (Wahrheitsquelle Service-API)
```

Rules in `.claude/rules/` sind **Sekundaerquelle**. Bei Widerspruch: Schema/Code gewinnt,
Rule wird synchron nachgezogen.

→ Kein neuer Rule-Eintrag noetig — die 3 relevanten Rule-/Gate-Dateien sind bereits
auf Schema ausgerichtet. Der neue `ScriptInvariantsTest` sichert die kritischen
Script-Invarianten zusaetzlich strukturell ab.

---

### 2026-04-17 — phpMyAdmin-Export: malformierte VIEWs + DEFINER blockieren Import

**Kontext:**
Erster Live-Lauf der Test-Environment-Pipeline (G8-Nachgang). Strato-Produktions-
Dump wurde via phpMyAdmin exportiert und lokal importiert. Der Import via mysql-CLI
brach mit SQL-Syntax-Fehler auf Zeile 670 ab.

**Problem / Ueberraschung:**
Zwei ineinandergreifende Issues im phpMyAdmin-Export:

1. **DEFINER-Clauses auf Strato-User:**
   `CREATE ... DEFINER=\`o15312632\`@\`%\` VIEW ...` — der User `o15312632` existiert
   auf lokaler MySQL nicht. Views/Trigger mit unbekanntem DEFINER koennen nicht angelegt
   werden.

2. **Malformierte VIEW-Definitionen:**
   Bei VIEWs mit verschachtelten JOINs exportiert phpMyAdmin teilweise ueberzaehlige
   schliessende Klammern. Beispiel aus unserem Dump:
   `... WHERE (\`we\`.\`deleted_at\` is null)) ;` — ein \`(\` offen, zwei \`)\` zu.

**Loesung:**
`scripts/import-strato-db.php` um `sanitizeDumpForImport()` erweitert:
- Regex entfernt alle `DEFINER=\`user\`@\`host\``-Clauses
- Regex entfernt komplette `CREATE VIEW`-Statements (inkl. vorheriger
  `DROP TABLE IF EXISTS \`v_*\``)
- Ausgabe beim Lauf: wie viele Clauses/Views entfernt wurden (Transparenz)

Views sind fuer die Test-Env nicht essenziell (Tests arbeiten auf Tabellen,
nicht auf Views). Falls Views in Tests spaeter gebraucht werden: nach Import
manuell neu anlegen.

**Praevention:**
- phpMyAdmin-Export-Settings koennen DEFINER weglassen ("Keine DEFINER-Klausel",
  je nach phpMyAdmin-Version), aber darauf sollte sich der Import-Flow nicht
  verlassen — Sanitize laeuft jetzt immer.
- Bei kuenftigen Dump-Anomalien: `sanitizeDumpForImport()` erweitern, nicht
  User-Action verlangen.
- Der Regressions-Test `tests/Unit/Scripts/ScriptInvariantsTest.php` wurde
  NICHT um einen Sanitize-Check erweitert, weil es bislang nur zwei Verhaltens-
  Invarianten gibt. Falls sich zukuenftig weitere phpMyAdmin-Quirks zeigen,
  dann dort ergaenzen.

---

### 2026-04-17 — $user-Object vs. Array-Access: Runtime-Bug ueberlebt Syntax-Check + Unit-Tests

**Kontext:**
Modul 6 I1, Gate G3 Reviewer. Ich hatte in den neuen EventAdminController/EventTemplateController
sieben Stellen mit `$user['id']` — Array-Access auf ein User-Objekt. Bestehende Controller
nutzen konsistent `$user->getId()` (User ist eine Klasse mit Gettern, keine ArrayAccess-Impl).

**Problem / Ueberraschung:**
Der Bug haette zur Laufzeit mit "Cannot use object of type App\Models\User as array"
crashen muessen. Aber: `php -l` (Syntax-Check) uebersieht ihn (syntaktisch gueltig),
die 245 Unit-Tests fangen ihn nicht (keiner fuehrt einen Controller tatsaechlich aus).
Ohne das manuelle Review waere der Bug erst in Prod aufgefallen.

**Loesung:**
- Alle 7 Stellen auf `$user->getId()` umgestellt
- Neue Regressions-Tests in `tests/Unit/Controllers/EventAdminControllerInvariantsTest.php`
  fangen solche Patterns statisch:
  - `test_no_array_access_on_user_object` — Regex-Check
  - `test_all_write_actions_call_auditService_log` — Audit-Completeness
  - `test_enum_inputs_are_allowlist_validated` — Defensive Input-Check
  - Zwei weitere gegen Migration-Regressionen

**Praevention:**
Bis Feature-Tests (echte HTTP-Roundtrips via Slim-App + Test-DB) verfuegbar sind,
statische Invarianten-Tests als "poor man's feature tests" schreiben. Das Pattern:
Regex gegen bekannte Anti-Patterns im Code, Datei-basiert, ohne Bootstrap-Overhead.

Langfristig: `FeatureTestCase` (existiert seit dem Test-Env-Setup) aktivieren bei I3
wenn echte Workflow-Logik mit Audit-Assertion sinnvoll testbar ist.

---

### 2026-04-17 — FK ON DELETE CASCADE verletzt Audit-Integritaet

**Kontext:**
Modul 6 I1, Gate G5 DSGVO. Meine Migration 002 setzte `event_organizers.user_id`
auf `ON DELETE CASCADE` — intuitiv, weil bei User-Loeschung die Zuordnung "weg" sein soll.

**Problem / Ueberraschung:**
CASCADE loescht die Organizer-Historie MIT. Nach physischer User-Loeschung (z.B. nach
Ablauf der 10-Jahres-Aufbewahrungsfrist) ist nicht mehr rekonstruierbar, wer historisch
Organisator welchen Events war. Das verletzt den Audit-Anspruch der App (jede Zuordnung
soll 10 Jahre nachvollziehbar bleiben).

**Loesung:**
- `fk_eo_user` auf `ON DELETE RESTRICT` umgestellt
- Inline-Kommentar in Migration dokumentiert die Entscheidung + den
  Anonymisierungs-Workaround fuer DSGVO-Loeschrecht

**Praevention:**
Default-Pattern fuer User-Referenzen in Audit-relevanten Tabellen:

- `ON DELETE RESTRICT` fuer `user_id` in History/Participation-Tabellen
  (`event_organizers`, `event_task_assignments`, `work_entries`)
- `ON DELETE SET NULL` fuer Actor-Felder (`deleted_by`, `assigned_by`, `reviewed_by`)
  wo Aktor anonymisiert werden darf, aber der Eintrag erhalten bleibt
- `ON DELETE CASCADE` nur bei tight-coupling OHNE Audit-Bedeutung
  (z.B. `event_organizers.event_id` → `events.id`: wenn das Event weg ist,
  ist die Organizer-Zuordnung obsolet)

Bei DSGVO-Loeschrecht: User werden **anonymisiert** (Name/E-Mail/Adresse ueberschrieben),
nicht physisch geloescht — FK-Integritaet bleibt erhalten.

---

### 2026-04-18 — Playwright baseURL + absoluter Pfad: Base-Path wird ignoriert

**Kontext:**
Modul 6 I4, Gate G8 Smoke-Test. Playwright-Config hat `baseURL: http://localhost:8000/helferstunden/`.
Alle Specs nutzten `page.goto('/login')`, `page.goto('/admin/event-templates')` etc.
Resultat: `404 Not Found` fuer Pfade, die in der App registriert sind.

**Problem / Ueberraschung:**
Playwright folgt WHATWG-URL: ein mit `/` beginnender Pfad ist **absolut** und ersetzt
den kompletten Path-Teil der baseURL. Aus `http://localhost:8000/helferstunden/` +
`/login` wird also `http://localhost:8000/login` — der `/helferstunden`-Prefix geht verloren.
Ohne Prefix findet der Slim-Router keine Route und wirft 404.

Das ist **kein Playwright-Bug**, sondern Standard-URL-Resolution. Bestehende Specs
liefen nur, weil der User sie bisher mit `BASE_URL=http://localhost:8000/` (ohne Prefix)
laufen liess.

**Loesung:**
`page.goto()`-Pfade ohne fuehrenden Slash schreiben:
- `page.goto('login')` + baseURL mit Trailing-Slash → respektiert Prefix
- `page.goto('admin/event-templates')` statt `/admin/event-templates`

Angepasst wurden `e2e-tests/helpers/auth.js` (Login-Helper) und neue Spec
`e2e-tests/tests/08-event-templates.spec.js`. Die Specs 03-07 haben denselben Bug
und laufen nur gegen Root-baseURL — Fix als Follow-up dokumentiert.

**Praevention:**
- Team-Konvention fuer alle neuen Playwright-Specs: **relative Pfade (ohne `/`)**
- Bei Anlegen eines neuen Specs: Pattern `page.goto('irgendwas')` statt `/irgendwas`
- baseURL mit Trailing-Slash behalten (`/helferstunden/` statt `/helferstunden`)

Koennte in `.claude/rules/` unter einem neuen `09-e2e.md` landen, wenn die
Playwright-Suite weiter waechst.

---

### 2026-04-18 — `DROP COLUMN IF EXISTS` nicht portabel zwischen MySQL und MariaDB

**Kontext:**
Modul 6 I4, Gate G8. Migration `004_events_source_template.down.sql` nutzte
`ALTER TABLE events DROP COLUMN IF EXISTS source_template_version;`. Lief lokal
auf WAMP mit Fehler `#1064 Fehler in der SQL-Syntax bei 'IF EXISTS ...'`.

**Problem / Ueberraschung:**
`DROP COLUMN IF EXISTS` ist erst ab **MySQL 8.0.29** vorhanden. MariaDB (was WAMP
oft mitbringt) und aeltere MySQL-Versionen kennen das nicht. Die UP-Migration
nutzte bereits das portable INFORMATION_SCHEMA-Pattern — die DOWN-Migration
wich ab und verliess sich auf das neuere Feature.

Strato laeuft mit MySQL 8.4, dort haette es funktioniert — aber lokale Dev-Envs
(WAMP/XAMPP) sind oft aelter. Inkonsistenz zwischen UP- und DOWN-Migration ist
der eigentliche Defekt.

**Loesung:**
Die DOWN-Migration analog zur UP-Migration umgebaut: INFORMATION_SCHEMA.COLUMNS-
Check + `PREPARE stmt FROM @sql` + `EXECUTE stmt`. Damit laeuft der Rollback auf
jeder MySQL-Version ≥ 5.7 und auf MariaDB ≥ 10.x.

**Praevention:**
- Rule-Ergaenzung in `.claude/rules/04-database.md`: Migrations (up + down) muessen
  **beide** mit INFORMATION_SCHEMA-Pattern arbeiten, nie mit `IF EXISTS` auf
  Spalten-Ebene (Tabellen-Ebene ist OK).
- Bei neuen Down-Migrations: Pattern aus UP kopieren, nicht eigenes erfinden.
- Lokale Dev-DB auf MySQL ≥ 8.0.29 bringen (Empfehlung) ODER MariaDB, in beiden
  Faellen muss das INFORMATION_SCHEMA-Pattern greifen.

---

### 2026-04-18 — `ValidationException`-Signatur: array statt string

**Kontext:**
Modul 6 I4, Gate G6 Audit-Fix-Iteration. Die IDE markierte 12 Aufrufe von
`new ValidationException('string')` in `EventTemplateService.php` als Typ-Fehler.

**Problem / Ueberraschung:**
`App\Exceptions\ValidationException::__construct(array $errors, ...)` erwartet
seit langem ein **Array**, nicht einen String. Der Rest des Codebases
(`WorkEntryController`) nutzt korrekt `throw new ValidationException($errorsArray)`.
In I4 hatte die Coder-Phase durchgaengig Strings uebergeben.

PHP 8 mit `strict_types=1` wirft hier zur Laufzeit einen `TypeError`, KEINE
`ValidationException`. Im Fehler-Pfad sieht der User dann einen 500er mit
"Argument must be of type array, string given" statt einer sauberen Flash-Message.

Warum hat niemand das gesehen?
- `php -l` (Syntax-Check) ist syntaktisch OK — Parameter-Typ wird nicht gepruft
- Unit-Tests decken nur Happy-Path ab — die `throw`-Zweige werden nie ausgeloest
- G3 Reviewer/G4 Security haben die Zeilen ueberflogen, nicht gegen die Klassen-
  Signatur validiert

Die IDE hat die Fehler erst offengelegt, als ich waehrend G6-Fix eine andere Stelle
in der gleichen Datei editierte — der Diagnostics-Hook rechnete die ganze Datei
neu und meldete die 12 pre-existing Bugs.

**Loesung:**
Alle 12 Stellen auf `throw new ValidationException(['string'])` umgestellt. Konsistent
mit restlichem Codebase.

**Praevention:**
- **G3 Reviewer** und **G4 Coder**: bei Exceptions **immer** kurz die Signatur
  der Exception-Klasse checken (1x per Klasse, nicht pro Call). Die richtige
  Pruefung: Grep nach `class NameException` → `__construct` lesen.
- Bei neuen Exception-Klassen: eine Convenience-Factory erwaegen
  (`ValidationException::single(string $msg)`), damit String-Usage valide wird
  — aber das veraendert den Service-Layer, darum vorlaeufig aufgehoben.
- Langfristig: PHPStan/Psalm-Config aktivieren, die solche Arg-Typ-Mismatches
  statisch erkennt. Wuerde die 12 Bugs noch VOR G3 fangen.

---

### 2026-04-18 — DB-Check-Constraint findet Service-Validation-Luecke (fix-slot Offsets)

**Kontext:**
Modul 6 I4, Gate G8 Browser-Smoke-Test. Playwright klickte den Derive-Flow durch
und bekam 500: `Check constraint 'chk_et_fix_times' is violated` beim Insert in
`event_tasks`.

**Problem / Ueberraschung:**
`EventTemplateService::validateTaskData()` erlaubte `slot_mode='fix'` auch ohne
`default_offset_minutes_start`/`_end`. Beim `deriveEvent()` wurden die Offsets
zu `null` aufgeloest → `event_tasks`-Row mit `slot_mode='fix'` + `start_at=NULL`
+ `end_at=NULL` → DB-Constraint `chk_et_fix_times` schlaegt zu.

Der Bug war **latent**: Nur beim Ableiten eines Events **aus einem fix-Template
ohne Offsets** kracht es. Der Happy-Path "Template anlegen + Task erstellen"
funktioniert bis dahin einwandfrei. Weder G2 Coder, noch G3 Reviewer, noch G4
Security haben es gesehen — der Smoke-Test hat es beim ersten echten End-to-End-
Durchlauf gefunden.

Warum hat niemand das gesehen?
- Der Check-Constraint wurde in I1 auf `event_tasks` gesetzt — das Template-Schema
  (I4-Scope) hat keinen entsprechenden Constraint, weil Offsets dort null sein
  duerfen (ein Template KANN variable Slots haben).
- Service-Validation prueft Feldformat, nicht die Kombinations-Invariante
  `slot_mode=fix ⇒ Offsets != null`.

**Loesung:**
1. `validateTaskData()` um expliziten Check erweitert: `slot_mode === SLOT_FIX && (offsetStart === null || offsetEnd === null)` → `ValidationException`.
2. Neuer Invariants-Test `test_fix_slot_requires_both_offsets` in
   `EventTemplateServiceInvariantsTest.php` sichert die Regel statisch ab.
3. Der Smoke-Spec fuellt die Offset-Felder, damit der Happy-Path durchlaeuft.

**Praevention:**
- **Service-Layer-Prinzip**: Business-Invarianten gehoeren in die Service-Validation,
  nicht nur als DB-Check-Constraint. Der Constraint ist die *letzte* Verteidigungs-
  linie, nicht die erste.
- Bei neuen Invarianten-Regeln: immer beide Seiten pruefen (Service + DB), damit
  User saubere Validation-Fehler sehen statt 500er.
- Smoke-Tests mit realistischen End-to-End-Flows sind unverzichtbar — statische
  Invariants-Tests allein finden solche Kombinations-Luecken nicht.
- Bei aggregierten Schemas (Template → Event): Template-Validation muss die
  **engere** Event-Side-Constraint spiegeln, nicht die losere Template-Side.

---

### 2026-04-18 — Slim-Bridge ControllerInvoker: `array $args` wird NICHT injected

**Kontext:**
Modul 6 I5, Gate G8 Smoke-Test. Neuer `IcalController::subscribe(Request, Response, array $args)`
lieferte 500: `Invoker\Exception\NotEnoughParametersException — no value was given for parameter 3 ($args)`.

**Problem / Ueberraschung:**
Ich hatte die uebliche Slim-4-Controller-Signatur genommen:
`function action(Request $request, Response $response, array $args): Response`.

In diesem Projekt wird Slim jedoch via **php-di/slim-bridge** angesprochen, mit einem
eigenen `ControllerInvoker`. Der nutzt PHP-DI-Reflection fuer Parameter-Resolution:
- `Request` / `Response` werden ueber Type-Hint injected
- Ein `array`-Parameter wird aber NICHT mit Route-Args populiert — der Invoker weiss
  nicht, was er da reinstecken soll und wirft `NotEnoughParametersException`

Alle anderen Controller im Projekt (z.B. `MemberEventController`, `EventAdminController`)
haben die Signatur `function action(Request $request, Response $response): Response`
und holen Route-Args via Helper `$this->routeArgs($request)` aus
`BaseController::routeArgs()`, das intern `RouteContext::fromRequest()` nutzt.

Ich hatte das Pattern uebersehen, weil Slim-Dokumentation den dritten Parameter als
Standard zeigt. Es ist aber ein slim-bridge-spezifisches Invoker-Verhalten.

**Loesung:**
IcalController als `extends BaseController` geschrieben, `array $args`-Parameter entfernt,
und Route-Args via `$this->routeArgs($request)['token']` gelesen.

**Praevention:**
- Neue Controller: **immer** `extends BaseController` und `$this->routeArgs($request)['<name>']`.
- Rules-Ergaenzung in `.claude/rules/03-framework.md`: Beispiel-Snippet ist bereits so,
  aber Hinweis zum `array $args`-Gotcha hinzufuegen (Slim-Bridge != Pure-Slim).
- Wenn ein neuer Controller auch nur mit einem Parameter-Typ-Fehler bricht: **zuerst**
  einen bestehenden Controller als Template nehmen, nicht die Slim-Doku.

---

### 2026-04-18 — FullCalendar v6 hat kein separates `main.min.css`

**Kontext:**
Modul 6 I5, Integration von FullCalendar in `events/calendar.php` und `my-events/calendar.php`.
Download via `Invoke-WebRequest` schlug bei `main.min.css` mit HTTP 404 fehl:
`Couldn't find the requested file /main.min.css in fullcalendar`.

**Problem / Ueberraschung:**
FullCalendar v5 hatte ein separates CSS-Bundle (`main.min.css`). **Ab v6** wurde das
geaendert: Das JS-Bundle (`index.global.min.js`) injected die Styles zur Laufzeit
selbst (ueber dynamisch generierte `<style>`-Tags). Es gibt gar **kein** separates
CSS-File mehr.

Ich hatte blind die v5-Dokumentation als Quelle genommen und auch ein `<link>`-Tag
auf das nicht existierende CSS in den Views.

**Loesung:**
- `<link rel="stylesheet">` fuer `main.min.css` aus beiden Calendar-Views entfernt
- README in `src/public/js/vendor/fullcalendar/` korrigiert (Hinweis: kein CSS noetig)

**Praevention:**
- Bei neuen JS-Libraries: erst die **aktuelle** Doku (npm readme / offizielle Docs)
  lesen, nicht Stack-Overflow-Antworten, die meist aelter sind.
- Bei Library-Upgrades: Changelog/Breaking-Changes pruefen, auch fuer Asset-Struktur.

---

### 2026-04-18 — `app.url` + `base_path`: Doppelter Prefix bei naiver URL-Konstruktion

**Kontext:**
Modul 6 I5, `MemberEventController::icalSettings()` konstruiert die persoenliche
Abo-URL fuer den iCal-Client: `https://domain/helferstunden/helferstunden/ical/subscribe/...`
— der `/helferstunden`-Prefix war DOPPELT.

**Problem / Ueberraschung:**
In `src/config/config.php` ist `app.url` oft als vollstaendige Produktions-URL
**inklusive** base_path eingetragen:
```php
'app' => [
    'url' => 'https://192.168.3.98/helferstunden',
    'base_path' => '/helferstunden',
],
```

Mein erster Ansatz war die naive Konkatenation:
```php
$subscribeUrl = $appUrl . $basePath . '/ical/subscribe/' . $token;
```

Das doppelt den Prefix, weil `$appUrl` ihn schon enthaelt. Der Bug ist nicht sichtbar,
solange man immer nur relative URLs baut (ViewHelper::url() macht das richtig), aber
fuer absolute URLs wie die iCal-Abo-URL, die ausserhalb der Session vom Kalender-Client
gepollt wird, muss sie genau einmal den Prefix haben.

Historisch ist im Projekt unklar, ob `app.url` Origin-only oder inkl. base_path sein soll.
Beide Varianten sind in der Praxis angetroffen worden.

**Loesung:**
Defensives Prefix-Handling im Controller:
```php
$appUrl = rtrim($this->settings['app']['url'] ?? '', '/');
if ($basePath !== '' && !str_ends_with($appUrl, $basePath)) {
    $appUrl .= $basePath;
}
$subscribeUrl = $appUrl . '/ical/subscribe/' . $token;
```

**Praevention:**
- Rules-Ergaenzung in `.claude/rules/03-framework.md`: fuer **absolute** URLs (Mail,
  iCal-Abo, Exports, Webhook-Callbacks) IMMER das defensive Pattern verwenden, nie
  `$appUrl . $basePath . ...`.
- Langfristig besser: `app.url` klar als Origin-only definieren und Validator-Check
  in der Config-Load-Phase. Aber das ist ein Refactor fuer ein eigenes Ticket.
- Noch besser: zentrale Helper-Methode `ViewHelper::absoluteUrl($path)` analog zu
  `ViewHelper::url()`. Damit kann niemand mehr die Konkatenation falsch machen.

---

### 2026-04-21 — E2E-Spec als Duplikat geschrieben, weil Bestands-Coverage uebersehen

**Kontext:**
Modul 8 Inkrement 7 sollte "E2E Event-Abschluss + Auto-WorkEntry-Generierung" als neue Spec `08-event-completion.spec.ts` liefern. Ein Explore-Agent-Scan zu Session-Anfang meldete "Backend komplett, nur E2E-Test-Gap" — die Spec wurde geschrieben und gruente standalone, flakte im Gesamtlauf wegen Cross-Spec-Daten (siehe folgender Eintrag). Beim Debuggen fiel auf, dass `04-event-workflow.spec.ts` Test 5 ("ALICE sieht automatisch erzeugten Antrag in /entries") denselben Flow bereits durchspielt — Event mit vergangener Laufzeit, fixer Slot, ALICE uebernimmt, Admin schliesst ab, Detail-Body enthaelt Event-Titel.

**Problem / Ueberraschung:**
Der Explore-Agent hat den Flow gesucht als "Event-Completion-Test" — die Bestands-Spec heisst aber "Event-Komplettflow" und die Test-Beschreibung spricht von "automatisch erzeugtem Antrag", nicht von "Completion". Naming-Divergenz hat die Duplikat-Erkennung unterlaufen. Konsequenz: eine komplette neue Spec (134 Zeilen + Debug-Zeit) wurde geschrieben, bevor die Duplikation auffiel.

**Loesung:**
- Spec 08 geloescht (c9fabe7 vorausgehend bereinigt).
- Die beiden echten Delta-Assertions (Status-Badge "Eingereicht" in der Liste, Origin-Satz "Automatisch erzeugt aus Event" im Detail) wandern in Spec 04 Test 5.
- Volle Suite 33/33 headless, Spec 04 5/5 headed gruen.

**Praevention:**
- Vor JEDER neuen E2E-Spec IMMER grep auf die Service-/Route-Bestandteile im ganzen `tests/e2e/specs/`-Baum, nicht nur nach dem Spec-Titel. Beispiel: fuer Event-Completion `grep -rE "complete\(\)|EventCompletionService|Automatisch erzeugt"`.
- Der Explore-Agent-Prompt muss ausdruecklich fragen "welche **Flows** decken die bestehenden Specs bereits ab?", nicht "gibt es eine Spec namens X?".
- `.claude/tester.md` bekommt einen E2E-Hygiene-Abschnitt mit expliziter Coverage-Suche als G7-Kriterium (diese Session ergaenzt).

---

### 2026-04-21 — Cross-Spec-Daten sprengen `tbody tr.first()`-Selektor

**Kontext:**
Die inzwischen geloeschte `08-event-completion.spec.ts` Test 4 navigierte ALICE auf `/entries` (ohne Query-Parameter) und pruefte `page.locator('tbody tr').first().locator('.badge')` auf "Eingereicht". Standalone gruen in 9.4s, im Gesamtlauf schlug die Assertion fehl — erste Zeile war Entry `2026-00001` mit Status "Freigegeben" aus Spec 02.

**Problem / Ueberraschung:**
`WorkEntry`-Listing sortiert per Default nach `work_date DESC`. Wenn mehrere Eintraege am selben Tag angelegt werden (Test-Fall: alle heute), ist der Tie-Break nicht stabil spezifiziert — MySQL faellt auf Physical-Order (≈ `id ASC`) zurueck, der neu generierte Eintrag steht damit hinten. Der Flake war also nicht "flaky im Sinne von Zeit/Zufall", sondern deterministisch falsch auf Cross-Spec-Datenbestand.

Im Projekt existiert bereits `WorkEntryListPage.goto()`, die explizit `/entries?sort=created_at&dir=DESC` aufruft — exakt aus diesem Grund. Die neue Spec hat den Page-Object-Kontrakt umgangen und `page.goto('/entries')` direkt verwendet.

**Loesung:**
Im vorausgehenden Aufraeumen landeten die Assertions in Spec 04, die Page-Object-Kontrakt benutzt. Generelle Regel fuer neue Specs:
```ts
// RICHTIG — Page-Object, enthaelt den stabilisierenden Query-String
await list.goto();
const topRow = page.locator('tbody tr').first();

// FALSCH — defaultet auf work_date DESC, Tie-Break instabil
await page.goto('/entries');
const topRow = page.locator('tbody tr').first();
```

**Praevention:**
- `.claude/tester.md` E2E-Hygiene-Abschnitt listet Page-Object-Pflicht fuer Listen-Navigation als Gate-G7-Kriterium (diese Session ergaenzt).
- Bei Listen-Tests immer zuerst pruefen: Welches Sort-Verhalten garantiert der neueste Eintrag oben? Wenn der Default nicht passt, Query-String setzen — nicht den Test-Erfolg dem Zufall ueberlassen.

---

### 2026-04-22 — ASCII-Retransliteration per Regex zerstoert Woerter wie "neue", "dass"

**Kontext:**
Erstellung einer DOCX-Version des Benutzerhandbuchs aus `docs/Benutzerhandbuch.md`.
Die Markdown-Quelle ist durchgehend ASCII-transliteriert (`ae`, `oe`, `ue`, `ss`
statt `ä`, `ö`, `ü`, `ß`), weil sie ueber die Jahre aus CLI-Eingaben und
Harness-Regeln gewachsen ist. Die DOCX-Ausgabe soll aber korrekte deutsche
Typografie haben.

**Problem / Ueberraschung:**
Der naive erste Wurf ersetzte pauschal per Regex `ue→ü`, `ae→ä`, `oe→ö`,
`ss→ß`. Resultat in einer Stichprobe von 20 Absaetzen: >50 kaputte Woerter.

- `ue → ü` zerstoert *neue* → *nü*, *aktuell* → *aktüll*, *manuell* → *manüll*,
  *individuelle* → *individülle*, *Grauer* → *Grürer*, *Blauer* → *Blürer*.
- `ss → ß` zerstoert *dass* → *daß*, *muss* → *muß*, *passiert* → *paßiert*,
  *lassen* → *laßen*, *Session* → *Seßion*, *password* → *paßword*,
  *Permissions* → *Permißions*, *Assignment* → *Aßignment*.
- `oe → ö` zerstoert *Poet*, *Coexistenz*, *Proest* (wenn Substring
  irgendwo im Text).
- `ae → ä` trifft seltener, aber z.B. *Rafael*, *Israel* waren in der
  Stichprobe.

Syntax-Check (`python -c`) sah sauber aus, Unit-Tests gibt es fuer diesen
Konverter nicht — erst das Oeffnen der DOCX-Datei macht die Zerstoerung
sichtbar.

**Loesung:**
`tools/md-to-docx/build-handbuch-docx.py` mit **expliziter Wort-Whitelist**:

```python
WORD_MAP: dict[str, str] = {
    "abschliessen": "abschließen",
    "Abhaengig": "Abhängig",
    # ... 300+ Eintraege, einmalig manuell aus --audit-Lauf erstellt ...
    "zurueckzusetzen": "zurückzusetzen",
}

_WORD_RE = re.compile(r"[A-Za-z][A-Za-z]+")

def retransliterate(text: str) -> str:
    return _WORD_RE.sub(
        lambda m: WORD_MAP.get(m.group(0), m.group(0)),
        text,
    )
```

Der Script-`--audit`-Modus listet alle Kandidaten-Token (`ae/oe/ue/ss`-haltig),
die noch NICHT in der Map sind — einmal durchlaufen, Map komplettieren,
dann erst echter Export-Lauf.

Ergebnis: 228 ä, 107 ö, 369 ü, 16 Ä, 23 Ö, 19 Ü, 20 ß — null Fehltreffer.

**Praevention:**
- Fuer ASCII→Umlaut-Retransliteration in deutschen Texten IMMER eine
  explizite Wort-Whitelist bauen, nie pauschale Regex.
- Vor dem ersten Echt-Lauf: Audit-Modus (Liste der ungemappten Kandidaten)
  nutzen — so entsteht die Whitelist in einem Rutsch, ohne dass man durch
  die DOCX-Datei hetzen muss, um Schaden zu finden.
- Wiederverwendbares Tool unter [tools/md-to-docx/](../tools/md-to-docx/)
  ablegen; die Map waechst inkrementell, wenn das Handbuch neue Begriffe
  bekommt.
- Gilt analog fuer andere ASCII-Transliterationen (Franzoesisch, Polnisch,
  Tschechisch) — auch dort sind Muster wie `oe`, `ss`, `cz` ambig.

---

### 2026-04-22 — Soft-Delete vs. FK ON DELETE RESTRICT bei Self-Referenzen

**Kontext:**
Modul 6 I7a (Aufgabenbaum), Gate G3 Reviewer. Die Self-FK `event_tasks.parent_task_id`
ist mit `ON DELETE RESTRICT` definiert (G1-Delta-Entscheidung — Tree-Aufraeumen
erfolgt bewusst via Service, nicht implizit durch DB). Das Loeschen eines Knotens
laeuft im Projekt grundsaetzlich als Soft-Delete (`UPDATE deleted_at = NOW()`),
nicht als physisches `DELETE FROM`.

**Problem / Ueberraschung:**
ON DELETE RESTRICT greift ausschliesslich bei `DELETE FROM`. Ein Soft-Delete via
`UPDATE deleted_at` umgeht den FK komplett — die Datenbank sieht die Operation als
ganz normales Spalten-Update, nicht als Loeschung. Konsequenz: wenn eine Gruppe
soft-deletet wird, bleiben ihre soft-deleteten Kinder mit `parent_task_id =
<Gruppen-ID>` einfach in der Tabelle stehen. Die Service-Validation
(`countActiveChildren > 0` blockiert das Soft-Delete einer Gruppe mit AKTIVEN
Kindern) deckt nicht den Fall ab, dass die Kinder bereits soft-deletet sind und
nun mit ihrer Eltern-Gruppe gemeinsam zu "Zombie-Referenzen" werden.

Aktuell (I7a) ohne Restore-Feature unkritisch, weil niemand jemals die geloeschten
Knoten wieder sichtbar macht. Aber: ein zukuenftiger Restore-Service muss explizit
entscheiden, ob Kinder mit ihrem Parent reaktiviert werden oder nicht — sonst
wuerden plotzlich Kinder ohne aktiven Parent in der UI auftauchen, oder ein
restorter Parent haette stillschweigend wieder soft-deletete Kinder.

**Loesung:**
Inline-Doku-Kommentar in `TaskTreeService::softDeleteNode()` direkt vor dem
`softDelete`-Call, der die Zombie-Mechanik explizit benennt (Datei:
`src/app/Services/TaskTreeService.php`). Plus dieser Lessons-Eintrag als
"Stolperstein-Hinweis" fuer den spaeteren Restore-Feature-Autor.

**Praevention:**
- Beim Bau eines Restore-Features fuer event_tasks IMMER zuerst pruefen:
  hat der Knoten einen geloeschten Parent? Falls ja: entweder Restore-Kette
  bis zur Wurzel mitziehen, oder Restore ablehnen mit klarer Fehlermeldung,
  oder Knoten beim Restore an einen aktiven Vorfahren umhaengen.
- Generelles Pattern fuer Self-FK + Soft-Delete: bei Projekten mit
  Restore-Feature solche FKs bevorzugt mit zusaetzlicher
  `parent.deleted_at IS NULL`-Invariante absichern (entweder per Trigger
  oder per Service-Validation), damit Zombie-Referenzen nicht entstehen.
- Allgemein: ON DELETE RESTRICT schuetzt NICHT vor Soft-Delete-Inkonsistenzen.
  Die Annahme "FK regelt das" ist trichterhaft, sobald Soft-Delete im Spiel ist.

→ Aufgenommen in `.claude/rules/04-database.md` ist nicht noetig — die Soft-Delete-
Konvention ist dort bereits dokumentiert; der Restore-Spezialfall ist so selten,
dass eine Rules-Erweiterung Overhead waere. Dieser Lessons-Eintrag reicht.

---

### 2026-04-22 — MySQL PREPARE/EXECUTE akzeptiert kein SIGNAL SQLSTATE

**Kontext:**
Modul 6 I7a, Migration 009 Down-Skript, G7 Tester-Durchlauf gegen
Live-Test-DB.

**Problem / Ueberraschung:**
SIGNAL SQLSTATE '45000' innerhalb eines dynamisch gebauten @sql-
Strings, ausgefuehrt via `PREPARE stmt FROM @sql; EXECUTE stmt;`
— wirft MySQL-Fehler 1295 ("This command is not supported in the
prepared statement protocol yet"). Der Sicherheits-Abbruch
funktioniert zwar (Schema intakt, Daten intakt), aber die
vorgesehene Klartext-Diagnose fuer den DBA ist verloren — er sieht
nur eine technische Protokoll-Meldung statt der durchdachten
Bereinigungs-Anweisung.

Die Restriktion ist eine MySQL-Protokoll-Eigenschaft, kein
Syntax-Fehler — `php -l`, `phpunit` und Statisch-Inspektions-Tests
erkennen sie nicht. Erst der Live-Lauf gegen MySQL faengt es.

**Loesung:**
SIGNAL aus dem PREPARE-Wrapper herausziehen und in eine kurzlebige
Stored Procedure mit DELIMITER-Wechsel verlagern. Pattern:

- `DROP PROCEDURE IF EXISTS _migration_xxx_safety_check;` am Anfang
  (Idempotenz, raeumt eine evtl. hinterlassene Prozedur eines
  vorigen Abbruchs).
- `DELIMITER $$` ... `END$$ DELIMITER ;`-Wrap fuer die Prozedur-
  Definition (von phpMyAdmin auf Strato live verifiziert,
  vgl. `create_database.sql`).
- IF-Bedingung auf Session-Variablen, SIGNAL im Body.
- `CALL _migration_xxx_safety_check();` direkt nach `CREATE`.
- `DROP PROCEDURE _migration_xxx_safety_check;` direkt nach `CALL`.
  Bei Abbruch durch SIGNAL bleibt die Prozedur im Schema fuer
  DBA-Debugging — der naechste Lauf raeumt sie via `DROP IF EXISTS`
  am Anfang.

**Praevention:**
- Jede Migration mit `SIGNAL` muss gegen eine Live-Test-DB
  durchgespielt werden, sowohl im Erfolgs- als auch im geplanten
  Abbruch-Pfad. Statische SQL-Pruefung erkennt die PREPARE-
  Restriktion nicht — es ist eine Protokoll-Laufzeit-Restriktion.
- G7 Tester-Gate: SIGNAL-Pfade muessen in der Checkliste explizit
  manuell getriggert werden, nicht nur code-gelesen.
- `MESSAGE_TEXT`-Laenge unter **128 Zeichen** halten (MySQL-Limit);
  detaillierte Reparatur-Anweisungen gehoeren in den Skript-Kommentar
  direkt oberhalb des Checks, damit der DBA auch bei phpMyAdmin-
  Versionen, die `MESSAGE_TEXT` verschlucken, die Anleitung im
  Skript findet.

→ Konkrete Umsetzung in
[`scripts/database/migrations/009_event_task_tree.down.sql`](../scripts/database/migrations/009_event_task_tree.down.sql)
als Vorlage fuer kuenftige Migrationen mit Sicherheits-SIGNAL.

---

### 2026-04-22 — Nested SortableJS-Konfiguration: Minimal ist das Maximum

**Kontext:**
Modul 6 I7b1 Phase 3 — Aufgabenbaum-Editor mit verschachtelten Listen
(task-tree-root → task-node LI → task-tree-children → ...), beides als
Sortable initialisiert mit gleichem `group: 'event-tasks'`.

**Problem / Ueberraschung:**
Drei Iterationen Options-Umlegen, bevor der Flow stabil lief:
1. Default-Config (nur `group`, `handle`, `animation`): Drop-Targets in
   verschachtelten ULs und leeren Gruppen wurden nicht erkannt.
2. Option-Paket mit `fallbackOnBody: true`, `emptyInsertThreshold: 12`,
   `swapThreshold: 0.65`, `invertSwap: true`, `draggable: '.task-node'`:
   Drag-and-Drop komplett kaputt, `onEnd` feuerte gar nicht mehr — der
   Ghost folgte dem Cursor, aber Drops wurden nie verarbeitet. Besonders
   `invertSwap: true` kombiniert mit `swapThreshold: 0.65` bringt in
   SortableJS 1.15.x die Drop-Zone-Geometrie zum Kollabieren.
3. Minimal-Reset auf nur `fallbackOnBody: true` +
   `emptyInsertThreshold: 12`: funktioniert stabil, inklusive leerer
   Ziel-Gruppen.

Blind-Fumfen mit Options war die falsche Strategie — erst
Diagnose-Logs in onChoose/onStart/onMove/onEnd haetten den Rollback
gezielt machen koennen.

**Loesung:**
- `fallbackOnBody: true` rendert den Ghost unter `document.body`, sodass
  er aus der Quell-UL heraus in Ziel-ULs wandern kann.
- `emptyInsertThreshold: 12` (px) aktiviert leere Ziel-Gruppen als
  gueltige Drop-Targets — ohne diese Option sind sie zu klein (keine
  Kinder-LIs = keine Hoehe).
- Alles andere (`swapThreshold`, `invertSwap`, explizites `draggable`,
  `delayOnTouchOnly`) NICHT setzen, solange der Default funktioniert.

**Praevention:**
- Bei SortableJS-Setup in neuen Tree-/Nested-Listen-UIs (I7c Templates-
  Editor, I7b2 evtl. Mitglieder-Accordion): nur `fallbackOnBody` +
  `emptyInsertThreshold` setzen. Weitere Options erst nach konkretem
  Nachweis einbauen, welche Regression sie loesen.
- Inline-Kommentar im JS dokumentiert, warum gerade diese zwei Options.
- Bei Symptom "Drag geht nicht / Drop wird abgelehnt": zuerst
  `console.info` in onChoose/onStart/onMove/onEnd einbauen, bevor
  Options geaendert werden. Eine Iteration pro Change, nicht mehrere
  Options gleichzeitig.

→ Ergaenzung in `.claude/rules/05-frontend.md` (SortableJS-Pattern).

---

### 2026-04-22 — HTTP-String-vs-Service-strict-Type-Drift

**Kontext:**
Modul 6 I7b1 Phase 3 — EventAdminController ruft TaskTreeService fuer
Tree-Mutationen. Der Service hat `declare(strict_types=1)` und strikte
Parametertypen (`?int`, `int`, `float`, etc.).

**Problem / Ueberraschung:**
Vier Runtime-Bugs hintereinander, alle aus demselben Muster: HTTP-
Form-Inputs kommen immer als String oder leerer String, der Service
erwartet getypte Werte.

1. `parent_task_id` als `"2"` → TypeError, weil `normalizeParentId(?int)`
   keinen String akzeptiert → Fatal → Slim-HTML-500 → Modal-JS kann
   nicht parsen → nackt "HTTP 500" im Toast.
2. `start_at: ""`, `end_at: ""` → umgehen den Service-Null-Check
   `($startAt === null || $endAt === null)` und landen als ungueltiger
   DATETIME-String im INSERT → PDOException → 500.
3. Analog fuer `category_id: ""`, `capacity_target: ""`.
4. Aggregator-Schnittstelle: Controller gab Assoc-Arrays an `buildTree`,
   der aber EventTask-Objekte erwartet → "Call to a member function
   getParentTaskId() on array".

Unit-Tests mit Mocks haetten das nicht gefangen — die mocken oft
schon mit sauberen Typen. Auch die statischen Invariants-Tests (Regex
gegen Code) pruefen Struktur, nicht Laufzeittypen.

**Loesung:**
- Ein zentraler Helper im Controller: `normalizeTreeFormInputs(array
  $data): array`. Macht:
  * `parent_task_id` zu `?int` (null bei '', '0', null, 0).
  * Leere Strings in `start_at`, `end_at`, `category_id`,
    `capacity_target` zu `null`.
- Drei Actions rufen den Helper (create, update, convert). Die anderen
  fuenf haben einfachere Payloads und typen direkt im Action-Body
  (`(int) $data['new_parent_id']`, `array_map('intval', ...)`).
- Aggregator-Schnittstelle: der `loadEventTasks`-Hilfer aus Phase 2
  (der Arrays baute) wurde geloescht; Controller reicht Repository-
  Objekte direkt an den Aggregator. Fuer JSON-Serialisierung gibt es
  `serializeTreeForJson`, die einzige Stelle, an der Objekte in flache
  Arrays umgewandelt werden.

**Praevention:**
- Rules `03-framework.md` sollte Pattern "HTTP-Input → Service-Input
  im Controller normalisieren" aufnehmen, sobald I7b2 oder I7c den
  Helper wiederverwendet (momentan I7b1-spezifisch).
- Runtime-Coverage: Playwright-E2E (neu in Phase 4) faengt genau diese
  Fehlerklasse zukuenftig. Die zwei Regressions-Tests
  `test_create_child_flow` (parent_task_id als String) und
  `test_validation_error_shown_in_toast` (leere Slot-Zeiten) sind
  explizit als Regressionsabdeckung der Fixes markiert.
- FeatureTestCase-Setup mit echter DB (Follow-up) wuerde die Lücken
  zwischen Mock-Unit-Tests und Browser-E2E schliessen.

→ Siehe `fix`-Commits `e142d9d`, `d7ff41c`, `c5f78a2` auf
`feature/event-task-tree-i7b1`.

---

### 2026-04-22 — View-Rekursion via Container-Closure, nicht naked include

**Kontext:**
Modul 6 I7b1 Phase 3 — rekursive Render-Partials fuer den
Aufgabenbaum (_task_tree_node.php, spaeter _task_tree_readonly.php).

**Problem / Ueberraschung:**
Die intuitive Loesung "Partial inkludiert sich selbst im foreach" ist
PHP-spezifisch broken:

```php
<?php foreach ($node['children'] as $child): ?>
    <?php
        $node = $child;        // ueberschreibt Container-$node
        $depth = $depth + 1;
        include __DIR__ . '/_task_tree_node.php';
    ?>
<?php endforeach; ?>
```

Nach dem include ist `$node` im Container-Scope ueberschrieben und die
foreach-Schleife sieht beim naechsten Iterations-Schritt den falschen
Wert. Das ist der klassische Scope-Leak von PHP-includes. Der Prompt-
Autor des Phase-3-Prompts hatte das selbst als "naked include ist
fragil, Render-Funktion waere sauberer" angemerkt.

**Loesung:**
Container (edit.php, show.php) definiert eine Closure
`$renderTaskNode = function(array $node, int $depth) use (&$renderTaskNode, ...): void`
mit `use(&$renderTaskNode)` fuer den Self-Call. Die Closure kapselt
den Scope — jeder Aufruf sieht seine eigenen `$node`/`$depth`-Bindings,
keine Leaks zum Container. Das Partial ruft im Kinder-Loop nur noch
`$renderTaskNode($child, $depth + 1)`, keinen weiteren include.

**Praevention:**
- Rules 05-frontend.md sollte einen Pattern-Absatz fuer rekursive
  Partials bekommen (Closure + use-by-reference, kein naked include).
- Invariants-Tests pruefen beide Partials auf das Muster (
  `test_task_tree_node_partial_uses_container_closure`,
  `test_task_tree_readonly_partial_uses_container_closure`), damit
  kuenftige Refactorings nicht zurueck ins naked-include kippen.
- Bei neuen rekursiven Partials (I7b2 Mitgliedersicht-Accordion, I7c
  Templates-Tree): gleiches Muster uebernehmen.

→ Partials `_task_tree_node.php`, `_task_tree_readonly.php` und die
  Container-Closures in `edit.php`, `show.php` als Referenz.

<!-- Neue Eintraege hier unten anfuegen, nicht oben. Append-Only. -->

---

### 2026-04-23 — Bestandsanalyse mit nur Grep uebersieht JS-gefuellte Partials

**Kontext:**
Modul 6 I7c Phase 2 — Architect-Plan nahm an, das JS bleibt im
Partial-Generalisierungs-Umbau unveraendert. Der Plan war "URLs kommen
per data-endpoint-*-Attribute aus dem Partial; JS ist endpoint-
agnostisch".

**Problem / Ueberraschung:**
Die Annahme war halb-richtig: die URLs kommen tatsaechlich aus data-
Attributen. Aber das `_task_edit_modal.php` ist nur ein Skelett-
Container — der Modal-Body (das Formular mit den Feldern!) wird erst
nach fetch() vom JS via DOM-APIs aufgebaut. Feldnamen wie `start_at`/
`end_at` vs. `default_offset_minutes_start/end` stehen nicht im
Partial, sondern hart im `buildForm()` des JS. Eine reine Partial-
Generalisierung ohne JS-Anpassung haette den Template-Kontext mit
den Event-Feldnamen serialisiert und das Backend haette die Inputs
verworfen.

Der Architect-Plan hat ausdruecklich "JS-Kern unveraendert, Endpoint-
Agnostik bestaetigt" als Deliverable-3-Erwartung genannt — das war
basierend auf einer Bestandsanalyse, die nur File-Inhalte las und
nicht die Laufzeit-Semantik pruefte.

**Loesung:**
JS-Kern wurde kontext-aware gemacht via
- `dataset.context` am Wrapper (mit Default `'event'`),
- `dataset.entityId` mit Fallback auf `dataset.eventId`,
- `parentIdField()`-Helfer fuer Form-Feldnamen,
- kontext-abhaengige Zeit-Felder in `buildForm()` und Default-Werte
  in `showCreateModal()`.

**Praevention:**
Bei Architect-Plaenen, die "JS greift nicht in die Aenderung rein"
annehmen, IMMER zusaetzlich pruefen: ruft der Partial nur das Modal-
Skelett, oder rendert er auch Inhalt? Wenn der Inhalt vom JS gebaut
wird (DOM-APIs, buildForm-Stil), ist die Partial-Generalisierung
unvollstaendig — das JS selbst muss kontext-aware werden.

→ Relevant fuer kuenftige UI-Generalisierungen (z.B. I7e Organisator-
  Editor): Bestandsanalyse muss Laufzeit-Rendering-Pfade einschliessen,
  nicht nur Dateiinhalte. Konkreter Check: fuer jedes betroffene
  Partial "rendert es Inhalt oder nur Container?" — bei Container
  Modal-JS pruefen.

---

### 2026-04-23 — Drei-Mode-View-Zustand statt Editor-vs-Legacy-Binaer

**Kontext:**
Modul 6 I7c Phase 2 — Template-Editor-Integration in edit.php. Die
Phase-2-Implementierung hatte nur zwei Zustaende: Tree-Editor (bei
Flag an und editierbarem Template) vs. flache Legacy-Liste
(sonst). Das schliesst "Template gesperrt, aber User will trotzdem
die Hierarchie sehen" als Fall mit ein — waehrend der G1-Plan einen
dedizierten Read-Only-Modus vorsah.

**Problem / Ueberraschung:**
Der binaere Editor-vs-Legacy-Switch hat einen Template-Admin, der
nach `deriveEvent` die Struktur seiner Vorlage nur noch ansehen
wollte, auf die flache Liste geworfen — die Baum-Information war
visuell weg, obwohl sie im Schema da ist. Der G1-Plan nannte das
explizit als vorzugsweise Variante ("Read-Only-Tree, damit Admin die
Struktur sieht, aber nicht editieren kann"); der Coder hatte den
Hinweis beim Schreiben uebersehen und kein expliziter G3-Check
hat es gefangen, weil G3 erst in Phase 4 kommt.

Der User entdeckte die Abweichung durch direkte Frage und loeste die
Nachbesserung Phase 2b aus.

**Loesung:**
Drei-Mode-Variable `$treeMode ∈ {'legacy', 'editor', 'readonly'}`:

- `'editor'` — Flag an, editierbar (isCurrent, kein hasDerivedEvents).
- `'readonly'` — Flag an, Lock greift. Hierarchische Ansicht via
  `_task_tree_readonly.php` + Info-Zeile "Als neue Version speichern".
- `'legacy'` — Flag aus oder Aggregator nicht verfuegbar.

`$treeEditorEnabled` bleibt als Abgeleitet-Bool fuer Rueckwaerts-Kompat.

**Praevention:**
- Bei View-Zustaenden, die mit Business-Rule-Locks zusammenhaengen,
  erst die Zustandsmaschine vollstaendig skizzieren (n Zustaende,
  Uebergaenge), dann implementieren. Binaer-Switches sind ein Red
  Flag, wenn mehr als zwei distinkte Zustaende existieren.
- G1-Plan-Hinweise zu Read-Only-Fallbacks sind bindend — im G3-Review
  explizit gegen den Plan pruefen, nicht nur gegen die Implementierung.
- Fuer aehnliche Faelle (I7e Organisator-Editor wird auch
  verschiedene Berechtigungs-Zustaende haben): Drei-Mode-Muster
  als Default-Denkweise uebernehmen.

→ Siehe `admin/event-templates/edit.php` als Referenz-Implementierung.

---

### 2026-04-23 — Playwright-"intercepts pointer events" ist Symptom, nicht Ursache

**Kontext:**
Modul 6 I7b6 — Mobile-Tree-Editor-Fix. Playwright meldete auf
mobile-se und mobile-14, dass beim Klick auf den Delete-Button
eines Tree-Nodes zwei verschiedene Elemente den Click abfingen:
`<div class="container">` und `<button data-action="add-child">`
(der Top-Level-Toolbar-Button).

**Problem / Ueberraschung:**
Die intuitive Diagnose "Toolbar-Button ueberlagert Delete-Button
per Stacking-Context" war falsch. Im CSS gab es keine sticky-,
fixed- oder z-index-Regel, die das erklaeren konnte. Der Bug-
Mechanismus lag in einer ganz anderen Ebene: Bootstrap-5 erbt
`white-space: nowrap` auf jede `.btn`-Klasse. Der Titel-Button
`.task-node__edit-trigger` mit `flex-grow-1` schrumpfte deswegen
nicht unter seinen Content-Anspruch. Zusammen mit den 44-px-
Touch-Targets der Action-Buttons wurde die Row breiter als
375 px, die ganze Seite horizontal scrollbar. Beim
`scrollIntoViewIfNeeded` scrollte Playwright horizontal, und
der Click-Punkt wanderte in die Toolbar-Region — die Toolbar
war der Interceptor, nicht die Ursache.

Der Full-Page-Screenshot (Playwright legt ihn beim Failure ab)
zeigte den Bug sofort: Tree-Nodes reichten von x=0 bis x=750
im 375-px-Viewport; der Delete-Button lag physisch jenseits
des rechten Viewport-Rands.

**Loesung:**
CSS-Fix ohne Partial-/JS-Aenderung:
- `.task-tree-editor .task-node__edit-trigger`:
  `min-width: 0; overflow: hidden; text-overflow: ellipsis;
   white-space: nowrap;`
  Titel schrumpft unter Flex-Min-Content, lange Titel werden
  mit "..." gekuerzt. Der volle Titel bleibt via title-Attribut
  am Button erreichbar.
- `.task-tree-editor { overflow-x: hidden; }` und
  `.task-tree-readonly { overflow-x: hidden; }` als Safety-Net
  gegen andere Overflow-Quellen (lange Badges,
  Beschreibungen).

**Praevention:**
Bei Playwright-Meldungen "X intercepts pointer events" der
Diagnose-Einstieg-Regel folgen:
1. **Zuerst den Full-Page-Screenshot pruefen** (Playwright legt
   ihn als `test-failed-1.png` im test-results-Ordner ab).
   Horizontaler Overflow ist dort sofort sichtbar — die Seite
   ist breiter als der Viewport, oder Elemente ragen ueber den
   rechten Rand hinaus.
2. Dann erst den DOM-Baum und Z-Index-Hierarchie ansehen.

Der gemeldete Interceptor ist das Ziel des horizontalen Scrolls
nach scrollIntoViewIfNeeded, nicht der Verursacher. DOM-Baum-
Analyse allein fuehrt in die Irre, wenn Overflow das Problem
ist.

→ Relevant fuer kuenftige Mobile-Layout-Bugs in Event-Editor,
  Template-Editor und spaeterem Organisator-Editor.

---

### 2026-04-23 — Bootstrap-5 .btn-Klasse erbt white-space: nowrap

**Kontext:**
Gleicher Kontext wie der vorherige Eintrag (I7b6). Der Titel-
Button im Tree-Node ist ein `<button class="btn btn-link
flex-grow-1 ...">`. Trotz `flex-grow-1` schrumpfte er nicht auf
schmalen Viewports — er erzwang seine volle Text-Breite und
brachte die Row ueber die Viewport-Breite hinaus.

**Problem / Ueberraschung:**
Bootstrap-5 setzt auf jede `.btn` und implizit auch auf `.btn-
link` `white-space: nowrap`. Das verhindert den Zeilenumbruch
im Button-Text — wuenschenswert fuer kompakte Action-Buttons,
aber problematisch fuer Buttons mit `flex-grow-1`, die laengen-
unabhaengig werden sollen.

Flex-Children haben per Default `min-width: auto`, was
effektiv "nicht unter min-content-Width schrumpfen" bedeutet.
Min-content-Width eines Buttons mit `white-space: nowrap` ist
die komplette Text-Breite. Die beiden Regeln kombinieren sich
zum Schrumpf-Verbot.

**Loesung:**
Fuer Buttons mit `flex-grow-1` im schmalen Flex-Layout:
```css
button.btn {
    min-width: 0;              /* bricht min-content-Schrumpf-Verbot */
    overflow: hidden;          /* clippt ueberlaufenden Text */
    text-overflow: ellipsis;   /* sichtbares "..." als Kuerzungshinweis */
    /* white-space: nowrap bleibt — sonst bricht der Text um,
       was mit Ellipsis nicht vertraeglich ist */
}
```
Fuer den vollen Text braucht der Button ein `title`-Attribut,
das bei Hover (Desktop) oder Long-Press (Mobile) den ganzen
Text zeigt.

**Praevention:**
- Bei Flex-Rows mit einem `flex-grow-1` Text-Element und
  mehreren festen Action-Elementen: `min-width: 0` + Ellipsis-
  Regel ist Default-Pattern. `.claude/rules/05-frontend.md`
  um einen Abschnitt "Schrumpfbare Text-Buttons in Flex-Rows"
  erweitern.
- Merkregel: "Wenn ein `.btn` in einem Flex-Row mit
  `flex-grow-1` steht und bei schmalem Viewport nicht
  schrumpft, ist es nie die Schuld vom Flex — es ist die
  Schuld von Bootstrap's `.btn { white-space: nowrap }`."

→ Siehe `src/public/css/app.css` `.task-tree-editor .task-
  node__edit-trigger`-Block als Referenz.

### 2026-04-24 — Invariants-Tests und Promoted Constructor Parameters

**Kontext:**
Phase 3 Teil 1 von I7e-A (PHPUnit-Invarianten fuer
`OrganizerEventEditController`). Der bewaehrte `methodBody()`-
Helper aus den I7b1/I7b4-Test-Klassen greift den Method-Rumpf
zwischen Signatur-`{` und der naechsten Method-Definition. Fuer
einen regulaeren Method-Body funktioniert das zuverlaessig.

**Problem / Ueberraschung:**
PHP-8-Constructor-Property-Promotion schreibt die Dependencies
in die Signatur, nicht in den Rumpf. Der eigentliche
Constructor-Body ist danach leer (oder nur Whitespace und
Kommentar). `methodBody($code, '__construct')` liefert einen
leeren String — und ein `assertStringContainsString('EventRepository', $body)`
schlaegt fehl, obwohl die Dependency korrekt injected ist. Im
Worst Case laufen saemtliche Dep-Checks ins Leere und liefern
still einen False-Positive.

**Loesung:**
Fuer Ctor-Dependency-Checks nicht `methodBody()` verwenden,
sondern das Promoted-Property-Pattern gegen den gesamten
File-Inhalt matchen:
```php
self::assertMatchesRegularExpression(
    '/private\s+' . preg_quote($type, '/') . '\s+\$\w+/',
    $code,
    "Konstruktor muss $type als Promoted-Property injecten."
);
```
Fuer normale Method-Bodies bleibt `methodBody()` korrekt.

**Praevention:**
- Neue Invariants-Tests prueffen: sobald ein Test gegen den
  Konstruktor-Rumpf laeuft, pruefen, ob die Klasse Ctor-Promotion
  nutzt. Falls ja, auf File-Regex umstellen.
- Merkregel: "Promoted Parameters leben in der Signatur. Wer
  sie testet, muss die Signatur sehen."

### 2026-04-24 — IDOR bei per-Task-Service-Methoden ohne Event-Scope

**Kontext:**
G4-Security-Review von I7e-A, Dimension 3. Der `TaskTreeService`
ist per G1-Entscheidung E1 bewusst auth-neutral; der Controller
ist fuer die Authorization zustaendig (`isOrganizer($eventId)`).
Die Service-Methoden `move`, `convertToGroup/Leaf`,
`softDeleteNode`, `updateNode` akzeptieren nur eine `taskId` —
der Event-Kontext geht dort verloren.

**Problem / Ueberraschung:**
User X ist Organisator von Event A und schickt
`POST /organizer/events/A/tasks/{B-task-id}/tree-delete`.
`isOrganizer(A)` greift korrekt und gibt `true`. Der Service
ruft `taskRepo->findById($taskId)` auf — und findet die Task
aus Event B. Loescht sie. **Kein Event-Scope-Check auf dem
Pfad.**

Die Luecke existierte seit I7b1 im Admin-Pfad, war dort aber
auf `event_admin`-Rolle begrenzt (limitierter Angreifer-Kreis).
I7e-A hat den Pfad unter `/organizer/events/...` ohne
RoleMiddleware freigegeben und damit den Angriffs-Kreis auf alle
Event-Organisatoren ausgeweitet.

**Loesung (Option B, minimal-invasiv):**
Controller-seitiger Cross-Check vor dem Service-Call, gespiegelt
aus dem schon in Phase 1 fuer `editTaskNode` etablierten Muster:
```php
$task = $this->taskRepo->findById($taskId);
if ($task === null || $task->getEventId() !== $eventId) {
    return $response->withStatus(404);
}
```
`404` statt `403` — verdeckt die Task-Existenz in einem fremden
Event. Drei Controller gefixt (Organizer/Admin/Template mit
`getTemplateId()`-Variante).

**Alternative (Option A) als Follow-up:**
Service-API um `int $eventId` / `int $templateId` erweitern,
Service wirft `AuthorizationException` bei Mismatch. Sauberer,
aber API-Break in fuenf Methoden. Fuer spaeter vorgemerkt.

**Praevention:**
- Statische Invarianten in drei Test-Dateien
  (`test_mutating_actions_check_task_belongs_to_event` /
  `..._template`) fangen zukuenftige mutierende Actions ab, die
  den Scope-Check vergessen.
- Merkregel: "Wenn der Service auf einer Domain-ID arbeitet
  (taskId, assignmentId, ...), aber die Authorization eine
  Parent-ID kennt (eventId, userId), muss **irgendwer** die
  beiden verknuepfen. Im auth-neutralen Service ist das der
  Controller."

### 2026-04-24 — Scope-Hygiene bei Browser-Smoke-Nachbesserungen

**Kontext:**
I7e-A Phase 2c war urspruenglich fuer Link-Ergaenzung und
Sidebar-Label-Bug-Fix geplant. Waehrend der Session kam nach
einem Smoke-Test der explizite Wunsch dazu, Expand/Collapse-All-
Buttons zu ergaenzen. Das wurde als "Phase 2c Teil 2" aufgenommen.

**Problem / Ueberraschung:**
Expand/Collapse-All ist inhaltlich ein **neues Feature**, nicht
eine Nachbesserung an bestehenden Features. Die zugrundeliegende
Annahme im erweiterten Prompt war zusaetzlich falsch (der
bestehende Tree-Editor hatte kein Per-Node-Collapse, der Prompt
nahm `<details>`/`<summary>` an). Daraus entstanden zwei
Scope-Fragen:
- Darf ein Feature unter dem Label "Phase 2c" wachsen?
- Wieviel zusaetzliche Funktionalitaet (Per-Node-Chevron,
  klassenbasierte Collapse-CSS, JS-Delegation) darf implizit
  mitkommen, damit das Feature sinnvoll ist?

**Loesung:**
- Neue Features bekommen einen eigenen Coder-Prompt, auch wenn
  sie klein sind. Prompt benennt die Abweichung vom Original-
  Scope explizit.
- Im Commit-Trailer muss die Erweiterung als solche markiert
  sein, damit der Architect-Final-Review sie klar zuordnen
  kann (statt sie als "noch zu Phase 2c gehoerig" durchwinken
  zu muessen).
- Wenn sich bei der Umsetzung Zwangs-Ergaenzungen ergeben
  (Per-Node-Chevron war noetig, damit "Alle einklappen" keine
  Sackgasse wird), sind auch die im Commit dokumentiert, mit
  Begruendung.

**Praevention:**
- Unterscheidung `fix` vs. `feat` im Commit-Type-Prefix
  diszipliniert fuehren: Nachbesserung == gleiche Features
  sauberer gemacht; Erweiterung == neue Features.
- Merkregel: "Wer waehrend der Session neue UI-Affordance
  ergaenzt, eroeffnet einen neuen Scope. Auch wenn sie
  verbunden aussieht, bekommt sie einen eigenen Commit."

### 2026-04-24 — Multi-Line-replace_all bei Trait-Extraktion

**Kontext:**
I7e-B.0.1 extrahierte acht Helper-Methoden plus den IDOR-Scope-
Check aus drei Tree-Controllern in Traits. Ein Teil der Umstellung
verlangte, dass bestehende Aufrufer-Zeilen auf die neue Trait-
Signatur umgezogen wurden (`int $eventId` → `string $redirectPath`).
Fuer den grossen Teil der Aufrufe hat `Edit(replace_all=true)` auf
das Pattern `$this->treeSuccessResponse($request, $response, $eventId,`
sauber gegriffen.

**Problem / Ueberraschung:**
In drei Actions (je `convertTaskNode`, plus `reorderTasks` und
einer Inline-Error-Response im Template-Controller) war der Aufruf
ueber mehrere Zeilen verteilt -- das replace-Pattern passt dann
nicht, weil der Zeilenumbruch zwischen den Argumenten liegt.
Die Stellen blieben stehen mit der **alten** Signatur, der
Composer-Autoloader meldete nichts (Syntax ist korrekt), und
PHPUnit blieb gruen, weil kein Unit-Test genau diesen Pfad
trifft. Erst ein Browser-Smoke in Phase-1-Vorbereitung zeigte
500-Fehler beim Klick auf "In Gruppe konvertieren".

**Loesung:**
Nach replace_all-Operationen auf mehrzeiligen Patterns immer
einen Grep auf die **alte** Signatur als Kontroll-Lauf machen:
`grep -rn "treeSuccessResponse(\$request, \$response, \$eventId"
src/app/Controllers/` muss danach leer sein. Zusaetzlich bei
Trait-Extraktionen: kurzer Browser-Smoke-Durchlauf aller Actions,
die den umgestellten Helper nutzen, vor dem Commit.

**Praevention:**
- Multi-Line-Pattern-Ersetzungen IMMER mit Grep-Gegenprobe auf
  alte Signatur absichern.
- PHPUnit faengt `ArgumentCountError` / `TypeError` nur dann ab,
  wenn der betroffene Code-Pfad im Test tatsaechlich durchlaufen
  wird. Bei Controller-Actions ist das selten.
- Nach Trait-Extraktion: `composer dump-autoload -o` plus kurzer
  Dev-Server-Smoke in 2-3 der geaenderten Actions.

### 2026-04-24 — Repo-bool + Service-Exception als VAES-Lock-Pattern

**Kontext:**
Modul 7 I3 (events-Tabelle) hatte Optimistic Locking mit einem
bool-Rueckgabewert aus `EventRepository::update` eingefuehrt --
der Controller dort pruefte `if (!$ok) { 409 }` selbst. In Modul
6 I7e-B musste der gleiche Mechanismus auf `event_tasks`
implementiert werden, aber dort sitzt ein Service
(`TaskTreeService`) zwischen Controller und Repository. Der
Service wirft bereits typisierte Exceptions fuer andere
Fehlerklassen (`ValidationException`, `BusinessRuleException`).

**Problem / Ueberraschung:**
Ein `bool`-Rueckgabewert vom Service an den Controller waere
inkonsistent zum Service-Exception-Stil. Andererseits wuerde
ein Exception-Wurf aus dem Repository das Modul-7-Muster
brechen und fuer bestehende Aufrufer (die den Code-Pfad nur
als "Update hat geklappt / nicht" kennen) ein Upgrade-Risiko
bedeuten.

**Loesung (Architect-Entscheidung I7e-B G1-B2b):**
Asymmetrisches Pattern:
- **Repository** gibt weiterhin `bool` zurueck. Konsistent zu
  Modul 7 I3. Kein Wissen ueber "Konflikt vs. Row-Missing" --
  der Repo-Aufruf sieht nur `rowCount`.
- **Service** faengt `$ok === false` ab und wirft bei
  nicht-null `$expectedVersion` eine `OptimisticLockException`.
  Konsistent zum Service-Exception-Stil.
- **Controller** faengt die Exception und rendert 409 via
  `lockConflictResponse` (Trait-Helper).

**Regel als VAES-Konvention:**
Bei zukuenftigen Lock-Erweiterungen (Template-Lock,
assignments-Lock):
- Repository immer `bool`.
- Wenn Service dazwischen liegt: Service wirft Exception.
- Wenn kein Service dazwischen liegt (direkter Controller/Repo-
  Pfad wie Modul 7 I3): Controller entscheidet selbst ueber 409
  anhand `bool`.

### 2026-04-24 — errorCode-Feld als 409-Discriminator im Tree-Editor

**Kontext:**
Mit Einfuehrung des Optimistic Locks auf event_tasks (I7e-B.1
Phase 2) haben die mutierenden Tree-Actions zwei semantisch
verschiedene 409-Rueckgaben:
- `BusinessRuleException` (z.B. "Task hat noch aktive Zusagen,
  Loeschung nicht moeglich"). UX: Fehlermeldung zeigen, Nutzer
  soll Aenderung ueberdenken.
- `OptimisticLockException` (Version-Mismatch nach paralleler
  Bearbeitung). UX: Warn-Toast plus automatischer Reload, weil
  der Nutzer mit einem veralteten Stand arbeitet.

Beide Faelle teilen HTTP-Status 409.

**Problem / Ueberraschung:**
Der erste Wurf des JS-Handlers pruefte `result.status === 409`
und konnte nicht unterscheiden. Folge: bei BusinessRule-Konflikt
reloadete die Seite ebenfalls (unerwuenscht), oder bei
Optimistic-Lock-Konflikt blieb der Nutzer im veralteten Modal
(ebenfalls unerwuenscht).

**Loesung:**
JSON-Response-Shape um ein **semantisches** Feld `error`
erweitert. `lockConflictResponse` setzt
`error: 'optimistic_lock_conflict'`; andere 409-Faelle setzen
es nicht. Der `postJson`-Wrapper im JS exponiert das Feld als
`result.errorCode`. Die vier Mutation-Handler (Save, Move,
Convert, Delete) pruefen `result.errorCode === 'optimistic_lock_conflict'`
vor dem Reload -- andere 409-Faelle fallen durch zu
`showFormErrors` bzw. Error-Toast.

**Regel:**
- HTTP-Status allein fuer Machine-to-Machine-Reaktion (Cache,
  Proxy, Retry-Logik).
- Fuer UX-Differenzierung **innerhalb** desselben Status-Codes
  einen semantischen Discriminator im Body platzieren.
- VAES-Konvention: `error`-Feld als Kategorie-Key, `message` als
  menschenlesbarer Text. Client-JS liest `error` als
  `errorCode`, um den Namenskonflikt mit dem Bestand-Feld
  `errors` (Validation-Fehler-Liste) zu vermeiden.

### 2026-04-24 — sendBeacon mit URL-encoded Form-Body als VAES-Konvention fuer beforeunload-Close

**Kontext:**
beforeunload-Handler muessen Best-Effort-Close-Requests
absetzen, ohne auf eine Response zu warten. Synchrones XHR ist
in modernen Browsern deprecated; navigator.sendBeacon ist die
Empfehlung. Bei VAES gibt es zwei Anwendungsfaelle: das
bestehende `entry-lock.js` (Modul 7 I1, Bearbeitungssperren) und
das neue `edit-session.js` (Modul 6 I7e-C.1, Edit-Session-
Hinweis).

**Problem:**
`navigator.sendBeacon` unterstuetzt kein direktes Setzen von
HTTP-Headern. Der Standard-fetch-Header-Mechanismus
(`X-CSRF-Token`) ist damit nicht nutzbar. Naive Loesung:
JSON-Blob mit `csrf_token` im Body — aber das verlangt vom
Server, JSON-Body fuer CSRF-Pruefung zu parsen und einen
JSON-Pfad in der CsrfMiddleware.

**Loesung:**
Pattern aus `entry-lock.js` als VAES-Konvention etabliert:
- Body-Format `application/x-www-form-urlencoded`.
- `URLSearchParams` baut den Body, `csrf_token` als Form-Field.
- `Blob` mit Content-Type aus URLSearchParams.
- Server-seitig parst `addBodyParsingMiddleware` form-encoded
  automatisch, `CsrfMiddleware::process` liest
  `$body['csrf_token']` aus `getParsedBody()`.

**Praevention:**
Diese VAES-Konvention gilt fuer alle kuenftigen
beforeunload/pagehide-Close-Patterns. Wenn `sendBeacon` nicht
verfuegbar ist (alte Browser): `keepalive: true`-fetch als
Fallback, der dann die normalen JSON-/Header-Pfade nutzt. Der
2-Minuten-Server-Timeout (Edit-Session) bzw. 5-Minuten-Lock-
Timeout (entry-lock) faengt den Fall ab, dass weder sendBeacon
noch keepalive-fetch ankommen.

### 2026-04-24 — sessionStorage vs. localStorage fuer tab-lokale Survivor-State

**Kontext:**
Der Optimistic-Lock-Reload aus I7e-B (`window.location.reload()`
nach 409) wirft eine laufende JavaScript-Session weg, wenn sie
nur im Modul-State lebt. Edit-Session-Tracking aus I7e-C.1 muss
ueber Reloads hinweg dieselbe Session-ID weiterverwenden, damit
der Heartbeat-Pfad weiterlaeuft und keine neue DB-Zeile
entsteht.

**Problem:**
`localStorage` ueberlebt Reloads, ist aber browser-weit geteilt
— zwei Tabs desselben Users wuerden dieselbe Session-ID sehen.
Das bricht die Multi-Device/Multi-Tab-Semantik aus G1 (R2: jede
Browser-Session bekommt eine eigene `browser_session_id`,
Anzeige dedupliziert per `user_id`).

**Loesung:**
`sessionStorage` ist tab-skopiert:
- Ueberlebt Reloads im selben Tab (gleiches Window, gleicher
  Browser-Process).
- Ist eigenstaendig pro Tab — kein Cross-Tab-Leak.
- Wird beim Schliessen des Tabs geloescht (kein Data-Retention-
  Problem, DSGVO-freundlich).

Server-seitig deduziert die Anzeige-Logik
(`EditSessionView::toJsonReadyArray`) per `user_id` — zwei Tabs
desselben Users erscheinen nur einmal im UI, obwohl in der DB
zwei `edit_sessions`-Zeilen mit verschiedenen
`browser_session_id`-Werten liegen.

**Praevention:**
- Fuer **tab-lokalen** State, der Reloads ueberleben soll:
  `sessionStorage`.
- Fuer **geraete-weiten** State (z.B. UI-Theme): `localStorage`.
- Fuer **Server-seitiges Tracking** von Multi-Tab-Nutzung: eigene
  `browser_session_id`-Spalte in der Tabelle, JavaScript baut
  einen client-zufaelligen Identifier.

### 2026-04-24 — Per-Test-Timeout fuer Polling-Tests in Playwright

**Kontext:**
Spec 17 (Edit-Session-Hinweis) testet ein Polling-Feature mit
30-Sekunden-Heartbeat-/Refresh-Intervall (`HEARTBEAT_INTERVAL_MS`
in `edit-session.js`). Ein Cross-User-Sichtbarkeits-Test braucht
einen vollen Polling-Tick — die assertion ist
`expect(...).toHaveCount(1, { timeout: 35_000 })`.

**Problem:**
Playwright's per-test-Default-Timeout ist 30 Sekunden. Die
35-Sekunden-Wartezeit wird also **nie** evaluiert — Playwright
killt den Test vor der Assertion. Die Fehlermeldung lautet
"Test timeout of 30000ms exceeded", was den Code-Pfad auf den
Wait verweist — aber die eigentliche Ursache ist die zu enge
Outer-Timeout-Grenze.

**Loesung:**
Per-Test-Setup: `test.setTimeout(60_000)` als erste Anweisung
im Test-Body fuer Tests, die Polling-Wartezeiten enthalten.

Zusatzlich: `await page.waitForLoadState('networkidle')`
funktioniert nicht zuverlaessig bei Polling-Features — wenn der
Browser permanent Background-Heartbeats absetzt, wird "idle"
nie erreicht. Stattdessen deterministische `await page
.waitForTimeout(2000)` verwenden, die das JS-Boot-Intervall
ueberbrueckt, ohne auf network-idle zu warten.

**Praevention:**
Kuenftige Polling-Feature-Specs (z.B. Dashboard-Live-
Aktualisierung, neue Real-Time-Hinweise) brauchen die gleiche
Test-Infrastruktur:
- `test.setTimeout(60_000)` fuer alle Tests, die mehr als
  30 Sekunden Polling-Wait erwarten.
- Kein `networkidle`-Wait. Stattdessen entweder ein
  deterministischer `waitForTimeout` oder ein DOM-Signal-Wait
  (`expect(...).toBeVisible({ timeout: ... })`).

Als Kommentar im Test-Header die Begruendung notieren, damit
spaetere Editoren die Wartezeiten nicht fragen ("warum 60
statt 30?").

