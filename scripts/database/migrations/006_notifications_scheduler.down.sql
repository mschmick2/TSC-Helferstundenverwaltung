-- =============================================================================
-- Rollback Migration 006: Notifications + Scheduler entfernen
-- =============================================================================
-- WARNUNG: Loescht alle pending/failed Jobs und die Run-Historie.
--          Vor Rollback ggf. scheduler_runs exportieren fuer Nachvollzug.
-- =============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Settings-Keys entfernen
-- ---------------------------------------------------------------------------
DELETE FROM settings WHERE setting_key IN (
    'notifications_enabled',
    'cron_external_token_hash',
    'cron_min_interval_seconds',
    'cron_last_run_at',
    'reminder_days'
);

-- ---------------------------------------------------------------------------
-- Tabellen droppen
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS scheduler_runs;
DROP TABLE IF EXISTS scheduled_jobs;

SELECT 'Rollback Migration 006 abgeschlossen' AS status;
