-- =============================================================================
-- Migration 002 DOWN: Modul 6 Rollback
-- =============================================================================
-- ACHTUNG: Destruktiv. Alle Event-Daten gehen verloren.
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- Tabellen in FK-Reihenfolge droppen
DROP TABLE IF EXISTS event_template_tasks;
DROP TABLE IF EXISTS event_templates;

-- work_entries FK + Spalten zurueckbauen (vor event_task_assignments droppen)
ALTER TABLE work_entries DROP FOREIGN KEY fk_we_eta;
ALTER TABLE work_entries DROP INDEX idx_work_entries_eta;
ALTER TABLE work_entries DROP INDEX idx_work_entries_origin;
ALTER TABLE work_entries DROP COLUMN event_task_assignment_id;
ALTER TABLE work_entries DROP COLUMN origin;

DROP TABLE IF EXISTS event_task_assignments;
DROP TABLE IF EXISTS event_tasks;
DROP TABLE IF EXISTS event_organizers;
DROP TABLE IF EXISTS events;

-- Beigaben-Kategorien entfernen (nur Seed-Eintraege, nicht fremde)
DELETE FROM categories WHERE name IN (
    'Beigabe: Kuchen',
    'Beigabe: Salat',
    'Beigabe: Sachspende'
) AND is_contribution = TRUE;

ALTER TABLE categories DROP COLUMN is_contribution;

-- event_admin-Rolle entfernen (user_roles CASCADEd automatisch)
DELETE FROM roles WHERE name = 'event_admin';

-- Feature-Flag loeschen
DELETE FROM settings WHERE setting_key = 'event_module_enabled';

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'Rollback 002 abgeschlossen' AS status;
