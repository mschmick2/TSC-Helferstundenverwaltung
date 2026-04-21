-- =============================================================================
-- Migration 002: Modul 6 — Events & Helferplanung (I1 Datenmodell)
-- Zielversion: VAES 1.5.0
-- Architect-Plan: alle 8 Entscheidungen (E1-E8) akzeptiert.
--
-- Ausfuehrung:
--   mysql -uroot helferstunden < scripts/database/migrations/002_module_events.sql
--
-- Rollback:
--   mysql -uroot helferstunden < scripts/database/migrations/002_module_events.down.sql
--
-- ACHTUNG: Vorher auf lokaler Test-Env-Kopie der Prod-DB validieren (E7).
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- E3: Neue Rolle `event_admin`
-- =============================================================================

INSERT IGNORE INTO roles (name, description) VALUES
('event_admin', 'Darf Events anlegen, Organisatoren zuweisen und Event-Templates verwalten');

-- Auto-Zuweisung: alle bestehenden Administratoren bekommen event_admin
INSERT IGNORE INTO user_roles (user_id, role_id, assigned_at)
SELECT
    ur.user_id,
    (SELECT id FROM roles WHERE name = 'event_admin'),
    NOW()
FROM user_roles ur
JOIN roles r ON ur.role_id = r.id
WHERE r.name = 'administrator';

-- =============================================================================
-- E1: Beigaben-Flag + Seed-Kategorien
-- =============================================================================

ALTER TABLE categories
    ADD COLUMN is_contribution BOOLEAN DEFAULT FALSE
        COMMENT 'Beigaben-Kategorie (Kuchen/Salat/Sachspende) - 0 Stunden-Charakter'
        AFTER is_active;

INSERT INTO categories (name, description, sort_order, is_contribution) VALUES
('Beigabe: Kuchen', 'Selbstgebackener Kuchen fuer Vereinsfeste', 200, TRUE),
('Beigabe: Salat', 'Salat, Beilagen fuer Buffet', 210, TRUE),
('Beigabe: Sachspende', 'Nicht-materielle Sachspende (z.B. Getraenke, Dekoration)', 220, TRUE);

-- =============================================================================
-- E8/I3: Metadaten-Spalten in work_entries (Origin + FK zur Assignment)
-- =============================================================================

ALTER TABLE work_entries
    ADD COLUMN origin ENUM('manual', 'event', 'correction') NOT NULL DEFAULT 'manual'
        COMMENT 'Herkunft des Antrags: manual=regulaer, event=auto-generiert, correction=nachtraegliche Korrektur'
        AFTER status,
    ADD COLUMN event_task_assignment_id INT UNSIGNED NULL
        COMMENT 'Verknuepfung zur Event-Task-Zusage, falls origin=event'
        AFTER correction_reason;

ALTER TABLE work_entries
    ADD INDEX idx_work_entries_origin (origin),
    ADD INDEX idx_work_entries_eta (event_task_assignment_id);

-- FK wird am Ende hinzugefuegt, nachdem event_task_assignments existiert (siehe unten).

-- =============================================================================
-- Settings-Toggle fuer Feature-Flag
-- =============================================================================

INSERT INTO settings (setting_key, setting_value, setting_type, description, is_public)
VALUES ('event_module_enabled', 'true', 'boolean', 'Events & Helferplanung-Modul aktiviert', FALSE)
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- =============================================================================
-- events: Veranstaltungen
-- =============================================================================

CREATE TABLE events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    location VARCHAR(500) NULL,
    start_at DATETIME NOT NULL,
    end_at DATETIME NOT NULL,
    status ENUM('entwurf', 'veroeffentlicht', 'abgeschlossen', 'abgesagt') NOT NULL DEFAULT 'entwurf',
    cancel_deadline_hours INT UNSIGNED NULL DEFAULT 24
        COMMENT 'Vorlauf-Stunden bis eigenstaendiger Storno moeglich ist',
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by INT UNSIGNED NULL,

    CONSTRAINT fk_events_created_by FOREIGN KEY (created_by)
        REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_events_deleted_by FOREIGN KEY (deleted_by)
        REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_events_status (status),
    INDEX idx_events_start (start_at),
    INDEX idx_events_deleted (deleted_at),

    -- Invariante: end_at >= start_at (MySQL 8 unterstuetzt CHECK)
    CONSTRAINT chk_events_timespan CHECK (end_at >= start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- event_organizers: n:m Event <-> User
-- =============================================================================

CREATE TABLE event_organizers (
    event_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    assigned_by INT UNSIGNED NULL,

    PRIMARY KEY (event_id, user_id),

    -- Event-Loeschung kaskadiert auf Organizer-Eintraege (Event weg = Zuordnung obsolet).
    -- User-Loeschung darf Organizer-Historie NICHT loeschen (DSGVO G5 D1: Audit-Integritaet).
    -- Bei DSGVO-Loeschrecht muss der User anonymisiert, nicht hart geloescht werden.
    CONSTRAINT fk_eo_event FOREIGN KEY (event_id)
        REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_eo_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_eo_assigned_by FOREIGN KEY (assigned_by)
        REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_eo_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- event_tasks: Aufgaben und Beigaben pro Event
-- =============================================================================

CREATE TABLE event_tasks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    task_type ENUM('aufgabe', 'beigabe') NOT NULL DEFAULT 'aufgabe',
    slot_mode ENUM('fix', 'variabel') NOT NULL DEFAULT 'fix',
    start_at DATETIME NULL COMMENT 'NULL wenn slot_mode=variabel',
    end_at DATETIME NULL,
    capacity_mode ENUM('unbegrenzt', 'ziel', 'maximum') NOT NULL DEFAULT 'unbegrenzt',
    capacity_target INT UNSIGNED NULL COMMENT 'Pflicht bei ziel/maximum',
    hours_default DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    sort_order INT UNSIGNED DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by INT UNSIGNED NULL,

    CONSTRAINT fk_et_event FOREIGN KEY (event_id)
        REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_et_category FOREIGN KEY (category_id)
        REFERENCES categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_et_deleted_by FOREIGN KEY (deleted_by)
        REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_et_event (event_id),
    INDEX idx_et_deleted (deleted_at),
    INDEX idx_et_sort (event_id, sort_order),

    -- Invariante: bei slot_mode=fix muessen Zeiten gesetzt sein und gueltig
    CONSTRAINT chk_et_fix_times CHECK (
        slot_mode = 'variabel'
        OR (start_at IS NOT NULL AND end_at IS NOT NULL AND end_at > start_at)
    ),
    -- Invariante: capacity_target nur bei ziel/maximum
    CONSTRAINT chk_et_capacity CHECK (
        (capacity_mode = 'unbegrenzt' AND capacity_target IS NULL)
        OR (capacity_mode IN ('ziel', 'maximum') AND capacity_target IS NOT NULL AND capacity_target > 0)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- event_task_assignments: Zusagen von Mitgliedern
-- =============================================================================

CREATE TABLE event_task_assignments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    status ENUM('vorgeschlagen', 'bestaetigt', 'storno_angefragt', 'storniert', 'abgeschlossen')
        NOT NULL DEFAULT 'vorgeschlagen',
    proposed_start DATETIME NULL COMMENT 'Nur bei slot_mode=variabel',
    proposed_end DATETIME NULL,
    actual_hours DECIMAL(6,2) NULL COMMENT 'Ueberschreibt task.hours_default bei Abschluss',
    replacement_suggested_user_id INT UNSIGNED NULL COMMENT 'Ersatzvorschlag bei Storno',
    work_entry_id INT UNSIGNED NULL COMMENT 'Nach I3: Referenz auf auto-generierten work_entry',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by INT UNSIGNED NULL,

    CONSTRAINT fk_eta_task FOREIGN KEY (task_id)
        REFERENCES event_tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_eta_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_eta_replacement FOREIGN KEY (replacement_suggested_user_id)
        REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_eta_work_entry FOREIGN KEY (work_entry_id)
        REFERENCES work_entries(id) ON DELETE SET NULL,
    CONSTRAINT fk_eta_deleted_by FOREIGN KEY (deleted_by)
        REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_eta_task (task_id),
    INDEX idx_eta_user (user_id),
    INDEX idx_eta_status (status),
    INDEX idx_eta_deleted (deleted_at),

    -- Invariante: proposed_end > proposed_start wenn beide gesetzt
    CONSTRAINT chk_eta_proposed CHECK (
        proposed_start IS NULL OR proposed_end IS NULL OR proposed_end > proposed_start
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FK von work_entries -> event_task_assignments jetzt nachtraeglich setzen
ALTER TABLE work_entries
    ADD CONSTRAINT fk_we_eta FOREIGN KEY (event_task_assignment_id)
        REFERENCES event_task_assignments(id) ON DELETE SET NULL;

-- =============================================================================
-- event_templates: Aufgaben-Vorlagen mit Versionierung
-- =============================================================================

CREATE TABLE event_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    description TEXT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    parent_template_id INT UNSIGNED NULL COMMENT 'Vorgaenger-Version',
    is_current TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = aktuelle Version',
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by INT UNSIGNED NULL,

    CONSTRAINT fk_etpl_parent FOREIGN KEY (parent_template_id)
        REFERENCES event_templates(id) ON DELETE SET NULL,
    CONSTRAINT fk_etpl_created_by FOREIGN KEY (created_by)
        REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_etpl_deleted_by FOREIGN KEY (deleted_by)
        REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_etpl_current (is_current, name),
    INDEX idx_etpl_deleted (deleted_at),
    INDEX idx_etpl_parent (parent_template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- event_template_tasks: Template-Task-Vorlagen
-- =============================================================================

CREATE TABLE event_template_tasks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    task_type ENUM('aufgabe', 'beigabe') NOT NULL DEFAULT 'aufgabe',
    slot_mode ENUM('fix', 'variabel') NOT NULL DEFAULT 'fix',
    default_offset_minutes_start INT NULL COMMENT 'Offset zu event.start_at in Minuten',
    default_offset_minutes_end INT NULL,
    capacity_mode ENUM('unbegrenzt', 'ziel', 'maximum') NOT NULL DEFAULT 'unbegrenzt',
    capacity_target INT UNSIGNED NULL,
    hours_default DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    sort_order INT UNSIGNED DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_ett_template FOREIGN KEY (template_id)
        REFERENCES event_templates(id) ON DELETE CASCADE,
    CONSTRAINT fk_ett_category FOREIGN KEY (category_id)
        REFERENCES categories(id) ON DELETE SET NULL,

    INDEX idx_ett_template (template_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- Abschluss-Report
-- =============================================================================

SELECT 'Migration 002 (Modul 6 I1) abgeschlossen' AS status;
SELECT COUNT(*) AS event_admin_users FROM user_roles ur
    JOIN roles r ON ur.role_id = r.id AND r.name = 'event_admin';
SELECT COUNT(*) AS contribution_categories FROM categories WHERE is_contribution = TRUE;
