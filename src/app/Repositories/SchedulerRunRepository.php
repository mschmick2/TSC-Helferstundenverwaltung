<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Repository fuer das Betriebs-Log der Scheduler-Laeufe (scheduler_runs).
 * Nicht revisionspflichtig — dient Admin-UI und Diagnose.
 */
class SchedulerRunRepository
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function start(string $triggerSource, ?string $ipAddress = null): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO scheduler_runs (trigger_source, started_at, ip_address)
             VALUES (:src, NOW(), :ip)"
        );
        $stmt->execute([
            'src' => $triggerSource,
            'ip' => $ipAddress,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function finish(int $runId, int $jobsProcessed, int $jobsFailed): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE scheduler_runs
                SET finished_at = NOW(),
                    jobs_processed = :ok,
                    jobs_failed = :fail
              WHERE id = :id"
        );
        $stmt->execute([
            'ok' => $jobsProcessed,
            'fail' => $jobsFailed,
            'id' => $runId,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listRecent(int $limit = 50): array
    {
        $limit = max(1, min($limit, 500));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM scheduler_runs
              ORDER BY started_at DESC, id DESC
              LIMIT {$limit}"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
