-- =============================================================================
-- Migration 010: Edit-Session-Tracking fuer UX-Hinweise (Modul 6 I7e-C.1)
-- =============================================================================
--
-- Legt eine neue Tabelle edit_sessions an. Zweck: Koordinations-Info fuer
-- Editor-Nutzer ("Max Mustermann bearbeitet dieses Event seit 3 Minuten").
-- KEIN Daten-Integritaets-Schutz — das ist Aufgabe des Optimistic Lock aus
-- Migration 007. Hier geht es nur um UX-Transparenz.
--
-- Retention: Lazy-Cleanup ueber EditSessionRepository::cleanupStale(). Kein
-- Cron noetig (Strato). Ziel: closed_at IS NOT NULL -> sofort entfernbar,
-- dangling Sessions (last_seen_at > 1 Stunde alt) -> ebenfalls.
--
-- Feature-Flag: events.edit_sessions_enabled (Default 0). Hart gekoppelt an
-- events.tree_editor_enabled im SettingsService::editSessionsEnabled().
--
-- Idempotenz: INFORMATION_SCHEMA-Pattern wie in Migration 009. Mehrfaches
-- Ausfuehren laeuft sauber durch.
-- =============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- edit_sessions anlegen
-- ---------------------------------------------------------------------------
SET @table_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'edit_sessions'
);
SET @sql := IF(@table_exists = 0,
    'CREATE TABLE edit_sessions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        event_id INT UNSIGNED NOT NULL,
        browser_session_id VARCHAR(64) NOT NULL
            COMMENT ''Pro Tab/Browser eindeutig. Erlaubt Multi-Device desselben Users.'',
        started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        closed_at DATETIME NULL,
        PRIMARY KEY (id),
        INDEX idx_edit_sessions_event_last_seen (event_id, last_seen_at),
        INDEX idx_edit_sessions_user_event (user_id, event_id),
        CONSTRAINT fk_edit_sessions_user
            FOREIGN KEY (user_id) REFERENCES users(id)
            ON DELETE CASCADE,
        CONSTRAINT fk_edit_sessions_event
            FOREIGN KEY (event_id) REFERENCES events(id)
            ON DELETE CASCADE
    ) ENGINE=InnoDB
      DEFAULT CHARSET=utf8mb4
      COLLATE=utf8mb4_unicode_ci
      COMMENT=''Edit-Session-Tracking fuer UX-Hinweis (Modul 6 I7e-C.1). Kurzlebig, Lazy-Cleanup.''',
    'SELECT ''edit_sessions existiert bereits'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Settings-Flag events.edit_sessions_enabled (additiv, idempotent)
-- ---------------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value, setting_type, description, is_public)
VALUES
    ('events.edit_sessions_enabled', '0', 'boolean',
     'Zeigt Hinweis, wenn anderer Admin/Organisator gleichzeitig das Event bearbeitet (Modul 6 I7e-C). Nur wirksam wenn events.tree_editor_enabled=1.',
     FALSE)
ON DUPLICATE KEY UPDATE setting_key = setting_key;

SELECT 'Migration 010 (edit_sessions) abgeschlossen' AS status;
