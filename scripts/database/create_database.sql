-- =============================================================================
-- VAES - Vereins-Arbeitsstunden-Erfassungssystem
-- Datenbank-Erstellungsscript für MySQL 8.4
-- Version: 1.3
-- =============================================================================
-- 
-- ANLEITUNG:
-- 1. Öffnen Sie phpMyAdmin auf Ihrem Strato-Hosting
-- 2. Wählen Sie Ihre Datenbank aus (oder erstellen Sie eine neue)
-- 3. Klicken Sie auf "SQL" im oberen Menü
-- 4. Kopieren Sie dieses Script und fügen Sie es ein
-- 5. Klicken Sie auf "OK" zum Ausführen
--
-- WICHTIG: Passen Sie ggf. den Datenbanknamen in Zeile 20 an!
-- =============================================================================

-- Zeichensatz für die Session
SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- =============================================================================
-- TABELLEN LÖSCHEN (falls vorhanden) - Reihenfolge beachten wegen Foreign Keys!
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS work_entry_dialogs;
DROP TABLE IF EXISTS work_entries;
DROP TABLE IF EXISTS user_roles;
DROP TABLE IF EXISTS sessions;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS email_verification_codes;
DROP TABLE IF EXISTS user_invitations;
DROP TABLE IF EXISTS yearly_targets;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS entry_locks;
DROP TABLE IF EXISTS entry_number_sequence;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- TABELLE: roles (Benutzerrollen)
-- =============================================================================

CREATE TABLE roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Standard-Rollen einfügen
INSERT INTO roles (name, description) VALUES
('mitglied', 'Reguläres Vereinsmitglied - kann eigene Arbeitsstunden erfassen'),
('erfasser', 'Kann Stunden für andere Mitglieder eintragen'),
('pruefer', 'Kann Anträge freigeben, ablehnen und Rückfragen stellen'),
('auditor', 'Lesender Zugriff auf alle Vorgänge inkl. gelöschter Daten'),
('administrator', 'Vollzugriff auf alle Funktionen');

-- =============================================================================
-- TABELLE: users (Benutzer/Mitglieder)
-- =============================================================================

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mitgliedsnummer VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NULL,
    vorname VARCHAR(100) NOT NULL,
    nachname VARCHAR(100) NOT NULL,
    strasse VARCHAR(255) NULL,
    plz VARCHAR(20) NULL,
    ort VARCHAR(100) NULL,
    telefon VARCHAR(50) NULL,
    eintrittsdatum DATE NULL,
    
    -- 2FA Felder
    totp_secret VARCHAR(255) NULL,
    totp_enabled BOOLEAN DEFAULT FALSE,
    email_2fa_enabled BOOLEAN DEFAULT FALSE,
    
    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    email_verified_at TIMESTAMP NULL,
    password_changed_at TIMESTAMP NULL,
    last_login_at TIMESTAMP NULL,
    failed_login_attempts INT UNSIGNED DEFAULT 0,
    locked_until TIMESTAMP NULL,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    
    -- Indizes
    INDEX idx_users_email (email),
    INDEX idx_users_mitgliedsnummer (mitgliedsnummer),
    INDEX idx_users_deleted_at (deleted_at),
    INDEX idx_users_nachname (nachname)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- TABELLE: user_roles (Benutzer-Rollen-Zuordnung)
-- =============================================================================

CREATE TABLE user_roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    assigned_by INT UNSIGNED NULL,
    
    UNIQUE KEY unique_user_role (user_id, role_id),
    
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) 
        REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) 
        REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_assigned_by FOREIGN KEY (assigned_by) 
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- TABELLE: sessions (Aktive Sessions)
-- =============================================================================

CREATE TABLE sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token VARCHAR(255) NOT NULL UNIQUE,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    last_activity_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_sessions_token (token),
    INDEX idx_sessions_user_id (user_id),
    INDEX idx_sessions_expires_at (expires_at),
    
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) 
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- TABELLE: email_verification_codes (2FA E-Mail-Codes)
-- =============================================================================

CREATE TABLE email_verification_codes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    code VARCHAR(10) NOT NULL,
    purpose ENUM('login', 'password_reset', 'email_verify') DEFAULT 'login',
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_email_codes_user (user_id),
    INDEX idx_email_codes_expires (expires_at),
    
    CONSTRAINT fk_email_codes_user FOREIGN KEY (user_id) 
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- TABELLE: password_resets (Passwort-Reset-Tokens)
-- =============================================================================

CREATE TABLE password_resets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token VARCHAR(255) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_password_resets_token (token),
    
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) 
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- TABELLE: user_invitations (Einladungen fuer neue Mitglieder)
-- =============================================================================

CREATE TABLE user_invitations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token VARCHAR(255) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    sent_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED NULL,
    
    INDEX idx_invitations_token (token),
    INDEX idx_invitations_user (user_id),
    
    CONSTRAINT fk_invitations_user FOREIGN KEY (user_id) 
        REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_invitations_created_by FOREIGN KEY (created_by) 
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- TABELLE: yearly_targets (Soll-Stunden pro Mitglied und Jahr)
-- =============================================================================

CREATE TABLE yearly_targets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    year YEAR NOT NULL,
    target_hours DECIMAL(5,2) NOT NULL DEFAULT 0,
    is_exempt BOOLEAN DEFAULT FALSE COMMENT 'Befreit von Sollstunden (z.B. Ehrenmitglieder)',
    notes VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_user_year (user_id, year),
    
    CONSTRAINT fk_yearly_targets_user FOREIGN KEY (user_id) 
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- TABELLE: categories (Tätigkeitskategorien)
-- =============================================================================

CREATE TABLE categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(500) NULL,
    sort_order INT UNSIGNED DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    
    INDEX idx_categories_active (is_active),
    INDEX idx_categories_sort (sort_order),
    INDEX idx_categories_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Beispiel-Kategorien einfügen
INSERT INTO categories (name, description, sort_order) VALUES
('Rasenpflege', 'Mähen, Vertikutieren, Düngen', 10),
('Gebäudepflege', 'Reinigung, kleine Reparaturen', 20),
('Veranstaltungen', 'Auf- und Abbau, Bewirtung', 30),
('Verwaltung', 'Büroarbeiten, Organisation', 40),
('Sonstiges', 'Nicht kategorisierte Tätigkeiten', 100);

-- =============================================================================
-- TABELLE: work_entries (Arbeitsstunden-Einträge)
-- =============================================================================

CREATE TABLE work_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    -- Eindeutige Antragsnummer (Format: JJJJ-NNNNN)
    entry_number VARCHAR(20) NOT NULL UNIQUE,
    
    -- Beziehungen
    user_id INT UNSIGNED NOT NULL COMMENT 'Mitglied, für das die Stunden erfasst werden',
    created_by_user_id INT UNSIGNED NOT NULL COMMENT 'Benutzer, der den Eintrag erstellt hat',
    category_id INT UNSIGNED NULL,
    
    -- Arbeitsdaten
    work_date DATE NOT NULL,
    time_from TIME NULL,
    time_to TIME NULL,
    hours DECIMAL(5,2) NOT NULL,
    project VARCHAR(255) NULL,
    description TEXT NULL,
    
    -- Workflow-Status
    status ENUM('entwurf', 'eingereicht', 'in_klaerung', 'freigegeben', 'abgelehnt', 'storniert') 
        DEFAULT 'entwurf',
    
    -- Prüfer-Felder
    reviewed_by_user_id INT UNSIGNED NULL,
    reviewed_at TIMESTAMP NULL,
    rejection_reason TEXT NULL,
    return_reason TEXT NULL COMMENT 'Begründung bei Zurück zur Überarbeitung',
    
    -- Korrektur-Felder (für nachträgliche Änderungen an freigegebenen Anträgen)
    is_corrected BOOLEAN DEFAULT FALSE,
    corrected_by_user_id INT UNSIGNED NULL,
    corrected_at TIMESTAMP NULL,
    correction_reason TEXT NULL,
    original_hours DECIMAL(5,2) NULL COMMENT 'Ursprüngliche Stundenzahl vor Korrektur',
    
    -- Timestamps
    submitted_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    
    -- Versionierung für Optimistic Locking
    version INT UNSIGNED DEFAULT 1,
    
    -- Indizes
    INDEX idx_work_entries_user (user_id),
    INDEX idx_work_entries_status (status),
    INDEX idx_work_entries_date (work_date),
    INDEX idx_work_entries_category (category_id),
    INDEX idx_work_entries_created_by (created_by_user_id),
    INDEX idx_work_entries_deleted_at (deleted_at),
    INDEX idx_work_entries_entry_number (entry_number),
    
    -- Foreign Keys
    CONSTRAINT fk_work_entries_user FOREIGN KEY (user_id) 
        REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_work_entries_created_by FOREIGN KEY (created_by_user_id) 
        REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_work_entries_category FOREIGN KEY (category_id) 
        REFERENCES categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_work_entries_reviewed_by FOREIGN KEY (reviewed_by_user_id) 
        REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_work_entries_corrected_by FOREIGN KEY (corrected_by_user_id) 
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- TABELLE: entry_locks (Bearbeitungssperren für Multisession)
-- =============================================================================

CREATE TABLE entry_locks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    work_entry_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    session_id INT UNSIGNED NULL,
    locked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    
    UNIQUE KEY unique_entry_lock (work_entry_id),
    
    INDEX idx_entry_locks_expires (expires_at),
    
    CONSTRAINT fk_entry_locks_entry FOREIGN KEY (work_entry_id) 
        REFERENCES work_entries(id) ON DELETE CASCADE,
    CONSTRAINT fk_entry_locks_user FOREIGN KEY (user_id) 
        REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_entry_locks_session FOREIGN KEY (session_id) 
        REFERENCES sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- TABELLE: work_entry_dialogs (Dialog-Nachrichten)
-- =============================================================================

CREATE TABLE work_entry_dialogs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    work_entry_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    is_question BOOLEAN DEFAULT FALSE COMMENT 'Markiert Nachrichten als Frage vom Prüfer',
    is_answered BOOLEAN DEFAULT FALSE COMMENT 'Wurde die Frage beantwortet?',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Nachrichten können nicht bearbeitet oder gelöscht werden (Revisionssicherheit)
    
    INDEX idx_dialogs_entry (work_entry_id),
    INDEX idx_dialogs_user (user_id),
    INDEX idx_dialogs_created (created_at),
    INDEX idx_dialogs_unanswered (work_entry_id, is_question, is_answered),
    
    CONSTRAINT fk_dialogs_entry FOREIGN KEY (work_entry_id) 
        REFERENCES work_entries(id) ON DELETE CASCADE,
    CONSTRAINT fk_dialogs_user FOREIGN KEY (user_id) 
        REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- TABELLE: audit_log (Vollständiger Audit-Trail)
-- =============================================================================

CREATE TABLE audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    -- Wer hat die Aktion ausgeführt?
    user_id INT UNSIGNED NULL,
    session_id INT UNSIGNED NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    
    -- Was wurde geändert?
    action ENUM(
        'create', 'update', 'delete', 'restore',
        'login', 'logout', 'login_failed',
        'status_change', 'export', 'import',
        'config_change', 'dialog_message'
    ) NOT NULL,
    
    table_name VARCHAR(100) NULL,
    record_id INT UNSIGNED NULL,
    entry_number VARCHAR(20) NULL COMMENT 'Antragsnummer für einfache Zuordnung',
    
    -- Änderungsdetails
    old_values JSON NULL,
    new_values JSON NULL,
    
    -- Zusätzliche Informationen
    description VARCHAR(500) NULL,
    metadata JSON NULL COMMENT 'Zusätzliche Daten wie Filter bei Export',
    
    -- Timestamp (nicht änderbar)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Indizes für schnelle Abfragen
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_action (action),
    INDEX idx_audit_table (table_name),
    INDEX idx_audit_record (table_name, record_id),
    INDEX idx_audit_created (created_at),
    INDEX idx_audit_entry_number (entry_number)
    
    -- Kein Foreign Key auf users, da Audit-Einträge erhalten bleiben müssen
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- TABELLE: settings (Systemeinstellungen)
-- =============================================================================

CREATE TABLE settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    setting_type ENUM('string', 'integer', 'boolean', 'json') DEFAULT 'string',
    description VARCHAR(500) NULL,
    is_public BOOLEAN DEFAULT FALSE COMMENT 'Darf im Frontend angezeigt werden?',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED NULL,
    
    INDEX idx_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Standard-Einstellungen einfügen
INSERT INTO settings (setting_key, setting_value, setting_type, description, is_public) VALUES
-- Allgemein
('app_name', 'VAES', 'string', 'Name der Anwendung', TRUE),
('app_version', '1.1.0', 'string', 'Aktuelle Version der Anwendung', TRUE),
('vereinsname', 'Mein Verein e.V.', 'string', 'Name des Vereins für Anzeige und Exporte', TRUE),
('vereinslogo_path', NULL, 'string', 'Pfad zum Vereinslogo für PDF-Exporte', FALSE),

-- Session & Sicherheit
('session_timeout_minutes', '30', 'integer', 'Session-Timeout in Minuten', FALSE),
('max_login_attempts', '5', 'integer', 'Maximale Fehlversuche vor Sperrung', FALSE),
('lockout_duration_minutes', '15', 'integer', 'Sperrdauer nach zu vielen Fehlversuchen', FALSE),
('require_2fa', 'true', 'boolean', '2FA für alle Benutzer verpflichtend', FALSE),

-- Erinnerungen
('reminder_days', '7', 'integer', 'Tage bis zur Erinnerungs-E-Mail bei offenen Fragen', FALSE),
('reminder_enabled', 'true', 'boolean', 'Erinnerungs-E-Mails aktiviert', FALSE),

-- Soll-Stunden
('target_hours_enabled', 'false', 'boolean', 'Soll-Stunden-Funktion aktiviert', FALSE),
('target_hours_default', '20', 'integer', 'Standard-Sollstunden pro Jahr', FALSE),

-- Datenaufbewahrung
('data_retention_years', '10', 'integer', 'Aufbewahrungsfrist in Jahren', FALSE),

-- Einladungen
('invitation_expiry_days', '7', 'integer', 'Gültigkeit von Einladungslinks in Tagen', FALSE),

-- E-Mail
('smtp_host', '', 'string', 'SMTP-Server für E-Mail-Versand', FALSE),
('smtp_port', '587', 'integer', 'SMTP-Port', FALSE),
('smtp_username', '', 'string', 'SMTP-Benutzername', FALSE),
('smtp_password', '', 'string', 'SMTP-Passwort (verschlüsselt)', FALSE),
('smtp_encryption', 'tls', 'string', 'SMTP-Verschlüsselung (tls/ssl)', FALSE),
('email_from_address', 'noreply@example.com', 'string', 'Absender-E-Mail-Adresse', FALSE),
('email_from_name', 'VAES System', 'string', 'Absender-Name', FALSE),

-- Pflichtfelder (Stundenerfassung)
('field_datum_required', 'required', 'string', 'Pflichtfeld-Status: required/optional/hidden', FALSE),
('field_zeit_von_required', 'optional', 'string', 'Pflichtfeld-Status: required/optional/hidden', FALSE),
('field_zeit_bis_required', 'optional', 'string', 'Pflichtfeld-Status: required/optional/hidden', FALSE),
('field_stunden_required', 'required', 'string', 'Pflichtfeld-Status: required/optional/hidden', FALSE),
('field_kategorie_required', 'required', 'string', 'Pflichtfeld-Status: required/optional/hidden', FALSE),
('field_projekt_required', 'optional', 'string', 'Pflichtfeld-Status: required/optional/hidden', FALSE),
('field_beschreibung_required', 'optional', 'string', 'Pflichtfeld-Status: required/optional/hidden', FALSE),

-- Bearbeitungssperren
('lock_timeout_minutes', '5', 'integer', 'Timeout für Bearbeitungssperren in Minuten', FALSE);

-- =============================================================================
-- TABELLE: entry_number_sequence (Für fortlaufende Antragsnummern)
-- =============================================================================

CREATE TABLE entry_number_sequence (
    year YEAR PRIMARY KEY,
    last_number INT UNSIGNED DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aktuelles Jahr initialisieren
INSERT INTO entry_number_sequence (year, last_number) VALUES (YEAR(CURRENT_DATE), 0);

-- =============================================================================
-- STORED PROCEDURE: Nächste Antragsnummer generieren
-- =============================================================================

DELIMITER //

CREATE PROCEDURE generate_entry_number(OUT new_number VARCHAR(20))
BEGIN
    DECLARE current_year YEAR;
    DECLARE next_seq INT;
    
    SET current_year = YEAR(CURRENT_DATE);
    
    -- Zeile für aktuelles Jahr sperren und Nummer erhöhen
    INSERT INTO entry_number_sequence (year, last_number) 
    VALUES (current_year, 1)
    ON DUPLICATE KEY UPDATE last_number = last_number + 1;
    
    -- Aktuelle Nummer abrufen
    SELECT last_number INTO next_seq 
    FROM entry_number_sequence 
    WHERE year = current_year;
    
    -- Formatierte Nummer zurückgeben (JJJJ-NNNNN)
    SET new_number = CONCAT(current_year, '-', LPAD(next_seq, 5, '0'));
END //

DELIMITER ;

-- =============================================================================
-- TRIGGER: Automatische Antragsnummer bei INSERT
-- =============================================================================

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

-- =============================================================================
-- VIEW: Aktive Benutzer mit Rollen
-- =============================================================================

CREATE VIEW v_users_with_roles AS
SELECT 
    u.id,
    u.mitgliedsnummer,
    u.email,
    u.vorname,
    u.nachname,
    CONCAT(u.vorname, ' ', u.nachname) AS vollname,
    u.is_active,
    u.last_login_at,
    u.deleted_at,
    GROUP_CONCAT(r.name ORDER BY r.id SEPARATOR ', ') AS rollen
FROM users u
LEFT JOIN user_roles ur ON u.id = ur.user_id
LEFT JOIN roles r ON ur.role_id = r.id
GROUP BY u.id;

-- =============================================================================
-- VIEW: Anträge mit offenen Fragen
-- =============================================================================

CREATE VIEW v_entries_with_open_questions AS
SELECT 
    we.*,
    u.vorname,
    u.nachname,
    u.email,
    c.name AS category_name,
    (
        SELECT COUNT(*) 
        FROM work_entry_dialogs wed 
        WHERE wed.work_entry_id = we.id 
        AND wed.is_question = TRUE 
        AND wed.is_answered = FALSE
    ) AS open_questions_count,
    (
        SELECT MAX(wed.created_at) 
        FROM work_entry_dialogs wed 
        WHERE wed.work_entry_id = we.id
    ) AS last_dialog_at
FROM work_entries we
JOIN users u ON we.user_id = u.id
LEFT JOIN categories c ON we.category_id = c.id
WHERE we.deleted_at IS NULL;

-- =============================================================================
-- VIEW: Soll/Ist-Vergleich pro Mitglied und Jahr
-- =============================================================================

CREATE VIEW v_target_comparison AS
SELECT 
    u.id AS user_id,
    u.mitgliedsnummer,
    u.vorname,
    u.nachname,
    CONCAT(u.vorname, ' ', u.nachname) AS vollname,
    YEAR(we.work_date) AS year,
    COALESCE(yt.target_hours, 0) AS target_hours,
    COALESCE(yt.is_exempt, FALSE) AS is_exempt,
    COALESCE(SUM(CASE WHEN we.status = 'freigegeben' THEN we.hours ELSE 0 END), 0) AS actual_hours,
    COALESCE(yt.target_hours, 0) - COALESCE(SUM(CASE WHEN we.status = 'freigegeben' THEN we.hours ELSE 0 END), 0) AS remaining_hours
FROM users u
LEFT JOIN work_entries we ON u.id = we.user_id AND we.deleted_at IS NULL
LEFT JOIN yearly_targets yt ON u.id = yt.user_id AND yt.year = YEAR(we.work_date)
WHERE u.deleted_at IS NULL AND u.is_active = TRUE
GROUP BY u.id, YEAR(we.work_date), yt.target_hours, yt.is_exempt;

-- =============================================================================
-- ADMIN-BENUTZER ANLEGEN
-- =============================================================================
-- WICHTIG: Ändern Sie das Passwort nach dem ersten Login!
-- Standard-Passwort: Admin123!
-- Passwort-Hash wurde mit bcrypt (cost 12) erstellt

INSERT INTO users (mitgliedsnummer, email, vorname, nachname, password_hash, is_active, email_verified_at)
VALUES ('ADMIN001', 'admin@example.com', 'System', 'Administrator', 
        '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/X4P.nR5VhKqVwVqLe', 
        TRUE, CURRENT_TIMESTAMP);

-- Admin alle Rollen zuweisen
INSERT INTO user_roles (user_id, role_id)
SELECT 
    (SELECT id FROM users WHERE mitgliedsnummer = 'ADMIN001'),
    id
FROM roles;

-- =============================================================================
-- ABSCHLUSS
-- =============================================================================

SELECT 'Datenbank wurde erfolgreich erstellt!' AS Status;
SELECT CONCAT('Admin-Benutzer: admin@example.com / Admin123!') AS Hinweis;
SELECT 'WICHTIG: Ändern Sie das Admin-Passwort nach dem ersten Login!' AS Warnung;
