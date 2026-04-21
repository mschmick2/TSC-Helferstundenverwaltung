-- =============================================================================
-- Rollback Migration 007: Optimistic Locking (Modul 7 I3)
-- =============================================================================
--
-- Entfernt die version-Spalten wieder. Idempotent via INFORMATION_SCHEMA.
-- =============================================================================

SET NAMES utf8mb4;

-- events.version
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'events'
      AND COLUMN_NAME = 'version'
);
SET @sql := IF(@col_exists = 1,
    'ALTER TABLE events DROP COLUMN version',
    'SELECT ''events.version bereits entfernt'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- event_tasks.version
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_tasks'
      AND COLUMN_NAME = 'version'
);
SET @sql := IF(@col_exists = 1,
    'ALTER TABLE event_tasks DROP COLUMN version',
    'SELECT ''event_tasks.version bereits entfernt'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- event_task_assignments.version
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_task_assignments'
      AND COLUMN_NAME = 'version'
);
SET @sql := IF(@col_exists = 1,
    'ALTER TABLE event_task_assignments DROP COLUMN version',
    'SELECT ''event_task_assignments.version bereits entfernt'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Rollback 007 abgeschlossen' AS status;
