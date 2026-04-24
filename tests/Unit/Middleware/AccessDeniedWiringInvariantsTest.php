<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;

/**
 * Statische Invarianten fuer die Audit-bei-Authorization-Denial-
 * Verkabelung aus Modul 6 I8 Phase 1 (Follow-up v).
 *
 * Regex-/Substring-Pruefungen analog zu den Lock-Invarianten aus
 * I7e-B. Ziel: die Drei-Punkte-Kette RoleMiddleware / CsrfMiddleware
 * / Slim-ErrorHandler bleibt durch kuenftiges Refactoring sichtbar.
 */
final class AccessDeniedWiringInvariantsTest extends TestCase
{
    private const AUDIT_SERVICE =
        __DIR__ . '/../../../src/app/Services/AuditService.php';
    private const AUTH_EXCEPTION =
        __DIR__ . '/../../../src/app/Exceptions/AuthorizationException.php';
    private const ROLE_MIDDLEWARE =
        __DIR__ . '/../../../src/app/Middleware/RoleMiddleware.php';
    private const CSRF_MIDDLEWARE =
        __DIR__ . '/../../../src/app/Middleware/CsrfMiddleware.php';
    private const INDEX_PHP =
        __DIR__ . '/../../../src/public/index.php';
    private const DEPENDENCIES =
        __DIR__ . '/../../../src/config/dependencies.php';

    private function read(string $path): string
    {
        return (string) file_get_contents($path);
    }

    // =========================================================================
    // AuditService::logAccessDenied — Methoden-Signatur und Verhalten
    // =========================================================================

    public function test_audit_service_has_log_access_denied_method(): void
    {
        $code = $this->read(self::AUDIT_SERVICE);
        self::assertMatchesRegularExpression(
            '/public\s+function\s+logAccessDenied\s*\(/',
            $code,
            'AuditService muss eine public-Methode logAccessDenied haben.'
        );
    }

    public function test_log_access_denied_catches_throwable_for_resilience(): void
    {
        $code = $this->read(self::AUDIT_SERVICE);
        self::assertMatchesRegularExpression(
            '/catch\s*\(\s*\\\\?Throwable\s+\$/',
            $code,
            'logAccessDenied muss Throwable fangen -- Audit-Failure darf '
            . 'die App-Verfuegbarkeit nicht blockieren (Architect Q5).'
        );
    }

    public function test_log_access_denied_uses_access_denied_action(): void
    {
        $code = $this->read(self::AUDIT_SERVICE);
        self::assertMatchesRegularExpression(
            "/action:\s*'access_denied'/",
            $code,
            'logAccessDenied muss die ENUM-Zeile access_denied nutzen '
            . '(nicht config_change-Misbrauch oder aehnliches).'
        );
    }

    // =========================================================================
    // AuthorizationException — reason + metadata
    // =========================================================================

    public function test_authorization_exception_has_reason_getter(): void
    {
        $code = $this->read(self::AUTH_EXCEPTION);
        self::assertMatchesRegularExpression(
            '/public\s+function\s+getReason\s*\(\s*\)\s*:\s*string/',
            $code,
            'AuthorizationException muss getReason(): string anbieten.'
        );
    }

    public function test_authorization_exception_has_metadata_getter(): void
    {
        $code = $this->read(self::AUTH_EXCEPTION);
        self::assertMatchesRegularExpression(
            '/public\s+function\s+getMetadata\s*\(\s*\)\s*:\s*array/',
            $code,
            'AuthorizationException muss getMetadata(): array anbieten.'
        );
    }

    public function test_authorization_exception_reason_default_missing_role(): void
    {
        $code = $this->read(self::AUTH_EXCEPTION);
        // Default ist 'missing_role' -- die vier bestehenden throw-
        // Aufrufer (BaseController, WorkflowService, WorkEntryController,
        // EventAssignmentService) nutzen keinen reason-Parameter, also
        // muss der Default semantisch passen.
        self::assertMatchesRegularExpression(
            "/string\s+\\\$reason\s*=\s*'missing_role'/",
            $code,
            'Default-Reason in AuthorizationException muss missing_role '
            . 'sein, damit die 4 bestehenden Aufrufer ohne reason-Param '
            . 'weiter funktionieren.'
        );
    }

    // =========================================================================
    // RoleMiddleware — static-setter + logAccessDenied-Aufruf
    // =========================================================================

    public function test_role_middleware_has_static_audit_service_setter(): void
    {
        $code = $this->read(self::ROLE_MIDDLEWARE);
        self::assertMatchesRegularExpression(
            '/public\s+static\s+function\s+setAuditService\s*\(/',
            $code,
            'RoleMiddleware muss setAuditService als statischen '
            . 'Bootstrap-Setter haben (Architect-Entscheidung zur '
            . 'Vermeidung des routes.php-Refactorings).'
        );
    }

    public function test_role_middleware_logs_missing_role_before_redirect(): void
    {
        $code = $this->read(self::ROLE_MIDDLEWARE);
        self::assertStringContainsString(
            'logAccessDenied',
            $code,
            'RoleMiddleware muss logAccessDenied aufrufen bei Rollen-Denial.'
        );
        self::assertMatchesRegularExpression(
            "/reason:\s*'missing_role'/",
            $code,
            'RoleMiddleware muss reason=missing_role setzen.'
        );

        // Audit muss VOR dem Redirect stehen -- sonst entgeht der
        // Eintrag, wenn die Response irgendwo upstream ueberschrieben
        // wird.
        $posLog = strpos($code, 'logAccessDenied');
        $posRedirect = strpos($code, "withHeader('Location'");
        self::assertNotFalse($posLog, 'logAccessDenied fehlt.');
        // withHeader taucht an zwei Stellen auf (unauthenticated-Redirect
        // oben, Rollen-Denial-Redirect unten). Wir suchen den letzten
        // Treffer, weil der die Denial-Stelle ist.
        $posRedirectLast = strrpos($code, "withHeader('Location'");
        self::assertNotFalse($posRedirectLast, 'Denial-Redirect fehlt.');
        self::assertLessThan(
            $posRedirectLast,
            $posLog,
            'logAccessDenied muss VOR dem Rollen-Denial-Redirect stehen.'
        );
    }

    // =========================================================================
    // CsrfMiddleware — Constructor-Injection + logAccessDenied-Aufruf
    // =========================================================================

    public function test_csrf_middleware_accepts_audit_service_via_constructor(): void
    {
        $code = $this->read(self::CSRF_MIDDLEWARE);
        self::assertMatchesRegularExpression(
            '/public\s+function\s+__construct\s*\([^)]*AuditService/s',
            $code,
            'CsrfMiddleware muss AuditService im Konstruktor akzeptieren.'
        );
    }

    public function test_csrf_middleware_logs_csrf_invalid_reason(): void
    {
        $code = $this->read(self::CSRF_MIDDLEWARE);
        self::assertStringContainsString(
            'logAccessDenied',
            $code,
            'CsrfMiddleware muss logAccessDenied bei CSRF-Failure aufrufen.'
        );
        self::assertMatchesRegularExpression(
            "/reason:\s*'csrf_invalid'/",
            $code,
            'CsrfMiddleware muss reason=csrf_invalid setzen.'
        );
    }

    // =========================================================================
    // index.php — ErrorHandler + RoleMiddleware-Bootstrap
    // =========================================================================

    public function test_index_php_registers_authorization_exception_handler(): void
    {
        $code = $this->read(self::INDEX_PHP);
        self::assertStringContainsString(
            'setErrorHandler',
            $code,
            'index.php muss einen setErrorHandler-Aufruf haben.'
        );
        self::assertMatchesRegularExpression(
            '/AuthorizationException::class/',
            $code,
            'setErrorHandler muss auf AuthorizationException::class '
            . 'registriert sein.'
        );
    }

    public function test_index_php_bootstraps_role_middleware_audit(): void
    {
        $code = $this->read(self::INDEX_PHP);
        self::assertMatchesRegularExpression(
            '/RoleMiddleware::setAuditService\s*\(/',
            $code,
            'index.php muss RoleMiddleware::setAuditService im Bootstrap '
            . 'aufrufen, damit die Middleware den AuditService sieht.'
        );
    }

    // =========================================================================
    // dependencies.php — CsrfMiddleware-Factory mit AuditService
    // =========================================================================

    public function test_dependencies_csrf_factory_injects_audit_service(): void
    {
        $code = $this->read(self::DEPENDENCIES);
        // Wir suchen den CsrfMiddleware-Factory-Body. Er muss
        // AuditService::class anziehen.
        $pattern = '/CsrfMiddleware::class\s*=>\s*function[^{]*\{(.*?)\},/s';
        if (!preg_match($pattern, $code, $m)) {
            self::fail('CsrfMiddleware-Factory nicht auffindbar.');
        }
        self::assertStringContainsString(
            'AuditService::class',
            $m[1],
            'CsrfMiddleware-Factory muss AuditService::class aus dem '
            . 'Container ziehen.'
        );
    }
}
