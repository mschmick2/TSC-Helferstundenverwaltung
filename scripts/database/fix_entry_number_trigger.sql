-- =============================================================================
-- Fix: Antragsnummer-Automatik wiederherstellen
-- =============================================================================
--
-- Hintergrund
--   `work_entries.entry_number` ist UNIQUE NOT NULL und wird bei INSERT durch
--   einen BEFORE-INSERT-Trigger + Stored Procedure automatisch gefuellt. Wenn
--   die Datenbank ueber einen phpMyAdmin-/mysqldump-Export ohne die Flags
--   `--routines --triggers` aufgebaut wurde, fehlen Trigger und Prozedur. Dann
--   landet beim ersten INSERT ein leerer String als `entry_number` in der
--   Tabelle — der zweite INSERT schlaegt mit "Duplicate entry '' for key
--   'work_entries.entry_number'" fehl.
--
-- Dieses Skript ist idempotent und restauriert den Automatismus.
--
-- Aufruf (PowerShell):
--   Get-Content scripts/database/fix_entry_number_trigger.sql |
--     & "C:\wamp64\bin\mysql\mysql9.1.0\bin\mysql.exe" `
--       -h 127.0.0.1 -u root helferstunden
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1) Leere entry_number-Zeilen aufraeumen (fehlgeschlagene Test-Antraege)
-- -----------------------------------------------------------------------------
DELETE FROM work_entries WHERE entry_number = '';

-- -----------------------------------------------------------------------------
-- 2) Sequenz-Tabelle sicherstellen
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS entry_number_sequence (
    year YEAR PRIMARY KEY,
    last_number INT UNSIGNED DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aktuelles Jahr init, ohne existierenden Stand zu zerstoeren
INSERT IGNORE INTO entry_number_sequence (year, last_number)
VALUES (YEAR(CURRENT_DATE), 0);

-- Sequenz auf Max(existierende Antragsnummer im aktuellen Jahr) heben,
-- damit keine Kollision mit bereits vergebenen Nummern entsteht.
UPDATE entry_number_sequence s
SET s.last_number = GREATEST(
    s.last_number,
    COALESCE((
        SELECT MAX(CAST(SUBSTRING_INDEX(entry_number, '-', -1) AS UNSIGNED))
        FROM work_entries
        WHERE entry_number LIKE CONCAT(YEAR(CURRENT_DATE), '-%')
    ), 0)
)
WHERE s.year = YEAR(CURRENT_DATE);

-- -----------------------------------------------------------------------------
-- 3) Stored Procedure neu anlegen
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS generate_entry_number;

DELIMITER //
CREATE PROCEDURE generate_entry_number(OUT new_number VARCHAR(20))
BEGIN
    DECLARE current_year YEAR;
    DECLARE next_seq INT;

    SET current_year = YEAR(CURRENT_DATE);

    INSERT INTO entry_number_sequence (year, last_number)
    VALUES (current_year, 1)
    ON DUPLICATE KEY UPDATE last_number = last_number + 1;

    SELECT last_number INTO next_seq
    FROM entry_number_sequence
    WHERE year = current_year;

    SET new_number = CONCAT(current_year, '-', LPAD(next_seq, 5, '0'));
END //
DELIMITER ;

-- -----------------------------------------------------------------------------
-- 4) BEFORE-INSERT-Trigger neu anlegen
-- -----------------------------------------------------------------------------
DROP TRIGGER IF EXISTS before_work_entry_insert;

DELIMITER //
CREATE TRIGGER before_work_entry_insert
BEFORE INSERT ON work_entries
FOR EACH ROW
BEGIN
    DECLARE new_number VARCHAR(20);

    IF NEW.entry_number IS NULL OR NEW.entry_number = '' THEN
        CALL generate_entry_number(new_number);
        SET NEW.entry_number = new_number;
    END IF;
END //
DELIMITER ;

-- -----------------------------------------------------------------------------
-- 5) Verifikation (zur Kontrolle in der mysql-Konsole)
-- -----------------------------------------------------------------------------
SELECT 'entry_number_sequence' AS check_name, year, last_number
FROM entry_number_sequence
WHERE year = YEAR(CURRENT_DATE);

SELECT 'procedure_exists' AS check_name, ROUTINE_NAME
FROM INFORMATION_SCHEMA.ROUTINES
WHERE ROUTINE_SCHEMA = DATABASE()
  AND ROUTINE_NAME = 'generate_entry_number';

SELECT 'trigger_exists' AS check_name, TRIGGER_NAME, EVENT_MANIPULATION, ACTION_TIMING
FROM INFORMATION_SCHEMA.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND TRIGGER_NAME = 'before_work_entry_insert';
