<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use App\Helpers\SecurityHelper;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für SecurityHelper
 */
class SecurityHelperTest extends TestCase
{
    protected function setUp(): void
    {
        // Session für Tests bereitstellen
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // =========================================================================
    // CSRF-Token
    // =========================================================================

    /** @test */
    public function generate_csrf_token_erstellt_token(): void
    {
        $token = SecurityHelper::generateCsrfToken();

        $this->assertNotEmpty($token);
        $this->assertSame(64, strlen($token)); // 32 bytes = 64 hex chars
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $token);
    }

    /** @test */
    public function generate_csrf_token_wiederverwendet_bestehenden(): void
    {
        $token1 = SecurityHelper::generateCsrfToken();
        $token2 = SecurityHelper::generateCsrfToken();

        $this->assertSame($token1, $token2);
    }

    /** @test */
    public function generate_csrf_token_speichert_in_session(): void
    {
        $token = SecurityHelper::generateCsrfToken();

        $this->assertSame($token, $_SESSION['csrf_token']);
    }

    /** @test */
    public function validate_csrf_token_korrekt(): void
    {
        $token = SecurityHelper::generateCsrfToken();

        $this->assertTrue(SecurityHelper::validateCsrfToken($token));
    }

    /** @test */
    public function validate_csrf_token_falsch(): void
    {
        SecurityHelper::generateCsrfToken();

        $this->assertFalse(SecurityHelper::validateCsrfToken('wrong_token'));
    }

    /** @test */
    public function validate_csrf_token_null(): void
    {
        SecurityHelper::generateCsrfToken();

        $this->assertFalse(SecurityHelper::validateCsrfToken(null));
    }

    /** @test */
    public function validate_csrf_token_leer(): void
    {
        SecurityHelper::generateCsrfToken();

        $this->assertFalse(SecurityHelper::validateCsrfToken(''));
    }

    /** @test */
    public function validate_csrf_token_ohne_session_token(): void
    {
        // Kein Token in Session
        $this->assertFalse(SecurityHelper::validateCsrfToken('any_token'));
    }

    // =========================================================================
    // Passwort-Hashing
    // =========================================================================

    /** @test */
    public function hash_password_erzeugt_bcrypt_hash(): void
    {
        $hash = SecurityHelper::hashPassword('Test123!');

        $this->assertStringStartsWith('$2y$12$', $hash);
    }

    /** @test */
    public function hash_password_verschiedene_hashes_fuer_gleichen_input(): void
    {
        $hash1 = SecurityHelper::hashPassword('Test123!');
        $hash2 = SecurityHelper::hashPassword('Test123!');

        // Bcrypt erzeugt wegen Salt verschiedene Hashes
        $this->assertNotSame($hash1, $hash2);
    }

    /** @test */
    public function verify_password_korrekt(): void
    {
        $hash = SecurityHelper::hashPassword('MeinPasswort123');

        $this->assertTrue(SecurityHelper::verifyPassword('MeinPasswort123', $hash));
    }

    /** @test */
    public function verify_password_falsch(): void
    {
        $hash = SecurityHelper::hashPassword('MeinPasswort123');

        $this->assertFalse(SecurityHelper::verifyPassword('FalschesPasswort', $hash));
    }

    /** @test */
    public function verify_password_case_sensitive(): void
    {
        $hash = SecurityHelper::hashPassword('Test123!');

        $this->assertFalse(SecurityHelper::verifyPassword('test123!', $hash));
        $this->assertFalse(SecurityHelper::verifyPassword('TEST123!', $hash));
    }

    // =========================================================================
    // Token-Generierung
    // =========================================================================

    /** @test */
    public function generate_token_default_laenge(): void
    {
        $token = SecurityHelper::generateToken();

        // 64 Bytes = 128 hex chars
        $this->assertSame(128, strlen($token));
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $token);
    }

    /** @test */
    public function generate_token_custom_laenge(): void
    {
        $token = SecurityHelper::generateToken(32);

        $this->assertSame(64, strlen($token)); // 32 bytes = 64 hex chars
    }

    /** @test */
    public function generate_token_ist_einzigartig(): void
    {
        $tokens = [];
        for ($i = 0; $i < 100; $i++) {
            $tokens[] = SecurityHelper::generateToken();
        }

        // Alle Tokens müssen einzigartig sein
        $this->assertCount(100, array_unique($tokens));
    }

    // =========================================================================
    // Numerischer Code
    // =========================================================================

    /** @test */
    public function generate_numeric_code_default_6_stellen(): void
    {
        $code = SecurityHelper::generateNumericCode();

        $this->assertSame(6, strlen($code));
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertGreaterThanOrEqual(100000, (int) $code);
        $this->assertLessThanOrEqual(999999, (int) $code);
    }

    /** @test */
    public function generate_numeric_code_custom_stellen(): void
    {
        $code = SecurityHelper::generateNumericCode(8);

        $this->assertSame(8, strlen($code));
        $this->assertMatchesRegularExpression('/^\d{8}$/', $code);
    }

    /** @test */
    public function generate_numeric_code_4_stellen(): void
    {
        $code = SecurityHelper::generateNumericCode(4);

        $this->assertSame(4, strlen($code));
        $this->assertGreaterThanOrEqual(1000, (int) $code);
        $this->assertLessThanOrEqual(9999, (int) $code);
    }

    // =========================================================================
    // Passwort-Validierung
    // =========================================================================

    /** @test */
    public function validate_password_gueltig(): void
    {
        $errors = SecurityHelper::validatePassword('Test123!');

        $this->assertEmpty($errors);
    }

    /** @test */
    public function validate_password_zu_kurz(): void
    {
        $errors = SecurityHelper::validatePassword('Aa1');

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('8 Zeichen', $errors[0]);
    }

    /** @test */
    public function validate_password_ohne_grossbuchstabe(): void
    {
        $errors = SecurityHelper::validatePassword('test1234');

        $this->assertNotEmpty($errors);
        $foundError = false;
        foreach ($errors as $error) {
            if (str_contains($error, 'Großbuchstaben')) {
                $foundError = true;
                break;
            }
        }
        $this->assertTrue($foundError, 'Fehlermeldung für fehlenden Großbuchstaben erwartet');
    }

    /** @test */
    public function validate_password_ohne_kleinbuchstabe(): void
    {
        $errors = SecurityHelper::validatePassword('TEST1234');

        $this->assertNotEmpty($errors);
        $foundError = false;
        foreach ($errors as $error) {
            if (str_contains($error, 'Kleinbuchstaben')) {
                $foundError = true;
                break;
            }
        }
        $this->assertTrue($foundError, 'Fehlermeldung für fehlenden Kleinbuchstaben erwartet');
    }

    /** @test */
    public function validate_password_ohne_ziffer(): void
    {
        $errors = SecurityHelper::validatePassword('TestTest');

        $this->assertNotEmpty($errors);
        $foundError = false;
        foreach ($errors as $error) {
            if (str_contains($error, 'Ziffer')) {
                $foundError = true;
                break;
            }
        }
        $this->assertTrue($foundError, 'Fehlermeldung für fehlende Ziffer erwartet');
    }

    /** @test */
    public function validate_password_alle_regeln_verletzt(): void
    {
        $errors = SecurityHelper::validatePassword('aa');

        // Zu kurz + keine Großbuchstaben + keine Ziffer = mindestens 3 Fehler
        $this->assertGreaterThanOrEqual(3, count($errors));
    }

    /** @test */
    public function validate_password_genau_8_zeichen(): void
    {
        $errors = SecurityHelper::validatePassword('Test123a');

        $this->assertEmpty($errors);
    }

    /** @test */
    public function validate_password_leer(): void
    {
        $errors = SecurityHelper::validatePassword('');

        $this->assertNotEmpty($errors);
    }
}
