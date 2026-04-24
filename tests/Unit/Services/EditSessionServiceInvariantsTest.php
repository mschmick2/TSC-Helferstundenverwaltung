<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\EditSessionView;
use App\Repositories\EditSessionRepository;
use App\Services\EditSessionService;
use App\Services\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Invarianten fuer EditSessionService (Modul 6 I7e-C.1 Phase 1).
 *
 * Sichert das asymmetrische Flag-Verhalten ab:
 *   - start / heartbeat / listActiveForEvent respektieren das Feature-Flag.
 *   - close funktioniert unabhaengig vom Flag (Client soll sauber
 *     aufraeumen koennen, auch wenn Admin das Feature mitten in einer
 *     laufenden Session abschaltet).
 */
final class EditSessionServiceInvariantsTest extends TestCase
{
    private function service(bool $flagEnabled): EditSessionService
    {
        $repo = $this->createMock(EditSessionRepository::class);
        $settings = $this->createMock(SettingsService::class);
        $settings->method('editSessionsEnabled')->willReturn($flagEnabled);
        return new EditSessionService($repo, $settings);
    }

    private function serviceWithRepo(
        EditSessionRepository $repo,
        bool $flagEnabled,
    ): EditSessionService {
        $settings = $this->createMock(SettingsService::class);
        $settings->method('editSessionsEnabled')->willReturn($flagEnabled);
        return new EditSessionService($repo, $settings);
    }

    public function test_startSession_throws_when_feature_disabled(): void
    {
        $service = $this->service(false);
        $this->expectException(BusinessRuleException::class);
        $service->startSession(10, 42, 'abc123');
    }

    public function test_startSession_returns_repo_create_result_when_enabled(): void
    {
        $repo = $this->createMock(EditSessionRepository::class);
        $repo->expects($this->once())
            ->method('create')
            ->with(10, 42, 'abc123')
            ->willReturn(777);

        $service = $this->serviceWithRepo($repo, true);
        self::assertSame(777, $service->startSession(10, 42, 'abc123'));
    }

    public function test_heartbeat_returns_false_when_feature_disabled(): void
    {
        $repo = $this->createMock(EditSessionRepository::class);
        $repo->expects($this->never())->method('heartbeat');

        $service = $this->serviceWithRepo($repo, false);
        self::assertFalse($service->heartbeat(777, 10));
    }

    public function test_heartbeat_delegates_to_repo_when_enabled(): void
    {
        $repo = $this->createMock(EditSessionRepository::class);
        $repo->expects($this->once())
            ->method('heartbeat')
            ->with(777, 10)
            ->willReturn(true);

        $service = $this->serviceWithRepo($repo, true);
        self::assertTrue($service->heartbeat(777, 10));
    }

    public function test_close_delegates_to_repo_when_feature_enabled(): void
    {
        $repo = $this->createMock(EditSessionRepository::class);
        $repo->expects($this->once())
            ->method('close')
            ->with(777, 10)
            ->willReturn(true);

        $service = $this->serviceWithRepo($repo, true);
        self::assertTrue($service->close(777, 10));
    }

    public function test_close_delegates_to_repo_even_when_feature_disabled(): void
    {
        // Asymmetrie gegenueber start/heartbeat: close MUSS laufen, auch
        // wenn das Feature inzwischen aus ist — sonst bleiben dangling
        // Sessions bis zum Timeout stehen.
        $repo = $this->createMock(EditSessionRepository::class);
        $repo->expects($this->once())
            ->method('close')
            ->with(777, 10)
            ->willReturn(true);

        $service = $this->serviceWithRepo($repo, false);
        self::assertTrue($service->close(777, 10));
    }

    public function test_listActiveForEvent_returns_empty_when_feature_disabled(): void
    {
        $repo = $this->createMock(EditSessionRepository::class);
        $repo->expects($this->never())->method('findActiveByEventId');

        $service = $this->serviceWithRepo($repo, false);
        self::assertSame([], $service->listActiveForEvent(42));
    }

    public function test_listActiveForEvent_delegates_to_repo_when_enabled(): void
    {
        $view = new EditSessionView(
            sessionId: 1,
            userId: 10,
            vorname: 'Max',
            nachname: 'Mustermann',
            startedAt: '2026-04-24 12:00:00',
            lastSeenAt: '2026-04-24 12:03:00',
            durationSeconds: 180,
        );

        $repo = $this->createMock(EditSessionRepository::class);
        $repo->expects($this->once())
            ->method('findActiveByEventId')
            ->with(42)
            ->willReturn([$view]);

        $service = $this->serviceWithRepo($repo, true);
        $result = $service->listActiveForEvent(42);

        self::assertCount(1, $result);
        self::assertSame($view, $result[0]);
    }
}
