<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AuditService;
use PHPUnit\Framework\TestCase;

/**
 * Tests fuer AuditService::logAccessDenied (Modul 6 I8 Phase 1, C11).
 *
 * Wir testen Verhalten via Partial-Mock: eine Test-Subklasse
 * ueberschreibt die public `log()`-Methode und haelt die letzten
 * Aufruf-Argumente fest. Das entkoppelt den Test von der DB-Ebene
 * (kein PDO-Setup) und pinnt die Delegation an das oeffentliche
 * AuditService-Verhalten.
 */
final class AuditServiceAccessDeniedTest extends TestCase
{
    public function test_logAccessDenied_writes_access_denied_action(): void
    {
        $service = $this->service();
        $service->logAccessDenied('/admin/events/42/editor', 'POST', 'missing_role');

        self::assertSame('access_denied', $service->lastAction);
    }

    public function test_logAccessDenied_delegates_with_description(): void
    {
        $service = $this->service();
        $service->logAccessDenied('/admin/events/42', 'POST', 'not_organizer');

        self::assertNotNull($service->lastDescription);
        self::assertStringContainsString(
            'Authorization denied',
            $service->lastDescription
        );
        self::assertStringContainsString(
            'not_organizer',
            $service->lastDescription
        );
    }

    public function test_logAccessDenied_includes_route_method_reason_in_metadata(): void
    {
        $service = $this->service();
        $service->logAccessDenied('/admin/events/1', 'post', 'missing_role');

        self::assertIsArray($service->lastMetadata);
        self::assertSame('/admin/events/1', $service->lastMetadata['route']);
        self::assertSame('POST', $service->lastMetadata['method']); // uppercased
        self::assertSame('missing_role', $service->lastMetadata['reason']);
    }

    public function test_logAccessDenied_uppercases_method(): void
    {
        $service = $this->service();
        $service->logAccessDenied('/foo', 'get', 'csrf_invalid');
        self::assertSame('GET', $service->lastMetadata['method']);
    }

    public function test_logAccessDenied_passes_extra_metadata_through(): void
    {
        $service = $this->service();
        $service->logAccessDenied(
            '/admin/events/5',
            'POST',
            'missing_role',
            ['required_roles' => ['administrator'], 'extra' => 'x']
        );

        self::assertSame(
            ['administrator'],
            $service->lastMetadata['required_roles']
        );
        self::assertSame('x', $service->lastMetadata['extra']);
        // Kern-Felder bleiben erhalten, custom ueberschreiben sie nicht.
        self::assertSame('missing_role', $service->lastMetadata['reason']);
    }

    public function test_logAccessDenied_never_throws_on_inner_failure(): void
    {
        // Wenn die interne log()-Methode eine Exception wirft, soll
        // logAccessDenied das abfangen -- Audit darf die App-
        // Verfuegbarkeit nicht blockieren (Architect Q5).
        $service = new class (new \PDO('sqlite::memory:')) extends AuditService {
            public function log(
                string $action,
                ?string $tableName = null,
                ?int $recordId = null,
                ?array $oldValues = null,
                ?array $newValues = null,
                ?string $description = null,
                ?string $entryNumber = null,
                ?array $metadata = null
            ): void {
                throw new \RuntimeException('simulated DB failure');
            }
        };

        // Darf NICHT werfen.
        $service->logAccessDenied('/foo', 'POST', 'missing_role');
        self::assertTrue(true, 'logAccessDenied fing RuntimeException ab');
    }

    public function test_logAccessDenied_preserves_reason_for_rate_limited(): void
    {
        // Architect-C13: Rate-Limit-Overflow loggt mit reason='rate_limited'.
        // Wir pinnen, dass diese spezifische reason-Zeichenkette durchgeht.
        $service = $this->service();
        $service->logAccessDenied(
            '/api/edit-sessions/42/heartbeat',
            'POST',
            'rate_limited',
            ['limit' => 4, 'window' => 60]
        );

        self::assertSame('rate_limited', $service->lastMetadata['reason']);
        self::assertSame(4, $service->lastMetadata['limit']);
        self::assertSame(60, $service->lastMetadata['window']);
    }

    // =========================================================================
    // Helfer
    // =========================================================================

    /**
     * Liefert einen AuditService mit ueberschriebener log()-Methode, die
     * die letzten Aufruf-Argumente als public Properties freigibt.
     */
    private function service(): AuditService
    {
        return new class (new \PDO('sqlite::memory:')) extends AuditService {
            public ?string $lastAction = null;
            public ?string $lastDescription = null;
            public ?array $lastMetadata = null;

            public function log(
                string $action,
                ?string $tableName = null,
                ?int $recordId = null,
                ?array $oldValues = null,
                ?array $newValues = null,
                ?string $description = null,
                ?string $entryNumber = null,
                ?array $metadata = null
            ): void {
                $this->lastAction = $action;
                $this->lastDescription = $description;
                $this->lastMetadata = $metadata;
            }
        };
    }
}
