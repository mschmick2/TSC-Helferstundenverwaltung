# 📋 Rules: Audit-Trail (VAES — Revisionssicherheit)

Geladen von: `auditor.md` (G6), bei Bedarf `coder.md` (G2) bei Audit-Log-Aufrufen.

---

## Prinzip

- **Append-only**: `audit_log` nie `UPDATE` oder `DELETE`. DB-Trigger blockt das.
- **Vollstaendig**: jede Business-Aenderung hat einen Eintrag.
- **Nachvollziehbar**: `user_id`, `ip_address`, `user_agent`, `created_at` immer gefuellt.
- **Details**: `old_values`/`new_values` als JSON (nur geaenderte Felder), `metadata` fuer Kontext.

---

## AuditService-Signatur

```php
namespace App\Services;

final class AuditService
{
    public function __construct(
        private readonly PDO $pdo,
    ) {}

    public function log(
        string $action,
        ?string $tableName = null,
        ?int $recordId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null,
        ?string $entryNumber = null,
        ?array $metadata = null,
    ): void {
        // INSERT in audit_log
        // user_id, session_id, ip_address, user_agent werden aus
        // Service-State (init()) gezogen, nicht pro log()-Aufruf.
    }
}
```

---

## Action-Katalog (Schema-ENUM)

Das Feld `audit_log.action` ist ein **MySQL-ENUM** mit 13 festen Werten
(seit Migration 011 / Modul 6 I8, 2026-04-24):

```sql
action ENUM(
    'create', 'update', 'delete', 'restore',
    'login', 'logout', 'login_failed',
    'status_change', 'export', 'import',
    'config_change', 'dialog_message',
    'access_denied'
) NOT NULL
```

**Konsequenz:** Neue Action-Kategorien erfordern eine Schema-Migration
(`ALTER TABLE audit_log MODIFY action ENUM(...)`).

### Mapping Business-Aktion → action-Wert

| Business-Aktion | `action` | Beispiel-Felder |
|-----------------|----------|-----------------|
| Antrag erstellen | `create` | `table_name='work_entries'`, `new_values={...}` |
| Antrag editieren | `update` | `old_values`, `new_values` |
| Antrag soft-deleten | `delete` | `description='Antrag geloescht'` |
| Antrag wiederherstellen | `restore` | — |
| Login erfolgreich | `login` | `user_id`, `ip_address` |
| Login-Fehlversuch | `login_failed` | `user_id` (nullable), `metadata={email}` |
| Logout | `logout` | `user_id` |
| Status-Wechsel WorkEntry | `status_change` | `old_values={status}`, `new_values={status}` |
| CSV/PDF-Export | `export` | `metadata={scope, filters, row_count}` |
| CSV-Import | `import` | `metadata={file, rows_ok, rows_fail}` |
| Settings-/Rollen-Aenderung | `config_change` | `table_name='settings'`/`user_roles` |
| Dialog-Nachricht | `dialog_message` | `record_id=<dialog_id>` |
| Authorization-Denial | `access_denied` | `metadata={route, method, reason, …}` |

**Hinweis zur Granularitaet:** Feinere Unterscheidungen (z.B.
`entry_approve` vs. `entry_reject`) landen in `description` und `metadata`,
nicht als eigenes ENUM-Level.

### Reason-Codes fuer `access_denied` (seit Modul 6 I8)

Der `access_denied`-Wert wird immer mit einem Reason-Code in der Metadata
kombiniert. Die Codes sind fuer den Auditor maschinen-lesbar und erlauben
`SELECT`-Filter wie `metadata->>'$.reason' = 'rate_limited'`.

| `metadata.reason` | Wann | Quelle |
|-------------------|------|--------|
| `missing_role` | User hat die erforderliche Rolle nicht | `RoleMiddleware` |
| `csrf_invalid` | CSRF-Token fehlt oder ist abgelaufen | `CsrfMiddleware` |
| `rate_limited` | Request ueber dem Bucket-Limit (429) | `RateLimitMiddleware` |
| `ownership_violation` | Resource gehoert anderem User (z.B. IDOR-Check) | `BaseController::assertEventEditPermission` |
| `resource_not_found` | Ziel-Resource existiert nicht oder ist soft-deleted | Controller-individuell |

Weitere Reason-Codes duerfen ergaenzt werden. Die `AuthorizationException`
nimmt den Code im zweiten Konstruktor-Parameter entgegen; der
`handleAuthorizationDenial`-Helper aus `BaseController` oder der Slim-
ErrorHandler geben den Code an `AuditService::logAccessDenied` weiter.

---

## Pflicht-Felder pro Eintrag (Schema-konform)

| Feld | Pflicht | Beispiel |
|------|---------|----------|
| `action` | ✅ | `update` |
| `user_id` | empfohlen (ausser `login_failed` ohne User) | 42 |
| `created_at` | ✅ (DB-Default) | NOW() |
| `ip_address` | empfohlen | `$_SERVER['REMOTE_ADDR']` |
| `user_agent` | empfohlen | `$_SERVER['HTTP_USER_AGENT']` |
| `table_name` | empfohlen | `work_entries` |
| `record_id` | empfohlen | 1234 |
| `entry_number` | empfohlen bei work_entries | `2026-00042` |
| `old_values` | bei UPDATE | `{"status": "eingereicht"}` |
| `new_values` | bei INSERT/UPDATE | `{"status": "freigegeben"}` |
| `description` | empfohlen | "Antrag freigegeben durch Pruefer" |
| `metadata` | kontextabhaengig | `{"reason": "...", "dialog_id": 7}` |

**HINWEIS:** Das Feld heisst im Schema **`metadata`**, nicht `details`.
Frueher falsch dokumentiert; Rules v2 korrigiert.

---

## Beispiel-Aufrufe

```php
// Entry-Approval (Status-Wechsel)
$this->audit->log(
    action: 'status_change',
    tableName: 'work_entries',
    recordId: $entry->id,
    oldValues: ['status' => $entry->status],
    newValues: ['status' => 'freigegeben'],
    description: 'Antrag freigegeben durch Pruefer',
    metadata: [
        'entry_number' => $entry->entryNumber,
        'hours'        => $entry->hours,
        'reviewer_id'  => $reviewerId,
    ],
);

// Rueckfrage (Status-Wechsel + Dialog-Eintrag = ZWEI Audit-Zeilen)
$this->audit->log(
    action: 'status_change',
    tableName: 'work_entries',
    recordId: $entry->id,
    oldValues: ['status' => 'eingereicht'],
    newValues: ['status' => 'in_klaerung'],
    description: 'Rueckfrage vom Pruefer',
);
$this->audit->log(
    action: 'dialog_message',
    tableName: 'work_entry_dialogs',
    recordId: $msgId,
    description: 'Neue Dialog-Nachricht',
);

// Rollen-Aenderung (config_change)
$this->audit->log(
    action: 'config_change',
    tableName: 'user_roles',
    recordId: $userId,
    newValues: ['role' => 'pruefer'],
    description: "Rolle 'pruefer' zugewiesen an User $userId",
);

// CSV-Export
$this->audit->log(
    action: 'export',
    description: 'CSV-Export durchgefuehrt',
    metadata: [
        'scope'     => 'entries',
        'filter'    => ['year' => 2026, 'status' => 'freigegeben'],
        'row_count' => $count,
        'format'    => 'csv',
    ],
);

// Reorder einer Geschwister-Liste (z.B. TaskTreeService::reorderSiblings,
// CategoryService-Sortierung). Reorder hat keinen einzelnen Record-Traeger —
// der Zustand ist in der Reihenfolge mehrerer Zeilen codiert.
$this->audit->log(
    action: 'update',
    tableName: 'event_tasks',  // konkrete Tabelle, NICHT die uebergeordnete Entity
    recordId: null,            // kein einzelnes Record — bewusst null
    description: 'Reihenfolge der Aufgaben geaendert',
    metadata: [
        'event_id'        => $eventId,
        'parent_task_id'  => $parentId,           // null = Top-Level
        'children_order'  => [12, 7, 9, 4],       // Task-IDs in neuer Reihenfolge
        'operation'       => 'reorder',
    ],
);
```

> **Reorder-Konvention** (festgelegt im G1-Delta-Review von Modul 6 I7a,
> 2026-04-22): bei jeder Reorder-Operation `tableName` = konkrete Tabelle,
> `recordId` = `null`, vollstaendige Information in `metadata`. Begruendung:
> Reorder beruehrt einen Set von Rows, nicht eine einzelne — eine
> stellvertretende `recordId` (z.B. die des Parents oder Events) waere
> irrefuehrend. Auditoren-Suche identifiziert Reorder-Eintraege ueber
> `metadata->operation = 'reorder'` und `metadata->parent_task_id`.

---

## Whitelisting bei UPDATE-Logs

Nicht alle Felder eines Models ins Audit schreiben — nur die geaenderten. Insbesondere NIE:
- `password_hash`
- `totp_secret`
- `remember_token`
- Session-IDs

**Pattern:**
```php
$diff = [];
$newDiff = [];
foreach ($changes as $field => $newValue) {
    if (in_array($field, ['password_hash', 'totp_secret'], true)) continue;
    if ($oldRow[$field] === $newValue) continue;
    $diff[$field] = $oldRow[$field];
    $newDiff[$field] = $newValue;
}

if ($diff !== []) {
    $this->audit->log(
        action: 'update',
        tableName: 'users',
        recordId: $userId,
        oldValues: $diff,
        newValues: $newDiff,
    );
}
```

---

## Fehlerbehandlung

- Audit-Log-Schreibung schlaegt fehl → Exception bis zur Transaktion propagieren
- Transaktion rollbacked → Business-Write wird ebenfalls rueckgaengig gemacht
- NIEMALS `try/catch { /* ignore */ }` um `$this->audit->log()`

---

## Verbotenes

- Aktion als Freitext schreiben, ohne sie ins Action-Katalog aufzunehmen
- `old_values`/`new_values` als Strings statt JSON
- `audit_log` mit `UPDATE` oder `DELETE` beruehren
- Passwoerter/Secrets in `old_values`/`new_values`/`metadata`
- Audit-Aufruf auskommentieren "fuer Debugging"
- Audit-Eintrag schreiben BEVOR die Business-Aktion commited ist (falsche Reihenfolge)
