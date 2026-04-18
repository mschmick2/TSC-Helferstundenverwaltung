-- =============================================================================
-- Rollback Migration 005: Kalender + iCal-Export entfernen
-- =============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Unique-Index auf users.ical_token droppen
-- ---------------------------------------------------------------------------
SET @idx_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'uniq_users_ical_token'
);
SET @sql := IF(@idx_exists > 0,
    'ALTER TABLE users DROP INDEX uniq_users_ical_token',
    'SELECT ''uniq_users_ical_token nicht vorhanden'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- users.ical_token droppen
-- ---------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'ical_token'
);
SET @sql := IF(@col_exists > 0,
    'ALTER TABLE users DROP COLUMN ical_token',
    'SELECT ''users.ical_token nicht vorhanden'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- categories.color droppen
-- ---------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'categories'
      AND COLUMN_NAME = 'color'
);
SET @sql := IF(@col_exists > 0,
    'ALTER TABLE categories DROP COLUMN color',
    'SELECT ''categories.color nicht vorhanden'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Rollback Migration 005 abgeschlossen' AS status;
