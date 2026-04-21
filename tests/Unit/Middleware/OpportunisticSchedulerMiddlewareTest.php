<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Middleware\OpportunisticSchedulerMiddleware;
use App\Services\SchedulerService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;

/**
 * Unit-Tests fuer OpportunisticSchedulerMiddleware.
 *
 * Determinismus: Wahrscheinlichkeit wird auf 0 oder 100 gesetzt, nie zwischen
 * 1-99 — der wuerfelnde Pfad wird mit p=100 (immer) und p=0 (nie) abgedeckt.
 */
final class OpportunisticSchedulerMiddlewareTest extends TestCase
{
    private SchedulerService&MockObject $scheduler;
    private LoggerInterface&MockObject $logger;
    private RequestHandlerInterface&MockObject $handler;
    private ServerRequestInterface $request;
    private ResponseInterface $expectedResponse;

    protected function setUp(): void
    {
        $this->scheduler = $this->createMock(SchedulerService::class);
        $this->logger    = $this->createMock(LoggerInterface::class);
        $this->handler   = $this->createMock(RequestHandlerInterface::class);

        $this->request = (new ServerRequestFactory())->createServerRequest('GET', '/dashboard');
        $this->expectedResponse = (new SlimResponse())->withStatus(200);

        $this->handler->method('handle')->willReturn($this->expectedResponse);
    }

    public function test_probability_null_triggert_nie_und_liefert_response(): void
    {
        $mw = new OpportunisticSchedulerMiddleware(
            $this->scheduler, $this->logger, probabilityPercent: 0
        );
        $this->scheduler->expects(self::never())->method('canRunNow');
        $this->scheduler->expects(self::never())->method('runDue');

        $response = $mw->process($this->request, $this->handler);

        self::assertSame($this->expectedResponse, $response);
    }

    public function test_probability_voll_triggert_runDue_wenn_canRunNow_true(): void
    {
        $mw = new OpportunisticSchedulerMiddleware(
            $this->scheduler, $this->logger, probabilityPercent: 100, maxJobs: 5
        );
        $this->scheduler->method('canRunNow')->willReturn(true);
        $this->scheduler->expects(self::once())
            ->method('runDue')
            ->with('request', self::isType('string'), 5)
            ->willReturn(['processed' => 2, 'failed' => 0, 'run_id' => 17]);

        $response = $mw->process($this->request, $this->handler);

        self::assertSame($this->expectedResponse, $response);
    }

    public function test_probability_voll_aber_canRunNow_false_skipped_runDue(): void
    {
        $mw = new OpportunisticSchedulerMiddleware(
            $this->scheduler, $this->logger, probabilityPercent: 100
        );
        $this->scheduler->method('canRunNow')->willReturn(false);
        $this->scheduler->expects(self::never())->method('runDue');

        $response = $mw->process($this->request, $this->handler);

        self::assertSame($this->expectedResponse, $response);
    }

    public function test_runDue_wirft_exception_wird_geloggt_aber_nie_propagiert(): void
    {
        $mw = new OpportunisticSchedulerMiddleware(
            $this->scheduler, $this->logger, probabilityPercent: 100
        );
        $this->scheduler->method('canRunNow')->willReturn(true);
        $this->scheduler->method('runDue')
            ->willThrowException(new RuntimeException('DB weg'));

        $this->logger->expects(self::once())
            ->method('error')
            ->with(self::stringContains('Trigger fehlgeschlagen'));

        $response = $mw->process($this->request, $this->handler);

        // UI bekommt trotzdem die normale Response
        self::assertSame($this->expectedResponse, $response);
    }

    public function test_uebernimmt_REMOTE_ADDR_in_runDue_call(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET', '/dashboard', ['REMOTE_ADDR' => '203.0.113.7']
        );

        $mw = new OpportunisticSchedulerMiddleware(
            $this->scheduler, $this->logger, probabilityPercent: 100
        );
        $this->scheduler->method('canRunNow')->willReturn(true);
        $this->scheduler->expects(self::once())
            ->method('runDue')
            ->with('request', '203.0.113.7', self::anything())
            ->willReturn(['processed' => 0, 'failed' => 0, 'run_id' => 1]);

        $mw->process($request, $this->handler);
    }
}
