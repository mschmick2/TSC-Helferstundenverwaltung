<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Middleware\RateLimitMiddleware;
use App\Models\User;
use App\Services\AuditService;
use App\Services\RateLimitService;
use App\Services\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;

/**
 * Unit-Tests fuer RateLimitMiddleware (Modul 6 I8 Phase 2 / FU-G4-1).
 *
 * Mockt die drei Dependencies (RateLimitService, SettingsService,
 * AuditService) und prueft das Verhalten bei:
 *   - Request unter dem Limit -> Pass-through mit Attempt-Record.
 *   - Request ueber dem Limit -> 429 + Audit-Eintrag (C13).
 *   - Request ohne User-Attribut -> Pass-through.
 *   - Settings-Lesung via getInt.
 */
final class RateLimitMiddlewareTest extends TestCase
{
    public function test_allows_request_when_under_limit(): void
    {
        $rateLimiter = $this->createMock(RateLimitService::class);
        $rateLimiter->method('isAllowedForUser')->willReturn(true);
        $rateLimiter->expects($this->once())
            ->method('recordAttemptForUser')
            ->with(42, $this->anything(), 'tree_action');

        $middleware = $this->middleware($rateLimiter);
        $handler = $this->handler();
        $request = $this->request(withUser: true);

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_returns_429_when_limit_exceeded(): void
    {
        $rateLimiter = $this->createMock(RateLimitService::class);
        $rateLimiter->method('isAllowedForUser')->willReturn(false);

        $middleware = $this->middleware($rateLimiter);
        $handler = $this->handler();
        $request = $this->request(withUser: true);

        $response = $middleware->process($request, $handler);

        self::assertSame(429, $response->getStatusCode());
    }

    public function test_429_includes_retry_after_header(): void
    {
        $rateLimiter = $this->createMock(RateLimitService::class);
        $rateLimiter->method('isAllowedForUser')->willReturn(false);

        $settings = $this->createMock(SettingsService::class);
        $settings->method('getInt')->willReturnMap([
            ['security.tree_action_rate_limit_max', 60, 60],
            ['security.tree_action_rate_limit_window', 60, 90],
        ]);

        $audit = $this->createMock(AuditService::class);

        $middleware = new RateLimitMiddleware(
            $rateLimiter,
            $settings,
            $audit,
            'tree_action',
            'security.tree_action_rate_limit_max',
            'security.tree_action_rate_limit_window'
        );

        $response = $middleware->process(
            $this->request(withUser: true),
            $this->handler()
        );

        self::assertSame('90', $response->getHeaderLine('Retry-After'));
    }

    public function test_429_includes_json_error_body(): void
    {
        $rateLimiter = $this->createMock(RateLimitService::class);
        $rateLimiter->method('isAllowedForUser')->willReturn(false);

        $middleware = $this->middleware($rateLimiter);
        $response = $middleware->process(
            $this->request(withUser: true),
            $this->handler()
        );

        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        self::assertIsArray($data);
        self::assertSame('rate_limited', $data['error']);
        self::assertArrayHasKey('retry_after', $data);
    }

    public function test_429_triggers_audit_log_access_denied_with_rate_limited_reason(): void
    {
        $rateLimiter = $this->createMock(RateLimitService::class);
        $rateLimiter->method('isAllowedForUser')->willReturn(false);

        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->once())
            ->method('logAccessDenied')
            ->with(
                '/admin/events/42/tasks/reorder',
                'POST',
                'rate_limited',
                $this->callback(function ($metadata) {
                    return is_array($metadata)
                        && $metadata['bucket'] === 'tree_action'
                        && isset($metadata['limit'])
                        && isset($metadata['window_seconds']);
                })
            );

        $settings = $this->settingsMock();
        $middleware = new RateLimitMiddleware(
            $rateLimiter,
            $settings,
            $audit,
            'tree_action',
            'security.tree_action_rate_limit_max',
            'security.tree_action_rate_limit_window'
        );

        $request = $this->request(
            withUser: true,
            path: '/admin/events/42/tasks/reorder'
        );
        $middleware->process($request, $this->handler());
    }

    public function test_passes_through_when_user_not_authenticated(): void
    {
        $rateLimiter = $this->createMock(RateLimitService::class);
        // Service-Methoden duerfen NICHT aufgerufen werden.
        $rateLimiter->expects($this->never())->method('isAllowedForUser');
        $rateLimiter->expects($this->never())->method('recordAttemptForUser');

        $middleware = $this->middleware($rateLimiter);
        $response = $middleware->process(
            $this->request(withUser: false),
            $this->handler()
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_reads_max_and_window_from_settings(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->expects($this->exactly(2))->method('getInt')
            ->willReturnMap([
                ['security.tree_action_rate_limit_max', 60, 120],
                ['security.tree_action_rate_limit_window', 60, 300],
            ]);

        $rateLimiter = $this->createMock(RateLimitService::class);
        $rateLimiter->expects($this->once())
            ->method('isAllowedForUser')
            ->with(42, 'tree_action', 120, 300)
            ->willReturn(true);

        $middleware = new RateLimitMiddleware(
            $rateLimiter,
            $settings,
            $this->createMock(AuditService::class),
            'tree_action',
            'security.tree_action_rate_limit_max',
            'security.tree_action_rate_limit_window'
        );

        $middleware->process($this->request(withUser: true), $this->handler());
    }

    public function test_records_attempt_before_handler_so_exceptions_still_count(): void
    {
        // Architect-Q8/Fallstrick 4: recordAttempt muss VOR dem Handler-
        // Call passieren, damit ein exception-werfender Handler nicht
        // die Zaehlung umgeht.
        $rateLimiter = $this->createMock(RateLimitService::class);
        $rateLimiter->method('isAllowedForUser')->willReturn(true);

        $sequence = [];
        $rateLimiter->expects($this->once())
            ->method('recordAttemptForUser')
            ->willReturnCallback(function () use (&$sequence) {
                $sequence[] = 'record';
            });

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')
            ->willReturnCallback(function () use (&$sequence) {
                $sequence[] = 'handle';
                return new SlimResponse();
            });

        $middleware = $this->middleware($rateLimiter);
        $middleware->process($this->request(withUser: true), $handler);

        self::assertSame(['record', 'handle'], $sequence);
    }

    public function test_records_attempt_with_ip_from_request(): void
    {
        $rateLimiter = $this->createMock(RateLimitService::class);
        $rateLimiter->method('isAllowedForUser')->willReturn(true);
        $rateLimiter->expects($this->once())
            ->method('recordAttemptForUser')
            ->with(42, '203.0.113.5', 'tree_action');

        $middleware = $this->middleware($rateLimiter);
        $request = $this->request(withUser: true, remoteAddr: '203.0.113.5');
        $middleware->process($request, $this->handler());
    }

    // =========================================================================
    // Helfer
    // =========================================================================

    private function middleware(RateLimitService $rateLimiter): RateLimitMiddleware
    {
        return new RateLimitMiddleware(
            $rateLimiter,
            $this->settingsMock(),
            $this->createMock(AuditService::class),
            'tree_action',
            'security.tree_action_rate_limit_max',
            'security.tree_action_rate_limit_window'
        );
    }

    private function settingsMock(): SettingsService
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->method('getInt')->willReturnCallback(function ($key, $default) {
            return $default;
        });
        return $settings;
    }

    private function handler(): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new SlimResponse());
        return $handler;
    }

    private function request(
        bool $withUser,
        string $path = '/admin/events/42/tasks/reorder',
        string $remoteAddr = '127.0.0.1'
    ): ServerRequestInterface {
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest('POST', $path, ['REMOTE_ADDR' => $remoteAddr]);

        if ($withUser) {
            $user = $this->createMock(User::class);
            $user->method('getId')->willReturn(42);
            $request = $request->withAttribute('user', $user);
        }

        return $request;
    }
}
