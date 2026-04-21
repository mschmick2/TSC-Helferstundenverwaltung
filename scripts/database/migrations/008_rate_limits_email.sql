-- =============================================================================
-- Migration 008: rate_limits.email fuer Email-basierten Bucket (CLAUDE.md §8 Nr. 2)
-- =============================================================================
--
-- Fuegt `rate_limits` eine optionale `email`-Spalte hinzu. Zweck: zusaetzlich
-- zum bestehenden IP-Bucket kann der /forgot-password-Endpunkt jetzt auch
-- pro E-Mail-Empfaenger limitieren. Damit schlaegt ein verteilter Angriff aus
-- vielen IPs, der das Postfach eines einzelnen Nutzers mit Reset-Mails fluten
-- will, nicht mehr durch.
--
-- Bestehende Eintraege bleiben unberuehrt (email=NULL). Alte Controller-Pfade
-- (Login/Setup-Password/Reset-Password) schreiben weiterhin nur ip_address,
-- der neue Email-Bucket fuer Forgot-Password schreibt beide Felder.
--
-- Idempotent via INFORMATION_SCHEMA-Check (analog 004-007).
-- =============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- rate_limits.email
-- ---------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rate_limits'
      AND COLUMN_NAME = 'email'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE rate_limits
        ADD COLUMN email VARCHAR(255) NULL
            COMMENT ''Optional: Empfaenger-Email fuer Email-basierten Bucket (Forgot-Password Anti-Flood)''
            AFTER ip_address',
    'SELECT ''rate_limits.email existiert bereits'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Index fuer (email, endpoint, attempted_at) — symmetrisch zum IP-Lookup-Index.
-- ---------------------------------------------------------------------------
SET @idx_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rate_limits'
      AND INDEX_NAME = 'idx_rate_limits_email_lookup'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE rate_limits
        ADD INDEX idx_rate_limits_email_lookup (email, endpoint, attempted_at)',
    'SELECT ''idx_rate_limits_email_lookup existiert bereits'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Migration 008 (Email-Bucket fuer rate_limits) abgeschlossen' AS status;
