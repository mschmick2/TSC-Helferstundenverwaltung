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
  Design-Luecke zu Architect-C1 aufgefallen (Follow-up z).

### ~~Follow-up t -- Lock-Reload-E2E-Test in Spec 17~~ (erledigt)

Spec 17 Test 5 "Lock-Reload aus I7e-B: Edit-Session bleibt
funktional" laeuft. Deckt die User-sichtbare Invariante
(Session-Tracking bleibt nach Lock-Reload funktional) ab. Die
strikte C1-Intent-Invariante (gleiche Session-ID ueberlebt den
Reload) ist nicht erreichbar -- siehe Follow-up z.

### Follow-up z -- Design-Fix fuer Architect-C1 (beforeunload-Close vs. Reload)

- **Quelle:** FU-G6-1 Umsetzung (Test-Befund, 2026-04-24).
- **Status:** offen, Design-Luecke.

**Beschreibung:**
Architect-C1 aus I7e-C G1 postuliert: "sessionStorage ueberlebt
Lock-Reload, gleiche Session-ID bleibt". In der Implementierung
greift jedoch der `beforeunload`-Handler in `edit-session.js`
auch bei programmatischen Reloads und schickt per
`navigator.sendBeacon` einen Close-Request. Nach dem Reload
findet `resumeOrStartSession` die Session geschlossen (404)
und legt eine neue mit neuer ID an.

User-sichtbar **intakt**: der Server dedupliziert per `user_id`
(`EditSessionView::toJsonReadyArray`), andere Editoren sehen
weiterhin "EVENT_ADMIN bearbeitet..." ohne Luecke im Polling-
Feed. Die Praesenz-Invariante haelt.

C1-Intent-Invariante **nicht erfuellt**: neue DB-Zeile, neue ID,
minimaler Server-Overhead (ein zusaetzlicher INSERT + DELETE-
Rotation ueber `cleanupStale`).

**Naechster Schritt:**
Design-Fix in `edit-session.js`. Optionen:
- (A) Ein Modul-Flag `programmaticReload = true` wird von
  `handleLockConflict` vor `window.location.reload()` gesetzt;
  `closeSessionBestEffort` prueft das Flag und ueberspringt den
  sendBeacon-Aufruf.
- (B) `handleLockConflict` ruft explizit einen "soft-reload"-Modus
  auf, der den beforeunload-Handler vorher abmeldet.

Option (A) ist minimal-invasiv, eine Flag-Variable + ein
`if`-Check. Kann in einer <30-Min-Mini-Iteration umgesetzt werden.

Nach Umsetzung: Spec 17 Test 5 strenger fassen
(`sessionIdAfter === sessionIdBefore`) und als Gegenbeweis
ausbauen.

**Groessenordnung:** ca. 30 Min inkl. Test-Erweiterung.

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

### Geplantes naechstes Inkrement

**I7e-C.2** -- Template-Edit-Sessions als analoges Pattern fuer
Event-Templates. Eigenes G1-Runde-1 mit Entscheidungen zur
DB-Schema-Erweiterung und HTTP-/JS-Symmetrie. Geschaetzt
1-2 Sessions. Wartet auf bewussten Start; nicht blockierend
fuer den I7e-C.1-Tag.

---

## Bearbeitungsregel

Wenn ein Inkrement-Coder beim Pre-Flight einen der obigen Follow-ups
adressiert, traegt er den Abschluss in den Commit-Trailer ein und
entfernt den Eintrag hier. Neu entdeckte Follow-ups werden als
`Follow-up <naechster-Buchstabe>` angehaengt.
