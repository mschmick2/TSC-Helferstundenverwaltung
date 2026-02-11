-- =============================================================================
-- Migration 001: Tabelle dialog_read_status erstellen
-- Zweck: Tracking des Lese-Status fuer Dialog-Nachrichten
-- =============================================================================

CREATE TABLE IF NOT EXISTS dialog_read_status (
    user_id INT UNSIGNED NOT NULL,
    work_entry_id INT UNSIGNED NOT NULL,
    last_read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, work_entry_id),
    CONSTRAINT fk_dialog_read_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_dialog_read_entry FOREIGN KEY (work_entry_id)
        REFERENCES work_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
