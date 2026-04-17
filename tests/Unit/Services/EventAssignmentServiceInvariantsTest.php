<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * Statische Invarianten fuer EventAssignmentService.
 *
 * Begleitet den G6-Auditor-Check: Jede Write-Methode muss
 * $this->audit->log(...) aufrufen. Die Unit-Tests mit Anonymous-Mock
 * fangen fehlende Audits NICHT — dieser Test macht einen Grep gegen
 * den Service-Code.
 */
final class EventAssignmentServiceInvariantsTest extends TestCase
{
    private const SERVICE_FILE = __DIR__ . '/../../../src/app/Services/EventAssignmentService.php';

    public function test_all_write_methods_call_audit_log(): void
    {
        $code = (string) file_get_contents(self::SERVICE_FILE);

        // Alle 7 schreibenden Service-Methoden
        $writeMethods = [
            'assignMember',
            'withdrawSelf',
            'requestCancellation',
            'approveTime',
            'rejectTime',
            'approveCancellation',
            'rejectCancellation',
        ];

        foreach ($writeMethods as $method) {
            $pattern = '/public function ' . preg_quote($method, '/')
                . '\s*\([^{]*\{(.*?)(?=public function |private function |\z)/s';

            self::assertMatchesRegularExpression(
                $pattern,
                $code,
                "EventAssignmentService::$method() nicht im Code gefunden."
            );

            preg_match($pattern, $code, $matches);
            $body = $matches[1] ?? '';

            self::assertStringContainsString(
                '$this->audit->log(',
                $body,
                "EventAssignmentService::$method() enthaelt keinen \$this->audit->log()-Aufruf "
                . '(G6-Regel: jede Business-Schreibung muss Audit-Log schreiben).'
            );
        }
    }

    public function test_self_approval_guard_covers_all_organizer_methods(): void
    {
        $code = (string) file_get_contents(self::SERVICE_FILE);

        $organizerMethods = [
            'approveTime',
            'rejectTime',
            'approveCancellation',
            'rejectCancellation',
        ];

        foreach ($organizerMethods as $method) {
            $pattern = '/public function ' . preg_quote($method, '/')
                . '\s*\([^{]*\{(.*?)(?=public function |private function |\z)/s';
            preg_match($pattern, $code, $matches);
            $body = $matches[1] ?? '';

            self::assertStringContainsString(
                'assertNoSelfApproval(',
                $body,
                "EventAssignmentService::$method() ruft nicht assertNoSelfApproval() auf "
                . '(Requirements §12.1 - kein Organisator entscheidet eigene Zusage).'
            );

            self::assertStringContainsString(
                'assertIsEventOrganizer(',
                $body,
                "EventAssignmentService::$method() ruft nicht assertIsEventOrganizer() auf "
                . '(Authorization: nur Organisator darf entscheiden).'
            );
        }
    }

    public function test_uses_status_constants_not_strings(): void
    {
        $code = (string) file_get_contents(self::SERVICE_FILE);

        // Kein Status-String-Literal im Service - alle ueber Konstanten
        foreach ([
            "'vorgeschlagen'",
            "'bestaetigt'",
            "'storniert'",
            "'storno_angefragt'",
            "'abgeschlossen'",
        ] as $literal) {
            self::assertStringNotContainsString(
                $literal,
                $code,
                "Status-Literal $literal im Service gefunden - nutze EventTaskAssignment::STATUS_*"
            );
        }
    }
}
