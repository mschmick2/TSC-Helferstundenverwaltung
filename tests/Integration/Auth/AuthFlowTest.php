<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Exceptions\AuthenticationException;
use App\Models\User;
use App\Repositories\SessionRepository;
use App\Repositories\UserRepository;
use App\Services\AuditService;
use App\Services\AuthService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Integrationstests für den Authentifizierungs-Flow
 *
 * Testet das Zusammenspiel von AuthService, UserRepository-Mock
 * und Session-Management über mehrere Schritte hinweg.
 */
class AuthFlowTest extends TestCase
{
    private AuthService $authService;
    private UserRepository&MockObject $userRepo;
    private SessionRepository&MockObject $sessionRepo;
    private AuditService&MockObject $auditService;

    protected function setUp(): void
    {
        $_SESSION = [];

        $this->userRepo = $this->createMock(UserRepository::class);
        $this->sessionRepo = $this->createMock(SessionRepository::class);
        $this->auditService = $this->createMock(AuditService::class);

        $this->authService = new AuthService(
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

    private function createUser(int $id = 1): User
    {
        $user = User::fromArray([
            'id' => (string) $id,
            'email' => "user{$id}@test.de",
            'password_hash' => password_hash('Test123!', PASSWORD_BCRYPT, ['cost' => 4]),
            'vorname' => 'User',
            'nachname' => "Test{$id}",
            'is_active' => '1',
            'failed_login_attempts' => '0',
        ]);
        $user->setRoles(['mitglied']);
        return $user;
    }

    // =========================================================================
    // Login → 2FA → Logout Flow
    // =========================================================================

    /** @test */
    public function login_2fa_pending_flow(): void
    {
        $user = User::fromArray([
            'id' => '1',
            'email' => 'test@test.de',
            'password_hash' => password_hash('Test123!', PASSWORD_BCRYPT, ['cost' => 4]),
            'vorname' => 'Max',
            'nachname' => 'Test',
            'is_active' => '1',
            'totp_enabled' => '1',
            'email_2fa_enabled' => '0',
        ]);

        $this->userRepo->method('findByEmail')->willReturn($user);
        $this->userRepo->method('resetFailedAttempts');

        // Schritt 1: Credentials prüfen
        $result = $this->authService->authenticate('test@test.de', 'Test123!', '127.0.0.1');
        $this->assertSame(1, $result->getId());

        // Schritt 2: 2FA pending setzen
        $this->authService->setPending2fa($result);
        $pending = $this->authService->getPending2fa();
        $this->assertNotNull($pending);
        $this->assertSame('totp', $pending['method']);
        $this->assertSame(1, $pending['user_id']);

        // Schritt 3: Nach 2FA-Verifizierung (simuliert): Pending aufräumen
        $this->authService->clearPending2fa();
        $this->assertNull($this->authService->getPending2fa());
    }

    // =========================================================================
    // Brute-Force-Sperre
    // =========================================================================

    /** @test */
    public function brute_force_sperrt_nach_5_fehlversuchen(): void
    {
        $user = $this->createUser(1);
        $this->userRepo->method('findByEmail')->willReturn($user);

        $attemptCount = 0;
        $this->userRepo->method('incrementFailedAttempts')
            ->willReturnCallback(function () use (&$attemptCount) {
                return ++$attemptCount;
            });

        // 4 Fehlversuche mit verbleibenden Versuchen
        for ($i = 1; $i <= 4; $i++) {
            try {
                $this->authService->authenticate('user1@test.de', 'wrong', '127.0.0.1');
                $this->fail('Exception erwartet');
            } catch (AuthenticationException $e) {
                $remaining = 5 - $i;
                if ($remaining > 0) {
                    $this->assertStringContainsString((string) $remaining, $e->getMessage());
                }
            }
        }

        // 5. Fehlversuch → Account-Sperre
        $this->userRepo->expects($this->once())
            ->method('lockAccount')
            ->with(1, 900);

        try {
            $this->authService->authenticate('user1@test.de', 'wrong', '127.0.0.1');
            $this->fail('Exception erwartet');
        } catch (AuthenticationException $e) {
            $this->assertStringContainsString('gesperrt', $e->getMessage());
        }
    }

    // =========================================================================
    // Passwortänderung beendet alle Sessions
    // =========================================================================

    /** @test */
    public function passwortaenderung_beendet_alle_sessions(): void
    {
        $this->sessionRepo->expects($this->once())
            ->method('deleteAllByUser')
            ->with(42);

        $this->userRepo->expects($this->once())
            ->method('updatePassword')
            ->with(42, $this->callback(function (string $hash) {
                return str_starts_with($hash, '$2y$');
            }));

        $this->authService->changePassword(42, 'NeuesPasswort1');
    }

    // =========================================================================
    // Session-Zustand
    // =========================================================================

    /** @test */
    public function nicht_authentifiziert_initial(): void
    {
        $this->assertFalse($this->authService->isAuthenticated());
        $this->assertNull($this->authService->getCurrentUser());
    }

    /** @test */
    public function authentifiziert_nach_session_setup(): void
    {
        $_SESSION['session_token'] = 'valid_token';
        $_SESSION['user_id'] = 1;

        $this->assertTrue($this->authService->isAuthenticated());
    }

    // =========================================================================
    // Audit-Logging wird aufgerufen
    // =========================================================================

    /** @test */
    public function fehlgeschlagener_login_wird_geloggt(): void
    {
        $this->userRepo->method('findByEmail')->willReturn(null);

        $this->auditService->expects($this->once())
            ->method('logLoginFailed')
            ->with('unknown@test.de', '192.168.1.1', $this->stringContains('nicht gefunden'));

        try {
            $this->authService->authenticate('unknown@test.de', 'wrong', '192.168.1.1');
        } catch (AuthenticationException) {
            // Expected
        }
    }
}
