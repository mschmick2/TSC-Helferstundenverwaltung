<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Controllers\AuthController;
use App\Repositories\UserRepository;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\EmailService;
use App\Services\RateLimitService;
use App\Services\TotpService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Feature-Test fuer das Zwei-Bucket-Rate-Limiting von POST /forgot-password.
 *
 * Getestet wird die Entscheidungslogik im AuthController:
 *   - IP-Bucket voll   -> sichtbare Fehlermeldung, redirect /forgot-password.
 *   - Email-Bucket voll -> silent: GLEICHE Erfolgsmeldung wie Normalfall,
 *     KEINE Mail, KEIN Audit-Eintrag, redirect /login.
 *   - Normalfall (beide Buckets frei) -> Erfolgsmeldung, Mail-Send, Audit.
 *
 * Gemockt werden RateLimitService, UserRepository, EmailService, AuditService
 * sowie die unbenutzten Deps (AuthService, TotpService). Der Test prueft
 * Response-Header (Location, Status) und $_SESSION['_flash'].
 */
class ForgotPasswordRateLimitTest extends TestCase
{
    private RateLimitService&MockObject $rateLimit;
    private UserRepository&MockObject $userRepo;
    private EmailService&MockObject $emailService;
    private AuditService&MockObject $auditService;
    private AuthService&MockObject $authService;
    private TotpService&MockObject $totpService;
    private AuthController $controller;

    protected function setUp(): void
    {
        $_SESSION = [];

        $this->rateLimit = $this->createMock(RateLimitService::class);
        $this->userRepo = $this->createMock(UserRepository::class);
        $this->emailService = $this->createMock(EmailService::class);
        $this->auditService = $this->createMock(AuditService::class);
        $this->authService = $this->createMock(AuthService::class);
        $this->totpService = $this->createMock(TotpService::class);

        $settings = [
            'app' => ['url' => 'http://localhost:8000'],
            'security' => [
                'forgot_password_rate_limit_max_per_ip' => 5,
                'forgot_password_rate_limit_window_per_ip' => 900,
                'forgot_password_rate_limit_max_per_email' => 3,
                'forgot_password_rate_limit_window_per_email' => 3600,
            ],
        ];

        $this->controller = new AuthController(
            $this->authService,
            $this->totpService,
            $this->emailService,
            $this->auditService,
            $this->userRepo,
            $this->rateLimit,
            $settings
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function makeRequest(string $email, string $ip = '192.0.2.10'): \Psr\Http\Message\ServerRequestInterface
    {
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest('POST', '/forgot-password', ['REMOTE_ADDR' => $ip]);
        return $request->withParsedBody(['email' => $email]);
    }

    private function emptyResponse(): \Psr\Http\Message\ResponseInterface
    {
        return (new ResponseFactory())->createResponse();
    }

    /** @test */
    public function ip_bucket_voll_blockt_sichtbar_und_redirectet_zurueck(): void
    {
        // IP-Bucket voll, Email-Bucket wird gar nicht gefragt.
        $this->rateLimit->expects($this->once())
            ->method('isAllowed')
            ->with('192.0.2.10', 'forgot-password', 5, 900)
            ->willReturn(false);

        $this->rateLimit->expects($this->never())->method('isAllowedForEmail');
        $this->rateLimit->expects($this->never())->method('recordAttemptForEmail');

        $this->emailService->expects($this->never())->method($this->anything());
        $this->auditService->expects($this->never())->method('log');

        $response = $this->controller->requestReset(
            $this->makeRequest('opfer@example.com'),
            $this->emptyResponse()
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/forgot-password', $response->getHeaderLine('Location'));
        $this->assertSame(
            'Zu viele Anfragen. Bitte versuchen Sie es später erneut.',
            $_SESSION['_flash']['danger'][0] ?? null
        );
    }

    /** @test */
    public function email_bucket_voll_laeuft_silent_ohne_mail_oder_audit(): void
    {
        $this->rateLimit->method('isAllowed')->willReturn(true);
        $this->rateLimit->expects($this->once())
            ->method('isAllowedForEmail')
            ->with('opfer@example.com', 'forgot-password', 3, 3600)
            ->willReturn(false);

        // Attempt wird trotzdem gebucht, damit die Sperre fortdauert.
        $this->rateLimit->expects($this->once())
            ->method('recordAttemptForEmail')
            ->with('192.0.2.10', 'opfer@example.com', 'forgot-password');

        // Kritisch: weder Mail noch Audit.
        $this->userRepo->expects($this->never())->method('findByEmail');
        $this->emailService->expects($this->never())->method($this->anything());
        $this->auditService->expects($this->never())->method('log');

        $response = $this->controller->requestReset(
            $this->makeRequest('opfer@example.com'),
            $this->emptyResponse()
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/login', $response->getHeaderLine('Location'));

        // Gleiche Erfolgsmeldung wie im Normalfall — kein Information-Leak.
        $this->assertArrayHasKey('info', $_SESSION['_flash']);
        $this->assertStringStartsWith(
            'Falls ein Account mit dieser E-Mail existiert',
            $_SESSION['_flash']['info'][0]
        );
        $this->assertArrayNotHasKey('danger', $_SESSION['_flash']);
    }

    /** @test */
    public function normalfall_sendet_mail_und_schreibt_audit(): void
    {
        $this->rateLimit->method('isAllowed')->willReturn(true);
        $this->rateLimit->method('isAllowedForEmail')->willReturn(true);
        $this->rateLimit->expects($this->once())
            ->method('recordAttemptForEmail')
            ->with('192.0.2.10', 'opfer@example.com', 'forgot-password');

        $user = \App\Models\User::fromArray([
            'id' => '42',
            'email' => 'opfer@example.com',
            'password_hash' => 'x',
            'vorname' => 'Max',
            'nachname' => 'Test',
            'is_active' => '1',
        ]);

        $this->userRepo->expects($this->once())
            ->method('findByEmail')
            ->with('opfer@example.com')
            ->willReturn($user);
        $this->userRepo->expects($this->once())->method('createPasswordReset');

        $this->emailService->expects($this->once())
            ->method('sendPasswordResetLink')
            ->with('opfer@example.com', 'Max', $this->stringContains('/reset-password/'));

        $this->auditService->expects($this->once())
            ->method('log')
            ->with('update', 'users', 42, null, null, 'Passwort-Reset angefordert');

        $response = $this->controller->requestReset(
            $this->makeRequest('opfer@example.com'),
            $this->emptyResponse()
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/login', $response->getHeaderLine('Location'));
        $this->assertArrayHasKey('info', $_SESSION['_flash']);
    }

    /** @test */
    public function email_wird_fuer_bucket_lowercased(): void
    {
        // Eingabe mit Mischkasus: Bucket-Key muss normalisiert werden, sonst
        // koennte ein Angreifer durch wechselnde Schreibweisen den Email-Bucket
        // umgehen (je eigener Counter).
        $this->rateLimit->method('isAllowed')->willReturn(true);
        $this->rateLimit->expects($this->once())
            ->method('isAllowedForEmail')
            ->with('opfer@example.com', 'forgot-password', 3, 3600)
            ->willReturn(true);
        $this->rateLimit->expects($this->once())
            ->method('recordAttemptForEmail')
            ->with('192.0.2.10', 'opfer@example.com', 'forgot-password');

        $this->userRepo->method('findByEmail')->willReturn(null);

        $response = $this->controller->requestReset(
            $this->makeRequest('  Opfer@Example.COM  '),
            $this->emptyResponse()
        );

        // Bestaetigt, dass Mischkasus sauber redirected und nicht crasht.
        $this->assertSame(302, $response->getStatusCode());
    }

    /** @test */
    public function leere_email_bucht_ip_attempt_und_flasht_hinweis(): void
    {
        $this->rateLimit->method('isAllowed')->willReturn(true);
        $this->rateLimit->expects($this->once())
            ->method('recordAttempt')
            ->with('192.0.2.10', 'forgot-password');
        $this->rateLimit->expects($this->never())->method('isAllowedForEmail');
        $this->rateLimit->expects($this->never())->method('recordAttemptForEmail');

        $response = $this->controller->requestReset(
            $this->makeRequest(''),
            $this->emptyResponse()
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/forgot-password', $response->getHeaderLine('Location'));
        $this->assertSame(
            'Bitte geben Sie Ihre E-Mail-Adresse ein.',
            $_SESSION['_flash']['danger'][0] ?? null
        );
    }
}
