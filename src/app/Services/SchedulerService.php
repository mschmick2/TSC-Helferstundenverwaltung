<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ScheduledJobRepository;
use App\Repositories\SchedulerRunRepository;
use App\Repositories\SettingsRepository;
use App\Services\Jobs\JobHandlerRegistry;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Orchestriert die Job-Queue: dispatchen, faellige Jobs abarbeiten, Runs loggen.
 *
 * Strato-Kontext: Kein echter Cron → diese Klasse wird ueber
 *   - CronController (externer Pinger) oder
 *   - OpportunisticSchedulerMiddleware (Request-Piggyback)
 * aufgerufen. Siehe docs/NOTIFICATIONS.md.
 */
class SchedulerService
{
    public function __construct(
        private ScheduledJobRepository $jobs,
        private SchedulerRunRepository $runs,
        private SettingsRepository $settings,
        private JobHandlerRegistry $registry,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Job einplanen. Bei gleichem $uniqueKey wird ein bestehender Eintrag
     * aktualisiert (z.B. wenn ein Event verschoben wird).
     *
     * Ohne aktiviertes Feature-Flag (notifications_enabled=false) werden
     * Dispatches stillschweigend verworfen (Kill-Switch).
     *
     * @param array<string, mixed>|null $payload
     */
    public function dispatch(
        string $jobType,
        ?array $payload,
        DateTimeImmutable $runAt,
        ?string $uniqueKey = null,
        int $maxAttempts = 3
    ): ?int {
        if (!$this->isEnabled()) {
            return null;
        }

        if (!$this->registry->isRegistered($jobType)) {
            $this->logger->warning("Dispatch fuer unbekannten Job-Typ: {$jobType}");
            return null;
        }

        return $this->jobs->insertIfNotExists(
            $jobType,
            $uniqueKey,
            $payload,
            $runAt,
            $maxAttempts,
            true
        );
    }

    /**
     * Dispatch fuer einmalige Jobs (z.B. Einladungsmails): Wenn unter dem
     * unique_key bereits ein Job existiert UND bereits abgearbeitet ist
     * (done/failed/cancelled), wird NICHT neu eingeplant. So entstehen keine
     * Doppel-Mails, wenn ein Assignment-Status zwischenzeitlich kippt und
     * wieder in VORGESCHLAGEN wechselt.
     *
     * @param array<string, mixed>|null $payload
     */
    public function dispatchIfNew(
        string $jobType,
        ?array $payload,
        DateTimeImmutable $runAt,
        string $uniqueKey,
        int $maxAttempts = 3
    ): ?int {
        if (!$this->isEnabled()) {
            return null;
        }

        if (!$this->registry->isRegistered($jobType)) {
            $this->logger->warning("Dispatch fuer unbekannten Job-Typ: {$jobType}");
            return null;
        }

        return $this->jobs->insertIfNotExists(
            $jobType,
            $uniqueKey,
            $payload,
            $runAt,
            $maxAttempts,
            false
        );
    }

    public function cancel(string $uniqueKey): int
    {
        return $this->jobs->cancelByUniqueKey($uniqueKey);
    }

    /**
     * Darf der Scheduler jetzt laufen? Prueft Feature-Flag + Mindest-Intervall.
     * Manuelle Trigger (trigger_source='manual') ignorieren das Intervall.
     */
    public function canRunNow(bool $ignoreInterval = false): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        if ($ignoreInterval) {
            return true;
        }

        $minInterval = (int) ($this->settings->getValue('cron_min_interval_seconds', '300') ?? '300');
        $lastRun = $this->settings->getValue('cron_last_run_at');

        if ($lastRun === null || $lastRun === '') {
            return true;
        }

        $lastTs = strtotime($lastRun);
        if ($lastTs === false) {
            return true;
        }

        return (time() - $lastTs) >= $minInterval;
    }

    /**
     * Faellige Jobs ausfuehren. Gibt [processed, failed] zurueck.
     *
     * @return array{processed: int, failed: int, run_id: int}
     */
    public function runDue(
        string $triggerSource,
        ?string $ipAddress = null,
        int $maxJobs = 20
    ): array {
        $runId = $this->runs->start($triggerSource, $ipAddress);

        $processed = 0;
        $failed = 0;

        try {
            $this->jobs->requeueStuckJobs();

            $due = $this->jobs->claimDue($maxJobs);

            foreach ($due as $job) {
                $jobId = (int) $job['id'];
                $jobType = (string) $job['job_type'];
                $payload = $this->decodePayload($job['payload'] ?? null);

                try {
                    $handler = $this->registry->resolve($jobType);
                    $handler->handle($payload);
                    $this->jobs->markDone($jobId);
                    $processed++;
                } catch (Throwable $e) {
                    $this->logger->error(
                        "Job {$jobId} ({$jobType}) fehlgeschlagen: " . $e->getMessage(),
                        ['exception' => $e]
                    );
                    $this->jobs->markFailed(
                        $jobId,
                        $e->getMessage() . "\n" . $e->getTraceAsString()
                    );
                    $failed++;
                }
            }
        } finally {
            $this->runs->finish($runId, $processed, $failed);
            $this->settings->update(
                'cron_last_run_at',
                date('Y-m-d H:i:s'),
                0
            );
        }

        return ['processed' => $processed, 'failed' => $failed, 'run_id' => $runId];
    }

    public function isEnabled(): bool
    {
        $flag = $this->settings->getValue('notifications_enabled', 'false');
        return $flag === 'true' || $flag === '1';
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_array($raw)) {
            return $raw;
        }
        try {
            $decoded = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }
}
