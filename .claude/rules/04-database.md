# 🗄️ Rules: Database (MySQL 8.4 / PDO — VAES)

Geladen von: `coder.md` (G2), bei Bedarf `architect.md` (G1) bei Schema-Aenderungen.

---

## PDO-Connection-Policy

```php
$pdo = new PDO(
    "mysql:host=$host;dbname=$name;charset=utf8mb4",
    $user,
    $pass,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,     // Pflicht
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,                       // echte Prepared Statements
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]
);
```

- Charset: `utf8mb4` — fuer Emojis und volle Unicode-Unterstuetzung
- `EMULATE_PREPARES => false` — verhindert Typ-Verwirrung bei Prepared Statements

---

## Prepared Statements — IMMER

**DO:**
```php
$stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email');
$stmt->execute(['email' => $email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
```

**DON'T:**
```php
$pdo->query("SELECT * FROM users WHERE email = '$email'");
$pdo->query("... WHERE id = " . $id);
```

---

## Soft-Delete — Schema-Konvention

**Alle Business-Tabellen haben:**
```sql
created_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
created_by    INT         NULL,
updated_at    DATETIME    NULL ON UPDATE CURRENT_TIMESTAMP,
updated_by    INT         NULL,
deleted_at    DATETIME    NULL,
deleted_by    INT         NULL
```

**Query-Konvention:**
```php
// Normaler Filter: nur aktive Datensaetze
$sql = 'SELECT * FROM work_entries WHERE user_id = :uid AND deleted_at IS NULL';

// Auditor-Sicht: inkl. geloescht
$sql = 'SELECT * FROM work_entries WHERE user_id = :uid';
```

**Loeschen:**
```php
$stmt = $pdo->prepare(
    'UPDATE work_entries SET deleted_at = NOW(), deleted_by = :actor WHERE id = :id'
);
$stmt->execute(['actor' => $currentUserId, 'id' => $id]);
```

**Niemals:**
```php
$pdo->exec("DELETE FROM work_entries WHERE id = $id");  // NIE!
```

---

## Audit-Log — append-only

```sql
CREATE TABLE audit_log (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NULL,
    action      VARCHAR(64) NOT NULL,
    table_name  VARCHAR(64) NULL,
    record_id   INT NULL,
    old_values  JSON NULL,
    new_values  JSON NULL,
    description TEXT NULL,
    details     JSON NULL,
    ip_address  VARCHAR(45) NULL,
    user_agent  TEXT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_action (action),
    INDEX idx_audit_table_record (table_name, record_id),
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB;

-- Trigger gegen Manipulation
DELIMITER $$
CREATE TRIGGER audit_no_update BEFORE UPDATE ON audit_log
FOR EACH ROW BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only';
END$$

CREATE TRIGGER audit_no_delete BEFORE DELETE ON audit_log
FOR EACH ROW BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only';
END$$
DELIMITER ;
```

---

## Migrations — Regeln

- **Idempotent:** `CREATE TABLE IF NOT EXISTS ...`, `DROP TABLE IF EXISTS ...`
- **Rollback-SQL mitliefern** (als Kommentar oder separates `*.down.sql`)
- **Keine Daten-Verlust-Migrationen** ohne Backup-Hinweis
- Reihenfolge: Schema → Daten → ggf. Constraints

**Beispiel:**
```sql
-- migrations/2026_04_17_add_dialog_read_status.sql

CREATE TABLE IF NOT EXISTS dialog_read_status (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    dialog_id       INT NOT NULL,
    user_id         INT NOT NULL,
    read_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_dialog_user (dialog_id, user_id),
    CONSTRAINT fk_drs_dialog FOREIGN KEY (dialog_id)
        REFERENCES dialog_messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_drs_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ROLLBACK: DROP TABLE IF EXISTS dialog_read_status;
```

---

## Foreign Keys

**Konvention:**
- FK-Name: `fk_<short>_<referenced>` (z.B. `fk_we_user`)
- `ON DELETE`:
  - `CASCADE` bei tight coupling (Dialog-Messages → Dialog)
  - `SET NULL` wenn optional (letzter Bearbeiter)
  - `RESTRICT` (Default) wenn Business-Bedeutung hat (Work-Entry → Category)

---

## Indizes

**Pflicht-Indizes:**
- Alle Foreign-Key-Spalten
- Alle Spalten in `WHERE`/`ORDER BY`/`GROUP BY` der Haupt-Queries
- `deleted_at`-Spalten, wenn Soft-Delete

**Zusammengesetzte Indizes — Reihenfolge zaehlt:**
```sql
-- Query: WHERE user_id = ? AND status = ? ORDER BY created_at DESC
CREATE INDEX idx_we_user_status_created
    ON work_entries (user_id, status, created_at);
```

---

## Transaktionen

```php
$pdo->beginTransaction();
try {
    // mehrere Writes
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    throw $e;
}
```

**Wann Transaktion?**
- Mehrere Zeilen in verschiedenen Tabellen, die konsistent sein muessen
- Business-Write + Audit-Log (darf nicht auseinanderfallen)
- Status-Wechsel + Dialog-Eintrag

---

## Datentyp-Konventionen

| Feld | MySQL-Typ | Begruendung |
|------|-----------|-------------|
| IDs | `INT UNSIGNED AUTO_INCREMENT` (oder `BIGINT` bei audit_log) | Performance |
| Status-Strings | `VARCHAR(32)` (wenn nicht ENUM) | Flexibel |
| Datum+Uhrzeit | `DATETIME` (NICHT `TIMESTAMP`) | Zeitzonen-Unabhaengigkeit |
| Nur Datum | `DATE` | — |
| Stunden | `DECIMAL(6,2)` | Exakt, kein Float-Rundungsfehler |
| Geld (falls) | `DECIMAL(10,2)` | s.o. |
| Beschreibung | `TEXT` oder `VARCHAR(500)` | Nach Max-Laenge |
| JSON | `JSON` | Native Validation |
| Bool | `TINYINT(1)` (0/1) | Konvention |

---

## Verbotenes

- `SELECT *` in Produktions-Code (nur fuer Audit/Debug mit Kommentar)
- `DELETE FROM` auf Business-Tabellen
- `UPDATE audit_log` / `DELETE audit_log`
- FK ohne Index auf der Kind-Spalte
- Mehrere NULL-Werte in UNIQUE-Keys (MySQL erlaubt das, kann aber stoeren)
- Float fuer Stunden/Geld
- `ENUM` fuer Status, wenn spaeter erweiterbar sein soll (Migration teuer)
