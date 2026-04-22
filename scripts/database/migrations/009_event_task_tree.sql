-- =============================================================================
-- Migration 009: Hierarchischer Aufgabenbaum fuer event_tasks und event_template_tasks
-- =============================================================================
--
-- Erweitert beide Task-Tabellen um eine Adjacency-List-Hierarchie aus Gruppen-
-- knoten und Leaf-Tasks. Bestehende Zeilen bleiben rueckwaertskompatibel:
--   parent_task_id = NULL und is_group = 0 ergibt das bisherige flache Verhalten.
--
-- Schema-Delta pro Tabelle:
--   - parent_task_id (Self-FK ON DELETE RESTRICT — Tree-Aufraeumen erfolgt
--     bewusst via Service, nicht implizit durch DB)
--   - is_group (TINYINT(1), Default 0)
--   - Index (parent_task_id, sort_order) fuer Geschwister-Lookup
--   - slot_mode wird NULLable (Gruppen haben keinen Slot-Modus)
--
-- Invarianten als CHECK-Constraints:
--   - chk_et_fix_times wird ersetzt: greift nur fuer Leaves (is_group = 0)
--   - chk_et_group_shape: Gruppen muessen "leer" sein (slot_mode IS NULL,
--     capacity_mode='unbegrenzt', capacity_target IS NULL, hours_default = 0,
--     task_type = 'aufgabe' als Sentinel)
--   - chk_ett_group_shape: identische Shape-Pflicht fuer Templates
--
-- Settings (additiv):
--   - events.tree_editor_enabled (boolean, Default '0') — Feature-Flag
--   - events.tree_max_depth (integer, Default '4') — Service-enforced
--
-- Idempotenz: jede Aenderung ist via INFORMATION_SCHEMA-Check abgesichert
-- (Pattern aus Migration 008). Mehrfaches Ausfuehren laeuft sauber durch.
-- Plattform: MySQL 8.0.16+ (CHECK-Constraints werden erzwungen) und MariaDB
-- 10.x (INFORMATION_SCHEMA-Pattern portabel).
-- =============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- event_tasks.parent_task_id
-- ---------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_tasks'
      AND COLUMN_NAME = 'parent_task_id'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE event_tasks
        ADD COLUMN parent_task_id INT UNSIGNED NULL
            COMMENT ''Self-FK fuer hierarchischen Aufgabenbaum (Adjacency List). NULL = Top-Level.''
            AFTER event_id',
    'SELECT ''event_tasks.parent_task_id existiert bereits'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- event_tasks.is_group
-- ---------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_tasks'
      AND COLUMN_NAME = 'is_group'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE event_tasks
        ADD COLUMN is_group TINYINT(1) NOT NULL DEFAULT 0
            COMMENT ''1 = Gruppenknoten (kein Helferbedarf, keine Zuweisungen, darf Kinder haben). 0 = Leaf.''
            AFTER parent_task_id',
    'SELECT ''event_tasks.is_group existiert bereits'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- event_tasks.slot_mode auf NULLable umstellen (Gruppen haben keinen Slot-Modus)
-- ---------------------------------------------------------------------------
SET @is_nullable := (
    SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_tasks'
      AND COLUMN_NAME = 'slot_mode'
);
SET @sql := IF(@is_nullable = 'NO',
    'ALTER TABLE event_tasks
        MODIFY COLUMN slot_mode ENUM(''fix'', ''variabel'') NULL DEFAULT ''fix''
            COMMENT ''NULL bei Gruppenknoten (is_group=1), sonst Slot-Modus des Leafs''',
    'SELECT ''event_tasks.slot_mode ist bereits NULLable'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Self-FK fk_et_parent (ON DELETE RESTRICT, siehe Header)
-- ---------------------------------------------------------------------------
SET @fk_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_tasks'
      AND CONSTRAINT_NAME = 'fk_et_parent'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE event_tasks
        ADD CONSTRAINT fk_et_parent FOREIGN KEY (parent_task_id)
            REFERENCES event_tasks(id) ON DELETE RESTRICT ON UPDATE RESTRICT',
    'SELECT ''fk_et_parent existiert bereits'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Index fuer Geschwister-Lookup (parent_task_id, sort_order)
-- ---------------------------------------------------------------------------
SET @idx_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_tasks'
      AND INDEX_NAME = 'idx_et_parent_sort'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE event_tasks
        ADD INDEX idx_et_parent_sort (parent_task_id, sort_order)',
    'SELECT ''idx_et_parent_sort existiert bereits'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- chk_et_fix_times ersetzen: greift nur fuer Leaves
-- ---------------------------------------------------------------------------
SET @chk_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.CHECK_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND CONSTRAINT_NAME = 'chk_et_fix_times'
);
SET @sql := IF(@chk_exists > 0,
    'ALTER TABLE event_tasks DROP CHECK chk_et_fix_times',
    'SELECT ''chk_et_fix_times existiert nicht, ueberspringe DROP'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE event_tasks
    ADD CONSTRAINT chk_et_fix_times CHECK (
        is_group = 1
        OR slot_mode = 'variabel'
        OR (slot_mode = 'fix'
            AND start_at IS NOT NULL
            AND end_at IS NOT NULL
            AND end_at > start_at)
    );

-- ---------------------------------------------------------------------------
-- chk_et_group_shape: Gruppen muessen "leer" sein
-- ---------------------------------------------------------------------------
SET @chk_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.CHECK_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND CONSTRAINT_NAME = 'chk_et_group_shape'
);
SET @sql := IF(@chk_exists = 0,
    'ALTER TABLE event_tasks
        ADD CONSTRAINT chk_et_group_shape CHECK (
            is_group = 0
            OR (slot_mode IS NULL
                AND capacity_mode = ''unbegrenzt''
                AND capacity_target IS NULL
                AND hours_default = 0
                AND task_type = ''aufgabe'')
        )',
    'SELECT ''chk_et_group_shape existiert bereits'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ===========================================================================
-- event_template_tasks — analoge Erweiterung
-- ===========================================================================

-- ---------------------------------------------------------------------------
-- event_template_tasks.parent_template_task_id
-- ---------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_template_tasks'
      AND COLUMN_NAME = 'parent_template_task_id'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE event_template_tasks
        ADD COLUMN parent_template_task_id INT UNSIGNED NULL
            COMMENT ''Self-FK fuer Template-Baum (Adjacency List). NULL = Top-Level.''
            AFTER template_id',
    'SELECT ''event_template_tasks.parent_template_task_id existiert bereits'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- event_template_tasks.is_group
-- ---------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_template_tasks'
      AND COLUMN_NAME = 'is_group'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE event_template_tasks
        ADD COLUMN is_group TINYINT(1) NOT NULL DEFAULT 0
            COMMENT ''1 = Gruppenknoten, 0 = Leaf''
            AFTER parent_template_task_id',
    'SELECT ''event_template_tasks.is_group existiert bereits'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- event_template_tasks.slot_mode auf NULLable umstellen
-- ---------------------------------------------------------------------------
SET @is_nullable := (
    SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_template_tasks'
      AND COLUMN_NAME = 'slot_mode'
);
SET @sql := IF(@is_nullable = 'NO',
    'ALTER TABLE event_template_tasks
        MODIFY COLUMN slot_mode ENUM(''fix'', ''variabel'') NULL DEFAULT ''fix''
            COMMENT ''NULL bei Gruppenknoten''',
    'SELECT ''event_template_tasks.slot_mode ist bereits NULLable'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Self-FK fk_ett_parent (ON DELETE RESTRICT)
-- ---------------------------------------------------------------------------
SET @fk_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_template_tasks'
      AND CONSTRAINT_NAME = 'fk_ett_parent'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE event_template_tasks
        ADD CONSTRAINT fk_ett_parent FOREIGN KEY (parent_template_task_id)
            REFERENCES event_template_tasks(id) ON DELETE RESTRICT ON UPDATE RESTRICT',
    'SELECT ''fk_ett_parent existiert bereits'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Index fuer Geschwister-Lookup (parent_template_task_id, sort_order)
-- ---------------------------------------------------------------------------
SET @idx_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_template_tasks'
      AND INDEX_NAME = 'idx_ett_parent_sort'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE event_template_tasks
        ADD INDEX idx_ett_parent_sort (parent_template_task_id, sort_order)',
    'SELECT ''idx_ett_parent_sort existiert bereits'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- chk_ett_group_shape (Templates haben keinen chk_ett_fix_times-Aequivalent,
-- weil Offsets bei Templates auch fuer Leaves NULL sein duerfen).
-- ---------------------------------------------------------------------------
SET @chk_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.CHECK_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND CONSTRAINT_NAME = 'chk_ett_group_shape'
);
SET @sql := IF(@chk_exists = 0,
    'ALTER TABLE event_template_tasks
        ADD CONSTRAINT chk_ett_group_shape CHECK (
            is_group = 0
            OR (slot_mode IS NULL
                AND capacity_mode = ''unbegrenzt''
                AND capacity_target IS NULL
                AND hours_default = 0
                AND task_type = ''aufgabe''
                AND default_offset_minutes_start IS NULL
                AND default_offset_minutes_end IS NULL)
        )',
    'SELECT ''chk_ett_group_shape existiert bereits'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ===========================================================================
-- Settings — Feature-Flag und Maximaltiefe (additiv, idempotent)
-- ===========================================================================

INSERT INTO settings (setting_key, setting_value, setting_type, description, is_public)
VALUES
    ('events.tree_editor_enabled', '0', 'boolean',
     'Aufgabenbaum-Editor freigeschaltet (Modul 6 I7). 0 = aus, 1 = an.', FALSE),
    ('events.tree_max_depth', '4', 'integer',
     'Maximale Tiefe des Aufgabenbaums (Service-enforced, ohne Wurzel-Event).', FALSE)
ON DUPLICATE KEY UPDATE setting_key = setting_key;

SELECT 'Migration 009 (Aufgabenbaum-Schema) abgeschlossen' AS status;
