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

- **Doku-Buendel** (Follow-ups o + p): kombinierter `docs(repo)`-
  Commit, ca. 30 Min.
- **Test-Breite-Buendel** (Follow-ups q + r): kombinierter
  `test(locks)`-Commit, ca. 1 Stunde.
- **Systemisch** (Follow-up s): gehoert zu einem breiter angelegten
  Security-Inkrement zusammen mit Follow-up v, keine eigene Mini-
  Iteration.

### Follow-up o — Docblock-Update EventTaskRepository::update()

- **Quelle:** Sanity-Gate I7e-B (G3-Reviewer-Befund).
- **Status:** offen, kosmetisch.

**Beschreibung:**
Der Docblock von `EventTaskRepository::update()` (aktuell Zeilen
286-292) traegt noch den Satz "Eine Version-Pruefung (Optimistic
Lock) gibt es derzeit nicht ... Parameter ist fuer spaetere
Aktivierung reserviert." Das stammt aus Modul 7 I3 und ist seit
I7e-B.1 Phase 1 falsch -- der Parameter ist aktiv.

**Naechster Schritt:**
Docblock an den aktuellen Stand angleichen: "Bei gesetztem
`$expectedVersion` wird das UPDATE per `AND version = :version`
auf die erwartete Version gefiltert. Bei Mismatch liefert die
Methode `false` zurueck; der Service uebersetzt das in eine
`OptimisticLockException`."

**Groessenordnung:** Einzeiler, ca. 5 Min. Sollte mit Follow-up
p gebuendelt werden.

### Follow-up p — Docblock-Erweiterung Lock-Semantik

- **Quelle:** G4 Security-Review I7e-B (Dimension 9).
- **Status:** offen, Doku.

**Beschreibung:**
Im G4-Review aufgefallen: Der Parameter `?int $expectedVersion = null`
ist **optional** -- ein fehlender Wert deaktiviert den Lock (last-
write-wins). Das ist by-design, damit Legacy-Aufrufer ohne Lock
weiterfunktionieren, aber in keinem Docblock explizit erwaehnt.
Ein kuenftiger Endpunkt-Autor koennte annehmen, der Lock sei
automatisch aktiv, sobald die Signatur ihn enthaelt.

**Naechster Schritt:**
In den Docblocks von `TaskTreeService` (5 mutierende Methoden)
und `EventTaskRepository` (5 mutierende Methoden) den Satz
ergaenzen: "Ein fehlender `$expectedVersion` bedeutet last-write-
wins; Authorisierung bleibt in den Controller-Guards."

**Groessenordnung:** ca. 20 Min. Gemeinsam mit Follow-up o als
einen `docs(repo)`-Commit.

### Follow-up q — Organizer-Multi-Context-Test in Spec 16

- **Quelle:** Sanity-Gate I7e-B (G6-Tester-Befund).
- **Status:** offen, Test-Breite.

**Beschreibung:**
Alle drei Konflikt-Szenarien in `tests/e2e/specs/16-optimistic-lock.spec.ts`
nutzen `EVENT_ADMIN` als Login. Der `OrganizerEventEditController`-
Lock-Pfad ist damit **nur statisch** abgesichert (via
`OptimisticLockControllerWiringTest`). Ein End-zu-End-Beweis, dass
der Organizer-Pfad den gleichen Lock nutzt, fehlt.

**Naechster Schritt:**
Einen vierten Test in Spec 16 ergaenzen: Analog zu Test 1 (Update-
Konflikt), aber mit `EVENT_ORGANIZER` statt `EVENT_ADMIN`. Einmalig
bestaetigt, dass die Lock-Verkabelung im Organizer-Pfad identisch
funktioniert.

**Groessenordnung:** ca. 30 Min. Gemeinsam mit Follow-up r als
`test(locks)`-Commit.

### Follow-up r — Statische Flag-Reihenfolge-Invariante

- **Quelle:** Sanity-Gate I7e-B (G6-Tester-Befund), explizit als
  nicht-sicherheitsrelevant im G4-Review (Dimension 8) bestaetigt.
- **Status:** offen, Drift-Schutz.

**Beschreibung:**
Die 4 Lock-Actions in `OrganizerEventEditController` und
`EventAdminController` pruefen `treeEditorEnabled()` vor dem
`$expectedVersion`-Parsing. Das ist manuell verifiziert, aber nicht
per Invariante festgehalten. Bei kuenftigem Refactoring koennte
die Reihenfolge unbemerkt gedreht werden.

**Naechster Schritt:**
Erweiterung von `OptimisticLockInvariantsGapTest` um einen Test,
der pro Action prueft, dass die Position von `treeEditorEnabled()`
im Method-Body kleiner ist als die Position von `$expectedVersion = isset`.
Regex-Assertion, ca. 10 Zeilen.

**Groessenordnung:** ca. 30 Min. Gemeinsam mit Follow-up q als
`test(locks)`-Commit.

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

## Bearbeitungsregel

Wenn ein Inkrement-Coder beim Pre-Flight einen der obigen Follow-ups
adressiert, traegt er den Abschluss in den Commit-Trailer ein und
entfernt den Eintrag hier. Neu entdeckte Follow-ups werden als
`Follow-up <naechster-Buchstabe>` angehaengt.
