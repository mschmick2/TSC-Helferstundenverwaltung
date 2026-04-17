-- =============================================================================
-- Migration 003: System-User fuer Modul 6 I3
-- =============================================================================
--
-- Erzeugt einen technischen System-User, der als `created_by_user_id` fuer
-- automatisch generierte work_entries (aus Event-Abschluss) verwendet wird.
--
-- Eigenschaften:
--   - is_active=0         -> kein Login moeglich
--   - password_hash=NULL  -> keine Authentifizierung moeglich
--   - totp_enabled=0      -> kein 2FA-Einstieg
--   - mitgliedsnummer='SYSTEM' -> eindeutig identifizierbar
--
-- Idempotent: ON DUPLICATE KEY UPDATE -> kann gefahrlos re-ausgefuehrt werden.
-- =============================================================================

SET NAMES utf8mb4;

INSERT INTO users (
    mitgliedsnummer, email, password_hash, vorname, nachname,
    is_active, totp_enabled, email_2fa_enabled,
    eintrittsdatum
) VALUES (
    'SYSTEM',
    'system@vaes.internal',
    NULL,
    'System',
    'Automat',
    FALSE,
    FALSE,
    FALSE,
    '2020-01-01'
) ON DUPLICATE KEY UPDATE
    is_active = FALSE,
    password_hash = NULL,
    totp_enabled = FALSE,
    email_2fa_enabled = FALSE;

SELECT CONCAT('System-User vorhanden mit ID ',
    (SELECT id FROM users WHERE mitgliedsnummer = 'SYSTEM')
) AS status;
