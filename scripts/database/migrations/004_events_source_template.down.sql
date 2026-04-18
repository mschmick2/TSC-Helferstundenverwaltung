-- =============================================================================
-- Rollback Migration 004: events.source_template_id entfernen
-- =============================================================================

SET NAMES utf8mb4;

SET @fk_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'events'
      AND CONSTRAINT_NAME = 'fk_events_source_template'
);
SET @sql := IF(@fk_exists > 0,
    'ALTER TABLE events DROP FOREIGN KEY fk_events_source_template',
    'SELECT ''FK fk_events_source_template nicht vorhanden'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'events'
      AND INDEX_NAME = 'idx_events_source_template'
);
SET @sql := IF(@idx_exists > 0,
    'ALTER TABLE events DROP INDEX idx_events_source_template',
    'SELECT ''Index idx_events_source_template nicht vorhanden'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE events DROP COLUMN IF EXISTS source_template_version;
ALTER TABLE events DROP COLUMN IF EXISTS source_template_id;

SELECT 'Rollback Migration 004 abgeschlossen' AS status;
