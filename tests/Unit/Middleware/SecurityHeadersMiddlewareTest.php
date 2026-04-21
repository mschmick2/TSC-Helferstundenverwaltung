<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Middleware\SecurityHeadersMiddleware;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;

/**
 * Unit-Tests fuer SecurityHeadersMiddleware.
 *
 * Deckt ab:
 *   - Statische Header (CSP, Referrer, Permissions, X-Content-Type-Options, X-Frame-Options)
 *   - HSTS nur auf HTTPS
 *   - CSP enthaelt die Saefe-Improvements-Direktiven
 */
final class SecurityHeadersMiddlewareTest extends TestCase
{
    private SecurityHeadersMiddleware $middleware;
    private RequestHandlerInterface&MockObject $handler;

    protected function setUp(): void
    {
        $this->middleware = new SecurityHeadersMiddleware();
        $this->handler = $this->createMock(RequestHandlerInterface::class);
        $this->handler->method('handle')->willReturn(new SlimResponse());
    }

    private function requestHttp(): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/dashboard');
    }

    private function requestHttps(): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest(
            'GET',
            'https://vaes.example.com/dashboard',
            ['HTTPS' => 'on']
        );
    }

    /** @test */
    public function setzt_alle_statischen_header(): void
    {
        $response = $this->middleware->process($this->requestHttp(), $this->handler);

        $this->assertNotEmpty($response->getHeaderLine('Content-Security-Policy'));
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
        $this->assertStringContainsString('geolocation=()', $response->getHeaderLine('Permissions-Policy'));
    }

    /** @test */
    public function csp_enthaelt_safe_improvement_direktiven(): void
    {
        $response = $this->middleware->process($this->requestHttp(), $this->handler);
        $csp = $response->getHeaderLine('Content-Security-Policy');

        // Safe Improvements: diese Direktiven waren vorher NICHT in der Policy.
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString('upgrade-insecure-requests', $csp);
    }

    /** @test */
    public function csp_behaelt_bootstrap_cdn_whitelist(): void
    {
        // 'unsafe-inline' bleibt im Safe-Improvements-Stand bewusst drin;
        // Nonce-Rollout ist eigene Iteration.
        $response = $this->middleware->process($this->requestHttp(), $this->handler);
        $csp = $response->getHeaderLine('Content-Security-Policy');

        $this->assertStringContainsString("'unsafe-inline'", $csp);
        $this->assertStringContainsString('https://cdn.jsdelivr.net', $csp);
    }

    /** @test */
    public function hsts_nur_ueber_https(): void
    {
        // HTTP: KEIN HSTS — sonst locked der Browser eine HTTP-Dev-URL auf HTTPS.
        $responseHttp = $this->middleware->process($this->requestHttp(), $this->handler);
        $this->assertFalse(
            $responseHttp->hasHeader('Strict-Transport-Security'),
            'HSTS darf auf HTTP nicht gesetzt werden'
        );

        // HTTPS: HSTS mit 2-Jahre-Policy.
        $responseHttps = $this->middleware->process($this->requestHttps(), $this->handler);
        $hsts = $responseHttps->getHeaderLine('Strict-Transport-Security');
        $this->assertStringContainsString('max-age=63072000', $hsts);
        $this->assertStringContainsString('includeSubDomains', $hsts);
    }

    /** @test */
    public function hsts_auch_bei_proxy_forwarded_proto_https(): void
    {
        // Strato/Load-Balancer terminieren TLS und setzen X-Forwarded-Proto.
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://internal.example/dashboard',
            ['HTTP_X_FORWARDED_PROTO' => 'https']
        );

        $response = $this->middleware->process($request, $this->handler);
        $this->assertTrue(
            $response->hasHeader('Strict-Transport-Security'),
            'HSTS muss auch hinter Reverse-Proxy mit X-Forwarded-Proto=https greifen'
        );
    }
}
