<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\User;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für das User-Model
 */
class UserTest extends TestCase
{
    // =========================================================================
    // fromArray() - Konstruktion aus DB-Daten
    // =========================================================================

    /** @test */
    public function from_array_setzt_alle_felder_korrekt(): void
    {
        $data = [
            'id' => '42',
            'mitgliedsnummer' => 'M001',
            'email' => 'max@test.de',
            'password_hash' => '$2y$12$hash...',
            'vorname' => 'Max',
            'nachname' => 'Mustermann',
            'strasse' => 'Teststr. 1',
            'plz' => '12345',
            'ort' => 'Musterstadt',
            'telefon' => '0123-456789',
            'eintrittsdatum' => '2020-01-15',
            'totp_secret' => 'JBSWY3DPEHPK3PXP',
            'totp_enabled' => '1',
            'email_2fa_enabled' => '0',
            'is_active' => '1',
            'email_verified_at' => '2020-01-20 10:00:00',
            'password_changed_at' => '2025-01-01 08:00:00',
            'last_login_at' => '2025-02-09 12:00:00',
            'failed_login_attempts' => '3',
            'locked_until' => null,
            'created_at' => '2020-01-15 10:00:00',
            'updated_at' => '2025-02-09 12:00:00',
            'deleted_at' => null,
        ];

        $user = User::fromArray($data);

        $this->assertSame(42, $user->getId());
        $this->assertSame('M001', $user->getMitgliedsnummer());
        $this->assertSame('max@test.de', $user->getEmail());
        $this->assertSame('$2y$12$hash...', $user->getPasswordHash());
        $this->assertSame('Max', $user->getVorname());
        $this->assertSame('Mustermann', $user->getNachname());
        $this->assertSame('Teststr. 1', $user->getStrasse());
        $this->assertSame('12345', $user->getPlz());
        $this->assertSame('Musterstadt', $user->getOrt());
        $this->assertSame('0123-456789', $user->getTelefon());
        $this->assertSame('2020-01-15', $user->getEintrittsdatum());
        $this->assertSame('JBSWY3DPEHPK3PXP', $user->getTotpSecret());
        $this->assertTrue($user->isTotpEnabled());
        $this->assertFalse($user->isEmail2faEnabled());
        $this->assertTrue($user->isActive());
        $this->assertSame(3, $user->getFailedLoginAttempts());
        $this->assertNull($user->getLockedUntil());
        $this->assertNull($user->getDeletedAt());
    }

    /** @test */
    public function from_array_mit_leeren_daten_setzt_defaults(): void
    {
        $user = User::fromArray([]);

        $this->assertNull($user->getId());
        $this->assertSame('', $user->getMitgliedsnummer());
        $this->assertSame('', $user->getEmail());
        $this->assertNull($user->getPasswordHash());
        $this->assertSame('', $user->getVorname());
        $this->assertSame('', $user->getNachname());
        $this->assertNull($user->getStrasse());
        $this->assertTrue($user->isActive());
        $this->assertFalse($user->isTotpEnabled());
        $this->assertFalse($user->isEmail2faEnabled());
        $this->assertSame(0, $user->getFailedLoginAttempts());
    }

    // =========================================================================
    // Vollname
    // =========================================================================

    /** @test */
    public function get_vollname_kombiniert_vor_und_nachname(): void
    {
        $user = User::fromArray(['vorname' => 'Anna', 'nachname' => 'Schmidt']);

        $this->assertSame('Anna Schmidt', $user->getVollname());
    }

    /** @test */
    public function get_vollname_bei_leerem_nachnamen(): void
    {
        $user = User::fromArray(['vorname' => 'Anna', 'nachname' => '']);

        $this->assertSame('Anna ', $user->getVollname());
    }

    // =========================================================================
    // 2FA-Status
    // =========================================================================

    /** @test */
    public function is_2fa_enabled_true_bei_totp(): void
    {
        $user = User::fromArray(['totp_enabled' => '1', 'email_2fa_enabled' => '0']);

        $this->assertTrue($user->is2faEnabled());
    }

    /** @test */
    public function is_2fa_enabled_true_bei_email_2fa(): void
    {
        $user = User::fromArray(['totp_enabled' => '0', 'email_2fa_enabled' => '1']);

        $this->assertTrue($user->is2faEnabled());
    }

    /** @test */
    public function is_2fa_enabled_false_wenn_keines_aktiv(): void
    {
        $user = User::fromArray(['totp_enabled' => '0', 'email_2fa_enabled' => '0']);

        $this->assertFalse($user->is2faEnabled());
    }

    /** @test */
    public function is_2fa_enabled_true_wenn_beides_aktiv(): void
    {
        $user = User::fromArray(['totp_enabled' => '1', 'email_2fa_enabled' => '1']);

        $this->assertTrue($user->is2faEnabled());
    }

    // =========================================================================
    // Account-Sperre (isLocked)
    // =========================================================================

    /** @test */
    public function is_locked_false_ohne_locked_until(): void
    {
        $user = User::fromArray(['locked_until' => null]);

        $this->assertFalse($user->isLocked());
    }

    /** @test */
    public function is_locked_true_wenn_zukunft(): void
    {
        $future = (new \DateTime('+1 hour'))->format('Y-m-d H:i:s');
        $user = User::fromArray(['locked_until' => $future]);

        $this->assertTrue($user->isLocked());
    }

    /** @test */
    public function is_locked_false_wenn_vergangenheit(): void
    {
        $past = (new \DateTime('-1 hour'))->format('Y-m-d H:i:s');
        $user = User::fromArray(['locked_until' => $past]);

        $this->assertFalse($user->isLocked());
    }

    // =========================================================================
    // Rollen
    // =========================================================================

    /** @test */
    public function rollen_zuweisen_und_pruefen(): void
    {
        $user = User::fromArray([]);
        $user->setRoles(['mitglied', 'erfasser']);

        $this->assertTrue($user->hasRole('mitglied'));
        $this->assertTrue($user->hasRole('erfasser'));
        $this->assertFalse($user->hasRole('pruefer'));
        $this->assertFalse($user->hasRole('administrator'));
        $this->assertSame(['mitglied', 'erfasser'], $user->getRoles());
    }

    /** @test */
    public function is_admin_true_mit_administrator_rolle(): void
    {
        $user = User::fromArray([]);
        $user->setRoles(['administrator']);

        $this->assertTrue($user->isAdmin());
    }

    /** @test */
    public function is_admin_false_ohne_administrator_rolle(): void
    {
        $user = User::fromArray([]);
        $user->setRoles(['mitglied', 'pruefer']);

        $this->assertFalse($user->isAdmin());
    }

    /** @test */
    public function is_pruefer_erkennt_pruefer_rolle(): void
    {
        $user = User::fromArray([]);
        $user->setRoles(['mitglied', 'pruefer']);

        $this->assertTrue($user->isPruefer());
        $this->assertFalse($user->isErfasser());
        $this->assertFalse($user->isAuditor());
    }

    /** @test */
    public function is_erfasser_erkennt_erfasser_rolle(): void
    {
        $user = User::fromArray([]);
        $user->setRoles(['mitglied', 'erfasser']);

        $this->assertTrue($user->isErfasser());
        $this->assertFalse($user->isPruefer());
    }

    // =========================================================================
    // Berechtigungs-Helper
    // =========================================================================

    /** @test */
    public function can_create_for_others_fuer_erfasser(): void
    {
        $user = User::fromArray([]);
        $user->setRoles(['mitglied', 'erfasser']);

        $this->assertTrue($user->canCreateForOthers());
    }

    /** @test */
    public function can_create_for_others_fuer_pruefer(): void
    {
        $user = User::fromArray([]);
        $user->setRoles(['mitglied', 'pruefer']);

        $this->assertTrue($user->canCreateForOthers());
    }

    /** @test */
    public function can_create_for_others_fuer_admin(): void
    {
        $user = User::fromArray([]);
        $user->setRoles(['administrator']);

        $this->assertTrue($user->canCreateForOthers());
    }

    /** @test */
    public function can_create_for_others_false_fuer_nur_mitglied(): void
    {
        $user = User::fromArray([]);
        $user->setRoles(['mitglied']);

        $this->assertFalse($user->canCreateForOthers());
    }

    /** @test */
    public function can_review_nur_fuer_pruefer_und_admin(): void
    {
        $mitglied = User::fromArray([]);
        $mitglied->setRoles(['mitglied']);

        $pruefer = User::fromArray([]);
        $pruefer->setRoles(['pruefer']);

        $admin = User::fromArray([]);
        $admin->setRoles(['administrator']);

        $this->assertFalse($mitglied->canReview());
        $this->assertTrue($pruefer->canReview());
        $this->assertTrue($admin->canReview());
    }

    // =========================================================================
    // Setter
    // =========================================================================

    /** @test */
    public function setter_fuer_passwort_hash(): void
    {
        $user = User::fromArray([]);
        $this->assertNull($user->getPasswordHash());

        $user->setPasswordHash('new_hash');
        $this->assertSame('new_hash', $user->getPasswordHash());
    }

    /** @test */
    public function setter_fuer_totp(): void
    {
        $user = User::fromArray([]);

        $user->setTotpSecret('SECRET');
        $this->assertSame('SECRET', $user->getTotpSecret());

        $user->setTotpEnabled(true);
        $this->assertTrue($user->isTotpEnabled());

        $user->setTotpSecret(null);
        $this->assertNull($user->getTotpSecret());
    }

    /** @test */
    public function setter_fuer_email_2fa(): void
    {
        $user = User::fromArray([]);
        $this->assertFalse($user->isEmail2faEnabled());

        $user->setEmail2faEnabled(true);
        $this->assertTrue($user->isEmail2faEnabled());
    }
}
