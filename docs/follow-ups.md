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

## Bearbeitungsregel

Wenn ein Inkrement-Coder beim Pre-Flight einen der obigen Follow-ups
adressiert, traegt er den Abschluss in den Commit-Trailer ein und
entfernt den Eintrag hier. Neu entdeckte Follow-ups werden als
`Follow-up <naechster-Buchstabe>` angehaengt.
