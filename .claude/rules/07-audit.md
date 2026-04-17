# 📋 Rules: Audit-Trail (VAES — Revisionssicherheit)

Geladen von: `auditor.md` (G6), bei Bedarf `coder.md` (G2) bei Audit-Log-Aufrufen.

---

## Prinzip

- **Append-only**: `audit_log` nie `UPDATE` oder `DELETE`. DB-Trigger blockt das.
- **Vollstaendig**: jede Business-Aenderung hat einen Eintrag.
- **Nachvollziehbar**: `user_id`, `ip_address`, `user_agent`, `created_at` immer gefuellt.
- **Details**: `old_values`/`new_values` als JSON (nur geaenderte Felder), `details` fuer Kontext.

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
        ?array $details = null,
        ?int $userId = null,          // default: aktueller User aus Session
        ?string $ipAddress = null,    // default: $_SERVER
        ?string $userAgent = null,    // default: $_SERVER
    ): void {
        // INSERT in audit_log
    }
}
```

---

## Action-Katalog (VAES-spezifisch)

### Auth

| `action` | Wann |
|----------|------|
| `login` | Erfolgreicher Login |
| `login_failed` | Fehlversuch (auch in `login_attempts`) |
| `logout` | Logout |
| `password_change` | User aendert eigenes Passwort |
| `password_reset_request` | "Passwort vergessen" ausgeloest |
| `password_reset_complete` | Reset-Link genutzt |
| `2fa_setup` | 2FA-Methode eingerichtet |
| `2fa_reset` | Admin setzt 2FA zurueck |
| `session_invalidate` | Alle Sessions eines Users beendet |

### Mitglieder

| `action` | Wann |
|----------|------|
| `user_create` | Neuer User (manuell oder CSV) |
| `user_update` | Stammdaten-Aenderung |
| `user_delete` | Soft-Delete |
| `user_restore` | Reaktivierung |
| `user_anonymize` | Nach Loeschfrist |
| `role_assign` | Rolle hinzugefuegt |
| `role_revoke` | Rolle entzogen |
| `invite_send` | Einladungs-E-Mail versandt |

### Arbeitsstunden

| `action` | Wann |
|----------|------|
| `entry_create` | Neuer Antrag |
| `entry_update` | Antrag editiert (im Entwurf) |
| `entry_submit` | Eingereicht |
| `entry_approve` | Pruefer gibt frei |
| `entry_reject` | Pruefer lehnt ab |
| `entry_ask_question` | Rueckfrage (→ `in_klaerung`) |
| `entry_return_to_draft` | Zurueck zur Ueberarbeitung |
| `entry_cancel` | Stornierung |
| `entry_reactivate` | Aus Storniert wieder Entwurf |
| `entry_correct` | Korrektur nach Freigabe (mit Begruendung) |
| `entry_delete` | Soft-Delete |

### Dialog

| `action` | Wann |
|----------|------|
| `dialog_message` | Neue Nachricht im Dialog |
| `dialog_read` | Nachricht als gelesen markiert (optional, wenn gewuenscht) |

### Kategorien / Admin

| `action` | Wann |
|----------|------|
| `category_create`, `category_update`, `category_deactivate`, `category_activate` | Kategorieverwaltung |
| `settings_update` | Systemeinstellung geaendert |
| `yearly_target_update` | Soll-Stunden-Ziel geaendert |
| `export_csv` | CSV-Export (Scope in `details`) |
| `export_pdf` | PDF-Export (Scope in `details`) |

---

## Pflicht-Felder pro Eintrag

| Feld | Pflicht | Beispiel |
|------|---------|----------|
| `action` | ✅ | `entry_approve` |
| `user_id` | ✅ (ausser Login-Failed ohne User) | 42 |
| `created_at` | ✅ | NOW() (DB-Default) |
| `ip_address` | ✅ | `$_SERVER['REMOTE_ADDR']` |
| `user_agent` | ✅ | `$_SERVER['HTTP_USER_AGENT']` |
| `table_name` | empfohlen | `work_entries` |
| `record_id` | empfohlen | 1234 |
| `old_values` | bei UPDATE | `{"status": "eingereicht"}` |
| `new_values` | bei UPDATE/INSERT | `{"status": "freigegeben"}` |
| `description` | empfohlen | "Antrag freigegeben" |
| `details` | kontextabhaengig | `{"reason": "Begruendung Pruefer"}` |

---

## Beispiel-Aufrufe

```php
// Entry-Approval
$this->audit->log(
    action: 'entry_approve',
    tableName: 'work_entries',
    recordId: $entry->id,
    oldValues: ['status' => $entry->status],
    newValues: ['status' => 'freigegeben'],
    description: 'Antrag freigegeben',
    details: [
        'entry_number' => $entry->entryNumber,
        'hours' => $entry->hours,
    ],
);

// Rueckfrage
$this->audit->log(
    action: 'entry_ask_question',
    tableName: 'work_entries',
    recordId: $entry->id,
    oldValues: ['status' => 'eingereicht'],
    newValues: ['status' => 'in_klaerung'],
    description: 'Rueckfrage vom Pruefer',
    details: ['dialog_message_id' => $msgId],
);

// Rollen-Aenderung
$this->audit->log(
    action: 'role_assign',
    tableName: 'user_roles',
    recordId: $userId,
    newValues: ['role' => 'pruefer'],
    description: "Rolle 'pruefer' zugewiesen an User $userId",
);

// CSV-Export
$this->audit->log(
    action: 'export_csv',
    description: 'CSV-Export durchgefuehrt',
    details: [
        'scope' => 'entries',
        'filter' => ['year' => 2026, 'status' => 'freigegeben'],
        'row_count' => $count,
    ],
);
```

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
        action: 'user_update',
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
- Passwoerter/Secrets in `old_values`/`new_values`/`details`
- Audit-Aufruf auskommentieren "fuer Debugging"
- Audit-Eintrag schreiben BEVOR die Business-Aktion commited ist (falsche Reihenfolge)
