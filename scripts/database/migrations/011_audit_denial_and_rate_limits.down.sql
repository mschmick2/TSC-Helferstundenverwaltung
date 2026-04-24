-- =============================================================================
-- Rollback Migration 011: ENUM-Wert 'access_denied' + Rate-Limit-Settings weg
-- =============================================================================
--
-- Achtung: der ENUM-Rollback verliert Audit-Zeilen, wenn Zeilen mit
-- action='access_denied' existieren. Im MySQL-Default-SQL-Mode akzeptiert
-- ALTER TABLE ... MODIFY ENUM den Wechsel klaglos und setzt betroffene
-- Zeilen stillschweigend auf Leerstring -- kein Fail, kein Warning.
-- G9-Test zu Modul 6 I8 (2026-04-24) hat das verifiziert.
--
-- Deshalb enthaelt dieses Skript einen harten Guard am Anfang: wenn
-- `access_denied`-Zeilen gefunden werden, wird per SIGNAL SQLSTATE 45000
-- abgebrochen, bevor der ALTER ueberhaupt laeuft. Der Operator muss
-- VORHER bewusst entscheiden:
--    DELETE FROM audit_log WHERE action='access_denied';
-- oder
--    UPDATE audit_log SET action='config_change' WHERE action='access_denied';
-- (Die erste Variante loescht die Zeilen, die zweite biegt den ENUM-Wert
-- auf einen harmlosen Bestandswert um -- je nach Audit-Policy des
-- Operators.)
-- =============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 0) Guard: bei vorhandenen access_denied-Zeilen abbrechen, bevor der
--    ENUM-ALTER stillschweigend Audit-Zeilen ueberschreibt.
-- ---------------------------------------------------------------------------
SET @access_denied_rows := (
    SELECT COUNT(*) FROM audit_log WHERE action = 'access_denied'
);
SET @sql := IF(@access_denied_rows > 0,
    'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''Rollback 011 abgebrochen: audit_log enthaelt Zeilen mit action=access_denied. Vorher DELETE oder UPDATE ausfuehren (siehe Kommentar oben).''',
    'SELECT ''Guard 0: kein access_denied-Wert in Verwendung -- Rollback kann fortgesetzt werden.'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 1) audit_log.action auf Bestand zurueckfuehren (12 Werte, ohne
--    'access_denied')
-- ---------------------------------------------------------------------------
SET @col_def := (
    SELECT COLUMN_TYPE
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'audit_log'
      AND COLUMN_NAME = 'action'
);
SET @needs_update := IF(@col_def LIKE '%''access_denied''%', 1, 0);
SET @sql := IF(@needs_update = 1,
    'ALTER TABLE audit_log MODIFY COLUMN action ENUM(
        ''create'', ''update'', ''delete'', ''restore'',
        ''login'', ''logout'', ''login_failed'',
        ''status_change'', ''export'', ''import'',
        ''config_change'', ''dialog_message''
    ) NOT NULL',
    'SELECT ''audit_log.action enthaelt access_denied nicht mehr'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2) Rate-Limit-Settings entfernen
-- ---------------------------------------------------------------------------
DELETE FROM settings WHERE setting_key IN (
    'security.tree_action_rate_limit_max',
    'security.tree_action_rate_limit_window',
    'security.edit_session_heartbeat_rate_limit_max',
    'security.edit_session_heartbeat_rate_limit_window',
    'security.edit_session_other_rate_limit_max',
    'security.edit_session_other_rate_limit_window'
);

SELECT 'Rollback 011 (Audit-Denial + Rate-Limit-Settings) abgeschlossen' AS status;
