<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * Statische Invarianten fuer UserController::unlock() und die begleitende
 * Route. Zweck: nachtraegliche Refactorings duerfen die Audit-Pflicht und die
 * Admin-Role-Kopplung der manuellen Account-Entsperrung nicht aushebeln.
 *
 * Diese Checks folgen dem Pattern aus EventAdminControllerInvariantsTest.php
 * (G3-Lesson, 2026-04-17): kein Feature-Test-Harness im Projekt, also fangen
 * wir die kritischen Patterns regex-basiert.
 */
final class UserControllerUnlockTest extends TestCase
{
    private const CONTROLLER_PATH = __DIR__ . '/../../../src/app/Controllers/UserController.php';
    private const ROUTES_PATH     = __DIR__ . '/../../../src/config/routes.php';

    public function test_unlock_method_exists(): void
    {
        $code = (string) file_get_contents(self::CONTROLLER_PATH);

        self::assertMatchesRegularExpression(
            '/public\s+function\s+unlock\s*\(/',
            $code,
            'UserController::unlock() fehlt. Der manuelle Unlock ist dokumentiert und soll erhalten bleiben.'
        );
    }

    public function test_unlock_calls_reset_failed_attempts(): void
    {
        $code = $this->unlockBody();

        self::assertStringContainsString(
            'resetFailedAttempts',
            $code,
            'unlock() ruft resetFailedAttempts() nicht auf — ohne diesen Call bleiben locked_until/failed_login_attempts stehen.'
        );
    }

    public function test_unlock_writes_audit_log_with_config_change(): void
    {
        $code = $this->unlockBody();

        self::assertStringContainsString(
            'auditService->log',
            $code,
            'unlock() schreibt keinen Audit-Eintrag. Manuelle Entsperrung muss nachvollziehbar sein.'
        );
        self::assertStringContainsString(
            "'config_change'",
            $code,
            'Audit-Eintrag der Entsperrung muss die action "config_change" verwenden (Schema-ENUM).'
        );
    }

    public function test_unlock_route_exists_and_is_post(): void
    {
        $routes = (string) file_get_contents(self::ROUTES_PATH);

        self::assertMatchesRegularExpression(
            "#\\\$group->post\\(\s*'/users/\\{id:\\[0-9\\]\\+\\}/unlock'#",
            $routes,
            'Route POST /admin/users/{id}/unlock fehlt oder ist nicht als POST registriert.'
        );
    }

    private function unlockBody(): string
    {
        $code = (string) file_get_contents(self::CONTROLLER_PATH);

        $start = strpos($code, 'public function unlock(');
        self::assertNotFalse($start, 'unlock()-Methode nicht gefunden.');

        // Grob: von der Methoden-Signatur bis zum naechsten "public function "
        $next = strpos($code, 'public function ', $start + 10);
        $body = $next === false ? substr($code, $start) : substr($code, $start, $next - $start);

        return $body;
    }
}
