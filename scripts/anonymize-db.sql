-- =============================================================================
-- anonymize-db.sql
-- =============================================================================
--
-- Ueberschreibt personenbezogene Daten (PII) in der VAES-Test-DB.
-- NICHT DIREKT AUSFUEHREN. Stattdessen:
--
--   php scripts/anonymize-db.php
--
-- Das Wrapper-Script prueft Host-Allowlist (127.0.0.1), Identifier-Validitaet
-- und Prod-Config-Flags, bevor es diese SQL-Datei ausfuehrt. Die SQL-Datei
-- selbst ist reine Daten-Mutation ohne weitere Guards.
-- =============================================================================

SELECT CONCAT('Anonymisiere DB: ', DATABASE()) AS info;

-- =============================================================================
-- users: PII deterministisch ueberschreiben
-- =============================================================================

-- Betrifft bewusst ALLE Benutzer (inkl. soft-deleted), daher kein WHERE.
-- mitgliedsnummer + eintrittsdatum werden ebenfalls ueberschrieben, weil
-- deren Kombination mit anderen Feldern re-identifizierend wirken kann.
UPDATE users SET
    email                 = CONCAT('user', id, '@vaes.test'),
    vorname               = CONCAT('TestUser', id),
    nachname              = CONCAT('Nachname', LPAD(id, 4, '0')),
    mitgliedsnummer       = CONCAT('TEST-', LPAD(id, 6, '0')),
    strasse               = NULL,
    plz                   = NULL,
    ort                   = NULL,
    telefon               = NULL,
    eintrittsdatum        = '2020-01-01',
    totp_secret           = NULL,
    totp_enabled          = FALSE,
    email_2fa_enabled     = FALSE,
    last_login_at         = NULL,
    failed_login_attempts = 0,
    locked_until          = NULL,
    password_hash         = NULL;
-- password_hash wird anschliessend durch seed-test-users.php pro Testuser
-- frisch gesetzt. Alte Passwoerter sind damit sofort ungueltig.

-- =============================================================================
-- sessions / login_attempts / password_resets: ablaufen lassen
-- =============================================================================

DELETE FROM sessions WHERE 1 = 1;
DELETE FROM password_resets WHERE 1 = 1;
DELETE FROM email_verification_codes WHERE 1 = 1;
DELETE FROM user_invitations WHERE 1 = 1;

-- rate_limits sind nicht PII, aber Test-Konsistenz
DELETE FROM rate_limits WHERE 1 = 1;

-- =============================================================================
-- work_entries.description: Freitext koennte PII enthalten
-- =============================================================================

UPDATE work_entries SET
    description = CONCAT('Anonymisierte Beschreibung fuer Antrag #', id)
WHERE description IS NOT NULL AND description <> '';

-- =============================================================================
-- work_entry_dialogs: Nachrichten koennten PII enthalten
-- =============================================================================

UPDATE work_entry_dialogs SET
    message = CONCAT('[anonymisierte Nachricht #', id, ']')
WHERE message IS NOT NULL AND message <> '';

-- =============================================================================
-- audit_log: komplett leeren.
--
-- Grund: Die Trigger audit_log_no_update / audit_log_no_delete blockieren
-- UPDATE und DELETE. Nur TRUNCATE (DDL) bypasst Row-Level-Trigger.
-- Fuer die Test-DB ist ein leerer audit_log vertretbar - die Historie aus
-- der Produktion ist fuer Tests nicht relevant, und neue Audit-Eintraege
-- entstehen durch Test-Aktionen. In der Produktions-DB bleiben die Trigger
-- aktiv, der TRUNCATE wird dort bewusst nur im Go-Live-Reset verwendet
-- (scripts/prod-reset-for-golive.sql).
--
-- Ausserdem vermeidet TRUNCATE das Exponieren von Volltext-PII
-- (description/metadata koennten Namen enthalten).
-- =============================================================================

TRUNCATE TABLE audit_log;

-- =============================================================================
-- Abschlussbericht
-- =============================================================================

SELECT 'Anonymisierung abgeschlossen' AS status;
SELECT COUNT(*) AS users_anonymized FROM users;
SELECT COUNT(*) AS audit_rows_cleaned FROM audit_log;
