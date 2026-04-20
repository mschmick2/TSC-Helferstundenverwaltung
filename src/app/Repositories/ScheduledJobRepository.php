<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Repository fuer die Job-Queue (scheduled_jobs).
 *
 * Nutzungsmuster:
 *   - dispatch()  → insertIfNotExists() mit optionalem unique_key (Dedup)
 *   - runDue()    → claimDue() liefert Jobs zum Abarbeiten
 *   - Worker     → markDone() / markFailed()
 */
class ScheduledJobRepository
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    /**
     * Job einplanen. Existiert bereits ein Job mit gleichem unique_key,
     * wird run_at aktualisiert (z.B. Event wurde verschoben). Wenn der
     * vorherige Job bereits abgearbeitet/abgelehnt wurde (done/failed/cancelled),
     * wird er neu aktiviert (status -> pending, attempts -> 0).
     *
     * Fuer einmalige Events wie Einladungsmails: `resetIfTerminal = false`
     * verwenden — sonst fuehrt ein erneuter Dispatch nach Status-Hin-und-Her
     * (VORGESCHLAGEN -> ABGELEHNT -> VORGESCHLAGEN) zu Doppelmails.
     *
     * Ohne unique_key wird immer neu angelegt.
     *
     * @return int ID des Jobs (neu oder bestehend)
     */
    public function insertIfNotExists(
        string $jobType,
        ?string $uniqueKey,
        ?array $payload,
        \DateTimeImmutable $runAt,
        int $maxAttempts = 3,
        bool $resetIfTerminal = true
    ): int {
        $payloadJson = $payload !== null ? json_encode($payload, JSON_THROW_ON_ERROR) : null;
        $runAtStr = $runAt->format('Y-m-d H:i:s');

        if ($uniqueKey === null) {
            $stmt = $this->pdo->prepare(
                "INSERT INTO scheduled_jobs (job_type, unique_key, payload, run_at, max_attempts)
                 VALUES (:type, NULL, :payload, :run_at, :max)"
            );
            $stmt->execute([
                'type' => $jobType,
                'payload' => $payloadJson,
                'run_at' => $runAtStr,
                'max' => $maxAttempts,
            ]);
            return (int) $this->pdo->lastInsertId();
        }

        // Wenn resetIfTerminal=false, bleibt ein bereits abgearbeiteter Job
        // (done/failed/cancelled) unangetastet — verhindert Doppelmails bei
        // einmaligen Events (z.B. Einladungs-Reminder).
        $updateClause = $resetIfTerminal
            ? "payload  = VALUES(payload),
               run_at   = VALUES(run_at),
               status   = IF(status IN ('done','failed','cancelled'), 'pending', status),
               attempts = IF(status IN ('done','failed','cancelled'), 0, attempts),
               last_error = IF(status IN ('done','failed','cancelled'), NULL, last_error)"
            : "payload  = IF(status = 'pending', VALUES(payload), payload),
               run_at   = IF(status = 'pending', VALUES(run_at),   run_at)";

        $stmt = $this->pdo->prepare(
            "INSERT INTO scheduled_jobs (job_type, unique_key, payload, run_at, max_attempts)
             VALUES (:type, :key, :payload, :run_at, :max)
             ON DUPLICATE KEY UPDATE {$updateClause}"
        );
        $stmt->execute([
            'type' => $jobType,
            'key' => $uniqueKey,
            'payload' => $payloadJson,
            'run_at' => $runAtStr,
            'max' => $maxAttempts,
        ]);

        $insertId = (int) $this->pdo->lastInsertId();
        if ($insertId > 0) {
            return $insertId;
        }

        $lookup = $this->pdo->prepare(
            "SELECT id FROM scheduled_jobs WHERE unique_key = :key"
        );
        $lookup->execute(['key' => $uniqueKey]);
        return (int) $lookup->fetchColumn();
    }

    /**
     * Faellige Jobs sperren (status='running') und zurueckgeben.
     *
     * Nutzt SELECT ... FOR UPDATE SKIP LOCKED innerhalb einer Transaktion:
     * Dadurch kann kein paralleler claimDue()-Aufruf (z.B. externer Cron-Pinger
     * + Opportunistic-Middleware gleichzeitig) dieselben Jobs beanspruchen. Ohne
     * SKIP LOCKED wuerde Prozess B auf die Sperre von Prozess A warten; so
     * ueberspringt er sie und beansprucht andere Jobs. MySQL 8.0+ Pflicht.
     *
     * @return array<int, array<string, mixed>>
     */
    public function claimDue(int $limit): array
    {
        $limit = max(1, min($limit, 100));

        // Wenn bereits eine Transaktion laeuft (z.B. Integration-Test-Wrapper),
        // nutzen wir diese mit — sonst oeffnen wir eine eigene. FOR UPDATE
        // SKIP LOCKED greift in beiden Faellen.
        $weStarted = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $weStarted = true;
        }

        try {
            $selectStmt = $this->pdo->prepare(
                "SELECT id, job_type, unique_key, payload, run_at, status,
                        attempts, max_attempts, last_error, created_at, started_at
                   FROM scheduled_jobs
                  WHERE status = 'pending'
                    AND run_at <= NOW()
                  ORDER BY run_at ASC, id ASC
                  LIMIT {$limit}
                  FOR UPDATE SKIP LOCKED"
            );
            $selectStmt->execute();
            $rows = $selectStmt->fetchAll(PDO::FETCH_ASSOC);

            if ($rows === []) {
                if ($weStarted) {
                    $this->pdo->commit();
                }
                return [];
            }

            $ids = array_map(static fn($r) => (int) $r['id'], $rows);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $updateStmt = $this->pdo->prepare(
                "UPDATE scheduled_jobs
                    SET status = 'running',
                        started_at = NOW(),
                        attempts = attempts + 1
                  WHERE id IN ({$placeholders})"
            );
            $updateStmt->execute($ids);

            if ($weStarted) {
                $this->pdo->commit();
            }

            $nowStr = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            foreach ($rows as &$row) {
                $row['status'] = 'running';
                $row['started_at'] = $nowStr;
                $row['attempts'] = (int) $row['attempts'] + 1;
            }
            unset($row);
            return $rows;
        } catch (\Throwable $e) {
            if ($weStarted && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function markDone(int $jobId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE scheduled_jobs
                SET status = 'done',
                    finished_at = NOW(),
                    last_error = NULL
              WHERE id = :id"
        );
        $stmt->execute(['id' => $jobId]);
    }

    /**
     * Job fehlschlagen lassen. Wenn attempts < max_attempts, wird er neu
     * eingeplant (exponentielles Backoff). Sonst endgueltig 'failed'.
     */
    public function markFailed(int $jobId, string $error): void
    {
        $row = $this->findById($jobId);
        if ($row === null) {
            return;
        }

        $attempts = (int) $row['attempts'];
        $max = (int) $row['max_attempts'];

        if ($attempts >= $max) {
            $stmt = $this->pdo->prepare(
                "UPDATE scheduled_jobs
                    SET status = 'failed',
                        finished_at = NOW(),
                        last_error = :err
                  WHERE id = :id"
            );
            $stmt->execute(['err' => $this->truncateError($error), 'id' => $jobId]);
            return;
        }

        $delayMinutes = $this->backoffMinutes($attempts);
        $stmt = $this->pdo->prepare(
            "UPDATE scheduled_jobs
                SET status = 'pending',
                    started_at = NULL,
                    last_error = :err,
                    run_at = DATE_ADD(NOW(), INTERVAL :delay MINUTE)
              WHERE id = :id"
        );
        $stmt->execute([
            'err' => $this->truncateError($error),
            'delay' => $delayMinutes,
            'id' => $jobId,
        ]);
    }

    public function cancelByUniqueKey(string $uniqueKey): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE scheduled_jobs
                SET status = 'cancelled', finished_at = NOW()
              WHERE unique_key = :key
                AND status IN ('pending','running')"
        );
        $stmt->execute(['key' => $uniqueKey]);
        return $stmt->rowCount();
    }

    public function findById(int $jobId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM scheduled_jobs WHERE id = :id");
        $stmt->execute(['id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByStatus(string $status, int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM scheduled_jobs
              WHERE status = :s
              ORDER BY run_at DESC, id DESC
              LIMIT {$limit}"
        );
        $stmt->execute(['s' => $status]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countPending(): int
    {
        $stmt = $this->pdo->query(
            "SELECT COUNT(*) FROM scheduled_jobs
              WHERE status = 'pending' AND run_at <= NOW()"
        );
        return (int) $stmt->fetchColumn();
    }

    /**
     * Haengengebliebene 'running'-Jobs zurueck auf 'pending' setzen
     * (z.B. nach PHP-Fatal-Error). Nutzt wall-clock timeout.
     */
    public function requeueStuckJobs(int $stuckAfterMinutes = 15): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE scheduled_jobs
                SET status = 'pending',
                    started_at = NULL,
                    last_error = CONCAT(COALESCE(last_error,''), ' [requeued: stuck]')
              WHERE status = 'running'
                AND started_at < DATE_SUB(NOW(), INTERVAL :min MINUTE)"
        );
        $stmt->execute(['min' => $stuckAfterMinutes]);
        return $stmt->rowCount();
    }

    private function backoffMinutes(int $attemptsSoFar): int
    {
        return match (true) {
            $attemptsSoFar <= 1 => 1,
            $attemptsSoFar === 2 => 5,
            default => 30,
        };
    }

    private function truncateError(string $error): string
    {
        return mb_substr($error, 0, 2000);
    }
}
