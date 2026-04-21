<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Repositories\ScheduledJobRepository;
use App\Repositories\SchedulerRunRepository;
use App\Repositories\SettingsRepository;
use App\Services\Jobs\JobHandler;
use App\Services\Jobs\JobHandlerRegistry;
use App\Services\SchedulerService;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class SchedulerServiceTest extends TestCase
{
    private ScheduledJobRepository&MockObject $jobs;
    private SchedulerRunRepository&MockObject $runs;
    private SettingsRepository&MockObject $settings;
    private JobHandlerRegistry&MockObject $registry;
    private LoggerInterface&MockObject $logger;
    private SchedulerService $service;

    protected function setUp(): void
    {
        $this->jobs = $this->createMock(ScheduledJobRepository::class);
        $this->runs = $this->createMock(SchedulerRunRepository::class);
        $this->settings = $this->createMock(SettingsRepository::class);
        $this->registry = $this->createMock(JobHandlerRegistry::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new SchedulerService(
            $this->jobs,
            $this->runs,
            $this->settings,
            $this->registry,
            $this->logger
        );
    }

    private function enableFeature(): void
    {
        $this->settings
            ->method('getValue')
            ->willReturnCallback(fn (string $k, ?string $d = null) => match ($k) {
                'notifications_enabled' => 'true',
                'cron_min_interval_seconds' => '300',
                default => $d,
            });
    }

    // -------------------------------------------------------------------------
    // dispatch
    // -------------------------------------------------------------------------

    /** @test */
    public function dispatch_bei_deaktiviertem_feature_flag_gibt_null(): void
    {
        $this->settings->method('getValue')->willReturn('false');

        $this->jobs->expects($this->never())->method('insertIfNotExists');

        $result = $this->service->dispatch(
            'demo',
            ['x' => 1],
            new DateTimeImmutable('+1 minute')
        );

        $this->assertNull($result);
    }

    /** @test */
    public function dispatch_bei_unbekanntem_job_typ_warnt_und_gibt_null(): void
    {
        $this->enableFeature();
        $this->registry->method('isRegistered')->with('mystery')->willReturn(false);
        $this->logger->expects($this->once())->method('warning');
        $this->jobs->expects($this->never())->method('insertIfNotExists');

        $result = $this->service->dispatch(
            'mystery',
            null,
            new DateTimeImmutable('+1 minute')
        );

        $this->assertNull($result);
    }

    /** @test */
    public function dispatch_aktiv_und_bekannter_typ_ruft_repo(): void
    {
        $this->enableFeature();
        $this->registry->method('isRegistered')->with('demo')->willReturn(true);

        $runAt = new DateTimeImmutable('+10 minutes');
        $this->jobs
            ->expects($this->once())
            ->method('insertIfNotExists')
            ->with('demo', 'key-1', ['a' => 1], $runAt, 5)
            ->willReturn(42);

        $result = $this->service->dispatch('demo', ['a' => 1], $runAt, 'key-1', 5);
        $this->assertSame(42, $result);
    }

    /** @test */
    public function cancel_delegiert_an_repo(): void
    {
        $this->jobs->expects($this->once())
            ->method('cancelByUniqueKey')
            ->with('event:1:24h')
            ->willReturn(3);

        $this->assertSame(3, $this->service->cancel('event:1:24h'));
    }

    // -------------------------------------------------------------------------
    // canRunNow
    // -------------------------------------------------------------------------

    /** @test */
    public function can_run_now_false_wenn_deaktiviert(): void
    {
        $this->settings->method('getValue')->willReturn('false');
        $this->assertFalse($this->service->canRunNow());
    }

    /** @test */
    public function can_run_now_true_wenn_noch_nie_gelaufen(): void
    {
        $this->settings->method('getValue')
            ->willReturnCallback(fn (string $k, ?string $d = null) => match ($k) {
                'notifications_enabled' => 'true',
                'cron_min_interval_seconds' => '300',
                'cron_last_run_at' => null,
                default => $d,
            });

        $this->assertTrue($this->service->canRunNow());
    }

    /** @test */
    public function can_run_now_false_wenn_innerhalb_intervall(): void
    {
        $recent = date('Y-m-d H:i:s', time() - 60);
        $this->settings->method('getValue')
            ->willReturnCallback(fn (string $k, ?string $d = null) => match ($k) {
                'notifications_enabled' => 'true',
                'cron_min_interval_seconds' => '300',
                'cron_last_run_at' => $recent,
                default => $d,
            });

        $this->assertFalse($this->service->canRunNow());
    }

    /** @test */
    public function can_run_now_true_nach_intervall(): void
    {
        $old = date('Y-m-d H:i:s', time() - 600);
        $this->settings->method('getValue')
            ->willReturnCallback(fn (string $k, ?string $d = null) => match ($k) {
                'notifications_enabled' => 'true',
                'cron_min_interval_seconds' => '300',
                'cron_last_run_at' => $old,
                default => $d,
            });

        $this->assertTrue($this->service->canRunNow());
    }

    /** @test */
    public function can_run_now_ignoriert_intervall_bei_manual(): void
    {
        $recent = date('Y-m-d H:i:s', time() - 10);
        $this->settings->method('getValue')
            ->willReturnCallback(fn (string $k, ?string $d = null) => match ($k) {
                'notifications_enabled' => 'true',
                'cron_min_interval_seconds' => '300',
                'cron_last_run_at' => $recent,
                default => $d,
            });

        $this->assertTrue($this->service->canRunNow(ignoreInterval: true));
    }

    // -------------------------------------------------------------------------
    // runDue
    // -------------------------------------------------------------------------

    /** @test */
    public function run_due_verarbeitet_jobs_und_markiert_done(): void
    {
        $this->enableFeature();
        $this->runs->method('start')->willReturn(99);

        $this->jobs->expects($this->once())->method('requeueStuckJobs');
        $this->jobs->expects($this->once())
            ->method('claimDue')
            ->with(5)
            ->willReturn([
                ['id' => 1, 'job_type' => 'demo', 'payload' => '{"x":1}'],
                ['id' => 2, 'job_type' => 'demo', 'payload' => null],
            ]);

        $handler = $this->createMock(JobHandler::class);
        $handler->expects($this->exactly(2))->method('handle');
        $this->registry->method('resolve')->with('demo')->willReturn($handler);

        $this->jobs->expects($this->exactly(2))->method('markDone');
        $this->jobs->expects($this->never())->method('markFailed');

        $this->runs->expects($this->once())
            ->method('finish')
            ->with(99, 2, 0);

        $result = $this->service->runDue('external', '1.2.3.4', 5);
        $this->assertSame(2, $result['processed']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(99, $result['run_id']);
    }

    /** @test */
    public function run_due_markiert_fehlerhafte_jobs_als_failed(): void
    {
        $this->enableFeature();
        $this->runs->method('start')->willReturn(100);
        $this->jobs->method('claimDue')->willReturn([
            ['id' => 7, 'job_type' => 'demo', 'payload' => null],
        ]);

        $handler = $this->createMock(JobHandler::class);
        $handler->method('handle')->willThrowException(new RuntimeException('boom'));
        $this->registry->method('resolve')->willReturn($handler);

        $this->jobs->expects($this->once())->method('markFailed')->with(7, $this->stringContains('boom'));
        $this->jobs->expects($this->never())->method('markDone');
        $this->runs->expects($this->once())->method('finish')->with(100, 0, 1);

        $result = $this->service->runDue('request');
        $this->assertSame(0, $result['processed']);
        $this->assertSame(1, $result['failed']);
    }

    /** @test */
    public function run_due_aktualisiert_cron_last_run_at(): void
    {
        $this->enableFeature();
        $this->runs->method('start')->willReturn(1);
        $this->jobs->method('claimDue')->willReturn([]);

        $this->settings->expects($this->once())
            ->method('update')
            ->with('cron_last_run_at', $this->isType('string'), 0);

        $this->service->runDue('manual');
    }
}
