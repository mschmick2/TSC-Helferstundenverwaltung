-- =============================================================================
-- Migration 011: Audit-Denial + Rate-Limit-Settings (Modul 6 I8)
-- =============================================================================
--
-- Zwei Aenderungen in einer Migration, beide zur Vorbereitung von I8
-- (Audit bei Authorization-Denial + Rate-Limit fuer Tree-/Edit-Session-
-- Endpunkte).
--
-- 1) audit_log.action-ENUM erweitert um den 13. Wert 'access_denied'.
--    Der Wert wird von der neuen AuditService::logAccessDenied-Methode
--    (Phase 1) verwendet und bei Rate-Limit-Overflow wiederverwendet
--    (Phase 2, Architect-C13).
--
-- 2) Sechs neue Settings-Keys unter dem Namespace security.* — Grenzen
--    und Fenster fuer das Rate-Limit auf Tree-Actions und Edit-Session-
--    Endpunkte (Architect-C14). Phase 1 definiert die Keys, Phase 2
--    liest sie im RateLimitMiddleware-Setup aus.
--
-- Idempotenz:
-- ENUM-Erweiterung per INFORMATION_SCHEMA-Check auf das Substring
-- 'access_denied' im COLUMN_TYPE — Mehrfach-Ausfuehrung ist harmlos.
-- Settings-Inserts per ON DUPLICATE KEY UPDATE (Pattern aus Migration
-- 009/010).
-- =============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1) audit_log.action um 'access_denied' erweitern
-- ---------------------------------------------------------------------------
SET @col_def := (
    SELECT COLUMN_TYPE
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'audit_log'
      AND COLUMN_NAME = 'action'
);
SET @needs_update := IF(@col_def LIKE '%''access_denied''%', 0, 1);
SET @sql := IF(@needs_update = 1,
    'ALTER TABLE audit_log MODIFY COLUMN action ENUM(
        ''create'', ''update'', ''delete'', ''restore'',
        ''login'', ''logout'', ''login_failed'',
        ''status_change'', ''export'', ''import'',
        ''config_change'', ''dialog_message'',
        ''access_denied''
    ) NOT NULL',
    'SELECT ''audit_log.action enthaelt bereits access_denied'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2) Rate-Limit-Settings (additiv, idempotent via ON DUPLICATE KEY)
-- ---------------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value, setting_type, description, is_public)
VALUES
    ('security.tree_action_rate_limit_max', '60', 'integer',
     'Maximale Tree-Action-Requests pro Fenster und User (Modul 6 I8). Phase 2 liest den Wert.', FALSE),
    ('security.tree_action_rate_limit_window', '60', 'integer',
     'Rate-Limit-Fenster fuer Tree-Actions in Sekunden. Default 60 s.', FALSE),
    -- Heartbeat-Default: 8/min (30-s-Polling = 2/min Normal, 4x Puffer fuer
    -- Netzwerk-Hiccups und clientseitige Retry-Loops). Wurde nach G4-Review
    -- I8 von 4/min auf 8/min angehoben (FU-I8-G4-3); 4x Puffer vermeidet
    -- user-sichtbare Session-Abbrueche bei flakigem Netzwerk.
    ('security.edit_session_heartbeat_rate_limit_max', '8', 'integer',
     'Maximale Edit-Session-Heartbeats pro Fenster und User. Polling-Intervall ist 30 s, 8 erlaubt 4x Puffer.', FALSE),
    ('security.edit_session_heartbeat_rate_limit_window', '60', 'integer',
     'Rate-Limit-Fenster fuer Heartbeats in Sekunden. Default 60 s.', FALSE),
    ('security.edit_session_other_rate_limit_max', '10', 'integer',
     'Maximale Edit-Session-Start/Close-Requests pro Fenster und User. Start/Close sind punktuell.', FALSE),
    ('security.edit_session_other_rate_limit_window', '60', 'integer',
     'Rate-Limit-Fenster fuer Edit-Session-Start/Close in Sekunden. Default 60 s.', FALSE)
ON DUPLICATE KEY UPDATE setting_key = setting_key;

SELECT 'Migration 011 (Audit-Denial + Rate-Limit-Settings) abgeschlossen' AS status;
