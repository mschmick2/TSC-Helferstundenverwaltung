-- =============================================================================
-- Rollback Migration 008: rate_limits.email entfernen
-- =============================================================================
--
-- Setzt Migration 008 zurueck. Idempotent via INFORMATION_SCHEMA-Check.
-- =============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Index entfernen (vor der Spalte)
-- ---------------------------------------------------------------------------
SET @idx_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rate_limits'
      AND INDEX_NAME = 'idx_rate_limits_email_lookup'
);
SET @sql := IF(@idx_exists > 0,
    'ALTER TABLE rate_limits DROP INDEX idx_rate_limits_email_lookup',
    'SELECT ''idx_rate_limits_email_lookup existiert nicht'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Spalte entfernen
-- ---------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rate_limits'
      AND COLUMN_NAME = 'email'
);
SET @sql := IF(@col_exists > 0,
    'ALTER TABLE rate_limits DROP COLUMN email',
    'SELECT ''rate_limits.email existiert nicht'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Rollback Migration 008 abgeschlossen' AS status;
