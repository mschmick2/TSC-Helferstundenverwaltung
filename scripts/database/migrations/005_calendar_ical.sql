-- =============================================================================
-- Migration 005: Kalender + iCal-Export (Modul 6 I5)
-- =============================================================================
--
-- Neue Spalten:
--   - users.ical_token            CHAR(64) UNIQUE NULL
--       Hex-Token fuer persoenliches iCal-Abo (/ical/subscribe/{token}).
--       Wird lazy beim ersten Aufruf von /profile/ical gesetzt.
--   - categories.color            VARCHAR(7) NOT NULL DEFAULT '#0d6efd'
--       Hex-Farbcode (#RRGGBB) fuer Kalender-Darstellung.
--
-- Idempotent via INFORMATION_SCHEMA-Check (analog Migration 004).
-- =============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- users.ical_token
-- ---------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'ical_token'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE users
        ADD COLUMN ical_token CHAR(64) NULL
            COMMENT ''Hex-Token (bin2hex(random_bytes(32))) fuer /ical/subscribe/{token}''',
    'SELECT ''users.ical_token existiert bereits'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Unique-Index auf ical_token (verhindert Kollision, ermoeglicht Reverse-Lookup)
SET @idx_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'uniq_users_ical_token'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE users ADD UNIQUE INDEX uniq_users_ical_token (ical_token)',
    'SELECT ''uniq_users_ical_token existiert bereits'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- categories.color
-- ---------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'categories'
      AND COLUMN_NAME = 'color'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE categories
        ADD COLUMN color VARCHAR(7) NOT NULL DEFAULT ''#0d6efd''
            COMMENT ''Hex-Farbcode #RRGGBB fuer Kalender-Darstellung''
            AFTER description',
    'SELECT ''categories.color existiert bereits'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Migration 005 (Modul 6 I5) abgeschlossen' AS status;
