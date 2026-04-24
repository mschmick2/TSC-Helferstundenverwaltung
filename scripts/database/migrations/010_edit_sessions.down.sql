-- =============================================================================
-- Rollback Migration 010: edit_sessions und Settings-Key entfernen
-- =============================================================================
--
-- Gegenstueck zu 010_edit_sessions.sql. Sessions sind kurzlebig (Lazy-Cleanup
-- haelt die Tabelle effektiv < 1 Stunde), daher kein Daten-Verlust-Risiko beim
-- DROP. Der Settings-Key wird entfernt; das Feature ist danach nicht mehr
-- aktivierbar.
-- =============================================================================

SET NAMES utf8mb4;

DROP TABLE IF EXISTS edit_sessions;

DELETE FROM settings WHERE setting_key = 'events.edit_sessions_enabled';

SELECT 'Rollback 010 (edit_sessions) abgeschlossen' AS status;
