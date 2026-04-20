-- =============================================================================
-- Migration 007: Optimistic Locking fuer Event-Tabellen (Modul 7 I3)
-- =============================================================================
--
-- Fuehrt eine monoton steigende version-Spalte auf events, event_tasks und
-- event_task_assignments ein. Analog zu work_entries.version wird damit die
-- gleiche Optimistic-Locking-Strategie auf den Event-Stack ausgerollt:
-- jedes UPDATE muss die gelesene version mitfuehren, sonst schlaegt es fehl.
--
-- Defaultwert 1, damit Bestandszeilen sofort valide sind. Idempotent via
-- INFORMATION_SCHEMA-Check (analog 004/005/006).
-- =============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- events.version
-- ---------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'events'
      AND COLUMN_NAME = 'version'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE events
        ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1
            COMMENT ''Optimistic-Locking-Counter (wird bei jedem Write inkrementiert)''
            AFTER updated_at',
    'SELECT ''events.version existiert bereits'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- event_tasks.version
-- ---------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_tasks'
      AND COLUMN_NAME = 'version'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE event_tasks
        ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1
            COMMENT ''Optimistic-Locking-Counter (wird bei jedem Write inkrementiert)''
            AFTER updated_at',
    'SELECT ''event_tasks.version existiert bereits'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- event_task_assignments.version
-- ---------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_task_assignments'
      AND COLUMN_NAME = 'version'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE event_task_assignments
        ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1
            COMMENT ''Optimistic-Locking-Counter (wird bei jedem Write inkrementiert)''
            AFTER updated_at',
    'SELECT ''event_task_assignments.version existiert bereits'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Migration 007 (Modul 7 I3) abgeschlossen' AS status;
