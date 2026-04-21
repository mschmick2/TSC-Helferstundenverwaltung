<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Tests\Support\TestCase;

/**
 * Regressions-Schutz fuer die Test-Environment-Scripts.
 *
 * Keine echte Ausfuehrung - nur statische Invarianten:
 *   - Dateien existieren
 *   - Kritische SQL-Statements sind vorhanden
 *   - Bekannte Bugs (Trigger-verletzende UPDATEs, Referenzen auf
 *     nicht-existente Spalten) tauchen nicht wieder auf
 *
 * Falls diese Tests rot werden, wurde ein Script in einer Weise geaendert,
 * die die Test-Environment-Invarianten verletzt.
 */
final class ScriptInvariantsTest extends TestCase
{
    private const SCRIPTS_DIR = __DIR__ . '/../../../scripts';

    // ==========================================================================
    // anonymize-db.sql
    // ==========================================================================

    public function test_anonymize_sql_exists(): void
    {
        self::assertFileExists(self::SCRIPTS_DIR . '/anonymize-db.sql');
    }

    public function test_anonymize_sql_uses_truncate_not_update_on_audit_log(): void
    {
        $sql = (string) file_get_contents(self::SCRIPTS_DIR . '/anonymize-db.sql');

        // Muss TRUNCATE verwenden (DDL bypasst den audit_log_no_update-Trigger)
        self::assertStringContainsString(
            'TRUNCATE TABLE audit_log',
            $sql,
            'anonymize-db.sql muss TRUNCATE auf audit_log verwenden (Trigger blockt UPDATE)'
        );

        // Darf KEIN UPDATE auf audit_log enthalten (wuerde am Trigger scheitern)
        self::assertDoesNotMatchRegularExpression(
            '/UPDATE\s+audit_log\b/i',
            $sql,
            'anonymize-db.sql darf kein UPDATE audit_log enthalten (Trigger blockt)'
        );
    }

    public function test_anonymize_sql_does_not_reference_nonexistent_details_column(): void
    {
        $sql = (string) file_get_contents(self::SCRIPTS_DIR . '/anonymize-db.sql');

        // Das Schema hat 'metadata', nicht 'details'. In Kommentaren ok.
        // Wir pruefen: kein 'details' in SET-/WHERE-/SELECT-Klauseln.
        $nonCommentLines = preg_replace('/--[^\n]*/', '', $sql);
        self::assertDoesNotMatchRegularExpression(
            '/\bdetails\s*=/i',
            (string) $nonCommentLines,
            'anonymize-db.sql darf nicht auf nicht-existente Spalte "details" setzen'
        );
    }

    public function test_anonymize_sql_removed_legacy_refuse_hack(): void
    {
        $sql = (string) file_get_contents(self::SCRIPTS_DIR . '/anonymize-db.sql');

        self::assertStringNotContainsString(
            '__refuse_anonymize_looks_like_strato__',
            $sql,
            'Hack-Guard wurde durch PHP-Wrapper (anonymize-db.php) ersetzt'
        );
    }

    public function test_anonymize_sql_anonymizes_critical_pii_fields(): void
    {
        $sql = (string) file_get_contents(self::SCRIPTS_DIR . '/anonymize-db.sql');

        $requiredFieldUpdates = [
            'email',
            'vorname',
            'nachname',
            'mitgliedsnummer',
            'telefon',
            'totp_secret',
            'password_hash',
        ];
        foreach ($requiredFieldUpdates as $field) {
            self::assertMatchesRegularExpression(
                '/\b' . preg_quote($field, '/') . '\s*=/i',
                $sql,
                "anonymize-db.sql muss das PII-Feld '$field' ueberschreiben"
            );
        }
    }

    // ==========================================================================
    // prod-reset-for-golive.sql
    // ==========================================================================

    public function test_prod_reset_sql_exists(): void
    {
        self::assertFileExists(self::SCRIPTS_DIR . '/prod-reset-for-golive.sql');
    }

    public function test_prod_reset_preserves_admin_users(): void
    {
        $sql = (string) file_get_contents(self::SCRIPTS_DIR . '/prod-reset-for-golive.sql');

        // Muss eine WHERE-Klausel haben, die Administratoren ausschliesst
        self::assertMatchesRegularExpression(
            "/DELETE\s+.+FROM\s+users.+administrator/is",
            $sql,
            'prod-reset muss Nicht-Admins gezielt loeschen, nicht pauschal TRUNCATE users'
        );

        // users darf nicht TRUNCATEd werden
        self::assertDoesNotMatchRegularExpression(
            '/TRUNCATE\s+(TABLE\s+)?users\b/i',
            $sql,
            'prod-reset darf users nicht TRUNCATEn (Admins wuerden verloren)'
        );
    }

    public function test_prod_reset_uses_truncate_for_audit_log(): void
    {
        $sql = (string) file_get_contents(self::SCRIPTS_DIR . '/prod-reset-for-golive.sql');

        self::assertMatchesRegularExpression(
            '/TRUNCATE\s+(TABLE\s+)?audit_log\b/i',
            $sql,
            'prod-reset muss audit_log truncaten (Go-Live startet mit leerer Historie)'
        );
    }

    // ==========================================================================
    // PHP-Scripts
    // ==========================================================================

    public function test_import_strato_db_has_host_allowlist_guard(): void
    {
        $php = (string) file_get_contents(self::SCRIPTS_DIR . '/import-strato-db.php');

        self::assertStringContainsString(
            'ALLOWED_DB_HOSTS',
            $php,
            'import-strato-db.php muss Host-Allowlist-Konstante definieren'
        );
        self::assertStringContainsString(
            'assertHostAllowed',
            $php,
            'import-strato-db.php muss assertHostAllowed()-Guard aufrufen'
        );
    }

    public function test_import_strato_db_validates_identifier(): void
    {
        $php = (string) file_get_contents(self::SCRIPTS_DIR . '/import-strato-db.php');

        self::assertStringContainsString('IDENTIFIER_REGEX', $php);
        self::assertStringContainsString('validateIdentifier', $php);
    }

    public function test_import_strato_db_has_no_windows_command_injection(): void
    {
        $php = (string) file_get_contents(self::SCRIPTS_DIR . '/import-strato-db.php');

        // Nach G4-Fix darf kein Windows-spezifischer Branch mit Raw-Interpolation
        // in einer cmd-String existieren.
        self::assertDoesNotMatchRegularExpression(
            "/'cmd\s+\/c\s+\"%s[^']*-p\\\$pass/i",
            $php,
            'Windows-Branch mit Raw-Interpolation wurde in G4 entfernt'
        );
    }

    public function test_seed_test_users_generates_password_at_runtime(): void
    {
        $php = (string) file_get_contents(self::SCRIPTS_DIR . '/seed-test-users.php');

        // Muss password_hash zur Laufzeit aufrufen
        self::assertMatchesRegularExpression(
            '/password_hash\s*\(\s*\$password/i',
            $php,
            'seed-test-users.php muss password_hash() zur Laufzeit aufrufen'
        );

        // Keine gepinnten bcrypt-Hashes im Repo
        self::assertDoesNotMatchRegularExpression(
            '/[\'"]\$2y\$12\$[A-Za-z0-9.\/]{53}[\'"]/',
            $php,
            'Kein gepinnter bcrypt-Hash im Seed-Script'
        );
    }

    public function test_anonymize_db_php_has_guards(): void
    {
        $php = (string) file_get_contents(self::SCRIPTS_DIR . '/anonymize-db.php');

        self::assertStringContainsString('ALLOWED_DB_HOSTS', $php);
        self::assertStringContainsString('assertHostAllowed', $php);
        self::assertStringContainsString('assertConfigNotProduction', $php);
    }

    // ==========================================================================
    // Doku
    // ==========================================================================

    public function test_testumgebung_doc_mentions_dsgvo_section(): void
    {
        $docPath = __DIR__ . '/../../../docs/Testumgebung.md';
        self::assertFileExists($docPath);

        $doc = (string) file_get_contents($docPath);
        self::assertStringContainsString(
            'DSGVO',
            $doc,
            'docs/Testumgebung.md muss einen DSGVO-Abschnitt haben'
        );
        self::assertStringContainsString(
            'Go-Live',
            $doc,
            'Go-Live-Abschnitt muss dokumentiert sein'
        );
        self::assertStringContainsString(
            'yearly_targets',
            $doc,
            'Go-Live-Nebeneffekt yearly_targets muss im Doc erwaehnt sein'
        );
    }
}
