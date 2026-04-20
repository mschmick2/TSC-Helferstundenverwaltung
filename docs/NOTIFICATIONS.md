# Benachrichtigungen & Scheduler — VAES Modul 6 I6

> Stand: 2026-04-20 — fuehrt durch die Strato-kompatible Job-Queue, die externen
> Cron-Pinger und die Opportunistic-Middleware. Zielgruppe: Admins, die VAES auf
> Strato Shared Hosting betreiben oder lokal entwickeln.

---

## 1. Ueberblick

VAES versendet Mails fuer fuenf Anlaesse:

| Anlass | Wann | Job-Typ |
|--------|------|---------|
| Event-Erinnerung | 7 Tage und 24 h vor Event-Start | `event_reminder` |
| Aufgaben-Einladung | Sofort beim Vorschlag eines Helfers | `assignment_invite` |
| Aufgaben-Erinnerung | 24 h vor Event-Start, falls Zusage offen | `assignment_reminder` |
| Dialog-Erinnerung | Nach `reminder_days` ohne Antwort auf Rueckfrage | `dialog_reminder` |
| Event-Abschluss-Erinnerung | 1 h nach Event-Ende, falls Organisator nicht abgeschlossen | `event_completion_reminder` |

Alle Mails laufen ueber dieselbe Job-Queue (`scheduled_jobs`). Ein Job wird zur
geplanten Zeit (`run_at`) aufgegriffen, ueber den passenden Handler in
`src/app/Services/Jobs/` abgearbeitet und nach Erfolg auf `done` gesetzt.

---

## 2. Architektur

```
   Business-Aktion (z.B. Assignment vorgeschlagen)
                     │
                     ▼
        SchedulerService::dispatch()  oder  ::dispatchIfNew()
                     │
                     ▼
        scheduled_jobs (MySQL, append + state-machine)
                     │
                     ▼
   ┌──── Trigger (eines von beiden) ────┐
   │                                     │
   ▼                                     ▼
 CronController                  OpportunisticSchedulerMiddleware
 POST /cron/run                  (Piggyback an z.B. Dashboard-Requests)
 X-Cron-Token: <Klartext>
                     │
                     ▼
        SchedulerService::runDue()
          - requeueStuckJobs()
          - claimDue()  (FOR UPDATE SKIP LOCKED)
          - fuer jeden Job: Handler->handle() + markDone/markFailed
          - scheduler_runs-Eintrag (Audit)
```

Wichtige Eigenschaften:

- **Atomares Claiming**: `claimDue()` nutzt `SELECT … FOR UPDATE SKIP LOCKED`,
  damit zwei parallele Trigger (externer Cron + Middleware) nie denselben Job
  doppelt bekommen. Setzt **MySQL 8.0+** voraus.
- **Backoff bei Fehlern**: 1 / 5 / 30 Minuten; nach `max_attempts` Endstatus
  `failed`.
- **Stuck-Detection**: Jobs, die laenger als 15 Minuten in `running` haengen
  (z.B. nach PHP-Fatal), werden bei naechstem Lauf zurueck auf `pending`
  gesetzt.
- **Kill-Switch**: Setting `notifications_enabled=false` blockiert sowohl
  Dispatch als auch Lauf (`isEnabled()`). `runDue()` wird ueber `canRunNow()`
  zusaetzlich ueber `cron_min_interval_seconds` gedrosselt.

---

## 3. Setup auf Strato

Strato Shared Hosting hat keinen System-Cron. VAES loest das mit zwei
unabhaengigen Triggern, die parallel laufen koennen.

### 3.1 Externer Cron-Pinger (empfohlen, primaer)

Jede Cron-as-a-Service-Plattform reicht (cron-job.org, EasyCron, GitHub Actions
mit `schedule:`-Workflow, …).

**Schritt 1 — Token rotieren:**

1. In VAES als Admin einloggen.
2. **Verwaltung → Einstellungen → Benachrichtigungen / Scheduler**.
3. Button **„Cron-Token rotieren"** klicken.
4. Der **Klartext-Token wird genau einmal angezeigt** (gruener Block oben in
   der Einstellungen-Seite). Sofort kopieren — er wird nirgendwo gespeichert
   und nach 5 Minuten oder beim Verlassen der Seite verworfen.

In der Datenbank steht nur der SHA-256-Hash (`settings.cron_external_token_hash`).

**Schritt 2 — Pinger konfigurieren:**

```
URL:        https://<deine-vaes-domain>/cron/run
Methode:    POST
Header:     X-Cron-Token: <der gerade kopierte Klartext>
Intervall:  alle 5 Minuten (mehr bringt nichts, weniger ist okay)
```

**Schritt 3 — Test-Aufruf manuell:**

```bash
curl -X POST https://deine-vaes-domain/cron/run \
     -H "X-Cron-Token: <token>" \
     -i
```

Erwartete Antworten:

| HTTP | JSON `reason` | Bedeutung |
|------|---------------|-----------|
| 200 | — | Lauf erfolgreich, `processed`/`failed` zeigen die Job-Anzahl |
| 200 | `min_interval_not_reached` | Letzter Lauf zu jung — kein Fehler, einfach abwarten |
| 401 | `unauthorized` | Token fehlt, falsch oder kein Hash hinterlegt |
| 429 | `rate_limited` | >6 Aufrufe in 60 s pro IP — Pinger drosseln |
| 503 | `notifications_disabled` | Setting `notifications_enabled=false` |

Der Lauf wird in `scheduler_runs` protokolliert (sichtbar im spaeteren Admin-Panel).

### 3.2 Opportunistische Middleware (Backup)

Falls der externe Pinger ausfaellt, springt die `OpportunisticSchedulerMiddleware`
ein: Sie haengt am Dashboard-Request und triggert mit konfigurierbarer
Wahrscheinlichkeit den Scheduler im Hintergrund **nach** der Response. Drei
Schutzschichten verhindern, dass das die UI verzoegert:

1. Wuerfel pro Request (Default 10 %).
2. `canRunNow()` blockiert, wenn `cron_min_interval_seconds` nicht erreicht ist.
3. Maximal 5 Jobs pro Lauf.

Konfiguration in `src/config/dependencies.php` an der Routen-Registrierung.

---

## 4. Bedienung im Admin-Panel

**Verwaltung → Einstellungen** enthaelt die Gruppe „Benachrichtigungen / Scheduler"
mit drei Knoepfen:

| Setting | Default | Wirkung |
|---------|---------|---------|
| `notifications_enabled` | `false` | Kill-Switch. `false` = keine Mails, kein Lauf. |
| `cron_min_interval_seconds` | `300` | Mindest-Abstand zwischen Laeufen. <60 wird abgelehnt. |
| `cron_last_run_at` | leer | Read-only, vom Scheduler aktualisiert. |

Daneben gibt es zwei Aktionen:

- **„Cron-Token rotieren"** — erzeugt einen neuen Token, alter wird ungueltig.
  Nach jeder Rotation **muss** der externe Pinger mit dem neuen Token nachgezogen
  werden, sonst bekommt er ab dem naechsten Aufruf 401.
- **„Cron-Token entfernen"** — deaktiviert den externen Endpunkt vollstaendig
  (jeder POST liefert 401). Sinnvoll bei Wartung oder Sicherheits-Vorfall.

Beide Aktionen werden in `audit_log` als `config_change` protokolliert.

---

## 5. Job-Lifecycle (Status-Maschine)

```
    pending  ─── claimDue ─→  running ─── markDone ─→ done
       ▲                          │
       │                       markFailed
       │                          │
       │                          ▼
       │             attempts < max?
       │                /        \
       │              ja          nein
       │              │             ▼
       └──── pending (mit Backoff)  failed
              + last_error                + last_error
```

Zusaetzlich:
- `cancelled` per `cancelByUniqueKey()` — Job wird nicht mehr ausgefuehrt.
- `requeueStuckJobs()` setzt `running`-Jobs aelter als 15 min zurueck auf `pending`.

---

## 6. Job-Typen im Detail

Alle Handler liegen in `src/app/Services/Jobs/`. Jeder validiert seinen
Payload, laedt die noetigen Repositories, prueft Abbruchbedingungen (Event
abgesagt? Antrag schon beantwortet?) und ruft am Ende `NotificationService`.

| Job-Typ | unique_key-Schema | Modus | Geplant von |
|---------|-------------------|-------|-------------|
| `event_reminder` | `event:{id}:reminder:{days}` | `dispatch` | `EventService::publish()` |
| `assignment_invite` | `assignment:{id}:invite` | `dispatchIfNew` | `EventAssignmentService::propose()` |
| `assignment_reminder` | `assignment:{id}:reminder` | `dispatch` | `EventAssignmentService::confirm/propose` |
| `dialog_reminder` | `entry:{id}:dialog_reminder` | `dispatch` | `WorkflowService::askQuestion()` |
| `event_completion_reminder` | `event:{id}:completion` | `dispatch` | `EventService::publish()` |

**Warum `dispatchIfNew` fuer Invites?**
Wenn ein Helfer abgelehnt wird und spaeter erneut vorgeschlagen wird (Status
flippt VORGESCHLAGEN → ABGELEHNT → VORGESCHLAGEN), wuerde `dispatch` (mit
`resetIfTerminal=true`) den abgearbeiteten `done`-Job reaktivieren und eine
zweite Einladungsmail schicken. `dispatchIfNew` laesst Endstatus unangetastet,
verhindert also genau diese Doppel-Mails. Reminder hingegen laufen mit
`dispatch`, weil ein verschobener Event sehr wohl die Erinnerung verschieben
soll.

---

## 7. Lokale Entwicklung & Tests

**Manuell triggern (ohne Cron):**
```bash
curl -X POST http://localhost:8000/cron/run \
     -H "X-Cron-Token: <token aus settings>"
```

**Tests:**
- Unit: `tests/Unit/Services/SchedulerServiceTest.php`,
  `tests/Unit/Services/NotificationServiceTest.php`,
  `tests/Unit/Middleware/OpportunisticSchedulerMiddlewareTest.php`,
  `tests/Unit/Services/Jobs/*Test.php`,
  `tests/Unit/Services/JobHandlerRegistryTest.php`
- Integration: `tests/Integration/Scheduler/` — fasst die Queue mit echter MySQL an
- Feature: `tests/Feature/Cron/CronControllerTest.php` — vollstaendiger HTTP-Roundtrip

---

## 8. Diagnose

| Symptom | Erste Anlaufstelle |
|---------|-------------------|
| Keine Mails kommen an | `notifications_enabled=true` gesetzt? Token im Pinger korrekt? `scheduler_runs` zeigt Eintraege? |
| 401 vom Pinger | Token wurde rotiert, aber Pinger nicht aktualisiert |
| 429 vom Pinger | Pinger feuert zu schnell — Intervall hochsetzen |
| 503 `notifications_disabled` | Kill-Switch ist aktiv |
| Jobs bleiben in `running` haengen | PHP-Fatal beim Handler. Naechster Lauf requeued sie automatisch nach 15 min |
| `failed`-Jobs in der Queue | `scheduled_jobs.last_error` lesen, Handler-Code pruefen |

---

## 9. Sicherheits-Notizen

- **Token-Entropie**: 32 Bytes Zufall = 256 Bit. Brute-Force ist astronomisch
  unwahrscheinlich, das Rate-Limit fuengt eher Log-Spam und Reverse-Proxy-Last
  ab.
- **DB speichert nur den SHA-256-Hash** des Tokens. Klartext lebt nur einmalig
  in der Session (TTL 5 min) waehrend der Anzeige.
- **Audit-Log**: Token rotieren / entfernen → `audit_log.action='config_change'`.
  Jeder Lauf → `scheduler_runs`-Eintrag.
- **DSGVO**: Job-Payloads enthalten User-IDs und Mail-Adressen, keine
  Freitexte. Nach Abarbeitung verbleiben sie in `scheduled_jobs.payload` bis
  zur regulaeren Aufbewahrungsfrist (siehe Rule 02-dsgvo).

---

*Letzte Aktualisierung: 2026-04-20 — Modul 6 I6.*
