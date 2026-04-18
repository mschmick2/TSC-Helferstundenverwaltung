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

<!-- Neue Eintraege hier unten anfuegen, nicht oben. Append-Only. -->
