<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\SchedulerService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Opportunistischer Scheduler-Trigger als Backup zum externen Cron-Pinger.
 *
 * Strato-Hosting hat keinen System-Cron. Damit Notifications auch dann
 * laufen, wenn der externe Pinger ausfaellt, wird hier auf jedem Request
 * (an dem die Middleware haengt) mit kleiner Wahrscheinlichkeit der
 * SchedulerService::runDue() angestossen.
 *
 * Reihenfolge im Stack:
 *   - Erst die normale Response erzeugen.
 *   - Danach (best-effort) den Scheduler triggern.
 *   - Errors werden nur geloggt, nie geworfen — UI darf nie wegen
 *     eines Hintergrund-Jobs kaputtgehen.
 *
 * Drosselung in mehreren Schichten:
 *   1. probabilityPercent (Default 10): wuerfelt pro Request.
 *   2. SchedulerService::canRunNow() prueft cron_min_interval_seconds.
 *   3. maxJobs begrenzt die pro Trigger ausgefuehrte Job-Zahl, damit der
 *      Request nicht um Sekunden verzoegert wird.
 *
 * Registrieren ueblicherweise NUR an haeufig getroffenen, eingeloggten
 * Routen (z.B. Dashboard) — nicht global, sonst werden API/JSON-Endpunkte
 * unnoetig verzoegert.
 */
class OpportunisticSchedulerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SchedulerService $scheduler,
        private readonly LoggerInterface $logger,
        private readonly int $probabilityPercent = 10,
        private readonly int $maxJobs = 5,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        // Eigentlichen Response IMMER zuerst erzeugen — Job-Trigger darf nichts brechen.
        $response = $handler->handle($request);

        try {
            $this->maybeTrigger($request);
        } catch (Throwable $e) {
            // Ueberlebensregel: nichts an die UI durchreichen.
            $this->logger->error(
                'OpportunisticSchedulerMiddleware: Trigger fehlgeschlagen: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }

        return $response;
    }

    private function maybeTrigger(Request $request): void
    {
        if ($this->probabilityPercent <= 0) {
            return;
        }

        if ($this->probabilityPercent < 100 && random_int(1, 100) > $this->probabilityPercent) {
            return;
        }

        if (!$this->scheduler->canRunNow()) {
            return;
        }

        $ip = $this->clientIp($request);
        $result = $this->scheduler->runDue('request', $ip, $this->maxJobs);

        $this->logger->info('OpportunisticScheduler: Lauf abgeschlossen', [
            'ip'        => $ip,
            'run_id'    => $result['run_id'],
            'processed' => $result['processed'],
            'failed'    => $result['failed'],
        ]);
    }

    private function clientIp(Request $request): string
    {
        $serverParams = $request->getServerParams();
        $ip = $serverParams['REMOTE_ADDR'] ?? '0.0.0.0';
        return is_string($ip) && $ip !== '' ? $ip : '0.0.0.0';
    }
}
