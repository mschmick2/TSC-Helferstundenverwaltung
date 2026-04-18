<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * Statische Code-Invarianten fuer EventTemplateController (Modul 6 I4).
 *
 * Im Gegensatz zu EventAdminController ruft der TemplateController die
 * Audit-Calls groesstenteils indirekt ueber EventTemplateService auf. Wir
 * pruefen hier stattdessen:
 *   - keine $user['...']-Zugriffe (G3-Regression)
 *   - alle I4-Endpoints existieren
 *   - Exception-Handling (ValidationException + BusinessRuleException)
 */
final class EventTemplateControllerInvariantsTest extends TestCase
{
    private const FILE = __DIR__ . '/../../../src/app/Controllers/EventTemplateController.php';

    private function code(): string
    {
        return (string) file_get_contents(self::FILE);
    }

    public function test_no_array_access_on_user_object(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/\$user\[[\'"]/',
            $this->code(),
            "EventTemplateController: \$user['key'] Array-Access gefunden. "
            . 'Nutze \$user->getId(). Regression des G3-Findings vom 2026-04-17.'
        );
    }

    public function test_all_i4_endpoints_exist(): void
    {
        $code = $this->code();

        foreach ([
            'edit', 'addTask', 'updateTask', 'deleteTask',
            'saveAsNewVersion', 'deriveForm', 'deriveStore',
        ] as $method) {
            self::assertMatchesRegularExpression(
                '/public function ' . $method . '\s*\(/',
                $code,
                "EventTemplateController::$method() (I4-Endpoint) fehlt."
            );
        }
    }

    public function test_write_endpoints_handle_business_rule_exception(): void
    {
        $code = $this->code();

        // Jeder Write-Endpoint muss mind. BusinessRuleException abfangen
        foreach ([
            'addTask', 'updateTask', 'deleteTask',
            'saveAsNewVersion', 'deriveStore',
        ] as $method) {
            $pattern = '/public function ' . $method . '\s*\([^{]*\{(.*?)(?=public function |\}\s*$)/s';
            preg_match($pattern, $code, $matches);
            $body = $matches[1] ?? '';

            self::assertStringContainsString(
                'BusinessRuleException',
                $body,
                "EventTemplateController::$method() muss BusinessRuleException abfangen "
                . '(G3-Pattern: Business-Fehler werden als Flash-Message gerendert).'
            );
        }
    }

    public function test_input_validating_endpoints_handle_validation_exception(): void
    {
        $code = $this->code();

        // Nur Endpoints mit User-Input-Validierung
        foreach ([
            'addTask', 'updateTask', 'saveAsNewVersion', 'deriveStore',
        ] as $method) {
            $pattern = '/public function ' . $method . '\s*\([^{]*\{(.*?)(?=public function |\}\s*$)/s';
            preg_match($pattern, $code, $matches);
            $body = $matches[1] ?? '';

            self::assertStringContainsString(
                'ValidationException',
                $body,
                "EventTemplateController::$method() muss ValidationException abfangen "
                . '(Input-Validierung durch Service).'
            );
        }
    }

    public function test_derive_flow_redirects_to_event_on_success(): void
    {
        $code = $this->code();

        self::assertMatchesRegularExpression(
            '/function\s+deriveStore.*?redirect.*?\/admin\/events\/.*?eventId/s',
            $code,
            'deriveStore() muss bei Erfolg zum neuen Event redirecten.'
        );
    }
}
