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

## 5. Inkrement 2 (I2) — BroadcastChannel fuer Cross-Tab-Sync (Skizze)

**Noch nicht implementiert. Hier nur die Zielarchitektur.**

Problem: Nach I1 weiss Tab B zwar beim Oeffnen, dass Tab A locked — aber
wenn Tab A mittendrin fertig wird, merkt Tab B das erst bei seinem naechsten
Poll. Und Logout in Tab A laesst Tab B bis zum naechsten Request
eingeloggt.

**Loesung (Browser-nativ):** Eine kleine JS-Library `broadcast.js` nutzt die
`BroadcastChannel`-API. Drei Kanaele:

- `vaes:auth` — Logout-Broadcast; andere Tabs redirecten auf `/login`.
- `vaes:entry:{id}` — Save-Broadcast; andere Tabs zeigen „in anderem Tab
  geaendert, [Neu laden]".
- `vaes:session` — Session-Timeout-Warnung synchron in allen Tabs.

Fallback: `localStorage`-`storage`-Event (100 % Abdeckung der Ziel-Browser).

**Aufwand-Schaetzung:** 1–2 Tage, rein Frontend. Kein Backend-Change.

---

## 6. Inkrement 3 (I3) — Optimistic Locking ausrollen + Cookie-Haerting (Skizze)

**Noch nicht implementiert. Hier nur die Zielarchitektur.**

- Migration 007: `version INT UNSIGNED NOT NULL DEFAULT 1` auf `events`,
  `event_tasks`, `event_task_assignments`.
- Repositories ziehen den `WHERE version = :v` / `version = version + 1`
  Pattern nach.
- Conflict-UX: Zweispaltige Diff-Ansicht „Dein Stand" vs. „Aktueller
  Stand", Re-Submit-Button.
- Cookie-Policy in Produktion auf `SameSite=Strict` umstellen
  (Rule 02-security schliessen).

**Aufwand-Schaetzung:** 2–3 Tage, Schema-Migration + Repository-Fleissarbeit
+ UI-Design.

---

## 7. Reihenfolge und Gates

| Schritt | Gates | Status |
|---------|-------|--------|
| I1 — Pessimistic Lock | G1–G9 | **fertig 2026-04-20** |
| I2 — BroadcastChannel | G1–G9 | offen, Skizze hier |
| I3 — Optimistic Rollout + Cookie-Strict | G1–G9 | offen, Skizze hier |

I1 hat keinen Konflikt mit der laufenden Arbeit aus Modul 6 I6 und beruehrt
bewusst nur den `work_entries`-Edit-Flow. Events/Tasks/Assignments werden
erst in I3 einbezogen — das haelt den Blast-Radius klein.

---

*Letzte Aktualisierung: 2026-04-20 — I1 abgeschlossen, 399 Unit-Tests gruen.*
