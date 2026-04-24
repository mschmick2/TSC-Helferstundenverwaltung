<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\BaseController;
use App\Exceptions\AuthorizationException;
use App\Services\AuditService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;

/**
 * Testbare Subclass, die den protected Helper nach public exponiert --
 * ohne Subclass koennte der Test den Helper nicht aufrufen.
 */
final class BaseControllerAuthorizationDenialTestSubject extends BaseController
{
    public function callHelper(
        AuthorizationException $e,
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $redirectTarget,
        string $flashType = 'danger'
    ): ResponseInterface {
        return $this->handleAuthorizationDenial(
            $e,
            $request,
            $response,
            $redirectTarget,
            $flashType
        );
    }
}

/**
 * Unit-Tests fuer BaseController::handleAuthorizationDenial (Modul 6 I8
 * G4-ROT-Fix / FU-I8-G4-0).
 *
 * Pruefen fuenf Verhaltens-Invarianten:
 *   1. logAccessDenied wird mit dem reason der Exception gerufen.
 *   2. logAccessDenied erhaelt Route und Methode aus dem Request.
 *   3. Metadata der Exception wird 1:1 weitergereicht.
 *   4. Flash-Message wird in $_SESSION geschrieben.
 *   5. Die Response bekommt Location-Header + 302.
 */
final class BaseControllerAuthorizationDenialTest extends TestCase
{
    private AuditService&MockObject $auditMock;
    private BaseControllerAuthorizationDenialTestSubject $subject;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->auditMock = $this->createMock(AuditService::class);
        BaseController::setAuditService($this->auditMock);
        $this->subject = new BaseControllerAuthorizationDenialTestSubject();
    }

    public function test_handleAuthorizationDenial_calls_logAccessDenied_with_correct_reason(): void
    {
        $this->auditMock->expects(self::once())
            ->method('logAccessDenied')
            ->with(
                self::anything(),
                self::anything(),
                'ownership_violation',
                self::anything()
            );

        $this->subject->callHelper(
            new AuthorizationException('Nope', 'ownership_violation'),
            $this->request('GET', '/entries/42'),
            new SlimResponse(),
            '/entries'
        );
    }

    public function test_handleAuthorizationDenial_calls_logAccessDenied_with_correct_route_and_method(): void
    {
        $this->auditMock->expects(self::once())
            ->method('logAccessDenied')
            ->with(
                '/entries/42/approve',
                'POST',
                self::anything(),
                self::anything()
            );

        $this->subject->callHelper(
            new AuthorizationException('Nope'),
            $this->request('POST', '/entries/42/approve'),
            new SlimResponse(),
            '/entries/42'
        );
    }

    public function test_handleAuthorizationDenial_passes_metadata_from_exception(): void
    {
        $this->auditMock->expects(self::once())
            ->method('logAccessDenied')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                ['required_roles' => ['pruefer'], 'entry_id' => 7]
            );

        $this->subject->callHelper(
            new AuthorizationException(
                'Nope',
                'missing_role',
                ['required_roles' => ['pruefer'], 'entry_id' => 7]
            ),
            $this->request('POST', '/entries/7/approve'),
            new SlimResponse(),
            '/entries/7'
        );
    }

    public function test_handleAuthorizationDenial_flashes_message(): void
    {
        $this->auditMock->method('logAccessDenied');

        $this->subject->callHelper(
            new AuthorizationException('Keine Berechtigung.'),
            $this->request('POST', '/entries'),
            new SlimResponse(),
            '/entries',
            'danger'
        );

        self::assertSame(
            ['danger' => ['Keine Berechtigung.']],
            $_SESSION['_flash'] ?? null
        );
    }

    public function test_handleAuthorizationDenial_redirects_to_target(): void
    {
        $this->auditMock->method('logAccessDenied');

        $response = $this->subject->callHelper(
            new AuthorizationException('x'),
            $this->request('POST', '/any'),
            new SlimResponse(),
            '/entries/123'
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/entries/123', $response->getHeaderLine('Location'));
    }

    public function test_handleAuthorizationDenial_works_without_audit_bootstrap(): void
    {
        // Defensiv: falls der Bootstrap-Setter nicht aufgerufen wurde
        // (z.B. fruehes Unit-Test-Szenario), darf der Helper nicht
        // werfen -- Flash und Redirect muessen weiter funktionieren.
        $reflection = new \ReflectionClass(BaseController::class);
        $prop = $reflection->getProperty('auditService');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $response = $this->subject->callHelper(
            new AuthorizationException('x'),
            $this->request('POST', '/any'),
            new SlimResponse(),
            '/entries'
        );

        self::assertSame(302, $response->getStatusCode());
    }

    private function request(string $method, string $path): ServerRequestInterface
    {
        $factory = new ServerRequestFactory();
        return $factory->createServerRequest($method, $path);
    }
}
