# Modul 7 — Multitab-/Multisession-Haerting

> **Stand:** 2026-04-20
> **Zweck:** Schliesst die offenen Punkte zu `REQ-MULTI-001…004` in
> `docs/REQUIREMENTS.md §13.2`. Ergaenzt den bestehenden Stack nur minimal —
> keine neuen Dependencies, keine externen Dienste, alles Strato-kompatibel.
> **Scope dieser Runde:** **Inkrement 1 (Pessimistic Lock) wird umgesetzt.**
> I2 und I3 bleiben als Skizze im Dokument stehen und werden spaeter eigens
> geplant.

---

## 1. Ausgangslage

Aus einer gezielten Bestandsaufnahme am 2026-04-20:

### Was schon sauber ist

- **Session-Pool**: `SessionRepository` haelt beliebig viele aktive Sessions
  pro User (mehrere Geraete parallel). Passwort-Reset killt alle Sessions
  atomar (`AuthService::destroyAllSessions()`).
- **Cookie-Flags**: `HttpOnly`, `SameSite=Lax`, `Secure` (automatisch bei
  HTTPS) gesetzt in `src/public/index.php`.
- **Optimistic Locking auf `work_entries`**: `version`-Spalte plus
  `WHERE version = :v`-Check in jedem UPDATE. `WorkflowService` wirft
  `BusinessRuleException('… zwischenzeitlich geaendert …')` bei Konflikt.
- **CSRF-Token**: Ein Token pro Session, timing-safe Vergleich ueber
  `hash_equals`. Nach Login neu gesetzt, bei Logout verworfen.

### Was fehlt

| Luecke | Betroffene Anforderung |
|--------|------------------------|
| `entry_locks`-Tabelle existiert, wird nirgendwo genutzt | REQ-MULTI-004 |
| `lock_timeout_minutes`-Setting ist im UI sichtbar, wird nicht gelesen | REQ-MULTI-004 |
| Keine Tab-zu-Tab-Kommunikation (Logout, Save-Conflict) | REQ-MULTI-002 (komfortabel) |
| `events`, `event_tasks`, `event_task_assignments` ohne `version`-Spalte | REQ-MULTI-003 (vollstaendig) |
| Conflict-UX: User verliert Eingaben, nur „Bitte neu laden" | UX-Qualitaet |
| `SameSite=Lax` statt `Strict` in Produktion | Rule 02-security |

---

## 2. Leitprinzipien fuer den Tech-Stack

Die Erweiterung darf den heutigen Strato-Stack nicht aufbohren. Daraus folgt:

- **Backend**: PHP 8.3 + PDO + bestehende Service-/Repository-Schichten.
  Keine Queues, keine Worker ausserhalb der bestehenden Job-Queue.
- **Datenbank**: MySQL 8.4 (`entry_locks`-Tabelle ist bereits angelegt).
  Keine neuen Schemas fuer I1 — nur vorhandenes aktivieren.
- **Browser-zu-Browser-Sync**: **BroadcastChannel-API** (nativ, alle Ziel-Browser
  nach §13.4 unterstuetzen sie). Fallback via `localStorage`-`storage`-Event.
  **Kein WebSocket-Server, kein SSE.**
- **Polling statt Push**: Heartbeat ueber normales `fetch()`. Kein
  EventSource.
- **Frontend**: Vanilla JS. Keine neuen Libraries, kein Build-Schritt.

Konsequenz: **Deployment bleibt genauso wie heute — FTP-Upload, fertig.**

---

## 3. CSRF-Entscheidung (heutiger Stand dokumentiert)

**Entscheidung (2026-04-20): Der aktuelle Stand bleibt.**

Ein CSRF-Token pro Session, timing-safe validiert, bei Privileg-Wechsel
(Login / 2FA / Logout) regeneriert. Diese Policy erfuellt den OWASP-Standard
und ist **tab-sicher**: alle Tabs teilen sich die Session und damit den
gueltigen Token. Eine Rotation pro Request wuerde zu Double-Submit-Konflikten
zwischen Tabs fuehren und bietet keinen zusaetzlichen Schutz gegen das
Bedrohungsmodell (Dritt-Seiten-Angriffe via `<form>`/`<img>`/CORS).

**Nicht geaendert:** `SecurityHelper`, `CsrfMiddleware`, alle View-Formulare.

---

## 4. Inkrement 1 (I1) — Pessimistic Lock aktivieren

**Ziel:** Wenn zwei Nutzer denselben `work_entry` gleichzeitig bearbeiten
wollen, sieht der Zweitkommende sofort, dass der Erste im Bearbeiten ist —
nicht erst beim Submit. Der Lock laeuft nach `lock_timeout_minutes` (Default 5)
automatisch ab, wenn der Erste seinen Tab zumacht.

### 4.1 Datenmodell (unveraendert)

Die `entry_locks`-Tabelle existiert bereits
(`scripts/database/create_database.sql`):

```sql
CREATE TABLE entry_locks (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    work_entry_id  INT UNSIGNED NOT NULL,
    user_id        INT UNSIGNED NOT NULL,
    session_id     INT UNSIGNED NULL,
    locked_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at     TIMESTAMP NOT NULL,
    UNIQUE KEY unique_entry_lock (work_entry_id),
    INDEX idx_entry_locks_expires (expires_at),
    FK auf work_entries, users, sessions (alle ON DELETE CASCADE)
);
```

Wichtig:
- **`UNIQUE (work_entry_id)`** → hoechstens ein aktiver Lock pro Entry;
  das Atomare ueberlassen wir dem Index.
- **FK auf `sessions` mit `ON DELETE CASCADE`** → Session-Logout loescht
  automatisch alle Locks der Session. Wir muessen das nirgends selbst
  aufraeumen.

**Keine Migration fuer I1 noetig.** Die Tabelle wird aktiviert, nicht neu
angelegt.

### 4.2 Backend-Komponenten (neu)

#### `src/app/Repositories/EntryLockRepository.php`

```
acquireOrRefresh(int $entryId, int $userId, int $sessionId, int $ttlMin): array|null
  → Versucht einen Lock fuer (entryId) zu setzen oder zu verlaengern.
  → Gibt die Lock-Zeile zurueck, wenn erfolgreich. Null, wenn ein
    fremder Lock aktiv ist. Nutzt INSERT … ON DUPLICATE KEY UPDATE mit
    Bedingung auf user_id + expires_at > NOW().

releaseByUser(int $entryId, int $userId): int
  → Loescht den eigenen Lock. Gibt die Zeilenzahl zurueck.

findActive(int $entryId): array|null
  → Aktive Lock-Zeile (expires_at > NOW()) oder null.

deleteStale(): int
  → Haushalt: DELETE WHERE expires_at <= NOW(). Zeilenzahl.
```

**Atomizitaet:** Weil `work_entry_id` UNIQUE ist, macht MySQL das Lock-
Acquiring in einem einzigen Statement konsistent. Keine expliziten
Transaktionen noetig.

#### `src/app/Services/EntryLockService.php`

Fassade um den Repository, die das `lock_timeout_minutes`-Setting liest
(`SettingsService::getInt('lock_timeout_minutes', 5)`) und den Lock plus die
Session-ID aus dem Request verknuepft. Gibt strukturierte Ergebnisse zurueck:

- `tryAcquire($entryId, $user, $sessionId)` →
  `{success: true, lock: {…}}` oder
  `{success: false, heldBy: {userName, expiresAt}}`.

Kein Throwing im Normalfall — der Controller entscheidet, ob das eine
Read-Only-Seite oder eine 409-Response wird.

### 4.3 Integration in `WorkEntryController`

- **`edit()`**: Vor `render()` einen Lock-Versuch. Zwei Faelle:
  - Lock bekommen → normale Edit-Seite + `lock_expires_at` an View mitgeben.
  - Fremder Lock aktiv → Read-Only-Modus des gleichen Templates (`$locked =
    ['user' => '…', 'expires_at' => '…']`), Submit-Button hidden, rotes
    Banner oben.
- **`update()`**: Nach erfolgreicher DB-Aenderung Lock freigeben
  (`releaseByUser`). Bei Version-Conflict Lock bestehen lassen — User
  kann es nochmal versuchen.
- **Neue Routen** (`src/config/routes.php`):
  - `POST /entries/{id}/lock/heartbeat` — AJAX vom Frontend alle 60 s.
    Verlaengert Lock via `acquireOrRefresh`.
  - `POST /entries/{id}/lock/release` — AJAX beim Verlassen der Seite.
    `releaseByUser`.

  Beide Routen sind JSON-Endpunkte, geschuetzt via `AuthMiddleware` +
  `CsrfMiddleware`. Rueckgabe schlank: `{ok: true, expires_at: …}` oder
  `{ok: false, reason: 'held_by_other'}`.

### 4.4 Frontend (Vanilla JS)

Neue Datei `src/public/js/entry-lock.js`. Auf der Edit-Seite per
`<script>`-Tag eingebunden, wenn `$locked === null`:

```
const ENTRY_ID = <hidden input>;
const HEARTBEAT_MS = 60_000;

// Heartbeat alle 60 s
setInterval(heartbeat, HEARTBEAT_MS);

// Release beim Verlassen (Tab zu, Navigation, Reload)
window.addEventListener('beforeunload', releaseSync);
document.addEventListener('visibilitychange', maybeHeartbeatOnReturn);

// Fallback: bei erfolgreichem Submit wird der Lock serverseitig geloescht.
```

`releaseSync` nutzt `navigator.sendBeacon()`, damit der Release-Request
auch dann sicher durchgeht, wenn die Seite schon entladen wird.

Im Read-Only-Modus (`$locked !== null`) wird statt dessen ein kleines
Polling-Script aktiv, das alle 30 s prueft, ob der Lock frei ist —
falls ja, Banner-Hinweis „Der Eintrag ist jetzt frei. [Neu laden]".

### 4.5 Stale-Lock-Cleanup

**G1-Entscheidung (2026-04-20):** Cleanup haengt am Anfang von
`SchedulerService::runDue()`. Beide Trigger-Pfade — externer Cron-Pinger
UND `OpportunisticSchedulerMiddleware` — landen dort. Ein einziger Hook
reicht aus, der `EntryLockRepository::deleteStale()` ruft. Fehler werden
nur geloggt, damit die eigentlichen Jobs weiterlaufen (konsistent zur
I6-Ueberlebensregel). Kein separater Job-Handler noetig.

### 4.6 Audit-Log

- **Nicht** jedes Lock-Acquire/Release. Das ist Lese-Verhalten, kein
  Business-Event, und wuerde das `audit_log` aufblaehen.
- **Ja** fuer Lock-Bruch durch Admin-Override (nicht Teil von I1 —
  Follow-up, wenn der Bedarf entsteht).
- **Ja** fuer 409-„held_by_other" — als `description` im AuditService
  bei der anschliessenden Business-Aktion, falls eine ausgeloest wurde.

### 4.7 Tests (G7)

- **Unit**: `EntryLockRepositoryTest` (acquire/refresh/release/stale),
  `EntryLockServiceTest` (Settings-Lookup + Policy).
- **Integration**: `EntryLockIntegrationTest` mit echter MySQL — pruefen,
  dass UNIQUE-Constraint zwei parallele Acquires korrekt trennt.
- **Feature**: Edit-Flow mit zwei Usern simuliert — Tab A holt Lock,
  Tab B bekommt Read-Only, Tab A released, Tab B kann jetzt acquiren.

### 4.8 DSGVO / Audit / Security

- `entry_locks` enthaelt `user_id` und `session_id` — beides bereits PII,
  aber durch FK-CASCADE mit `users`/`sessions` an die regulaere
  Aufbewahrung gekoppelt. Keine zusaetzliche Dokumentation in
  `02-dsgvo.md` noetig.
- Heartbeat-Endpunkt schuetzt wie `update()` per `AuthMiddleware` +
  `CsrfMiddleware`. Kein Rate-Limit pro User noetig (Eigener Lock, keine
  Spam-Flaeche).

---

## 5. Inkrement 2 (I2) — BroadcastChannel fuer Cross-Tab-Sync

**Status: fertig 2026-04-20.** Cross-Tab-Sync ueber `BroadcastChannel` mit
`localStorage`-Fallback ist aktiv. Logout und erfolgreiche Entry-Updates
werden an andere Tabs derselben Session verteilt.

### 5.1 Kanaele (Implementierung)

- `auth:logout` — setzt andere Tabs auf `/login`. Verhindert Zombie-Tabs
  nach Logout.
- `entry:updated` mit `{id}` — read-only-Tabs (`entry-lock.js` im Poll-
  Modus) zeigen sofort den „jetzt frei / neu laden"-Banner statt auf den
  30-s-Poll zu warten.

`vaes:session`-Timeout-Kanal aus der urspruenglichen Skizze bleibt
offen — dafuer fehlt heute noch eine Client-Countdown-UI. Wenn der Bedarf
kommt, nachziehen.

### 5.2 Transport

- Primaer: `BroadcastChannel('vaes')` — same-origin, kein Backend.
- Fallback: `localStorage.setItem(STORAGE_KEY, JSON.stringify(msg))` +
  sofortiges `removeItem`. Andere Tabs bekommen den `storage`-Event und
  deserialisieren. Deckt aeltere Browser und sehr restriktive iframes ab.

Payload **bewusst minimal**: `{event, at, [id]}`. Keine PII, keine Titel,
keine Nutzernamen. Andere Tabs holen sich Details bei Bedarf via normalem
HTTP aus ihrer eigenen Session.

### 5.3 Server-Trigger

Neuer Flash-aehnlicher Helper `ViewHelper::broadcast(string $event, array
$payload = [])` schreibt in `$_SESSION['_broadcast']`.
Layout (`layouts/main.php`, `layouts/auth.php`) ruft beim naechsten Render
`getBroadcasts()` und serialisiert die Liste in ein
`data-vaes-broadcasts`-Attribut am `<body>`. `broadcast.js` liest das
beim `DOMContentLoaded` einmalig und sendet jede Nachricht.

**Trigger-Punkte:**

- `AuthController::logout()` setzt `auth:logout` vor Redirect auf `/login`.
- `WorkEntryController::update()` setzt `entry:updated` nach erfolgreichem
  Write (vor Redirect auf Liste).

### 5.4 Sicherheit

- Kein CSRF-Token noetig — `BroadcastChannel` und `localStorage` sind rein
  same-origin-lokale Browser-Mechanismen.
- Payload enthaelt keine PII (nur `event/at/id`).
- `JSON.parse` und `JSON` im Storage werden im `try/catch` gekapselt, damit
  manipulierter Key nichts kippt. Falls doch manipuliert: im Worst Case
  redirected die Tab auf `/login` oder zeigt den Banner — also nichts
  Sicherheitsrelevantes.
- Kein Audit-Eintrag fuer das Broadcast selbst. Der eigentliche Save /
  Logout wird ohnehin per `AuditService` protokolliert.

---

## 6. Inkrement 3 (I3) — Optimistic Locking ausrollen + Cookie-Haerting

**Status: fertig 2026-04-20.**

### 6.1 Schema

- Migration `007_optimistic_locking.sql` ergaenzt
  `version INT UNSIGNED NOT NULL DEFAULT 1` auf `events`, `event_tasks`,
  `event_task_assignments`. Idempotent via `INFORMATION_SCHEMA.COLUMNS`
  (gleiche Mechanik wie 004–006).
- Rollback `007_optimistic_locking.down.sql` entfernt die Spalte wieder,
  ebenfalls idempotent.
- `scripts/database/create_database.sql` kommt ohne die drei Tabellen aus
  (die leben ausschliesslich in Migration 002 + 007), deshalb kein Patch
  dort notwendig.

### 6.2 Repositories

Alle Writes auf den drei Tabellen inkrementieren `version = version + 1`:

- `EventRepository::update`, `changeStatus`, `softDelete`
- `EventTaskRepository::update`, `softDelete`
- `EventTaskAssignmentRepository::changeStatus`, `setWorkEntryId`,
  `setReplacement`, `softDelete`

Zusaetzlich akzeptieren `EventRepository::update` und
`EventTaskRepository::update` den optionalen Parameter `?int $expectedVersion`.
Ist er gesetzt, wird das UPDATE mit `AND version = :version` verriegelt und
gibt `false` zurueck, wenn niemand betroffen ist (Konflikt). Default bleibt
`null`, damit Bestandscaller ohne Anpassung funktionieren.

### 6.3 Models

`Event`, `EventTask` und `EventTaskAssignment` parsen `version` in
`fromArray()` (Default 1 fuer Zeilen vor Migration 007) und bieten
`getVersion(): int`. Damit koennen Views die Version im Formular
transportieren.

### 6.4 Conflict-UX

`EventAdminController::update` liest `$_POST['version']` und reicht die Zahl
an `EventRepository::update` weiter. Bleibt das UPDATE ohne Treffer (weil
ein anderer Tab zwischen Read und Write geschrieben hat), setzt der
Controller einen Warn-Flash — „Das Event wurde zwischenzeitlich von jemand
anderem geaendert. Bitte Formular neu laden und Aenderungen erneut
eintragen." — und leitet zurueck auf die Edit-Seite.

Die urspruenglich geplante Diff-Ansicht („Dein Stand" vs. „Aktueller Stand")
wurde zugunsten einer schlanken Reload-Aufforderung verworfen. Begruendung:
Admins editieren Event-Rumpfdaten selten parallel und die Konflikt-Frequenz
ist niedrig; eine vollstaendige Mergeview waere hoher Aufwand fuer einen
Fall, der in Praxis selten vorkommt. Die Entscheidung ist reversibel,
sobald der Anwendungsfall haeufiger wird.

Das Formular in `src/app/Views/admin/events/edit.php` traegt
`<input type="hidden" name="version" value="<?= $event->getVersion() ?>">`.
Task- und Assignment-UI bleibt ohne Version-Prueflogik, weil dort aktuell
kein paralleles Edit-Formular existiert; die version-Spalte laeuft trotzdem
mit, damit spaeter ohne Migration eingeschaltet werden kann.

### 6.5 Cookie-Haerting

`src/config/config.example.php` empfiehlt jetzt `samesite => 'Strict'` als
Produktions-Default. Damit werden Session-Cookies bei Cross-Site-
Navigationen nicht mitgesendet, was CSRF-artige Angriffe zusaetzlich zum
Token-Schutz erschwert. In einer reinen Vereinsverwaltung ohne externe
Auth-Rueckleitungen ist die Einschraenkung unkritisch. `src/config/config.php`
selbst (dev-Wert) bleibt unveraendert — die Empfehlung wirkt erst beim
naechsten Produktions-Deployment, das die Vorlage als Referenz verwendet.

### 6.6 Tests

- Unit-Tests erweitert: `EventTest::test_fromArray_parses_version`,
  `EventTest::test_fromArray_defaults_version_to_1`,
  `EventTaskTest::test_fromArray_parses_version`,
  plus die bestehende `test_fromArray_uses_sensible_defaults`-Erweiterung.
- PHPUnit-Gesamtlauf: 440 Tests, 923 Assertions, 6 Fehler (alle aus den
  DB-gebundenen Integration-/Feature-Suiten, weil `helferstunden_test` in
  dieser Dev-Umgebung nicht laeuft — deckungsgleich mit der Baseline vor
  I3). Keine Regression.

---

## 7. Inkrement 4 (I4) — Conflict-Diff-UI fuer den Event-Editor

**Status: fertig 2026-04-20.**

I3 hatte den Konflikt-Fall mit einem Flash + Redirect auf die Edit-Seite
behandelt. Das war ausreichend gegen Lost-Updates, aber unangenehm in der
Praxis: Der Nutzer verlor seine eingetippten Werte und musste raten, was
der andere Tab geaendert hatte.

### 7.1 Verhalten

`EventAdminController::update` rendert bei Versions-Konflikt statt zu
redirecten die Edit-View erneut. Dabei wird:

- `$event` aus frischem `findById()` gezogen (inkl. neuer version-Nummer),
- das Formular mit dem aktuellen DB-Stand vorbelegt,
- ein Zusatzparameter `$conflictMyState` mit den vom Nutzer gerade gesendeten
  Werten uebergeben.

Die View (`admin/events/edit.php`) baut in einem Warn-Alert eine drei-
spaltige Tabelle auf: "Feld / Dein Stand / Aktueller DB-Stand". Nur Felder,
die tatsaechlich auseinander laufen, werden gelistet — Organisator-Listen
werden als sortierte Namen verglichen. Der Nutzer sieht sofort, wo der
Konflikt liegt, kann seine Werte manuell uebernehmen (copy/paste aus der
Tabelle) und speichert mit der frischen version erneut ab.

### 7.2 Scope-Entscheidungen

- **Kein Radio-/Toggle-UI pro Feld.** Wir haben das erwogen, aber in der
  Praxis sind die Konfliktfelder wenige und der Nutzer hat den Text ohnehin
  schon im Kopf. Ein Radio-Mechanismus haette serverseitig zusaetzliche
  Merge-Logik verlangt, die fuer die niedrige Konflikt-Frequenz nicht
  gerechtfertigt ist. Reversibel: sobald die Frequenz steigt, kann die
  Tabelle zu einer aktiven UI ausgebaut werden.
- **Kein Force-Apply-Flag.** Der Nutzer faellt nach manueller Uebernahme
  zurueck in den regulaeren UPDATE-Pfad. Ein Force-Apply haette die
  Optimistic-Locking-Garantie unterlaufen — nicht gewollt.
- **Organizer-Liste** wird verglichen, aber nicht separat aufgeloest. Wer
  zuletzt speichert, setzt die Organizer-Liste. Das ist vertretbar, weil
  Organizer-Aenderungen selten konkurrieren und jeder Schreibversuch im
  Audit-Log landet.

### 7.3 Tests

- Zwei neue statische Invarianten in
  `tests/Unit/Controllers/EventAdminControllerInvariantsTest.php`:
  - `test_update_renders_conflict_view_on_version_mismatch`: sichert, dass
    `update()` das Token `conflictMyState` an die View weiterreicht.
  - `test_edit_view_renders_conflict_diff`: sichert, dass die View das
    Gegenstueck rendert (Alert-Heading + Auswertung von
    `$conflictMyState`).
- Gesamtlauf: 442 Tests, 926 Assertions, 6 Fehler (baseline-identisch).

---

## 8. Reihenfolge und Gates

| Schritt | Gates | Status |
|---------|-------|--------|
| I1 — Pessimistic Lock | G1–G9 | **fertig 2026-04-20** |
| I2 — BroadcastChannel | G1–G9 | **fertig 2026-04-20** |
| I3 — Optimistic Rollout + Cookie-Strict | G1–G9 | **fertig 2026-04-20** |
| I4 — Conflict-Diff-UI | G1–G9 | **fertig 2026-04-20** |

I1 hat keinen Konflikt mit der laufenden Arbeit aus Modul 6 I6 und beruehrt
bewusst nur den `work_entries`-Edit-Flow. Events/Tasks/Assignments kamen
in I3 dazu — der Blast-Radius blieb klein, weil alle drei Repos dem bereits
in `WorkEntryRepository` erprobten Pattern folgen. I4 schliesst die UX-
Luecke, die I3 am Edit-Formular offen gelassen hatte.

---

*Letzte Aktualisierung: 2026-04-20 — I1 + I2 + I3 + I4 abgeschlossen. PHPUnit:
442 Tests (Unit-Suite gruen, 6 DB-gebundene Integration-/Feature-Tests
warten weiterhin auf `helferstunden_test`-Instanz — identische Baseline
seit I3).*
