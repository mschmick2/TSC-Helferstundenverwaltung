<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\AuthenticationException;
use App\Models\User;
use App\Repositories\SessionRepository;
use App\Repositories\UserRepository;
use App\Services\AuditService;
use App\Services\AuthService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für AuthService
 */
class AuthServiceTest extends TestCase
{
    private AuthService $service;
    private UserRepository&MockObject $userRepo;
    private SessionRepository&MockObject $sessionRepo;
    private AuditService&MockObject $auditService;

    protected function setUp(): void
    {
        $_SESSION = [];

        $this->userRepo = $this->createMock(UserRepository::class);
        $this->sessionRepo = $this->createMock(SessionRepository::class);
        $this->auditService = $this->createMock(AuditService::class);

        $this->service = new AuthService(
            $this->userRepo,
            $this->sessionRepo,
            $this->auditService,
            [
                'max_login_attempts' => 5,
                'lockout_duration' => 900,
                'session_lifetime' => 1800,
            ]
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function createActiveUser(
        int $id = 1,
        string $email = 'test@test.de',
        ?string $passwordHash = null
    ): User {
        if ($passwordHash === null) {
            $passwordHash = password_hash('Test123!', PASSWORD_BCRYPT, ['cost' => 4]);
        }
        $user = User::fromArray([
            'id' => (string) $id,
            'email' => $email,
            'password_hash' => $passwordHash,
            'vorname' => 'Max',
            'nachname' => 'Test',
            'is_active' => '1',
            'failed_login_attempts' => '0',
            'locked_until' => null,
        ]);
        $user->setRoles(['mitglied']);
        return $user;
    }

    // =========================================================================
    // authenticate()
    // =========================================================================

    /** @test */
    public function authenticate_erfolgreich(): void
    {
        $user = $this->createActiveUser();
        $this->userRepo->method('findByEmail')->willReturn($user);
        $this->userRepo->expects($this->once())->method('resetFailedAttempts');

        $result = $this->service->authenticate('test@test.de', 'Test123!', '127.0.0.1');

        $this->assertSame($user, $result);
    }

    /** @test */
    public function authenticate_benutzer_nicht_gefunden(): void
    {
        $this->userRepo->method('findByEmail')->willReturn(null);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Ungültige Anmeldedaten');

        $this->service->authenticate('unknown@test.de', 'Test123!', '127.0.0.1');
    }

    /** @test */
    public function authenticate_gesperrter_account(): void
    {
        $user = User::fromArray([
            'id' => '1',
            'email' => 'test@test.de',
            'password_hash' => password_hash('Test123!', PASSWORD_BCRYPT, ['cost' => 4]),
            'is_active' => '1',
            'locked_until' => (new \DateTime('+1 hour'))->format('Y-m-d H:i:s'),
        ]);
        $this->userRepo->method('findByEmail')->willReturn($user);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('gesperrt');

        $this->service->authenticate('test@test.de', 'Test123!', '127.0.0.1');
    }

    /** @test */
    public function authenticate_deaktivierter_account(): void
    {
        $user = User::fromArray([
            'id' => '1',
            'email' => 'test@test.de',
            'password_hash' => password_hash('Test123!', PASSWORD_BCRYPT, ['cost' => 4]),
            'is_active' => '0',
        ]);
        $this->userRepo->method('findByEmail')->willReturn($user);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('deaktiviert');

        $this->service->authenticate('test@test.de', 'Test123!', '127.0.0.1');
    }

    /** @test */
    public function authenticate_passwort_nicht_gesetzt(): void
    {
        $user = User::fromArray([
            'id' => '1',
            'email' => 'test@test.de',
            'password_hash' => null,
            'is_active' => '1',
        ]);
        $this->userRepo->method('findByEmail')->willReturn($user);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Einladungslink');

        $this->service->authenticate('test@test.de', 'Test123!', '127.0.0.1');
    }

    /** @test */
    public function authenticate_falsches_passwort_zaehlt_fehlversuch(): void
    {
        $user = $this->createActiveUser();
        $this->userRepo->method('findByEmail')->willReturn($user);

        $this->userRepo->expects($this->once())
            ->method('incrementFailedAttempts')
            ->willReturn(1);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('4 Versuch(e) verbleibend');

        $this->service->authenticate('test@test.de', 'FalschesPasswort', '127.0.0.1');
    }

    /** @test */
    public function authenticate_sperrt_nach_max_fehlversuchen(): void
    {
        $user = $this->createActiveUser();
        $this->userRepo->method('findByEmail')->willReturn($user);

        $this->userRepo->method('incrementFailedAttempts')->willReturn(5);

        $this->userRepo->expects($this->once())
            ->method('lockAccount')
            ->with(1, 900);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('vorübergehend gesperrt');

        $this->service->authenticate('test@test.de', 'FalschesPasswort', '127.0.0.1');
    }

    // =========================================================================
    // Session-Management
    // =========================================================================

    /** @test */
    public function is_authenticated_true_mit_session(): void
    {
        $_SESSION['session_token'] = 'token123';
        $_SESSION['user_id'] = 1;

        $this->assertTrue($this->service->isAuthenticated());
    }

    /** @test */
    public function is_authenticated_false_ohne_session(): void
    {
        $this->assertFalse($this->service->isAuthenticated());
    }

    /** @test */
    public function is_authenticated_false_mit_nur_token(): void
    {
        $_SESSION['session_token'] = 'token123';

        $this->assertFalse($this->service->isAuthenticated());
    }

    /** @test */
    public function get_current_user_aus_session(): void
    {
        $_SESSION['user_id'] = 42;

        $user = $this->createActiveUser(42);
        $this->userRepo->method('findById')->with(42)->willReturn($user);

        $result = $this->service->getCurrentUser();

        $this->assertSame(42, $result->getId());
    }

    /** @test */
    public function get_current_user_null_ohne_session(): void
    {
        $result = $this->service->getCurrentUser();

        $this->assertNull($result);
    }

    // =========================================================================
    // 2FA-Pending
    // =========================================================================

    /** @test */
    public function set_pending_2fa_totp(): void
    {
        $user = User::fromArray(['id' => '1', 'totp_enabled' => '1', 'email_2fa_enabled' => '0']);

        $this->service->setPending2fa($user);

        $pending = $this->service->getPending2fa();
        $this->assertSame(1, $pending['user_id']);
        $this->assertSame('totp', $pending['method']);
    }

    /** @test */
    public function set_pending_2fa_email(): void
    {
        $user = User::fromArray(['id' => '1', 'totp_enabled' => '0', 'email_2fa_enabled' => '1']);

        $this->service->setPending2fa($user);

        $pending = $this->service->getPending2fa();
        $this->assertSame('email', $pending['method']);
    }

    /** @test */
    public function get_pending_2fa_null_ohne_pending(): void
    {
        $this->assertNull($this->service->getPending2fa());
    }

    /** @test */
    public function clear_pending_2fa(): void
    {
        $user = User::fromArray(['id' => '1', 'totp_enabled' => '1']);
        $this->service->setPending2fa($user);

        $this->service->clearPending2fa();

        $this->assertNull($this->service->getPending2fa());
    }

    // =========================================================================
    // Passwort ändern
    // =========================================================================

    /** @test */
    public function change_password_beendet_alle_sessions(): void
    {
        $this->userRepo->expects($this->once())
            ->method('updatePassword')
            ->with(42, $this->anything());

        $this->sessionRepo->expects($this->once())
            ->method('deleteAllByUser')
            ->with(42);

        $this->service->changePassword(42, 'NeuesPasswort1');
    }

    // =========================================================================
    // Destroy All Sessions
    // =========================================================================

    /** @test */
    public function destroy_all_sessions_loescht_alle_user_sessions(): void
    {
        $this->sessionRepo->expects($this->once())
            ->method('deleteAllByUser')
            ->with(42);

        $this->service->destroyAllSessions(42);
    }
}
