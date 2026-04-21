-- =============================================================================
-- prod-reset-for-golive.sql
-- =============================================================================
--
-- Wird EINMALIG beim Wechsel von Test- in Produktionsbetrieb ausgefuehrt.
-- Loescht alle operationalen Daten, behaelt Struktur + Konfiguration +
-- Administrator-Accounts.
--
-- ACHTUNG: DESTRUKTIV. Vor Ausfuehrung Backup anlegen!
--          Ausfuehren z.B. ueber phpMyAdmin gegen die Prod-DB.
--
-- Erhalten bleiben:
--   - users      (nur mit Rolle 'administrator')
--   - roles      (Rollenkatalog)
--   - categories (Kategorien-Stammdaten)
--   - settings   (Systemeinstellungen)
--
-- Geloescht werden:
--   - work_entries, work_entry_dialogs, dialog_read_status
--   - sessions, password_resets, email_verification_codes, user_invitations
--   - entry_locks, rate_limits
--   - yearly_targets (pro Jahr neu zu setzen)
--   - audit_log (rechtlich: vor Release OK, ab Prod-Start append-only)
--   - users ohne Administrator-Rolle
-- =============================================================================

SELECT CONCAT('PROD-RESET gegen DB: ', DATABASE(), '  (', NOW(), ')') AS info;

SET FOREIGN_KEY_CHECKS = 0;

-- --- Transaktionale Tabellen (FK-Reihenfolge) --------------------------------
TRUNCATE TABLE dialog_read_status;
TRUNCATE TABLE work_entry_dialogs;
TRUNCATE TABLE work_entries;
TRUNCATE TABLE entry_locks;
TRUNCATE TABLE entry_number_sequence;
TRUNCATE TABLE yearly_targets;

-- --- Auth-/Session-Transients -----------------------------------------------
TRUNCATE TABLE sessions;
TRUNCATE TABLE password_resets;
TRUNCATE TABLE email_verification_codes;
TRUNCATE TABLE user_invitations;
TRUNCATE TABLE rate_limits;

-- --- Audit-Log wird bei Go-Live gecleared (neu beginnen) --------------------
-- Ab dem Zeitpunkt ist audit_log append-only im Sinne der Compliance.
TRUNCATE TABLE audit_log;

-- --- Nicht-Admin-User entfernen ---------------------------------------------
-- Schritt 1: Alle user_roles-Eintraege von Nicht-Admins finden und Kaskade
DELETE u FROM users u
LEFT JOIN user_roles ur ON u.id = ur.user_id
LEFT JOIN roles      r  ON ur.role_id = r.id AND r.name = 'administrator'
WHERE r.id IS NULL;

-- Schritt 2: Verwaiste user_roles (nach User-Kaskade sollten keine existieren)
DELETE ur FROM user_roles ur
LEFT JOIN users u ON ur.user_id = u.id
WHERE u.id IS NULL;

SET FOREIGN_KEY_CHECKS = 1;

-- --- Bericht ----------------------------------------------------------------
SELECT 'PROD-RESET abgeschlossen' AS status;
SELECT COUNT(*) AS remaining_users       FROM users;
SELECT COUNT(*) AS remaining_admins      FROM users u
    JOIN user_roles ur ON u.id = ur.user_id
    JOIN roles r       ON ur.role_id = r.id AND r.name = 'administrator';
SELECT COUNT(*) AS work_entries_count    FROM work_entries;
SELECT COUNT(*) AS audit_log_count       FROM audit_log;
