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

## Offene Follow-ups nach I8 Sanity-Gate (Modul 6, 2026-04-24)

Aus dem Sanity-Check-Gate zu den I8-Phasen 1+2 (Commits `a2f4695`
und `d085676`) ergaben sich acht Follow-ups (FU-1 bis FU-8). Die
komplette Sammlung wird mit dem I8-G9-Dokumentar-Commit ins Register
uebernommen. Bereits vorab erledigt ist FU-3, weil der Scope
(Docblock-Konvention + Invariant-Test) klein genug fuer einen
Micro-Commit war:

- ~~**FU-3** (G5 DSGVO)~~ -- erledigt. Metadata-PII-Konvention fuer
  `AuthorizationException`-Werfer im `__construct`-Docblock
  kodifiziert (keine E-Mail-Adressen, Namen, IP-Adressen,
  Request-Body-Inhalte in `$metadata`, weil der Slim-ErrorHandler
  das Array ungefiltert ins `audit_log` weiterreicht).
  Statischer Invariant-Test `AuthorizationExceptionDocblockTest`
  schuetzt vor einem spaeteren Ausduennen des Hinweises.
  Siehe Commit `docs(exceptions): FU-3 - Metadata-PII-Hinweis auf
  AuthorizationException`.

---

## Bearbeitungsregel

Wenn ein Inkrement-Coder beim Pre-Flight einen der obigen Follow-ups
adressiert, traegt er den Abschluss in den Commit-Trailer ein und
entfernt den Eintrag hier. Neu entdeckte Follow-ups werden als
`Follow-up <naechster-Buchstabe>` angehaengt.
