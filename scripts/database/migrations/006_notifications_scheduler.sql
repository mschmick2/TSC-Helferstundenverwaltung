-- =============================================================================
-- Migration 006: Notifications + Scheduler (Cron-Ersatz) (Modul 6 I6)
-- =============================================================================
--
-- Neue Tabellen:
--   - scheduled_jobs        Job-Queue fuer zeitgesteuerte Aufgaben.
--                           Wird von SchedulerService::runDue() abgearbeitet.
--   - scheduler_runs        Audit der Scheduler-Laeufe (pro HTTP-Trigger).
--
-- Neue Settings-Keys (idempotent via INSERT IGNORE):
--   notifications_enabled           boolean  Feature-Flag (Default: false)
--   cron_external_token_hash        string   SHA-256-Hash des Pinger-Tokens
--   cron_min_interval_seconds       integer  Rate-Limit fuer runDue() (300)
--   cron_last_run_at                string   DATETIME des letzten Runs
--   reminder_days                   integer  Tage bis Dialog-Erinnerung (7)
--
-- Idempotent: CREATE TABLE IF NOT EXISTS + INSERT IGNORE.
-- Rollback: 006_notifications_scheduler.down.sql
-- =============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- scheduled_jobs: Job-Queue
-- ---------------------------------------------------------------------------
-- unique_key erlaubt Deduplizierung (z.B. 'event:42:remind24h'); NULL = nicht
-- dedupliziert. MySQL erlaubt mehrere NULLs in UNIQUE INDEX.
-- Indizes:
--   idx_sj_due       (status, run_at)   -- Haupt-Query: findDue()
--   idx_sj_type      (job_type)         -- Auswertung/Admin-UI
--   uniq_sj_key      (unique_key)       -- Dedup + ON DUPLICATE KEY UPDATE
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS scheduled_jobs (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_type        VARCHAR(64)  NOT NULL
        COMMENT 'z.B. event_reminder_24h, assignment_invite, dialog_reminder',
    unique_key      VARCHAR(191) NULL
        COMMENT 'Optionaler Dedup-Schluessel (z.B. event:42:remind24h)',
    payload         JSON         NULL
        COMMENT 'Job-spezifische Daten (z.B. event_id, user_id)',
    run_at          DATETIME     NOT NULL
        COMMENT 'Frueheste Ausfuehrungszeit (serverlokal)',
    status          ENUM('pending','running','done','failed','cancelled')
                    NOT NULL DEFAULT 'pending',
    attempts        INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts    INT UNSIGNED NOT NULL DEFAULT 3,
    last_error      TEXT         NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at      DATETIME     NULL,
    finished_at     DATETIME     NULL,

    INDEX idx_sj_due    (status, run_at),
    INDEX idx_sj_type   (job_type),
    UNIQUE KEY uniq_sj_key (unique_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- scheduler_runs: Betriebs-Log der Scheduler-Laeufe
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS scheduler_runs (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trigger_source  ENUM('external','request','manual') NOT NULL,
    started_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at     DATETIME     NULL,
    jobs_processed  INT UNSIGNED NOT NULL DEFAULT 0,
    jobs_failed     INT UNSIGNED NOT NULL DEFAULT 0,
    ip_address      VARCHAR(45)  NULL
        COMMENT 'Nur bei trigger_source=external gesetzt',

    INDEX idx_sr_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Settings-Seeds (INSERT IGNORE auf UNIQUE setting_key)
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO settings (setting_key, setting_value, setting_type, description, is_public) VALUES
('notifications_enabled',     'false', 'boolean', 'Feature-Flag: E-Mail-Benachrichtigungen aktiv', FALSE),
('cron_external_token_hash',  NULL,    'string',  'SHA-256-Hash des externen Cron-Pinger-Tokens (leer = deaktiviert)', FALSE),
('cron_min_interval_seconds', '300',   'integer', 'Minimales Intervall zwischen Scheduler-Laeufen in Sekunden', FALSE),
('cron_last_run_at',          NULL,    'string',  'Zeitstempel des letzten erfolgreichen Scheduler-Laufs', FALSE),
('reminder_days',             '7',     'integer', 'Tage bis zur Dialog-Erinnerung ohne Antwort', FALSE);

SELECT 'Migration 006 (Modul 6 I6) abgeschlossen' AS status;
