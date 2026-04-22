-- =============================================================================
-- Rollback Migration 009: Aufgabenbaum-Schema entfernen
-- =============================================================================
--
-- Setzt Migration 009 zurueck. Wichtig: laeuft NUR durch, wenn keine Tree-
-- Strukturen mehr in der DB liegen (parent_task_id IS NULL und is_group = 0
-- in beiden Tabellen). Andernfalls bricht der Rollback mit SIGNAL 45000 ab,
-- damit kein versehentlicher Datenverlust eintritt.
--
-- Reihenfolge: erst Daten-Pruefung, dann CHECK-Constraints, dann FK, dann
-- Index, dann Spalten, dann slot_mode zurueck auf NOT NULL, zuletzt die
-- Settings-Keys.
-- =============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Daten-Sicherheits-Check: keine Tree-Strukturen mehr in event_tasks?
-- ---------------------------------------------------------------------------
SET @cols_exist := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_tasks'
      AND COLUMN_NAME IN ('parent_task_id', 'is_group')
);
SET @tree_count := 0;
SET @sql := IF(@cols_exist = 2,
    'SELECT COUNT(*) INTO @tree_count FROM event_tasks
        WHERE parent_task_id IS NOT NULL OR is_group = 1',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Daten-Sicherheits-Check: keine Tree-Strukturen mehr in event_template_tasks?
-- ---------------------------------------------------------------------------
SET @cols_exist := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_template_tasks'
      AND COLUMN_NAME IN ('parent_template_task_id', 'is_group')
);
SET @tpl_tree_count := 0;
SET @sql := IF(@cols_exist = 2,
    'SELECT COUNT(*) INTO @tpl_tree_count FROM event_template_tasks
        WHERE parent_template_task_id IS NOT NULL OR is_group = 1',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Abbruch wenn Tree-Daten vorhanden.
-- SIGNAL SQLSTATE ist in MySQL 8.0+ nicht innerhalb von PREPARE/EXECUTE erlaubt
-- (Fehler 1295 "not supported in the prepared statement protocol yet").
-- Daher kurzlebige Stored Procedure als Trampolin: sie liest die oben
-- gefuellten Session-Variablen @tree_count und @tpl_tree_count und wirft
-- bei Bedarf ein Klartext-SIGNAL. DROP PROCEDURE IF EXISTS am Anfang macht
-- den Block idempotent (raeumt eine eventuell hinterlassene Prozedur eines
-- vorigen Abbruchs auf). Konkrete Bereinigungs-Bedingung steht im Kommentar
-- darunter — SIGNAL-MESSAGE_TEXT ist auf 128 Zeichen begrenzt.
-- Vor dem naechsten Down-Lauf bereinigen:
--   DELETE FROM event_tasks          WHERE parent_task_id IS NOT NULL OR is_group = 1;
--   DELETE FROM event_template_tasks WHERE parent_template_task_id IS NOT NULL OR is_group = 1;

DROP PROCEDURE IF EXISTS _migration_009_safety_check;

DELIMITER $$
CREATE PROCEDURE _migration_009_safety_check()
BEGIN
    IF @tree_count > 0 OR @tpl_tree_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Rollback Migration 009 abgebrochen: Tree-Strukturen vorhanden. Bitte event_tasks und event_template_tasks bereinigen.';
    END IF;
END$$
DELIMITER ;

CALL _migration_009_safety_check();
DROP PROCEDURE _migration_009_safety_check;

SELECT 'Datenfreiheit ok, Rollback laeuft.' AS msg;

-- ---------------------------------------------------------------------------
-- event_tasks: CHECK-Constraints zuruecksetzen
-- ---------------------------------------------------------------------------
SET @chk_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.CHECK_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND CONSTRAINT_NAME = 'chk_et_group_shape'
);
SET @sql := IF(@chk_exists > 0,
    'ALTER TABLE event_tasks DROP CHECK chk_et_group_shape',
    'SELECT ''chk_et_group_shape existiert nicht'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- chk_et_fix_times: aktuelle Variante droppen, alte (ohne is_group-Klausel)
-- wieder anlegen
SET @chk_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.CHECK_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND CONSTRAINT_NAME = 'chk_et_fix_times'
);
SET @sql := IF(@chk_exists > 0,
    'ALTER TABLE event_tasks DROP CHECK chk_et_fix_times',
    'SELECT ''chk_et_fix_times existiert nicht'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE event_tasks
    ADD CONSTRAINT chk_et_fix_times CHECK (
        slot_mode = 'variabel'
        OR (start_at IS NOT NULL AND end_at IS NOT NULL AND end_at > start_at)
    );

-- ---------------------------------------------------------------------------
-- event_tasks: Self-FK + Index entfernen
-- ---------------------------------------------------------------------------
SET @fk_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_tasks'
      AND CONSTRAINT_NAME = 'fk_et_parent'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists > 0,
    'ALTER TABLE event_tasks DROP FOREIGN KEY fk_et_parent',
    'SELECT ''fk_et_parent existiert nicht'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_tasks'
      AND INDEX_NAME = 'idx_et_parent_sort'
);
SET @sql := IF(@idx_exists > 0,
    'ALTER TABLE event_tasks DROP INDEX idx_et_parent_sort',
    'SELECT ''idx_et_parent_sort existiert nicht'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- event_tasks: slot_mode zurueck auf NOT NULL
-- ---------------------------------------------------------------------------
SET @is_nullable := (
    SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_tasks'
      AND COLUMN_NAME = 'slot_mode'
);
SET @sql := IF(@is_nullable = 'YES',
    'ALTER TABLE event_tasks
        MODIFY COLUMN slot_mode ENUM(''fix'', ''variabel'') NOT NULL DEFAULT ''fix''',
    'SELECT ''event_tasks.slot_mode ist bereits NOT NULL'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- event_tasks: Spalten droppen
-- ---------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_tasks'
      AND COLUMN_NAME = 'is_group'
);
SET @sql := IF(@col_exists > 0,
    'ALTER TABLE event_tasks DROP COLUMN is_group',
    'SELECT ''event_tasks.is_group existiert nicht'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_tasks'
      AND COLUMN_NAME = 'parent_task_id'
);
SET @sql := IF(@col_exists > 0,
    'ALTER TABLE event_tasks DROP COLUMN parent_task_id',
    'SELECT ''event_tasks.parent_task_id existiert nicht'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ===========================================================================
-- event_template_tasks — analoger Rueckbau
-- ===========================================================================

SET @chk_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.CHECK_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND CONSTRAINT_NAME = 'chk_ett_group_shape'
);
SET @sql := IF(@chk_exists > 0,
    'ALTER TABLE event_template_tasks DROP CHECK chk_ett_group_shape',
    'SELECT ''chk_ett_group_shape existiert nicht'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_template_tasks'
      AND CONSTRAINT_NAME = 'fk_ett_parent'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists > 0,
    'ALTER TABLE event_template_tasks DROP FOREIGN KEY fk_ett_parent',
    'SELECT ''fk_ett_parent existiert nicht'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_template_tasks'
      AND INDEX_NAME = 'idx_ett_parent_sort'
);
SET @sql := IF(@idx_exists > 0,
    'ALTER TABLE event_template_tasks DROP INDEX idx_ett_parent_sort',
    'SELECT ''idx_ett_parent_sort existiert nicht'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @is_nullable := (
    SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_template_tasks'
      AND COLUMN_NAME = 'slot_mode'
);
SET @sql := IF(@is_nullable = 'YES',
    'ALTER TABLE event_template_tasks
        MODIFY COLUMN slot_mode ENUM(''fix'', ''variabel'') NOT NULL DEFAULT ''fix''',
    'SELECT ''event_template_tasks.slot_mode ist bereits NOT NULL'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_template_tasks'
      AND COLUMN_NAME = 'is_group'
);
SET @sql := IF(@col_exists > 0,
    'ALTER TABLE event_template_tasks DROP COLUMN is_group',
    'SELECT ''event_template_tasks.is_group existiert nicht'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_template_tasks'
      AND COLUMN_NAME = 'parent_template_task_id'
);
SET @sql := IF(@col_exists > 0,
    'ALTER TABLE event_template_tasks DROP COLUMN parent_template_task_id',
    'SELECT ''event_template_tasks.parent_template_task_id existiert nicht'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ===========================================================================
-- Settings entfernen
-- ===========================================================================

DELETE FROM settings WHERE setting_key IN ('events.tree_editor_enabled', 'events.tree_max_depth');

SELECT 'Rollback Migration 009 abgeschlossen' AS status;
