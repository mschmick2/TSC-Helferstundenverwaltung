-- =============================================================================
-- Migration 004: events.source_template_id (Modul 6 I4)
-- =============================================================================
--
-- Fuegt eine nullable FK-Referenz zu dem Template hinzu, aus dem ein Event
-- abgeleitet wurde. Plus die damals aktuelle Version (Snapshot-Zeitpunkt).
--
-- Idempotent via Information-Schema-Check (MySQL 8 unterstuetzt kein
-- "ADD COLUMN IF NOT EXISTS" direkt).
-- =============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Spalte source_template_id
-- ---------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'events'
      AND COLUMN_NAME = 'source_template_id'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE events
        ADD COLUMN source_template_id INT UNSIGNED NULL
            COMMENT ''Template, aus dem dieses Event abgeleitet wurde''
            AFTER cancel_deadline_hours',
    'SELECT ''source_template_id existiert bereits'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Spalte source_template_version
-- ---------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'events'
      AND COLUMN_NAME = 'source_template_version'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE events
        ADD COLUMN source_template_version INT UNSIGNED NULL
            COMMENT ''Version-Snapshot zum Zeitpunkt der Ableitung''
            AFTER source_template_id',
    'SELECT ''source_template_version existiert bereits'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- FK events.source_template_id -> event_templates(id) ON DELETE SET NULL
-- ---------------------------------------------------------------------------
SET @fk_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'events'
      AND CONSTRAINT_NAME = 'fk_events_source_template'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE events
        ADD CONSTRAINT fk_events_source_template FOREIGN KEY (source_template_id)
            REFERENCES event_templates(id) ON DELETE SET NULL',
    'SELECT ''fk_events_source_template existiert bereits'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Index fuer Reverse-Lookup "welche Events aus Template X?"
-- ---------------------------------------------------------------------------
SET @idx_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'events'
      AND INDEX_NAME = 'idx_events_source_template'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE events ADD INDEX idx_events_source_template (source_template_id)',
    'SELECT ''idx_events_source_template existiert bereits'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Migration 004 (Modul 6 I4) abgeschlossen' AS status;
