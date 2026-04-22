<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * Statische Invarianten fuer WorkflowService::returnToDraft() und die begleitende
 * Route, Controller-Methode und View-Integration. Zweck: nachtraegliche
 * Refactorings duerfen das Pruefer-Feature "Zurueck zur Ueberarbeitung" nicht
 * aushebeln (CLAUDE.md §6 Lifecycle: Uebergang eingereicht/in_klaerung -> entwurf
 * durch den Pruefer, mit Audit-Eintrag und Pflicht-Begruendung).
 *
 * Folgt dem Pattern aus UserControllerUnlockTest (G3-Lesson, 2026-04-17): kein
 * Feature-Test-Harness im Projekt, also fangen wir die kritischen Patterns
 * regex-basiert.
 */
final class WorkflowReturnToDraftTest extends TestCase
{
    private const SERVICE_PATH    = __DIR__ . '/../../../src/app/Services/WorkflowService.php';
    private const CONTROLLER_PATH = __DIR__ . '/../../../src/app/Controllers/WorkEntryController.php';
    private const ROUTES_PATH     = __DIR__ . '/../../../src/config/routes.php';
    private const VIEW_PATH       = __DIR__ . '/../../../src/app/Views/entries/show.php';

    public function test_service_method_exists(): void
    {
        $code = (string) file_get_contents(self::SERVICE_PATH);

        self::assertMatchesRegularExpression(
            '/public\s+function\s+returnToDraft\s*\(/',
            $code,
            'WorkflowService::returnToDraft() fehlt. Pruefer-Aktion "Zurueck zur Ueberarbeitung" ist dokumentiert und muss erhalten bleiben.'
        );
    }

    public function test_service_transitions_to_entwurf(): void
    {
        $body = $this->serviceBody();

        self::assertMatchesRegularExpression(
            "/assertTransition\\s*\\(\\s*\\\$entry\\s*,\\s*'entwurf'\\s*\\)/",
            $body,
            'returnToDraft() muss den Ziel-Status "entwurf" gegen assertTransition pruefen.'
        );
    }

    public function test_service_requires_review_permission(): void
    {
        $body = $this->serviceBody();

        self::assertStringContainsString(
            'assertReviewPermission',
            $body,
            'returnToDraft() muss assertReviewPermission aufrufen — sonst koennte ein Mitglied die Pruefer-Aktion missbrauchen.'
        );
    }

    public function test_service_requires_reason(): void
    {
        $body = $this->serviceBody();

        self::assertMatchesRegularExpression(
            '/trim\\(\\s*\\$reason\\s*\\)\\s*===\\s*\'\'/',
            $body,
            'returnToDraft() muss eine leere Begruendung ablehnen — Audit-Pflicht fuer Statuswechsel.'
        );
    }

    public function test_service_writes_audit_status_change(): void
    {
        $body = $this->serviceBody();

        self::assertStringContainsString(
            'auditService->log',
            $body,
            'returnToDraft() schreibt keinen Audit-Eintrag. Statuswechsel muss nachvollziehbar sein.'
        );
        self::assertStringContainsString(
            "'status_change'",
            $body,
            'Audit-Eintrag muss die action "status_change" verwenden (Schema-ENUM).'
        );
    }

    public function test_service_preserves_dialog(): void
    {
        $body = $this->serviceBody();

        self::assertStringContainsString(
            'dialogRepo->create',
            $body,
            'returnToDraft() muss die Begruendung als Dialog-Nachricht sichern — Dialog-Integritaet gem. CLAUDE.md §3.8.'
        );
    }

    public function test_controller_method_exists(): void
    {
        $code = (string) file_get_contents(self::CONTROLLER_PATH);

        self::assertMatchesRegularExpression(
            '/public\s+function\s+returnToDraft\s*\(/',
            $code,
            'WorkEntryController::returnToDraft() fehlt.'
        );
    }

    public function test_route_exists_and_is_post(): void
    {
        $routes = (string) file_get_contents(self::ROUTES_PATH);

        self::assertMatchesRegularExpression(
            "#\\\$group->post\\(\\s*'/entries/\\{id:\\[0-9\\]\\+\\}/return-to-draft'#",
            $routes,
            'Route POST /entries/{id}/return-to-draft fehlt oder ist nicht als POST registriert.'
        );
    }

    public function test_view_has_button_and_modal(): void
    {
        $view = (string) file_get_contents(self::VIEW_PATH);

        self::assertStringContainsString(
            'returnToDraftModal',
            $view,
            'Button oder Modal "Zurueck zur Ueberarbeitung" fehlt in show.php — Feature ist fuer Pruefer unzugaenglich.'
        );
        self::assertStringContainsString(
            '/return-to-draft',
            $view,
            'Form im Modal postet nicht an /return-to-draft.'
        );
    }

    private function serviceBody(): string
    {
        $code = (string) file_get_contents(self::SERVICE_PATH);

        $start = strpos($code, 'public function returnToDraft(');
        self::assertNotFalse($start, 'returnToDraft()-Methode nicht gefunden.');

        $next = strpos($code, 'public function ', $start + 10);
        return $next === false ? substr($code, $start) : substr($code, $start, $next - $start);
    }
}
