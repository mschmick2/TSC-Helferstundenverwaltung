-- =============================================================================
-- Rollback Migration 011: ENUM-Wert 'access_denied' + Rate-Limit-Settings weg
-- =============================================================================
--
-- Achtung: der ENUM-Rollback schlaegt fehl, wenn Zeilen mit
-- action='access_denied' existieren. Das ist erwuenscht — der Auditor
-- soll keine Audit-Zeilen verlieren. Vor dem Down:
--    DELETE FROM audit_log WHERE action='access_denied';
-- ausfuehren ist ein bewusster Zusatzschritt.
-- =============================================================================

SET NAMES utf8mb4;

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
