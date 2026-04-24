# VAES Follow-up-Register

Zentrale Liste offener Follow-ups. Eintraege werden hier aufgenommen, wenn
ein Gate einen Befund findet, dessen Fix den Scope des aktuellen Inkrements
sprengen wuerde. Nach Abschluss eines Follow-ups wird der Eintrag entfernt
(nicht als erledigt markiert, um die Liste schlank zu halten).

Format pro Eintrag:

- **ID** (fortlaufender Buchstabe, global).
- **Quelle**: in welchem Inkrement / welchem Gate der Befund entstand.
- **Status**: offen / in Arbeit.
- **Beschreibung**, **Naechster Schritt**, **Groessenordnung**.

---

## Offene Follow-ups nach I7e-A (Modul 6, abgeschlossen 2026-04-24)

### Follow-up v — Audit-Log bei fehlgeschlagenen Authorization-Zugriffen

- **Quelle:** I7e-A G4 Security-Review, Dimension 7.
- **Status:** offen, systemisch.

**Beschreibung:**
403-Antworten aus `isOrganizer`-Gate und `assertEventEditPermission`
werden nicht auditiert. IDOR-Probing bleibt damit spurlos — ein
Angreifer, der sich durch Task-IDs durchrate, hinterlaesst im
Audit-Log keinen Trail.

**Naechster Schritt:**
`auditService->log('access_denied', ...)` im Guard-Exit-Zweig jeder
Auth-Middleware bzw. jedes Controller-Guards. Systemisch, betrifft
alle Controller mit Auth-Gates — nicht nur Tree-Editor.
Gehoert in ein gebuendeltes Security-Inkrement, NICHT in I7e-B/C.
Neuer `action`-Wert im `audit_log`-Enum erforderlich
(Schema-Migration).

**Groessenordnung:** 0.5-1 Session.

### Follow-up w — Accessibility-Audit Tree-Editor

- **Quelle:** I7e-A Security-Fix (Commit 3, `aria-expanded`-Beobachtung).
- **Status:** offen, neu.

**Beschreibung:**
Der Tree-Editor nutzt klassenbasierte Collapse-Darstellung
(`.task-node--collapsed`). `aria-expanded`, `aria-label` und
Keyboard-Navigation sind nicht einheitlich gesetzt. Screen-Reader-
Erlebnis unbewertet.

**Naechster Schritt:**
Dediziertes Accessibility-Inkrement (A11y-Review) fuer den gesamten
Tree-Editor-Komplex (I7b1 + I7c + I7e-A). Deckungsbereich:
`aria-*`-Attribute, Keyboard-Handler, Focus-Management, Screen-Reader-
Flow. Ggf. mit echtem Screenreader-Test (NVDA oder VoiceOver).

**Groessenordnung:** 2-3 Sessions.

### Follow-up D4 — Playwright D&D-Test auf eingeklappten Gruppen

- **Quelle:** Spec 15 (Test D4 als Skip markiert).
- **Status:** offen, niedrige Prioritaet.

**Beschreibung:**
Drag-and-Drop-Test auf einem eingeklappten Gruppen-Knoten ist in
Spec 15 als Skip markiert, analog zu Spec 10 (I7b1). Die Playwright-
Mouse-Simulation fuer SortableJS ist mehrfach als flaky erfahren
worden.

**Naechster Schritt:**
Nachdem die Spec-10-D&D-Tests als stabile Baseline etabliert sind,
das Pattern auf Spec 15 uebertragen.

**Groessenordnung:** 0.5 Session.

---

## Offene Follow-ups nach I7e-B (Modul 6, abgeschlossen 2026-04-24)

Aus dem Sanity-Gate und dem G4-Security-Review von I7e-B.1 ergaben
sich fuenf neue Eintraege. Der Architect empfiehlt in G8 drei
Buendelungen:

- ~~**Doku-Buendel** (Follow-ups o + p)~~ -- abgeschlossen als
  Commit `docs(locking): I7e-B Follow-up-Buendel 1` (Docblock von
  `EventTaskRepository::update` und der fuenf mutierenden
  `TaskTreeService`-Methoden aktualisiert).
- ~~**Test-Breite-Buendel** (Follow-ups q + r)~~ -- abgeschlossen
  als Commit `test(locking): I7e-B Follow-up-Buendel 2` (Spec 16
  um Organizer-Multi-Context-Test erweitert; neuer statischer
  Invariants-Test fuer Feature-Flag-Reihenfolge in
  `OptimisticLockInvariantsGapTest`).
- **Systemisch** (Follow-up s): gehoert zu einem breiter angelegten
  Security-Inkrement zusammen mit Follow-up v, keine eigene Mini-
  Iteration.

### Follow-up s — Rate-Limit auf mutierende Tree-Actions

- **Quelle:** G4 Security-Review I7e-B (Dimension 2).
- **Status:** offen, systemisch (nicht Lock-spezifisch).

**Beschreibung:**
Die mutierenden Tree-Actions (Create, Update, Move, Convert,
Delete, Reorder) sind nicht rate-limitiert. Das ist Bestand-
Verhalten -- nur Login- und Forgot-Password-Routen sind durch
`RateLimitService` geschuetzt. Im Kontext von I7e-B.1 als
Beobachtung aufgenommen; kein Lock-Feature-spezifisches Problem,
aber thematisch eng mit Follow-up v (Audit bei 403) verbunden.

**Naechster Schritt:**
Gemeinsam mit Follow-up v in einem breiter angelegten Security-
Inkrement ("Audit + Rate-Limit-Breite"): Generische Rate-Limit-
Middleware fuer mutierende Admin/Organizer-Routen, IP-basiert,
mit konfigurierbarem Budget pro Nutzer + IP.

**Groessenordnung:** 1-2 Sessions, gemeinsam mit Follow-up v.
Keine Mini-Iteration.

---

## Offene Follow-ups nach I7e-C.1 (Modul 6, abgeschlossen 2026-04-24)

Aus Sanity-Gate und G4-Security-Review I7e-C.1 ergaben sich vier
neue Eintraege. Zwei davon (FU-G5-1 DSGVO-Nachweis und FU-G7-1
Deploy-Reihenfolge) sind G9-Pflicht-Eintraege und mit dem
G9-Commit dieses Inkrements direkt erledigt — sie sind hier nur
zur Historie nochmal genannt und nicht mehr offen. Die zwei
verbleibenden offenen Eintraege empfiehlt der Architect als
gebuendelte Mini-Iteration nach dem Tag.

- ~~**FU-G5-1**~~ -- erledigt im G9-Commit (PII-Flow in
  `docs/DSGVO_und_Security_Nachweis.md` ergaenzt).
- ~~**FU-G7-1**~~ -- erledigt im G9-Commit (Deploy-Reihenfolge im
  Benutzerhandbuch-Abschnitt "Hinweis auf parallele Editoren"
  unter "Verfuegbarkeit").
- ~~**Follow-up t**~~ -- erledigt in Commit `test(e2e): I7e-C
  Nach-Tag Test-Breite` als Spec 17 Test 5. Dabei ist eine
  Design-Luecke zu Architect-C1 aufgefallen (Follow-up z,
  inzwischen ebenfalls erledigt).
- ~~**Follow-up z**~~ -- erledigt in Commit `fix(edit-sessions):
  Follow-up z - beforeunload respektiert programmatischen
  Reload (C1)`. sessionStorage-Flag-Pattern zwischen
  event-task-tree.js und edit-session.js; Spec 17 Test 5
  strenger gefasst (`toBe(sessionIdBefore)`); zwei statische
  Invariants als Drift-Schutz ergaenzt.

### ~~Follow-up t -- Lock-Reload-E2E-Test in Spec 17~~ (erledigt)

Spec 17 Test 5 "Lock-Reload aus I7e-B: Edit-Session bleibt
funktional" laeuft. Deckt die User-sichtbare Invariante
(Session-Tracking bleibt nach Lock-Reload funktional) ab. Die
strikte C1-Intent-Invariante (gleiche Session-ID ueberlebt den
Reload) ist nicht erreichbar -- siehe Follow-up z.

### ~~Follow-up z -- Design-Fix fuer Architect-C1~~ (erledigt)

Erledigt in Commit `fix(edit-sessions): Follow-up z - beforeunload
respektiert programmatischen Reload (C1)`. Umgesetzt wurde
Variante A3 (sessionStorage-Flag statt Modul-Variable): sowohl
`event-task-tree.js::handleLockConflict` als auch
`edit-session.js::closeSessionBestEffort` nutzen
`sessionStorage['vaes_programmatic_reload']` als Koordinations-
Signal. Das Flag wird von `handleLockConflict` gesetzt und von
`closeSessionBestEffort` beim ersten Lesen konsumiert (self-
cleanup). Spec 17 Test 5 ist jetzt auf die strenge C1-Invariante
`toBe(sessionIdBefore)` umgestellt, zwei statische Invariants
schuetzen gegen Drift (in `EditSessionJsInvariantsTest` und
`OptimisticLockInvariantsGapTest`).

### Follow-up u -- Repository-Integration-Tests fuer EditSessionRepository

- **Quelle:** Sanity-Gate I7e-C.1 (G6-Tester-Befund).
- **Status:** offen, optional.

**Beschreibung:**
Reine Unit-Suite hat keine Repository-Schreib-Tests gegen Test-DB.
SQL-Pattern (JOIN auf `users`, Lazy-Cleanup-DELETE, IDOR-Filter im
UPDATE-WHERE) sind nur statisch ueber die SQL-Strings im
`EditSessionRepository.php`-File abgesichert. Spec 17 deckt das
End-zu-End ab, aber langsamer und weniger gezielt.

**Naechster Schritt:**
Optional: `EditSessionRepositoryIntegrationTest` mit echter
Test-DB. Sinnvoll erst, wenn andere I7e-Repos (z.B.
`EventTaskRepository` aus I7e-B) ebenfalls dahin migrieren.

**Groessenordnung:** ca. 1 Stunde, gebuendelt mit FU-t als
Test-Buendel.

### I7e-C.2 Template-Edit-Sessions — deferred (2026-04-24)

**Status:** verworfen fuer jetzt, als Follow-up dokumentiert.

**Analyse (G1-Runde-1 Architect-Plan I7e-C.2):**
Template-Parallel-Bearbeitung ist in der aktuellen Nutzung sehr
selten:
- TSC Mondial hat 1-3 Event-Admins.
- Templates sind Vorlagen fuer wiederkehrende Event-Typen, die
  einmal aufgebaut und dann ueber Monate unveraendert abgeleitet
  werden.
- Keine Template-Organizer-Rolle -- nur `event_admin` und
  `administrator` duerfen ueberhaupt bearbeiten.

**Aufwandsschaetzung fuer volle Umsetzung:** 4-4,5 Sessions
(Migration auf target_type-Generalisierung von `edit_sessions`,
Controller-Refactor, Frontend-Partial-Generalisierung, neue
Playwright-Spec 18, Gate-Pipeline Sanity+G4+G8+G9, Tag
`v1.4.10-local-i7e-c2`).

**Entscheidung:** Kosten-Nutzen nicht gerechtfertigt bei
aktueller Nutzungsfrequenz. I7e-C.1 deckt den ueblichen
Koordinations-Fall (Event-Bearbeitung) vollstaendig ab.

**Re-Evaluation empfohlen, wenn:**
- Die Event-Admin-Zahl signifikant waechst (z.B. > 5 regelmaessig
  aktive Admins).
- Templates haeufiger gepflegt werden (z.B. monatliche
  Anpassungen an Saisondaten).
- Oder andere Target-Typen (z.B. Einstellungs-Editor) eingefuehrt
  werden und die target_type-Generalisierung der `edit_sessions`-
  Tabelle dann ohnehin Sinn macht.

**Architect-Plan-Ausarbeitung** (G1-Runde-1 inkl. K1b/K2b/K3b-
Bestaetigungen, fuenf C-Korrekturen C6-C10, zwoelf Q-Klaerungen,
Risiko-Matrix R1-R8 und Vier-Phasen-Plan) steht im Session-
Verlauf vom 2026-04-24 bereit, falls Re-Evaluation zu "umsetzen"
fuehrt.

---

## Follow-ups aus Modul 6 I8 (Tag v1.4.10-local-i8, 2026-04-24)

Modul 6 I8 (Audit bei Authorization-Denial + Rate-Limit) hatte vier
Commits auf `feature/i8-audit-rate-limit`: `a2f4695` (Phase 1),
`d085676` (Phase 2), `1ffc064` (FU-3 Docblock), `eab1c19` (G4-ROT-Fix).
Daraus ergaben sich insgesamt zwoelf Follow-ups — acht aus dem
Sanity-Check-Gate (FU-1..FU-8) und vier aus dem G4-Security-Review
(FU-I8-G4-1..-4 plus der als ROT klassifizierte FU-I8-G4-0).

### Erledigt im Inkrement (durchgestrichen)

- ~~**FU-3** (Sanity G5 DSGVO)~~ — Metadata-PII-Konvention auf
  `AuthorizationException::__construct` dokumentiert, Invariant-Test
  `AuthorizationExceptionDocblockTest`. Siehe Commit `1ffc064`.
- ~~**FU-I8-G4-0** (G4 ROT, Tag-Blocker)~~ — 16 Controller-catch-Stellen
  riefen ihn zuvor nicht auf. Neuer Helper
  `BaseController::handleAuthorizationDenial` + statischer
  Bootstrap-Setter, Invariants-Tests `AuthorizationCatchBlockInvariants`.
  Siehe Commit `eab1c19`.
- ~~**FU-I8-G4-3** (G4 UX)~~ — Heartbeat-Rate-Limit-Default von 4/min
  auf 8/min angehoben (4x statt 2x Puffer bei 2/min Normal-Polling,
  Flaky-Reduktion bei Netzwerk-Hiccups). Siehe Commit `eab1c19` +
  Migration 011 im G9-Commit.
- ~~**FU-4** (Sanity G5 DSGVO, Pre-Tag)~~ — `docs/DSGVO_und_Security_Nachweis.md`
  und `.claude/rules/07-audit.md` um den 13. ENUM-Wert `access_denied`
  und die sechs neuen Settings-Keys ergaenzt. Siehe G9-Commit.
- ~~**FU-6** (Sanity G7 Integrator, Pre-Tag)~~ — Playwright-Specs 16
  (5/5 gruen) und 17 (5/5 gruen im Re-Run; erster kombinierter Lauf
  hatte einen Timeout-Flake in 17.160 bei 35 s Timeout und 30 s
  Polling-Intervall, keine I8-Regression). G9 dokumentiert.
- ~~**FU-7** (Sanity G7 Integrator, Pre-Tag)~~ — Migration 011 auf Dev-DB
  ausgefuehrt: 13 ENUM-Werte, sechs Settings-Zeilen, Heartbeat-Default
  8/min verifiziert. Rollback-Test in 011.down.sql zusaetzlich um
  einen SIGNAL-Guard erweitert, weil der MySQL-Default-SQL-Mode den
  ENUM-Rollback klaglos akzeptiert und `access_denied`-Zeilen
  stillschweigend auf Leerstring setzt — der neue Guard bricht das
  jetzt hart ab.
- ~~**FU-8** (Sanity G7 Integrator, Pre-Tag)~~ — Deploy-Reihenfolge fuer
  Strato ("Migration 011 vor App-Update") und der verlustsichere
  Rollback-Pfad (App zurueck → DELETE/UPDATE der `access_denied`-
  Zeilen → 011.down.sql) im Benutzerhandbuch ergaenzt.

### Offen — Post-Tag-Bundles

**Bundle A — Audit-Hardening (~1 Session):**

- **FU-I8-G4-1** (G4 Dim 5): Deduplication fuer
  `AuditService::logAccessDenied` bei Rate-Limit-Dauerblock. Aktueller
  Code schreibt bei jedem blockierten Request einen neuen
  Audit-Eintrag; ein Angreifer mit validem Session-Cookie kann damit
  die `audit_log`-Tabelle fluten. Mitigation: "pro (user_id, bucket,
  window) hoechstens ein `access_denied`-Eintrag" — braucht entweder
  einen Tracker (Memcache oder DB-Spalte) oder eine zusaetzliche
  Zeit-Guard-Abfrage vor dem Insert.
- **FU-I8-G4-4** (G4 Dim 9+10): IP-basierter Rate-Limit-Bucket fuer
  CSRF-Failures. Aktuell sind CSRF-Fehler (`action='access_denied',
  reason='csrf_invalid'`) nicht rate-limitiert; ein Angreifer mit
  Session-Cookie (aber ohne CSRF-Token) kann beliebig viele
  POST-Requests und damit Audit-Eintraege generieren. Empfohlen:
  neuer Bucket `csrf_failure` mit 20/min/IP-Grenze, gelesen durch
  `CsrfMiddleware` (die bereits `RateLimitService` kennt).

**Bundle B — Code-Hygiene (~0.5 Session):**

- **FU-1** (Sanity G3 Reviewer): Konstanten fuer die drei
  RateLimit-Bucket-Keys (`tree_action`, `edit_session_heartbeat`,
  `edit_session_other`) einfuehren — aktuell String-Literale in
  `dependencies.php` und `routes.php`, Tippfehler-Risiko bei Drift.
- **FU-2** (Sanity G3 Reviewer): `RateLimitService` dedupen — die drei
  10%-Cleanup-Bloecke und die nahezu identischen SELECT-Strukturen in
  `isAllowed / isAllowedForEmail / isAllowedForUser` bieten sich fuer
  einen privaten Helper an. Reines Refactor ohne Verhaltensaenderung.

**Bundle C — DB-Hardening (~15 Min):**

- **FU-I8-G4-2** (G4 Dim 6): Migration 012 mit
  `CREATE INDEX idx_rate_limits_attempted_at ON rate_limits
  (attempted_at)`. Der Lazy-Cleanup-DELETE macht aktuell einen
  Full-Table-Scan, weil beide Composite-Indizes `attempted_at` erst
  an dritter Position haben. Bei TSC-Scale harmlos, unter Flood
  teuer.

**Einzeln:**

- **FU-5** (Sanity G6 Tester): MySQL-Integration-Test fuer
  `RateLimitService::isAllowedForUser` Count-Semantik. Das Verhalten
  ist aktuell nur statisch per Regex-Invariants auf das SQL-Pattern
  abgesichert (SQLite kann `DATE_SUB(NOW(), INTERVAL :p SECOND)` nicht
  parsen). Gehoert in die geplante Modul-8-Integration-Suite.

---

## Bearbeitungsregel

Wenn ein Inkrement-Coder beim Pre-Flight einen der obigen Follow-ups
adressiert, traegt er den Abschluss in den Commit-Trailer ein und
entfernt den Eintrag hier. Neu entdeckte Follow-ups werden als
`Follow-up <naechster-Buchstabe>` angehaengt.
